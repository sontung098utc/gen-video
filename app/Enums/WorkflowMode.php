<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Enum định nghĩa chế độ vận hành quy trình sản xuất video:
 * - STEP_BY_STEP: Mặc định dừng lại ở 4 Cổng kiểm duyệt (Checkpoints 1 -> 4) để người dùng duyệt/chỉnh sửa.
 * - AUTO: Tự động bỏ qua toàn bộ các cổng kiểm duyệt và chạy thông suốt tới khi xuất file MP4.
 */
enum WorkflowMode: string
{
    case STEP_BY_STEP = 'step_by_step';
    case AUTO = 'auto';

    /**
     * Nhãn hiển thị tiếng Việt trên giao diện điều khiển.
     */
    public function label(): string
    {
        return match ($this) {
            self::STEP_BY_STEP => 'Duyệt từng bước (4 Cổng kiểm duyệt)',
            self::AUTO => 'Tự động hoàn toàn (Full-Auto)',
        };
    }

    /**
     * Kiểm tra có phải chế độ tự động bỏ qua các cổng duyệt hay không.
     */
    public function shouldBypassCheckpoints(): bool
    {
        return $this === self::AUTO;
    }
}
