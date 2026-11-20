<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model quản lý Kế Hoạch Nội Dung Kênh theo Tháng -> Tuần -> Ngày (Agent AI 1).
 */
class HealthContentPlan extends Model
{
    protected $table = 'health_content_plans';

    protected $fillable = [
        'name',
        'channel_name',
        'month',
        'year',
        'posts_per_day',
        'total_days',
        'topics_json',
        'plan_summary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'year' => 'integer',
            'posts_per_day' => 'integer',
            'total_days' => 'integer',
            'topics_json' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(HealthPlanItem::class, 'plan_id')
            ->orderBy('week_number')
            ->orderBy('day_number')
            ->orderBy('slot_index');
    }
}
