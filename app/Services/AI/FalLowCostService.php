<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Dịch vụ quản lý kết nối hợp nhất tới Fal.ai sử dụng duy nhất 1 khóa `FAL_KEY`.
 *
 * Tích hợp 3 endpoint siêu tiết kiệm chi phí:
 *  1. `fal-ai/trellis` ($0.020/model): Chuyển ảnh sản phẩm đã tách nền thành mô hình 3D `.glb`.
 *  2. `fal-ai/flux/schnell` ($0.003/ảnh): Tạo ảnh keyframe chất lượng cao tỷ lệ 9:16 (720x1280) cho Eco Mode.
 *  3. `fal-ai/ltx-video/image-to-video` ($0.020/clip 5s): Tạo video chuyển động từ ảnh keyframe.
 *
 * Quy tắc nghiêm ngặt:
 *  - Header xác thực bắt buộc: `Authorization: Key {$this->falKey}`.
 *  - Tải ảnh/video thật từ URL của Fal.ai (`https://v3.fal.media/files/...`) bằng `Http::timeout(60)->sink($localPath)->get($remoteUrl)`
 *    lưu vào `storage/app/public/renders/`.
 *  - Tuyệt đối không vẽ hình tròn/gradient giả lập bằng PHP GD/ImageMagick. Nếu Fal.ai trả lỗi (401, 403, 422, timeout...),
 *    ghi log chi tiết vào `storage/logs/fal_api.log` và ném `RuntimeException` để người dùng kiểm tra `FAL_KEY`.
 */
class FalLowCostService
{
    public const COST_FLUX_SCHNELL_USD = 0.0030;

    public const COST_LTX_VIDEO_USD = 0.0200;

    public const COST_TRELLIS_3D_USD = 0.0200;

    private readonly string $falKey;

    private readonly string $baseUrl;

    private readonly string $modelFluxSchnell;

    private readonly string $modelLtxVideo;

    private readonly string $modelTrellis3d;

    public function __construct()
    {
        $this->falKey = trim((string) config('services.fal_ai.api_key', ''));
        $this->baseUrl = rtrim((string) config('services.fal_ai.base_url', 'https://fal.run'), '/');
        $this->modelFluxSchnell = (string) config('services.fal_ai.models.flux_schnell', 'fal-ai/flux/schnell');
        $this->modelLtxVideo = (string) config('services.fal_ai.models.ltx_video', 'fal-ai/ltx-video/image-to-video');
        $this->modelTrellis3d = (string) config('services.fal_ai.models.trellis_3d', 'fal-ai/trellis');
    }

    /**
     * Tạo mô hình 3D `.glb` từ ảnh sản phẩm đã tách nền thông qua endpoint `fal-ai/trellis` ($0.02).
     *
     * @return array{
     *     glb_relative_path: string,
     *     glb_full_path: string,
     *     remote_glb_url: string,
     *     cost_usd: float
     * }
     */
    public function generate3dModelWithTrellis(
        string $imageUrlOrDataUri,
        ?string $outputFileName = null,
        ?int $videoProjectId = null
    ): array {
        $safeName = $outputFileName !== null && $outputFileName !== ''
            ? Str::slug(pathinfo($outputFileName, PATHINFO_FILENAME), '_')
            : 'trellis_' . Str::random(12);

        $relativePath = "models_3d/raw/{$safeName}.glb";

        $payload = [
            'image_url' => $this->normalizeImageInput($imageUrlOrDataUri),
            'ss_guidance_strength' => 7.5,
            'ss_sampling_steps' => 12,
            'slat_guidance_strength' => 3.0,
            'slat_sampling_steps' => 12,
            'mesh_simplify' => 0.95,
            'texture_size' => 1024,
        ];

        $response = $this->invokeFalEndpoint(
            modelEndpoint: $this->modelTrellis3d,
            payload: $payload,
            actionType: 'generate_3d',
            costUsd: self::COST_TRELLIS_3D_USD,
            videoProjectId: $videoProjectId,
            storyboardSceneId: null
        );

        $remoteGlbUrl = (string) (
            data_get($response, 'model_mesh.url')
            ?? data_get($response, 'glb.url')
            ?? data_get($response, 'mesh.url')
            ?? ''
        );

        if ($remoteGlbUrl === '') {
            $errMsg = 'Phản hồi từ fal-ai/trellis không chứa URL file .glb hợp lệ.';
            $this->writeFalApiLog('generate_3d', $this->modelTrellis3d, 200, $errMsg, $response);
            throw new RuntimeException($errMsg);
        }

        $fullPath = $this->downloadRemoteAsset($remoteGlbUrl, $relativePath);

        return [
            'glb_relative_path' => $relativePath,
            'glb_full_path' => $fullPath,
            'remote_glb_url' => $remoteGlbUrl,
            'cost_usd' => self::COST_TRELLIS_3D_USD,
        ];
    }

