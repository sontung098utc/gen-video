<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Hệ Thống Đa Tác Tử AI 4 Vai Trò (Multi-Agent Video Production Team) chạy trên Google Gemini API ($0.00):
 *
 *  - AI #2 (`Scriptwriter & Marketing Expert`): Chuyên gia nội dung chuyên sâu + Marketing viral (Hook 3s, Pain Point, USP, CTA).
 *  - AI #3 (`Visual & Stock Prompt Director`): Chuyên gia tạo Prompt ảnh/video AI & từ khóa tìm kiếm Video Stock Pexels/Pixabay.
 *  - AI #4 (`Post-Production & Effects Director`): Đạo diễn dựng phim, nhịp cắt xfade, chuyển động camera, SFX & word-anchor tags.
 *  - AI #1 (`Manager / QA Reviewer`): Quản lý cấp cao kiểm duyệt toàn bộ kịch bản, loại bỏ văn mẫu sáo rỗng, chuẩn hóa đầu ra cuối cùng.
 */
class MultiAgentVideoTeamService
{
    private readonly string $apiKey;

    private readonly string $model;

    private readonly string $baseUrl;

    private readonly int $timeoutSeconds;

    public function __construct()
    {
        $this->apiKey = trim((string) config('services.gemini.api_key', ''));
        $this->model = (string) config('services.gemini.model', 'gemini-3.5-flash-lite');
        $this->baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');
        $this->timeoutSeconds = max(15, min(90, (int) config('services.gemini.timeout', 45)));
    }

