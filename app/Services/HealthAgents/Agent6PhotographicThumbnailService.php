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
            $headlineWords = array_slice($headlineWords, 0, 7);
            while (count($headlineWords) > 3 && in_array(mb_strtolower((string) end($headlineWords)), ['mà', 'của', 'và', 'khi', 'cho', 'với', 'ở', 'tại', 'để', 'thì'], true)) {
                array_pop($headlineWords);
            }
            $headline = implode(' ', $headlineWords);
        }
        $badgeRed = trim((string) ($seoMeta['cover_badge_red'] ?? ''));

        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');
        $bgPrompt = trim((string) ($seoMeta['thumbnail_visual_prompt']
            ?? 'Bright sunlit editorial studio food and herbal photography of healthy green vegetables, fresh herbs and warm herbal tea on a rustic wooden table, blurred sunny green garden bokeh background at top, vibrant natural colors, no text, no watermark'));

        $thumbRel = sprintf('thumbnails/project_%d_cover_%s.jpg', $project->id, date('Ymd_His'));
        $thumbFull = $disk->path($thumbRel);
        $this->ensureParentDirectory($thumbFull);

        $mode = AppSetting::getValue('thumbnail_mode', 'hybrid');
        if ($mode === 'full_ai_poster') {
            $posterPrompt = "Create a vibrant 9:16 vertical Vietnamese health infographic poster for channel '{$channelName}' matching this exact layout:
1. Top-left: small green leaf-person wellness logo with text 'SỐNG KHỎE MỖI NGÀY' on a bright sunlit green-leaf bokeh background.
2. Upper hero (top 38%): expressive photorealistic Vietnamese/East Asian person or clear subject ({$bgPrompt}) with a small red triangle '!' warning icon.
3. Center typography stack (3 slightly tilted horizontal paint-brush stroke banners):
   - Top red brush banner with bold white italic text: '{$badgeRed}'
   - Middle bright yellow brush banner with giant dark-green bold italic text: '{$headline}'
   - Bottom dark-green brush banner with bold white italic text + red underline swoop.
4. Bottom row: 4 small circular yellow-bordered photographic thumbnails illustrating the key points, above a smooth layered green wave footer with fresh green leaves at bottom-left.
Ensure Vietnamese spelling is 100% accurate and crisp.";
            $posterReady = $this->generatePhotographicBackground($project, $posterPrompt, $thumbFull, true);
            if ($posterReady && is_file($thumbFull)) {
                $project->update(['thumbnail_path' => $thumbRel]);

                return $project->refresh();
            }
        }

        $bgTempPath = storage_path("app/temp/project_{$project->id}_cover_bg_" . uniqid() . '.jpg');
        $this->ensureParentDirectory($bgTempPath);

        $bgReady = $this->generatePhotographicBackground($project, $bgPrompt, $bgTempPath, false);

        // Lấy tối đa 4 ảnh phân cảnh từ Agent 4 để hiển thị 4 vòng tròn minh họa ở chân ảnh bìa
        $circleImages = [];
        foreach ($project->scenes as $scene) {
            if ($scene->image_file_path && $disk->exists($scene->image_file_path)) {
                $full = $disk->path($scene->image_file_path);
                if (is_file($full) && filesize($full) > 1024) {
                    $circleImages[] = $full;
                }
            }
            if (count($circleImages) >= 4) {
                break;
            }
        }

        $rendered = $this->renderTypographyOverlay(
            bgImagePath: $bgReady ? $bgTempPath : null,
            outputPath: $thumbFull,
            headline: $headline,
            channelName: $channelName,
            badgeRed: $badgeRed,
            circleImages: $circleImages
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

    private function generatePhotographicBackground(VideoProject $project, string $prompt, string $destPath, bool $allowText = false): bool
    {
        $preferred = AppSetting::getValue('thumbnail_provider', 'sangtao');
        $cleanPrompt = $allowText
            ? $prompt
            : $prompt . ', photorealistic editorial DSLR photography, warm natural lighting, sharp focus, vertical 9:16 portrait composition, strictly NO text, NO words, NO letters, NO watermark';

        $allProviders = ['sangtao', 'openai', 'gemini_image', 'grok', 'deepseek', 'muse_ai', 'fal', 'pexels_photo'];
        $order = match ($preferred) {
            'scene_frame' => [],
            default => array_values(array_unique(array_merge([$preferred], $allProviders))),
        };

        foreach ($order as $provider) {
            if ($provider === 'sangtao' && $this->trySangtaoAiPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'openai' && $this->tryOpenAiPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'gemini_image' && $this->tryGeminiImagePhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'grok' && $this->tryGrokPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'deepseek' && $this->tryDeepSeekPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'muse_ai' && $this->tryMuseAiPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if ($provider === 'fal' && $this->tryFalPhotographic($cleanPrompt, $destPath)) {
                return true;
            }
            if (! $allowText && $provider === 'pexels_photo' && $this->tryPexelsPhotoSearch($project->title . ' ' . $prompt, $destPath)) {
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
     * Sinh ảnh qua Sangtao.ai (`POST /v1/generate` -> poll `GET /v1/jobs/{job_id}`).
     */
    private function trySangtaoAiPhotographic(string $prompt, string $destPath): bool
    {
        $apiKey = AppSetting::getValue('sangtao_api_key', '');
        if ($apiKey === '') {
            return false;
        }

        $baseUrl = rtrim(AppSetting::getValue('sangtao_endpoint', 'https://api.tramsangtao.com/v1'), '/');
        $model = AppSetting::getValue('sangtao_model', 'nano-banana-pro');

        try {
            $resp = Http::timeout(45)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->acceptJson()
                ->post("{$baseUrl}/generate", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'params' => [
                        'aspect_ratio' => '9:16',
                        'resolution' => '2k',
                    ],
                ]);

            if (! $resp->successful()) {
                Log::warning('[Agent6Thumbnail] Sangtao.ai HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 250));

                return false;
            }

            $json = $resp->json();
            $directUrl = (string) (data_get($json, 'outputs.0') ?: data_get($json, 'data.0.url') ?: data_get($json, 'url') ?: '');
            if ($directUrl !== '') {
                $dl = Http::timeout(60)->sink($destPath)->get($directUrl);

                return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
            }

            $jobId = (string) (data_get($json, 'job_id') ?: data_get($json, 'id') ?: data_get($json, 'data.job_id') ?: '');
            if ($jobId === '') {
                return false;
            }

            // Poll tối đa 25 lần (50 giây)
            for ($i = 0; $i < 25; $i++) {
                sleep(2);
                $poll = Http::timeout(20)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                    ->acceptJson()
                    ->get("{$baseUrl}/jobs/{$jobId}");

                if (! $poll->successful()) {
                    continue;
                }

                $pJson = $poll->json();
                $status = strtolower((string) (data_get($pJson, 'status') ?: data_get($pJson, 'state') ?: ''));
                $outUrl = (string) (data_get($pJson, 'outputs.0')
                    ?: data_get($pJson, 'outputs.0.url')
                    ?: data_get($pJson, 'result.url')
                    ?: data_get($pJson, 'result.images.0')
                    ?: '');

                if ($outUrl !== '') {
                    $dl = Http::timeout(60)->sink($destPath)->get($outUrl);

                    return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
                }

                if (in_array($status, ['failed', 'error', 'cancelled'], true)) {
                    Log::warning('[Agent6Thumbnail] Sangtao.ai job failed: ' . mb_substr($poll->body(), 0, 250));

                    return false;
                }
            }
        } catch (Throwable $e) {
            Log::warning('[Agent6Thumbnail] Sangtao.ai error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Sinh ảnh qua xAI Grok (`https://api.x.ai/v1/images/generations`, `grok-imagine-image-2.0`).
     */
    private function tryGrokPhotographic(string $prompt, string $destPath): bool
    {
        $apiKey = AppSetting::getValue('grok_api_key', '');
        if ($apiKey === '') {
            return false;
        }

        $model = AppSetting::getValue('grok_image_model', 'grok-imagine-image-2.0');

        try {
            $response = Http::timeout(60)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->acceptJson()
                ->post('https://api.x.ai/v1/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                    'aspect_ratio' => '9:16',
                ]);

            if (! $response->successful()) {
                Log::warning('[Agent6Thumbnail] Grok xAI HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 250));

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
            Log::warning('[Agent6Thumbnail] Grok xAI error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Sinh ảnh qua DeepSeek / Custom OpenAI-compatible Image Endpoint.
     */
    private function tryDeepSeekPhotographic(string $prompt, string $destPath): bool
    {
        $apiKey = AppSetting::getValue('deepseek_image_api_key', '');
        $endpoint = AppSetting::getValue('deepseek_image_endpoint', '');
        if ($apiKey === '' || $endpoint === '') {
            return false;
        }

        $model = AppSetting::getValue('deepseek_image_model', 'janus-pro-7b');

        try {
            $response = Http::timeout(60)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->withHeaders(['Authorization' => 'Bearer ' . $apiKey])
                ->acceptJson()
                ->post($endpoint, [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => '1024x1792',
                ]);

            if (! $response->successful()) {
                Log::warning('[Agent6Thumbnail] DeepSeek/Custom Image HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 250));

                return false;
            }

            $json = $response->json();
            $b64 = (string) (data_get($json, 'data.0.b64_json') ?: data_get($json, 'image_base64') ?: '');
            if ($b64 !== '') {
                file_put_contents($destPath, base64_decode($b64));

                return is_file($destPath) && filesize($destPath) > 2048;
            }

            $url = (string) (data_get($json, 'data.0.url') ?: data_get($json, 'url') ?: '');
            if ($url !== '') {
                $dl = Http::timeout(60)->sink($destPath)->get($url);

                return $dl->successful() && is_file($destPath) && filesize($destPath) > 2048;
            }
        } catch (Throwable $e) {
            Log::warning('[Agent6Thumbnail] DeepSeek/Custom Image error: ' . $e->getMessage());
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
        string $badgeRed,
        array $circleImages = []
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
            'circleImages' => $circleImages,
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
