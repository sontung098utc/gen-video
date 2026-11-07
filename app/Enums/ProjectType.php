<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum phân loại kịch bản đầu vào của Dự án Video:
 * - KNOWLEDGE: Video chia sẻ kiến thức, mẹo vặt, kể chuyện theo chủ đề (chỉ dùng NLP Gemini 2.5 Flash).
 * - PRODUCT: Video quảng cáo/review sản phẩm thương mại (dùng Gemini Vision + rembg + Trellis 3D + trimesh).
 */
enum ProjectType: string
{
    case KNOWLEDGE = 'knowledge';
    case PRODUCT = 'product';

    /**
     * Nhãn hiển thị tiếng Việt.
     */
    public function label(): string
    {
        return match ($this) {
            self::KNOWLEDGE => 'Video Kiến Thức & Kể Chuyện (Knowledge Topic)',
            self::PRODUCT => 'Video Quảng Cáo Sản Phẩm 3D/AI (Product Showcase)',
        };
    }

    /**
     * Kiểm tra dự án có yêu cầu hồ sơ sản phẩm và xử lý khối 3D (.glb) hay không.
     */
    public function requiresProductProfile(): bool
    {
        return $this === self::PRODUCT;
    }
}