    /**
     * Điều phối 4 vai trò AI phối hợp sản xuất kịch bản & phân cảnh hoàn chỉnh cho Video Kiến Thức hoặc Video Sản Phẩm.
     *
     * @param  array<int, string>  $imagePaths
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    public function produceWithFourAgents(
        string $mode,
        string $topicOrDescription,
        array $imagePaths = [],
        array $dimensions = [],
        ?int $videoProjectId = null
    ): array {
        if ($this->apiKey === '') {
            throw new RuntimeException('Thiếu GEMINI_API_KEY trong file .env. Vui lòng cập nhật khóa Gemini API hợp lệ.');
        }

        // ------------------------------------------------------------------
        // VAI TRÒ 2: AI CHUYÊN GIA KỊCH BẢN & MARKETING (SCRIPTWRITER)
        // ------------------------------------------------------------------
        $scriptDraft = $this->runScriptwriterAgent(
            mode: $mode,
            topicOrDescription: $topicOrDescription,
            imagePaths: $imagePaths,
            dimensions: $dimensions,
            videoProjectId: $videoProjectId
        );

        // ------------------------------------------------------------------
        // VAI TRÒ 3: AI ĐẠO DIỄN HÌNH ẢNH, PROMPT & TÌM VIDEO STOCK
        // ------------------------------------------------------------------
        $visualPlan = $this->runVisualDirectorAgent(
            mode: $mode,
            scriptDraft: $scriptDraft,
            videoProjectId: $videoProjectId
        );

        // ------------------------------------------------------------------
        // VAI TRÒ 4: AI ĐẠO DIỄN DỰNG PHIM & HIỆU ỨNG (POST-PRODUCTION)
        // ------------------------------------------------------------------
        $editedPlan = $this->runEditorEffectsAgent(
            visualPlan: $visualPlan,
            videoProjectId: $videoProjectId
        );

        // ------------------------------------------------------------------
        // VAI TRÒ 1: AI QUẢN LÝ (MANAGER / QA REVIEWER) DUYỆT TOÀN BỘ
        // ------------------------------------------------------------------
        return $this->runManagerQaAgent(
            mode: $mode,
            topicOrDescription: $topicOrDescription,
            editedPlan: $editedPlan,
            videoProjectId: $videoProjectId
        );
    }

    /**
     * AI #2: Chuyên gia chuyên môn + Marketing viết kịch bản TikTok/Reels thực chiến (Hook - Insight - Giải pháp - CTA).
     *
     * @param  array<int, string>  $imagePaths
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    private function runScriptwriterAgent(
        string $mode,
        string $topicOrDescription,
        array $imagePaths,
        array $dimensions,
        ?int $videoProjectId
    ): array {
        $isProduct = $mode === 'product';

        $systemPrompt = <<<'PROMPT'
Bạn là AI #2 — CHUYÊN GIA KỊCH BẢN & MARKETING VIDEO NGẮN HÀNG ĐẦU VIỆT NAM.
Bạn sở hữu kiến thức chuyên môn sâu (y khoa, công nghệ, làm đẹp, tiêu dùng) kết hợp tư duy Direct-Response Marketing (AIDA / PAS).

NGUYÊN TẮC BẮT BUỘC KHI VIẾT KỊCH BẢN:
1. CẤM TUYỆT ĐỐI các câu văn mẫu AI sáo rỗng như: "Bạn đã bao giờ tự hỏi...", "Thực tế cho thấy phần lớn...", "Khi áp dụng đúng phương pháp khoa học...", "Hãy theo dõi kênh để khám phá thêm...".
2. Cảnh 1 (HOOK 3 giây đầu): Phải đánh thẳng vào nỗi đau, sự thật gây sốc, con số cụ thể hoặc cảnh báo khẩn cấp khiến người xem PHẢI dừng ngón tay lại (VD: "Nếu sáng ngủ dậy thấy 3 dấu hiệu này ở chân, đường huyết của bạn đang báo động đỏ!").
3. Các cảnh giữa (Giá trị chuyên sâu): Nêu rõ cơ chế/triệu chứng/USP cụ thể, ngôn ngữ đời thường dễ hiểu như bác sĩ/chuyên gia đang nói chuyện trực tiếp với người xem ("cô bác", "bạn").
4. Cảnh cuối (CTA mạnh mẽ): Kêu gọi hành động rõ ràng, đánh vào lợi ích tức thì (lưu video, kiểm tra ngay, đặt mua/nhận ưu đãi).
5. Chia thành 4 đến 6 phân cảnh, mỗi cảnh 16-28 từ tiếng Việt tự nhiên, đọc lên trôi chảy trong 4-7 giây.

TRẢ VỀ JSON:
{
  "title": "Tiêu đề video giật tít, đúng trọng tâm",
  "hook_strategy": "Giải thích ngắn gọn tại sao Hook này giữ chân người xem",
  "customer_analysis": {
    "target_audience": "Đối tượng người xem mục tiêu",
    "customer_persona": "Chân dung & tâm lý người xem",
    "pain_points": ["Nỗi đau 1", "Nỗi đau 2"],
    "unique_selling_points": ["Giá trị cốt lõi 1", "Giá trị cốt lõi 2"],
    "real_world_scale_context": "Bối cảnh thực tế"
  },
  "scenes": [
    {
      "scene_order": 1,
      "role_in_funnel": "hook | pain_point | solution | proof | cta",
      "narration_text": "Lời thoại tiếng Việt sắc bén, chuyên sâu, KHÔNG sáo rỗng"
    }
  ]
}
PROMPT;

        $userText = $isProduct
            ? sprintf(
                "Loại video: PR SẢN PHẨM.\nThông tin sản phẩm: %s\nKích thước thực tế: %.1fmm x %.1fmm x %.1fmm.\nHãy viết kịch bản bán hàng chuyển đổi cao.",
                trim($topicOrDescription),
                (float) ($dimensions['width_mm'] ?? 100),
                (float) ($dimensions['height_mm'] ?? 100),
                (float) ($dimensions['depth_mm'] ?? 100)
            )
            : sprintf(
                "Loại video: KIẾN THỨC HỮU ÍCH CHUYÊN SÂU.\nChủ đề: \"%s\"\nHãy viết kịch bản có chiều sâu chuyên môn thực tế, Hook sắc bén và CTA rõ ràng.",
                trim($topicOrDescription)
            );

        $parts = [['text' => $userText]];
        foreach (array_slice($imagePaths, 0, 2) as $imgPath) {
            if (is_file($imgPath) && is_readable($imgPath)) {
                $bytes = @file_get_contents($imgPath);
                if ($bytes !== false && $bytes !== '') {
                    $parts[] = [
                        'inline_data' => [
                            'mime_type' => mime_content_type($imgPath) ?: 'image/jpeg',
                            'data' => base64_encode($bytes),
                        ],
                    ];
                }
            }
        }

        return $this->invokeRoleJson($systemPrompt, $parts, 'ai_role_scriptwriter', $videoProjectId);
    }

    /**
     * AI #3: Đạo diễn Hình ảnh — tạo Prompt tách lớp (`prompt_kit`) + từ khóa tìm kiếm Video Stock (`stock_search_terms`).
     *
     * @param  array<string, mixed>  $scriptDraft
     * @return array<string, mixed>
     */
    private function runVisualDirectorAgent(string $mode, array $scriptDraft, ?int $videoProjectId): array
    {
        $systemPrompt = <<<'PROMPT'
Bạn là AI #3 — ĐẠO DIỄN HÌNH ẢNH & TẠO PROMPT (VISUAL & STOCK DIRECTOR).
Nhiệm vụ: Nhận kịch bản từ AI #2 và thiết kế hình ảnh minh họa SÁT NGHĨA NHẤT với từng câu thoại tiếng Việt.

QUY TẮC:
1. Tạo `master_style_prompt` bằng tiếng Anh định hình phong cách đồng nhất toàn video.
2. Với mỗi phân cảnh trong `scenes`:
   - `stock_search_terms`: Đúng 2-3 từ khóa tiếng Anh CỤ THỂ, ĐỜI THƯỜNG để tìm Video Stock thật trên Pexels/Pixabay (VD: "elderly man drinking water", "doctor checking blood sugar", "senior foot pain"). TUYỆT ĐỐI không dùng từ trừu tượng như "conceptual 3D".
   - `prompt_kit`: Tách 3 lớp (`subject_anchor`, `shot_composition`, `lighting_setting`) mô tả cảnh quay chân thực để tạo ảnh AI/video AI.
   - `visual_prompt`: Ghép hoàn chỉnh bằng tiếng Anh (bắt đầu bằng từ khóa mô tả cảnh quay + chi tiết).

GIỮ NGUYÊN `title`, `customer_analysis`, `scenes.*.scene_order`, `scenes.*.narration_text` và bổ sung các trường hình ảnh vào JSON:
{
  "title": "...",
  "master_style_prompt": "...",
  "customer_analysis": { ... },
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "...",
      "stock_search_terms": "senior doctor blood test",
      "prompt_kit": {
        "subject_anchor": "...",
        "shot_composition": "...",
        "lighting_setting": "..."
      },
      "visual_prompt": "..."
    }
  ]
}
PROMPT;

        $userPrompt = "KỊCH BẢN TỪ AI #2:\n" . json_encode($scriptDraft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->invokeRoleJson($systemPrompt, [['text' => $userPrompt]], 'ai_role_visual_director', $videoProjectId);
    }

    /**
     * AI #4: Đạo diễn Dựng phim & Hiệu ứng — thiết kế nhịp chuyển cảnh `xfade`, chuyển động camera, SFX và Word-Anchor tags.
     *
     * @param  array<string, mixed>  $visualPlan
     * @return array<string, mixed>
     */
    private function runEditorEffectsAgent(array $visualPlan, ?int $videoProjectId): array
    {
        $systemPrompt = <<<'PROMPT'
Bạn là AI #4 — ĐẠO DIỄN DỰNG PHIM & HIỆU ỨNG (POST-PRODUCTION DIRECTOR).
Nhiệm vụ: Nhận kịch bản + hình ảnh từ AI #3 và hoàn thiện lớp dựng phim hậu kỳ:
1. Chọn `recommended_bgm_mood` phù hợp (`inspiring_tech`, `dramatic_cinematic`, `upbeat_modern`, `warm_healthcare`).
2. Với mỗi cảnh:
   - Chèn 1 thẻ neo từ khóa (`@{pop}`, `@{whoosh}`, hoặc `@{sparkle}`) ngay trước từ khóa đắt giá nhất trong `narration_text` (giữ nguyên nội dung câu thoại, chỉ chèn thẻ).
   - `sfx_prompt`: chọn từ khóa SFX phù hợp (`whoosh_fast`, `sparkle_chime`, `pop`, `impact`, `typing`).
   - `camera_motion`: chọn nhịp chuyển động hợp lý giữa các cảnh (`zoom_in`, `zoom_out`, `pan_right`, `pan_left`).
   - `transition_type`: chọn hiệu ứng chuyển cảnh FFmpeg (`fade`, `smoothleft`, `wipeleft`, `circleopen`, `dissolve`).

Trả về JSON giữ nguyên các trường cũ và bổ sung đầy đủ các trường hậu kỳ trên.
PROMPT;

        $userPrompt = "KẾ HOẠCH HÌNH ẢNH TỪ AI #3:\n" . json_encode($visualPlan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->invokeRoleJson($systemPrompt, [['text' => $userPrompt]], 'ai_role_editor_director', $videoProjectId);
    }

    /**
     * AI #1: Quản lý Cấp Cao (Manager / QA Reviewer) — kiểm duyệt chéo toàn bộ quy trình, sửa lỗi sáo rỗng và xuất bản JSON cuối.
     *
     * @param  array<string, mixed>  $editedPlan
     * @return array<string, mixed>
     */
    private function runManagerQaAgent(
        string $mode,
        string $topicOrDescription,
        array $editedPlan,
        ?int $videoProjectId
    ): array {
        $systemPrompt = <<<'PROMPT'
Bạn là AI #1 — GIÁM ĐỐC QUẢN LÝ CHẤT LƯỢNG (EXECUTIVE MANAGER & QA REVIEWER).
Nhiệm vụ: Kiểm duyệt lần cuối toàn bộ sản phẩm của AI #2 (Kịch bản), AI #3 (Hình ảnh/Stock) và AI #4 (Dựng phim/Hiệu ứng) cho chủ đề người dùng yêu cầu.

TIÊU CHÍ DUYỆT KHẮT KHE:
1. Cảnh #1 PHẢI là một Hook thực sự gây chú ý ngay 3 giây đầu; Cảnh cuối PHẢI có CTA rõ ràng.
2. Nếu phát hiện bất kỳ câu thoại nào sáo rỗng, chung chung, không đúng chuyên môn -> VIẾT LẠI cho sắc bén và tự nhiên hơn.
3. Đảm bảo `visual_prompt` và `stock_search_terms` khớp 100% với lời thoại của từng cảnh; đảm bảo mọi trường JSON (`title`, `master_style_prompt`, `recommended_bgm_mood`, `scenes` với `scene_order`, `narration_text`, `visual_prompt`, `stock_search_terms`, `sfx_prompt`, `camera_motion`, `transition_type`) đều hợp lệ.

Xuất JSON cuối cùng đã được phê duyệt.
PROMPT;

        $userPrompt = sprintf(
            "CHỦ ĐỀ/SẢN PHẨM GỐC CỦA KHÁCH HÀNG (%s): \"%s\"\n\nBẢN DỰ THẢO ĐẦY ĐỦ TỪ TEAM (AI #2 + #3 + #4):\n%s",
            strtoupper($mode),
            $topicOrDescription,
            json_encode($editedPlan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $approved = $this->invokeRoleJson($systemPrompt, [['text' => $userPrompt]], 'ai_role_manager_qa', $videoProjectId);

        // Đảm bảo trường visual_prompt luôn ưu tiên ghép thêm stock_search_terms để Pexels tìm chính xác
        if (! empty($approved['scenes']) && is_array($approved['scenes'])) {
            foreach ($approved['scenes'] as $idx => $scene) {
                $terms = trim((string) ($scene['stock_search_terms'] ?? ''));
                $visual = trim((string) ($scene['visual_prompt'] ?? ''));
                if ($terms !== '' && ! str_contains(strtolower($visual), strtolower($terms))) {
                    $approved['scenes'][$idx]['visual_prompt'] = "{$terms}, {$visual}";
                }
            }
        }

        return $approved;
    }

    /**
     * Gọi Google Gemini API cho từng vai trò AI và ghi log riêng vào `generation_logs`.
     *
     * @param  array<int, array<string, mixed>>  $userParts
     * @return array<string, mixed>
     */
    private function invokeRoleJson(
        string $systemPrompt,
        array $userParts,
        string $roleActionType,
        ?int $videoProjectId
    ): array {
        $startTime = microtime(true);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => $userParts],
            ],
            'generationConfig' => [
                'temperature' => 0.55,
                'topP' => 0.9,
                'responseMimeType' => 'application/json',
            ],
        ];

        $candidateModels = array_values(array_unique([
            $this->model,
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'gemini-3.8-flash',
        ]));

        $lastError = 'Không thể kết nối Gemini API.';

        foreach ($candidateModels as $candidateModel) {
            try {
                $endpoint = sprintf('%s/%s:generateContent?key=%s', $this->baseUrl, $candidateModel, $this->apiKey);
                /** @var Response $response */
                $response = Http::timeout($this->timeoutSeconds)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->acceptJson()
                    ->post($endpoint, $payload);

                if ($response->successful()) {
                    $rawText = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
                    if ($rawText !== '') {
                        $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawText)) ?? trim($rawText);
                        $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $this->recordRoleLog(
                                videoProjectId: $videoProjectId,
                                modelName: $candidateModel,
                                actionType: $roleActionType,
                                status: 'success',
                                latencyMs: (int) round((microtime(true) - $startTime) * 1000),
                                errorMessage: null
                            );

                            return $decoded;
                        }
                    }
                }

                $status = $response->status();
                $body = $response->body();
                $lastError = sprintf('HTTP %d: %s', $status, substr($body, 0, 260));

                // Nếu lỗi xác thực (401/403) do API key bị vô hiệu hóa thì dừng ngay để báo người dùng
                if (in_array($status, [401, 403], true)) {
                    break;
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $this->recordRoleLog(
            videoProjectId: $videoProjectId,
            modelName: $this->model,
            actionType: $roleActionType,
            status: 'failed',
            latencyMs: (int) round((microtime(true) - $startTime) * 1000),
            errorMessage: $lastError
        );

        throw new RuntimeException(sprintf('[%s] Lỗi gọi Gemini API: %s', $roleActionType, $lastError));
    }

    private function recordRoleLog(
        ?int $videoProjectId,
        string $modelName,
        string $actionType,
        string $status,
        int $latencyMs,
        ?string $errorMessage
    ): void {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $videoProjectId,
                'storyboard_scene_id' => null,
                'provider' => 'gemini_team',
                'model_name' => $modelName,
                'action_type' => $actionType,
                'status' => $status,
                'cost_usd' => 0.0000,
                'latency_ms' => $latencyMs,
                'request_payload' => ['role' => $actionType],
                'response_payload' => null,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable) {
            // Không làm gián đoạn luồng chính
        }
    }
}
