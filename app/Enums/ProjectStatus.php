<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum quản lý vòng đời trạng thái của Dự án Video (State Machine)
 * Hỗ trợ 4 Cổng kiểm duyệt (Checkpoints 1 -> 4) và chế độ Tự động hoàn toàn (Full-Auto).
 */
enum ProjectStatus: string
{
    case DRAFT = 'draft';
    case ANALYZING = 'analyzing';
    case WAITING_APPROVAL_PRODUCT = 'waiting_approval_product'; // Checkpoint 1 (CP1)
    case SCRIPTING = 'scripting';
    case WAITING_APPROVAL_SCRIPT = 'waiting_approval_script';   // Checkpoint 2 (CP2)
    case GENERATING_AUDIO = 'generating_audio';
    case WAITING_APPROVAL_AUDIO = 'waiting_approval_audio';     // Checkpoint 3 (CP3)
    case GENERATING_VISUALS = 'generating_visuals';
    case WAITING_APPROVAL_SCENES = 'waiting_approval_scenes';   // Checkpoint 4 (CP4)
    case RENDERING = 'rendering';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * Trả về tên hiển thị tiếng Việt của từng trạng thái.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Bản nháp (Khởi tạo)',
            self::ANALYZING => 'Đang phân tích & Chuẩn hóa 3D',
            self::WAITING_APPROVAL_PRODUCT => 'Chờ duyệt Hồ sơ Sản phẩm & 3D (Cổng 1)',
            self::SCRIPTING => 'Đang biên tập Kịch bản Phân cảnh',
            self::WAITING_APPROVAL_SCRIPT => 'Chờ duyệt Kịch bản & Prompt (Cổng 2)',
            self::GENERATING_AUDIO => 'Đang tạo Giọng đọc Tiếng Việt (Edge-TTS)',
            self::WAITING_APPROVAL_AUDIO => 'Chờ duyệt Giọng đọc & Phụ đề (Cổng 3)',
            self::GENERATING_VISUALS => 'Đang tạo Hình ảnh / Video AI (Fal.ai)',
            self::WAITING_APPROVAL_SCENES => 'Chờ duyệt Cảnh quay & Chuyển cảnh (Cổng 4)',
            self::RENDERING => 'Đang kết xuất Video 60fps (FFmpeg)',
            self::COMPLETED => 'Hoàn thành xuất bản',
            self::FAILED => 'Thất bại (Cần kiểm tra lỗi)',
        };
    }

    /**
     * Kiểm tra trạng thái hiện tại có phải là một trong 4 Cổng kiểm duyệt (Checkpoints) hay không.
     */
    public function isCheckpoint(): bool
    {
        return match ($this) {
            self::WAITING_APPROVAL_PRODUCT,
            self::WAITING_APPROVAL_SCRIPT,
            self::WAITING_APPROVAL_AUDIO,
            self::WAITING_APPROVAL_SCENES => true,
            default => false,
        };
    }

    /**
     * Trả về số thứ tự của Cổng kiểm duyệt (1 -> 4), hoặc null nếu không phải cổng duyệt.
     */
    public function checkpointNumber(): ?int
    {
        return match ($this) {
            self::WAITING_APPROVAL_PRODUCT => 1,
            self::WAITING_APPROVAL_SCRIPT => 2,
            self::WAITING_APPROVAL_AUDIO => 3,
            self::WAITING_APPROVAL_SCENES => 4,
            default => null,
        };
    }

    /**
     * Xác định trạng thái kế tiếp dựa trên chế độ duyệt (Step-by-Step hoặc Full-Auto)
     * và loại dự án (Knowledge hay Product).
     */
    public function nextStatus(WorkflowMode $workflowMode, ProjectType $projectType): ?self
    {
        $isAuto = $workflowMode === WorkflowMode::AUTO;

        return match ($this) {
            self::DRAFT => self::ANALYZING,

            // Nếu là dự án Kiến thức (không có 3D sản phẩm) hoặc đang bật Full-Auto -> bỏ qua CP1
            self::ANALYZING => ($isAuto || $projectType === ProjectType::KNOWLEDGE)
                ? self::SCRIPTING
                : self::WAITING_APPROVAL_PRODUCT,

            self::WAITING_APPROVAL_PRODUCT => self::SCRIPTING,

            self::SCRIPTING => $isAuto
                ? self::GENERATING_AUDIO
                : self::WAITING_APPROVAL_SCRIPT,

            self::WAITING_APPROVAL_SCRIPT => self::GENERATING_AUDIO,

            self::GENERATING_AUDIO => $isAuto
                ? self::GENERATING_VISUALS
                : self::WAITING_APPROVAL_AUDIO,

            self::WAITING_APPROVAL_AUDIO => self::GENERATING_VISUALS,

            self::GENERATING_VISUALS => $isAuto
                ? self::RENDERING
                : self::WAITING_APPROVAL_SCENES,

            self::WAITING_APPROVAL_SCENES => self::RENDERING,

            self::RENDERING => self::COMPLETED,

            self::COMPLETED, self::FAILED => null,
        };
    }

    /**
     * Xác định tên Queue PM2 phụ trách xử lý trạng thái hiện tại.
     */
    public function targetQueue(ProjectType $projectType = ProjectType::KNOWLEDGE): ?string
    {
        return match ($this) {
            self::ANALYZING => $projectType === ProjectType::PRODUCT
                ? '3d-processing'
                : 'ai-orchestration',
            self::SCRIPTING,
            self::GENERATING_AUDIO,
            self::GENERATING_VISUALS => 'ai-orchestration',
            self::RENDERING => 'ffmpeg-render',
            default => null,
        };
    }
}
