<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\GenerationLog;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dịch vụ tìm kiếm & xử lý Video Stock miễn phí ($0.00) tham khảo từ `harry0703/MoneyPrinterTurbo`.
 *
 * Luồng hoạt động:
 *  1. Trích xuất từ khóa tìm kiếm tiếng Anh ngắn gọn từ `visual_prompt` (tương tự `video_terms` của MoneyPrinterTurbo).
 *  2. Truy vấn Pexels Video API (ưu tiên) hoặc Pixabay Video API (dự phòng) theo đúng khung hình (`portrait` 9:16 hoặc `landscape` 16:9).
 *  3. Tải clip MP4 chất lượng HD về `storage/app/temp/` (ép IPv4 tránh treo cURL trên Windows).
 *  4. Dùng FFmpeg (`nice -n 10` trên Linux, `-threads 6`) cắt/lặp, crop-scale chuẩn `1080x1920` (hoặc `1920x1080`) @ 60fps
 *     khớp chính xác thời lượng `$durationMs` của giọng đọc Edge-TTS, đồng thời trích xuất 1 ảnh thumbnail `.jpg` cho Studio.
 */
class MoneyPrinterStockVideoService
{
    private readonly string $pexelsKey;

    private readonly string $pexelsBaseUrl;

    private readonly string $pixabayKey;

    private readonly string $pixabayBaseUrl;

    private readonly string $ffmpegBinary;

    private readonly int $maxThreads;

    private readonly int $nicePriority;

    public function __construct()
    {
        $this->pexelsKey = trim((string) config('services.pexels.api_key', ''));
        $this->pexelsBaseUrl = rtrim((string) config('services.pexels.base_url', 'https://api.pexels.com/videos'), '/');
        $this->pixabayKey = trim((string) config('services.pixabay.api_key', ''));
        $this->pixabayBaseUrl = rtrim((string) config('services.pixabay.base_url', 'https://pixabay.com/api/videos'), '/');
        $this->ffmpegBinary = (string) config('services.ffmpeg.ffmpeg_binary', '/usr/local/bin/ffmpeg');
        $this->maxThreads = max(1, min(6, (int) config('services.ffmpeg.max_threads', 6)));
        $this->nicePriority = (int) config('services.ffmpeg.nice_priority', 10);
    }

    /**
     * Kiểm tra dự án có bật chế độ MoneyPrinterTurbo Stock Video hay không.
     */
    public function shouldUseStockVideo(VideoProject $project): bool
    {
        return str_contains(strtolower((string) $project->master_style_prompt), '[money_printer_stock]');
    }

