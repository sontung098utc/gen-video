<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Dịch vụ neo mốc thời gian theo từ (Word-Anchored Timeline) lấy cảm hứng từ `@hypit/narrative-temporal`.
 *
 * Chuyên trách:
 *  1. Làm sạch các thẻ neo nội tuyến `@{pop}`, `@{whoosh}`, `@{sparkle}`, `@{zoom}`, `@{sticker}`
 *     trước khi đưa văn bản tiếng Việt vào bộ tổng hợp giọng đọc `EdgeTtsVietnameseService`.
 *  2. Bóc tách vị trí chỉ mục từ (word index) của từng thẻ neo trong câu thoại và ánh xạ sang
 *     mốc thời gian mili-giây chính xác dựa trên file phụ đề `.vtt` của phân cảnh.
 *  3. Phân loại thành `sfx_events` (đối sánh qua `LocalSfxMatcherService`) và `visual_events`.
 */
class WordAnchoredTimelineService
{
    /**
     * Danh sách các thẻ neo thuộc nhóm hiệu ứng hình ảnh / chuyển động.
     *
     * @var array<int, string>
     */
    private const VISUAL_TAGS = [
        'zoom',
        'sticker',
    ];

    public function __construct(
        private readonly LocalSfxMatcherService $sfxMatcherService
    ) {}

    /**
     * Loại bỏ toàn bộ thẻ neo dạng `@{...}` khỏi câu thoại để phục vụ tổng hợp giọng đọc TTS.
     */
    public function stripTags(string $taggedNarration): string
    {
        $withoutTags = preg_replace('/@\{[^}]+\}/u', '', $taggedNarration) ?? $taggedNarration;
        $collapsedSpaces = preg_replace('/\s+/u', ' ', $withoutTags) ?? $withoutTags;
        $cleanedPunctuation = preg_replace('/\s+([,.;:!?])/u', '$1', $collapsedSpaces) ?? $collapsedSpaces;

        return trim($cleanedPunctuation);
    }

    /**
     * Bóc tách các thẻ neo từ câu thoại có gắn thẻ và ánh xạ sang mốc thời gian mili-giây thực tế
     * dựa trên file phụ đề `.vtt` của phân cảnh.
     *
     * @return array{
     *     clean_text: string,
     *     sfx_events: array<int, array{tag: string, word_index: int, local_ms: int, start_ms: int, file_path: string}>,
     *     visual_events: array<int, array{tag: string, word_index: int, local_ms: int, start_ms: int}>
     * }
     */
    public function extractWordAnchors(
        string $taggedNarration,
        string $vttFullPath,
        int $sceneOffsetMs,
        int $sceneDurationMs
    ): array {
        $parsed = $this->parseTaggedNarration($taggedNarration);
        $cleanText = $parsed['clean_text'];
        $anchors = $parsed['anchors'];
        $totalWords = $parsed['word_count'];

        if ($anchors === []) {
            return [
                'clean_text' => $cleanText,
                'sfx_events' => [],
                'visual_events' => [],
            ];
        }

        $wordTimestamps = $this->buildWordTimestampsFromVtt($vttFullPath);
        $safeDurationMs = max(0, $sceneDurationMs);
        $safeOffsetMs = max(0, $sceneOffsetMs);

        $sfxEvents = [];
        $visualEvents = [];

        foreach ($anchors as $anchor) {
            $tag = $anchor['tag'];
            $wordIndex = $anchor['word_index'];
            $localMs = $this->resolveLocalTimestampMs(
                wordIndex: $wordIndex,
                totalWords: $totalWords,
                wordTimestamps: $wordTimestamps,
                sceneDurationMs: $safeDurationMs
            );
            $globalStartMs = $safeOffsetMs + $localMs;

            if (in_array($tag, self::VISUAL_TAGS, true)) {
                $visualEvents[] = [
                    'tag' => $tag,
                    'word_index' => $wordIndex,
                    'local_ms' => $localMs,
                    'start_ms' => $globalStartMs,
                ];

                continue;
            }

            $matchedSfxPath = $this->sfxMatcherService->matchSfxForScene($tag);
            if ($matchedSfxPath !== null && is_file($matchedSfxPath)) {
                $sfxEvents[] = [
                    'tag' => $tag,
                    'word_index' => $wordIndex,
                    'local_ms' => $localMs,
                    'start_ms' => $globalStartMs,
                    'file_path' => $matchedSfxPath,
                ];
            }
        }

        return [
            'clean_text' => $cleanText,
            'sfx_events' => $sfxEvents,
            'visual_events' => $visualEvents,
        ];
    }

    /**
     * Tách câu thoại thành danh sách từ sạch và danh sách thẻ neo kèm chỉ mục từ (`word_index`).
     *
     * @return array{
     *     clean_text: string,
     *     word_count: int,
     *     anchors: array<int, array{tag: string, word_index: int}>
     * }
     */
    private function parseTaggedNarration(string $taggedNarration): array
    {
        $parts = preg_split('/(@\{[a-zA-Z0-9_\-]+\})/u', $taggedNarration, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [
                'clean_text' => $this->stripTags($taggedNarration),
                'word_count' => 0,
                'anchors' => [],
            ];
        }

        $wordCount = 0;
        $anchors = [];

        foreach ($parts as $part) {
            if (preg_match('/^@\{([a-zA-Z0-9_\-]+)\}$/u', $part, $matches) === 1) {
                $anchors[] = [
                    'tag' => mb_strtolower(trim($matches[1])),
                    'word_index' => $wordCount,
                ];

                continue;
            }

            $tokens = preg_split('/\s+/u', trim($part), -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($tokens)) {
                $wordCount += count($tokens);
            }
        }

        return [
            'clean_text' => $this->stripTags($taggedNarration),
            'word_count' => $wordCount,
            'anchors' => $anchors,
        ];
    }

