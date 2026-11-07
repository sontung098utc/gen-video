<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model quản lý từng Phân cảnh trong kịch bản (`storyboard_scenes`).
 *
 * @property int $id
 * @property int $video_project_id
 * @property int $scene_order
 * @property string $narration_text
 * @property string $visual_prompt
 * @property string|null $sfx_prompt
 * @property string $camera_motion
 * @property string $transition_type
 * @property int $transition_duration_ms
 * @property int $duration_ms
 * @property string|null $voice_file_path
 * @property string|null $subtitle_file_path
 * @property string|null $image_file_path
 * @property string|null $video_file_path
 * @property string|null $sfx_file_path
 */
class StoryboardScene extends Model
{
    use HasFactory;

    protected $table = 'storyboard_scenes';

    protected $fillable = [
        'video_project_id',
        'scene_order',
        'narration_text',
        'visual_prompt',
        'sfx_prompt',
        'camera_motion',
        'transition_type',
        'transition_duration_ms',
        'duration_ms',
        'voice_file_path',
        'subtitle_file_path',
        'image_file_path',
        'video_file_path',
        'sfx_file_path',
    ];

    /**
     * Cấu hình ép kiểu thuộc tính theo chuẩn Laravel 11.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scene_order' => 'integer',
            'transition_duration_ms' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * Quan hệ N-1: Dự án video chứa phân cảnh này.
     */
    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    /**
     * Quan hệ 1-N: Lịch sử gọi API của riêng phân cảnh này.
     */
    public function generationLogs(): HasMany
    {
        return $this->hasMany(GenerationLog::class, 'storyboard_scene_id');
    }

    /**
     * Trả về thời lượng của phân cảnh tính bằng giây (phục vụ tính toán FFmpeg xfade offset).
     */
    public function durationInSeconds(): float
    {
        return round($this->duration_ms / 1000.0, 3);
    }
}
