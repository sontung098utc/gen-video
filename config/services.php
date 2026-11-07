<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cấu Hình Các Dịch Vụ Bên Thứ Ba & AI Gateway (CentOS 8 Edition)
    |--------------------------------------------------------------------------
    */

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        'timeout' => (int) env('GEMINI_TIMEOUT_SECONDS', 90),
    ],

    'edge_tts' => [
        'binary' => env('EDGE_TTS_BINARY', '/usr/local/bin/edge-tts'),
        'ffprobe_binary' => env('FFPROBE_BINARY', '/usr/local/bin/ffprobe'),
        'default_voice' => env('EDGE_TTS_DEFAULT_VOICE', 'vi-VN-NamMinhNeural'),
        'female_voice' => env('EDGE_TTS_FEMALE_VOICE', 'vi-VN-HoaiMyNeural'),
        'default_rate' => env('EDGE_TTS_DEFAULT_RATE', '+5%'),
        'default_pitch' => env('EDGE_TTS_DEFAULT_PITCH', '+0Hz'),
    ],

    'fal_ai' => [
        'api_key' => env('FAL_KEY', ''),
        'base_url' => env('FAL_BASE_URL', 'https://fal.run'),
        'queue_url' => env('FAL_QUEUE_URL', 'https://queue.fal.run'),
        'models' => [
            'flux_schnell' => env('FAL_MODEL_FLUX_SCHNELL', 'fal-ai/flux/schnell'),
            'ltx_video' => env('FAL_MODEL_LTX_VIDEO', 'fal-ai/ltx-video/image-to-video'),
            'trellis_3d' => env('FAL_MODEL_TRELLIS_3D', 'fal-ai/trellis'),
        ],
        'pricing_usd' => [
            'fal-ai/flux/schnell' => 0.0030,
            'fal-ai/ltx-video/image-to-video' => 0.0200,
            'fal-ai/trellis' => 0.0200,
        ],
    ],

    'ffmpeg' => [
        'ffmpeg_binary' => env('FFMPEG_BINARY', '/usr/local/bin/ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', '/usr/local/bin/ffprobe'),
        'max_threads' => (int) env('FFMPEG_MAX_THREADS', 6),
        'nice_priority' => (int) env('FFMPEG_NICE_PRIORITY', 10),
    ],

    'python' => [
        'binary' => env('PYTHON3_BINARY', '/usr/bin/python3.11'),
        'omp_threads' => (int) env('OMP_NUM_THREADS', 6),
        'ort_threads' => (int) env('ORT_NUM_THREADS', 6),
    ],

];
