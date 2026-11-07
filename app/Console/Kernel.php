<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Commands\CleanupTempMedia;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

/**
 * Console Kernel đăng ký lệnh Artisan và lập lịch Cronjob tự động dọn rác lúc 02:00 sáng hàng ngày.
 */
class Kernel extends ConsoleKernel
{
    /**
     * Danh sách các Artisan Commands của ứng dụng.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        CleanupTempMedia::class,
    ];

    /**
     * Định nghĩa lịch chạy tự động (Task Schedule).
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('media:cleanup-temp')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/cleanup_temp_media.log'));
    }

    /**
     * Đăng ký các lệnh Console cho ứng dụng.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        if (is_file(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }
}