    /**
     * Tạo ảnh Keyframe chất lượng cao bằng `fal-ai/flux/schnell` ($0.003/ảnh)
     * với kích thước dọc 9:16 (`width: 720, height: 1280`) hoặc ngang 16:9 (`width: 1280, height: 720`),
     * tải ảnh thật từ URL của Fal.ai về `storage/app/public/renders/`.
     *
     * @return array{
     *     image_relative_path: string,
     *     image_full_path: string,
     *     remote_image_url: string,
     *     cost_usd: float
     * }
     */
    public function generateKeyframeWithFluxSchnell(
        string $visualPrompt,
        string $aspectRatio = '9:16',
        ?string $outputFileName = null,
        ?int $videoProjectId = null,
        ?int $storyboardSceneId = null
    ): array {
        $safeName = $outputFileName !== null && $outputFileName !== ''
            ? Str::slug(pathinfo($outputFileName, PATHINFO_FILENAME), '_')
            : 'scene_img_' . Str::random(12);

        $relativePath = "renders/{$safeName}.jpg";

        $imageSize = $aspectRatio === '16:9'
            ? ['width' => 1280, 'height' => 720]
            : ['width' => 720, 'height' => 1280];

        $payload = [
            'prompt' => trim($visualPrompt),
            'image_size' => $imageSize,
            'num_inference_steps' => 4,
            'num_images' => 1,
            'enable_safety_checker' => true,
        ];

        $response = $this->invokeFalEndpoint(
            modelEndpoint: $this->modelFluxSchnell,
            payload: $payload,
            actionType: 'generate_image',
            costUsd: self::COST_FLUX_SCHNELL_USD,
            videoProjectId: $videoProjectId,
            storyboardSceneId: $storyboardSceneId
        );

        $remoteImageUrl = (string) data_get($response, 'images.0.url', '');
        if ($remoteImageUrl === '') {
            $errMsg = 'Phản hồi từ fal-ai/flux/schnell không chứa URL ảnh hợp lệ.';
            $this->writeFalApiLog('generate_image', $this->modelFluxSchnell, 200, $errMsg, $response);
            throw new RuntimeException($errMsg);
        }

        $fullPath = $this->downloadRemoteAsset($remoteImageUrl, $relativePath);

        return [
            'image_relative_path' => $relativePath,
            'image_full_path' => $fullPath,
            'remote_image_url' => $remoteImageUrl,
            'cost_usd' => self::COST_FLUX_SCHNELL_USD,
        ];
    }

    /**
     * Tạo video chuyển động 5 giây từ ảnh keyframe bằng `fal-ai/ltx-video/image-to-video` ($0.02/clip)
     * và lưu vào `storage/app/public/renders/`.
     *
     * @return array{
     *     video_relative_path: string,
     *     video_full_path: string,
     *     remote_video_url: string,
     *     cost_usd: float
     * }
     */
    public function generateVideoWithLtx(
        string $imageUrlOrPath,
        string $motionPrompt,
        ?string $outputFileName = null,
        ?int $videoProjectId = null,
        ?int $storyboardSceneId = null
    ): array {
        $safeName = $outputFileName !== null && $outputFileName !== ''
            ? Str::slug(pathinfo($outputFileName, PATHINFO_FILENAME), '_')
            : 'scene_vid_' . Str::random(12);

        $relativePath = "renders/{$safeName}.mp4";

        $payload = [
            'image_url' => $this->normalizeImageInput($imageUrlOrPath),
            'prompt' => trim($motionPrompt),
            'negative_prompt' => 'low quality, worst quality, deformed, distorted, watermark, blurry, jittery',
            'num_frames' => 121,
            'frame_rate' => 24,
            'num_inference_steps' => 30,
            'guidance_scale' => 3.0,
        ];

        $response = $this->invokeFalEndpoint(
            modelEndpoint: $this->modelLtxVideo,
            payload: $payload,
            actionType: 'generate_video',
            costUsd: self::COST_LTX_VIDEO_USD,
            videoProjectId: $videoProjectId,
            storyboardSceneId: $storyboardSceneId
        );

        $remoteVideoUrl = (string) data_get($response, 'video.url', '');
        if ($remoteVideoUrl === '') {
            $errMsg = 'Phản hồi từ fal-ai/ltx-video/image-to-video không chứa URL video hợp lệ.';
            $this->writeFalApiLog('generate_video', $this->modelLtxVideo, 200, $errMsg, $response);
            throw new RuntimeException($errMsg);
        }

        $fullPath = $this->downloadRemoteAsset($remoteVideoUrl, $relativePath);

        return [
            'video_relative_path' => $relativePath,
            'video_full_path' => $fullPath,
            'remote_video_url' => $remoteVideoUrl,
            'cost_usd' => self::COST_LTX_VIDEO_USD,
        ];
    }

