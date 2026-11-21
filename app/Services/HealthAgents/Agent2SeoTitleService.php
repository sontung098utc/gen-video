<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\HealthContentPlan;
use App\Models\HealthPlanItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * AGENT AI 2 — CHUYÊN GIA TIÊU ĐỀ CHUẨN SEO 3 NỀN TẢNG (SEO Title Producer).
 *
 * Từ từng mục kế hoạch của Agent 1 -> Sản xuất Tiêu đề chuẩn SEO cho:
 *  - TikTok (`seo_title_tiktok`)
 *  - Fanpage Reels (`seo_title_reels`)
 *  - YouTube Shorts (`seo_title_shorts`)
 *  - Tiêu đề ngắn 4-7 từ in lên Ảnh bìa Photographic (`cover_headline`)
 *  - Mô tả chuẩn SEO + Hashtags.
 */
class Agent2SeoTitleService
{
    public function __construct(
        private readonly HealthGeminiClient $gemini
    ) {}

    /**
     * Sinh tiêu đề chuẩn SEO 3 nền tảng cho một mục bài viết cụ thể.
     */
    public function generateForItem(HealthPlanItem $item, ?string $qaFeedback = null): HealthPlanItem
    {
        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');
        $feedbackNote = $qaFeedback ? "\nLƯU Ý BẮT BUỘC SỬA TỪ TRƯỞNG PHÒNG AGENT 8:\n{$qaFeedback}\n" : '';

        $systemPrompt = <<<PROMPT
Bạn là Agent AI 2 — Chuyên gia đặt Tiêu đề Video Ngắn Sức Khỏe có tỷ lệ nhấp (CTR) cực cao cho kênh "{$channelName}" trên TikTok, Facebook Reels và YouTube Shorts.

CÔNG THỨC ĐẶT TIÊU ĐỀ GIẬT TÍT HIỆU QUẢ (BẮT BUỘC ÁP DỤNG 1 TRONG CÁC MẪU SAU):
1. Con số + Sai lầm / Cảnh báo: "5 SAI LẦM KHI... NGƯỜI BỆNH HAY MẮC", "3 DẤU HIỆU... ĐỪNG CHỦ QUAN", "4 TÍN HIỆU... ĐỪNG BỎ QUA"
2. Con số + Lợi ích / Thực phẩm cụ thể: "7 LỢI ÍCH CỦA...", "7 LOẠI RAU... NÊN BIẾT", "9 LÝ DO NÊN ĂN..."
3. Câu hỏi phản biện gây tò mò: "[TÊN THỰC PHẨM] CÓ THỰC SỰ...?", "10 LOẠI NƯỚC UỐNG NHIỀU HẠI THẬN?", "BỮA PHỤ NÊN CÓ GÌ?"

NGUYÊN TẮC BẮT BUỘC:
- Đánh trúng nỗi lo hoặc sự tò mò của người Việt (đường huyết, thận, gan, giấc ngủ, xương khớp, dưỡng sinh).
- TUYỆT ĐỐI KHÔNG dùng từ vi phạm chính sách y tế ("chữa khỏi 100%", "thần dược", "dứt điểm hoàn toàn", "bỏ thuốc tây").
- `cover_headline`: 4–7 từ IN HOA ngắn gọn, cực mạnh để in chữ vàng to lên ảnh bìa.
- `cover_badge_red`: 3–5 từ ngắn gọn cho dải ruy-băng đỏ trên ảnh bìa (VD: "ĐỪNG CHỦ QUAN", "ĐÚNG LOẠI • ĐÚNG CÁCH", "NGƯỜI BỆNH HAY MẮC").
- Trả về đúng JSON:
{
  "seo_title_tiktok": "Tiêu đề cuốn hút, gây tò mò mạnh (<= 85 ký tự)",
  "seo_title_reels": "Tiêu đề đánh trúng tâm lý gia đình trên Fanpage Reels (<= 95 ký tự)",
  "seo_title_shorts": "Tiêu đề chuẩn từ khóa tìm kiếm YouTube Shorts (<= 85 ký tự)",
  "cover_headline": "5 SAI LẦM KHI...",
  "cover_badge_red": "ĐỪNG BỎ QUA",
  "seo_description": "Mô tả 2 câu tóm tắt giá trị + lời nhắc tham khảo bác sĩ.",
  "hashtags": ["#songkhoemoingay", "#suckhoe", "#dongy", "#dinhduong", "#meosuckhoe"]
}
PROMPT;

        $userPrompt = <<<USER
Hãy đặt bộ tiêu đề giật tít chuẩn SEO 3 nền tảng cho chủ đề sau:
- Chủ đề gốc: {$item->topic}
- Góc tiếp cận: {$item->angleLabel()}
- Ý chính: {$item->brief}
{$feedbackNote}
USER;

        try {
            $res = $this->gemini->generateJson(
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                actionType: 'agent2_seo_title',
                videoProjectId: $item->video_project_id,
                temperature: 0.7
            );

            $item->update([
                'seo_title_tiktok' => Str::limit(trim((string) ($res['seo_title_tiktok'] ?? $item->topic)), 240, ''),
                'seo_title_reels' => Str::limit(trim((string) ($res['seo_title_reels'] ?? $item->topic)), 240, ''),
                'seo_title_shorts' => Str::limit(trim((string) ($res['seo_title_shorts'] ?? $item->topic)), 240, ''),
                'cover_headline' => Str::limit(trim((string) ($res['cover_headline'] ?? Str::words($item->topic, 6, ''))), 140, ''),
                'seo_description' => trim((string) ($res['seo_description'] ?? $item->brief)),
                'hashtags_json' => is_array($res['hashtags'] ?? null)
                    ? array_values(array_slice(array_map('strval', $res['hashtags']), 0, 8))
                    : ['#songkhoemoingay', '#suckhoe', '#dinhduong', '#meosuckhoe'],
                'status' => $item->video_project_id ? $item->status : 'titled',
            ]);
        } catch (Throwable $e) {
            Log::warning('[Agent2SeoTitle] Fallback tiêu đề SEO cho item #' . $item->id . ': ' . $e->getMessage());
            $shortCover = Str::words($item->topic, 6, '');
            $item->update([
                'seo_title_tiktok' => $item->topic . ' — Bạn đã biết chưa?',
                'seo_title_reels' => 'Sống Khỏe Mỗi Ngày: ' . $item->topic,
                'seo_title_shorts' => $item->topic . ' | Kiến thức sức khỏe chuẩn',
                'cover_headline' => mb_strtoupper($shortCover, 'UTF-8'),
                'seo_description' => ($item->brief ?: $item->topic) . ' Theo dõi Sống Khỏe Mỗi Ngày để cập nhật kiến thức y khoa hữu ích mỗi ngày.',
                'hashtags_json' => ['#songkhoemoingay', '#suckhoe', '#dinhduong', '#dongy', '#suckhoegiadinh'],
                'status' => $item->video_project_id ? $item->status : 'titled',
            ]);
        }

        return $item->refresh();
    }

