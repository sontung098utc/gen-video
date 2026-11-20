<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\VideoProject;
use App\Services\Media\AssSubtitleService;
use App\Services\Media\FfmpegVideoCompositorService;
use App\Services\Media\LocalSfxMatcherService;
use App\Services\Media\WordAnchoredTimelineService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * AGENT AI 7 — ĐẠO DIỄN HẬU KỲ PHONG CÁCH CAPCUT (CapCut-Style Video Compositor).
 *
 * Chức năng:
 *  - Kết nối các đoạn video châu Á (Agent 4) bằng hiệu ứng chuyển cảnh mượt mà (`xfade`).
 *  - Ghép giọng đọc đồng nhất (Agent 5) + nhạc nền nhẹ nhàng (auto-ducking) + hiệu ứng âm thanh (SFX) đúng mốc chuyển cảnh & từ khóa.
 *  - Tùy chọn bật/tắt phụ đề và chọn mẫu phụ đề theo ý người dùng (`capcut_yellow`, `karaoke_green`, `box_highlight`, `clean_white`).
 */
class Agent7CapCutCompositorService
{
    public function __construct(
        private readonly FfmpegVideoCompositorService $compositor,
        private readonly AssSubtitleService $assSubtitle,
        private readonly LocalSfxMatcherService $sfxMatcher,
        private readonly WordAnchoredTimelineService $wordTimeline
    ) {}

    /**
     * Danh sách mẫu phụ đề phong cách CapCut cho người dùng lựa chọn trên giao diện.
     *
     * @return array<string, array{label: string, preset: string, description: string}>
     */
    public static function subtitleStyles(): array
    {
        return [
            'capcut_yellow' => [
                'label' => 'CapCut Vàng Nổi Bật (Karaoke)',
                'preset' => 'karaoke',
                'description' => 'Chữ trắng nổi bật từng từ vàng rực viền đen — chuẩn TikTok / Reels viral.',
            ],
            'box_highlight' => [
                'label' => 'Hộp Nền Mờ Hiện Đại (Box Karaoke)',
                'preset' => 'karaoke_box',
                'description' => 'Có hộp nền bán trong suốt giúp dễ đọc trên mọi nền video sáng.',
            ],
            'karaoke_green' => [
                'label' => 'Nhấn Mạnh Sức Khỏe (Highlight)',
                'preset' => 'highlight',
                'description' => 'Chữ rõ nét, nhấn sáng nhẹ nhàng phù hợp khán giả trung niên & gia đình.',
            ],
            'clean_white' => [
                'label' => 'Tối Giản Thanh Lịch (Clean)',
                'preset' => 'clean',
                'description' => 'Phụ đề trắng viền mảnh tinh tế, không che khuất hình ảnh.',
            ],
        ];
    }

