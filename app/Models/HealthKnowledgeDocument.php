<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model lưu trữ Tài liệu Kiến thức Sức khỏe Chính thống (Đông y, Tây y, Khoa học, Y tế).
 */
class HealthKnowledgeDocument extends Model
{
    protected $table = 'health_knowledge_documents';

    protected $fillable = [
        'title',
        'category',
        'source_name',
        'source_url',
        'file_path',
        'extracted_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Nhãn tiếng Việt cho danh mục tài liệu.
     */
    public function categoryLabel(): string
    {
        return match ($this->category) {
            'dong_y' => 'Đông Y Chính Thống',
            'tay_y' => 'Tây Y / Y Học Hiện Đại',
            'dinh_duong' => 'Dinh Dưỡng & Thực Phẩm',
            'khoa_hoc' => 'Nghiên Cứu Khoa Học',
            default => 'Y Tế & Sức Khỏe Cộng Đồng',
        };
    }
}
