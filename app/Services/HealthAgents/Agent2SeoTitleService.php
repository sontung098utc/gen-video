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
Bạn là Agent AI 2 — Chuyên gia SEO Video Ngắn Sức Khỏe trên 3 nền tảng TikTok, Facebook Reels và YouTube Shorts cho kênh "{$channelName}".
Nguyên tắc bắt buộc:
- Viết tiếng Việt chuẩn chính tả 100%, không sai dấu, không giật tít sai sự thật.
- TUYỆT ĐỐI KHÔNG dùng các từ vi phạm chính sách y tế: "chữa khỏi 100%", "thần dược", "dứt điểm hoàn toàn", "bỏ thuốc tây".
- `cover_headline` PHẢI rất ngắn gọn (4 đến 7 từ), mạnh mẽ, dễ đọc to rõ trên ảnh bìa điện thoại.
- Trả về đúng JSON:
{
  "seo_title_tiktok": "Tiêu đề cuốn hút chuẩn TikTok (<= 90 ký tự)",
  "seo_title_reels": "Tiêu đề gần gũi, dễ chia sẻ cho gia đình trên Fanpage Reels (<= 100 ký tự)",
  "seo_title_shorts": "Tiêu đề chuẩn từ khóa tìm kiếm YouTube Shorts (<= 85 ký tự)",
  "cover_headline": "TIÊU ĐỀ NGẮN 4-7 TỪ CHO ẢNH BÌA",
  "seo_description": "Mô tả 2-3 câu chuẩn SEO kèm lời nhắc tham khảo chuyên gia y tế.",
  "hashtags": ["#songkhoemoingay", "#suckhoe", "#dinhduong", "#dongy", "#meosuckhoe"]
}
PROMPT;

        $userPrompt = <<<USER
Hãy sản xuất bộ tiêu đề chuẩn SEO 3 nền tảng cho bài viết sau:
- Chủ đề: {$item->topic}
- Góc tiếp cận: {$item->angleLabel()}
- Tóm tắt ý chính: {$item->brief}
{$feedbackNote}
USER;

        try {
            $res = $this->gemini->generateJson(
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                actionType: 'agent2_seo_title',
                videoProjectId: $item->video_project_id,
                temperature: 0.55
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
