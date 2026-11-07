<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Dịch vụ kết nối Google Gemini 2.5 Flash API (Free Tier - $0.00).
 *
 * Chuyên trách:
 *  1. Phân tích chủ đề kiến thức (NLP) -> Kịch bản tiếng Việt, Master Style Prompt, danh sách phân cảnh JSON.
 *  2. Phân tích đa phương thức sản phẩm (Multimodal Vision) từ ảnh thực tế + kích thước (mm)
 *     -> Chân dung khách hàng mục tiêu, USP, và kịch bản phân cảnh thương mại.
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
        $this->timeoutSeconds = (int) config('services.gemini.timeout', 90);
    }

    /**
     * Phân tích chủ đề kiến thức và sinh kịch bản phân cảnh hoàn chỉnh bằng tiếng Việt.
     *
     * @param  string  $topic  Chủ đề kiến thức hoặc ý tưởng chính của video ngắn
     * @param  int|null  $videoProjectId  ID dự án (để ghi log chi phí $0.00)
     * @return array{
     *     title: string,
     *     master_style_prompt: string,
     *     recommended_bgm_mood: string,
     *     scenes: array<int, array{
     *         scene_order: int,
     *         narration_text: string,
     *         visual_prompt: string,
     *         sfx_prompt: string,
     *         camera_motion: string,
     *         transition_type: string
     *     }>
     * }
     */
    public function analyzeKnowledgeTopic(string $topic, ?int $videoProjectId = null): array
    {
        $systemPrompt = <<<'PROMPT'
Bạn là Đạo diễn Video Ngắn AI (TikTok / YouTube Shorts / Reels) hàng đầu tại Việt Nam.
Nhiệm vụ của bạn là biến chủ đề kiến thức người dùng cung cấp thành kịch bản video ngắn cuốn hút (từ 5 đến 8 phân cảnh, tổng thời lượng khoảng 30-50 giây).

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
      "sfx_prompt": "Từ khóa hiệu ứng âm thanh ngắn gọn bằng tiếng Anh (VD: whoosh_fast, keyboard_typing, cinematic_hit)",
      "camera_motion": "Một trong các giá trị: zoom_in, zoom_out, pan_right, pan_left",
      "transition_type": "Một trong các giá trị: fade, smoothleft, smoothright, circleopen, dissolve"
    }
  ]
}
PROMPT;

        $userPrompt = sprintf(
            "Hãy xây dựng kịch bản video ngắn chi tiết cho chủ đề sau:\n\"%s\"",
            trim($topic)
        );

        $parts = [
            ['text' => $userPrompt],
        ];

        return $this->callGeminiJsonEndpoint(
            systemPrompt: $systemPrompt,
            userParts: $parts,
            actionType: 'analyze_topic',
            videoProjectId: $videoProjectId,
            sanitizedRequestMeta: ['topic' => $topic]
        );
    }

    /**
     * Phân tích đa phương thức (Multimodal Vision) về sản phẩm từ danh sách ảnh thực tế và kích thước vật lý (mm).
     *
     * @param  string  $description  Mô tả tên và công dụng sản phẩm
     * @param  array<int, string>  $imagePaths  Danh sách đường dẫn tuyệt đối hoặc tương đối tới file ảnh sản phẩm
     * @param  array{width_mm?: float|int, height_mm?: float|int, depth_mm?: float|int}  $dimensions  Kích thước thực tế (mm)
     * @param  int|null  $videoProjectId  ID dự án (để ghi log chi phí $0.00)
     * @return array{
     *     title: string,
     *     master_style_prompt: string,
     *     customer_analysis: array{
     *         target_audience: string,
     *         customer_persona: string,
     *         pain_points: array<int, string>,
     *         unique_selling_points: array<int, string>,
     *         real_world_scale_context: string
     *     },
     *     scenes: array<int, array{
     *         scene_order: int,
     *         narration_text: string,
     *         visual_prompt: string,
     *         sfx_prompt: string,
     *         camera_motion: string,
     *         transition_type: string
     *     }>
     * }
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
1. Phân tích sâu sắc nhóm đối tượng mục tiêu, chân dung khách hàng (persona), nỗi đau (pain points), điểm bán hàng độc nhất (USP), và bối cảnh tỷ lệ kích thước thực tế (để khi ghép mô hình 3D .glb vào bối cảnh không bị sai lệch tỷ lệ).
2. Viết kịch bản video quảng cáo ngắn chuyển đổi cao (5-7 phân cảnh) bằng tiếng Việt.

YÊU CẦU BẮT BUỘC VỀ ĐỊNH DẠNG JSON TRẢ VỀ:
{
  "title": "Tiêu đề chiến dịch video quảng cáo sản phẩm",
  "master_style_prompt": "Prompt tiếng Anh định hình phong cách studio quảng cáo cao cấp (commercial product photography lighting, 8k, HDR)",
  "customer_analysis": {
    "target_audience": "Nhóm độ tuổi, giới tính, thu nhập, hành vi mua sắm tại Việt Nam",
    "customer_persona": "Mô tả chi tiết chân dung khách hàng điển hình",
    "pain_points": ["Nỗi đau 1", "Nỗi đau 2", "Nỗi đau 3"],
    "unique_selling_points": ["Điểm mạnh USP 1", "Điểm mạnh USP 2", "Điểm mạnh USP 3"],
    "real_world_scale_context": "Gợi ý bối cảnh đặt sản phẩm đúng tỷ lệ kích thước thực tế (VD: đặt gọn trong lòng bàn tay, đặt trên bàn làm việc cạnh laptop 14 inch)"
  },
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "Câu thoại tiếng Việt cuốn hút (Hook -> Giải pháp -> Trải nghiệm -> Kêu gọi hành động CTA)",
      "visual_prompt": "Prompt tiếng Anh mô tả bối cảnh đặt sản phẩm chuẩn tỷ lệ thực tế cho Flux Schnell / LTX-Video",
      "sfx_prompt": "Từ khóa SFX tiếng Anh (VD: sparkle_chime, whoosh_transition, bass_drop)",
      "camera_motion": "Một trong các giá trị: zoom_in, zoom_out, pan_right, pan_left",
      "transition_type": "Một trong các giá trị: fade, smoothleft, smoothright, circleopen, dissolve"
    }
  ]
}
PROMPT;

        $userText = sprintf(
            "THÔNG TIN SẢN PHẨM:\n- Mô tả: %s\n- Kích thước vật lý thực tế: Rộng %.1f mm x Cao %.1f mm x Sâu %.1f mm (tương đương %.2f m x %.2f m x %.2f m).\nHãy quan sát kỹ các hình ảnh đính kèm và xuất kết quả JSON theo đúng cấu trúc.",
            trim($description),
            $widthMm,
            $heightMm,
            $depthMm,
            $widthMm / 1000.0,
            $heightMm / 1000.0,
            $depthMm / 1000.0
        );

        $parts = [
            ['text' => $userText],
        ];

        // Đính kèm tối đa 4 ảnh sản phẩm dưới dạng inline_data base64 cho Gemini 2.5 Flash Vision
        foreach (array_slice($imagePaths, 0, 4) as $imagePath) {
            if (! is_file($imagePath) || ! is_readable($imagePath)) {
                continue;
            }

            $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
            $rawBytes = file_get_contents($imagePath);
            if ($rawBytes === false) {
                continue;
            }

            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data' => base64_encode($rawBytes),
                ],
            ];
        }

        return $this->callGeminiJsonEndpoint(
            systemPrompt: $systemPrompt,
            userParts: $parts,
            actionType: 'analyze_product',
            videoProjectId: $videoProjectId,
            sanitizedRequestMeta: [
                'description' => $description,
                'dimensions_mm' => [
                    'width_mm' => $widthMm,
                    'height_mm' => $heightMm,
                    'depth_mm' => $depthMm,
                ],
                'image_count' => count($imagePaths),
            ]
        );
    }

    /**
     * Hàm lõi thực thi HTTP POST tới Google Gemini 2.5 Flash API kèm ép kiểu JSON và ghi log chi phí $0.00.
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

        $endpoint = sprintf('%s/%s:generateContent?key=%s', $this->baseUrl, $this->model, $this->apiKey);
        $startTime = microtime(true);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $userParts,
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'topP' => 0.9,
                'responseMimeType' => 'application/json',
            ],
        ];

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->retry(3, 3000, function (Throwable $exception): bool {
                    // Tự động retry khi gặp lỗi mạng hoặc Rate Limit 429 của Free Tier
                    if ($exception instanceof RequestException && $exception->response !== null) {
                        return in_array($exception->response->status(), [429, 500, 502, 503, 504], true);
                    }

                    return true;
                })
                ->acceptJson()
                ->post($endpoint, $payload);

            $response->throw();

            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
            $responseData = $response->json();

            $rawText = (string) data_get($responseData, 'candidates.0.content.parts.0.text', '');
            if ($rawText === '') {
                throw new RuntimeException('Gemini 2.5 Flash trả về phản hồi rỗng.');
            }

            // Làm sạch trường hợp chuỗi vẫn bọc trong ```json ... ```
            $cleanedJson = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($rawText)) ?? trim($rawText);
            $decoded = json_decode($cleanedJson, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new RuntimeException('Dữ liệu JSON từ Gemini 2.5 Flash không phải là mảng hợp lệ.');
            }

            $this->recordGenerationLog(
                videoProjectId: $videoProjectId,
                actionType: $actionType,
                status: 'success',
                latencyMs: $latencyMs,
                requestMeta: $sanitizedRequestMeta,
                responseMeta: $decoded,
                errorMessage: null
            );

            return $decoded;
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            $this->recordGenerationLog(
                videoProjectId: $videoProjectId,
                actionType: $actionType,
                status: 'failed',
                latencyMs: $latencyMs,
                requestMeta: $sanitizedRequestMeta,
                responseMeta: null,
                errorMessage: $e->getMessage()
            );

            throw new RuntimeException(
                sprintf('Lỗi khi gọi Gemini 2.5 Flash (%s): %s', $actionType, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Ghi nhận lịch sử gọi API vào bảng `generation_logs` (chi phí Gemini Free Tier = $0.0000).
     *
     * @param  array<string, mixed>  $requestMeta
     * @param  array<string, mixed>|null  $responseMeta
     */
    private function recordGenerationLog(
        ?int $videoProjectId,
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
                'model_name' => $this->model,
                'action_type' => $actionType,
                'status' => $status,
                'cost_usd' => 0.0000,
                'latency_ms' => $latencyMs,
                'request_payload' => $requestMeta,
                'response_payload' => $responseMeta,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable) {
            // Không làm gián đoạn luồng chính nếu DB chưa migrate trong môi trường unit test
        }
    }
}
