<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\HealthPlanItem;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AGENT AI 3 — BIÊN KỊCH KIẾN THỨC SỨC KHỎE CHÍNH THỐNG (Health Scriptwriter).
 *
 * Từ Tiêu đề SEO + Kho Tài Liệu Y Khoa + Quy chuẩn Viral -> Sản xuất kịch bản 5-6 phân cảnh (`StoryboardScene`)
 * với lời thoại tiếng Việt chuẩn xác, tự nhiên, gắn thẻ SFX (`@{whoosh}`, `@{pop}`, `@{ding}`),
 * chuyển cảnh phong cách CapCut (`xfade`), và từ khóa tìm video người châu Á (Việt Nam / Trung Quốc) cho Agent 4.
 */
class Agent3HealthScriptwriterService
{
    public function __construct(
        private readonly HealthGeminiClient $gemini,
        private readonly HealthKnowledgeBaseService $knowledgeBase
    ) {}

    /**
     * Sản xuất kịch bản hoàn chỉnh và lưu vào bảng `storyboard_scenes` của dự án.
     */
    public function writeScriptForProject(
        VideoProject $project,
        ?HealthPlanItem $planItem = null,
        ?string $qaFeedback = null
    ): VideoProject {
        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');
        $title = $planItem?->seo_title_tiktok ?: $project->title;
        $brief = $planItem?->brief ?: (string) $project->topic_or_brief;
        $angle = $planItem ? $planItem->angleLabel() : 'Đông - Tây Y Kết Hợp';
        $kbContext = $this->knowledgeBase->retrieveRelevantContext($title . ' ' . $brief, 3200);
        $viralRules = $this->loadRuleExcerpt('viral_structure.md');
        $policyRules = $this->loadRuleExcerpt('platform_policies.md');

        $feedbackSection = $qaFeedback
            ? "\n=== YÊU CẦU CHỈNH SỬA BẮT BUỘC TỪ TRƯỞNG PHÒNG AGENT AI 8 ===\n{$qaFeedback}\n"
            : '';

        $systemPrompt = <<<PROMPT
Bạn là Agent AI 3 — Biên kịch Y khoa & Sức khỏe cao cấp cho kênh "{$channelName}" (TikTok, Fanpage Reels, YouTube Shorts).

QUY CHUẨN CHÍNH SÁCH NỀN TẢNG:
{$policyRules}

QUY CHUẨN CẤU TRÚC VIDEO VIRAL:
{$viralRules}

YÊU CẦU ĐẦU RA (BẮT BUỘC JSON):
- Tạo đúng 6 phân cảnh (tổng thời lượng khoảng 45–60 giây).
- `narration_text`: Tiếng Việt 100% chuẩn chính tả, ấm áp, dễ hiểu, KHÔNG pha tạp từ tiếng Anh vô nghĩa. Có thể chèn tối đa 1 thẻ hiệu ứng âm thanh hợp lý ở vị trí nhấn mạnh: `@{whoosh}`, `@{pop}`, `@{ding}`, `@{chime}`.
- `visual_prompt`: Từ khóa tìm video stock bằng TIẾNG ANH ngắn gọn (3–6 từ) BẮT BUỘC ưu tiên bối cảnh **người châu Á / Việt Nam / Trung Quốc** hoặc thảo dược/món ăn lành mạnh (ví dụ: `"asian woman drinking herbal tea"`, `"elderly asian couple morning exercise"`, `"chinese traditional herbs teapot"`, `"asian doctor explaining nutrition"`).
- `transition_type`: Chọn một trong các chuyển cảnh CapCut mượt mà: `fade`, `smoothleft`, `smoothright`, `wipeup`, `dissolve`, `circleopen`.
- `sfx_prompt`: Một từ khóa ngắn (`whoosh`, `pop`, `ding`, `chime`, `none`).
- `cover_headline`: Tiêu đề ngắn 4–7 từ siêu sắc nét, chuẩn chính tả tiếng Việt để in lên Ảnh bìa Photographic.
- `thumbnail_visual_prompt`: Mô tả ảnh chụp thực tế (Photographic, ánh sáng tự nhiên, người châu Á hoặc nguyên liệu sức khỏe đẹp mắt, TUYỆT ĐỐI KHÔNG CÓ CHỮ trên ảnh) bằng tiếng Anh cho Agent AI 6.

Cấu trúc JSON:
{
  "cover_headline": "TIÊU ĐỀ NGẮN CHO ẢNH BÌA",
  "thumbnail_visual_prompt": "Photorealistic studio photography of ...",
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "Câu thoại tiếng Việt...",
      "visual_prompt": "asian woman morning herbal tea",
      "transition_type": "fade",
      "sfx_prompt": "whoosh",
      "camera_motion": "zoom_in"
    }
  ]
}
PROMPT;

        $userPrompt = <<<USER
Hãy viết kịch bản video kiến thức sức khỏe cho kênh "{$channelName}":
- Tiêu đề video: {$title}
- Góc tiếp cận: {$angle}
- Nội dung trọng tâm: {$brief}

