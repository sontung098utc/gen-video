<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model lưu phiếu kiểm duyệt & chấm điểm của Trưởng phòng Agent AI 8 (`agent_qa_reviews`).
 */
class AgentQaReview extends Model
{
    protected $table = 'agent_qa_reviews';

    protected $fillable = [
        'video_project_id',
        'attempt_number',
        'status',
        'overall_score',
        'checklist_json',
        'failed_agents_json',
        'feedback_notes',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'overall_score' => 'integer',
            'checklist_json' => 'array',
            'failed_agents_json' => 'array',
        ];
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }
}
