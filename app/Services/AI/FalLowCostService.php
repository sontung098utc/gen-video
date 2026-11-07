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
 *  2. `fal-ai/flux/schnell` ($0.003/ảnh): Tạo ảnh keyframe chất lượng cao cho Eco Mode.
 *  3. `fal-ai/ltx-video/image-to-video` ($0.020/clip 5s): Tạo video chuyển động từ ảnh keyframe.
 */
class FalLowCostService
{
    public const COST_FLUX_SCHNELL_USD = 0.0030;

    public const COST_LTX_VIDEO_USD = 0.0200;

    public const COST_TRELLIS_3D_USD = 0.0200;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly string $modelFluxSchnell;

    private readonly string $modelLtxVideo;

    private readonly string $modelTrellis3d;

    public function __construct()
    {
        $this->apiKey = (string) config('services.fal_ai.api_key', '');
        $this->baseUrl = rtrim((string) config('services.fal_ai.base_url', 'https://fal.run'), '/');
        $this->modelFluxSchnell = (string) config('services.fal_ai.models.flux_schnell', 'fal-ai/flux/schnell');
        $this->modelLtxVideo = (string) config('services.fal_ai.models.ltx_video', 'fal-ai/ltx-video/image-to-video');
        $this->modelTrellis3d = (string) config('services.fal_ai.models.trellis_3d', 'fal-ai/trellis');
    }

    /**
     * Tạo mô hình 3D `.glb` từ ảnh sản phẩm đã tách nền thông qua endpoint `fal-ai/trellis` ($0.02).
     *
     * @param  string  $imageUrlOrDataUri  URL công khai hoặc Data URI (`data:image/png;base64,...`) của ảnh đã tách nền
     * @param  string|null  $outputFileName  Tên file `.glb` mong muốn
     * @param  int|null  $videoProjectId  ID dự án để ghi log và cộng dồn chi phí
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
            throw new RuntimeException('Phản hồi từ fal-ai/trellis không chứa URL file .glb hợp lệ.');
        }

        $safeName = $outputFileName !== null && $outputFileName !== ''
            ? Str::slug(pathinfo($outputFileName, PATHINFO_FILENAME), '_')
            : 'trellis_' . Str::random(12);

        $relativePath = "models_3d/raw/{$safeName}.glb";
        $fullPath = $this->downloadRemoteAsset($remoteGlbUrl, $relativePath);

        return [
            'glb_relative_path' => $relativePath,
            'glb_full_path' => $fullPath,
            'remote_glb_url' => $remoteGlbUrl,
            'cost_usd' => self::COST_TRELLIS_3D_USD,
        ];
    }

    /**
     * Tạo ảnh Keyframe chất lượng cao bằng `fal-ai/flux/schnell` ($0.003/ảnh) phục vụ Eco Mode hoặc làm đầu vào cho LTX-Video.
     *
     * @param  string  $visualPrompt  Prompt tiếng Anh mô tả bối cảnh
     * @param  string  $aspectRatio  Tỷ lệ khung hình (`9:16` hoặc `16:9`)
     * @param  string|null  $outputFileName  Tên file ảnh đầu ra
     * @param  int|null  $videoProjectId  ID dự án
     * @param  int|null  $storyboardSceneId  ID phân cảnh
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
        $imageSize = $aspectRatio === '16:9' ? 'landscape_16_9' : 'portrait_16_9';

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
            throw new RuntimeException('Phản hồi từ fal-ai/flux/schnell không chứa URL ảnh hợp lệ.');
        }

        $safeName = $outputFileName !== null && $outputFileName !== ''
            ? Str::slug(pathinfo($outputFileName, PATHINFO_FILENAME), '_')
            : 'scene_img_' . Str::random(12);

        $relativePath = "scenes/images/{$safeName}.jpg";
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
     * dành cho chế độ Full AI Video Mode.
     *
     * @param  string  $imageUrlOrPath  URL ảnh hoặc đường dẫn file ảnh cục bộ
     * @param  string  $motionPrompt  Prompt tiếng Anh mô tả chuyển động vật lý và camera
     * @param  string|null  $outputFileName  Tên file video đầu ra
     * @param  int|null  $videoProjectId  ID dự án
     * @param  int|null  $storyboardSceneId  ID phân cảnh
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
            throw new RuntimeException('Phản hồi từ fal-ai/ltx-video/image-to-video không chứa URL video hợp lệ.');
        }

        $safeName = $outputFileName !== null && $outputFileName !== ''
            ? Str::slug(pathinfo($outputFileName, PATHINFO_FILENAME), '_')
            : 'scene_vid_' . Str::random(12);

        $relativePath = "scenes/videos/{$safeName}.mp4";
        $fullPath = $this->downloadRemoteAsset($remoteVideoUrl, $relativePath);

        return [
            'video_relative_path' => $relativePath,
            'video_full_path' => $fullPath,
            'remote_video_url' => $remoteVideoUrl,
            'cost_usd' => self::COST_LTX_VIDEO_USD,
        ];
    }

    /**
     * Hàm lõi thực thi HTTP POST tới Fal.ai sử dụng duy nhất 1 `FAL_KEY`,
     * tự động ghi nhận log vào `generation_logs` và cộng dồn chi phí vào `video_projects`.
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
        if ($this->apiKey === '') {
            throw new RuntimeException('Thiếu cấu hình FAL_KEY trong file .env.');
        }

        $url = sprintf('%s/%s', $this->baseUrl, ltrim($modelEndpoint, '/'));
        $startTime = microtime(true);

        // Ẩn chuỗi Data URI base64 trong request_payload khi lưu vào DB để tiết kiệm dung lượng MySQL
        $sanitizedPayload = $payload;
        if (isset($sanitizedPayload['image_url']) && is_string($sanitizedPayload['image_url']) && str_starts_with($sanitizedPayload['image_url'], 'data:')) {
            $sanitizedPayload['image_url'] = '[BASE64_DATA_URI_REDACTED]';
        }

        try {
            $response = Http::timeout(240)
                ->retry(2, 4000)
                ->withHeaders([
                    'Authorization' => 'Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($url, $payload);

            $response->throw();

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
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
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

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
                errorMessage: $e->getMessage()
            );

            throw new RuntimeException(
                sprintf('Lỗi khi gọi Fal.ai endpoint [%s]: %s', $modelEndpoint, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Chuẩn hóa đầu vào ảnh: Nếu là đường dẫn file cục bộ trên CentOS 8 thì tự động chuyển sang Data URI base64.
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
     * Tải tài nguyên sinh ra từ Fal.ai (ảnh `.jpg`, video `.mp4`, hoặc mô hình `.glb`) về ổ đĩa `storage/app/public/`.
     */
    private function downloadRemoteAsset(string $remoteUrl, string $relativeStoragePath): string
    {
        $response = Http::timeout(180)->retry(2, 2000)->get($remoteUrl);
        $response->throw();

        $disk = Storage::disk('public');
        $disk->put($relativeStoragePath, $response->body());

        return $disk->path($relativeStoragePath);
    }

    /**
     * Ghi nhận lịch sử gọi API vào bảng `generation_logs` và cập nhật `total_cost_usd` của dự án.
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
            // Không làm gián đoạn luồng chính nếu chạy độc lập ngoài DB
        }
    }
}
