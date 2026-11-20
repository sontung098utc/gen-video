<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AppSetting;
use App\Models\CustomVoice;
use App\Models\GenerationLog;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\AI\EdgeTtsVietnameseService;
use App\Services\Media\WordAnchoredTimelineService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * AGENT AI 5 — PHÒNG THU GIỌNG ĐỌC ĐA ENGINE (Multi-Engine Voice Studio).
 *
 * Hỗ trợ 4 nguồn giọng đọc chuyên nghiệp (người dùng chọn giọng + tốc độ đọc):
 *  1. `edge_tts`   : Microsoft Edge Neural Tiếng Việt (miễn phí, sẵn dùng ngay)
 *  2. `google_tts` : Google Cloud Text-to-Speech API (Neural2 / WaveNet Tiếng Việt)
 *  3. `vbee`       : Vbee / Vivibe API (App ID + Bearer Token)
 *  4. `genmax`     : GenMax API (`genmax.io/app/voices` hoặc Custom Endpoint)
 *
 * CAM KẾT KỸ THUẬT: Khóa cứng ĐÚNG 1 GIỌNG ĐỌC + 1 TỐC ĐỘ duy nhất xuyên suốt toàn bộ các phân cảnh!
 */
class Agent5MultiVoiceStudioService
{
    public function __construct(
        private readonly EdgeTtsVietnameseService $edgeTts,
        private readonly WordAnchoredTimelineService $wordTimeline
    ) {}

    /**
     * Danh mục giọng đọc có sẵn + Giọng đọc tùy chỉnh đã lưu theo từng nhà cung cấp.
     *
     * @return array<string, array{label: string, voices: array<string, string>}>
     */
    public static function catalog(): array
    {
        $catalog = [
            'edge_tts' => [
                'label' => 'Microsoft Edge Neural (Miễn phí — Sẵn dùng)',
                'voices' => [
                    'vi-VN-HoaiMyNeural' => 'Hoài My (Nữ miền Bắc — Ấm áp, truyền cảm)',
                    'vi-VN-NamMinhNeural' => 'Nam Minh (Nam miền Bắc — Chững chạc, chuyên gia)',
                ],
            ],
            'google_tts' => [
                'label' => 'Google Cloud TTS (Cần Google TTS API Key)',
                'voices' => [
                    'vi-VN-Neural2-A' => 'Google Neural2-A (Nữ chuẩn sáng)',
                    'vi-VN-Neural2-D' => 'Google Neural2-D (Nam trầm ấm)',
                    'vi-VN-Wavenet-A' => 'Google WaveNet-A (Nữ tự nhiên)',
                    'vi-VN-Wavenet-B' => 'Google WaveNet-B (Nam rõ ràng)',
                    'vi-VN-Wavenet-C' => 'Google WaveNet-C (Nữ nhẹ nhàng)',
                    'vi-VN-Wavenet-D' => 'Google WaveNet-D (Nam tin cậy)',
                ],
            ],
            'vbee' => [
                'label' => 'Vivibe / Vbee AI (Cần App ID & Token)',
                'voices' => [
                    'hn_female_ngochuyen_full_48k-fhg' => 'Vbee Ngọc Huyền (Nữ Hà Nội — Truyền cảm)',
                    'hn_male_manhdung_news_48k-fhg' => 'Vbee Mạnh Dũng (Nam Hà Nội — Tin tức y khoa)',
                    'sg_female_thaotrinh_full_48k-fhg' => 'Vbee Thảo Trinh (Nữ Sài Gòn — Dịu dàng)',
                    'sg_male_minhhoang_full_48k-fhg' => 'Vbee Minh Hoàng (Nam Sài Gòn — Ấm áp)',
                ],
            ],
            'genmax' => [
                'label' => 'GenMax - CapCut (Cần GenMax API Key)',
                'voices' => [
                    'genmax_vi_female_warm' => 'GenMax Nữ Sức Khỏe (Ấm áp)',
                    'genmax_vi_male_expert' => 'GenMax Nam Chuyên Gia (Tin cậy)',
                ],
            ],
        ];

        // Gộp toàn bộ giọng đọc tùy chỉnh theo Voice ID mà người dùng đã lưu vào đầu từng nhà cung cấp
        try {
            $customVoices = CustomVoice::query()
                ->where('is_active', true)
                ->latest()
                ->get();

            foreach ($customVoices as $cv) {
                if (isset($catalog[$cv->provider])) {
                    $catalog[$cv->provider]['voices'] = [
                        $cv->voice_id => "★ {$cv->name} ({$cv->voice_id})",
                    ] + $catalog[$cv->provider]['voices'];
                }
            }
        } catch (Throwable) {
            // Bỏ qua nếu bảng custom_voices chưa được migrate
        }

        // Cho phép nhập nhanh Voice ID trực tiếp nếu cần
        foreach ($catalog as $prov => $meta) {
            $catalog[$prov]['voices']['custom'] = '+ Nhập nhanh Voice ID khác...';
        }

        return $catalog;
    }

