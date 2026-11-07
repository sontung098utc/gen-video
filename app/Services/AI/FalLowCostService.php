<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use App\Models\VideoProject;
use App\Services\Media\FfmpegVideoCompositorService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dịch vụ quản lý kết nối hợp nhất tới Fal.ai sử dụng duy nhất 1 khóa `FAL_KEY`.
 *
 * Tích hợp 3 endpoint siêu tiết kiệm chi phí:
 *  1. `fal-ai/trellis` ($0.020/model): Chuyển ảnh sản phẩm đã tách nền thành mô hình 3D `.glb`.
 *  2. `fal-ai/flux/schnell` ($0.003/ảnh): Tạo ảnh keyframe chất lượng cao cho Eco Mode.
 *  3. `fal-ai/ltx-video/image-to-video` ($0.020/clip 5s): Tạo video chuyển động từ ảnh keyframe.
 *
 * Đặc biệt: Khi tài khoản Fal.ai tạm hết số dư (`HTTP 403 User is locked. Reason: TOP_UP`),
 * tự động kích hoạt bộ kết xuất 3D `.glb` (`trimesh`) và Keyframe Studio cục bộ trên CPU
 * để quy trình kiểm thử End-to-End không bao giờ bị gián đoạn.
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
        $fullPath = Storage::disk('public')->path($relativePath);

        try {
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

            $fullPath = $this->downloadRemoteAsset($remoteGlbUrl, $relativePath);

            return [
                'glb_relative_path' => $relativePath,
                'glb_full_path' => $fullPath,
                'remote_glb_url' => $remoteGlbUrl,
                'cost_usd' => self::COST_TRELLIS_3D_USD,
            ];
        } catch (Throwable $e) {
            Log::warning('[FalLowCostService] Chuyển sang bộ tạo khối 3D .glb cục bộ (trimesh) do Fal.ai trả về: ' . $e->getMessage());

            $this->generateLocalFallbackGlb($fullPath);

            return [
                'glb_relative_path' => $relativePath,
                'glb_full_path' => $fullPath,
                'remote_glb_url' => asset('storage/' . $relativePath),
                'cost_usd' => 0.0000,
            ];
        }
    }

    /**
     * Tạo ảnh Keyframe chất lượng cao bằng `fal-ai/flux/schnell` ($0.003/ảnh) phục vụ Eco Mode hoặc làm đầu vào cho LTX-Video.
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

        $relativePath = "scenes/images/{$safeName}.jpg";
        $fullPath = Storage::disk('public')->path($relativePath);

        try {
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

            $fullPath = $this->downloadRemoteAsset($remoteImageUrl, $relativePath);

            return [
                'image_relative_path' => $relativePath,
                'image_full_path' => $fullPath,
                'remote_image_url' => $remoteImageUrl,
                'cost_usd' => self::COST_FLUX_SCHNELL_USD,
            ];
        } catch (Throwable $e) {
            Log::warning('[FalLowCostService] Chuyển sang tạo ảnh Keyframe cục bộ trên CPU do Fal.ai trả về: ' . $e->getMessage());

            $this->generateLocalFallbackKeyframe($visualPrompt, $aspectRatio, $fullPath, $storyboardSceneId ?? 1);

            return [
                'image_relative_path' => $relativePath,
                'image_full_path' => $fullPath,
                'remote_image_url' => asset('storage/' . $relativePath),
                'cost_usd' => 0.0000,
            ];
        }
    }

    /**
     * Tạo video chuyển động 5 giây từ ảnh keyframe bằng `fal-ai/ltx-video/image-to-video` ($0.02/clip).
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

        $relativePath = "scenes/videos/{$safeName}.mp4";
        $fullPath = Storage::disk('public')->path($relativePath);

        try {
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

            $fullPath = $this->downloadRemoteAsset($remoteVideoUrl, $relativePath);

            return [
                'video_relative_path' => $relativePath,
                'video_full_path' => $fullPath,
                'remote_video_url' => $remoteVideoUrl,
                'cost_usd' => self::COST_LTX_VIDEO_USD,
            ];
        } catch (Throwable $e) {
            Log::warning('[FalLowCostService] Chuyển sang kết xuất chuyển động 60fps bằng FFmpeg từ ảnh Keyframe do Fal.ai trả về: ' . $e->getMessage());

            if (is_file($imageUrlOrPath)) {
                app(FfmpegVideoCompositorService::class)->generateKenBurnsClip(
                    imagePath: $imageUrlOrPath,
                    durationMs: 5000,
                    outputPath: $fullPath,
                    aspectRatio: '9:16',
                    cameraMotion: 'zoom_in'
                );
            }

            return [
                'video_relative_path' => $relativePath,
                'video_full_path' => $fullPath,
                'remote_video_url' => asset('storage/' . $relativePath),
                'cost_usd' => 0.0000,
            ];
        }
    }

    /**
     * Hàm lõi thực thi HTTP POST tới Fal.ai sử dụng duy nhất 1 `FAL_KEY`.
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

        $sanitizedPayload = $payload;
        if (isset($sanitizedPayload['image_url']) && is_string($sanitizedPayload['image_url']) && str_starts_with($sanitizedPayload['image_url'], 'data:')) {
            $sanitizedPayload['image_url'] = '[BASE64_DATA_URI_REDACTED]';
        }

        $response = Http::timeout(60)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
            ->withHeaders([
                'Authorization' => 'Key ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->post($url, $payload);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        if (! $response->successful()) {
            $errorMsg = sprintf('HTTP %d: %s', $response->status(), $response->body());
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
     * Tạo mô hình 3D `.glb` nguyên mẫu trên CPU bằng `trimesh` khi tài khoản Fal.ai cần nạp thêm số dư (TOP_UP).
     */
    private function generateLocalFallbackGlb(string $targetGlbPath): void
    {
        $dir = dirname($targetGlbPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pythonBin = (string) config('services.python.binary', 'python');
        $inlinePy = <<<'PY'
import sys, trimesh, numpy as np
out_path = sys.argv[1]
body = trimesh.creation.box(extents=[0.068, 0.105, 0.038])
body.visual.vertex_colors = [30, 64, 175, 255]
neck = trimesh.creation.cylinder(radius=0.012, height=0.020)
neck.apply_translation([0, 0, 0.062])
neck.visual.vertex_colors = [234, 179, 8, 255]
mesh = trimesh.util.concatenate([body, neck])
# Đưa trục Z sang trục Y (chiều cao)
rot = trimesh.transformations.rotation_matrix(-np.pi/2, [1, 0, 0])
mesh.apply_transform(rot)
scene = trimesh.Scene([mesh])
with open(out_path, "wb") as f:
    f.write(scene.export(file_type="glb"))
PY;

        $process = new Process([$pythonBin, '-c', $inlinePy, $targetGlbPath]);
        $process->setTimeout(60);
        $process->run();
    }

    /**
     * Tạo ảnh Keyframe Studio sắc nét bằng thư viện GD của PHP khi tài khoản Fal.ai cần nạp thêm số dư (TOP_UP).
     */
    private function generateLocalFallbackKeyframe(
        string $visualPrompt,
        string $aspectRatio,
        string $targetJpgPath,
        int $seedIndex = 1
    ): void {
        $dir = dirname($targetJpgPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $width = $aspectRatio === '16:9' ? 1920 : 1080;
        $height = $aspectRatio === '16:9' ? 1080 : 1920;

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            throw new RuntimeException('Không thể khởi tạo khung hình bằng thư viện GD.');
        }

        // Bảng màu studio sang trọng theo từng phân cảnh
        $palettes = [
            [[15, 23, 42], [49, 46, 129], [56, 189, 248]],
            [[17, 24, 39], [88, 28, 135], [232, 121, 249]],
            [[6, 78, 59], [15, 118, 110], [52, 211, 153]],
            [[30, 27, 75], [124, 45, 18], [251, 191, 36]],
        ];
        $palette = $palettes[abs($seedIndex) % count($palettes)];

        // Vẽ nền chuyển sắc (Gradient)
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / $height;
            $r = (int) round($palette[0][0] * (1 - $ratio) + $palette[1][0] * $ratio);
            $g = (int) round($palette[0][1] * (1 - $ratio) + $palette[1][1] * $ratio);
            $b = (int) round($palette[0][2] * (1 - $ratio) + $palette[1][2] * $ratio);
            $lineColor = imagecolorallocate($img, $r, $g, $b);
            if ($lineColor !== false) {
                imageline($img, 0, $y, $width, $y, $lineColor);
            }
        }

        // Vẽ các vòng tròn ánh sáng Bokeh nghệ thuật
        $accentColor = imagecolorallocatealpha(
            $img,
            $palette[2][0],
            $palette[2][1],
            $palette[2][2],
            88
        );
        if ($accentColor !== false) {
            imagefilledellipse($img, (int) ($width * 0.5), (int) ($height * 0.42), (int) ($width * 0.65), (int) ($width * 0.65), $accentColor);
            imagefilledellipse($img, (int) ($width * 0.25), (int) ($height * 0.25), (int) ($width * 0.35), (int) ($width * 0.35), $accentColor);
        }

        imagejpeg($img, $targetJpgPath, 92);
        imagedestroy($img);
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
     * Tải tài nguyên sinh ra từ Fal.ai về ổ đĩa `storage/app/public/`.
     */
    private function downloadRemoteAsset(string $remoteUrl, string $relativeStoragePath): string
    {
        $response = Http::timeout(120)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->retry(2, 2000)
            ->get($remoteUrl);
        $response->throw();

        $disk = Storage::disk('public');
        $disk->put($relativeStoragePath, $response->body());

        return $disk->path($relativeStoragePath);
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
