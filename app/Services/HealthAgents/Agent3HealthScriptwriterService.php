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

QUY CHUẨN KỊCH BẢN CHUYÊN SÂU — MANG LẠI GIÁ TRỊ THẬT CHO NGƯỜI XEM (BẮT BUỘC):
1. **Độ dài & Số phân cảnh (7 đến 9 phân cảnh — tổng thời lượng ~50–70 giây)**:
   - KHÔNG BAO GIỜ gộp 2 ý/2 dấu hiệu vào cùng 1 cảnh ngắn cụt lủn! Nếu tiêu đề có 5 dấu hiệu (hoặc 5 sai lầm/lợi ích), hãy dành **5 phân cảnh riêng biệt** cho từng mục (Cảnh 2 → Cảnh 6), cộng thêm Cảnh 1 (Hook), Cảnh 7 (Giá trị thực hành / Chỉ số cần biết), Cảnh 8 (Kết + Xét nghiệm + Từ chối trách nhiệm).
2. **Mỗi phân cảnh nội dung PHẢI ĐẠT ĐỦ 3 TẦNG GIÁ TRỊ (28–40 từ/cảnh)**:
   - **Tầng 1 (Cái gì):** Nêu rõ dấu hiệu/nguyên tắc + **ngưỡng con số cụ thể** (VD: *"đi tiểu đêm từ 2 lần trở lên"*, *"vết trầy xước nhỏ hơn 1cm nhưng hơn 2 tuần chưa lành"*).
   - **Tầng 2 (TẠI SAO — Cơ chế y khoa dễ hiểu):** Giải thích ngắn gọn cơ chế Tây y hoặc Đông y để người xem hiểu bản chất và thấy kiến thức thực sự giá trị (VD: *"Vì đường huyết cao kéo nước từ mô tế bào vào máu khiến thận phải tăng tốc đào thải"*, hoặc *"Tế bào không hấp thụ được đường để tạo năng lượng nên dù ăn đủ vẫn rã rời"*).
   - **Tầng 3 (Phân biệt thực tế):** Chỉ ra cách phân biệt với thói quen/tuổi già thông thường.
3. **Cảnh 1 (HOOK 3–4 giây đầu)**: Nêu nghịch lý hoặc con số/sự thật khiến người xem phải dừng lại nghe tiếp.
4. **Cảnh áp chót (Giá trị thực hành / Chỉ số y khoa chuẩn)**: Cung cấp kiến thức cụ thể để người xem áp dụng ngay (VD: *"Đừng đoán qua cảm giác — hãy kiểm tra đường huyết lúc đói (ngưỡng tiền tiểu đường 5,6 đến 6,9 mmol/L) hoặc chỉ số HbA1c"*).
5. **Cảnh cuối (CTA + TỪ CHỐI TRÁCH NHIỆM BẮT BUỘC)**:
   - Nhấn mạnh: *"Những biểu hiện này không đủ để kết luận bệnh — đừng chờ có triệu chứng mới đi kiểm tra định kỳ."*
   - Mời theo dõi kênh {$channelName} và kết thúc bằng: *"Nội dung chỉ mang tính tham khảo, không thay thế chẩn đoán của bác sĩ."*
6. **Thẻ SFX**: Chỉ dùng tối đa 1–2 thẻ `@{pop}` hoặc `@{ding}` ở điểm nhấn quan trọng nhất trong cả video (KHÔNG dùng `@{whoosh}`).
7. **visual_prompt (TIẾNG ANH 4–6 TỪ — CỤ THỂ HÀNH ĐỘNG & KHÔNG LẶP GÓC MÁY)**:
   - PHẢI mô tả HÀNH ĐỘNG CỤ THỂ khớp 100% lời thoại từng cảnh với **người Việt Nam / Trung Quốc** hoặc **cận cảnh y tế/thực phẩm**; các cảnh liên tiếp PHẢI khác nhau về hành động và góc quay.

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
Hãy viết kịch bản 7–9 phân cảnh CHUYÊN SÂU, CÓ CƠ CHẾ Y KHOA (TẠI SAO), CON SỐ CỤ THỂ VÀ GIÁ TRỊ THỰC TIỄN CAO cho video:
- Tiêu đề video: {$title}
- Góc tiếp cận: {$angle}
- Nội dung trọng tâm: {$brief}

Tài liệu chính thống từ Kho Kiến Thức:
{$kbContext}
{$feedbackSection}
Hãy trả về JSON chuẩn (mỗi cảnh nội dung 28–40 từ, không gộp 2 ý vào 1 cảnh ngắn).
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