    /**
     * Sinh giọng đọc + phụ đề VTT cho toàn bộ phân cảnh bằng ĐÚNG 1 giọng đọc duy nhất.
     */
    public function synthesizeProjectVoice(VideoProject $project, bool $forceRegenerate = false): VideoProject
    {
        $project->load('scenes');

        $provider = trim((string) ($project->voice_provider ?: 'edge_tts'));
        $voiceId = trim((string) ($project->voice_id ?: 'vi-VN-HoaiMyNeural'));
        $speed = max(0.75, min(1.35, (float) ($project->voice_speed ?: 1.00)));

        $totalDurationMs = 0;

        foreach ($project->scenes as $scene) {
            if (
                ! $forceRegenerate
                && $scene->voice_file_path
                && Storage::disk('public')->exists($scene->voice_file_path)
                && $scene->subtitle_file_path
                && Storage::disk('public')->exists($scene->subtitle_file_path)
                && (int) $scene->duration_ms > 1000
            ) {
                $totalDurationMs += (int) $scene->duration_ms;
                continue;
            }

            $cleanText = $this->wordTimeline->stripTags((string) $scene->narration_text);
            if ($cleanText === '') {
                $cleanText = 'Sống Khỏe Mỗi Ngày chia sẻ kiến thức chăm sóc sức khỏe hữu ích.';
            }

            $prefix = sprintf('project_%d_scene_%02d_a5_%s', $project->id, $scene->scene_order, $provider);
            $result = $this->synthesizeSingleScene($project, $scene, $cleanText, $provider, $voiceId, $speed, $prefix);

            $scene->update([
                'voice_file_path' => $result['audio_relative_path'],
                'subtitle_file_path' => $result['vtt_relative_path'],
                'duration_ms' => $result['duration_ms'],
            ]);

            $totalDurationMs += $result['duration_ms'];
        }

        $project->update([
            'total_duration_ms' => $totalDurationMs,
        ]);

        return $project->refresh()->load('scenes');
    }

