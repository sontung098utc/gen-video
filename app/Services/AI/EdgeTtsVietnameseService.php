<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dịch vụ tổng hợp giọng đọc tiếng Việt miễn phí bằng Microsoft Edge Neural TTS CLI (`edge-tts` - $0.00)
 * và đo thời lượng thực tế bằng `ffprobe`.
 *
 * Hỗ trợ 2 giọng đọc Neural chuẩn tiếng Việt:
 *  - Nam: `vi-VN-NamMinhNeural`
 *  - Nữ:  `vi-VN-HoaiMyNeural`
 *
 * Quy tắc nhất quán giọng đọc (Voice Consistency):
 *  - Khóa cứng duy nhất 1 giọng đọc (`$selectedVoice`) đã chọn của dự án xuyên suốt 100% các phân cảnh.
 *  - Truyền văn bản tiếng Việt qua file tạm chuẩn UTF-8 (`--file`) và tự động thử lại (retry) tối đa 5 lần
 *    với đúng giọng đọc đó nếu kết nối WebSocket tới Edge-TTS chập chờn.
 *  - Tuyệt đối không tự ý chuyển đổi sang giọng đọc khác hoặc bộ đọc dự phòng khác giới tính giữa các cảnh.
 */
class EdgeTtsVietnameseService
{
    public const VOICE_MALE = 'vi-VN-NamMinhNeural';

    public const VOICE_FEMALE = 'vi-VN-HoaiMyNeural';

    private readonly string $edgeTtsBinary;

    private readonly string $ffprobeBinary;

    private readonly string $defaultVoice;

    private readonly string $defaultRate;

    private readonly string $defaultPitch;

    public function __construct()
    {
        $this->edgeTtsBinary = (string) config('services.edge_tts.binary', '/usr/local/bin/edge-tts');
        $this->ffprobeBinary = (string) config('services.edge_tts.ffprobe_binary', '/usr/local/bin/ffprobe');
        $this->defaultVoice = (string) config('services.edge_tts.default_voice', self::VOICE_MALE);
        $this->defaultRate = (string) config('services.edge_tts.default_rate', '+5%');
        $this->defaultPitch = (string) config('services.edge_tts.default_pitch', '+0Hz');
    }

