<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model lưu trữ lịch sử gọi API AI và thống kê chi phí thực tế (`generation_logs`).
 *
 * @property int $id
 * @property int|null $video_project_id
 * @property int|null $storyboard_scene_id
 * @property string $provider
 * @property string $model_name
 * @property string $action_type
 * @property string $status
 * @property float $cost_usd
 * @property int $latency_ms
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $response_payload
 * @property string|null $error_message
 */
class GenerationLog extends Model
{
    use HasFactory;

    protected $table = 'generation_logs';

    protected $fillable = [
        'video_project_id',
        'storyboard_scene_id',
        'provider',
        'model_name',
        'action_type',
        'status',
        'cost_usd',
        'latency_ms',
        'request_payload',
        'response_payload',
        'error_message',
    ];

    /**
     * Cấu hình ép kiểu thuộc tính theo chuẩn Laravel 11.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
            'latency_ms' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    /**
     * Quan hệ N-1: Dự án video liên quan.
     */
    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    /**
     * Quan hệ N-1: Phân cảnh liên quan (nếu có).
     */
    public function storyboardScene(): BelongsTo
    {
        return $this->belongsTo(StoryboardScene::class, 'storyboard_scene_id');
    }
}