    /**
     * @return array{audio_relative_path: string, vtt_relative_path: string, duration_ms: int, provider_used: string}
     */
    private function synthesizeSingleScene(
        VideoProject $project,
        StoryboardScene $scene,
        string $cleanText,
        string $provider,
        string $voiceId,
        float $speed,
        string $filePrefix
    ): array {
        $disk = Storage::disk('public');
        $audioRel = "audio/{$filePrefix}.mp3";
        $vttRel = "subtitles/{$filePrefix}.vtt";
        $audioFull = $disk->path($audioRel);
        $vttFull = $disk->path($vttRel);
        $this->ensureParentDirectory($audioFull);
        $this->ensureParentDirectory($vttFull);

        $startTime = microtime(true);
        $usedProvider = $provider;

        if ($provider === 'google_tts') {
            $this->callGoogleCloudTts($cleanText, $voiceId, $speed, $audioFull);
        } elseif ($provider === 'vbee') {
            $this->callVbeeTts($cleanText, $voiceId, $speed, $audioFull);
        } elseif ($provider === 'genmax') {
            $this->callGenMaxTts($cleanText, $voiceId, $speed, $audioFull);
        } else {
            // Edge TTS mặc định (hỗ trợ rate +XX% / -XX%)
            $ratePercent = (int) round(($speed - 1.0) * 100);
            $rateStr = ($ratePercent >= 0 ? '+' : '') . $ratePercent . '%';
            $edgeVoice = $this->edgeTts->resolveVietnameseVoice($voiceId);

            $edgeResult = $this->edgeTts->synthesizeSceneVoice(
                text: $cleanText,
                voice: $edgeVoice,
                filePrefix: $filePrefix,
                videoProjectId: $project->id,
                storyboardSceneId: $scene->id,
                rate: $rateStr
            );

            return [
                'audio_relative_path' => $edgeResult['audio_relative_path'],
                'vtt_relative_path' => $edgeResult['subtitle_relative_path'],
                'duration_ms' => $edgeResult['duration_ms'],
                'provider_used' => 'edge_tts',
            ];
        }

        if (! is_file($audioFull) || filesize($audioFull) < 512) {
            throw new RuntimeException("Agent AI 5 ({$provider}) không tạo được file âm thanh hợp lệ cho Cảnh {$scene->scene_order}.");
        }

        $durationMs = $this->edgeTts->measureAudioDurationMs($audioFull);
        $this->writeFourWordVtt($cleanText, $durationMs, $vttFull);

        $this->log(
            $project->id,
            $scene->id,
            $usedProvider,
            $voiceId,
            (int) round((microtime(true) - $startTime) * 1000)
        );

        return [
            'audio_relative_path' => $audioRel,
            'vtt_relative_path' => $vttRel,
            'duration_ms' => $durationMs,
            'provider_used' => $usedProvider,
        ];
    }

