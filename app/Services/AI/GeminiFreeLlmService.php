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
        $this->model = (string) config('services.gemini.model', 'gemini-3.8-flash');
        $this->baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');
        $this->timeoutSeconds = max(10, min(90, (int) config('services.gemini.timeout', 45)));
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
        try {
            $result = app(MultiAgentVideoTeamService::class)->produceWithFourAgents(
                mode: 'knowledge',
                topicOrDescription: $topic,
                imagePaths: [],
                dimensions: [],
                videoProjectId: $videoProjectId
            );

            return $this->normalizeScenePromptKits($result);
        } catch (Throwable $e) {
            // Không âm thầm fallback sang văn mẫu nếu GEMINI_API_KEY bị vô hiệu hóa (401/403)
            if (str_contains($e->getMessage(), 'HTTP 401') || str_contains($e->getMessage(), 'HTTP 403') || str_contains($e->getMessage(), 'UNAUTHENTICATED')) {
                throw new RuntimeException(
                    'Lỗi xác thực GEMINI_API_KEY (401/403): Khóa API hiện tại đã bị vô hiệu hóa hoặc chưa hợp lệ. Vui lòng tạo API Key mới tại https://aistudio.google.com/apikey và cập nhật vào file .env. Chi tiết: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

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
      "narration_text": "Câu thoại tiếng Việt cuốn hút (Hook -> Giải pháp -> Trải nghiệm -> CTA). Có thể chèn tối đa 1-2 thẻ neo từ khóa tùy chọn như @{pop}, @{whoosh}, @{sparkle}, @{zoom}, @{sticker} ngay trước từ khóa điểm nhấn.",
      "prompt_kit": {
        "subject_anchor": "Mô tả chi tiết sản phẩm, chất liệu và tỷ lệ kích thước thực tế bằng tiếng Anh",
        "shot_composition": "Góc máy, bố cục thương mại bằng tiếng Anh (VD: macro hero shot, low angle, shallow depth of field)",
        "lighting_setting": "Ánh sáng studio và bối cảnh đặt sản phẩm bằng tiếng Anh (VD: luxury dark marble podium, golden rim light, 8k HDR)"
      },
      "visual_prompt": "Prompt tiếng Anh tổng hợp từ prompt_kit mô tả bối cảnh đặt sản phẩm chuẩn tỷ lệ thực tế cho Flux Schnell / LTX-Video",
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
            $result = app(MultiAgentVideoTeamService::class)->produceWithFourAgents(
                mode: 'product',
                topicOrDescription: $description,
                imagePaths: $imagePaths,
                dimensions: ['width_mm' => $widthMm, 'height_mm' => $heightMm, 'depth_mm' => $depthMm],
                videoProjectId: $videoProjectId
            );

            return $this->normalizeScenePromptKits($result);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 401') || str_contains($e->getMessage(), 'HTTP 403') || str_contains($e->getMessage(), 'UNAUTHENTICATED')) {
                throw new RuntimeException(
                    'Lỗi xác thực GEMINI_API_KEY (401/403): Khóa API hiện tại đã bị vô hiệu hóa hoặc chưa hợp lệ. Vui lòng tạo API Key mới tại https://aistudio.google.com/apikey và cập nhật vào file .env. Chi tiết: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            Log::warning('[GeminiFreeLlmService] Chuyển sang Smart Local Fallback cho analyzeProduct: ' . $e->getMessage());

            return $this->buildFallbackProductAnalysis($description, $widthMm, $heightMm, $depthMm, $videoProjectId, $e->getMessage());
        }
    }

    /**
     * Phân tích đa phương thức Video mẫu (.mp4/.mov/.webm) bằng cách trích xuất các khung hình tiêu biểu qua FFmpeg
     * và dùng Gemini Multimodal để clone cấu trúc viral, nhịp cắt, góc máy & viết lại kịch bản tiếng Việt mới.
     *
     * @return array<string, mixed>
     */
    public function analyzeReferenceVideo(
        string $videoPath,
        string $remakeInstructions = '',
        ?int $videoProjectId = null
    ): array {
        $systemPrompt = <<<'PROMPT'
Bạn là Đạo diễn Clone Video Viral (lấy cảm hứng từ Hypit SVML) trên TikTok / Reels / YouTube Shorts.
Dựa trên các khung hình trích xuất theo trình tự thời gian từ Video mẫu và yêu cầu làm lại (nếu có), hãy:
1. Giải mã cấu trúc viral của video mẫu (Hook 3 giây đầu, nhịp độ, góc máy, bố cục chữ/đồ họa, chuyển cảnh).
2. Tái tạo thành kịch bản video mới hoàn chỉnh bằng tiếng Việt (4-6 phân cảnh), giữ nguyên nhịp hấp dẫn của bản gốc nhưng áp dụng đúng chủ đề/sản phẩm người dùng yêu cầu.
3. Trong `tagged_narration` và `narration_text`, chèn các thẻ neo từ khóa (`@{pop}`, `@{whoosh}`, `@{sparkle}`, `@{zoom}`, `@{sticker}`) ngay trước từ cần nhấn mạnh.
4. Mỗi phân cảnh có `prompt_kit` tách lớp (`subject_anchor`, `shot_composition`, `lighting_setting`) để giữ đồng nhất phong cách.

YÊU CẦU BẮT BUỘC VỀ ĐỊNH DẠNG JSON TRẢ VỀ:
{
  "title": "Tiêu đề video mới hấp dẫn bằng tiếng Việt",
  "master_style_prompt": "Mô tả phong cách thị giác tổng thể bằng tiếng Anh dựa trên video mẫu",
  "recommended_bgm_mood": "upbeat_modern",
  "scenes": [
    {
      "scene_order": 1,
      "narration_text": "Lời thoại tiếng Việt (có thể kèm thẻ @{pop}, @{whoosh} trước từ khóa)",
      "tagged_narration": "Lời thoại tiếng Việt kèm thẻ @{pop}, @{whoosh}",
      "prompt_kit": {
        "subject_anchor": "Mô tả chủ thể chính",
        "shot_composition": "Bố cục & góc máy tương ứng video mẫu",
        "lighting_setting": "Ánh sáng & bối cảnh"
      },
      "visual_prompt": "Prompt tiếng Anh đầy đủ ghép từ prompt_kit",
      "sfx_prompt": "whoosh_fast",
      "camera_motion": "zoom_in | zoom_out | pan_right | pan_left",
      "transition_type": "fade | smoothleft | wipeleft | circleopen | dissolve"
    }
  ]
}
PROMPT;

        $instructions = trim($remakeInstructions) !== ''
            ? trim($remakeInstructions)
            : 'Giữ nguyên cấu trúc Hook, nhịp chuyển cảnh và phong cách thị giác của video mẫu, chuyển thể lời thoại tiếng Việt tự nhiên và cuốn hút.';

        $parts = [[
            'text' => sprintf("YÊU CẦU LÀM LẠI VIDEO MẪU:\n\"%s\"\nHãy phân tích các khung hình đính kèm và xuất JSON.", $instructions),
        ]];

        // Trích xuất tối đa 5 khung hình đại diện từ video mẫu bằng FFmpeg (-threads 6)
        $extractedFrames = $this->extractReferenceVideoFrames($videoPath, 5);
        foreach ($extractedFrames as $framePath) {
            $rawBytes = @file_get_contents($framePath);
            if ($rawBytes !== false && $rawBytes !== '') {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => 'image/jpeg',
                        'data' => base64_encode($rawBytes),
                    ],
                ];
            }
            @unlink($framePath);
        }

        try {
            $result = $this->callGeminiJsonEndpoint(
                systemPrompt: $systemPrompt,
                userParts: $parts,
                actionType: 'analyze_reference_video',
                videoProjectId: $videoProjectId,
                sanitizedRequestMeta: [
                    'video_file' => basename($videoPath),
                    'remake_instructions' => $instructions,
                    'frames_extracted' => count($extractedFrames),
                ]
            );

            return $this->normalizeScenePromptKits($result);
        } catch (Throwable $e) {
            Log::warning('[GeminiFreeLlmService] Chuyển sang Smart Local Fallback cho analyzeReferenceVideo: ' . $e->getMessage());

            return $this->buildFallbackKnowledgeScript($instructions, $videoProjectId, $e->getMessage());
        }
    }

    /**
     * Trích xuất các khung hình JPEG đại diện từ video mẫu bằng FFmpeg (giới hạn luồng -threads 6).
     *
     * @return array<int, string>
     */
    private function extractReferenceVideoFrames(string $videoPath, int $maxFrames = 5): array
    {
        if (! is_file($videoPath)) {
            return [];
        }

        $ffmpegBin = (string) config('services.ffmpeg.ffmpeg_binary', '/usr/local/bin/ffmpeg');
        $ffprobeBin = (string) config('services.ffmpeg.ffprobe_binary', '/usr/local/bin/ffprobe');
        $maxThreads = max(1, min(6, (int) config('services.ffmpeg.max_threads', 6)));

        $durationSec = 10.0;
        $probeProc = new \Symfony\Component\Process\Process([
            $ffprobeBin,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);
        $probeProc->setTimeout(15);
        $probeProc->run();
        if ($probeProc->isSuccessful()) {
            $parsed = (float) trim($probeProc->getOutput());
            if ($parsed > 0.5) {
                $durationSec = $parsed;
            }
        }

        $tempDir = storage_path('app/temp/ref_frames_' . uniqid());
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $frames = [];
        for ($i = 0; $i < $maxFrames; $i++) {
            $timestamp = round(($durationSec * ($i + 0.5)) / $maxFrames, 2);
            $outPath = "{$tempDir}/frame_{$i}.jpg";
            $args = [
                '-y',
                '-threads',
                (string) $maxThreads,
                '-ss',
                (string) $timestamp,
                '-i',
                $videoPath,
                '-frames:v',
                '1',
                '-vf',
                'scale=640:-1',
                '-q:v',
                '4',
                $outPath,
            ];
            $cmd = PHP_OS_FAMILY === 'Linux'
                ? array_merge(['nice', '-n', '10', $ffmpegBin], $args)
                : array_merge([$ffmpegBin], $args);

            $proc = new \Symfony\Component\Process\Process($cmd);
            $proc->setTimeout(20);
            $proc->run();

            if (is_file($outPath) && filesize($outPath) > 0) {
                $frames[] = $outPath;
            }
        }

        return $frames;
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
            'gemini-3.5-flash-lite',
            'gemini-3.8-flash',
            'gemini-3.1-flash-lite',
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
                            $decoded = $this->normalizeScenePromptKits($decoded);

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
     * Chuẩn hóa dữ liệu phân cảnh: ghép Structured Prompt Kit (`subject_anchor`, `shot_composition`, `lighting_setting`)
     * thành `visual_prompt` hoàn chỉnh và đồng bộ `tagged_narration` (nếu có) sang `narration_text`.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizeScenePromptKits(array $decoded): array
    {
        if (! isset($decoded['scenes']) || ! is_array($decoded['scenes'])) {
            return $decoded;
        }

        foreach ($decoded['scenes'] as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            // Ưu tiên giữ các thẻ neo @{...} nếu model trả về qua trường tagged_narration
            $narration = trim((string) ($scene['narration_text'] ?? ''));
            $taggedNarration = trim((string) ($scene['tagged_narration'] ?? ''));
            if ($taggedNarration !== '' && ($narration === '' || (str_contains($taggedNarration, '@{') && ! str_contains($narration, '@{')))) {
                $scene['narration_text'] = $taggedNarration;
            }

            // Lắp ghép prompt_kit -> visual_prompt chuẩn hóa
            if (isset($scene['prompt_kit']) && is_array($scene['prompt_kit'])) {
                $kit = $scene['prompt_kit'];
                $kitParts = array_values(array_filter([
                    trim((string) ($kit['subject_anchor'] ?? '')),
                    trim((string) ($kit['shot_composition'] ?? '')),
                    trim((string) ($kit['lighting_setting'] ?? '')),
                ], static fn (string $part): bool => $part !== ''));

                if ($kitParts !== []) {
                    $assembledPrompt = implode(', ', $kitParts);
                    $existingVisual = trim((string) ($scene['visual_prompt'] ?? ''));

                    if ($existingVisual === '') {
                        $scene['visual_prompt'] = $assembledPrompt;
                    } elseif (! str_contains($existingVisual, $kitParts[0])) {
                        $scene['visual_prompt'] = $assembledPrompt . ', ' . $existingVisual;
                    }
                }
            }

            $decoded['scenes'][$index] = $scene;
        }

        return $decoded;
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
                    'narration_text' => "Bạn đã bao giờ @{whoosh} tự hỏi điều gì tạo nên sự khác biệt thực sự khi tìm hiểu về {$cleanTopic}?",
                    'prompt_kit' => [
                        'subject_anchor' => "Conceptual 3D focal visualization representing {$cleanTopic}",
                        'shot_composition' => 'Cinematic close-up shot, shallow depth of field',
                        'lighting_setting' => 'Glowing golden and cyan rim lighting, dark studio backdrop, ultra detailed 8k photorealistic',
                    ],
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'fade',
                ],
                [
                    'scene_order' => 2,
                    'narration_text' => "Thực tế cho thấy phần lớn mọi người thường bỏ qua @{pop} nguyên lý cốt lõi nhất giúp tối ưu hóa kết quả gấp nhiều lần.",
                    'prompt_kit' => [
                        'subject_anchor' => "Dynamic modern 3D infographic visualization about {$cleanTopic}",
                        'shot_composition' => 'Medium wide shot, balanced geometric framing',
                        'lighting_setting' => 'Futuristic glass interface reflections, dramatic volumetric studio lighting',
                    ],
                    'sfx_prompt' => 'whoosh_fast',
                    'camera_motion' => 'pan_right',
                    'transition_type' => 'smoothleft',
                ],
                [
                    'scene_order' => 3,
                    'narration_text' => "Khi áp dụng đúng phương pháp khoa học, bạn sẽ @{sparkle} tiết kiệm được hàng giờ đồng hồ mỗi tuần mà vẫn đạt hiệu quả vượt trội.",
                    'prompt_kit' => [
                        'subject_anchor' => "Inspiring high-tech creative workspace representing mastery of {$cleanTopic}",
                        'shot_composition' => 'Eye-level medium shot, smooth depth layering',
                        'lighting_setting' => 'Warm sunlight streaming through modern windows, crisp 8k HDR color grading',
                    ],
                    'sfx_prompt' => 'typing',
                    'camera_motion' => 'zoom_out',
                    'transition_type' => 'circleopen',
                ],
                [
                    'scene_order' => 4,
                    'narration_text' => "Hãy bắt đầu áp dụng @{pop} ngay hôm nay và theo dõi kênh để khám phá thêm nhiều kiến thức giá trị mỗi ngày nhé!",
                    'prompt_kit' => [
                        'subject_anchor' => "Celebratory golden light particles symbolizing success in {$cleanTopic}",
                        'shot_composition' => 'Epic wide angle shot, centered focal point',
                        'lighting_setting' => 'Warm cinematic bokeh, glowing amber highlights, 8k photorealistic',
                    ],
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'dissolve',
                ],
            ],
        ];

        $data = $this->normalizeScenePromptKits($data);

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
                    'narration_text' => "Nếu bạn đang tìm kiếm một giải pháp hoàn hảo cả về thiết kế lẫn công năng, hãy @{sparkle} chiêm ngưỡng siêu phẩm {$cleanDesc}.",
                    'prompt_kit' => [
                        'subject_anchor' => "Hero commercial product {$cleanDesc} with crisp realistic proportions",
                        'shot_composition' => 'Low-angle hero studio shot, centered composition',
                        'lighting_setting' => 'Sleek dark marble pedestal, golden rim lighting, 8k photorealistic',
                    ],
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'fade',
                ],
                [
                    'scene_order' => 2,
                    'narration_text' => sprintf(
                        'Với kích thước chuẩn xác @{pop} rộng %.0f milimét và cao %.0f milimét, từng đường nét đều được chế tác vô cùng tỉ mỉ.',
                        $widthMm,
                        $heightMm
                    ),
                    'prompt_kit' => [
                        'subject_anchor' => "Premium surface texture and craftsmanship details of {$cleanDesc}",
                        'shot_composition' => 'Close-up macro product showcase, shallow depth of field',
                        'lighting_setting' => 'Soft diffused studio key light with crisp specular highlights, bokeh 8k',
                    ],
                    'sfx_prompt' => 'whoosh_fast',
                    'camera_motion' => 'pan_right',
                    'transition_type' => 'smoothleft',
                ],
                [
                    'scene_order' => 3,
                    'narration_text' => 'Sản phẩm mang lại @{whoosh} trải nghiệm đẳng cấp, bền bỉ và tiện lợi tối đa trong mọi không gian sử dụng hàng ngày.',
                    'prompt_kit' => [
                        'subject_anchor' => "{$cleanDesc} placed naturally in a real-world interior scale",
                        'shot_composition' => 'Lifestyle medium shot, rule of thirds',
                        'lighting_setting' => 'Modern luxury living space with warm natural window light, 8k HDR',
                    ],
                    'sfx_prompt' => 'sparkle_chime',
                    'camera_motion' => 'zoom_out',
                    'transition_type' => 'circleopen',
                ],
                [
                    'scene_order' => 4,
                    'narration_text' => 'Đặt hàng @{pop} ngay hôm nay để nhận ưu đãi đặc biệt và trải nghiệm chất lượng vượt trội ngay tại nhà!',
                    'prompt_kit' => [
                        'subject_anchor' => "Final flagship presentation of {$cleanDesc}",
                        'shot_composition' => 'Dramatic three-quarter angle product shot',
                        'lighting_setting' => 'Focused overhead spotlight, luxury brand dark studio atmosphere, 8k',
                    ],
                    'sfx_prompt' => 'whoosh_fast',
                    'camera_motion' => 'zoom_in',
                    'transition_type' => 'dissolve',
                ],
            ],
        ];

        $data = $this->normalizeScenePromptKits($data);

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