    /**
     * Sinh bộ tiêu đề SEO giật tít cho một VideoProject tạo nhanh (không xuất phát từ HealthPlanItem).
     */
    public function generateForProject(\App\Models\VideoProject $project): \App\Models\VideoProject
    {
        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');

        $systemPrompt = <<<PROMPT
Bạn là Agent AI 2 — Chuyên gia đặt Tiêu đề Video Ngắn Sức Khỏe có tỷ lệ nhấp (CTR) cực cao cho kênh "{$channelName}" trên TikTok, Facebook Reels và YouTube Shorts.

NHIỆM VỤ:
Từ chủ đề thô người dùng nhập, KHÔNG ĐƯỢC copy y nguyên câu thô — hãy biến tấu thành tiêu đề chuẩn SEO giật tít tự nhiên, đánh trúng tâm lý tò mò/lo lắng của người xem Việt Nam (vẫn tuân thủ chính sách y tế, không nói "chữa khỏi 100%", "thần dược").
- `seo_title_tiktok`: Hấp dẫn, gây tò mò mạnh, có từ khóa nổi bật (<= 85 ký tự).
- `seo_title_reels`: Gần gũi, kích thích chia sẻ cho gia đình trên Fanpage Reels (<= 95 ký tự).
- `seo_title_shorts`: Chuẩn từ khóa tìm kiếm YouTube Shorts (<= 85 ký tự).
- `cover_headline`: Cực ngắn 4–7 từ IN HOA để in chữ to lên ảnh bìa (VD: "5 DẤU HIỆU TIỀN TIỂU ĐƯỜNG").
- `cover_badge_red`: 3–5 từ ngắn gọn cho ruy-băng đỏ (VD: "NGƯỜI LỚN TUỔI CẦN BIẾT", "ĐỪNG CHỦ QUAN").
- Trả về đúng JSON:
{
  "seo_title_tiktok": "...",
  "seo_title_reels": "...",
  "seo_title_shorts": "...",
  "cover_headline": "...",
  "cover_badge_red": "...",
  "seo_description": "...",
  "hashtags": ["#songkhoemoingay", "#suckhoe", "#tieuduong", "#meosuckhoe"]
}
PROMPT;

        $userPrompt = "Chủ đề người dùng nhập: {$project->title}\nGhi chú thêm: {$project->topic_or_brief}";

        try {
            $res = $this->gemini->generateJson(
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                actionType: 'agent2_seo_title_project',
                videoProjectId: $project->id,
                temperature: 0.7
            );

            $seoMeta = is_array($project->seo_meta_json) ? $project->seo_meta_json : [];
            $seoMeta['seo_title_tiktok'] = Str::limit(trim((string) ($res['seo_title_tiktok'] ?? $project->title)), 240, '');
            $seoMeta['seo_title_reels'] = Str::limit(trim((string) ($res['seo_title_reels'] ?? $project->title)), 240, '');
            $seoMeta['seo_title_shorts'] = Str::limit(trim((string) ($res['seo_title_shorts'] ?? $project->title)), 240, '');
            $seoMeta['cover_headline'] = Str::limit(trim((string) ($res['cover_headline'] ?? Str::words($project->title, 6, ''))), 120, '');
            $seoMeta['cover_badge_red'] = Str::limit(trim((string) ($res['cover_badge_red'] ?? 'ĐỪNG CHỦ QUAN')), 80, '');
            $seoMeta['seo_description'] = trim((string) ($res['seo_description'] ?? $project->topic_or_brief));
            $seoMeta['hashtags'] = is_array($res['hashtags'] ?? null)
                ? array_values(array_slice(array_map('strval', $res['hashtags']), 0, 8))
                : ['#songkhoemoingay', '#suckhoe', '#meosuckhoe'];
            $seoMeta['agent2_optimized'] = true;

            $project->update([
                'title' => $seoMeta['seo_title_tiktok'],
                'seo_meta_json' => $seoMeta,
            ]);
        } catch (Throwable $e) {
            Log::warning('[Agent2SeoTitle] Fallback SEO project #' . $project->id . ': ' . $e->getMessage());
        }

        return $project->refresh();
    }

    /**
     * Chạy Agent 2 hàng loạt cho các mục chưa có tiêu đề trong một Kế hoạch (giới hạn theo batch tránh quá tải).
     */
    public function generateBatchForPlan(HealthContentPlan $plan, int $limit = 10): int
    {
        $items = $plan->items()
            ->where(function ($q): void {
                $q->whereNull('seo_title_tiktok')->orWhere('seo_title_tiktok', '');
            })
            ->limit(max(1, min(31, $limit)))
            ->get();

        $count = 0;
        foreach ($items as $item) {
            $this->generateForItem($item);
            $count++;
        }

        if ($plan->items()->whereNull('seo_title_tiktok')->count() === 0) {
            $plan->update(['status' => 'titled']);
        }

        return $count;
    }
}
