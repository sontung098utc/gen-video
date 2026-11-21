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
Bạn là Agent AI 3 — Biên kịch Video Ngắn Sức Khỏe giữ chân người xem cực cao cho kênh "{$channelName}" (TikTok, Fanpage Reels, YouTube Shorts).

QUY CHUẨN CHÍNH SÁCH NỀN TẢNG:
{$policyRules}

QUY CHUẨN KỊCH BẢN TRỌNG TÂM — KHÔNG LAN MAN (BẮT BUỘC):
1. **Cảnh 1 (HOOK 0–3 giây đầu — CỰC QUAN TRỌNG)**: Đánh thẳng vào nỗi lo hoặc sự tò mò ngay câu đầu tiên (VD: *"Nhiều người lớn tuổi đang có 5 dấu hiệu tiền tiểu đường này nhưng lại tưởng do tuổi già!"*). TUYỆT ĐỐI KHÔNG chào hỏi dài dòng, không nói chung chung.
2. **ĐÚNG TRỌNG TÂM DẠNG DANH SÁCH (NẾU CÓ)**: Nếu tiêu đề có con số liệt kê (VD: *"5 dấu hiệu..."*, *"7 lợi ích..."*, *"3 sai lầm..."*), BẮT BUỘC các cảnh 2, 3, 4, 5 phải **liệt kê ĐẦY ĐỦ, RÕ RÀNG TỪNG MỤC (Thứ nhất là..., Thứ hai là..., Thứ ba là..., Thứ tư là..., Thứ năm là...)** sát với tiêu đề! KHÔNG được bỏ dở danh sách để nói lan man sang giải pháp chung chung.
3. **Cảnh cuối (Cảnh 6 — CTA + TỪ CHỐI TRÁCH NHIỆM Y KHOA BẮT BUỘC)**: Mời theo dõi kênh {$channelName} VÀ **BẮT BUỘC kết thúc bằng câu từ chối trách nhiệm**: *"Lưu ý: Nội dung chỉ mang tính chất tham khảo, không thay thế chẩn đoán hay chỉ định của bác sĩ."*
4. **Thẻ SFX**: Chỉ dùng tối đa 1–2 thẻ `@{pop}` hoặc `@{ding}` ở điểm nhấn quan trọng nhất trong cả video (KHÔNG dùng `@{whoosh}`).
5. **visual_prompt (TIẾNG ANH 3–5 TỪ)**: PHẢI mô tả cực sát hành động/triệu chứng/thực phẩm của từng cảnh với **người Việt Nam / Trung Quốc** hoặc **cận cảnh y tế/thực phẩm** (VD: người lớn tuổi châu Á mệt mỏi -> `"elderly asian man feeling tired"`, khát nước -> `"elderly asian drinking water"`, tiểu đêm/kiểm tra đường huyết -> `"blood sugar meter glucometer test"`, mờ mắt -> `"elderly asian rubbing eyes"`, tê bì chân tay -> `"elderly person massaging leg"`).

Cấu trúc JSON:
{
  "cover_headline": "TIÊU ĐỀ NGẮN 4-6 TỪ IN HOA GIẬT TÍT",
  "cover_badge_red": "NHÃN ĐỎ 3-5 TỪ (VD: ĐỪNG CHỦ QUAN)",
  "thumbnail_visual_prompt": "Bright sunny close-up studio food/herb/medical photography of [đối tượng chính của bài], vibrant natural colors, crisp detail, no text",
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "Câu thoại Hook cực mạnh, đi thẳng vấn đề...",
      "visual_prompt": "elderly asian person health check",
      "transition_type": "fade",
      "sfx_prompt": "none",
      "camera_motion": "zoom_in"
    }
  ]
}
PROMPT;

        $userPrompt = <<<USER
Hãy viết kịch bản 6 phân cảnh CỰC KỲ SÚC TÍCH, ĐÚNG TRỌNG TÂM, KHÔNG LAN MAN cho video:
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
        $coverBadgeRed = 'KIẾN THỨC HỮU ÍCH';
        $thumbnailPrompt = "Bright sunlit natural photography of fresh green herbs, ginger tea and healthy organic ingredients on clean wooden table, vibrant colors, no text, no watermark";

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
            if (! empty($res['cover_badge_red'])) {
                $coverBadgeRed = trim((string) $res['cover_badge_red']);
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

        DB::transaction(function () use ($project, $scenesData, $validTransitions, $coverHeadline, $coverBadgeRed, $thumbnailPrompt): void {
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
                    'visual_prompt' => trim((string) ($row['visual_prompt'] ?? 'chinese herbal tea warm cup')),
                    'sfx_prompt' => trim((string) ($row['sfx_prompt'] ?? 'none')),
                    'camera_motion' => trim((string) ($row['camera_motion'] ?? 'zoom_in')),
                    'transition_type' => $transition,
                    'transition_duration_ms' => 450,
                    'duration_ms' => 6000,
                ]);
            }

            $seoMeta = is_array($project->seo_meta_json) ? $project->seo_meta_json : [];
            $seoMeta['cover_headline'] = $coverHeadline;
            $seoMeta['cover_badge_red'] = $coverBadgeRed;
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
                'narration_text' => "Hãy lưu lại và theo dõi kênh {$channelName}. Lưu ý: Nội dung chỉ mang tính chất tham khảo, không thay thế chẩn đoán hay chỉ định của bác sĩ.",
                'visual_prompt' => 'happy asian family smiling outdoor nature',
                'transition_type' => 'fade',
                'sfx_prompt' => 'none',
                'camera_motion' => 'zoom_in',
            ],
        ];
    }
}
