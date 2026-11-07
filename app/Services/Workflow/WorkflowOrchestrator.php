<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\WorkflowMode;
use App\Jobs\Step1_AnalyzeProductAnd3DJob;
use App\Jobs\Step2_GenerateScriptAndScenesJob;
use App\Jobs\Step3_GenerateVoiceAndSfxJob;
use App\Jobs\Step4_GenerateAiSceneVideosJob;
use App\Jobs\Step5_RenderFinalVideoJob;
use App\Models\VideoProject;
use RuntimeException;

/**
 * Bộ điều phối trung tâm (State Machine Orchestrator) quản lý vòng đời dự án video
 * qua 4 Cổng kiểm duyệt (Checkpoints 1 -> 4) và chế độ Tự động hoàn toàn (Full-Auto).
 *
 * Phân luồng Queue Jobs chuẩn xác sang 3 nhóm PM2 Workers trên CentOS 8:
 *  - `3d-processing`    : Step1_AnalyzeProductAnd3DJob (rembg + Fal Trellis + trimesh)
 *  - `ai-orchestration` : Step2_GenerateScriptAndScenesJob, Step3_GenerateVoiceAndSfxJob, Step4_GenerateAiSceneVideosJob
 *  - `ffmpeg-render`    : Step5_RenderFinalVideoJob (FFmpeg 60fps xfade + sidechaincompress + ASS subtitles)
 */
class WorkflowOrchestrator
{
    /**
     * Đánh giá trạng thái hiện tại của dự án và chuyển tiếp sang bước kế tiếp.
     *
     * - Nếu `workflow_mode === WorkflowMode::STEP_BY_STEP` và bước kế tiếp là một Cổng kiểm duyệt
     *   (`WAITING_APPROVAL_*`): Cập nhật trạng thái sang cổng duyệt và DỪNG LẠI chờ người dùng duyệt.
     * - Nếu `workflow_mode === WorkflowMode::AUTO` hoặc đang đi từ một Cổng kiểm duyệt đã được duyệt:
     *   Chuyển thẳng sang trạng thái thực thi và đẩy Job vào đúng Queue PM2.
     */
    public function dispatchNextStep(VideoProject $project): void
    {
        $project->refresh();
        $currentStatus = $project->status;

        // Trường hợp khởi động dự án từ trạng thái DRAFT:
        // Nếu là dự án KNOWLEDGE (không cần xử lý khối 3D sản phẩm) -> nhảy thẳng sang SCRIPTING
        if ($currentStatus === ProjectStatus::DRAFT) {
            if ($project->project_type === ProjectType::PRODUCT) {
                $this->transitionAndDispatchJob($project, ProjectStatus::ANALYZING);
            } else {
                $this->transitionAndDispatchJob($project, ProjectStatus::SCRIPTING);
            }

            return;
        }

        $nextStatus = $currentStatus->nextStatus($project->workflow_mode, $project->project_type);

        if ($nextStatus === null) {
            return;
        }

        // Nếu bước kế tiếp là một trong 4 Cổng kiểm duyệt (khi ở chế độ STEP_BY_STEP):
        // Chỉ cập nhật trạng thái và dừng lại chờ người dùng kiểm duyệt trên giao diện Web.
        if ($nextStatus->isCheckpoint()) {
            $project->update([
                'status' => $nextStatus,
                'error_message' => null,
            ]);

            return;
        }

        // Nếu bước kế tiếp là COMPLETED
        if ($nextStatus === ProjectStatus::COMPLETED) {
            $project->update([
                'status' => ProjectStatus::COMPLETED,
                'error_message' => null,
            ]);

            return;
        }

        // Nếu bước kế tiếp là trạng thái xử lý (ANALYZING, SCRIPTING, GENERATING_AUDIO, GENERATING_VISUALS, RENDERING)
        $this->transitionAndDispatchJob($project, $nextStatus);
    }

    /**
     * Xác nhận duyệt một Cổng kiểm duyệt cụ thể (CP1 -> CP4) và kích hoạt Queue Job của bước kế tiếp.
     */
    public function approveCheckpoint(VideoProject $project, ProjectStatus $expectedCheckpoint): ProjectStatus
    {
        $project->refresh();

        if ($project->status !== $expectedCheckpoint) {
            throw new RuntimeException(sprintf(
                'Trạng thái dự án hiện tại là [%s], không khớp với cổng kiểm duyệt yêu cầu [%s].',
                $project->status->value,
                $expectedCheckpoint->value
            ));
        }

        $nextExecutionStatus = $expectedCheckpoint->nextStatus(
            WorkflowMode::STEP_BY_STEP,
            $project->project_type
        );

        if ($nextExecutionStatus === null) {
            throw new RuntimeException('Không xác định được bước thực thi kế tiếp sau cổng kiểm duyệt.');
        }

        $this->transitionAndDispatchJob($project, $nextExecutionStatus);

        return $nextExecutionStatus;
    }

    /**
     * Chuyển đổi chế độ vận hành dự án giữa `STEP_BY_STEP` (Duyệt từng bước) và `AUTO` (Tự động hoàn toàn).
     * Nếu dự án đang dừng chờ ở một Cổng kiểm duyệt và được gạt sang `AUTO`, hệ thống lập tức kích hoạt bước kế tiếp!
     */
    public function toggleWorkflowMode(VideoProject $project, ?WorkflowMode $targetMode = null): VideoProject
    {
        $newMode = $targetMode ?? (
            $project->workflow_mode === WorkflowMode::STEP_BY_STEP
                ? WorkflowMode::AUTO
                : WorkflowMode::STEP_BY_STEP
        );

        $project->update([
            'workflow_mode' => $newMode,
        ]);

        // Nếu vừa chuyển sang AUTO trong lúc đang dừng ở Cổng kiểm duyệt (CP1 -> CP4) -> tự động chạy tiếp!
        if ($newMode === WorkflowMode::AUTO && $project->status->isCheckpoint()) {
            $this->dispatchNextStep($project);
        }

        return $project->refresh();
    }

    /**
     * Đánh dấu dự án bị lỗi (`FAILED`) kèm thông báo chi tiết để người dùng dễ dàng xử lý/thử lại.
     */
    public function markAsFailed(VideoProject $project, string $errorMessage): void
    {
        $project->update([
            'status' => ProjectStatus::FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Cập nhật trạng thái thực thi và đẩy Job tương ứng vào đúng hàng đợi PM2 (`3d-processing`, `ai-orchestration`, `ffmpeg-render`).
     */
    public function transitionAndDispatchJob(VideoProject $project, ProjectStatus $executionStatus): void
    {
        $project->update([
            'status' => $executionStatus,
            'error_message' => null,
        ]);

        match ($executionStatus) {
            ProjectStatus::ANALYZING => Step1_AnalyzeProductAnd3DJob::dispatch($project->id)
                ->onQueue('3d-processing'),

            ProjectStatus::SCRIPTING => Step2_GenerateScriptAndScenesJob::dispatch($project->id)
                ->onQueue('ai-orchestration'),

            ProjectStatus::GENERATING_AUDIO => Step3_GenerateVoiceAndSfxJob::dispatch($project->id)
                ->onQueue('ai-orchestration'),

            ProjectStatus::GENERATING_VISUALS => Step4_GenerateAiSceneVideosJob::dispatch($project->id)
                ->onQueue('ai-orchestration'),

            ProjectStatus::RENDERING => Step5_RenderFinalVideoJob::dispatch($project->id)
                ->onQueue('ffmpeg-render'),

            default => null,
        };
    }
}
