<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CostTierMode;
use App\Enums\ProjectType;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\AI\FalLowCostService;
use App\Services\Media\FfmpegVideoCompositorService;
use App\Services\Media\HtmlMotionGraphicsService;
use App\Services\Media\MoneyPrinterStockVideoService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * BƯỚC 4 (Queue: `ai-orchestration`): Tạo hình ảnh Keyframe và Video chuyển động cho từng phân cảnh:
 *  - Nếu dự án ở `ECO_MODE`: Gọi `fal-ai/flux/schnell` ($0.003/ảnh) -> Gọi `FfmpegVideoCompositorService::generateKenBurnsClip()`
 *    tạo video chuyển động 60fps mượt mà khớp chính xác với thời lượng giọng đọc ($durationMs).
 *  - Nếu dự án ở `FULL_AI_VIDEO_MODE`: Sử dụng ảnh Turntable 3D (nếu có) hoặc ảnh keyframe từ `flux/schnell`,
 *    sau đó gọi `fal-ai/ltx-video/image-to-video` ($0.020/clip) để tạo video chuyển động vật lý thực thụ.
 */
class Step4_GenerateAiSceneVideosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    /**
     * @param  int  $videoProjectId  ID của dự án video
     * @param  int|null  $onlySceneId  Nếu khác null, chỉ tạo lại (re-generate) duy nhất 1 bối cảnh được chỉ định
     */
    public function __construct(
        public readonly int $videoProjectId,
        public readonly ?int $onlySceneId = null
    ) {
        $this->onQueue('ai-orchestration');
    }

    public function handle(
        FalLowCostService $falService,
        FfmpegVideoCompositorService $ffmpegCompositor,
        HtmlMotionGraphicsService $motionGraphicsService,
        MoneyPrinterStockVideoService $stockVideoService,
        WorkflowOrchestrator $orchestrator
    ): void {
        $project = VideoProject::query()->with(['scenes', 'productProfile'])->findOrFail($this->videoProjectId);

        try {
            $disk = Storage::disk('public');
            $masterStyle = trim((string) $project->master_style_prompt);

            $scenesQuery = $project->scenes();
            if ($this->onlySceneId !== null) {
                $scenesQuery->whereKey($this->onlySceneId);
            }
            $scenes = $scenesQuery->get();

            // Lấy danh sách ảnh snapshot 3D Turntable (0°, 90°, 180°, 270°) nếu là dự án PRODUCT
            $turntableSnapshots = [];
            if ($project->project_type === ProjectType::PRODUCT && $project->productProfile !== null) {
                $turntableSnapshots = (array) data_get(
                    $project->productProfile->customer_analysis_json,
                    'normalization_meta.turntable_snapshots',
                    []
                );
            }

            /** @var StoryboardScene $scene */
            foreach ($scenes as $index => $scene) {
                $fullPrompt = $masterStyle !== ''
                    ? "{$masterStyle}. {$scene->visual_prompt}"
                    : $scene->visual_prompt;

                $filePrefix = sprintf('project_%d_scene_%02d_%d', $project->id, $scene->scene_order, time());
                $durationMs = $scene->duration_ms > 0 ? $scene->duration_ms : 5000;

                // Nếu dự án bật chế độ MoneyPrinterTurbo (Video Stock Pexels/Pixabay miễn phí $0.00)
                if ($stockVideoService->shouldUseStockVideo($project)) {
                    try {
                        $stockClip = $stockVideoService->fetchAndPrepareSceneClip(
                            project: $project,
                            scene: $scene,
                            filePrefix: $filePrefix,
                            durationMs: $durationMs
                        );

                        $scene->update([
                            'image_file_path' => $stockClip['image_relative_path'],
                            'video_file_path' => $stockClip['video_relative_path'],
                        ]);
                        continue;
                    } catch (Throwable $stockError) {
                        Log::warning(sprintf(
                            '[Step4] MoneyPrinterStockVideo fallback sang Motion Graphics cho Project #%d Scene #%d: %s',
                            $project->id,
                            $scene->scene_order,
                            $stockError->getMessage()
                        ));

                        $this->renderMotionGraphicsScene(
                            motionGraphicsService: $motionGraphicsService,
                            project: $project,
                            scene: $scene,
                            filePrefix: $filePrefix,
                            durationMs: $durationMs,
                            disk: $disk
                        );
                        continue;
                    }
                }

                // Nếu dự án/phân cảnh chủ động chọn chế độ Motion Graphics ($0.00)
                if ($motionGraphicsService->shouldUseMotionGraphics($project, $scene)) {
                    $this->renderMotionGraphicsScene(
                        motionGraphicsService: $motionGraphicsService,
                        project: $project,
                        scene: $scene,
                        filePrefix: $filePrefix,
                        durationMs: $durationMs,
                        disk: $disk
                    );
                    continue;
                }

                if ($project->cost_tier_mode === CostTierMode::ECO_MODE) {
                    // ----------------------------------------------------------------
                    // CHẾ ĐỘ ECO MODE: Flux Schnell ($0.003) + FFmpeg 60fps Ken Burns
                    // Nếu Fal.ai lỗi -> tự động fallback sang HTML/Chromium Motion Graphics ($0.00)
                    // ----------------------------------------------------------------
                    try {
                        $keyframe = $falService->generateKeyframeWithFluxSchnell(
                            visualPrompt: $fullPrompt,
                            aspectRatio: $project->aspect_ratio,
                            outputFileName: $filePrefix,
                            videoProjectId: $project->id,
                            storyboardSceneId: $scene->id
                        );

                        $videoRelativePath = "renders/{$filePrefix}_eco60fps.mp4";
                        $videoFullPath = $disk->path($videoRelativePath);

                        $ffmpegCompositor->generateKenBurnsClip(
                            imagePath: $keyframe['image_full_path'],
                            durationMs: $durationMs,
                            outputPath: $videoFullPath,
                            aspectRatio: $project->aspect_ratio,
                            cameraMotion: $scene->camera_motion
                        );

                        $scene->update([
                            'image_file_path' => $keyframe['image_relative_path'],
                            'video_file_path' => $videoRelativePath,
                        ]);
                    } catch (Throwable $falException) {
                        // FalLowCostService đã ghi chi tiết lỗi vào storage/logs/fal_api.log;
                        // chuyển sang kết xuất Motion Graphics $0.00 để không làm gián đoạn pipeline Eco Mode.
                        Log::warning('Eco Mode Fal.ai fallback sang HtmlMotionGraphicsService ($0.00)', [
                            'project_id' => $project->id,
                            'scene_id' => $scene->id,
                            'error' => $falException->getMessage(),
                        ]);

                        $this->renderMotionGraphicsScene(
                            motionGraphicsService: $motionGraphicsService,
                            project: $project,
                            scene: $scene,
                            filePrefix: $filePrefix,
                            durationMs: $durationMs,
                            disk: $disk
                        );
                    }
                } else {
                    // ----------------------------------------------------------------
                    // CHẾ ĐỘ FULL AI VIDEO MODE: LTX-Video Image-to-Video ($0.020)
                    // ----------------------------------------------------------------
                    $sourceImageFullPath = null;
                    $imageRelativePath = $scene->image_file_path;

                    // Nếu dự án có sẵn ảnh chụp 3D Turntable và phân cảnh chưa có ảnh keyframe riêng
                    if (! empty($turntableSnapshots)) {
                        $turntableCandidate = (string) $turntableSnapshots[$index % count($turntableSnapshots)];
                        if (is_file($turntableCandidate)) {
                            $sourceImageFullPath = $turntableCandidate;
                        }
                    }

                    // Nếu chưa có ảnh đầu vào thì tạo ảnh keyframe bằng Flux Schnell trước khi đưa vào LTX-Video
                    if ($sourceImageFullPath === null) {
                        $keyframe = $falService->generateKeyframeWithFluxSchnell(
                            visualPrompt: $fullPrompt,
                            aspectRatio: $project->aspect_ratio,
                            outputFileName: $filePrefix,
                            videoProjectId: $project->id,
                            storyboardSceneId: $scene->id
                        );
                        $sourceImageFullPath = $keyframe['image_full_path'];
                        $imageRelativePath = $keyframe['image_relative_path'];
                    }

                    $ltxVideo = $falService->generateVideoWithLtx(
                        imageUrlOrPath: $sourceImageFullPath,
                        motionPrompt: $fullPrompt,
                        outputFileName: $filePrefix,
                        videoProjectId: $project->id,
                        storyboardSceneId: $scene->id
                    );

                    $scene->update([
                        'image_file_path' => $imageRelativePath,
                        'video_file_path' => $ltxVideo['video_relative_path'],
                    ]);
                }
            }

            // Chỉ chuyển trạng thái dự án nếu đang chạy toàn bộ dự án (không phải re-generate 1 cảnh lẻ)
            if ($this->onlySceneId === null) {
                $orchestrator->dispatchNextStep($project);
            }
        } catch (Throwable $e) {
            if ($this->onlySceneId === null) {
                $orchestrator->markAsFailed($project, 'Step 4 (Tạo Hình ảnh / Video AI) thất bại: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Kết xuất phân cảnh bằng HTML/Chromium Motion Graphics ($0.00) 60fps
     * và lưu ảnh xem trước + video vào `storage/app/public/renders/`.
     */
    private function renderMotionGraphicsScene(
        HtmlMotionGraphicsService $motionGraphicsService,
        VideoProject $project,
        StoryboardScene $scene,
        string $filePrefix,
        int $durationMs,
        Filesystem $disk
    ): void {
        $payload = $motionGraphicsService->buildTemplatePayloadFromScene($project, $scene);

        $imageRelativePath = "renders/{$filePrefix}_motion_gfx.png";
        $videoRelativePath = "renders/{$filePrefix}_motion_gfx60fps.mp4";

        $motionGraphicsService->renderSceneClip(
            template: $payload['template'],
            data: $payload['data'],
            durationMs: $durationMs,
            outputPath: $disk->path($videoRelativePath),
            aspectRatio: $project->aspect_ratio,
            cameraMotion: $scene->camera_motion ?: 'zoom_in',
            previewPngOutputPath: $disk->path($imageRelativePath)
        );

        $scene->update([
            'image_file_path' => $imageRelativePath,
            'video_file_path' => $videoRelativePath,
        ]);
    }
}