    /**
     * Đọc file `.vtt` và nội suy mốc thời gian bắt đầu/kết thúc cho từng từ trong phân cảnh.
     *
     * @return array<int, array{word: string, start_ms: int, end_ms: int}>
     */
    private function buildWordTimestampsFromVtt(string $vttFullPath): array
    {
        if ($vttFullPath === '' || ! is_file($vttFullPath)) {
            return [];
        }

        $content = file_get_contents($vttFullPath);
        if ($content === false) {
            return [];
        }

        $cues = $this->parseVttCues($content);
        $wordTimestamps = [];

        foreach ($cues as $cue) {
            $words = preg_split('/\s+/u', trim($cue['text']), -1, PREG_SPLIT_NO_EMPTY);
            if (! is_array($words) || $words === []) {
                continue;
            }

            $count = count($words);
            $cueDurationMs = max(1, $cue['end_ms'] - $cue['start_ms']);

            foreach ($words as $index => $word) {
                $wordStartMs = $cue['start_ms'] + (int) round(($cueDurationMs * $index) / $count);
                $wordEndMs = $cue['start_ms'] + (int) round(($cueDurationMs * ($index + 1)) / $count);

                $wordTimestamps[] = [
                    'word' => $word,
                    'start_ms' => $wordStartMs,
                    'end_ms' => max($wordStartMs, $wordEndMs),
                ];
            }
        }

        return $wordTimestamps;
    }

    /**
     * Bóc tách danh sách cue từ nội dung WebVTT.
     *
     * @return array<int, array{start_ms: int, end_ms: int, text: string}>
     */
    private function parseVttCues(string $content): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $cues = [];
        $currentStartMs = null;
        $currentEndMs = null;
        $currentTextLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, 'WEBVTT') || str_starts_with($trimmed, 'NOTE')) {
                if ($currentStartMs !== null && $currentEndMs !== null && $currentTextLines !== []) {
                    $cues[] = [
                        'start_ms' => $currentStartMs,
                        'end_ms' => $currentEndMs,
                        'text' => trim(implode(' ', $currentTextLines)),
                    ];
                }
                $currentStartMs = null;
                $currentEndMs = null;
                $currentTextLines = [];

                continue;
            }

            if (preg_match('/^(\d{2}:\d{2}:\d{2}[\.,]\d{3}|\d{2}:\d{2}[\.,]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[\.,]\d{3}|\d{2}:\d{2}[\.,]\d{3})/', $trimmed, $matches) === 1) {
                if ($currentStartMs !== null && $currentEndMs !== null && $currentTextLines !== []) {
                    $cues[] = [
                        'start_ms' => $currentStartMs,
                        'end_ms' => $currentEndMs,
                        'text' => trim(implode(' ', $currentTextLines)),
                    ];
                    $currentTextLines = [];
                }

                $currentStartMs = $this->timestampToMs($matches[1]);
                $currentEndMs = $this->timestampToMs($matches[2]);

                continue;
            }

            if ($currentStartMs === null && ctype_digit($trimmed)) {
                continue;
            }

            if ($currentStartMs !== null) {
                $cleanLine = strip_tags($trimmed);
                if ($cleanLine !== '') {
                    $currentTextLines[] = $cleanLine;
                }
            }
        }

        if ($currentStartMs !== null && $currentEndMs !== null && $currentTextLines !== []) {
            $cues[] = [
                'start_ms' => $currentStartMs,
                'end_ms' => $currentEndMs,
                'text' => trim(implode(' ', $currentTextLines)),
            ];
        }

        return $cues;
    }

    /**
     * Quy đổi chỉ mục từ (`word_index`) sang mili-giây cục bộ trong phân cảnh.
     *
     * @param  array<int, array{word: string, start_ms: int, end_ms: int}>  $wordTimestamps
     */
    private function resolveLocalTimestampMs(
        int $wordIndex,
        int $totalWords,
        array $wordTimestamps,
        int $sceneDurationMs
    ): int {
        $timestampCount = count($wordTimestamps);

        if ($timestampCount > 0) {
            if ($wordIndex < $timestampCount) {
                $ms = $wordTimestamps[$wordIndex]['start_ms'];
            } else {
                $ms = $wordTimestamps[$timestampCount - 1]['end_ms'];
            }

            return $sceneDurationMs > 0
                ? max(0, min($sceneDurationMs, $ms))
                : max(0, $ms);
        }

        if ($sceneDurationMs <= 0 || $totalWords <= 0) {
            return 0;
        }

        $ratio = min(1.0, max(0.0, $wordIndex / $totalWords));

        return (int) round($ratio * $sceneDurationMs);
    }

    /**
     * Chuyển đổi chuỗi thời gian WebVTT sang mili-giây.
     */
    private function timestampToMs(string $timestamp): int
    {
        $normalized = str_replace(',', '.', trim($timestamp));
        $parts = explode(':', $normalized);

        if (count($parts) === 3) {
            [$hours, $minutes, $seconds] = $parts;
        } elseif (count($parts) === 2) {
            $hours = '0';
            [$minutes, $seconds] = $parts;
        } else {
            return 0;
        }

        $totalSeconds = ((int) $hours * 3600) + ((int) $minutes * 60) + (float) $seconds;

        return (int) round($totalSeconds * 1000.0);
    }
}