    /**
     * Hàm lõi thực thi HTTP POST tới Fal.ai với header chuẩn `Authorization: Key {$this->falKey}`.
     * Nếu thất bại (401, 403, 422, timeout...), ghi log chi tiết vào `storage/logs/fal_api.log` và ném Exception.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function invokeFalEndpoint(
        string $modelEndpoint,
        array $payload,
        string $actionType,
        float $costUsd,
        ?int $videoProjectId,
        ?int $storyboardSceneId
    ): array {
        if ($this->falKey === '') {
            $msg = 'Thiếu cấu hình FAL_KEY trong file .env.';
            $this->writeFalApiLog($actionType, $modelEndpoint, 0, $msg, []);
            throw new RuntimeException($msg);
        }

        $url = sprintf('%s/%s', $this->baseUrl, ltrim($modelEndpoint, '/'));
        $startTime = microtime(true);

        $sanitizedPayload = $payload;
        if (isset($sanitizedPayload['image_url']) && is_string($sanitizedPayload['image_url']) && str_starts_with($sanitizedPayload['image_url'], 'data:')) {
            $sanitizedPayload['image_url'] = '[BASE64_DATA_URI_REDACTED]';
        }

        try {
            $response = Http::timeout(60)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ])
                ->withHeaders([
                    'Authorization' => "Key {$this->falKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);
        } catch (Throwable $httpException) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $errorMsg = sprintf('Lỗi kết nối/timeout khi gọi Fal.ai [%s]: %s', $modelEndpoint, $httpException->getMessage());

            $this->writeFalApiLog($actionType, $modelEndpoint, 0, $errorMsg, $sanitizedPayload);
            $this->recordGenerationLogAndCost(
                videoProjectId: $videoProjectId,
                storyboardSceneId: $storyboardSceneId,
                modelName: $modelEndpoint,
                actionType: $actionType,
                status: 'failed',
                costUsd: 0.0000,
                latencyMs: $latencyMs,
                requestPayload: $sanitizedPayload,
                responsePayload: null,
                errorMessage: $errorMsg
            );

            throw new RuntimeException($errorMsg, 0, $httpException);
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        if (! $response->successful()) {
            $statusCode = $response->status();
            $rawBody = $response->body();
            $errorMsg = sprintf(
                'Lỗi Fal.ai [%s] (HTTP %d): %s — Vui lòng kiểm tra lại FAL_KEY hoặc số dư tài khoản tại fal.ai.',
                $modelEndpoint,
                $statusCode,
                $rawBody
            );

            $this->writeFalApiLog($actionType, $modelEndpoint, $statusCode, $errorMsg, [
                'request' => $sanitizedPayload,
                'response_body' => $rawBody,
            ]);

            $this->recordGenerationLogAndCost(
                videoProjectId: $videoProjectId,
                storyboardSceneId: $storyboardSceneId,
                modelName: $modelEndpoint,
                actionType: $actionType,
                status: 'failed',
                costUsd: 0.0000,
                latencyMs: $latencyMs,
                requestPayload: $sanitizedPayload,
                responsePayload: null,
                errorMessage: $errorMsg
            );

            throw new RuntimeException($errorMsg);
        }

        $responseData = (array) $response->json();

        $this->recordGenerationLogAndCost(
            videoProjectId: $videoProjectId,
            storyboardSceneId: $storyboardSceneId,
            modelName: $modelEndpoint,
            actionType: $actionType,
            status: 'success',
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            requestPayload: $sanitizedPayload,
            responsePayload: $responseData,
            errorMessage: null
        );

        return $responseData;
    }

    /**
     * Tải tài nguyên thật từ URL của Fal.ai (`https://v3.fal.media/files/...`)
     * về thư mục `storage/app/public/renders/` bằng `Http::timeout(60)->sink($localPath)->get($remoteUrl)`.
     */
    private function downloadRemoteAsset(string $remoteUrl, string $relativeStoragePath): string
    {
        $disk = Storage::disk('public');
        $localPath = $disk->path($relativeStoragePath);
        $dir = dirname($localPath);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không thể tạo thư mục lưu trữ: {$dir}");
        }

