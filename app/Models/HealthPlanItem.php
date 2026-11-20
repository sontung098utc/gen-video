<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model quản lý từng Bài viết / Video theo Ngày trong Kế hoạch (Agent AI 1 & Agent AI 2).
 */
class HealthPlanItem extends Model
{
    protected $table = 'health_plan_items';

    protected $fillable = [
        'plan_id',
        'week_number',
        'day_number',
        'scheduled_date',
        'slot_index',
        'topic',
        'angle',
        'brief',
        'seo_title_tiktok',
        'seo_title_reels',
        'seo_title_shorts',
        'cover_headline',
        'seo_description',
        'hashtags_json',
        'video_project_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
            'day_number' => 'integer',
            'slot_index' => 'integer',
            'scheduled_date' => 'date',
            'hashtags_json' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(HealthContentPlan::class, 'plan_id');
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function angleLabel(): string
    {
        return match ($this->angle) {
            'dong_y' => 'Đông Y',
            'tay_y' => 'Tây Y',
            'khoa_hoc' => 'Khoa Học',
            default => 'Đông - Tây Y Kết Hợp',
        };
    }
}
