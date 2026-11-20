<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\HealthContentPlan;
use App\Models\HealthPlanItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AGENT AI 1 — GIÁM ĐỐC KẾ HOẠCH NỘI DUNG (Content Planner).
 *
 * Nhận: Số lượng bài/ngày + Danh sách chủ đề + Kho tài liệu y khoa chính thống.
 * Xuất: Kế hoạch nội dung phân cấp Tháng -> Tuần -> Ngày cho kênh "Sống Khỏe Mỗi Ngày".
 */
class Agent1ContentPlannerService
{
    public function __construct(
        private readonly HealthGeminiClient $gemini,
        private readonly HealthKnowledgeBaseService $knowledgeBase
    ) {}

    /**
     * Xây dựng kế hoạch nội dung hoàn chỉnh theo Tháng -> Tuần -> Ngày.
     *
     * @param  array<int, string>  $topics
     */
    public function buildMonthlyPlan(
        string $name,
        int $month,
        int $year,
        int $postsPerDay,
        int $totalDays,
        array $topics
    ): HealthContentPlan {
        $postsPerDay = max(1, min(5, $postsPerDay));
        $totalDays = max(1, min(31, $totalDays));
        $cleanTopics = array_values(array_filter(array_map('trim', $topics), static fn (string $t): bool => $t !== ''));
        if ($cleanTopics === []) {
            $cleanTopics = ['Dinh dưỡng lành mạnh', 'Dưỡng sinh Đông y', 'Phòng ngừa bệnh mạn tính', 'Giấc ngủ & Tinh thần'];
        }

        $channelName = AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày');
        $kbContext = $this->knowledgeBase->retrieveRelevantContext(implode(' ', $cleanTopics), 2500);
        $totalSlots = $totalDays * $postsPerDay;

        $systemPrompt = <<<PROMPT
Bạn là Agent AI 1 — Giám đốc Chiến lược Nội dung Sức khỏe cho kênh "{$channelName}" (TikTok, Fanpage Reels, YouTube Shorts).
Nhiệm vụ: Lập kế hoạch nội dung phân cấp Tháng -> Tuần -> Ngày dựa trên nguồn kiến thức chính thống (Đông y, Tây y, Khoa học, Y tế).
Nguyên tắc bắt buộc:
- Tuyệt đối KHÔNG hứa hẹn chữa khỏi bệnh 100%, không dùng từ "thần dược", tuân thủ chính sách y tế TikTok/Facebook/YouTube.
- Phân bổ xen kẽ hài hòa các góc tiếp cận: `dong_y`, `tay_y`, `khoa_hoc`, `ket_hop`.
- Mỗi bài viết phải có `topic` cụ thể, `angle`, và `brief` ngắn gọn (1-2 câu nêu giá trị hữu ích và cơ sở khoa học).
- Trả về đúng cấu trúc JSON:
{
  "plan_summary": "Tóm tắt chiến lược tháng (2-3 câu)",
  "items": [
    {
      "day_number": 1,
      "slot_index": 1,
      "topic": "Chủ đề cụ thể ngắn gọn",
      "angle": "dong_y|tay_y|khoa_hoc|ket_hop",
      "brief": "Ý chính hữu ích theo Đông y / Tây y / Khoa học"
    }
  ]
}
PROMPT;

        $topicsText = implode(', ', $cleanTopics);
        $userPrompt = <<<USER
Hãy lập kế hoạch nội dung tháng {$month}/{$year} cho kênh "{$channelName}":
- Số ngày lập kế hoạch: {$totalDays} ngày (từ Ngày 1 đến Ngày {$totalDays})
- Số bài/ngày: {$postsPerDay} bài/ngày (Tổng cộng đúng {$totalSlots} bài)
- Các chủ đề trọng tâm người dùng yêu cầu: {$topicsText}

Tài liệu tham khảo chính thống từ Kho Kiến Thức:
{$kbContext}

Hãy trả về JSON chứa đúng {$totalSlots} mục trong mảng `items`, sắp xếp lần lượt từ day_number 1 đến {$totalDays}.
USER;

        $planSummary = "Kế hoạch nội dung tháng {$month}/{$year} cho kênh {$channelName} ({$postsPerDay} bài/ngày × {$totalDays} ngày).";
        $generatedItems = [];

        try {
            $result = $this->gemini->generateJson(
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                actionType: 'agent1_content_planner',
                temperature: 0.65
            );
            $planSummary = trim((string) ($result['plan_summary'] ?? $planSummary));
            $rawItems = is_array($result['items'] ?? null) ? $result['items'] : [];
            $generatedItems = $this->normalizeItems($rawItems, $totalDays, $postsPerDay, $cleanTopics);
        } catch (Throwable $e) {
            Log::warning('[Agent1ContentPlanner] Dùng kế hoạch dự phòng thông minh: ' . $e->getMessage());
            $generatedItems = $this->buildFallbackItems($totalDays, $postsPerDay, $cleanTopics);
        }

        return DB::transaction(function () use (
            $name,
            $channelName,
            $month,
            $year,
            $postsPerDay,
            $totalDays,
            $cleanTopics,
            $planSummary,
            $generatedItems
        ): HealthContentPlan {
            $plan = HealthContentPlan::query()->create([
                'name' => trim($name) !== '' ? trim($name) : "Kế hoạch Tháng {$month}/{$year} — {$channelName}",
                'channel_name' => $channelName,
                'month' => $month,
                'year' => $year,
                'posts_per_day' => $postsPerDay,
                'total_days' => $totalDays,
                'topics_json' => $cleanTopics,
                'plan_summary' => $planSummary,
                'status' => 'planned',
            ]);

            $baseDate = Carbon::create($year, $month, 1);

            foreach ($generatedItems as $row) {
                $dayNum = (int) $row['day_number'];
                $weekNum = (int) ceil($dayNum / 7);
                $scheduledDate = $baseDate->copy()->addDays($dayNum - 1);

                HealthPlanItem::query()->create([
                    'plan_id' => $plan->id,
                    'week_number' => $weekNum,
                    'day_number' => $dayNum,
                    'scheduled_date' => $scheduledDate->toDateString(),
                    'slot_index' => (int) $row['slot_index'],
                    'topic' => (string) $row['topic'],
                    'angle' => (string) $row['angle'],
                    'brief' => (string) $row['brief'],
                    'status' => 'planned',
                ]);
            }

            return $plan->load('items');
        });
    }

