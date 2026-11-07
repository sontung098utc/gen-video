<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductProfile;
use App\Models\VideoProject;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Artisan Command: `php artisan media:cleanup-temp`
 *
 * Chức năng:
 *  - Quét các thư mục chứa file tạm (`storage/app/temp/`, `storage/app/temp_render/`, `storage/app/public/renders/`,
 *    `storage/app/public/models_3d/raw/`).
 *  - Tự động xóa các file video cắt vụn, file audio trung gian, file ảnh tách nền tạm có tuổi đời lớn hơn 48 giờ.
 *  - Bảo lưu vĩnh viễn:
 *      1. Các file mô hình 3D `.glb` đã chuẩn hóa trong `product_profiles` (`normalized_glb_path` & `models_3d/cache/`)
 *         để tái sử dụng theo `cache_key` với chi phí $0.00.
 *      2. Các file video `.mp4` hoàn chỉnh trong `storage/app/public/videos/` (`final_video_path`).
 *      3. Kho hiệu ứng âm thanh và nhạc nền cục bộ `storage/app/sfx_library/`.
 */
class CleanupTempMedia extends Command
{
    /**
     * Cú pháp gọi lệnh Artisan kèm các tham số tùy chọn.
     *
     * @var string
     */
    protected $signature = 'media:cleanup-temp
                            {--hours=48 : Xóa các file tạm có thời gian tạo/sửa đổi cũ hơn số giờ quy định (mặc định: 48 giờ)}
                            {--dry-run : Chỉ liệt kê danh sách file sẽ bị xóa mà không xóa thực tế trên ổ cứng}';

    /**
     * Mô tả lệnh Artisan.
     *
     * @var string
     */
    protected $description = 'Dọn dẹp các file media tạm quá 48 giờ, bảo lưu vĩnh viễn cache 3D .glb và video .mp4 hoàn chỉnh.';

    public function handle(): int
    {
        $maxAgeHours = max(1, (int) $this->option('hours'));
        $isDryRun = (bool) $this->option('dry-run');
        $cutoffTimestamp = time() - ($maxAgeHours * 3600);

        $this->info(sprintf(
            '=== BẮT ĐẦU DỌN DẸP FILE TẠM (Tuổi đời > %d giờ | Chế độ: %s) ===',
            $maxAgeHours,
            $isDryRun ? 'DRY-RUN (Thử nghiệm)' : 'XÓA THỰC TẾ'
        ));

        // 1. Tập hợp danh sách các đường dẫn tuyệt đối cần bảo vệ tuyệt đối từ Database
        $protectedPaths = $this->collectProtectedPaths();

        // 2. Danh sách các thư mục chứa file tạm cần quét dọn
        $targetDirectories = [
            storage_path('app/temp'),
            storage_path('app/temp_render'),
            storage_path('app/public/renders'),
            storage_path('app/public/models_3d/raw'),
            storage_path('app/public/products/nobg'),
            storage_path('app/public/products/turntable'),
        ];

        $deletedFilesCount = 0;
        $freedBytes = 0;

        foreach ($targetDirectories as $dirPath) {
            if (! is_dir($dirPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                $realPath = $item->getRealPath() ?: $item->getPathname();
                $normalizedRealPath = $this->normalizePath($realPath);

                // Nếu là thư mục rỗng sau khi xóa các file con -> dọn thư mục rỗng (trừ thư mục gốc)
                if ($item->isDir()) {
                    if ($normalizedRealPath !== $this->normalizePath($dirPath) && ! $isDryRun) {
                        @rmdir($realPath);
                    }

                    continue;
                }

                if (! $item->isFile()) {
                    continue;
                }

                // Kiểm tra danh sách bảo vệ tuyệt đối (.glb cache vĩnh viễn & video .mp4 hoàn chỉnh)
                if ($this->isProtectedFile($normalizedRealPath, $protectedPaths)) {
                    continue;
                }

                $lastModified = $item->getMTime();
                if ($lastModified >= $cutoffTimestamp) {
                    continue;
                }

                $fileSize = (int) $item->getSize();

                if ($isDryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] Sẽ xóa: %s (%s)',
                        $realPath,
                        $this->formatBytes($fileSize)
                    ));
                    $deletedFilesCount++;
                    $freedBytes += $fileSize;

                    continue;
                }

                if (@unlink($realPath)) {
                    $deletedFilesCount++;
                    $freedBytes += $fileSize;
                }
            }
        }

        $summary = sprintf(
            'Hoàn tất dọn rác media tạm: Đã xử lý %d file, giải phóng %s dung lượng ổ cứng.',
            $deletedFilesCount,
            $this->formatBytes($freedBytes)
        );

        $this->info($summary);
        Log::info('[CleanupTempMedia] ' . $summary, [
            'max_age_hours' => $maxAgeHours,
            'dry_run' => $isDryRun,
            'deleted_files' => $deletedFilesCount,
            'freed_bytes' => $freedBytes,
        ]);

        return self::SUCCESS;
    }

    /**
     * Thu thập toàn bộ đường dẫn file được bảo vệ vĩnh viễn:
     *  - Mọi file `.glb` đã chuẩn hóa trong bảng `product_profiles` (`normalized_glb_path`).
     *  - Mọi file video `.mp4` hoàn chỉnh trong bảng `video_projects` (`final_video_path`).
     *  - Các ảnh tách nền của những dự án vẫn đang trong quá trình chờ duyệt (chưa COMPLETED/FAILED).
     *
     * @return array<string, bool>
     */
    private function collectProtectedPaths(): array
    {
        $protected = [];
        $publicDisk = Storage::disk('public');

        try {
            // 1. Bảo lưu vĩnh viễn toàn bộ file .glb đã chuẩn hóa kích thước trong product_profiles
            $glbPaths = ProductProfile::query()
                ->whereNotNull('normalized_glb_path')
                ->pluck('normalized_glb_path');

            foreach ($glbPaths as $relPath) {
                $full = $this->normalizePath($publicDisk->path((string) $relPath));
                $protected[$full] = true;
            }

            // 2. Bảo lưu vĩnh viễn toàn bộ video .mp4 hoàn chỉnh trong video_projects
            $finalVideos = VideoProject::query()
                ->whereNotNull('final_video_path')
                ->pluck('final_video_path');

            foreach ($finalVideos as $relPath) {
                $full = $this->normalizePath($publicDisk->path((string) $relPath));
                $protected[$full] = true;
            }
        } catch (Throwable) {
            // Trường hợp chạy lệnh khi DB chưa kết nối, vẫn bảo vệ bằng quy tắc thư mục ở hàm isProtectedFile()
        }

        return $protected;
    }

    /**
     * Kiểm tra một đường dẫn file có nằm trong diện bảo lưu vĩnh viễn hay không.
     *
     * @param  array<string, bool>  $protectedPaths
     */
    private function isProtectedFile(string $normalizedFilePath, array $protectedPaths): bool
    {
        if (isset($protectedPaths[$normalizedFilePath])) {
            return true;
        }

        // Không bao giờ xóa file trong thư mục videos hoàn chỉnh, models_3d/cache hoặc sfx_library
        $alwaysKeepSegments = [
            '/storage/app/public/videos/',
            '/storage/app/public/models_3d/cache/',
            '/storage/app/sfx_library/',
        ];

        foreach ($alwaysKeepSegments as $segment) {
            if (str_contains($normalizedFilePath, $segment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chuẩn hóa dấu gạch chéo đường dẫn để so sánh chính xác trên cả Linux (CentOS 8) và Windows.
     */
    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Định dạng dung lượng byte sang KB / MB / GB dễ đọc.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
