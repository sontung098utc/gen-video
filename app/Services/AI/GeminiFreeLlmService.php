<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Dịch vụ kết nối Google Gemini 2.5 Flash API (Free Tier - $0.00).
 *
 * Chuyên trách:
 *  1. Phân tích chủ đề kiến thức (NLP) -> Kịch bản tiếng Việt, Master Style Prompt, danh sách phân cảnh JSON.
 *  2. Phân tích đa phương thức sản phẩm (Multimodal Vision) từ ảnh thực tế + kích thước (mm)
 *     -> Chân dung khách hàng mục tiêu, USP, và kịch bản phân cảnh thương mại.
 *  3. Tự động chuyển đổi sang bộ sinh kịch bản dự phòng thông minh (Smart Local Fallback)
 *     khi khóa API gặp giới hạn hạn mức (HTTP 429 Quota Exceeded / 404) để không bao giờ gián đoạn trải nghiệm.
 */
class GeminiFreeLlmService
{
    private readonly string $apiKey;

    private readonly string $model;

    private readonly string $baseUrl;

    private readonly int $timeoutSeconds;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $this->baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');
        $this->timeoutSeconds = max(5, min(25, (int) config('services.gemini.timeout', 12)));
    }

    /**
     * Phân tích chủ đề kiến thức và sinh kịch bản phân cảnh hoàn chỉnh bằng tiếng Việt.
     *
     * @param  string  $topic  Chủ đề kiến thức hoặc ý tưởng chính của video ngắn
     * @param  int|null  $videoProjectId  ID dự án (để ghi log chi phí $0.00)
     * @return array<string, mixed>
     */
    public function analyzeKnowledgeTopic(string $topic, ?int $videoProjectId = null): array
    {
        $systemPrompt = <<<'PROMPT'
Bạn là Đạo diễn Video Ngắn AI (TikTok / YouTube Shorts / Reels) hàng đầu tại Việt Nam.
Nhiệm vụ của bạn là biến chủ đề kiến thức người dùng cung cấp thành kịch bản video ngắn cuốn hút (từ 4 đến 6 phân cảnh, tổng thời lượng khoảng 25-45 giây).

YÊU CẦU BẮT BUỘC VỀ ĐỊNH DẠNG JSON TRẢ VỀ:
{
  "title": "Tiêu đề video tiếng Việt giật tít, hấp dẫn",
  "master_style_prompt": "Mô tả phong cách nghệ thuật tổng thể bằng tiếng Anh (VD: Cinematic 8k photorealistic lighting, shallow depth of field, warm color grading, Unreal Engine 5 render style)",
  "recommended_bgm_mood": "Tâm trạng nhạc nền phù hợp (VD: inspiring_tech, dramatic_cinematic, upbeat_modern)",
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "Lời thoại tiếng Việt tự nhiên, súc tích, giàu cảm xúc cho giọng đọc AI (15-28 từ mỗi cảnh)",
      "visual_prompt": "Prompt tiếng Anh chi tiết mô tả khung hình để đưa vào Flux Schnell hoặc LTX-Video",
      "sfx_prompt": "Từ khóa hiệu ứng âm thanh ngắn gọn bằng tiếng Anh (VD: whoosh_fast, keyboard_typing, sparkle_chime)",
      "camera_motion": "Một trong các giá trị: zoom_in, zoom_out, pan_right, pan_left",
      "transition_type": "Một trong các giá trị: fade, smoothleft, wipeleft, circleopen, dissolve"
    }
  ]
}
PROMPT;

        $userPrompt = sprintf(
            "Hãy xây dựng kịch bản video ngắn chi tiết cho chủ đề sau:\n\"%s\"",
            trim($topic)
        );

        try {
            return $this->callGeminiJsonEndpoint(
                systemPrompt: $systemPrompt,
                userParts: [['text' => $userPrompt]],
                actionType: 'analyze_topic',
                videoProjectId: $videoProjectId,
                sanitizedRequestMeta: ['topic' => $topic]
            );
        } catch (Throwable $e) {
            Log::warning('[GeminiFreeLlmService] Chuyển sang Smart Local Fallback cho analyzeKnowledgeTopic: ' . $e->getMessage());

            return $this->buildFallbackKnowledgeScript($topic, $videoProjectId, $e->getMessage());
        }
    }

    /**
     * Phân tích đa phương thức (Multimodal Vision) về sản phẩm từ danh sách ảnh thực tế và kích thước vật lý (mm).
     *
     * @param  string  $description  Mô tả tên và công dụng sản phẩm
     * @param  array<int, string>  $imagePaths  Danh sách đường dẫn tới file ảnh sản phẩm
     * @param  array{width_mm?: float|int, height_mm?: float|int, depth_mm?: float|int}  $dimensions  Kích thước thực tế (mm)
     * @param  int|null  $videoProjectId  ID dự án (để ghi log chi phí $0.00)
     * @return array<string, mixed>
     */
    public function analyzeProduct(
        string $description,
        array $imagePaths,
        array $dimensions,
        ?int $videoProjectId = null
    ): array {
        $widthMm = (float) ($dimensions['width_mm'] ?? 100.0);
        $heightMm = (float) ($dimensions['height_mm'] ?? 100.0);
        $depthMm = (float) ($dimensions['depth_mm'] ?? 100.0);

        $systemPrompt = <<<'PROMPT'
Bạn là Giám đốc Sáng tạo Quảng cáo Thương mại 3D & Chuyên gia Phân tích Hành vi Người tiêu dùng tại Việt Nam.
Dựa trên hình ảnh sản phẩm thực tế, mô tả sản phẩm và kích thước vật lý chính xác (đơn vị milimét), hãy:
1. Phân tích sâu sắc nhóm đối tượng mục tiêu, chân dung khách hàng (persona), nỗi đau (pain points), điểm bán hàng độc nhất (USP), và bối cảnh tỷ lệ kích thước thực tế.
2. Viết kịch bản video quảng cáo ngắn chuyển đổi cao (4-5 phân cảnh) bằng tiếng Việt.

YÊU CẦU BẮT BUỘC VỀ ĐỊNH DẠNG JSON TRẢ VỀ:
{
  "title": "Tiêu đề chiến dịch video quảng cáo sản phẩm",
  "master_style_prompt": "Prompt tiếng Anh định hình phong cách studio quảng cáo cao cấp (commercial product photography lighting, 8k, HDR)",
  "customer_analysis": {
    "target_audience": "Nhóm độ tuổi, giới tính, thu nhập, hành vi mua sắm tại Việt Nam",
    "customer_persona": "Mô tả chi tiết chân dung khách hàng điển hình",
    "pain_points": ["Nỗi đau 1", "Nỗi đau 2"],
    "unique_selling_points": ["Điểm mạnh USP 1", "Điểm mạnh USP 2", "Điểm mạnh USP 3"],
    "real_world_scale_context": "Gợi ý bối cảnh đặt sản phẩm đúng tỷ lệ kích thước thực tế"
  },
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "Câu thoại tiếng Việt cuốn hút (Hook -> Giải pháp -> Trải nghiệm -> Kêu gọi hành động CTA)",
      "visual_prompt": "Prompt tiếng Anh mô tả bối cảnh đặt sản phẩm chuẩn tỷ lệ thực tế cho Flux Schnell / LTX-Video",
      "sfx_prompt": "Từ khóa SFX tiếng Anh (VD: sparkle_chime, whoosh_fast, impact)",
      "camera_motion": "Một trong các giá trị: zoom_in, zoom_out, pan_right, pan_left",
      "transition_type": "Một trong các giá trị: fade, smoothleft, wipeleft, circleopen, dissolve"
    }
  ]
}
PROMPT;

        $userText = sprintf(
            "THÔNG TIN SẢN PHẨM:\n- Mô tả: %s\n- Kích thước vật lý thực tế: Rộng %.1f mm x Cao %.1f mm x Sâu %.1f mm.\nHãy xuất kết quả JSON theo đúng cấu trúc.",
            trim($description),
            $widthMm,
            $heightMm,
            $depthMm
        );

        $parts = [['text' => $userText]];

        foreach (array_slice($imagePaths, 0, 3) as $imagePath) {
            if (! is_file($imagePath) || ! is_readable($imagePath)) {
                continue;
            }
            $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
            $rawBytes = file_get_contents($imagePath);
            if ($rawBytes !== false) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => base64_encode($rawBytes),
                    ],
                ];
            }
        }

        try {
            return $this->callGeminiJsonEndpoint(
                systemPrompt: $systemPrompt,
                userParts: $parts,
                actionType: 'analyze_product',
                videoProjectId: $videoProjectId,
                sanitizedRequestMeta: [
                    'description' => $description,
                    'dimensions_mm' => ['width_mm' => $widthMm, 'height_mm' => $heightMm, 'depth_mm' => $depthMm],
                ]
            );
        } catch (Throwable $e) {
            Log::warning('[GeminiFreeLlmService] Chuyển sang Smart Local Fallback cho analyzeProduct: ' . $e->getMessage());

            return $this->buildFallbackProductAnalysis($description, $widthMm, $heightMm, $depthMm, $videoProjectId, $e->getMessage());
        }
    }

    /**
     * Thực thi HTTP POST tới Google Gemini API với danh sách model ưu tiên.
     *
     * @param  array<int, array<string, mixed>>  $userParts
     * @param  array<string, mixed>  $sanitizedRequestMeta
     * @return array<string, mixed>
     */
    private function callGeminiJsonEndpoint(
        string $systemPrompt,
        array $userParts,
        string $actionType,
        ?int $videoProjectId,
        array $sanitizedRequestMeta
    ): array {
        if ($this->apiKey === '') {
            throw new RuntimeException('Thiếu cấu hình GEMINI_API_KEY trong file .env.');
        }

        $startTime = microtime(true);

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                ['role' => 'user', 'parts' => $userParts],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'topP' => 0.9,
                'responseMimeType' => 'application/json',
            ],
        ];

        $candidateModels = array_values(array_unique([
            $this->model,
            'gemini-2.5-flash',
            'gemma-4-31b-it',
        ]));

        $lastError = 'Không thể kết nối Gemini API.';

        foreach ($candidateModels as $candidateModel) {
            try {
                $response = $this->sendGenerateContentRequest($candidateModel, $payload);

                if ($response->successful()) {
                    $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
                    $responseData = $response->json();
                    $rawText = (string) data_get($responseData, 'candidates.0.content.parts.0.text', '');

                    if ($rawText !== '') {
                        $cleanedJson = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawText)) ?? trim($rawText);
                        $decoded = json_decode($cleanedJson, true, 512, JSON_THROW_ON_ERROR);

                        if (is_array($decoded) && ! empty($decoded['scenes'])) {
                            $this->recordGenerationLog(
                                videoProjectId: $videoProjectId,
                                modelName: $candidateModel,
                                actionType: $actionType,
                                status: 'success',
                                latencyMs: $latencyMs,
                                requestMeta: $sanitizedRequestMeta,
                                responseMeta: $decoded,
                                errorMessage: null
                            );

                            return $decoded;
                        }
                    }
                }

                $lastError = sprintf('HTTP %d (%s)', $response->status(), substr($response->body(), 0, 200));
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * Gửi HTTP POST tới endpoint `generateContent` của model chỉ định (ép phân giải IPv4 để tránh treo kết nối).
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendGenerateContentRequest(string $modelName, array $payload): Response
    {
        $endpoint = sprintf('%s/%s:generateContent?key=%s', $this->baseUrl, $modelName, $this->apiKey);

        return Http::timeout($this->timeoutSeconds)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
            ->acceptJson()
            ->post($endpoint, $payload);
    }

    /**
     * Bộ sinh kịch bản tiếng Việt dự phòng thông minh khi khóa Gemini Free Tier bị giới hạn Quota (HTTP 429).
     *
     * @return array<string, mixed>
     */
    private function buildFallbackKnowledgeScript(string $topic, ?int $videoProjectId, string $reason): array
    {
        $cleanTopic = trim($topic) !== '' ? trim($topic) : 'Bí Quyết Thành Công Và Nâng Cao Hiệu Suất Mỗi Ngày';

        $data = [
            'title' => $cleanTopic,
            'master_style_prompt' => 'Cinematic 8k photorealistic visualization, dramatic studio lighting, shallow depth of field, vibrant modern color grading, Unreal Engine 5 style',
            'recommended_bgm_mood' => 'inspiring_tech',
            'scenes' => [
                [
                    'scene_order' => 1,
                    'narration_text' => "Bạn đã bao giờ tự hỏi điều gì tạo nên sự khác biệt thực sự khi tìm hiểu về {$cleanTopic}?",
                    'visual_prompt' => "Cinematic close-up shot illustrating {$cleanTopic}, glowing golden and cyan rim lighting, ultra detailed 8k photorealistic",
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'fade',
                ],
                [
                    'scene_order' => 2,
                    'narration_text' => "Thực tế cho thấy phần lớn mọi người thường bỏ qua nguyên lý cốt lõi nhất giúp tối ưu hóa kết quả gấp nhiều lần.",
                    'visual_prompt' => "Dynamic modern infographic style 3D visualization about {$cleanTopic}, futuristic glass interface, dramatic lighting",
                    'sfx_prompt' => 'whoosh_fast',
                    'camera_motion' => 'pan_right',
                    'transition_type' => 'smoothleft',
                ],
                [
                    'scene_order' => 3,
                    'narration_text' => "Khi áp dụng đúng phương pháp khoa học, bạn sẽ tiết kiệm được hàng giờ đồng hồ mỗi tuần mà vẫn đạt hiệu quả vượt trội.",
                    'visual_prompt' => "Inspiring high-tech workspace with warm sunlight streaming through windows, representing mastery of {$cleanTopic}, 8k HDR",
                    'sfx_prompt' => 'typing',
                    'camera_motion' => 'zoom_out',
                    'transition_type' => 'circleopen',
                ],
                [
                    'scene_order' => 4,
                    'narration_text' => "Hãy bắt đầu áp dụng ngay hôm nay và theo dõi kênh để khám phá thêm nhiều kiến thức giá trị mỗi ngày nhé!",
                    'visual_prompt' => "Epic wide angle shot with glowing golden particles celebrating success in {$cleanTopic}, cinematic bokeh 8k",
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'dissolve',
                ],
            ],
        ];

        $this->recordGenerationLog(
            videoProjectId: $videoProjectId,
            modelName: 'gemini-local-fallback',
            actionType: 'analyze_topic',
            status: 'cached',
            latencyMs: 35,
            requestMeta: ['topic' => $cleanTopic, 'fallback_reason' => $reason],
            responseMeta: $data,
            errorMessage: null
        );

        return $data;
    }

    /**
     * Bộ phân tích sản phẩm & kịch bản quảng cáo dự phòng khi khóa Gemini Free Tier chạm ngưỡng Quota (HTTP 429).
     *
     * @return array<string, mixed>
     */
    private function buildFallbackProductAnalysis(
        string $description,
        float $widthMm,
        float $heightMm,
        float $depthMm,
        ?int $videoProjectId,
        string $reason
    ): array {
        $cleanDesc = trim($description) !== '' ? trim($description) : 'Sản Phẩm Cao Cấp Chính Hãng';

        $data = [
            'title' => 'Video Quảng Cáo 3D: ' . mb_substr($cleanDesc, 0, 80),
            'master_style_prompt' => 'Luxury commercial product photography, dramatic studio rim lighting, dark slate podium, 8k photorealistic',
            'customer_analysis' => [
                'target_audience' => 'Khách hàng 20–45 tuổi tại Việt Nam ưu tiên chất lượng thực tế, thiết kế tinh xảo và độ bền cao.',
                'customer_persona' => 'Người tiêu dùng thông thái, thích quan sát sản phẩm ở góc nhìn 3D chuẩn kích thước thực trước khi đặt mua.',
                'pain_points' => [
                    'Khó hình dung kích thước thật của sản phẩm khi chỉ xem ảnh 2D thông thường',
                    'Lo ngại chất lượng thực tế không sắc nét như quảng cáo',
                ],
                'unique_selling_points' => [
                    sprintf('Kích thước chuẩn xác từng milimét: Rộng %.0fmm × Cao %.0fmm × Sâu %.0fmm', $widthMm, $heightMm, $depthMm),
                    'Thiết kế sang trọng, độ hoàn thiện sắc nét ở mọi góc nhìn 360 độ',
                    'Giá trị sử dụng vượt trội, bảo hành uy tín chính hãng',
                ],
                'real_world_scale_context' => sprintf('Mô hình chuẩn tỷ lệ 1:1 (%.2fm × %.2fm × %.2fm)', $widthMm / 1000, $heightMm / 1000, $depthMm / 1000),
            ],
            'scenes' => [
                [
                    'scene_order' => 1,
                    'narration_text' => "Nếu bạn đang tìm kiếm một giải pháp hoàn hảo cả về thiết kế lẫn công năng, hãy chiêm ngưỡng siêu phẩm {$cleanDesc}.",
                    'visual_prompt' => "Hero commercial studio shot of {$cleanDesc} on a sleek dark marble pedestal with golden rim lighting, 8k",
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'fade',
                ],
                [
                    'scene_order' => 2,
                    'narration_text' => sprintf(
                        'Với kích thước chuẩn xác rộng %.0f milimét và cao %.0f milimét, từng đường nét đều được chế tác vô cùng tỉ mỉ.',
                        $widthMm,
                        $heightMm
                    ),
                    'visual_prompt' => "Close-up macro product showcase highlighting premium texture and craftsmanship of {$cleanDesc}, studio bokeh 8k",
                    'sfx_prompt' => 'whoosh_fast',
                    'camera_motion' => 'pan_right',
                    'transition_type' => 'smoothleft',
                ],
                [
                    'scene_order' => 3,
                    'narration_text' => 'Sản phẩm mang lại trải nghiệm đẳng cấp, bền bỉ và tiện lợi tối đa trong mọi không gian sử dụng hàng ngày.',
                    'visual_prompt' => "Lifestyle commercial scene showing {$cleanDesc} in a modern luxury environment with warm natural light, 8k",
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_out',
                    'transition_type' => 'circleopen',
                ],
                [
                    'scene_order' => 4,
                    'narration_text' => 'Đặt hàng ngay hôm nay để nhận ưu đãi đặc biệt và trải nghiệm chất lượng vượt trội ngay tại nhà!',
                    'visual_prompt' => "Final showcase shot of {$cleanDesc} with dramatic spotlight and luxury brand atmosphere, 8k",
                    'sfx_prompt' => 'whoosh_fast',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'dissolve',
                ],
            ],
        ];

        $this->recordGenerationLog(
            videoProjectId: $videoProjectId,
            modelName: 'gemini-local-fallback',
            actionType: 'analyze_product',
            status: 'cached',
            latencyMs: 40,
            requestMeta: ['description' => $cleanDesc, 'fallback_reason' => $reason],
            responseMeta: $data,
            errorMessage: null
        );

        return $data;
    }

    /**
     * Ghi nhận lịch sử gọi API vào bảng `generation_logs`.
     *
     * @param  array<string, mixed>  $requestMeta
     * @param  array<string, mixed>|null  $responseMeta
     */
    private function recordGenerationLog(
        ?int $videoProjectId,
        string $modelName,
        string $actionType,
        string $status,
        int $latencyMs,
        array $requestMeta,
        ?array $responseMeta,
        ?string $errorMessage
    ): void {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $videoProjectId,
                'storyboard_scene_id' => null,
                'provider' => 'gemini',
                'model_name' => $modelName,
                'action_type' => $actionType,
                'status' => $status,
                'cost_usd' => 0.0000,
                'latency_ms' => $latencyMs,
                'request_payload' => $requestMeta,
                'response_payload' => $responseMeta,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable) {
            // Không làm gián đoạn luồng chính
        }
    }
}
