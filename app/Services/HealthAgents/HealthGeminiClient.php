<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\GenerationLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Client gọi Google Gemini API thống nhất cho toàn bộ 8 Agent của "Sống Khỏe Mỗi Ngày Studio".
 * Tự động đọc API Key từ bảng `app_settings` (fallback sang `.env`), ép JSON chuẩn và ghi nhận lịch sử.
 */
class HealthGeminiClient
{
    private readonly string $baseUrl;

    private readonly string $model;

    private readonly int $timeoutSeconds;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta/models'), '/');
        $this->model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $this->timeoutSeconds = max(15, min(120, (int) config('services.gemini.timeout', 60)));
    }

    /**
     * Gọi Gemini sinh phản hồi JSON có cấu trúc (hỗ trợ đính kèm ảnh để Agent 8 chấm điểm ảnh bìa).
     *
     * @param  array<int, string>  $imagePaths
     * @return array<string, mixed>
     */
    public function generateJson(
        string $systemPrompt,
        string $userPrompt,
        string $actionType,
        ?int $videoProjectId = null,
        array $imagePaths = [],
        float $temperature = 0.6
    ): array {
        $apiKey = AppSetting::getValue('gemini_api_key', (string) config('services.gemini.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Chưa cấu hình Google Gemini API Key. Vui lòng nhập tại trang Cài đặt (/settings).');
        }

        $parts = [['text' => $userPrompt]];

        foreach ($imagePaths as $path) {
            if (is_file($path) && is_readable($path)) {
                $mime = mime_content_type($path) ?: 'image/jpeg';
                $raw = file_get_contents($path);
                if ($raw !== false) {
                    $parts[] = [
                        'inline_data' => [
                            'mime_type' => $mime,
                            'data' => base64_encode($raw),
                        ],
                    ];
                }
            }
        }

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'responseMimeType' => 'application/json',
            ],
        ];

        $modelsToTry = array_values(array_unique([$this->model, 'gemini-2.5-flash', 'gemini-2.0-flash']));
        $startTime = microtime(true);
        $lastError = 'Không thể kết nối Gemini API';

        foreach ($modelsToTry as $modelName) {
            $endpoint = "{$this->baseUrl}/{$modelName}:generateContent?key={$apiKey}";

            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->acceptJson()
                    ->post($endpoint, $payload);

                $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response->successful()) {
                    $body = $response->json();
                    $rawText = (string) data_get($body, 'candidates.0.content.parts.0.text', '');
                    $decoded = $this->decodeJsonSafely($rawText);

                    $this->log($videoProjectId, $modelName, $actionType, 'success', $latencyMs, null);

                    return $decoded;
                }

                $lastError = "HTTP {$response->status()}: " . mb_substr($response->body(), 0, 400);
                if (in_array($response->status(), [401, 403], true)) {
                    $this->log($videoProjectId, $modelName, $actionType, 'failed', $latencyMs, $lastError);
                    throw new RuntimeException("Lỗi xác thực Gemini API Key ({$response->status()}): {$lastError}");
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->log($videoProjectId, $this->model, $actionType, 'failed', $latencyMs, $lastError);

        throw new RuntimeException("Gemini API thất bại ({$actionType}): {$lastError}");
    }

    /**
     * Giải mã JSON an toàn kể cả khi model bọc trong ```json ... ```.
     *
     * @return array<string, mixed>
     */
    private function decodeJsonSafely(string $rawText): array
    {
        $clean = trim($rawText);
        if (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/^```(?:json)?\s*/i', '', $clean);
            $clean = (string) preg_replace('/\s*```$/', '', $clean);
        }

        $decoded = json_decode(trim($clean), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Thử trích khối JSON đầu tiên
        if (preg_match('/\{[\s\S]*\}/', $clean, $match) === 1) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Phản hồi từ Gemini không đúng định dạng JSON hợp lệ.');
    }

    private function log(
        ?int $videoProjectId,
        string $modelName,
        string $actionType,
        string $status,
        int $latencyMs,
        ?string $error
    ): void {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $videoProjectId,
                'provider' => 'gemini',
                'model_name' => $modelName,
                'action_type' => $actionType,
                'status' => $status,
                'cost_usd' => 0.0,
                'latency_ms' => $latencyMs,
                'error_message' => $error,
            ]);
        } catch (Throwable) {
            // Bỏ qua nếu không ghi được log
        }
    }
}
