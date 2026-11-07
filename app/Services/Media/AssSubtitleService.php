<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;

/**
 * Dịch vụ chuyển đổi phụ đề `.vtt` (do `edge-tts` sinh ra kèm mốc thời gian từng từ/cụm từ)
 * sang định dạng Advanced SubStation Alpha (`.ass`) với hiệu ứng Karaoke / Highlight vàng nổi bật.
 */
class AssSubtitleService
{
    /**
     * Chuyển đổi một file `.vtt` đơn lẻ sang file `.ass` với hiệu ứng Karaoke tiếng Việt.
     *
     * @param  string  $vttFilePath  Đường dẫn tuyệt đối tới file `.vtt` đầu vào
     * @param  string  $assOutputPath  Đường dẫn tuyệt đối lưu file `.ass` đầu ra
     * @param  string  $stylePreset  Kiểu hiển thị (`karaoke`, `highlight`, `clean`)
     * @param  string  $aspectRatio  Tỷ lệ khung hình (`9:16` hoặc `16:9`)
     */
    public function convertVttToAss(
        string $vttFilePath,
        string $assOutputPath,
        string $stylePreset = 'karaoke',
        string $aspectRatio = '9:16'
    ): bool {
        if (! is_file($vttFilePath)) {
            throw new RuntimeException("Không tìm thấy file phụ đề VTT: {$vttFilePath}");
        }

        $cues = $this->parseVttFile($vttFilePath, 0);

        return $this->writeAssDocument($cues, $assOutputPath, $stylePreset, $aspectRatio);
    }

    /**
     * Hợp nhất danh sách các file `.vtt` của nhiều phân cảnh (có bù trừ độ trễ `offset_ms` theo trục thời gian)
     * thành một file phụ đề `.ass` tổng thể duy nhất cho toàn bộ video.
     *
     * @param  array<int, array{vtt_path: string, offset_ms: int}>  $sceneVttTimeline
     * @param  string  $assOutputPath  Đường dẫn lưu file `.ass` tổng
     * @param  string  $stylePreset  Kiểu hiển thị (`karaoke`, `highlight`, `clean`)
     * @param  string  $aspectRatio  Tỷ lệ khung hình (`9:16` hoặc `16:9`)
     */
    public function mergeSceneVttsToAss(
        array $sceneVttTimeline,
        string $assOutputPath,
        string $stylePreset = 'karaoke',
        string $aspectRatio = '9:16'
    ): bool {
        $allCues = [];

        foreach ($sceneVttTimeline as $item) {
            $vttPath = (string) ($item['vtt_path'] ?? '');
            $offsetMs = max(0, (int) ($item['offset_ms'] ?? 0));

            if ($vttPath !== '' && is_file($vttPath)) {
                $sceneCues = $this->parseVttFile($vttPath, $offsetMs);
                foreach ($sceneCues as $cue) {
                    $allCues[] = $cue;
                }
            }
        }

        return $this->writeAssDocument($allCues, $assOutputPath, $stylePreset, $aspectRatio);
    }

