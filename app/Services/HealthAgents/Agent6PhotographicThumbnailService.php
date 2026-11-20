<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * AGENT AI 6 — THIẾT KẾ ẢNH BÌA PHOTOGRAPHIC (Photographic Thumbnail Designer).
 *
 * Quy trình 2 bước đảm bảo ĐẸP NHƯ ẢNH CHỤP THẬT + 100% KHÔNG SAI CHÍNH TẢ TIẾNG VIỆT:
 *  - Bước 1: Tạo ảnh nền Photographic thực tế (không chữ) qua Fal.ai Flux Schnell (nếu có key)
 *            hoặc trích frame sắc nét từ video châu Á của Agent 4.
 *  - Bước 2: Dùng Chromium/Playwright (`scripts/render_health_thumbnail.mjs`) vẽ lớp Typography
 *            tiêu đề tiếng Việt + badge thương hiệu "SỐNG KHỎE MỖI NGÀY" sắc nét chuẩn 1080x1920.
 */
class Agent6PhotographicThumbnailService
{
    public function generateThumbnailForProject(VideoProject $project, bool $forceRegenerate = false): VideoProject
    {
        if (
            ! $forceRegenerate
            && $project->thumbnail_path
            && Storage::disk('public')->exists($project->thumbnail_path)
        ) {
            return $project;
        }

        $project->load('scenes');
        $disk = Storage::disk('public');

        $seoMeta = is_array($project->seo_meta_json) ? $project->seo_meta_json : [];
        $headline = trim((string) ($seoMeta['cover_headline'] ?? ''));
        if ($headline === '') {
            $headline = Str::words($project->title, 7, '');
        }
        // Dọn sạch mọi thẻ kỹ thuật trước khi vẽ lên ảnh bìa
        $headline = trim((string) preg_replace('/@\{[a-zA-Z0-9_:\-]+\}|\[[^\]]+\]/u', '', $headline));

        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');
        $bgPrompt = trim((string) ($seoMeta['thumbnail_visual_prompt']
            ?? 'Photorealistic warm daylight studio photography of healthy East Asian lifestyle, fresh herbal tea, natural herbs and organic food, shallow depth of field, clean editorial composition, no text, no watermark'));

        $bgTempPath = storage_path("app/temp/project_{$project->id}_cover_bg.jpg");
        $this->ensureParentDirectory($bgTempPath);

        $bgReady = $this->generatePhotographicBackground($project, $bgPrompt, $bgTempPath);

        $thumbRel = sprintf('thumbnails/project_%d_cover_%s.jpg', $project->id, date('Ymd_His'));
        $thumbFull = $disk->path($thumbRel);
        $this->ensureParentDirectory($thumbFull);

        $rendered = $this->renderTypographyOverlay(
            bgImagePath: $bgReady ? $bgTempPath : null,
            outputPath: $thumbFull,
            headline: $headline,
            channelName: $channelName,
            subtitle: 'Kiến Thức Sức Khỏe Chính Thống'
        );

        if (! $rendered && $bgReady && is_file($bgTempPath)) {
            copy($bgTempPath, $thumbFull);
        }

        @unlink($bgTempPath);

        if (is_file($thumbFull)) {
            $project->update([
                'thumbnail_path' => $thumbRel,
            ]);
        }

        return $project->refresh();
    }

    private function generatePhotographicBackground(VideoProject $project, string $prompt, string $destPath): bool
    {
        // 1. Nếu có Fal.ai API Key -> tạo ảnh chụp Photographic chuyên nghiệp không chứa chữ
        $falKey = AppSetting::getValue('fal_api_key', (string) config('services.fal.api_key', ''));
        if ($falKey !== '') {
            try {
                $response = Http::timeout(45)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders(['Authorization' => 'Key ' . $falKey])
                    ->post('https://fal.run/fal-ai/flux/schnell', [
                        'prompt' => $prompt . ', photorealistic DSLR photography, natural lighting, 8k, no text, no letters, no watermark',
                        'image_size' => 'portrait_16_9',
                        'num_inference_steps' => 4,
                        'num_images' => 1,
                    ]);

                if ($response->successful()) {
                    $imgUrl = (string) data_get($response->json(), 'images.0.url', '');
                    if ($imgUrl !== '') {
                        $dl = Http::timeout(45)->sink($destPath)->get($imgUrl);
                        if ($dl->successful() && is_file($destPath) && filesize($destPath) > 2048) {
                            return true;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('[Agent6Thumbnail] Fal photographic background failed, using scene frame: ' . $e->getMessage());
            }
        }

        // 2. Fallback thông minh: lấy frame ảnh chụp thật đẹp nhất từ các phân cảnh video châu Á của Agent 4
        foreach ($project->scenes as $scene) {
            if ($scene->image_file_path && Storage::disk('public')->exists($scene->image_file_path)) {
                $src = Storage::disk('public')->path($scene->image_file_path);
                if (is_file($src) && filesize($src) > 2048) {
                    copy($src, $destPath);

                    return true;
                }
            }
        }

        return false;
    }

    private function renderTypographyOverlay(
        ?string $bgImagePath,
        string $outputPath,
        string $headline,
        string $channelName,
        string $subtitle
    ): bool {
        $scriptPath = base_path('scripts/render_health_thumbnail.mjs');
        if (! is_file($scriptPath)) {
            return false;
        }

        $payloadFile = storage_path('app/temp/thumb_payload_' . uniqid() . '.json');
        $this->ensureParentDirectory($payloadFile);

        file_put_contents($payloadFile, json_encode([
            'bgImagePath' => $bgImagePath ?? '',
            'outputPath' => $outputPath,
            'headline' => $headline,
            'channelName' => $channelName,
            'subtitle' => $subtitle,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $nodeBinary = (string) config('services.node.binary', 'node');
        $process = new Process([$nodeBinary, $scriptPath, $payloadFile], base_path());
        $process->setTimeout(60);
        $process->run();

        @unlink($payloadFile);

        if (! $process->isSuccessful()) {
            Log::warning('[Agent6Thumbnail] Node renderer error: ' . mb_substr($process->getErrorOutput(), 0, 300));
        }

        return $process->isSuccessful() && is_file($outputPath) && filesize($outputPath) > 2048;
    }

    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
