<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectType;
use App\Models\ProductProfile;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\AI\GeminiFreeLlmService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * BƯỚC 2 (Queue: `ai-orchestration`): Phân tích chủ đề kiến thức hoặc hồ sơ sản phẩm
 * bằng Google Gemini 2.5 Flash Free Tier ($0.00), tạo `master_style_prompt` và khởi tạo
 * danh sách các bản ghi `StoryboardScene` trong cơ sở dữ liệu.
 */
class Step2_GenerateScriptAndScenesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $videoProjectId
    ) {
        $this->onQueue('ai-orchestration');
    }

    public function handle(
        GeminiFreeLlmService $geminiService,
        WorkflowOrchestrator $orchestrator
    ): void {
        $project = VideoProject::query()->with('productProfile')->findOrFail($this->videoProjectId);

        try {
            if ($project->project_type === ProjectType::PRODUCT) {
                $analysisResult = $this->runProductAnalysis($project, $geminiService);
            } else {
                $topic = (string) ($project->topic_or_brief ?: $project->title);
                $analysisResult = $geminiService->analyzeKnowledgeTopic($topic, $project->id);
            }

            $scenesData = (array) ($analysisResult['scenes'] ?? []);
            if ($scenesData === []) {
                throw new RuntimeException('Gemini 2.5 Flash không trả về danh sách phân cảnh (scenes) hợp lệ.');
            }

            DB::transaction(function () use ($project, $analysisResult, $scenesData): void {
                // Cập nhật tiêu đề gợi ý và master_style_prompt cho dự án
                $project->update([
                    'title' => (string) ($analysisResult['title'] ?? $project->title),
                    'master_style_prompt' => (string) ($analysisResult['master_style_prompt'] ?? $project->master_style_prompt),
                ]);

                // Lưu kết quả phân tích chân dung khách hàng vào ProductProfile (nếu là dự án PRODUCT)
                if ($project->productProfile instanceof ProductProfile && isset($analysisResult['customer_analysis'])) {
                    $existingJson = is_array($project->productProfile->customer_analysis_json)
                        ? $project->productProfile->customer_analysis_json
                        : [];

                    $project->productProfile->update([
                        'customer_analysis_json' => array_merge($existingJson, [
                            'customer_analysis' => $analysisResult['customer_analysis'],
                        ]),
                    ]);
                }

                // Xóa các phân cảnh cũ (nếu chạy lại bước tạo kịch bản) và khởi tạo mới
                $project->scenes()->delete();

                foreach (array_values($scenesData) as $idx => $sceneItem) {
                    $order = (int) ($sceneItem['scene_order'] ?? ($idx + 1));

                    StoryboardScene::query()->create([
                        'video_project_id' => $project->id,
                        'scene_order' => $order,
                        'narration_text' => trim((string) ($sceneItem['narration_text'] ?? '')),
                        'visual_prompt' => trim((string) ($sceneItem['visual_prompt'] ?? '')),
                        'sfx_prompt' => trim((string) ($sceneItem['sfx_prompt'] ?? 'whoosh')),
                        'camera_motion' => (string) ($sceneItem['camera_motion'] ?? 'zoom_in'),
                        'transition_type' => (string) ($sceneItem['transition_type'] ?? 'fade'),
                        'transition_duration_ms' => 500,
                        'duration_ms' => 0,
                    ]);
                }
            });

            // Chuyển sang Cổng kiểm duyệt số 2 (WAITING_APPROVAL_SCRIPT) hoặc đi tiếp sang GENERATING_AUDIO
            $orchestrator->dispatchNextStep($project);
        } catch (Throwable $e) {
            $orchestrator->markAsFailed($project, 'Step 2 (Biên tập Kịch bản & Phân cảnh) thất bại: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Gọi Gemini 2.5 Flash Vision phân tích sản phẩm kèm ảnh thực tế và kích thước (mm).
     *
     * @return array<string, mixed>
     */
    private function runProductAnalysis(VideoProject $project, GeminiFreeLlmService $geminiService): array
    {
        $profile = $project->productProfile;
        if (! $profile instanceof ProductProfile) {
            throw new RuntimeException('Không tìm thấy ProductProfile để phân tích kịch bản sản phẩm.');
        }

        $disk = Storage::disk('public');
        $resolvedImages = [];

        if ($profile->nobg_image_path !== null && $disk->exists($profile->nobg_image_path)) {
            $resolvedImages[] = $disk->path($profile->nobg_image_path);
        }

        foreach ((array) $profile->original_image_paths as $imgPath) {
            $pathStr = (string) $imgPath;
            if (is_file($pathStr)) {
                $resolvedImages[] = $pathStr;
            } elseif ($disk->exists($pathStr)) {
                $resolvedImages[] = $disk->path($pathStr);
            }
        }

        $description = sprintf(
            '%s - %s',
            $profile->product_name,
            (string) ($profile->product_description ?: $project->topic_or_brief)
        );

        return $geminiService->analyzeProduct(
            description: $description,
            imagePaths: array_values(array_unique($resolvedImages)),
            dimensions: [
                'width_mm' => $profile->width_mm,
                'height_mm' => $profile->height_mm,
                'depth_mm' => $profile->depth_mm,
            ],
            videoProjectId: $project->id
        );
    }
}
