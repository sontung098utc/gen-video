<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\ProjectType;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dịch vụ kết xuất Motion Graphics $0.00 lấy cảm hứng từ Hypit (HTML5 + Chromium Headless + FFmpeg 60fps).
 *
 * Bọc script `scripts/render_motion_graphics.mjs` và FFmpeg (`nice -n 10` trên Linux, `-threads 6`)
 * để tạo ra các phân cảnh video 60fps hoặc ảnh overlay PNG sắc nét cho 4 template:
 *  - `comment_sticker`: Khung bình luận TikTok Q&A
 *  - `ranking_board`: Bảng xếp hạng Tier List (S/A/B/C/D)
 *  - `product_spec`: Thẻ thông số sản phẩm 3D kèm kích thước (mm) và USP
 *  - `box_caption`: Thẻ tiêu đề / điểm nhấn hiện đại
 */
class HtmlMotionGraphicsService
{
    public const SUPPORTED_TEMPLATES = [
        'comment_sticker',
        'ranking_board',
        'product_spec',
        'box_caption',
    ];

    private readonly string $nodeBinary;

    private readonly string $scriptPath;

    private readonly string $ffmpegBinary;

    private readonly int $maxThreads;

    private readonly int $nicePriority;

    public function __construct()
    {
        $this->nodeBinary = (string) config('services.node.binary', 'node');
        $this->scriptPath = base_path('scripts/render_motion_graphics.mjs');
        $this->ffmpegBinary = (string) config('services.ffmpeg.ffmpeg_binary', '/usr/local/bin/ffmpeg');
        $this->maxThreads = max(1, min(8, (int) config('services.ffmpeg.max_threads', 6)));
        $this->nicePriority = (int) config('services.ffmpeg.nice_priority', 10);
    }

    /**
     * Kết xuất một phân cảnh Motion Graphics thành video `.mp4` 60fps ($0.00).
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     video_full_path: string,
     *     image_full_path: string|null,
     *     template: string,
     *     cost_usd: float
     * }
     */
    public function renderSceneClip(
        string $template,
        array $data,
        int $durationMs,
        string $outputPath,
        string $aspectRatio = '9:16',
        string $cameraMotion = 'zoom_in',
        ?string $previewPngOutputPath = null
    ): array {
        $resolvedTemplate = in_array($template, self::SUPPORTED_TEMPLATES, true)
            ? $template
            : 'box_caption';

        $this->ensureParentDirectory($outputPath);

        $configPayload = [
            'template' => $resolvedTemplate,
            'aspectRatio' => $aspectRatio === '16:9' ? '16:9' : '9:16',
            'durationMs' => max(1000, $durationMs),
            'fps' => 60,
            'ffmpegBinary' => $this->ffmpegBinary,
            'maxThreads' => $this->maxThreads,
            'nicePriority' => $this->nicePriority,
            'cameraMotion' => $cameraMotion,
            'data' => $data,
        ];

        $this->runRendererScript($configPayload, $outputPath, 240);

        $imageFullPath = null;
        if ($previewPngOutputPath !== null && $previewPngOutputPath !== '') {
            $this->ensureParentDirectory($previewPngOutputPath);
            $this->runRendererScript($configPayload, $previewPngOutputPath, 60);
            if (is_file($previewPngOutputPath) && filesize($previewPngOutputPath) > 0) {
                $imageFullPath = $previewPngOutputPath;
            }
        }

        return [
            'video_full_path' => $outputPath,
            'image_full_path' => $imageFullPath,
            'template' => $resolvedTemplate,
            'cost_usd' => 0.0,
        ];
    }

    /**
     * Kết xuất thẻ Motion Graphics tĩnh dưới dạng ảnh `.png` để ghép phủ (overlay) lên video.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderOverlayPng(
        string $template,
        array $data,
        string $outputPngPath,
        string $aspectRatio = '9:16'
    ): string {
        $resolvedTemplate = in_array($template, self::SUPPORTED_TEMPLATES, true)
            ? $template
            : 'box_caption';

        $this->ensureParentDirectory($outputPngPath);

        $configPayload = [
            'template' => $resolvedTemplate,
            'aspectRatio' => $aspectRatio === '16:9' ? '16:9' : '9:16',
            'data' => $data,
        ];

        $this->runRendererScript($configPayload, $outputPngPath, 60);

        return $outputPngPath;
    }

    /**
     * Ghép phủ một ảnh overlay PNG lên video gốc bằng FFmpeg (`-threads 6`, `nice -n 10` trên Linux).
     */
    public function overlayMotionGraphicsOnVideo(
        string $baseVideoPath,
        string $overlayPngPath,
        string $outputPath,
        float $startSec = 0.0,
        float $endSec = 0.0
    ): bool {
        if (! is_file($baseVideoPath)) {
            throw new RuntimeException("Không tìm thấy video nền để ghép Motion Graphics: {$baseVideoPath}");
        }
        if (! is_file($overlayPngPath)) {
            throw new RuntimeException("Không tìm thấy ảnh overlay PNG: {$overlayPngPath}");
        }

        $this->ensureParentDirectory($outputPath);

        $filter = $endSec > $startSec
            ? sprintf("[0:v][1:v]overlay=0:0:enable='between(t,%.3f,%.3f)'[v]", $startSec, $endSec)
            : '[0:v][1:v]overlay=0:0[v]';

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
            '-i',
            $baseVideoPath,
            '-i',
            $overlayPngPath,
            '-filter_complex',
            $filter,
            '-map',
            '[v]',
            '-map',
            '0:a?',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '20',
            '-x264-params',
            "threads={$this->maxThreads}",
            '-c:a',
            'copy',
            '-pix_fmt',
            'yuv420p',
            $outputPath,
        ];