    /**
     * Lắp ráp hoàn chỉnh video 9:16 (hoặc 16:9) 60fps cho dự án.
     */
    public function composeProjectVideo(VideoProject $project): VideoProject
    {
        $project->load('scenes');
        $disk = Storage::disk('public');

        if ($project->scenes->isEmpty()) {
            throw new RuntimeException('Dự án chưa có phân cảnh nào để Agent AI 7 lắp ráp.');
        }

        $sceneVideoPaths = [];
        $voiceTrackPaths = [];
        $transitionTypes = [];
        $sceneVttTimeline = [];
        $sfxTimeline = [];

        $cursorMs = 0;
        $transitionDurationSec = 0.45;
        $capcutDefaultTransitions = ['fade', 'smoothleft', 'wipeup', 'dissolve', 'smoothright', 'circleopen'];

        foreach ($project->scenes as $idx => $scene) {
            if (! $scene->video_file_path || ! $disk->exists($scene->video_file_path)) {
                throw new RuntimeException("Thiếu video của Cảnh {$scene->scene_order} (Agent AI 4 cần chạy trước).");
            }
            if (! $scene->voice_file_path || ! $disk->exists($scene->voice_file_path)) {
                throw new RuntimeException("Thiếu âm thanh giọng đọc của Cảnh {$scene->scene_order} (Agent AI 5 cần chạy trước).");
            }

            $videoFull = $disk->path($scene->video_file_path);
            $voiceFull = $disk->path($scene->voice_file_path);

            $sceneVideoPaths[] = $videoFull;
            $voiceTrackPaths[] = $voiceFull;

            $sceneDurationMs = max(1500, (int) round($this->compositor->probeMediaDurationSeconds($voiceFull) * 1000));

            // Hiệu ứng chuyển cảnh CapCut giữa cảnh i và i+1
            if ($idx > 0) {
                $trans = trim((string) ($scene->transition_type ?: $capcutDefaultTransitions[$idx % count($capcutDefaultTransitions)]));
                $transitionTypes[] = $trans;

                // Chèn SFX chuyển cảnh nhẹ nhàng đúng mốc chuyển cảnh
                $transSfx = $this->sfxMatcher->matchSfxForScene($scene->sfx_prompt ?: 'whoosh');
                if ($transSfx !== null && is_file($transSfx)) {
                    $sfxTimeline[] = [
                        'file_path' => $transSfx,
                        'start_ms' => max(0, $cursorMs - 150),
                    ];
                }
            }

            // Gắn phụ đề VTT + SFX theo mốc từ khóa `@{whoosh}`, `@{pop}`, `@{ding}`
            if ($scene->subtitle_file_path && $disk->exists($scene->subtitle_file_path)) {
                $vttFull = $disk->path($scene->subtitle_file_path);
                $sceneVttTimeline[] = [
                    'vtt_path' => $vttFull,
                    'offset_ms' => $cursorMs,
                ];

                $anchors = $this->wordTimeline->extractWordAnchors(
                    taggedNarration: (string) $scene->narration_text,
                    vttFullPath: $vttFull,
                    sceneOffsetMs: $cursorMs,
                    sceneDurationMs: $sceneDurationMs
                );
                foreach ($anchors['sfx_events'] ?? [] as $item) {
                    $sfxTimeline[] = $item;
                }
            }

            $cursorMs += $sceneDurationMs;
        }

        // Giới hạn tối đa 10 SFX trong 1 video để không bị ồn
        $sfxTimeline = array_slice($sfxTimeline, 0, 10);

        $tempDir = storage_path('app/temp/agent7_' . $project->id . '_' . uniqid());
        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $stitchedVideoPath = "{$tempDir}/stitched.mp4";
        $mergedVoicePath = "{$tempDir}/voice_merged.mp3";
        $mixedVideoPath = "{$tempDir}/mixed.mp4";

        // 1. Nối video châu Á với chuyển cảnh CapCut
        $this->compositor->stitchScenesWithTransitions(
            sceneVideoPaths: $sceneVideoPaths,
            transitionTypes: $transitionTypes,
            outputVideoPath: $stitchedVideoPath,
            transitionDurationSec: $transitionDurationSec
        );

        // 2. Nối giọng đọc thành 1 track liên tục
        $this->compositor->concatenateVoiceTracks($voiceTrackPaths, $mergedVoicePath);

        // 3. Trộn giọng đọc + BGM (ducking) + SFX CapCut
        $bgmFull = ($project->bgm_file_path && $disk->exists($project->bgm_file_path))
            ? $disk->path($project->bgm_file_path)
            : null;

        $this->compositor->mixAudioLayers(
            videoPath: $stitchedVideoPath,
            voiceTrackPath: $mergedVoicePath,
            sfxTimeline: $sfxTimeline,
            bgmTrackPath: $bgmFull,
            finalOutputPath: $mixedVideoPath
        );

        // 4. Đốt phụ đề (chỉ khi người dùng tích chọn Bật phụ đề)
        $finalRelPath = sprintf('exports/songkhoemoingay_p%d_%s.mp4', $project->id, date('Ymd_His'));
        $finalFullPath = $disk->path($finalRelPath);
        if (! is_dir(dirname($finalFullPath))) {
            @mkdir(dirname($finalFullPath), 0775, true);
        }

        if ($project->subtitle_enabled && $sceneVttTimeline !== []) {
            $styles = self::subtitleStyles();
            $styleKey = (string) ($project->subtitle_style ?: 'capcut_yellow');
            $assPreset = $styles[$styleKey]['preset'] ?? 'karaoke';
            $assPath = "{$tempDir}/subtitles.ass";

            $this->assSubtitle->mergeSceneVttsToAss(
                sceneVttTimeline: $sceneVttTimeline,
                assOutputPath: $assPath,
                stylePreset: $assPreset,
                aspectRatio: $project->aspect_ratio === '16:9' ? '16:9' : '9:16'
            );

            $this->compositor->burnSubtitles(
                videoPath: $mixedVideoPath,
                assSubtitlePath: $assPath,
                finalOutputPath: $finalFullPath
            );
        } else {
            copy($mixedVideoPath, $finalFullPath);
        }

        // Dọn file tạm
        foreach (glob("{$tempDir}/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tempDir);

        $finalDurationMs = (int) round($this->compositor->probeMediaDurationSeconds($finalFullPath) * 1000);

        $project->update([
            'final_video_path' => $finalRelPath,
            'total_duration_ms' => max($cursorMs, $finalDurationMs),
        ]);

        return $project->refresh();
    }
}
