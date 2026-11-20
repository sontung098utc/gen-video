<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\GenerationLog;
use App\Models\StockMediaAsset;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AGENT AI 4 — CHUYÊN VIÊN TÌM VIDEO STOCK NGƯỜI CHÂU Á (Asian Footage Hunter).
 *
 * Thứ tự tìm kiếm ưu tiên cho từng phân cảnh:
 *  1. Kho Video Nội Bộ (`stock_media_assets`) do người dùng tải lên (người Việt Nam / Trung Quốc).
 *  2. Pexels Video API (lọc thông minh từ khóa châu Á: asian, vietnamese, chinese, herbal...).
 *  3. Pixabay Video API (dự phòng).
 * Sau đó chuẩn hóa bằng FFmpeg về 1080x1920 @ 60fps khớp chính xác `duration_ms` của giọng đọc.
 */
class Agent4AsianFootageHunterService
{
    private readonly string $ffmpegBinary;

    private readonly int $maxThreads;

    private readonly int $nicePriority;

    /** @var array<int, int> */
    private array $usedLocalAssetIds = [];

    /** @var array<int, string> */
    private array $usedRemoteUrls = [];

    public function __construct()
    {
        $this->ffmpegBinary = (string) config('services.ffmpeg.ffmpeg_binary', 'ffmpeg');
        $this->maxThreads = max(1, min(6, (int) config('services.ffmpeg.max_threads', 6)));
        $this->nicePriority = (int) config('services.ffmpeg.nice_priority', 10);
    }

    /**
     * Tìm kiếm và chuẩn hóa video châu Á cho toàn bộ phân cảnh của dự án.
     */
    public function huntFootageForProject(VideoProject $project, bool $forceRegenerate = false): VideoProject
    {
        $this->usedLocalAssetIds = [];
        $this->usedRemoteUrls = [];
        $project->load('scenes');

        foreach ($project->scenes as $scene) {
            if (
                ! $forceRegenerate
                && $scene->video_file_path
                && Storage::disk('public')->exists($scene->video_file_path)
            ) {
                continue;
            }

            $durationMs = max(2500, (int) $scene->duration_ms);
            $prefix = sprintf('project_%d_scene_%02d_a4_%s', $project->id, $scene->scene_order, substr(md5((string) microtime(true)), 0, 6));

            $result = $this->fetchAndPrepareScene($project, $scene, $prefix, $durationMs);

            $scene->update([
                'video_file_path' => $result['video_relative_path'],
                'image_file_path' => $result['image_relative_path'],
            ]);
        }

        return $project->refresh()->load('scenes');
    }

    /**
     * @return array{video_relative_path: string, image_relative_path: string|null, provider: string, search_query: string}
     */
    public function fetchAndPrepareScene(
        VideoProject $project,
        StoryboardScene $scene,
        string $filePrefix,
        int $durationMs
    ): array {
        $startTime = microtime(true);
        $aspectRatio = $project->aspect_ratio === '16:9' ? '16:9' : '9:16';
        $searchQuery = $this->buildAsianQuery((string) $scene->visual_prompt, (string) $project->title);

        $rawTempPath = storage_path("app/temp/{$filePrefix}_raw.mp4");
        $this->ensureParentDirectory($rawTempPath);

        $providerUsed = null;

        // 1. Ưu tiên Kho Video Nội Bộ (người Việt Nam / Trung Quốc)
        $localAsset = $this->findBestLocalAsset($searchQuery . ' ' . $scene->narration_text);
        if ($localAsset !== null) {
            $localFull = Storage::disk('public')->path($localAsset->file_path);
            if (is_file($localFull) && filesize($localFull) > 1024) {
                copy($localFull, $rawTempPath);
                $this->usedLocalAssetIds[] = $localAsset->id;
                $providerUsed = 'local_library';
            }
        }

        // 2. Tìm trên Pexels Video API (ưu tiên châu Á)
        $pexelsKey = AppSetting::getValue('pexels_api_key', (string) config('services.pexels.api_key', ''));
        if ($providerUsed === null && $pexelsKey !== '') {
            $remoteUrl = $this->searchPexels($pexelsKey, $searchQuery, $aspectRatio, $scene->scene_order);
            if ($remoteUrl !== null && $this->downloadVideo($remoteUrl, $rawTempPath)) {
                $this->usedRemoteUrls[] = $remoteUrl;
                $providerUsed = 'pexels_asian';
            }
        }

        // 3. Dự phòng Pixabay Video API (ưu tiên châu Á)
        $pixabayKey = AppSetting::getValue('pixabay_api_key', (string) config('services.pixabay.api_key', ''));
        if ($providerUsed === null && $pixabayKey !== '') {
            $remoteUrl = $this->searchPixabay($pixabayKey, $searchQuery, $scene->scene_order);
            if ($remoteUrl !== null && $this->downloadVideo($remoteUrl, $rawTempPath)) {
                $this->usedRemoteUrls[] = $remoteUrl;
                $providerUsed = 'pixabay_asian';
            }
        }

        if ($providerUsed === null || ! is_file($rawTempPath) || filesize($rawTempPath) < 1024) {
            throw new RuntimeException(
                'Agent AI 4 chưa tìm được video phù hợp cho Cảnh ' . $scene->scene_order
                . '. Vui lòng cấu hình Pexels API Key / Pixabay API Key tại trang Cài đặt (/settings) hoặc tải video vào Kho Video Châu Á (/stock-library).'
            );
        }

        $disk = Storage::disk('public');
        $videoRelPath = "renders/{$filePrefix}_asian60fps.mp4";
        $videoFullPath = $disk->path($videoRelPath);
        $this->ensureParentDirectory($videoFullPath);

        $this->normalizeClipWithFfmpeg($rawTempPath, $videoFullPath, $durationMs, $aspectRatio);

        $imageRelPath = "renders/{$filePrefix}_thumb.jpg";
        $imageFullPath = $disk->path($imageRelPath);
        $extracted = $this->extractThumbnailFrame($videoFullPath, $imageFullPath);

        @unlink($rawTempPath);

        $this->log($project->id, $scene->id, $providerUsed, $searchQuery, (int) round((microtime(true) - $startTime) * 1000));

        return [
            'video_relative_path' => $videoRelPath,
            'image_relative_path' => $extracted ? $imageRelPath : null,
            'provider' => $providerUsed,
            'search_query' => $searchQuery,
        ];
    }

