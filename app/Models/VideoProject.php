<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CostTierMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\WorkflowMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Model quản lý Dự án Tạo Video Ngắn Tự Động (`video_projects`).
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property ProjectType $project_type
 * @property string|null $topic_or_brief
 * @property string $aspect_ratio
 * @property ProjectStatus $status
 * @property WorkflowMode $workflow_mode
 * @property CostTierMode $cost_tier_mode
 * @property string $voice_id
 * @property string $voice_name
 * @property string|null $master_style_prompt
 * @property string|null $bgm_file_path
 * @property string|null $final_video_path
 * @property int $total_duration_ms
 * @property float $total_cost_usd
 * @property string|null $error_message
 */
class VideoProject extends Model
{
    use HasFactory;

    protected $table = 'video_projects';

    protected $fillable = [
        'uuid',
        'title',
        'project_type',
        'topic_or_brief',
        'aspect_ratio',
        'status',
        'workflow_mode',
        'cost_tier_mode',
        'voice_id',
        'voice_name',
        'master_style_prompt',
        'bgm_file_path',
        'final_video_path',
        'total_duration_ms',
        'total_cost_usd',
        'error_message',
    ];

    /**
     * Khởi tạo tự động UUID khi tạo mới dự án.
     */
    protected static function booted(): void
    {
        static::creating(function (self $project): void {
            if (empty($project->uuid)) {
                $project->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Cấu hình ép kiểu thuộc tính theo chuẩn Laravel 11 & PHP 8.2 Enums.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'workflow_mode' => WorkflowMode::class,
            'cost_tier_mode' => CostTierMode::class,
            'total_duration_ms' => 'integer',
            'total_cost_usd' => 'float',
        ];
    }

    /**
     * Quan hệ 1-1: Hồ sơ sản phẩm 3D (dành cho dự án loại Product).
     */
    public function productProfile(): HasOne
    {
        return $this->hasOne(ProductProfile::class, 'video_project_id');
    }

    /**
     * Quan hệ 1-N: Danh sách các phân cảnh trong kịch bản (Storyboard Scenes), sắp xếp theo thứ tự.
     */
    public function scenes(): HasMany
    {
        return $this->hasMany(StoryboardScene::class, 'video_project_id')->orderBy('scene_order');
    }

    /**
     * Quan hệ 1-N: Lịch sử gọi API và thống kê chi phí thực tế của dự án.
     */
    public function generationLogs(): HasMany
    {
        return $this->hasMany(GenerationLog::class, 'video_project_id')->latest();
    }

    /**
     * Chuyển dự án sang trạng thái kế tiếp dựa trên `workflow_mode` (Step-by-Step hoặc Full-Auto).
     */
    public function advanceState(): ?ProjectStatus
    {
        $next = $this->status->nextStatus($this->workflow_mode, $this->project_type);

        if ($next !== null) {
            $this->update([
                'status' => $next,
                'error_message' => null,
            ]);
        }

        return $next;
    }

    /**
     * Lấy mã giọng đọc chuẩn (đồng bộ giữa `voice_name` và `voice_id` để khóa duy nhất 1 giọng xuyên suốt dự án).
     */
    public function getVoiceNameAttribute(): string
    {
        return (string) ($this->attributes['voice_id'] ?? 'vi-VN-NamMinhNeural');
    }

    /**
     * Gán mã giọng đọc chuẩn vào cột `voice_id` khi truyền `voice_name`.
     */
    public function setVoiceNameAttribute(string $value): void
    {
        $this->attributes['voice_id'] = $value;
    }

    /**
     * Cộng dồn chi phí USD vào tổng chi phí thực tế của dự án.
     */
    public function addCostUsd(float $costUsd): void
    {
        if ($costUsd <= 0.0) {
            return;
        }

        $this->increment('total_cost_usd', $costUsd);
    }
}