    /**
     * Tìm kiếm, tải về và chuẩn hóa clip Video Stock 60fps khớp chính xác `$durationMs` cho một phân cảnh.
     *
     * @return array{
     *     video_relative_path: string,
     *     video_full_path: string,
     *     image_relative_path: string|null,
     *     provider: string,
     *     search_query: string
     * }
     */
    public function fetchAndPrepareSceneClip(
        VideoProject $project,
        StoryboardScene $scene,
        string $filePrefix,
        int $durationMs
    ): array {
        $startTime = microtime(true);
        $searchQuery = $this->extractSearchKeywords((string) $scene->visual_prompt, (string) $project->title);
        $aspectRatio = $project->aspect_ratio === '16:9' ? '16:9' : '9:16';

        $downloadedTempPath = storage_path("app/temp/{$filePrefix}_stock_raw.mp4");
        $this->ensureParentDirectory($downloadedTempPath);

        $providerUsed = null;

        if ($this->pexelsKey !== '') {
            $remoteUrl = $this->searchPexelsVideoUrl($searchQuery, $aspectRatio, $scene->scene_order);
            if ($remoteUrl !== null) {
                $this->downloadFile($remoteUrl, $downloadedTempPath);
                $providerUsed = 'pexels';
            }
        }

        if ($providerUsed === null && $this->pixabayKey !== '') {
            $remoteUrl = $this->searchPixabayVideoUrl($searchQuery, $aspectRatio, $scene->scene_order);
            if ($remoteUrl !== null) {
                $this->downloadFile($remoteUrl, $downloadedTempPath);
                $providerUsed = 'pixabay';
            }
        }

        if ($providerUsed === null || ! is_file($downloadedTempPath) || filesize($downloadedTempPath) < 1024) {
            throw new RuntimeException(
                'Chưa tìm được Video Stock từ Pexels/Pixabay (vui lòng cấu hình PEXELS_API_KEY hoặc PIXABAY_API_KEY miễn phí trong .env).'
            );
        }

        $disk = Storage::disk('public');
        $videoRelativePath = "renders/{$filePrefix}_stock60fps.mp4";
        $videoFullPath = $disk->path($videoRelativePath);
        $this->ensureParentDirectory($videoFullPath);

        $this->normalizeClipWithFfmpeg(
            inputPath: $downloadedTempPath,
            outputPath: $videoFullPath,
            durationMs: $durationMs,
            aspectRatio: $aspectRatio
        );

        // Trích xuất ảnh đại diện .jpg để hiển thị ở Cổng kiểm duyệt 4 (CP4)
        $imageRelativePath = "renders/{$filePrefix}_stock_thumb.jpg";
        $imageFullPath = $disk->path($imageRelativePath);
        $this->extractThumbnail($videoFullPath, $imageFullPath);

        @unlink($downloadedTempPath);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->recordLog($project->id, $scene->id, $providerUsed, $searchQuery, $latencyMs);

        return [
            'video_relative_path' => $videoRelativePath,
            'video_full_path' => $videoFullPath,
            'image_relative_path' => is_file($imageFullPath) ? $imageRelativePath : null,
            'provider' => $providerUsed,
            'search_query' => $searchQuery,
        ];
    }

