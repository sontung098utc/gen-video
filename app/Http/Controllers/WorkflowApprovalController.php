<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\WorkflowMode;
use App\Jobs\Step3_GenerateVoiceAndSfxJob;
use App\Jobs\Step4_GenerateAiSceneVideosJob;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\AI\EdgeTtsVietnameseService;
use App\Services\Media\LocalSfxMatcherService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Controller xử lý toàn bộ 4 Cổng kiểm duyệt (Step-by-Step Approval Checkpoints 1 -> 4),
 * tái tạo (Re-generate) từng phân cảnh đơn lẻ và chuyển đổi chế độ `STEP_BY_STEP` <-> `AUTO`.
 */
class WorkflowApprovalController extends Controller
{
    public function __construct(
        private readonly WorkflowOrchestrator $orchestrator
    ) {}

    /**
     * CỔNG KIỂM DUYỆT SỐ 1 (CP1): `POST /api/projects/{id}/approve-product`
     * Duyệt hồ sơ sản phẩm, kích thước vật lý thực tế (mm), kết quả AI phân tích khách hàng & mẫu 3D `.glb`
     * -> Kích hoạt tạo kịch bản (`SCRIPTING`).
     */
    public function approveProduct(Request $request, int $id): JsonResponse
    {
        $project = VideoProject::query()->with('productProfile')->findOrFail($id);

        $validated = $request->validate([
            'topic_or_brief' => ['sometimes', 'nullable', 'string'],
            'product_name' => ['sometimes', 'string', 'max:255'],
            'product_description' => ['sometimes', 'nullable', 'string'],
            'width_mm' => ['sometimes', 'numeric', 'min:1'],
            'height_mm' => ['sometimes', 'numeric', 'min:1'],
            'depth_mm' => ['sometimes', 'numeric', 'min:1'],
            'customer_analysis' => ['sometimes', 'array'],
            'customer_analysis.target_audience' => ['sometimes', 'nullable', 'string'],
            'customer_analysis.customer_persona' => ['sometimes', 'nullable', 'string'],
            'customer_analysis.pain_points' => ['sometimes', 'array'],
            'customer_analysis.unique_selling_points' => ['sometimes', 'array'],
            'customer_analysis.real_world_scale_context' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('topic_or_brief', $validated)) {
            $project->update(['topic_or_brief' => $validated['topic_or_brief']]);
        }

        if ($project->productProfile !== null) {
            $profileUpdates = array_intersect_key($validated, array_flip([
                'product_name',
                'product_description',
                'width_mm',
                'height_mm',
                'depth_mm',
            ]));

            if (isset($validated['customer_analysis']) && is_array($validated['customer_analysis'])) {
                $existingJson = is_array($project->productProfile->customer_analysis_json)
                    ? $project->productProfile->customer_analysis_json
                    : [];
                $existingJson['customer_analysis'] = $validated['customer_analysis'];
                $profileUpdates['customer_analysis_json'] = $existingJson;
            }

            if ($profileUpdates !== []) {
                $project->productProfile->update($profileUpdates);
            }
        }

        try {
            $nextStatus = $this->orchestrator->approveCheckpoint(
                project: $project,
                expectedCheckpoint: ProjectStatus::WAITING_APPROVAL_PRODUCT
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Đã duyệt Cổng 1 (Hồ sơ sản phẩm & Mô hình 3D). Hệ thống đang biên tập kịch bản phân cảnh.',
                'project_id' => $project->id,
                'next_status' => $nextStatus->value,
                'next_status_label' => $nextStatus->label(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * CỔNG KIỂM DUYỆT SỐ 2 (CP2): `POST /api/projects/{id}/approve-script`
     * Duyệt và cập nhật lời thoại tiếng Việt (`narration_text`), `visual_prompt`, `sfx_prompt`,
     * hỗ trợ thêm cảnh mới, xóa cảnh và sắp xếp lại thứ tự từng bối cảnh
     * -> Kích hoạt tạo giọng đọc (`GENERATING_AUDIO`).
     */
    public function approveScript(Request $request, int $id): JsonResponse
    {
        $project = VideoProject::query()->with('scenes')->findOrFail($id);

        $validated = $request->validate([
            'master_style_prompt' => ['sometimes', 'nullable', 'string'],
            'voice_id' => ['sometimes', 'string', 'in:vi-VN-NamMinhNeural,vi-VN-HoaiMyNeural'],
            'scenes' => ['sometimes', 'array', 'min:1'],
            'scenes.*.id' => ['nullable', 'integer'],
            'scenes.*.scene_order' => ['sometimes', 'integer', 'min:1'],
            'scenes.*.narration_text' => ['required_with:scenes', 'string'],
            'scenes.*.visual_prompt' => ['required_with:scenes', 'string'],
            'scenes.*.sfx_prompt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scenes.*.camera_motion' => ['sometimes', 'string', 'in:zoom_in,zoom_out,pan_right,pan_left'],
            'scenes.*.transition_type' => ['sometimes', 'string', 'in:fade,wipeleft,wiperight,smoothleft,smoothright,circleopen,circleclose,dissolve'],
        ]);

        try {
            DB::transaction(function () use ($project, $validated): void {
                $projectUpdates = [];
                if (array_key_exists('master_style_prompt', $validated)) {
                    $projectUpdates['master_style_prompt'] = $validated['master_style_prompt'];
                }
                if (isset($validated['voice_id'])) {
                    $projectUpdates['voice_id'] = $validated['voice_id'];
                }
                if ($projectUpdates !== []) {
                    $project->update($projectUpdates);
                }

                if (! empty($validated['scenes']) && is_array($validated['scenes'])) {
                    $keptIds = array_values(array_filter(array_map(
                        static fn (array $item): ?int => isset($item['id']) && $item['id'] > 0 ? (int) $item['id'] : null,
                        $validated['scenes']
                    )));

                    // Xóa các phân cảnh đã bị người dùng loại bỏ trên giao diện CP2
                    if ($keptIds !== []) {
                        StoryboardScene::query()
                            ->where('video_project_id', $project->id)
                            ->whereNotIn('id', $keptIds)
                            ->delete();
                    } else {
                        StoryboardScene::query()
                            ->where('video_project_id', $project->id)
                            ->delete();
                    }

                    // Tạm dịch chuyển scene_order để tránh đụng unique index (video_project_id, scene_order) khi sắp xếp lại
                    StoryboardScene::query()
                        ->where('video_project_id', $project->id)
                        ->increment('scene_order', 1000);

                    foreach (array_values($validated['scenes']) as $index => $sceneData) {
                        $order = $index + 1;
                        $payload = [
                            'scene_order' => $order,
                            'narration_text' => trim((string) $sceneData['narration_text']),
                            'visual_prompt' => trim((string) $sceneData['visual_prompt']),
                            'sfx_prompt' => trim((string) ($sceneData['sfx_prompt'] ?? 'whoosh')),
                            'camera_motion' => (string) ($sceneData['camera_motion'] ?? 'zoom_in'),
                            'transition_type' => (string) ($sceneData['transition_type'] ?? 'fade'),
                        ];

                        if (! empty($sceneData['id'])) {
                            StoryboardScene::query()
                                ->where('video_project_id', $project->id)
                                ->whereKey((int) $sceneData['id'])
                                ->update($payload);
                        } else {
                            StoryboardScene::query()->create(array_merge($payload, [
                                'video_project_id' => $project->id,
                                'transition_duration_ms' => 500,
                                'duration_ms' => 0,
                            ]));
                        }
                    }
                }
            });

            $nextStatus = $this->orchestrator->approveCheckpoint(
                project: $project,
                expectedCheckpoint: ProjectStatus::WAITING_APPROVAL_SCRIPT
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Đã duyệt Cổng 2 (Kịch bản & Phân cảnh). Hệ thống đang tổng hợp giọng đọc tiếng Việt (Edge-TTS).',
                'project_id' => $project->id,
                'next_status' => $nextStatus->value,
                'next_status_label' => $nextStatus->label(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * CỔNG KIỂM DUYỆT SỐ 3 (CP3): `POST /api/projects/{id}/approve-audio`
     * Duyệt giọng đọc tiếng Việt (`.mp3`), phụ đề (`.vtt`) và âm thanh SFX/BGM
     * -> Kích hoạt tạo hình ảnh / video AI từng cảnh (`GENERATING_VISUALS`).
     */
    public function approveAudio(
        Request $request,
        int $id,
        LocalSfxMatcherService $sfxMatcher
    ): JsonResponse {
        $project = VideoProject::query()->with('scenes')->findOrFail($id);

        $validated = $request->validate([
            'voice_id' => ['sometimes', 'string', 'in:vi-VN-NamMinhNeural,vi-VN-HoaiMyNeural'],
            'bgm_file_path' => ['sometimes', 'nullable', 'string', 'max:512'],
            'resynthesize_all_voices' => ['sometimes', 'boolean'],
            'scenes' => ['sometimes', 'array'],
            'scenes.*.id' => ['required_with:scenes', 'integer'],
            'scenes.*.sfx_prompt' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $voiceChanged = isset($validated['voice_id']) && $validated['voice_id'] !== $project->voice_id;

            if (isset($validated['voice_id'])) {
                $project->voice_id = $validated['voice_id'];
            }
            if (array_key_exists('bgm_file_path', $validated)) {
                $project->bgm_file_path = $validated['bgm_file_path'];
            }
            $project->save();

            // Cập nhật lại SFX nếu người dùng đổi từ khóa hiệu ứng âm thanh ở CP3
            if (! empty($validated['scenes']) && is_array($validated['scenes'])) {
                foreach ($validated['scenes'] as $sceneItem) {
                    if (array_key_exists('sfx_prompt', $sceneItem)) {
                        $newPrompt = trim((string) ($sceneItem['sfx_prompt'] ?? ''));
                        $matchedPath = $newPrompt !== '' ? $sfxMatcher->matchSfxForScene($newPrompt) : null;

                        StoryboardScene::query()
                            ->where('video_project_id', $project->id)
                            ->whereKey((int) $sceneItem['id'])
                            ->update([
                                'sfx_prompt' => $newPrompt,
                                'sfx_file_path' => $matchedPath,
                            ]);
                    }
                }
            }

            // Nếu người dùng yêu cầu đổi giọng đọc toàn dự án (NamMinh <-> HoaiMy) và tạo lại giọng
            if (! empty($validated['resynthesize_all_voices']) || $voiceChanged) {
                $project->update(['status' => ProjectStatus::GENERATING_AUDIO]);
                Step3_GenerateVoiceAndSfxJob::dispatch($project->id)->onQueue('ai-orchestration');

                return response()->json([
                    'status' => 'success',
                    'message' => 'Đang tổng hợp lại toàn bộ giọng đọc tiếng Việt với giọng mới (' . $project->voice_id . ').',
                    'project_id' => $project->id,
                    'next_status' => ProjectStatus::GENERATING_AUDIO->value,
                    'next_status_label' => ProjectStatus::GENERATING_AUDIO->label(),
                ]);
            }

            $nextStatus = $this->orchestrator->approveCheckpoint(
                project: $project,
                expectedCheckpoint: ProjectStatus::WAITING_APPROVAL_AUDIO
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Đã duyệt Cổng 3 (Giọng đọc & Âm thanh). Hệ thống đang tạo Hình ảnh / Video AI (Fal.ai).',
                'project_id' => $project->id,
                'next_status' => $nextStatus->value,
                'next_status_label' => $nextStatus->label(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * CỔNG KIỂM DUYỆT SỐ 4 (CP4): `POST /api/projects/{id}/approve-scenes`
     * Duyệt toàn bộ các đoạn video phân cảnh và kiểu chuyển cảnh `xfade`
     * -> Kích hoạt kết xuất video cuối cùng (`RENDERING`).
     */
    public function approveScenes(Request $request, int $id): JsonResponse
    {
        $project = VideoProject::query()->findOrFail($id);

        $validated = $request->validate([
            'scenes' => ['sometimes', 'array'],
            'scenes.*.id' => ['required_with:scenes', 'integer'],
            'scenes.*.transition_type' => ['required_with:scenes', 'string', 'in:fade,wipeleft,wiperight,smoothleft,smoothright,circleopen,circleclose,dissolve'],
        ]);

        try {
            if (! empty($validated['scenes']) && is_array($validated['scenes'])) {
                foreach ($validated['scenes'] as $sceneItem) {
                    StoryboardScene::query()
                        ->where('video_project_id', $project->id)
                        ->whereKey((int) $sceneItem['id'])
                        ->update(['transition_type' => (string) $sceneItem['transition_type']]);
                }
            }

            $nextStatus = $this->orchestrator->approveCheckpoint(
                project: $project,
                expectedCheckpoint: ProjectStatus::WAITING_APPROVAL_SCENES
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Đã duyệt Cổng 4 (Phân cảnh Video). Hệ thống đang kết xuất Video 60fps cuối cùng bằng FFmpeg.',
                'project_id' => $project->id,
                'next_status' => $nextStatus->value,
                'next_status_label' => $nextStatus->label(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * TÁI TẠO ĐƠN LẺ 1 PHÂN CẢNH: `POST /api/scenes/{sceneId}/regenerate`
     * Cho phép tạo lại (re-generate) giọng đọc và/hoặc hình ảnh/video của riêng một bối cảnh bất kỳ
     * mà không phải chạy lại toàn bộ dự án.
     */
    public function regenerateScene(
        Request $request,
        int $sceneId,
        EdgeTtsVietnameseService $edgeTtsService,
        LocalSfxMatcherService $sfxMatcher
    ): JsonResponse {
        $scene = StoryboardScene::query()->with('videoProject')->findOrFail($sceneId);
        $project = $scene->videoProject;

        $validated = $request->validate([
            'narration_text' => ['sometimes', 'string'],
            'visual_prompt' => ['sometimes', 'string'],
            'sfx_prompt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'camera_motion' => ['sometimes', 'string', 'in:zoom_in,zoom_out,pan_right,pan_left'],
            'transition_type' => ['sometimes', 'string', 'in:fade,wipeleft,wiperight,smoothleft,smoothright,circleopen,circleclose,dissolve'],
            'regenerate_audio' => ['sometimes', 'boolean'],
            'regenerate_visual' => ['sometimes', 'boolean'],
        ]);

        try {
            $narrationChanged = isset($validated['narration_text'])
                && trim($validated['narration_text']) !== trim($scene->narration_text);

            $scene->fill(array_intersect_key($validated, array_flip([
                'narration_text',
                'visual_prompt',
                'sfx_prompt',
                'camera_motion',
                'transition_type',
            ])));
            $scene->save();

            $shouldRegenAudio = (bool) ($validated['regenerate_audio'] ?? $narrationChanged);
            $shouldRegenVisual = (bool) ($validated['regenerate_visual'] ?? true);

            // 1. Nếu câu thoại thay đổi hoặc yêu cầu tạo lại Audio -> chạy đồng bộ Edge-TTS (0$)
            if ($shouldRegenAudio) {
                $filePrefix = sprintf('project_%d_scene_%02d_regen_%d', $project->id, $scene->scene_order, time());
                $lockedVoice = $edgeTtsService->resolveVietnameseVoice(
                    $project->voice_name !== '' ? $project->voice_name : $project->voice_id
                );
                $ttsResult = $edgeTtsService->synthesizeSceneVoice(
                    text: $scene->narration_text,
                    voice: $lockedVoice,
                    filePrefix: $filePrefix,
                    videoProjectId: $project->id,
                    storyboardSceneId: $scene->id
                );

                $sfxPath = $scene->sfx_prompt !== null
                    ? $sfxMatcher->matchSfxForScene($scene->sfx_prompt)
                    : $scene->sfx_file_path;

                $scene->update([
                    'voice_file_path' => $ttsResult['audio_relative_path'],
                    'subtitle_file_path' => $ttsResult['subtitle_relative_path'],
                    'duration_ms' => $ttsResult['duration_ms'],
                    'sfx_file_path' => $sfxPath,
                ]);
            }

            // 2. Nếu yêu cầu tạo lại Hình ảnh / Video AI -> đẩy Job riêng cho cảnh này vào queue `ai-orchestration`
            if ($shouldRegenVisual) {
                Step4_GenerateAiSceneVideosJob::dispatch($project->id, $scene->id)
                    ->onQueue('ai-orchestration');
            }

            return response()->json([
                'status' => 'success',
                'message' => "Đã kích hoạt tạo lại phân cảnh #{$scene->scene_order}.",
                'scene_id' => $scene->id,
                'regenerated_audio' => $shouldRegenAudio,
                'queued_visual_regeneration' => $shouldRegenVisual,
                'scene' => $scene->fresh(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lỗi khi tạo lại phân cảnh: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * CHUYỂN ĐỔI CHẾ ĐỘ DUYỆT: `POST /api/projects/{id}/toggle-mode`
     * Cho phép gạt công tắc giữa `STEP_BY_STEP` (Duyệt từng bước) và `AUTO` (Tự động hoàn toàn).
     * Nếu đang dừng ở cổng duyệt mà bật sang `AUTO`, hệ thống lập tức chạy tiếp tự động!
     */
    public function toggleMode(Request $request, int $id): JsonResponse
    {
        $project = VideoProject::query()->findOrFail($id);

        $validated = $request->validate([
            'workflow_mode' => ['sometimes', 'string', 'in:step_by_step,auto'],
        ]);

        $targetMode = isset($validated['workflow_mode'])
            ? WorkflowMode::from($validated['workflow_mode'])
            : null;

        $updatedProject = $this->orchestrator->toggleWorkflowMode($project, $targetMode);

        return response()->json([
            'status' => 'success',
            'message' => sprintf('Đã chuyển chế độ vận hành dự án sang: %s', $updatedProject->workflow_mode->label()),
            'project_id' => $updatedProject->id,
            'workflow_mode' => $updatedProject->workflow_mode->value,
            'workflow_mode_label' => $updatedProject->workflow_mode->label(),
            'current_status' => $updatedProject->status->value,
            'current_status_label' => $updatedProject->status->label(),
        ]);
    }
}
