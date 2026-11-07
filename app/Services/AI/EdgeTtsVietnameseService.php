<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\GenerationLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Dịch vụ tổng hợp giọng đọc tiếng Việt miễn phí bằng Microsoft Edge Neural TTS CLI (`edge-tts` - $0.00)
 * và đo thời lượng thực tế bằng `ffprobe` trực tiếp trên máy chủ CentOS 8.
 *
 * Hỗ trợ 2 giọng đọc Neural chuẩn tiếng Việt:
 *  - Nam: `vi-VN-NamMinhNeural`
 *  - Nữ:  `vi-VN-HoaiMyNeural`
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
     * Thực thi lệnh CLI `edge-tts` để xuất file âm thanh `.mp3`, trích xuất phụ đề `.vtt`
     * kèm word-level timestamps và đo chính xác thời lượng bằng `ffprobe` (mili-giây).
     *
     * @param  string  $text  Nội dung câu thoại tiếng Việt cần đọc
     * @param  string|null  $voice  Mã giọng đọc (`vi-VN-NamMinhNeural` hoặc `vi-VN-HoaiMyNeural`)
     * @param  string|null  $filePrefix  Tiền tố tên file đầu ra (VD: `project_1_scene_2`)
     * @param  int|null  $videoProjectId  ID dự án để ghi log
     * @param  int|null  $storyboardSceneId  ID phân cảnh để ghi log
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

        $selectedVoice = $this->resolveVietnameseVoice($voice);
        $baseName = $filePrefix !== null && $filePrefix !== ''
            ? Str::slug($filePrefix, '_')
            : 'tts_' . Str::random(12);

        $audioRelativePath = "audio/{$baseName}.mp3";
        $subtitleRelativePath = "subtitles/{$baseName}.vtt";

        $disk = Storage::disk('public');
        $audioFullPath = $disk->path($audioRelativePath);
        $subtitleFullPath = $disk->path($subtitleRelativePath);

        // Đảm bảo thư mục cha tồn tại trên CentOS 8
        $this->ensureDirectoryExists(dirname($audioFullPath));
        $this->ensureDirectoryExists(dirname($subtitleFullPath));

        $startTime = microtime(true);

        // Sử dụng mảng tham số trong Symfony Process để chống tuyệt đối lỗi Shell Injection
        $command = [
            $this->edgeTtsBinary,
            '--voice',
            $selectedVoice,
            '--rate=' . $this->defaultRate,
            '--pitch=' . $this->defaultPitch,
            '--text',
            $cleanText,
            '--write-media',
            $audioFullPath,
            '--write-subtitles',
            $subtitleFullPath,
        ];

        try {
            $process = new Process($command);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    sprintf('Lệnh edge-tts thất bại: %s', trim($process->getErrorOutput() ?: $process->getOutput()))
                );
            }

            if (! is_file($audioFullPath) || filesize($audioFullPath) === 0) {
                throw new RuntimeException("File âm thanh MP3 không được tạo ra tại: {$audioFullPath}");
            }

            // Đo thời lượng thực tế bằng ffprobe (mili-giây)
            $durationMs = $this->measureAudioDurationMs($audioFullPath);
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
                    'rate' => $this->defaultRate,
                ],
                responsePayload: $result,
                errorMessage: null
            );

            return $result;
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

            $this->recordGenerationLog(
                videoProjectId: $videoProjectId,
                storyboardSceneId: $storyboardSceneId,
                voice: $selectedVoice,
                status: 'failed',
                latencyMs: $latencyMs,
                requestPayload: [
                    'voice' => $selectedVoice,
                    'text' => $cleanText,
                ],
                responsePayload: null,
                errorMessage: $e->getMessage()
            );

            throw new RuntimeException(
                sprintf('Lỗi khi tạo giọng đọc tiếng Việt Edge-TTS: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Đo độ dài thực tế của file âm thanh (tính bằng mili-giây) thông qua `ffprobe`.
     *
     * @param  string  $audioFullPath  Đường dẫn tuyệt đối tới file `.mp3`
     * @return int Thời lượng tính bằng mili-giây (`duration_ms`)
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
            throw new RuntimeException(
                sprintf('Không thể đo thời lượng bằng ffprobe: %s', trim($process->getErrorOutput()))
            );
        }

        $rawDuration = trim($process->getOutput());
        $seconds = (float) $rawDuration;

        if ($seconds <= 0.0) {
            throw new RuntimeException("Thời lượng âm thanh trả về từ ffprobe không hợp lệ: '{$rawDuration}'");
        }

        return (int) round($seconds * 1000.0);
    }

    /**
     * Kiểm tra và chuẩn hóa mã giọng đọc tiếng Việt hợp lệ.
     */
    private function resolveVietnameseVoice(?string $voice): string
    {
        $allowedVoices = [
            self::VOICE_MALE,
            self::VOICE_FEMALE,
        ];

        if ($voice !== null && in_array($voice, $allowedVoices, true)) {
            return $voice;
        }

        return $this->defaultVoice;
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