        $response = Http::timeout(60)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
            ->sink($localPath)
            ->get($remoteUrl);

        if (! $response->successful() || ! is_file($localPath) || filesize($localPath) === 0) {
            $errMsg = sprintf('Không thể tải file từ Fal.ai URL [%s] (HTTP %d).', $remoteUrl, $response->status());
            $this->writeFalApiLog('download_asset', $remoteUrl, $response->status(), $errMsg, []);
            throw new RuntimeException($errMsg);
        }

        return $localPath;
    }

    /**
     * Chuẩn hóa đầu vào ảnh sang Data URI base64 nếu là file cục bộ.
     */
    private function normalizeImageInput(string $imageUrlOrPath): string
    {
        if (str_starts_with($imageUrlOrPath, 'http://') || str_starts_with($imageUrlOrPath, 'https://') || str_starts_with($imageUrlOrPath, 'data:')) {
            return $imageUrlOrPath;
        }

        if (! is_file($imageUrlOrPath) || ! is_readable($imageUrlOrPath)) {
            throw new RuntimeException("Không tìm thấy file ảnh đầu vào cho Fal.ai tại: {$imageUrlOrPath}");
        }

        $mimeType = mime_content_type($imageUrlOrPath) ?: 'image/png';
        $bytes = file_get_contents($imageUrlOrPath);
        if ($bytes === false) {
            throw new RuntimeException("Không thể đọc nội dung file ảnh: {$imageUrlOrPath}");
        }

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($bytes));
    }

    /**
     * Ghi log chi tiết lỗi gọi API Fal.ai vào file riêng `storage/logs/fal_api.log`.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeFalApiLog(
        string $actionType,
        string $endpoint,
        int $statusCode,
        string $message,
        array $context = []
    ): void {
        try {
            $logFile = storage_path('logs/fal_api.log');
            $logDir = dirname($logFile);
            if (! is_dir($logDir)) {
                mkdir($logDir, 0775, true);
            }

            $entry = sprintf(
                "[%s] action=%s | endpoint=%s | status=%d | message=%s | context=%s\n",
                date('Y-m-d H:i:s'),
                $actionType,
                $endpoint,
                $statusCode,
                $message,
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            file_put_contents($logFile, $entry, FILE_APPEND);
        } catch (Throwable) {
            // Không làm gián đoạn luồng chính
        }
    }

    /**
     * Ghi nhận lịch sử gọi API vào bảng `generation_logs`.
     *
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function recordGenerationLogAndCost(
        ?int $videoProjectId,
        ?int $storyboardSceneId,
        string $modelName,
        string $actionType,
        string $status,
        float $costUsd,
        int $latencyMs,
        array $requestPayload,
        ?array $responsePayload,
        ?string $errorMessage
    ): void {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $videoProjectId,
                'storyboard_scene_id' => $storyboardSceneId,
                'provider' => 'fal_ai',
                'model_name' => $modelName,
                'action_type' => $actionType,
                'status' => $status,
                'cost_usd' => $costUsd,
                'latency_ms' => $latencyMs,
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload,
                'error_message' => $errorMessage,
            ]);

            if ($videoProjectId !== null && $costUsd > 0.0) {
                VideoProject::query()->whereKey($videoProjectId)->increment('total_cost_usd', $costUsd);
            }
        } catch (Throwable) {
            // Không làm gián đoạn luồng chính
        }
    }
}