    /**
     * Tự động bổ sung từ khóa bối cảnh châu Á (Việt Nam / Trung Quốc) nếu từ khóa chưa có.
     */
    private function buildAsianQuery(string $visualPrompt, string $fallbackTitle): string
    {
        $clean = trim((string) preg_replace('/\[[^\]]+\]/', '', $visualPrompt));
        $clean = trim((string) preg_replace('/[^\w\s\-]+/u', ' ', $clean));
        $words = array_values(array_filter(preg_split('/\s+/', $clean) ?: [], static fn (string $w): bool => strlen($w) >= 2));

        $query = implode(' ', array_slice($words, 0, 5));
        if ($query === '') {
            $query = 'asian healthy lifestyle herbal tea';
        }

        $lower = strtolower($query);
        $hasAsianMarker = str_contains($lower, 'asian')
            || str_contains($lower, 'vietnam')
            || str_contains($lower, 'chinese')
            || str_contains($lower, 'herb')
            || str_contains($lower, 'tea')
            || str_contains($lower, 'vegetable')
            || str_contains($lower, 'fruit');

        if (! $hasAsianMarker) {
            $query = 'asian ' . $query;
        }

        return trim($query);
    }

    private function findBestLocalAsset(string $context): ?StockMediaAsset
    {
        $assets = StockMediaAsset::query()->where('is_active', true)->get();
        if ($assets->isEmpty()) {
            return null;
        }

        $tokens = array_filter(
            preg_split('/\s+/u', mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $context))) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 2
        );

        $best = null;
        $bestScore = -1;

        foreach ($assets as $asset) {
            if (in_array($asset->id, $this->usedLocalAssetIds, true)) {
                continue;
            }
            $haystack = mb_strtolower($asset->title . ' ' . $asset->tags . ' ' . $asset->category);
            $score = 0;
            foreach ($tokens as $tok) {
                if (str_contains($haystack, $tok)) {
                    $score += 2;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $asset;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    private function searchPexels(string $apiKey, string $query, string $aspectRatio, int $sceneOrder): ?string
    {
        $orientation = $aspectRatio === '16:9' ? 'landscape' : 'portrait';
        $queries = array_values(array_unique([
            $query,
            'asian ' . implode(' ', array_slice(explode(' ', str_replace('asian', '', strtolower($query))), 0, 2)),
            'asian healthy tea wellness',
        ]));

        foreach ($queries as $q) {
            $q = trim($q);
            if ($q === '') {
                continue;
            }

            try {
                $response = Http::timeout(20)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders(['Authorization' => $apiKey])
                    ->get('https://api.pexels.com/videos/search', [
                        'query' => $q,
                        'orientation' => $orientation,
                        'per_page' => 12,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $videos = $response->json('videos', []);
                if (! is_array($videos) || $videos === []) {
                    continue;
                }

                // Chọn video chưa dùng ở cảnh trước, ưu tiên HD
                $offset = ($sceneOrder - 1) % count($videos);
                $ordered = array_merge(array_slice($videos, $offset), array_slice($videos, 0, $offset));

                foreach ($ordered as $vid) {
                    $files = is_array($vid['video_files'] ?? null) ? $vid['video_files'] : [];
                    $bestUrl = $this->pickBestPexelsFile($files);
                    if ($bestUrl !== null && ! in_array($bestUrl, $this->usedRemoteUrls, true)) {
                        return $bestUrl;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('[Agent4AsianFootage] Pexels error: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $files
     */
    private function pickBestPexelsFile(array $files): ?string
    {
        $bestUrl = null;
        $bestScore = -1;

        foreach ($files as $f) {
            if (! is_array($f) || empty($f['link']) || ($f['file_type'] ?? '') !== 'video/mp4') {
                continue;
            }
            $width = (int) ($f['width'] ?? 0);
            $height = (int) ($f['height'] ?? 0);
            if ($width < 480) {
                continue;
            }
            // Ưu tiên độ phân giải Full HD (~720-1080px cạnh ngắn), tránh 4K quá nặng
            $shortSide = min($width, $height);
            $score = ($shortSide >= 720 && $shortSide <= 1280) ? 100 : ($shortSide > 1280 ? 60 : 30);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUrl = (string) $f['link'];
            }
        }

        return $bestUrl;
    }

    private function searchPixabay(string $apiKey, string $query, int $sceneOrder): ?string
    {
        $queries = array_values(array_unique([$query, 'asian health wellness', 'herbal tea nature']));

        foreach ($queries as $q) {
            try {
                $response = Http::timeout(20)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->get('https://pixabay.com/api/videos/', [
                        'key' => $apiKey,
                        'q' => $q,
                        'per_page' => 10,
                        'safesearch' => 'true',
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $hits = $response->json('hits', []);
                if (! is_array($hits) || $hits === []) {
                    continue;
                }

                $offset = ($sceneOrder - 1) % count($hits);
                $ordered = array_merge(array_slice($hits, $offset), array_slice($hits, 0, $offset));

                foreach ($ordered as $hit) {
                    $url = (string) (data_get($hit, 'videos.medium.url') ?: data_get($hit, 'videos.large.url') ?: data_get($hit, 'videos.small.url'));
                    if ($url !== '' && ! in_array($url, $this->usedRemoteUrls, true)) {
                        return $url;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('[Agent4AsianFootage] Pixabay error: ' . $e->getMessage());
            }
        }

        return null;
    }

    private function downloadVideo(string $url, string $destPath): bool
    {
        try {
            $response = Http::timeout(60)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->sink($destPath)
                ->get($url);

            return $response->successful() && is_file($destPath) && filesize($destPath) > 2048;
        } catch (Throwable $e) {
            Log::warning('[Agent4AsianFootage] Download failed: ' . $e->getMessage());

            return false;
        }
    }

    private function normalizeClipWithFfmpeg(string $inputPath, string $outputPath, int $durationMs, string $aspectRatio): void
    {
        $width = $aspectRatio === '16:9' ? 1920 : 1080;
        $height = $aspectRatio === '16:9' ? 1080 : 1920;
        $durationSec = number_format(max(1.5, $durationMs / 1000.0), 3, '.', '');

        $vf = "scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},fps=60,setsar=1,format=yuv420p";

        $cmd = [];
        if (PHP_OS_FAMILY !== 'Windows' && $this->nicePriority > 0) {
            $cmd = ['nice', '-n', (string) $this->nicePriority];
        }

        $cmd = array_merge($cmd, [
            $this->ffmpegBinary,
            '-y',
            '-threads',
            (string) $this->maxThreads,
            '-stream_loop',
            '-1',
            '-i',
            $inputPath,
            '-t',
            $durationSec,
            '-vf',
            $vf,
            '-an',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '22',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
            $outputPath,
        ]);

        $process = new Process($cmd);
        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            throw new RuntimeException('FFmpeg chuẩn hóa video châu Á thất bại: ' . mb_substr($process->getErrorOutput(), -400));
        }
    }

    private function extractThumbnailFrame(string $videoPath, string $imagePath): bool
    {
        $process = new Process([
            $this->ffmpegBinary,
            '-y',
            '-ss',
            '00:00:00.800',
            '-i',
            $videoPath,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $imagePath,
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() && is_file($imagePath);
    }

    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function log(int $projectId, int $sceneId, string $provider, string $query, int $latencyMs): void
    {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $projectId,
                'storyboard_scene_id' => $sceneId,
                'provider' => $provider,
                'model_name' => 'agent4_asian_footage',
                'action_type' => 'fetch_asian_video',
                'status' => 'success',
                'cost_usd' => 0.0,
                'latency_ms' => $latencyMs,
                'request_payload' => ['query' => $query],
            ]);
        } catch (Throwable) {
            // Bỏ qua
        }
    }
}
