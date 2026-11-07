<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\AI\EdgeTtsVietnameseService;
use App\Services\Media\LocalSfxMatcherService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * BƯỚC 3 (Queue: `ai-orchestration`): Tổng hợp giọng đọc tiếng Việt miễn phí bằng CLI `edge-tts` ($0.00),
 * trích xuất phụ đề `.vtt` kèm mốc thời gian từng từ, đo chính xác thời lượng mili-giây bằng `ffprobe`,
 * và tự động gán file hiệu ứng âm thanh (SFX) + nhạc nền (BGM) từ kho cục bộ `storage/app/sfx_library/` ($0.00).
 *
 * Đảm bảo khóa cứng duy nhất 1 giọng đọc (`$project->voice_name` / `$project->voice_id`)
 * xuyên suốt 100% các phân cảnh trong dự án.
 */
class Step3_GenerateVoiceAndSfxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $videoProjectId
    ) {
        $this->onQueue('ai-orchestration');
    }

    public function handle(
        EdgeTtsVietnameseService $edgeTtsService,
        LocalSfxMatcherService $sfxMatcherService,
        WorkflowOrchestrator $orchestrator
    ): void {
        $project = VideoProject::query()->with('scenes')->findOrFail($this->videoProjectId);

        if ($project->scenes->isEmpty()) {
            $orchestrator->markAsFailed($project, 'Dự án chưa có phân cảnh nào để tạo giọng đọc.');

            return;
        }

        try {
            $totalDurationMs = 0;

            // Khóa duy nhất 1 giọng đọc đã chọn từ $project->voice_name (hoặc $project->voice_id) cho toàn bộ các cảnh
            $lockedVoiceName = $edgeTtsService->resolveVietnameseVoice(
                $project->voice_name !== '' ? $project->voice_name : $project->voice_id
            );

            /** @var StoryboardScene $scene */
            foreach ($project->scenes as $scene) {
                if (trim($scene->narration_text) === '') {
                    throw new RuntimeException("Phân cảnh #{$scene->scene_order} đang trống nội dung câu thoại tiếng Việt.");
                }

                $filePrefix = sprintf('project_%d_scene_%02d', $project->id, $scene->scene_order);

                // 1. Gọi Edge-TTS với duy nhất giọng đọc $lockedVoiceName cho mọi phân cảnh
                $ttsResult = $edgeTtsService->synthesizeSceneVoice(
                    text: $scene->narration_text,
                    voice: $lockedVoiceName,
                    filePrefix: $filePrefix,
                    videoProjectId: $project->id,
                    storyboardSceneId: $scene->id
                );

                // 2. Gán hiệu ứng âm thanh (SFX) cục bộ phù hợp với từ khóa sfx_prompt (mặc định whoosh/pop)
                $sfxKeyword = ($scene->sfx_prompt !== null && trim($scene->sfx_prompt) !== '')
                    ? $scene->sfx_prompt
                    : 'whoosh';
                $matchedSfxPath = $sfxMatcherService->matchSfxForScene($sfxKeyword);

                $scene->update([
                    'voice_file_path' => $ttsResult['audio_relative_path'],
                    'subtitle_file_path' => $ttsResult['subtitle_relative_path'],
                    'duration_ms' => $ttsResult['duration_ms'],
                    'sfx_file_path' => $matchedSfxPath,
                ]);

                $totalDurationMs += $ttsResult['duration_ms'];
            }

            // Tự động gán nhạc nền BGM mặc định (storage/app/sfx_library/ambient_bgm.mp3) nếu dự án chưa có BGM hợp lệ
            $bgmPath = $project->bgm_file_path;
            if ($bgmPath === null || trim($bgmPath) === '' || ! is_file($bgmPath)) {
                $bgmPath = $sfxMatcherService->findDefaultBgmTrack();
            }

            $project->update([
                'voice_id' => $lockedVoiceName,
                'total_duration_ms' => $totalDurationMs,
                'bgm_file_path' => $bgmPath,
            ]);

            // Chuyển sang Cổng kiểm duyệt số 3 (WAITING_APPROVAL_AUDIO) hoặc đi tiếp sang GENERATING_VISUALS
            $orchestrator->dispatchNextStep($project);
        } catch (Throwable $e) {
            $orchestrator->markAsFailed($project, 'Step 3 (Tạo Giọng đọc Tiếng Việt & SFX) thất bại: ' . $e->getMessage());
            throw $e;
        }
    }
}