        $this->runFfmpegProcess($args, 300);

        return is_file($outputPath) && filesize($outputPath) > 0;
    }

    /**
     * Kiểm tra xem dự án hoặc phân cảnh có chủ động chọn chế độ Motion Graphics ($0.00) hay không.
     */
    public function shouldUseMotionGraphics(VideoProject $project, StoryboardScene $scene): bool
    {
        $style = strtolower((string) $project->master_style_prompt);
        $visual = strtolower((string) $scene->visual_prompt);
        $motion = strtolower(trim((string) $scene->camera_motion));

        return str_contains($style, '[motion_graphics]')
            || str_contains($style, 'motion_graphics_only')
            || str_contains($visual, '[motion_graphics]')
            || str_contains($visual, '[template:')
            || str_starts_with($motion, 'motion_gfx:');
    }

    /**
     * Trích xuất tên template ưu tiên nếu có khai báo trong `camera_motion` hoặc `visual_prompt`.
     */
    public function extractPreferredTemplate(VideoProject $project, StoryboardScene $scene): ?string
    {
        $motion = strtolower(trim((string) $scene->camera_motion));
        if (str_starts_with($motion, 'motion_gfx:')) {
            $candidate = trim(substr($motion, strlen('motion_gfx:')));
            if (in_array($candidate, self::SUPPORTED_TEMPLATES, true)) {
                return $candidate;
            }
        }

        $combined = strtolower((string) $scene->visual_prompt . ' ' . (string) $project->master_style_prompt);
        if (preg_match('/\[template:([a-z_]+)\]/', $combined, $matches) === 1) {
            if (in_array($matches[1], self::SUPPORTED_TEMPLATES, true)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Tự động ánh xạ dữ liệu của dự án & phân cảnh sang cấu hình template Motion Graphics phù hợp nhất.
     *
     * @return array{template: string, data: array<string, mixed>}
     */
    public function buildTemplatePayloadFromScene(
        VideoProject $project,
        StoryboardScene $scene,
        ?string $preferredTemplate = null
    ): array {
        $template = $preferredTemplate ?? $this->extractPreferredTemplate($project, $scene);
        $narration = trim((string) $scene->narration_text);
        $visual = trim((string) preg_replace('/\[[^\]]+\]/', '', (string) $scene->visual_prompt));
        $lowerContext = mb_strtolower($narration . ' ' . $visual);
        $sceneLabel = sprintf('CẢNH %02d', max(1, (int) $scene->scene_order));

        // Tự động suy luận template nếu chưa được chỉ định rõ
        if ($template === null) {
            if ($project->project_type === ProjectType::PRODUCT && $project->productProfile !== null && $scene->scene_order >= 2) {
                $template = 'product_spec';
            } elseif (preg_match('/(top\s*\d|xếp hạng|tier|bảng xếp hạng|ranking|hạng\s*[sabc])/u', $lowerContext) === 1) {
                $template = 'ranking_board';
            } elseif ($scene->scene_order === 1 || preg_match('/(bình luận|comment|câu hỏi|hỏi|thắc mắc|\?)/u', $lowerContext) === 1) {
                $template = 'comment_sticker';
            } else {
                $template = 'box_caption';
            }
        }

        if ($template === 'product_spec') {
            $profile = $project->productProfile;
            $disk = Storage::disk('public');
            $imagePath = null;
            if ($profile !== null && ! empty($profile->nobg_image_path) && $disk->exists($profile->nobg_image_path)) {
                $imagePath = $disk->path($profile->nobg_image_path);
            } elseif (! empty($scene->image_file_path) && $disk->exists($scene->image_file_path)) {
                $imagePath = $disk->path($scene->image_file_path);
            }

            $usps = (array) data_get($profile?->customer_analysis_json, 'usps', []);
            if (empty($usps)) {
                $usps = ['Chuẩn tỷ lệ 3D', 'Thiết kế sắc nét', 'Tối ưu trải nghiệm'];
            }

            return [
                'template' => 'product_spec',
                'data' => [
                    'sceneLabel' => $sceneLabel,
                    'productName' => $profile?->product_name ?: $project->title,
                    'sku' => $profile?->sku_code ?: sprintf('PRJ-%d', $project->id),
                    'widthMm' => $profile?->width_mm ?? 0,
                    'heightMm' => $profile?->height_mm ?? 0,
                    'depthMm' => $profile?->depth_mm ?? 0,
                    'description' => $narration !== '' ? $narration : ($profile?->product_description ?: $visual),
                    'usps' => array_values(array_slice(array_map('strval', $usps), 0, 4)),
                    'imagePath' => $imagePath,
                ],
            ];
        }

        if ($template === 'ranking_board') {
            return [
                'template' => 'ranking_board',
                'data' => [
                    'sceneLabel' => $sceneLabel,
                    'title' => $project->title !== '' ? $project->title : 'BẢNG XẾP HẠNG NỔI BẬT',
                    'subtitle' => $narration !== '' ? Str::limit($narration, 110) : 'Tổng hợp tiêu chí nổi bật',
                ],
            ];
        }

        if ($template === 'comment_sticker') {
            return [
                'template' => 'comment_sticker',
                'data' => [
                    'sceneLabel' => $sceneLabel,
                    'author' => 'Khách hàng quan tâm',
                    'handle' => '@review_thucte',
                    'replyLabel' => 'Trả lời bình luận',
                    'text' => $narration !== '' ? Str::limit($narration, 140) : $project->title,
                    'subtitle' => $visual !== '' ? Str::limit($visual, 120) : '',
                ],
            ];
        }

        return [
            'template' => 'box_caption',
            'data' => [
                'sceneLabel' => $sceneLabel,
                'title' => $project->title !== '' ? Str::limit($project->title, 70) : 'Điểm Nhấn Nội Dung',
                'subtitle' => $narration !== '' ? $narration : $visual,
                'tags' => ['EcoMotion', '60FPS', 'HD'],
            ],
        ];
    }

    /**
     * Thực thi `scripts/render_motion_graphics.mjs` với file cấu hình JSON tạm thời.
     *
     * @param  array<string, mixed>  $configPayload
     */
    private function runRendererScript(array $configPayload, string $outputPath, int $timeoutSeconds): void
    {
        if (! is_file($this->scriptPath)) {
            throw new RuntimeException("Không tìm thấy script Motion Graphics: {$this->scriptPath}");
        }

        $tempDir = storage_path('app/temp');
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw new RuntimeException("Không thể tạo thư mục tạm: {$tempDir}");
        }

        $configPath = $tempDir . DIRECTORY_SEPARATOR . 'motion_gfx_' . Str::random(12) . '.json';
        $jsonContent = json_encode($configPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($jsonContent === false) {
            throw new RuntimeException('Không thể mã hóa JSON cấu hình Motion Graphics.');
        }

        file_put_contents($configPath, $jsonContent);

        try {
            @set_time_limit(0);
            $process = new Process([
                $this->nodeBinary,
                $this->scriptPath,
                '--config',
                $configPath,
                '--output',
                $outputPath,
            ]);
            $process->setTimeout($timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    sprintf(
                        'Lỗi kết xuất HTML Motion Graphics (Exit Code %d): %s',
                        $process->getExitCode() ?? -1,
                        trim($process->getErrorOutput() ?: $process->getOutput())
                    )
                );
            }

            if (! is_file($outputPath) || filesize($outputPath) === 0) {
                throw new RuntimeException("File đầu ra Motion Graphics không tồn tại hoặc rỗng: {$outputPath}");
            }
        } finally {
            if (is_file($configPath)) {
                @unlink($configPath);
            }
        }
    }

    /**
     * Thực thi lệnh FFmpeg tuân thủ `nice -n 10` (trên Linux) và `-threads 6`.
     *
     * @param  array<int, string>  $ffmpegArguments
     */
    private function runFfmpegProcess(array $ffmpegArguments, int $timeoutSeconds = 300): void
    {
        @set_time_limit(0);
        if (PHP_OS_FAMILY === 'Linux') {
            $command = array_merge(
                ['nice', '-n', (string) $this->nicePriority, $this->ffmpegBinary],
                $ffmpegArguments
            );
        } else {
            $command = array_merge([$this->ffmpegBinary], $ffmpegArguments);
        }

        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                sprintf(
                    'Lệnh FFmpeg trong HtmlMotionGraphicsService thất bại (Exit Code %d): %s',
                    $process->getExitCode() ?? -1,
                    trim($process->getErrorOutput() ?: $process->getOutput())
                )
            );
        }
    }

    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không thể tạo thư mục lưu trữ: {$dir}");
        }
    }
}
