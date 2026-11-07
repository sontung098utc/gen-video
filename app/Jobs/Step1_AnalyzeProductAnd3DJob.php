<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectType;
use App\Models\GenerationLog;
use App\Models\ProductProfile;
use App\Models\VideoProject;
use App\Services\AI\FalLowCostService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * BƯỚC 1 (Queue: `3d-processing`): Phân tích ảnh sản phẩm, tách nền bằng `rembg` (CPU CentOS 8),
 * tạo mô hình 3D `.glb` qua `fal-ai/trellis` ($0.02) và chuẩn hóa kích thước thực tế (`mm` -> `1m`)
 * kèm chụp 4 ảnh góc xoay Turntable bằng `trimesh`.
 *
 * Đặc biệt: Hỗ trợ tái sử dụng Cache vĩnh viễn theo `cache_key` ($0.00 nếu sản phẩm đã từng tạo 3D).
 */
class Step1_AnalyzeProductAnd3DJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $videoProjectId
    ) {
        $this->onQueue('3d-processing');
    }

    public function handle(
        FalLowCostService $falService,
        WorkflowOrchestrator $orchestrator
    ): void {
        $project = VideoProject::query()->with('productProfile')->findOrFail($this->videoProjectId);

        // Nếu dự án là KNOWLEDGE (không phải PRODUCT), chuyển tiếp ngay sang bước tạo kịch bản
        if ($project->project_type !== ProjectType::PRODUCT) {
            $orchestrator->dispatchNextStep($project);

            return;
        }

        $profile = $project->productProfile;
        if (! $profile instanceof ProductProfile) {
            $orchestrator->markAsFailed($project, 'Dự án loại PRODUCT nhưng chưa có bản ghi ProductProfile.');

            return;
        }

        try {
            $disk = Storage::disk('public');
            $pythonBinary = (string) config('services.python.binary', '/usr/bin/python3.11');
            $cpuThreads = (string) config('services.python.omp_threads', 6);

            // 1. Lấy ảnh gốc đầu tiên của sản phẩm
            $originalImages = $profile->original_image_paths;
            if (empty($originalImages) || ! isset($originalImages[0])) {
                throw new RuntimeException('Hồ sơ sản phẩm không có ảnh gốc để tách nền và dựng 3D.');
            }

            $primaryImagePath = $this->resolveAbsolutePath((string) $originalImages[0]);

            // 2. Kiểm tra Cache vĩnh viễn theo `cache_key`
            $cachedProfile = ProductProfile::query()
                ->where('cache_key', $profile->cache_key)
                ->where('id', '!=', $profile->id)
                ->whereNotNull('normalized_glb_path')
                ->first();

            if ($cachedProfile !== null && $disk->exists((string) $cachedProfile->normalized_glb_path)) {
                $profile->update([
                    'nobg_image_path' => $cachedProfile->nobg_image_path,
                    'raw_glb_path' => $cachedProfile->raw_glb_path,
                    'normalized_glb_path' => $cachedProfile->normalized_glb_path,
                ]);

                GenerationLog::query()->create([
                    'video_project_id' => $project->id,
                    'provider' => 'local_cpu',
                    'model_name' => '3d_cache_hit',
                    'action_type' => 'generate_3d',
                    'status' => 'cached',
                    'cost_usd' => 0.0000,
                    'latency_ms' => 5,
                    'request_payload' => ['cache_key' => $profile->cache_key],
                    'response_payload' => ['normalized_glb_path' => $cachedProfile->normalized_glb_path],
                ]);

                $orchestrator->dispatchNextStep($project);

                return;
            }

            // 3. Chạy script `scripts/remove_background.py` tách nền PNG RGBA 32-bit trên CPU (0$)
            $nobgRelativePath = "products/nobg/project_{$project->id}_nobg.png";
            $nobgFullPath = $disk->path($nobgRelativePath);

            $removeBgScript = base_path('scripts/remove_background.py');
            $removeBgProcess = new Process(
                command: [
                    $pythonBinary,
                    $removeBgScript,
                    '--input',
                    $primaryImagePath,
                    '--output',
                    $nobgFullPath,
                ],
                env: [
                    'OMP_NUM_THREADS' => $cpuThreads,
                    'ORT_NUM_THREADS' => $cpuThreads,
                ]
            );
            $removeBgProcess->setTimeout(180);
            $removeBgProcess->run();

            if (! $removeBgProcess->isSuccessful()) {
                throw new RuntimeException(
                    'Lỗi tách nền ảnh sản phẩm (remove_background.py): ' . trim($removeBgProcess->getErrorOutput() ?: $removeBgProcess->getOutput())
                );
            }

            $profile->update(['nobg_image_path' => $nobgRelativePath]);

            // 4. Gọi `FalLowCostService::generate3dModelWithTrellis()` ($0.02) tạo mô hình .glb thô
            $trellisResult = $falService->generate3dModelWithTrellis(
                imageUrlOrDataUri: $nobgFullPath,
                outputFileName: "project_{$project->id}_raw",
                videoProjectId: $project->id
            );

            $rawGlbFullPath = $trellisResult['glb_full_path'];
            $profile->update(['raw_glb_path' => $trellisResult['glb_relative_path']]);

            // 5. Chạy script `scripts/normalize_glb_scale.py` chuẩn hóa kích thước thực tế (mm -> m)
            // và chụp tự động 4 ảnh góc xoay Turntable (0°, 90°, 180°, 270°)
            $normalizedRelativePath = "models_3d/cache/{$profile->cache_key}.glb";
            $normalizedFullPath = $disk->path($normalizedRelativePath);
            $turntableRelativeDir = "products/turntable/project_{$project->id}";
            $turntableFullDir = $disk->path($turntableRelativeDir);

            $normalizeScript = base_path('scripts/normalize_glb_scale.py');
            $normalizeProcess = new Process(
                command: [
                    $pythonBinary,
                    $normalizeScript,
                    '--input',
                    $rawGlbFullPath,
                    '--output',
                    $normalizedFullPath,
                    '--width-mm',
                    (string) $profile->width_mm,
                    '--height-mm',
                    (string) $profile->height_mm,
                    '--depth-mm',
                    (string) $profile->depth_mm,
                    '--render-turntable-dir',
                    $turntableFullDir,
                ],
                env: [
                    'OMP_NUM_THREADS' => $cpuThreads,
                    'ORT_NUM_THREADS' => $cpuThreads,
                ]
            );
            $normalizeProcess->setTimeout(240);
            $normalizeProcess->run();

            if (! $normalizeProcess->isSuccessful()) {
                throw new RuntimeException(
                    'Lỗi chuẩn hóa kích thước 3D (normalize_glb_scale.py): ' . trim($normalizeProcess->getErrorOutput() ?: $normalizeProcess->getOutput())
                );
            }

            $normalizeOutput = json_decode(trim($normalizeProcess->getOutput()), true);
            $existingAnalysis = is_array($profile->customer_analysis_json) ? $profile->customer_analysis_json : [];
            $existingAnalysis['normalization_meta'] = is_array($normalizeOutput) ? $normalizeOutput : [];

            $profile->update([
                'normalized_glb_path' => $normalizedRelativePath,
                'customer_analysis_json' => $existingAnalysis,
            ]);

            // 6. Chuyển sang Cổng kiểm duyệt số 1 (WAITING_APPROVAL_PRODUCT) hoặc đi tiếp sang SCRIPTING nếu ở chế độ AUTO
            $orchestrator->dispatchNextStep($project);
        } catch (Throwable $e) {
            $orchestrator->markAsFailed($project, 'Step 1 (Xử lý Sản phẩm & 3D) thất bại: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Phân giải đường dẫn tương đối trên Storage disk hoặc đường dẫn tuyệt đối.
     */
    private function resolveAbsolutePath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $publicDiskPath = Storage::disk('public')->path($path);
        if (is_file($publicDiskPath)) {
            return $publicDiskPath;
        }

        $localStoragePath = storage_path('app/' . ltrim($path, '/\\'));
        if (is_file($localStoragePath)) {
            return $localStoragePath;
        }

        throw new RuntimeException("Không tìm thấy file ảnh gốc tại: {$path}");
    }
}
