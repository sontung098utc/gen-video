<?php

declare(strict_types=1);

namespace App\Services\Media;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Dịch vụ đối sánh và chọn hiệu ứng âm thanh (SFX) hoàn toàn miễn phí ($0.00)
 * từ kho lưu trữ cục bộ `storage/app/sfx_library/` dựa trên từ khóa `$sfxPrompt`.
 */
class LocalSfxMatcherService
{
    /**
     * Bảng ánh xạ từ khóa đồng nghĩa trong `$sfxPrompt` sang nhóm hiệu ứng âm thanh chuẩn.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYWORD_SYNONYMS = [
        'whoosh' => ['whoosh', 'swoosh', 'swipe', 'transition', 'fast_move', 'wind', 'fly'],
        'unbox' => ['unbox', 'open', 'package', 'box', 'reveal', 'paper', 'tear'],
        'click' => ['click', 'button', 'tap', 'switch', 'snap', 'camera', 'shutter'],
        'sparkle' => ['sparkle', 'magic', 'shine', 'chime', 'ding', 'bell', 'luxury', 'glow'],
        'pop' => ['pop', 'bubble', 'plop', 'appear', 'boing', 'notification'],
        'typing' => ['typing', 'keyboard', 'text', 'computer', 'write', 'data', 'tech'],
        'ambient' => ['ambient', 'room', 'nature', 'background', 'air', 'calm', 'soft'],
        'impact' => ['impact', 'hit', 'boom', 'bass', 'drop', 'cinematic', 'thud', 'punch'],
    ];

    private readonly string $sfxLibraryRoot;

    public function __construct(?string $customLibraryRoot = null)
    {
        $this->sfxLibraryRoot = $customLibraryRoot ?? storage_path('app/sfx_library');
    }

    /**
     * Phân tích từ khóa trong `$sfxPrompt` và tự động gán đường dẫn tuyệt đối tới file `.mp3`/`.wav`
     * phù hợp nhất trong kho `storage/app/sfx_library/` (chi phí $0.00).
     *
     * @param  string  $sfxPrompt  Gợi ý hiệu ứng âm thanh từ Gemini (VD: `whoosh_fast`, `sparkle_chime`, `keyboard_typing`)
     * @return string|null Đường dẫn tuyệt đối tới file âm thanh SFX, hoặc `null` nếu kho trống
     */
    public function matchSfxForScene(string $sfxPrompt): ?string
    {
        $normalizedPrompt = mb_strtolower(trim($sfxPrompt));
        if ($normalizedPrompt === '' || ! is_dir($this->sfxLibraryRoot)) {
            return null;
        }

        $availableFiles = $this->scanAudioFiles($this->sfxLibraryRoot);
        if ($availableFiles === []) {
            return null;
        }

        // Bước 1: So khớp trực tiếp tên file với các từ khóa có trong $sfxPrompt
        $promptTokens = preg_split('/[\s_\-,.;:]+/u', $normalizedPrompt) ?: [];
        $promptTokens = array_values(array_filter($promptTokens, static fn (string $t): bool => strlen($t) >= 2));

        $bestPath = null;
        $bestScore = 0;

        foreach ($availableFiles as $filePath) {
            $fileName = mb_strtolower(pathinfo($filePath, PATHINFO_FILENAME));
            $folderName = mb_strtolower(basename(dirname($filePath)));
            $haystack = $folderName . '_' . $fileName;

            $score = 0;

            // Điểm khớp trực tiếp theo token
            foreach ($promptTokens as $token) {
                if (str_contains($haystack, $token)) {
                    $score += 10;
                }
            }

            // Điểm khớp theo bảng từ khóa đồng nghĩa (KEYWORD_SYNONYMS)
            foreach (self::KEYWORD_SYNONYMS as $canonicalKey => $synonyms) {
                $promptMatchesCategory = false;
                foreach ($synonyms as $synonym) {
                    if (str_contains($normalizedPrompt, $synonym)) {
                        $promptMatchesCategory = true;
                        break;
                    }
                }

                if ($promptMatchesCategory) {
                    if (str_contains($haystack, $canonicalKey)) {
                        $score += 8;
                    }
                    foreach ($synonyms as $synonym) {
                        if (str_contains($haystack, $synonym)) {
                            $score += 5;
                        }
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPath = $filePath;
            }
        }

        return $bestScore > 0 ? $bestPath : null;
    }

    /**
     * Tìm file nhạc nền (BGM) mặc định trong `storage/app/sfx_library/bgm` nếu dự án chưa gán BGM.
     */
    public function findDefaultBgmTrack(?string $moodKeyword = null): ?string
    {
        $bgmDir = $this->sfxLibraryRoot . DIRECTORY_SEPARATOR . 'bgm';
        $searchDir = is_dir($bgmDir) ? $bgmDir : $this->sfxLibraryRoot;

        if (! is_dir($searchDir)) {
            return null;
        }

        $bgmFiles = $this->scanAudioFiles($searchDir);
        if ($bgmFiles === []) {
            return null;
        }

        if ($moodKeyword !== null && trim($moodKeyword) !== '') {
            $normalizedMood = mb_strtolower(trim($moodKeyword));
            foreach ($bgmFiles as $file) {
                if (str_contains(mb_strtolower(basename($file)), $normalizedMood)) {
                    return $file;
                }
            }
        }

        return $bgmFiles[0];
    }

    /**
     * Quét đệ quy toàn bộ các file `.mp3` và `.wav` trong thư mục kho SFX.
     *
     * @return array<int, string>
     */
    private function scanAudioFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $ext = strtolower($fileInfo->getExtension());
            if (in_array($ext, ['mp3', 'wav', 'ogg'], true)) {
                $files[] = $fileInfo->getRealPath() ?: $fileInfo->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
