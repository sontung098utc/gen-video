<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum định nghĩa phân tầng chi phí sản xuất hình ảnh/video AI:
 * - ECO_MODE: Dùng fal-ai/flux/schnell ($0.003/ảnh) + FFmpeg 60fps Dynamic Motion (zoompan).
 * - FULL_AI_VIDEO_MODE: Dùng fal-ai/ltx-video/image-to-video ($0.02/clip 5s) hoặc Headless 3D Render từ .glb.
 */
enum CostTierMode: string
{
    case ECO_MODE = 'eco_mode';
    case FULL_AI_VIDEO_MODE = 'full_ai_video_mode';

    /**
     * Nhãn hiển thị tiếng Việt kèm đơn giá ước tính mỗi phân cảnh.
     */
    public function label(): string
    {
        return match ($this) {
            self::ECO_MODE => 'Chế độ Siêu Tiết Kiệm - Eco Mode ($0.003/cảnh + FFmpeg 60fps)',
            self::FULL_AI_VIDEO_MODE => 'Chế độ Full AI Video / 3D Render ($0.020/clip 5s)',
        };
    }

    /**
     * Chi phí ước tính (USD) cho mỗi phân cảnh hình ảnh/video.
     */
    public function estimatedSceneCostUsd(): float
    {
        return match ($this) {
            self::ECO_MODE => 0.0030,
            self::FULL_AI_VIDEO_MODE => 0.0200,
        };
    }
}