    /**
     * Đọc và bóc tách các mốc thời gian (cues) từ file WebVTT (`.vtt`).
     *
     * @return array<int, array{start_ms: int, end_ms: int, text: string}>
     */
    private function parseVttFile(string $vttFilePath, int $offsetMs = 0): array
    {
        $content = file_get_contents($vttFilePath);
        if ($content === false) {
            throw new RuntimeException("Không thể đọc nội dung file VTT: {$vttFilePath}");
        }

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
                        'start_ms' => $currentStartMs + $offsetMs,
                        'end_ms' => $currentEndMs + $offsetMs,
                        'text' => trim(implode(' ', $currentTextLines)),
                    ];
                }
                $currentStartMs = null;
                $currentEndMs = null;
                $currentTextLines = [];

                continue;
            }

            if (preg_match('/^(\d{2}:\d{2}:\d{2}[\.,]\d{3}|\d{2}:\d{2}[\.,]\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}[\.,]\d{3}|\d{2}:\d{2}[\.,]\d{3})/', $trimmed, $matches)) {
                if ($currentStartMs !== null && $currentEndMs !== null && $currentTextLines !== []) {
                    $cues[] = [
                        'start_ms' => $currentStartMs + $offsetMs,
                        'end_ms' => $currentEndMs + $offsetMs,
                        'text' => trim(implode(' ', $currentTextLines)),
                    ];
                    $currentTextLines = [];
                }

                $currentStartMs = $this->timestampToMs($matches[1]);
                $currentEndMs = $this->timestampToMs($matches[2]);

                continue;
            }

            // Bỏ qua dòng chỉ chứa số thứ tự cue
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
                'start_ms' => $currentStartMs + $offsetMs,
                'end_ms' => $currentEndMs + $offsetMs,
                'text' => trim(implode(' ', $currentTextLines)),
            ];
        }

        return $cues;
    }

    /**
     * Xuất cấu trúc file `.ass` (Advanced SubStation Alpha) với Font chữ tiếng Việt (`Montserrat` / `Noto Sans`),
     * màu vàng `#FFFF00` (`&H0000FFFF` trong hệ BGR của ASS), viền đen dày và hiệu ứng Karaoke / Pop-in.
     *
     * @param  array<int, array{start_ms: int, end_ms: int, text: string}>  $cues
     */
    private function writeAssDocument(
        array $cues,
        string $assOutputPath,
        string $stylePreset,
        string $aspectRatio
    ): bool {
        $dir = dirname($assOutputPath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không thể tạo thư mục lưu phụ đề ASS: {$dir}");
        }

        $isVertical = $aspectRatio !== '16:9';
        $playResX = $isVertical ? 1080 : 1920;
        $playResY = $isVertical ? 1920 : 1080;
        $fontSize = $isVertical ? 64 : 52;
        $marginV = $isVertical ? 280 : 95;
        $fontName = 'Montserrat';

        // Trong định dạng ASS: Màu sắc viết theo dạng &HAABBGGRR (BGR ngược với RGB)
        // Màu vàng #FFFF00 -> R=FF, G=FF, B=00 -> &H0000FFFF
        // Màu trắng #FFFFFF -> &H00FFFFFF
        // Màu đen viền #000000 -> &H00000000
        $header = <<<ASS
[Script Info]
; Generated by AI Short Video Generator (AssSubtitleService)
ScriptType: v4.00+
PlayResX: {$playResX}
PlayResY: {$playResY}
WrapStyle: 0
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Default,{$fontName},{$fontSize},&H0000FFFF,&H00FFFFFF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,4,2,2,60,60,{$marginV},1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
ASS;

        $dialogueLines = [];

        foreach ($cues as $cue) {
            $startAss = $this->msToAssTime($cue['start_ms']);
            $endAss = $this->msToAssTime(max($cue['start_ms'] + 100, $cue['end_ms']));
            $durationMs = max(100, $cue['end_ms'] - $cue['start_ms']);

            $formattedText = $this->applyKaraokeTags(
                text: $cue['text'],
                durationMs: $durationMs,
                stylePreset: $stylePreset
            );

            $dialogueLines[] = sprintf(
                'Dialogue: 0,%s,%s,Default,,0,0,0,,%s',
                $startAss,
                $endAss,
                $formattedText
            );
        }

        $fullAssContent = $header . "\n" . implode("\n", $dialogueLines) . "\n";

        return file_put_contents($assOutputPath, $fullAssContent) !== false;
    }

    /**
     * Tạo chuỗi thẻ điều khiển hiệu ứng Karaoke (`\kf`) và phóng to nhẹ (`\t(\fscx108\fscy108)`)
     * cho từng từ tiếng Việt trong câu thoại.
     */
    private function applyKaraokeTags(string $text, int $durationMs, string $stylePreset): string
    {
        $escapedText = str_replace(['\\', '{', '}'], ['', '(', ')'], $text);
        $words = preg_split('/\s+/u', trim($escapedText)) ?: [];

        if ($words === []) {
            return '';
        }

        if ($stylePreset === 'clean') {
            return $escapedText;
        }

        // Hiệu ứng phóng nhẹ từ 100% lên 106% trong 120ms đầu để tạo nhịp năng động
        $popEffect = '{\fscx105\fscy105\t(0,120,\fscx100\fscy100)}';

        // Chia đều thời lượng (đơn vị centi-giây: 1 cs = 10 ms) cho các từ theo độ dài ký tự
        $totalCentiseconds = max(10, (int) round($durationMs / 10.0));
        $wordCount = count($words);
        $csPerWord = max(5, (int) floor($totalCentiseconds / $wordCount));

        $karaokeParts = [];
        foreach ($words as $idx => $word) {
            $kDuration = ($idx === $wordCount - 1)
                ? max(5, $totalCentiseconds - ($csPerWord * ($wordCount - 1)))
                : $csPerWord;
            // Thẻ {\kf} tạo hiệu ứng quét màu vàng (#FFFF00 - PrimaryColour) từ SecondaryColour (Trắng)
            $karaokeParts[] = sprintf('{\kf%d}%s', $kDuration, $word);
        }

        return $popEffect . implode(' ', $karaokeParts);
    }

    /**
     * Chuyển đổi chuỗi thời gian VTT (`00:00:03.450` hoặc `00:03.450`) sang mili-giây.
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

    /**
     * Chuyển đổi mili-giây sang định dạng thời gian chuẩn của ASS (`H:MM:SS.cc`).
     */
    private function msToAssTime(int $milliseconds): string
    {
        $totalCentiseconds = (int) round(max(0, $milliseconds) / 10.0);
        $cs = $totalCentiseconds % 100;
        $totalSeconds = intdiv($totalCentiseconds, 100);
        $seconds = $totalSeconds % 60;
        $totalMinutes = intdiv($totalSeconds, 60);
        $minutes = $totalMinutes % 60;
        $hours = intdiv($totalMinutes, 60);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, $cs);
    }
}
