<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectStatus;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\Media\AssSubtitleService;
use App\Services\Media\FfmpegVideoCompositorService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * BƯỚC 5 (Queue: `ffmpeg-render`): Lắp ráp và kết xuất Video hoàn chỉnh cuối cùng bằng FFmpeg 7.x
 * trên máy chủ CentOS 8 (`nice -n 10 ffmpeg -threads 6`):
 *  1. Hợp nhất các file phụ đề `.vtt` sang định dạng `.ass` Karaoke tiếng Việt (`AssSubtitleService`).
 *  2. Ghép nối các video phân cảnh bằng bộ lọc `xfade` không lệch frame (`stitchScenesWithTransitions`).
 *  3. Ghép nối giọng đọc tiếng Việt và trộn đa lớp âm thanh Voice (0dB) + SFX (-6dB) + BGM (-18dB sidechain ducking).
 *  4. Đóng dấu (Burn-in) phụ đề `.ass` vào video và xuất file `.mp4` cuối cùng vào `storage/app/public/videos/`.
 */
class Step5_RenderFinalVideoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 1200;

    public function __construct(
        public readonly int $videoProjectId
    ) {
        $this->onQueue('ffmpeg-render');
    }

    public function handle(
        AssSubtitleService $assSubtitleService,
        FfmpegVideoCompositorService $ffmpegCompositor,
        WorkflowOrchestrator $orchestrator
    ): void {
        $project = VideoProject::query()->with('scenes')->findOrFail($this->videoProjectId);

        if ($project->scenes->isEmpty()) {
            $orchestrator->markAsFailed($project, 'Không có phân cảnh nào để kết xuất video cuối.');

            return;
        }

        try {
            $disk = Storage::disk('public');
            $buildDir = storage_path("app/temp_render/project_{$project->id}");
            if (! is_dir($buildDir)) {
                mkdir($buildDir, 0775, true);
            }

            $sceneVideoPaths = [];
            $transitionTypes = [];
            $voiceFilePaths = [];
            $sfxTimeline = [];
            $vttTimeline = [];

            $runningOffsetMs = 0;

            /** @var StoryboardScene $scene */
            foreach ($project->scenes as $scene) {
                if ($scene->video_file_path === null || ! $disk->exists($scene->video_file_path)) {
                    throw new RuntimeException("Phân cảnh #{$scene->scene_order} chưa có file video hợp lệ.");
                }
                if ($scene->voice_file_path === null || ! $disk->exists($scene->voice_file_path)) {
                    throw new RuntimeException("Phân cảnh #{$scene->scene_order} chưa có file giọng đọc tiếng Việt.");
                }

                $sceneVideoPaths[] = $disk->path($scene->video_file_path);
                $transitionTypes[] = $scene->transition_type ?: 'fade';
                $voiceFilePaths[] = $disk->path($scene->voice_file_path);

                // Định vị hiệu ứng âm thanh SFX (-6dB) tại điểm bắt đầu / chuyển cảnh của mỗi phân cảnh
                $sfxFilePath = $scene->sfx_file_path;
                if ($sfxFilePath === null || ! is_file($sfxFilePath)) {
                    $defaultSfxName = ($scene->scene_order % 2 === 0) ? 'pop.mp3' : 'whoosh.mp3';
                    $candidateSfx = storage_path("app/sfx_library/{$defaultSfxName}");
                    if (is_file($candidateSfx)) {
                        $sfxFilePath = $candidateSfx;
                    }
                }

                if ($sfxFilePath !== null && is_file($sfxFilePath)) {
                    $sfxTimeline[] = [
                        'file_path' => $sfxFilePath,
                        'start_ms' => $runningOffsetMs,
                    ];
                }

                // Định vị phụ đề .vtt của cảnh trên trục thời gian tổng
                if ($scene->subtitle_file_path !== null && $disk->exists($scene->subtitle_file_path)) {
                    $vttTimeline[] = [
                        'vtt_path' => $disk->path($scene->subtitle_file_path),
                        'offset_ms' => $runningOffsetMs,
                    ];
                }

                $sceneDurationMs = $scene->duration_ms > 0
                    ? $scene->duration_ms
                    : (int) round($ffmpegCompositor->probeMediaDurationSeconds($disk->path($scene->voice_file_path)) * 1000);

                $runningOffsetMs += $sceneDurationMs;
            }

            // 1. Tạo file phụ đề .ass tổng hợp với hiệu ứng Karaoke màu vàng #FFFF00
            $assFilePath = "{$buildDir}/merged_subtitles.ass";
            if (count($vttTimeline) === 1 && $vttTimeline[0]['offset_ms'] === 0) {
                $assSubtitleService->convertVttToAss(
                    vttFilePath: $vttTimeline[0]['vtt_path'],
                    assOutputPath: $assFilePath,
                    stylePreset: 'karaoke',
                    aspectRatio: $project->aspect_ratio
                );
            } else {
                $assSubtitleService->mergeSceneVttsToAss(
                    sceneVttTimeline: $vttTimeline,
                    assOutputPath: $assFilePath,
                    stylePreset: 'karaoke',
                    aspectRatio: $project->aspect_ratio
                );
            }

            // 2. Nối các video phân cảnh bằng bộ lọc xfade (có tpad bù thời gian chuyển cảnh để không lệch frame)
            $stitchedVideoPath = "{$buildDir}/stitched_visuals.mp4";
            $ffmpegCompositor->stitchScenesWithTransitions(
                sceneVideoPaths: $sceneVideoPaths,
                transitionTypes: $transitionTypes,
                outputVideoPath: $stitchedVideoPath,
                transitionDurationSec: 0.500
            );

            // 3. Ghép nối trục giọng đọc tiếng Việt và trộn đa lớp âm thanh (Voice 0dB + SFX -6dB + BGM -18dB sidechain ducking)
            $concatenatedVoicePath = "{$buildDir}/full_voiceover.mp3";
            $ffmpegCompositor->concatenateVoiceTracks($voiceFilePaths, $concatenatedVoicePath);

            $bgmTrackPath = $project->bgm_file_path;
            if ($bgmTrackPath === null || trim($bgmTrackPath) === '' || ! is_file($bgmTrackPath)) {
                $bgmTrackPath = storage_path('app/sfx_library/ambient_bgm.mp3');
            }

            $mixedAudioVideoPath = "{$buildDir}/mixed_audio_video.mp4";
            $ffmpegCompositor->mixAudioLayers(
                videoPath: $stitchedVideoPath,
                voiceTrackPath: $concatenatedVoicePath,
                sfxTimeline: $sfxTimeline,
                bgmTrackPath: $bgmTrackPath,
                finalOutputPath: $mixedAudioVideoPath
            );

            // 4. Đóng dấu (Burn-in) phụ đề tiếng Việt .ass vào video và xuất file .mp4 hoàn chỉnh
            $finalRelativePath = sprintf('videos/project_%d_final_%s.mp4', $project->id, date('Ymd_His'));
            $finalFullPath = $disk->path($finalRelativePath);

            $ffmpegCompositor->burnSubtitles(
                videoPath: $mixedAudioVideoPath,
                assSubtitlePath: $assFilePath,
                finalOutputPath: $finalFullPath
            );

            // 5. Cập nhật trạng thái COMPLETED và đường dẫn video cuối cùng
            $project->update([
                'final_video_path' => $finalRelativePath,
                'total_duration_ms' => $runningOffsetMs,
                'status' => ProjectStatus::COMPLETED,
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $orchestrator->markAsFailed($project, 'Step 5 (Kết xuất Video FFmpeg cuối cùng) thất bại: ' . $e->getMessage());
            throw $e;
        }
    }
}
