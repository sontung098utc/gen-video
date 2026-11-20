<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model quản lý Kho Video Stock Nội Bộ (ưu tiên bối cảnh người Việt Nam / Trung Quốc cho Agent AI 4).
 */
class StockMediaAsset extends Model
{
    protected $table = 'stock_media_assets';

    protected $fillable = [
        'title',
        'tags',
        'category',
        'orientation',
        'file_path',
        'duration_ms',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