    /**
     * Rút trích 2–4 từ khóa tiếng Anh trọng tâm từ `visual_prompt` để tìm Video Stock chính xác nhất.
     */
    public function extractSearchKeywords(string $visualPrompt, string $fallbackTitle = 'nature technology'): string
    {
        $clean = preg_replace('/\[[^\]]+\]/', '', $visualPrompt) ?? $visualPrompt;
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $clean) ?? '');

        $stopWords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'shot', 'close',
            'up', 'angle', 'wide', 'cinematic', 'lighting', 'studio', 'photorealistic',
            'ultra', 'detailed', 'resolution', 'depth', 'field', 'shallow', 'render',
            'unreal', 'engine', 'style', 'modern', 'color', 'grading', 'warm', 'dramatic',
            'showing', 'representing', 'background', 'foreground', 'bokeh', 'hdr', '8k', '4k',
        ];

        $words = array_values(array_filter(
            preg_split('/\s+/', trim($clean)) ?: [],
            static fn (string $w): bool => strlen($w) >= 3 && ! in_array($w, $stopWords, true)
        ));

        $keywords = array_slice(array_values(array_unique($words)), 0, 3);
        if ($keywords === []) {
            return 'modern lifestyle business';
        }

        return implode(' ', $keywords);
    }

    private function searchPexelsVideoUrl(string $query, string $aspectRatio, int $sceneOrder): ?string
    {
        try {
            $orientation = $aspectRatio === '16:9' ? 'landscape' : 'portrait';
            $response = Http::timeout(15)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => $this->pexelsKey])
                ->get("{$this->pexelsBaseUrl}/search", [
                    'query' => $query,
                    'orientation' => $orientation,
                    'size' => 'medium',
                    'per_page' => 8,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $videos = (array) $response->json('videos', []);
            if ($videos === []) {
                return null;
            }

            $pickedVideo = $videos[$sceneOrder % count($videos)];
            $files = (array) ($pickedVideo['video_files'] ?? []);

            // Ưu tiên file MP4 có chiều rộng >= 720 và <= 1920
            usort($files, static fn (array $a, array $b): int => ((int) ($b['width'] ?? 0)) <=> ((int) ($a['width'] ?? 0)));
            foreach ($files as $file) {
                $link = (string) ($file['link'] ?? '');
                $w = (int) ($file['width'] ?? 0);
                if ($link !== '' && $w >= 720 && $w <= 1920) {
                    return $link;
                }
            }

            return (string) ($files[0]['link'] ?? '') ?: null;
        } catch (Throwable $e) {
            Log::warning('[MoneyPrinterStockVideoService] Pexels search error: ' . $e->getMessage());

            return null;
        }
    }

    private function searchPixabayVideoUrl(string $query, string $aspectRatio, int $sceneOrder): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get($this->pixabayBaseUrl . '/', [
                    'key' => $this->pixabayKey,
                    'q' => $query,
                    'video_type' => 'film',
                    'per_page' => 8,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $hits = (array) $response->json('hits', []);
            if ($hits === []) {
                return null;
            }

            $picked = $hits[$sceneOrder % count($hits)];
            $url = (string) (data_get($picked, 'videos.medium.url') ?? data_get($picked, 'videos.small.url') ?? '');

            return $url !== '' ? $url : null;
        } catch (Throwable $e) {
            Log::warning('[MoneyPrinterStockVideoService] Pixabay search error: ' . $e->getMessage());

            return null;
        }
    }

    private function downloadFile(string $remoteUrl, string $localPath): void
    {
        $response = Http::timeout(60)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->sink($localPath)
            ->get($remoteUrl);

        if (! $response->successful() || ! is_file($localPath) || filesize($localPath) === 0) {
            throw new RuntimeException("Không thể tải Video Stock từ URL: {$remoteUrl}");
        }
    }

    private function normalizeClipWithFfmpeg(
        string $inputPath,
        string $outputPath,
        int $durationMs,
        string $aspectRatio
    ): void {
        $durationSec = max(1.0, round($durationMs / 1000.0, 3));
        [$targetW, $targetH] = $aspectRatio === '16:9' ? [1920, 1080] : [1080, 1920];

        // Tự động lặp (-stream_loop -1) nếu clip stock ngắn hơn giọng đọc, crop-scale vừa khít khung hình và xuất 60fps
        $vf = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,fps=60,setsar=1,format=yuv420p',
            $targetW,
            $targetH,
            $targetW,
            $targetH
        );

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
            '-stream_loop',
            '-1',
            '-i',
            $inputPath,
            '-t',
            number_format($durationSec, 3, '.', ''),
            '-vf',
            $vf,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '21',
            '-x264-params',
            "threads={$this->maxThreads}",
            '-an',
            $outputPath,
        ];

        $cmd = PHP_OS_FAMILY === 'Linux'
            ? array_merge(['nice', '-n', (string) $this->nicePriority, $this->ffmpegBinary], $args)
            : array_merge([$this->ffmpegBinary], $args);

        $process = new Process($cmd);
        $process->setTimeout(240);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('FFmpeg xử lý Video Stock thất bại: ' . trim($process->getErrorOutput()));
        }
    }

    private function extractThumbnail(string $videoPath, string $thumbPath): void
    {
        $args = [
            '-y',
            '-threads',
            '2',
            '-ss',
            '0.5',
            '-i',
            $videoPath,
            '-frames:v',
            '1',
            '-q:v',
            '3',
            $thumbPath,
        ];
        $cmd = PHP_OS_FAMILY === 'Linux'
            ? array_merge(['nice', '-n', (string) $this->nicePriority, $this->ffmpegBinary], $args)
            : array_merge([$this->ffmpegBinary], $args);

        $proc = new Process($cmd);
        $proc->setTimeout(20);
        $proc->run();
    }

    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không thể tạo thư mục: {$dir}");
        }
    }

    private function recordLog(
        int $videoProjectId,
        int $storyboardSceneId,
        string $provider,
        string $query,
        int $latencyMs
    ): void {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $videoProjectId,
                'storyboard_scene_id' => $storyboardSceneId,
                'provider' => $provider,
                'model_name' => 'moneyprinter-stock',
                'action_type' => 'fetch_stock_video',
                'status' => 'success',
                'cost_usd' => 0.0000,
                'latency_ms' => $latencyMs,
                'request_payload' => ['query' => $query],
                'response_payload' => ['provider' => $provider],
                'error_message' => null,
            ]);
        } catch (Throwable) {
            // Không làm gián đoạn luồng chính
        }
    }
}
