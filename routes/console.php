<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes & Laravel 11 Task Scheduler
|--------------------------------------------------------------------------
| Định kỳ chạy lệnh `media:cleanup-temp` vào lúc 02:00 sáng hàng ngày
| để tự động xóa các file tạm quá 48 giờ, bảo lưu vĩnh viễn cache 3D (.glb)
| và các video hoàn chỉnh (.mp4).
*/

Schedule::command('media:cleanup-temp')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/cleanup_temp_media.log'));
