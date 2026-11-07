<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CostTierMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\WorkflowMode;
use App\Models\GenerationLog;
use App\Models\ProductProfile;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\Media\LocalSfxMatcherService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Controller phục vụ Giao diện Web Studio (Blade + TailwindCSS + Alpine.js)
 * và Endpoint Polling thời gian thực (`/api/projects/{id}/status`).
 */
class ProjectWebController extends Controller
{
    public function __construct(
        private readonly WorkflowOrchestrator $orchestrator,
        private readonly LocalSfxMatcherService $sfxMatcher
    ) {}

    /**
     * `GET /projects` — Danh sách toàn bộ các dự án video, trạng thái cổng duyệt và thống kê chi phí USD.
     */
    public function index(): View
    {
        $projects = VideoProject::query()
            ->withCount('scenes')
            ->with('productProfile')
            ->latest()
            ->paginate(12);

        $stats = [
            'total_projects' => VideoProject::query()->count(),
            'completed_videos' => VideoProject::query()->where('status', ProjectStatus::COMPLETED->value)->count(),
            'waiting_approval' => VideoProject::query()->whereIn('status', [
                ProjectStatus::WAITING_APPROVAL_PRODUCT->value,
                ProjectStatus::WAITING_APPROVAL_SCRIPT->value,
                ProjectStatus::WAITING_APPROVAL_AUDIO->value,
                ProjectStatus::WAITING_APPROVAL_SCENES->value,
            ])->count(),
            'total_spent_usd' => (float) VideoProject::query()->sum('total_cost_usd'),
        ];

        return view('projects.index', [
            'projects' => $projects,
            'stats' => $stats,
        ]);
    }

    /**
     * `GET /projects/create` — Màn hình khởi tạo dự án mới (Tab Kiến Thức & Tab Sản Phẩm 3D).
     */
    public function create(): View
    {
        return view('projects.create', [
            'projectTypes' => ProjectType::cases(),
            'costTiers' => CostTierMode::cases(),
            'workflowModes' => WorkflowMode::cases(),
        ]);
    }

    /**
     * `POST /projects` — Lưu dự án mới, tải lên ảnh sản phẩm (nếu có), tính `cache_key` 3D
     * và kích hoạt Queue Worker tương ứng trên PM2.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'project_type' => ['required', 'string', 'in:knowledge,product'],
            'title' => ['required', 'string', 'max:255'],
            'topic_or_brief' => ['nullable', 'string', 'max:5000'],
            'aspect_ratio' => ['required', 'string', 'in:9:16,16:9'],
            'cost_tier_mode' => ['required', 'string', 'in:eco_mode,full_ai_video_mode'],
            'workflow_mode' => ['required', 'string', 'in:step_by_step,auto'],
            'voice_id' => ['required', 'string', 'in:vi-VN-NamMinhNeural,vi-VN-HoaiMyNeural'],

            // Các trường dành riêng cho Tab 2: Video Sản Phẩm 3D
            'product_name' => ['required_if:project_type,product', 'nullable', 'string', 'max:255'],
            'sku_code' => ['nullable', 'string', 'max:100'],
            'product_description' => ['nullable', 'string', 'max:5000'],
            'width_mm' => ['required_if:project_type,product', 'nullable', 'numeric', 'min:1', 'max:50000'],
            'height_mm' => ['required_if:project_type,product', 'nullable', 'numeric', 'min:1', 'max:50000'],
            'depth_mm' => ['required_if:project_type,product', 'nullable', 'numeric', 'min:1', 'max:50000'],
            'product_images' => ['required_if:project_type,product', 'nullable', 'array', 'min:1'],
            'product_images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:15360'],
        ]);

        $projectType = ProjectType::from($validated['project_type']);

        $project = DB::transaction(function () use ($request, $validated, $projectType): VideoProject {
            $project = VideoProject::query()->create([
                'title' => $validated['title'],
                'project_type' => $projectType,
                'topic_or_brief' => $validated['topic_or_brief'] ?? ($validated['product_description'] ?? ''),
                'aspect_ratio' => $validated['aspect_ratio'],
                'status' => ProjectStatus::DRAFT,
                'workflow_mode' => WorkflowMode::from($validated['workflow_mode']),
                'cost_tier_mode' => CostTierMode::from($validated['cost_tier_mode']),
                'voice_id' => $validated['voice_id'],
            ]);

            if ($projectType === ProjectType::PRODUCT) {
                $uploadedPaths = [];
                $firstFileHash = 'default_seed';

                /** @var array<int, UploadedFile> $files */
                $files = $request->file('product_images', []);
                foreach ($files as $idx => $file) {
                    $storedPath = $file->store("products/originals/project_{$project->id}", 'public');
                    if ($storedPath !== false) {
                        $uploadedPaths[] = $storedPath;
                        if ($idx === 0) {
                            $fullFile = Storage::disk('public')->path($storedPath);
                            $firstFileHash = is_file($fullFile) ? (md5_file($fullFile) ?: 'seed') : 'seed';
                        }
                    }
                }

