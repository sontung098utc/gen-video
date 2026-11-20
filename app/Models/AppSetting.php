<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Model lưu cấu hình hệ thống & API Keys (Google TTS, Vbee, GenMax, Pexels, Pixabay, Gemini, Kênh...).
 */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $fillable = [
        'key',
        'value',
        'group',
        'label',
        'is_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_secret' => 'boolean',
        ];
    }

    /**
     * Danh sách giá trị mặc định cho kênh Sống Khỏe Mỗi Ngày và các cổng API.
     *
     * @return array<string, array{group: string, label: string, default: string, is_secret: bool}>
     */
    public static function definitions(): array
    {
        return [
            'channel_name' => [
                'group' => 'channel',
                'label' => 'Tên kênh thương hiệu',
                'default' => 'Sống Khỏe Mỗi Ngày',
                'is_secret' => false,
            ],
            'channel_tiktok_url' => [
                'group' => 'channel',
                'label' => 'Link kênh TikTok',
                'default' => 'https://www.tiktok.com/@songkhoemoingay_85',
                'is_secret' => false,
            ],
            'channel_fanpage_url' => [
                'group' => 'channel',
                'label' => 'Link Fanpage Facebook Reels',
                'default' => 'https://www.facebook.com/profile.php?id=61560244119608',
                'is_secret' => false,
            ],
            'channel_youtube_url' => [
                'group' => 'channel',
                'label' => 'Link kênh YouTube Shorts',
                'default' => 'https://www.youtube.com/channel/UCRjdRnDY-BhaLVkVw_prc9g/',
                'is_secret' => false,
            ],
            'gemini_api_key' => [
                'group' => 'ai',
                'label' => 'Google Gemini API Key (Agent 1, 2, 3, 8)',
                'default' => (string) config('services.gemini.api_key', ''),
                'is_secret' => true,
            ],
            'pexels_api_key' => [
                'group' => 'stock',
                'label' => 'Pexels API Key (Agent 4 — Video Châu Á)',
                'default' => (string) config('services.pexels.api_key', ''),
                'is_secret' => true,
            ],
            'pixabay_api_key' => [
                'group' => 'stock',
                'label' => 'Pixabay API Key (Agent 4 — Video dự phòng)',
                'default' => (string) config('services.pixabay.api_key', ''),
                'is_secret' => true,
            ],
            'google_tts_api_key' => [
                'group' => 'voice',
                'label' => 'Google Cloud Text-to-Speech API Key (Agent 5)',
                'default' => (string) env('GOOGLE_TTS_API_KEY', ''),
                'is_secret' => true,
            ],
            'vbee_app_id' => [
                'group' => 'voice',
                'label' => 'Vbee (Vivibe) App ID (Agent 5)',
                'default' => (string) env('VBEE_APP_ID', ''),
                'is_secret' => false,
            ],
            'vbee_api_token' => [
                'group' => 'voice',
                'label' => 'Vbee (Vivibe) Bearer API Token (Agent 5)',
                'default' => (string) env('VBEE_API_TOKEN', ''),
                'is_secret' => true,
            ],
            'vbee_endpoint' => [
                'group' => 'voice',
                'label' => 'Vbee TTS Endpoint URL',
                'default' => (string) env('VBEE_ENDPOINT', 'https://vbee.vn/api/v1/tts'),
                'is_secret' => false,
            ],
            'genmax_api_key' => [
                'group' => 'voice',
                'label' => 'GenMax API Key (genmax.io — Agent 5)',
                'default' => (string) env('GENMAX_API_KEY', ''),
                'is_secret' => true,
            ],
            'genmax_endpoint' => [
                'group' => 'voice',
                'label' => 'GenMax TTS Endpoint URL (Custom Endpoint)',
                'default' => (string) env('GENMAX_ENDPOINT', 'https://genmax.io/api/v1/tts'),
                'is_secret' => false,
            ],
            'fal_api_key' => [
                'group' => 'thumbnail',
                'label' => 'Fal.ai API Key (Agent 6 — Nền Photographic)',
                'default' => (string) config('services.fal.api_key', ''),
                'is_secret' => true,
            ],
        ];
    }

    /**
     * Lấy giá trị cấu hình theo key (tự động giải mã nếu là secret và fallback sang mặc định/.env).
     */
    public static function getValue(string $key, string $fallback = ''): string
    {
        $defs = self::definitions();
        $default = $fallback !== '' ? $fallback : ($defs[$key]['default'] ?? '');

        try {
            $record = static::query()->where('key', $key)->first();
            if ($record === null || $record->value === null || trim($record->value) === '') {
                return $default;
            }

            if ($record->is_secret) {
                try {
                    return trim((string) Crypt::decryptString($record->value));
                } catch (Throwable) {
                    return trim((string) $record->value);
                }
            }

            return trim((string) $record->value);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Lưu cấu hình (tự mã hóa nếu là secret).
     */
    public static function setValue(string $key, ?string $value): void
    {
        $defs = self::definitions();
        $meta = $defs[$key] ?? [
            'group' => 'general',
            'label' => $key,
            'is_secret' => false,
        ];

        $clean = trim((string) $value);
        $stored = ($meta['is_secret'] && $clean !== '')
            ? Crypt::encryptString($clean)
            : $clean;

        static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $stored,
                'group' => $meta['group'],
                'label' => $meta['label'],
                'is_secret' => $meta['is_secret'],
            ]
        );
    }
}