    /**
     * Gọi Google Cloud Text-to-Speech API (v1/text:synthesize).
     */
    private function callGoogleCloudTts(string $text, string $voiceId, float $speed, string $outputPath): void
    {
        $apiKey = AppSetting::getValue('google_tts_api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('Chưa cấu hình Google Cloud TTS API Key. Vui lòng nhập tại trang Cài đặt (/settings) hoặc chuyển sang Edge TTS.');
        }

        $resolvedVoice = str_starts_with($voiceId, 'vi-VN-') ? $voiceId : 'vi-VN-Neural2-A';

        $response = Http::timeout(45)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->acceptJson()
            ->post("https://texttospeech.googleapis.com/v1/text:synthesize?key={$apiKey}", [
                'input' => ['text' => $text],
                'voice' => [
                    'languageCode' => 'vi-VN',
                    'name' => $resolvedVoice,
                ],
                'audioConfig' => [
                    'audioEncoding' => 'MP3',
                    'speakingRate' => $speed,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google Cloud TTS API lỗi HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $audioContent = (string) $response->json('audioContent', '');
        if ($audioContent === '') {
            throw new RuntimeException('Google Cloud TTS không trả về dữ liệu audioContent.');
        }

        file_put_contents($outputPath, base64_decode($audioContent));
    }

    /**
     * Gọi Vbee / Vivibe API (hỗ trợ cấu hình App ID, Token & Custom Endpoint).
     */
    private function callVbeeTts(string $text, string $voiceId, float $speed, string $outputPath): void
    {
        $appId = AppSetting::getValue('vbee_app_id', '');
        $token = AppSetting::getValue('vbee_api_token', '');
        $endpoint = AppSetting::getValue('vbee_endpoint', 'https://vbee.vn/api/v1/tts');

        if ($token === '') {
            throw new RuntimeException('Chưa cấu hình Vbee (Vivibe) API Token. Vui lòng nhập tại trang Cài đặt (/settings).');
        }

        $response = Http::timeout(60)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
            ->post($endpoint, [
                'app_id' => $appId,
                'response_type' => 'direct',
                'input_text' => $text,
                'voice_code' => $voiceId,
                'audio_type' => 'mp3',
                'bitrate' => 128,
                'speed_rate' => (string) number_format($speed, 2, '.', ''),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Vbee TTS API lỗi HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        if (str_contains($contentType, 'audio/')) {
            file_put_contents($outputPath, $response->body());

            return;
        }

        $json = $response->json();
        $audioUrl = (string) (data_get($json, 'result.audio_link') ?: data_get($json, 'audio_link') ?: data_get($json, 'audio_url') ?: '');
        if ($audioUrl !== '') {
            $dl = Http::timeout(60)->sink($outputPath)->get($audioUrl);
            if ($dl->successful()) {
                return;
            }
        }

        $b64 = (string) (data_get($json, 'result.audio_base64') ?: data_get($json, 'audio_base64') ?: '');
        if ($b64 !== '') {
            file_put_contents($outputPath, base64_decode($b64));

            return;
        }

        throw new RuntimeException('Vbee API trả về định dạng không chứa audio_link/audio_base64: ' . mb_substr($response->body(), 0, 250));
    }

    /**
     * Gọi GenMax API (`genmax.io/app/voices` hoặc Custom Endpoint tương thích ElevenLabs/MiniMax).
     */
    private function callGenMaxTts(string $text, string $voiceId, float $speed, string $outputPath): void
    {
        $apiKey = AppSetting::getValue('genmax_api_key', '');
        $endpoint = AppSetting::getValue('genmax_endpoint', 'https://genmax.io/api/v1/tts');

        if ($apiKey === '') {
            throw new RuntimeException('Chưa cấu hình GenMax API Key. Vui lòng nhập tại trang Cài đặt (/settings).');
        }

        $response = Http::timeout(60)
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'x-api-key' => $apiKey,
                'Accept' => 'audio/mpeg, application/json',
            ])
            ->post($endpoint, [
                'text' => $text,
                'voice_id' => $voiceId,
                'speed' => $speed,
                'output_format' => 'mp3',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('GenMax API lỗi HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        if (str_contains($contentType, 'audio/') || str_contains($contentType, 'octet-stream')) {
            file_put_contents($outputPath, $response->body());

            return;
        }

        $json = $response->json();
        $audioUrl = (string) (data_get($json, 'audio_url') ?: data_get($json, 'data.audio_url') ?: data_get($json, 'url') ?: '');
        if ($audioUrl !== '') {
            $dl = Http::timeout(60)->sink($outputPath)->get($audioUrl);
            if ($dl->successful()) {
                return;
            }
        }

        $b64 = (string) (data_get($json, 'audio_base64') ?: data_get($json, 'data.audio_base64') ?: '');
        if ($b64 !== '') {
            file_put_contents($outputPath, base64_decode($b64));

            return;
        }

        throw new RuntimeException('GenMax API không trả về file âm thanh hợp lệ: ' . mb_substr($response->body(), 0, 250));
    }

    /**
     * Xuất file phụ đề `.vtt` chia cụm 4 từ gọn gàng khớp chính xác `$durationMs`.
     */
    private function writeFourWordVtt(string $text, int $durationMs, string $vttFullPath): void
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if ($words === []) {
            file_put_contents($vttFullPath, "WEBVTT\n\n");

            return;
        }

        $phrases = array_chunk($words, 4);
        $count = count($phrases);
        $msPerPhrase = (int) floor($durationMs / $count);

        $lines = ["WEBVTT\n"];
        $cursor = 0;

        foreach ($phrases as $idx => $chunk) {
            $end = ($idx === $count - 1) ? $durationMs : ($cursor + $msPerPhrase);
            $lines[] = sprintf(
                "%s --> %s\n%s\n",
                $this->formatMs($cursor),
                $this->formatMs($end),
                implode(' ', $chunk)
            );
            $cursor = $end;
        }

        file_put_contents($vttFullPath, implode("\n", $lines));
    }

    private function formatMs(int $ms): string
    {
        $ms = max(0, $ms);
        $millis = $ms % 1000;
        $sec = intdiv($ms, 1000) % 60;
        $min = intdiv($ms, 60000) % 60;
        $hour = intdiv($ms, 3600000);

        return sprintf('%02d:%02d:%02d.%03d', $hour, $min, $sec, $millis);
    }

    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function log(int $projectId, int $sceneId, string $provider, string $voiceId, int $latencyMs): void
    {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $projectId,
                'storyboard_scene_id' => $sceneId,
                'provider' => $provider,
                'model_name' => $voiceId,
                'action_type' => 'agent5_multi_voice',
                'status' => 'success',
                'cost_usd' => 0.0,
                'latency_ms' => $latencyMs,
            ]);
        } catch (Throwable) {
            // Bỏ qua
        }
    }
}
