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
        // Dọn sạch mọi thẻ kỹ thuật và giới hạn tối đa 7 từ để ảnh bìa thoáng, dễ đọc
        $headline = trim((string) preg_replace('/@\{[a-zA-Z0-9_:\-]+\}|\[[^\]]+\]/u', '', $headline));
        $headlineWords = preg_split('/\s+/u', $headline) ?: [];
        if (count($headlineWords) > 7) {
            $headline = implode(' ', array_slice($headlineWords, 0, 7));
        }
        $badgeRed = trim((string) ($seoMeta['cover_badge_red'] ?? ''));

        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');
        $bgPrompt = trim((string) ($seoMeta['thumbnail_visual_prompt']
            ?? 'Bright sunlit editorial studio food and herbal photography of healthy green vegetables, fresh herbs and warm herbal tea on a rustic wooden table, blurred sunny green garden bokeh background at top, vibrant natural colors, no text, no watermark'));

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
            badgeRed: $badgeRed
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
        $preferred = AppSetting::getValue('thumbnail_provider', 'openai');
        $cleanPrompt = $prompt . ', photorealistic editorial DSLR photography, warm natural lighting, sharp focus, vertical 9:16 portrait composition, strictly NO text, NO words, NO letters, NO watermark';

        // Xây dựng thứ tự ưu tiên theo lựa chọn của người dùng, luôn có Gemini Image & Pexels DSLR Photo dự phòng trước khi lấy frame video
        $order = match ($preferred) {
            'muse_ai' => ['muse_ai', 'openai', 'fal', 'gemini_image', 'pexels_photo'],
            'fal' => ['fal', 'openai', 'muse_ai', 'gemini_image', 'pexels_photo'],
            'scene_frame' => [],
            default => ['openai', 'muse_ai', 'fal', 'gemini_image', 'pexels_photo'],
        };

        foreach ($order as $provider) {
            if ($provider === 'openai' && $this->tryOpenAiPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'muse_ai' && $this->tryMuseAiPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'fal' && $this->tryFalPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'gemini_image' && $this->tryGeminiImagePhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'pexels_photo' && $this->tryPexelsPhotoSearch($project->title . ' ' . $prompt, $destPath)) {
                return true;
            }
        }

        // Fallback thông minh: lấy frame ảnh chụp thật đẹp nhất từ các phân cảnh video châu Á của Agent 4
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

    /**
     * Sinh ảnh nền Photographic bằng ChatGPT / OpenAI Image API (`gpt-image-1` hoặc `dall-e-3`).
     */
    private function tryOpenAiPhotographic(string $prompt, string $destPath): bool
    {
        $apiKey = AppSetting::getValue('openai_api_key', '');
        if ($apiKey === '') {
            return false;
        }

        $model = AppSetting::getValue('openai_image_model', 'gpt-image-1');
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $model === 'dall-e-3' ? '1024x1792' : '1024x1536',
        ];
        if ($model === 'dall-e-3') {
            $payload['quality'] = 'standard';
            $payload['style'] = 'natural';
        }

        try {
            $response = Http::timeout(90)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->acceptJson()
                ->post('https://api.openai.com/v1/images/generations', $payload);

            if (! $response->successful()) {
                Log::warning('[Agent6Thumbnail] OpenAI Image API HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 250));

                return false;
            }

            $b64 = (string) data_get($response->json(), 'data.0.b64_json', '');
            if ($b64 !== '') {
                file_put_contents($destPath, base64_decode($b64));

                return is_file($destPath) && filesize($destPath) > 2048;
            }

            $url = (string) data_get($response->json(), 'data.0.url', '');
            if ($url !== '') {
                $dl = Http::timeout(60)->sink($destPath)->get($url);

                return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
            }
        } catch (Throwable $e) {
            Log::warning('[Agent6Thumbnail] OpenAI error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Sinh ảnh nền Photographic bằng Muse AI API (hỗ trợ Custom Endpoint & cả URL lẫn Base64).
     */
    private function tryMuseAiPhotographic(string $prompt, string $destPath): bool
    {
        $apiKey = AppSetting::getValue('muse_ai_api_key', '');
        $endpoint = AppSetting::getValue('muse_ai_endpoint', 'https://api.museai.io/v1/images/generations');
        if ($apiKey === '' || $endpoint === '') {
            return false;
        }

        try {
            $response = Http::timeout(90)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'x-api-key' => $apiKey,
                ])
                ->acceptJson()
                ->post($endpoint, [
                    'prompt' => $prompt,
                    'aspect_ratio' => '9:16',
                    'style' => 'photographic',
                    'n' => 1,
                ]);

            if (! $response->successful()) {
                Log::warning('[Agent6Thumbnail] Muse AI HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 250));

                return false;
            }

            $json = $response->json();
            $b64 = (string) (data_get($json, 'data.0.b64_json') ?: data_get($json, 'image_base64') ?: '');
            if ($b64 !== '') {
                file_put_contents($destPath, base64_decode($b64));

                return is_file($destPath) && filesize($destPath) > 2048;
            }

            $url = (string) (data_get($json, 'data.0.url') ?: data_get($json, 'images.0.url') ?: data_get($json, 'image_url') ?: data_get($json, 'url') ?: '');
            if ($url !== '') {
                $dl = Http::timeout(60)->sink($destPath)->get($url);

                return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
            }
        } catch (Throwable $e) {
            Log::warning('[Agent6Thumbnail] Muse AI error: ' . $e->getMessage());
        }

        return false;
    }

    private function tryFalPhotographic(string $prompt, string $destPath): bool
    {
        $falKey = AppSetting::getValue('fal_api_key', (string) config('services.fal.api_key', ''));
        if ($falKey === '') {
            return false;
        }

        try {
            $response = Http::timeout(45)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => 'Key ' . $falKey])
                ->post('https://fal.run/fal-ai/flux/schnell', [
                    'prompt' => $prompt,
                    'image_size' => 'portrait_16_9',
                    'num_inference_steps' => 4,
                    'num_images' => 1,
                ]);

            if ($response->successful()) {
                $imgUrl = (string) data_get($response->json(), 'images.0.url', '');
                if ($imgUrl !== '') {
                    $dl = Http::timeout(45)->sink($destPath)->get($imgUrl);

                    return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
                }
            }
        } catch (Throwable $e) {
            Log::warning('[Agent6Thumbnail] Fal error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Dự phòng 1: Sinh ảnh nền Photographic qua Gemini Image Generation (sử dụng sẵn Gemini API Key).
     */
    private function tryGeminiImagePhotographic(string $prompt, string $destPath): bool
    {
        $apiKey = AppSetting::getValue('gemini_api_key', (string) config('services.gemini.api_key', ''));
        if ($apiKey === '') {
            return false;
        }

        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');
        foreach (['gemini-2.5-flash-image', 'gemini-2.0-flash-exp-image-generation'] as $model) {
            try {
                $response = Http::timeout(60)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->acceptJson()
                    ->post("{$baseUrl}/{$model}:generateContent?key={$apiKey}", [
                        'contents' => [
                            [
                                'role' => 'user',
                                'parts' => [['text' => $prompt]],
                            ],
                        ],
                        'generationConfig' => [
                            'responseModalities' => ['IMAGE'],
                        ],
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $parts = data_get($response->json(), 'candidates.0.content.parts', []);
                foreach ((array) $parts as $part) {
                    $b64 = (string) (data_get($part, 'inlineData.data') ?: data_get($part, 'inline_data.data') ?: '');
                    if ($b64 !== '') {
                        file_put_contents($destPath, base64_decode($b64));
                        if (is_file($destPath) && filesize($destPath) > 2048) {
                            return true;
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning("[Agent6Thumbnail] Gemini Image ({$model}) error: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Dự phòng 2: Tìm ảnh chụp DSLR sắc nét từ Pexels Photo Search API (tránh dùng frame video mờ).
     */
    private function tryPexelsPhotoSearch(string $context, string $destPath): bool
    {
        $pexelsKey = AppSetting::getValue('pexels_api_key', (string) config('services.pexels.api_key', ''));
        if ($pexelsKey === '') {
            return false;
        }

        $lower = mb_strtolower($context);
        $query = 'healthy green vegetables herbal tea';
        if (str_contains($lower, 'tiểu đường') || str_contains($lower, 'đường huyết') || str_contains($lower, 'glucose')) {
            $query = 'blood glucose meter healthy food';
        } elseif (str_contains($lower, 'gan') || str_contains($lower, 'thận') || str_contains($lower, 'đông y')) {
            $query = 'chinese herbal tea healthy herbs';
        } elseif (str_contains($lower, 'người lớn tuổi') || str_contains($lower, 'bác sĩ')) {
            $query = 'asian doctor healthy lifestyle';
        }

        try {
            $response = Http::timeout(20)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => $pexelsKey])
                ->get('https://api.pexels.com/v1/search', [
                    'query' => $query,
                    'orientation' => 'portrait',
                    'per_page' => 6,
                ]);

            if (! $response->successful()) {
                return false;
            }

            $photoUrl = (string) (data_get($response->json(), 'photos.0.src.large2x')
                ?: data_get($response->json(), 'photos.0.src.large')
                ?: '');

            if ($photoUrl !== '') {
                $dl = Http::timeout(30)->sink($destPath)->get($photoUrl);

                return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
            }
        } catch (Throwable $e) {
            Log::warning('[Agent6Thumbnail] Pexels Photo error: ' . $e->getMessage());
        }

        return false;
    }

    private function renderTypographyOverlay(
        ?string $bgImagePath,
        string $outputPath,
        string $headline,
        string $channelName,
        string $badgeRed
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
            'badgeRed' => $badgeRed,
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
