<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model lưu Giọng Đọc Tùy Chỉnh theo Voice ID của từng nhà cung cấp (GenMax, Vivibe/Vbee, Google TTS, Edge TTS).
 */
class CustomVoice extends Model
{
    protected $table = 'custom_voices';

    protected $fillable = [
        'name',
        'voice_id',
        'provider',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            'genmax' => 'GenMax - CapCut',
            'vbee' => 'Vivibe / Vbee',
            'google_tts' => 'Google Cloud TTS',
            'edge_tts' => 'Microsoft Edge Neural',
            default => strtoupper($this->provider),
        };
    }
}