    /**
     * Thực thi lệnh CLI `edge-tts` với duy nhất giọng đọc `$voice` được chỉ định
     * để xuất file âm thanh `.mp3`, trích xuất phụ đề `.vtt` và đo thời lượng bằng `ffprobe` (mili-giây).
     *
     * @return array{
     *     audio_relative_path: string,
     *     audio_full_path: string,
     *     subtitle_relative_path: string,
     *     subtitle_full_path: string,
     *     duration_ms: int,
     *     voice: string
     * }
     */
    public function synthesizeSceneVoice(
        string $text,
        ?string $voice = null,
        ?string $filePrefix = null,
        ?int $videoProjectId = null,
        ?int $storyboardSceneId = null
    ): array {
        $cleanText = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($cleanText === '') {
            throw new RuntimeException('Nội dung câu thoại tiếng Việt không được để trống.');
        }

        // Khóa chính xác giọng đọc được truyền vào (NamMinh hoặc HoaiMy)
        $selectedVoice = $this->resolveVietnameseVoice($voice);
        $baseName = $filePrefix !== null && $filePrefix !== ''
            ? Str::slug($filePrefix, '_')
            : 'tts_' . Str::random(12);

        $audioRelativePath = "audio/{$baseName}.mp3";
        $subtitleRelativePath = "subtitles/{$baseName}.vtt";

        $disk = Storage::disk('public');
        $audioFullPath = $disk->path($audioRelativePath);
        $subtitleFullPath = $disk->path($subtitleRelativePath);

        $tempDir = storage_path('app/temp');
        $this->ensureDirectoryExists($tempDir);
        $this->ensureDirectoryExists(dirname($audioFullPath));
        $this->ensureDirectoryExists(dirname($subtitleFullPath));

        // Ghi nội dung câu thoại tiếng Việt ra file UTF-8 để tránh lỗi mã hóa ký tự trên CLI Windows/Linux
        $tempTextPath = "{$tempDir}/{$baseName}_input.txt";
        file_put_contents($tempTextPath, $cleanText);

        $startTime = microtime(true);

        $command = [
            $this->edgeTtsBinary,
            '--voice',
            $selectedVoice,
            '--rate=' . $this->defaultRate,
            '--pitch=' . $this->defaultPitch,
            '--file',
            $tempTextPath,
            '--write-media',
            $audioFullPath,
            '--write-subtitles',
            $subtitleFullPath,
        ];

        $maxAttempts = 5;
        $lastError = '';
        $synthesized = false;

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                @unlink($audioFullPath);
                @unlink($subtitleFullPath);

                $process = new Process($command);
                $process->setTimeout(45);
                $process->run();

                if ($process->isSuccessful() && is_file($audioFullPath) && filesize($audioFullPath) > 256) {
                    $synthesized = true;
                    break;
                }

                $lastError = trim($process->getErrorOutput() ?: $process->getOutput());
                Log::warning(sprintf(
                    '[EdgeTtsVietnameseService] Lần thử %d/%d với giọng [%s] chưa thành công: %s',
                    $attempt,
                    $maxAttempts,
                    $selectedVoice,
                    $lastError
                ));

                if ($attempt < $maxAttempts) {
                    usleep(600_000);
                }
            }
        } finally {
            @unlink($tempTextPath);
        }

        if (! $synthesized) {
            $errorMsg = sprintf(
                'Không thể tổng hợp giọng đọc Edge-TTS [%s] sau %d lần thử: %s',
                $selectedVoice,
                $maxAttempts,
                $lastError
            );

            $this->recordGenerationLog(
                videoProjectId: $videoProjectId,
                storyboardSceneId: $storyboardSceneId,
                voice: $selectedVoice,
                status: 'failed',
                latencyMs: (int) round((microtime(true) - $startTime) * 1000),
                requestPayload: ['voice' => $selectedVoice, 'text' => $cleanText],
                responsePayload: null,
                errorMessage: $errorMsg
            );

            throw new RuntimeException($errorMsg);
        }

        $durationMs = $this->measureAudioDurationMs($audioFullPath);

        // Đảm bảo file phụ đề luôn ở chuẩn WebVTT (.vtt) có header WEBVTT và mốc thời gian chuẩn
        $this->normalizeOrGenerateVtt($cleanText, $durationMs, $subtitleFullPath);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        $result = [
            'audio_relative_path' => $audioRelativePath,
            'audio_full_path' => $audioFullPath,
            'subtitle_relative_path' => $subtitleRelativePath,
            'subtitle_full_path' => $subtitleFullPath,
            'duration_ms' => $durationMs,
            'voice' => $selectedVoice,
        ];

        $this->recordGenerationLog(
            videoProjectId: $videoProjectId,
            storyboardSceneId: $storyboardSceneId,
            voice: $selectedVoice,
            status: 'success',
            latencyMs: $latencyMs,
            requestPayload: [
                'voice' => $selectedVoice,
                'text' => $cleanText,
            ],
            responsePayload: $result,
            errorMessage: null
        );

        return $result;
    }

    /**
     * Đo độ dài thực tế của file âm thanh (tính bằng mili-giây) thông qua `ffprobe`.
     */
    public function measureAudioDurationMs(string $audioFullPath): int
    {
        if (! is_file($audioFullPath)) {
            throw new RuntimeException("Không tìm thấy file âm thanh để đo thời lượng: {$audioFullPath}");
        }

        $command = [
            $this->ffprobeBinary,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $audioFullPath,
        ];

        $process = new Process($command);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return 4500;
        }

        $rawDuration = trim($process->getOutput());
        $seconds = (float) $rawDuration;

        if ($seconds <= 0.0) {
            return 4500;
        }

        return (int) round($seconds * 1000.0);
    }

    /**
     * Kiểm tra và chuẩn hóa mã giọng đọc tiếng Việt hợp lệ.
     * Bắt buộc trả về đúng giọng đọc đã chọn (`vi-VN-NamMinhNeural` hoặc `vi-VN-HoaiMyNeural`).
     */
    public function resolveVietnameseVoice(?string $voice): string
    {
        if ($voice === null || trim($voice) === '') {
            return $this->defaultVoice;
        }

        $trimmed = trim($voice);

        if ($trimmed === self::VOICE_FEMALE || stripos($trimmed, 'HoaiMy') !== false) {
            return self::VOICE_FEMALE;
        }

        if ($trimmed === self::VOICE_MALE || stripos($trimmed, 'NamMinh') !== false) {
            return self::VOICE_MALE;
        }

        return $this->defaultVoice;
    }

    /**
     * Chuẩn hóa file phụ đề do `edge-tts` xuất ra (có thể ở dạng SRT) sang chuẩn WebVTT (`.vtt`),
     * hoặc tự động chia cụm từ theo `$durationMs` nếu file phụ đề trống.
     */
    private function normalizeOrGenerateVtt(string $text, int $durationMs, string $vttFullPath): void
    {
        if (is_file($vttFullPath) && filesize($vttFullPath) > 0) {
            $rawContent = (string) file_get_contents($vttFullPath);
            if (trim($rawContent) !== '') {
                // Nếu edge-tts v7 xuất định dạng SRT (dùng dấu phẩy cho mili-giây), chuyển sang chuẩn WebVTT
                if (! str_starts_with(ltrim($rawContent), 'WEBVTT')) {
                    $vttContent = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $rawContent) ?? $rawContent;
                    file_put_contents($vttFullPath, "WEBVTT\n\n" . ltrim($vttContent));
                }

                return;
            }
        }

        $this->generateWordLevelVttFromText($text, $durationMs, $vttFullPath);
    }

    /**
     * Tự động chia câu thoại tiếng Việt thành các cụm từ có mốc thời gian chuẩn WebVTT (`.vtt`)
     * khớp chính xác với thời lượng `$durationMs`.
     */
    private function generateWordLevelVttFromText(string $text, int $durationMs, string $vttFullPath): void
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $totalWords = count($words);
        if ($totalWords === 0) {
            file_put_contents($vttFullPath, "WEBVTT\n\n");

            return;
        }

        $phrases = array_chunk($words, 6);
        $phraseCount = count($phrases);
        $msPerPhrase = (int) floor($durationMs / $phraseCount);

        $vttLines = ["WEBVTT\n"];
        $currentStart = 0;

        foreach ($phrases as $idx => $phraseWords) {
            $endMs = ($idx === $phraseCount - 1)
                ? $durationMs
                : ($currentStart + $msPerPhrase);

            $vttLines[] = sprintf(
                "%s --> %s\n%s\n",
                $this->formatVttTimestamp($currentStart),
                $this->formatVttTimestamp($endMs),
                implode(' ', $phraseWords)
            );

            $currentStart = $endMs;
        }

        file_put_contents($vttFullPath, implode("\n", $vttLines));
    }

    /**
     * Định dạng mili-giây sang chuỗi timestamp chuẩn WebVTT (`HH:MM:SS.mmm`).
     */
    private function formatVttTimestamp(int $ms): string
    {
        $ms = max(0, $ms);
        $millis = $ms % 1000;
        $totalSec = intdiv($ms, 1000);
        $sec = $totalSec % 60;
        $totalMin = intdiv($totalSec, 60);
        $min = $totalMin % 60;
        $hours = intdiv($totalMin, 60);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $min, $sec, $millis);
    }

    /**
     * Tạo thư mục lưu trữ nếu chưa tồn tại.
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Không thể khởi tạo thư mục lưu trữ: {$directory}");
        }
    }

    /**
     * Ghi nhận lịch sử gọi CLI `edge-tts` vào bảng `generation_logs` (chi phí = $0.0000).
     *
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function recordGenerationLog(
        ?int $videoProjectId,
        ?int $storyboardSceneId,
        string $voice,
        string $status,
        int $latencyMs,
        array $requestPayload,
        ?array $responsePayload,
        ?string $errorMessage
    ): void {
        try {
            GenerationLog::query()->create([
                'video_project_id' => $videoProjectId,
                'storyboard_scene_id' => $storyboardSceneId,
                'provider' => 'edge_tts',
                'model_name' => $voice,
                'action_type' => 'synthesize_tts',
                'status' => $status,
                'cost_usd' => 0.0000,
                'latency_ms' => $latencyMs,
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable) {
            // Bỏ qua lỗi ghi log nếu chạy độc lập ngoài DB
        }
    }
}