                $widthMm = (float) ($validated['width_mm'] ?? 100.0);
                $heightMm = (float) ($validated['height_mm'] ?? 100.0);
                $depthMm = (float) ($validated['depth_mm'] ?? 100.0);
                $skuOrName = (string) ($validated['sku_code'] ?: $validated['product_name']);

                $cacheKey = ProductProfile::generateCacheKey(
                    skuOrName: $skuOrName,
                    primaryImageHash: $firstFileHash,
                    widthMm: $widthMm,
                    heightMm: $heightMm,
                    depthMm: $depthMm
                );

                // Nếu trong DB đã có ProductProfile cùng cache_key, tạo hậu tố tránh đụng unique constraint
                if (ProductProfile::query()->where('cache_key', $cacheKey)->exists()) {
                    $cacheKey = substr($cacheKey, 0, 56) . '_p' . $project->id;
                }

                ProductProfile::query()->create([
                    'video_project_id' => $project->id,
                    'product_name' => (string) $validated['product_name'],
                    'sku_code' => $validated['sku_code'] ?? null,
                    'product_description' => $validated['product_description'] ?? $validated['topic_or_brief'] ?? null,
                    'width_mm' => $widthMm,
                    'height_mm' => $heightMm,
                    'depth_mm' => $depthMm,
                    'original_image_paths' => $uploadedPaths,
                    'cache_key' => $cacheKey,
                ]);
            }

            return $project;
        });

        // Kích hoạt bước đầu tiên thông qua State Machine Orchestrator
        try {
            $this->orchestrator->dispatchNextStep($project);
        } catch (\Throwable $e) {
            // Đảm bảo luôn điều hướng về màn hình Studio thay vì trang lỗi 500 khi chạy QUEUE_CONNECTION=sync
            $this->orchestrator->markAsFailed($project, $e->getMessage());
        }

        return redirect()
            ->route('projects.studio', ['id' => $project->id])
            ->with('success', 'Dự án đã được khởi tạo thành công!');
    }

    /**
     * `GET /projects/{id}/studio` — Màn hình Studio Chính hiển thị Thanh tiến trình 5 chặng,
     * 4 Cổng kiểm duyệt tương tác (CP1 -> CP4), Trình xem 3D `<model-viewer>` và Trình phát Video hoàn chỉnh.
     */
    public function studio(int $id): View
    {
        $project = VideoProject::query()
            ->with(['productProfile', 'scenes', 'generationLogs'])
            ->findOrFail($id);

        return view('projects.studio', [
            'project' => $project,
            'initialPayload' => $this->buildStudioStatusPayload($project),
        ]);
    }

    /**
     * `GET /api/projects/{id}/status` — Endpoint JSON phục vụ Real-time Polling (mỗi 2-3 giây)
     * để giao diện Studio tự động cập nhật tiến độ, bật sáng Cổng kiểm duyệt và nạp dữ liệu mới.
     */
    public function status(int $id): JsonResponse
    {
        $project = VideoProject::query()
            ->with(['productProfile', 'scenes', 'generationLogs'])
            ->findOrFail($id);

        return response()->json($this->buildStudioStatusPayload($project));
    }

    /**
     * Xây dựng cấu trúc dữ liệu JSON đồng nhất cho cả lần render đầu tiên và các chu kỳ Polling.
     *
     * @return array<string, mixed>
     */
    private function buildStudioStatusPayload(VideoProject $project): array
    {
        $disk = Storage::disk('public');
        $profile = $project->productProfile;

        // Xác định bước hiện tại trên thanh Stepper 5 chặng (1 -> 5)
        $currentStageNumber = match ($project->status) {
            ProjectStatus::DRAFT,
            ProjectStatus::ANALYZING,
            ProjectStatus::WAITING_APPROVAL_PRODUCT => 1,

            ProjectStatus::SCRIPTING,
            ProjectStatus::WAITING_APPROVAL_SCRIPT => 2,

            ProjectStatus::GENERATING_AUDIO,
            ProjectStatus::WAITING_APPROVAL_AUDIO => 3,

            ProjectStatus::GENERATING_VISUALS,
            ProjectStatus::WAITING_APPROVAL_SCENES => 4,

            ProjectStatus::RENDERING,
            ProjectStatus::COMPLETED => 5,

            ProjectStatus::FAILED => max(1, $project->scenes->isNotEmpty() ? 3 : 1),
        };

        $isProcessing = in_array($project->status, [
            ProjectStatus::DRAFT,
            ProjectStatus::ANALYZING,
            ProjectStatus::SCRIPTING,
            ProjectStatus::GENERATING_AUDIO,
            ProjectStatus::GENERATING_VISUALS,
            ProjectStatus::RENDERING,
        ], true);

        // Chuẩn bị dữ liệu Hồ sơ sản phẩm & Mô hình 3D (.glb + 4 ảnh Turntable)
        $productData = null;
        if ($profile instanceof ProductProfile) {
            $turntableUrls = [];
            $turntableDir = "products/turntable/project_{$project->id}";
            foreach ([0, 90, 180, 270] as $angle) {
                $candidateRel = sprintf('%s/turntable_%03d.png', $turntableDir, $angle);
                if ($disk->exists($candidateRel)) {
                    $turntableUrls[] = [
                        'angle' => $angle,
                        'url' => asset('storage/' . $candidateRel),
                    ];
                }
            }

            $customerAnalysis = (array) data_get($profile->customer_analysis_json, 'customer_analysis', [
                'target_audience' => 'Người tiêu dùng trẻ 18-35 tuổi yêu thích sản phẩm chất lượng cao tại Việt Nam',
                'customer_persona' => 'Khách hàng bận rộn, thích xem review trực quan ngắn gọn trước khi chốt đơn',
                'pain_points' => ['Khó hình dung kích thước thật khi mua hàng online', 'Sợ sản phẩm thực tế khác ảnh quảng cáo'],
                'unique_selling_points' => ['Thiết kế chuẩn tỷ lệ thực tế 100%', 'Chất liệu cao cấp, độ hoàn thiện sắc nét'],
                'real_world_scale_context' => sprintf(
                    'Kích thước chuẩn: Rộng %.0fmm x Cao %.0fmm x Sâu %.0fmm',
                    $profile->width_mm,
                    $profile->height_mm,
                    $profile->depth_mm
                ),
            ]);

            $productData = [
                'id' => $profile->id,
                'product_name' => $profile->product_name,
                'sku_code' => $profile->sku_code,
                'product_description' => $profile->product_description,
                'width_mm' => $profile->width_mm,
                'height_mm' => $profile->height_mm,
                'depth_mm' => $profile->depth_mm,
                'dimensions_meters' => $profile->dimensionsInMeters(),
                'nobg_image_url' => $profile->nobg_image_path ? asset('storage/' . $profile->nobg_image_path) : null,
                'normalized_glb_url' => $profile->normalized_glb_path ? asset('storage/' . $profile->normalized_glb_path) : null,
                'turntable_snapshots' => $turntableUrls,
                'customer_analysis' => $customerAnalysis,
                'cache_key' => $profile->cache_key,
            ];
        }

        // Chuẩn bị danh sách phân cảnh (Storyboard Scenes)
        $scenesPayload = $project->scenes->map(static function (StoryboardScene $scene): array {
            return [
                'id' => $scene->id,
                'scene_order' => $scene->scene_order,
                'narration_text' => $scene->narration_text,
                'visual_prompt' => $scene->visual_prompt,
                'sfx_prompt' => $scene->sfx_prompt ?? 'whoosh',
                'camera_motion' => $scene->camera_motion,
                'transition_type' => $scene->transition_type,
                'transition_duration_ms' => $scene->transition_duration_ms,
                'duration_ms' => $scene->duration_ms,
                'duration_seconds' => $scene->durationInSeconds(),
                'voice_url' => $scene->voice_file_path ? asset('storage/' . $scene->voice_file_path) : null,
                'subtitle_url' => $scene->subtitle_file_path ? asset('storage/' . $scene->subtitle_file_path) : null,
                'image_url' => $scene->image_file_path ? asset('storage/' . $scene->image_file_path) : null,
                'video_url' => $scene->video_file_path ? asset('storage/' . $scene->video_file_path) : null,
                'sfx_file_name' => $scene->sfx_file_path ? basename($scene->sfx_file_path) : null,
                'sfx_file_path' => $scene->sfx_file_path,
            ];
        })->values()->all();

        // Tổng thời gian xử lý máy chủ (tính từ tổng latency_ms trong generation_logs)
        $totalRenderLatencyMs = (int) $project->generationLogs->sum('latency_ms');

        $logsPayload = $project->generationLogs->take(15)->map(static function (GenerationLog $log): array {
            return [
                'id' => $log->id,
                'provider' => $log->provider,
                'model_name' => $log->model_name,
                'action_type' => $log->action_type,
                'status' => $log->status,
                'cost_usd' => round($log->cost_usd, 4),
                'latency_ms' => $log->latency_ms,
                'created_at' => $log->created_at?->format('H:i:s d/m/Y'),
            ];
        })->values()->all();

        return [
            'project' => [
                'id' => $project->id,
                'uuid' => $project->uuid,
                'title' => $project->title,
                'project_type' => $project->project_type->value,
                'project_type_label' => $project->project_type->label(),
                'topic_or_brief' => $project->topic_or_brief,
                'aspect_ratio' => $project->aspect_ratio,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'is_checkpoint' => $project->status->isCheckpoint(),
                'checkpoint_number' => $project->status->checkpointNumber(),
                'is_processing' => $isProcessing,
                'current_stage_number' => $currentStageNumber,
                'workflow_mode' => $project->workflow_mode->value,
                'workflow_mode_label' => $project->workflow_mode->label(),
                'cost_tier_mode' => $project->cost_tier_mode->value,
                'cost_tier_label' => $project->cost_tier_mode->label(),
                'voice_id' => $project->voice_id,
                'master_style_prompt' => $project->master_style_prompt ?? '',
                'bgm_file_path' => $project->bgm_file_path,
                'final_video_url' => $project->final_video_path ? asset('storage/' . $project->final_video_path) : null,
                'final_video_path' => $project->final_video_path,
                'total_duration_ms' => $project->total_duration_ms,
                'total_duration_seconds' => round($project->total_duration_ms / 1000.0, 1),
                'total_cost_usd' => round($project->total_cost_usd, 4),
                'total_processing_seconds' => round($totalRenderLatencyMs / 1000.0, 1),
                'scenes_count' => count($scenesPayload),
                'error_message' => $project->error_message,
            ],
            'product_profile' => $productData,
            'scenes' => $scenesPayload,
            'generation_logs' => $logsPayload,
        ];
    }
}