Tài liệu chính thống từ Kho Kiến Thức:
{$kbContext}
{$feedbackSection}
Hãy trả về JSON chuẩn với đúng 6 phân cảnh.
USER;

        $scenesData = [];
        $coverHeadline = $planItem?->cover_headline ?: $project->title;
        $thumbnailPrompt = "Photorealistic natural daylight photography of healthy Asian lifestyle, fresh herbal tea and organic nutritious ingredients, warm clean studio lighting, vertical 9:16 composition, no text, no watermark";

        try {
            $res = $this->gemini->generateJson(
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                actionType: 'agent3_health_scriptwriter',
                videoProjectId: $project->id,
                temperature: 0.55
            );

            $scenesData = is_array($res['scenes'] ?? null) ? $res['scenes'] : [];
            if (! empty($res['cover_headline'])) {
                $coverHeadline = trim((string) $res['cover_headline']);
            }
            if (! empty($res['thumbnail_visual_prompt'])) {
                $thumbnailPrompt = trim((string) $res['thumbnail_visual_prompt']);
            }
        } catch (Throwable $e) {
            Log::warning('[Agent3HealthScriptwriter] Fallback kịch bản sức khỏe cho Project #' . $project->id . ': ' . $e->getMessage());
            $scenesData = $this->buildFallbackScenes($title, $brief, $channelName);
        }

        if (count($scenesData) < 3) {
            $scenesData = $this->buildFallbackScenes($title, $brief, $channelName);
        }

        $validTransitions = ['fade', 'smoothleft', 'smoothright', 'wipeup', 'dissolve', 'circleopen'];

        DB::transaction(function () use ($project, $scenesData, $validTransitions, $coverHeadline, $thumbnailPrompt): void {
            $project->scenes()->delete();

            foreach (array_values($scenesData) as $idx => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $transition = (string) ($row['transition_type'] ?? $validTransitions[$idx % count($validTransitions)]);
                if (! in_array($transition, $validTransitions, true)) {
                    $transition = 'fade';
                }

                StoryboardScene::query()->create([
                    'video_project_id' => $project->id,
                    'scene_order' => $idx + 1,
                    'narration_text' => trim((string) ($row['narration_text'] ?? '')),
                    'visual_prompt' => trim((string) ($row['visual_prompt'] ?? 'asian healthy lifestyle morning tea')),
                    'sfx_prompt' => trim((string) ($row['sfx_prompt'] ?? 'whoosh')),
                    'camera_motion' => trim((string) ($row['camera_motion'] ?? 'zoom_in')),
                    'transition_type' => $transition,
                    'transition_duration_ms' => 450,
                    'duration_ms' => 6000,
                ]);
            }

            $seoMeta = is_array($project->seo_meta_json) ? $project->seo_meta_json : [];
            $seoMeta['cover_headline'] = $coverHeadline;
            $seoMeta['thumbnail_visual_prompt'] = $thumbnailPrompt;

            $project->update([
                'seo_meta_json' => $seoMeta,
                'master_style_prompt' => '[health_studio_8agents] [asian_stock_video] ' . $thumbnailPrompt,
            ]);
        });

        return $project->refresh()->load('scenes');
    }

    private function loadRuleExcerpt(string $filename): string
    {
        $path = storage_path('app/qa_rules/' . $filename);
        if (is_file($path)) {
            return trim((string) file_get_contents($path));
        }

        return '';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildFallbackScenes(string $title, string $brief, string $channelName): array
    {
        return [
            [
                'narration_text' => "Bạn có đang mắc thói quen nhỏ ảnh hưởng tới sức khỏe mỗi ngày? @{whoosh} Hãy cùng tìm hiểu ngay về {$title}.",
                'visual_prompt' => 'asian woman morning sunlight stretching healthy',
                'transition_type' => 'fade',
                'sfx_prompt' => 'whoosh',
                'camera_motion' => 'zoom_in',
            ],
            [
                'narration_text' => 'Theo các tài liệu y khoa chính thống, nhiều vấn đề sức khỏe bắt nguồn từ nhịp sinh hoạt và chế độ dinh dưỡng chưa cân bằng.',
                'visual_prompt' => 'asian doctor explaining health chart clinic',
                'transition_type' => 'smoothleft',
                'sfx_prompt' => 'pop',
                'camera_motion' => 'pan_right',
            ],
            [
                'narration_text' => ($brief !== '' ? $brief : "Hiểu đúng cơ chế của cơ thể theo cả Đông y và Tây y sẽ giúp bạn chủ động phòng ngừa hiệu quả.") . ' @{ding}',
                'visual_prompt' => 'traditional chinese herbal tea pouring warm cup',
                'transition_type' => 'wipeup',
                'sfx_prompt' => 'ding',
                'camera_motion' => 'zoom_in',
            ],
            [
                'narration_text' => 'Ưu tiên thực phẩm tự nhiên giàu chất xơ, uống đủ nước ấm và duy trì vận động nhẹ nhàng ba mươi phút mỗi ngày.',
                'visual_prompt' => 'asian family cooking healthy vegetables kitchen',
                'transition_type' => 'dissolve',
                'sfx_prompt' => 'pop',
                'camera_motion' => 'zoom_out',
            ],
            [
                'narration_text' => 'Lưu ý lắng nghe cơ thể và tham khảo ý kiến bác sĩ chuyên khoa nếu bạn đang điều trị bệnh nền. @{chime}',
                'visual_prompt' => 'elderly asian couple walking park morning exercise',
                'transition_type' => 'smoothright',
                'sfx_prompt' => 'chime',
                'camera_motion' => 'pan_left',
            ],
            [
                'narration_text' => "Hãy lưu lại video này cho người thân và theo dõi kênh {$channelName} để nhận thêm nhiều kiến thức hữu ích nhé!",
                'visual_prompt' => 'happy asian family smiling outdoor nature',
                'transition_type' => 'fade',
                'sfx_prompt' => 'ding',
                'camera_motion' => 'zoom_in',
            ],
        ];
    }
}