    /**
     * Đảm bảo đủ chính xác `$totalDays * $postsPerDay` bài viết ngay cả khi AI trả thiếu.
     *
     * @param  array<int, mixed>  $rawItems
     * @param  array<int, string>  $topics
     * @return array<int, array{day_number: int, slot_index: int, topic: string, angle: string, brief: string}>
     */
    private function normalizeItems(array $rawItems, int $totalDays, int $postsPerDay, array $topics): array
    {
        $validAngles = ['dong_y', 'tay_y', 'khoa_hoc', 'ket_hop'];
        $indexed = [];

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $day = max(1, min($totalDays, (int) ($item['day_number'] ?? 1)));
            $slot = max(1, min($postsPerDay, (int) ($item['slot_index'] ?? 1)));
            $key = "{$day}_{$slot}";
            if (isset($indexed[$key])) {
                continue;
            }

            $angle = (string) ($item['angle'] ?? 'ket_hop');
            if (! in_array($angle, $validAngles, true)) {
                $angle = 'ket_hop';
            }

            $indexed[$key] = [
                'day_number' => $day,
                'slot_index' => $slot,
                'topic' => trim((string) ($item['topic'] ?? $topics[($day + $slot) % count($topics)])),
                'angle' => $angle,
                'brief' => trim((string) ($item['brief'] ?? 'Chia sẻ kiến thức chăm sóc sức khỏe chuẩn khoa học, dễ áp dụng hằng ngày.')),
            ];
        }

        $fallback = $this->buildFallbackItems($totalDays, $postsPerDay, $topics);
        $final = [];

        foreach ($fallback as $fb) {
            $key = "{$fb['day_number']}_{$fb['slot_index']}";
            $final[] = $indexed[$key] ?? $fb;
        }

        return $final;
    }

    /**
     * @param  array<int, string>  $topics
     * @return array<int, array{day_number: int, slot_index: int, topic: string, angle: string, brief: string}>
     */
    private function buildFallbackItems(int $totalDays, int $postsPerDay, array $topics): array
    {
        $angles = ['dong_y', 'tay_y', 'khoa_hoc', 'ket_hop'];
        $templates = [
            'Thói quen buổi sáng tốt cho %s theo khuyến nghị y khoa',
            '3 thực phẩm quen thuộc hỗ trợ %s mà nhiều gia đình hay bỏ qua',
            'Sai lầm phổ biến khi chăm sóc %s và cách điều chỉnh an toàn',
            'Góc nhìn Đông - Tây y kết hợp giúp cải thiện %s bền vững',
            'Lưu ý quan trọng buổi tối để bảo vệ %s mỗi ngày',
        ];

        $items = [];
        $counter = 0;

        for ($day = 1; $day <= $totalDays; $day++) {
            for ($slot = 1; $slot <= $postsPerDay; $slot++) {
                $topicBase = $topics[$counter % count($topics)];
                $angle = $angles[$counter % count($angles)];
                $tpl = $templates[$counter % count($templates)];

                $items[] = [
                    'day_number' => $day,
                    'slot_index' => $slot,
                    'topic' => sprintf($tpl, $topicBase),
                    'angle' => $angle,
                    'brief' => "Kiến thức sức khỏe chuẩn y khoa về {$topicBase}, trình bày dễ hiểu và dễ áp dụng ngay tại nhà.",
                ];
                $counter++;
            }
        }

        return $items;
    }
}
