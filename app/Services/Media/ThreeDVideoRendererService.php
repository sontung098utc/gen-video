<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\StoryboardScene;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dịch vụ kết xuất Video Sản Phẩm 3D (.glb) 60fps bằng Headless Chromium WebGL (`scripts/render_3d_video.mjs`) + FFmpeg.
 *
 * Mỗi phân cảnh được gán một quỹ đạo góc máy 3D (`camera-orbit` azimuth/elevation/radius) và ánh sáng studio riêng biệt,
 * tạo ra video quảng cáo 3D mới 100% (chính xác hình dáng, màu sắc, kích thước thực tế) và chống trùng lặp nội dung trên TikTok.
 */
class ThreeDVideoRendererService
{
    private readonly string $pythonBinary;

    private readonly string $scriptPath;

    private readonly string $ffmpegBinary;

    private readonly int $maxThreads;

    private readonly int $nicePriority;

    public function __construct()
    {
        $this->pythonBinary = (string) config('services.python.binary', 'python');
        $this->scriptPath = base_path('scripts/render_3d_turntable_video.py');
        $this->ffmpegBinary = (string) config('services.ffmpeg.ffmpeg_binary', '/usr/local/bin/ffmpeg');
        $this->maxThreads = max(1, min(6, (int) config('services.ffmpeg.max_threads', 6)));
        $this->nicePriority = (int) config('services.ffmpeg.nice_priority', 10);
    }

    /**
     * Kết xuất một phân cảnh video 3D 60fps từ file `.glb` đã chuẩn hóa kích thước thực tế.
     *
     * @return array{video_relative_path: string, video_full_path: string, image_relative_path: string|null}
     */
    public function renderSceneFromGlb(
        string $glbFullPath,
        VideoProject $project,
        StoryboardScene $scene,
        string $filePrefix,
        int $durationMs
    ): array {
        if (! is_file($glbFullPath)) {
            throw new RuntimeException("Không tìm thấy file 3D .glb tại: {$glbFullPath}");
        }

        $disk = Storage::disk('public');
        $videoRelativePath = "renders/{$filePrefix}_3d60fps.mp4";
        $videoFullPath = $disk->path($videoRelativePath);
        if (! is_dir(dirname($videoFullPath))) {
            mkdir(dirname($videoFullPath), 0775, true);
        }

        $presets = [
            // Cảnh 1: Hero Orbit Reveal từ góc trái sang phải, zoom nhẹ vào sản phẩm
            ['startAzimuth' => -38, 'endAzimuth' => 28, 'startElevation' => 76, 'endElevation' => 82, 'startRadius' => 98, 'endRadius' => 82, 'theme' => 'dark_gold', 'badge' => '3D SHOWCASE'],
            // Cảnh 2: Xoay ngang chi tiết cạnh & nhãn sản phẩm
            ['startAzimuth' => 30, 'endAzimuth' => 135, 'startElevation' => 84, 'endElevation' => 70, 'startRadius' => 84, 'endRadius' => 74, 'theme' => 'emerald_medical', 'badge' => 'CHI TIẾT 3D'],
            // Cảnh 3: Xoay 360 độ toàn cảnh
            ['startAzimuth' => 120, 'endAzimuth' => 360, 'startElevation' => 74, 'endElevation' => 78, 'startRadius' => 92, 'endRadius' => 86, 'theme' => 'royal_indigo', 'badge' => '360° REAL SCALE'],
            // Cảnh 4: Góc thấp ấn tượng & khóa CTA
            ['startAzimuth' => -25, 'endAzimuth' => 20, 'startElevation' => 88, 'endElevation' => 75, 'startRadius' => 104, 'endRadius' => 76, 'theme' => 'warm_amber', 'badge' => 'CHÍNH HÃNG'],
        ];

        $preset = $presets[max(0, ($scene->scene_order - 1)) % count($presets)];
        $productName = $project->productProfile?->product_name ?: $project->title;

        $configPayload = [
            'glbPath' => $glbFullPath,
            'aspectRatio' => $project->aspect_ratio === '16:9' ? '16:9' : '9:16',
            'durationMs' => max(2000, $durationMs),
            'ffmpegBinary' => $this->ffmpegBinary,
            'maxThreads' => $this->maxThreads,
            'nicePriority' => $this->nicePriority,
            'productName' => $productName,
            'badgeText' => $preset['badge'],
            'theme' => $preset['theme'],
            'startAzimuth' => $preset['startAzimuth'],
            'endAzimuth' => $preset['endAzimuth'],
            'startElevation' => $preset['startElevation'],
            'endElevation' => $preset['endElevation'],
            'startRadius' => $preset['startRadius'],
            'endRadius' => $preset['endRadius'],
        ];

        $tempConfig = storage_path("app/temp/{$filePrefix}_3d_cfg.json");
        if (! is_dir(dirname($tempConfig))) {
            mkdir(dirname($tempConfig), 0775, true);
        }
        file_put_contents($tempConfig, json_encode($configPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $proc = new Process(
                command: [
                    $this->pythonBinary,
                    $this->scriptPath,
                    '--config',
                    $tempConfig,
                    '--output',
                    $videoFullPath,
                ],
                env: [
                    'OMP_NUM_THREADS' => (string) $this->maxThreads,
                    'OPENBLAS_NUM_THREADS' => (string) $this->maxThreads,
                ]
            );
            $proc->setTimeout(240);
            $proc->run();

            if (! $proc->isSuccessful() || ! is_file($videoFullPath) || filesize($videoFullPath) === 0) {
                throw new RuntimeException(
                    'Kết xuất video 3D CPU thất bại: ' . trim($proc->getErrorOutput() ?: $proc->getOutput())
                );
            }
        } finally {
            @unlink($tempConfig);
        }

        // Trích xuất ảnh thumbnail cho Cổng 4
        $imageRelativePath = "renders/{$filePrefix}_3d_thumb.jpg";
        $imageFullPath = $disk->path($imageRelativePath);
        $thumbProc = new Process([
            $this->ffmpegBinary, '-y', '-threads', '2', '-ss', '0.5', '-i', $videoFullPath,
            '-frames:v', '1', '-q:v', '3', $imageFullPath,
        ]);
        $thumbProc->setTimeout(20);
        $thumbProc->run();

        return [
            'video_relative_path' => $videoRelativePath,
            'video_full_path' => $videoFullPath,
            'image_relative_path' => is_file($imageFullPath) ? $imageRelativePath : null,
        ];
    }
}
