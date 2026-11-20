<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\HealthPlanItem;
use App\Models\VideoProject;
use App\Services\HealthAgents\Agent2SeoTitleService;
use App\Services\HealthAgents\Agent3HealthScriptwriterService;
use App\Services\HealthAgents\Agent4AsianFootageHunterService;
use App\Services\HealthAgents\Agent5MultiVoiceStudioService;
use App\Services\HealthAgents\Agent6PhotographicThumbnailService;
use App\Services\HealthAgents\Agent7CapCutCompositorService;
use App\Services\HealthAgents\Agent8QaDirectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job điều phối trọn bộ Dây chuyền 8-Agent "Sống Khỏe Mỗi Ngày":
 *  Agent 3 (Kịch bản) -> Agent 5 (Giọng đọc khóa 1 giọng) -> Agent 4 (Video Châu Á)
 *  -> Agent 6 (Ảnh bìa Photographic) -> Agent 7 (Hậu kỳ CapCut + Phụ đề tùy chọn + SFX)
 *  -> Agent 8 (Trưởng phòng Kiểm duyệt -> Nếu REJECTED tự động bắt Agent lỗi làm lại!).
 */
class RunHealth8AgentPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, string>  $onlyAgents  Nếu chỉ định (vd: ['agent6']), chỉ chạy lại các Agent này rồi kiểm duyệt lại.
     */
    public function __construct(
        public readonly int $projectId,
        public readonly array $onlyAgents = [],
        public readonly ?string $manualFeedback = null
    ) {
        $this->onQueue('ai-orchestration');
    }

    public function handle(
        Agent2SeoTitleService $agent2,
        Agent3HealthScriptwriterService $agent3,
        Agent4AsianFootageHunterService $agent4,
        Agent5MultiVoiceStudioService $agent5,
        Agent6PhotographicThumbnailService $agent6,
        Agent7CapCutCompositorService $agent7,
        Agent8QaDirectorService $agent8
    ): void {
        $project = VideoProject::query()->with(['scenes', 'planItem'])->find($this->projectId);
        if (! $project) {
            return;
        }

        try {
            $project->update([
                'status' => ProjectStatus::GENERATING_SCRIPT,
                'qa_status' => 'running',
                'error_message' => null,
            ]);

            $planItem = $project->health_plan_item_id
                ? HealthPlanItem::query()->find($project->health_plan_item_id)
                : $project->planItem;

            $runAll = $this->onlyAgents === [];
            $targets = $this->onlyAgents;

            // Vòng sản xuất chính (hỗ trợ chạy toàn bộ hoặc chạy lại riêng từng Agent theo lệnh)
            $this->executeSelectedAgents(
                project: $project,
                planItem: $planItem,
                targets: $runAll ? ['agent3', 'agent5', 'agent4', 'agent6', 'agent7'] : $targets,
                feedback: $this->manualFeedback,
                forceRebuild: ! $runAll,
                agent2: $agent2,
                agent3: $agent3,
                agent4: $agent4,
                agent5: $agent5,
                agent6: $agent6,
                agent7: $agent7
            );

            // Agent AI 8: Trưởng phòng Kiểm duyệt
            $review = $agent8->inspectProject($project->refresh());

            // Cơ chế BẮT BUỘC LÀM LẠI (Auto-Rework tối đa 1 vòng tự động khi chưa đạt chuẩn)
            if ($review->status === 'rejected' && $project->qa_attempts < 2) {
                $failedAgents = is_array($review->failed_agents_json) && $review->failed_agents_json !== []
                    ? $review->failed_agents_json
                    : ['agent3', 'agent5', 'agent7'];

                Log::info("[RunHealth8AgentPipelineJob] Agent 8 từ chối Project #{$project->id}, tự động yêu cầu làm lại: " . implode(', ', $failedAgents));

                // Nếu Agent 3 sửa kịch bản -> bắt buộc chạy lại Agent 5 (Voice), Agent 4 (Video), Agent 7 (Compose)
                if (in_array('agent3', $failedAgents, true)) {
                    $failedAgents = array_values(array_unique(array_merge($failedAgents, ['agent5', 'agent4', 'agent7'])));
                }
                if (in_array('agent4', $failedAgents, true) || in_array('agent5', $failedAgents, true)) {
                    $failedAgents = array_values(array_unique(array_merge($failedAgents, ['agent7'])));
                }

                $this->executeSelectedAgents(
                    project: $project->refresh(),
                    planItem: $planItem,
                    targets: $failedAgents,
                    feedback: $review->feedback_notes,
                    forceRebuild: true,
                    agent2: $agent2,
                    agent3: $agent3,
                    agent4: $agent4,
                    agent5: $agent5,
                    agent6: $agent6,
                    agent7: $agent7
                );

                // Kiểm duyệt lại lần 2
                $agent8->inspectProject($project->refresh());
            }

            $project->refresh()->update([
                'status' => ProjectStatus::COMPLETED,
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            Log::error("[RunHealth8AgentPipelineJob] Lỗi Project #{$this->projectId}: " . $e->getMessage(), [
                'trace' => mb_substr($e->getTraceAsString(), 0, 1000),
            ]);

            $project->refresh()->update([
                'status' => ProjectStatus::FAILED,
                'qa_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, string>  $targets
     */
    private function executeSelectedAgents(
        VideoProject $project,
        ?HealthPlanItem $planItem,
        array $targets,
        ?string $feedback,
        bool $forceRebuild,
        Agent2SeoTitleService $agent2,
        Agent3HealthScriptwriterService $agent3,
        Agent4AsianFootageHunterService $agent4,
        Agent5MultiVoiceStudioService $agent5,
        Agent6PhotographicThumbnailService $agent6,
        Agent7CapCutCompositorService $agent7
    ): void {
        if (in_array('agent2', $targets, true) && $planItem !== null) {
            $planItem = $agent2->generateForItem($planItem, $feedback);
            $project->update(['title' => $planItem->seo_title_tiktok ?: $project->title]);
        }

        if (in_array('agent3', $targets, true) || $project->scenes()->count() === 0) {
            $project->update(['status' => ProjectStatus::GENERATING_SCRIPT]);
            $project = $agent3->writeScriptForProject($project, $planItem, $feedback);
        }

        if (in_array('agent5', $targets, true)) {
            $project->update(['status' => ProjectStatus::GENERATING_VOICE]);
            $project = $agent5->synthesizeProjectVoice($project, $forceRebuild);
        }

        if (in_array('agent4', $targets, true)) {
            $project->update(['status' => ProjectStatus::GENERATING_VISUALS]);
            $project = $agent4->huntFootageForProject($project, $forceRebuild);
        }

        if (in_array('agent6', $targets, true)) {
            $project = $agent6->generateThumbnailForProject($project, $forceRebuild);
        }

        if (in_array('agent7', $targets, true)) {
            $project->update(['status' => ProjectStatus::RENDERING_FINAL]);
            $agent7->composeProjectVideo($project);
        }
    }
}
