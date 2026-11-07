<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dịch vụ lắp ráp và kết xuất Video bằng FFmpeg 7.x trên máy chủ vật lý CentOS 8.
 *
 * Tuân thủ nghiêm ngặt quy tắc an toàn tài nguyên LiteSpeed Web Server:
 *  - Mọi tiến trình FFmpeg luôn chạy với độ ưu tiên thấp (`nice -n 10`) và giới hạn `-threads 6`.
 *  - Hỗ trợ `zoompan` 60fps mượt mà (upscale 4320px chống rung giật pixel) cho Eco Mode.
 *  - Hỗ trợ nối cảnh `xfade` tính toán offset đệ quy chính xác từng mili-giây, không lệch frame.
 *  - Hỗ trợ trộn âm thanh đa lớp (`amix` + `sidechaincompress` tự động ducking nhạc nền theo giọng đọc tiếng Việt).
 */
class FfmpegVideoCompositorService
{
    /**
     * Danh sách hiệu ứng chuyển cảnh `xfade` hợp lệ được hệ thống hỗ trợ.
     *
     * @var array<int, string>
     */
    private const SUPPORTED_TRANSITIONS = [
        'fade',
        'wipeleft',
        'wiperight',
        'smoothleft',
        'smoothright',
        'circleopen',
        'circleclose',
        'dissolve',
    ];

    private readonly string $ffmpegBinary;

    private readonly string $ffprobeBinary;

    private readonly int $maxThreads;

    private readonly int $nicePriority;

    public function __construct()
    {
        $this->ffmpegBinary = (string) config('services.ffmpeg.ffmpeg_binary', '/usr/local/bin/ffmpeg');
        $this->ffprobeBinary = (string) config('services.ffmpeg.ffprobe_binary', '/usr/local/bin/ffprobe');
        $this->maxThreads = max(1, min(8, (int) config('services.ffmpeg.max_threads', 6)));
        $this->nicePriority = (int) config('services.ffmpeg.nice_priority', 10);
    }

    /**
     * Phục vụ chế độ `Eco Mode`: Chuyển 1 ảnh tĩnh AI thành video chuyển động 60fps mượt mà (Ken Burns)
     * sử dụng filter `zoompan` (upscale 4320px chống giật pixel) khớp chính xác với thời lượng câu đọc ($durationMs).
     *
     * @param  string  $imagePath  Đường dẫn tuyệt đối tới file ảnh tĩnh (.jpg/.png)
     * @param  int  $durationMs  Thời lượng mục tiêu tính bằng mili-giây (khớp với giọng đọc)
     * @param  string  $outputPath  Đường dẫn tuyệt đối lưu file video `.mp4`
     * @param  string  $aspectRatio  Tỷ lệ khung hình (`9:16` hoặc `16:9`)
     * @param  string  $cameraMotion  Kiểu chuyển động camera (`zoom_in`, `zoom_out`, `pan_right`, `pan_left`)
     */
    public function generateKenBurnsClip(
        string $imagePath,
        int $durationMs,
        string $outputPath,
        string $aspectRatio = '9:16',
        string $cameraMotion = 'zoom_in'
    ): bool {
        if (! is_file($imagePath)) {
            throw new RuntimeException("Không tìm thấy file ảnh đầu vào cho Ken Burns: {$imagePath}");
        }

        $this->ensureParentDirectory($outputPath);

        $fps = 60;
        $durationSeconds = max(1.0, round($durationMs / 1000.0, 3));
        $totalFrames = max(60, (int) ceil($durationSeconds * $fps));
        $resolution = $aspectRatio === '16:9' ? '1920x1080' : '1080x1920';

        $upscaleWidth = PHP_OS_FAMILY === 'Linux' ? 4320 : 2160;

        // Công thức zoompan 60fps: Upscale chiều rộng trước khi zoompan để loại bỏ hiện tượng rung pixel
        $zoompanExpr = match ($cameraMotion) {
            'zoom_out' => sprintf(
                "scale=%d:-1,zoompan=z='if(eq(on,1),1.25,max(1.0,zoom-0.0005))':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=%d:s=%s:fps=%d,setsar=1,format=yuv420p",
                $upscaleWidth,
                $totalFrames,
                $resolution,
                $fps
            ),
            'pan_right' => sprintf(
                "scale=%d:-1,zoompan=z='1.16':x='(iw-iw/zoom)*(on/%d)':y='(ih-ih/zoom)/2':d=%d:s=%s:fps=%d,setsar=1,format=yuv420p",
                $upscaleWidth,
                $totalFrames,
                $totalFrames,
                $resolution,
                $fps
            ),
            'pan_left' => sprintf(
                "scale=%d:-1,zoompan=z='1.16':x='(iw-iw/zoom)*(1-on/%d)':y='(ih-ih/zoom)/2':d=%d:s=%s:fps=%d,setsar=1,format=yuv420p",
                $upscaleWidth,
                $totalFrames,
                $totalFrames,
                $resolution,
                $fps
            ),
            default => sprintf(
                "scale=%d:-1,zoompan=z='min(zoom+0.0005,1.25)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=%d:s=%s:fps=%d,setsar=1,format=yuv420p",
                $upscaleWidth,
                $totalFrames,
                $resolution,
                $fps
            ),
        };

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
            '-loop',
            '1',
            '-i',
            $imagePath,
            '-vf',
            $zoompanExpr,
            '-t',
            number_format($durationSeconds, 3, '.', ''),
            '-r',
            (string) $fps,
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '21',
            '-x264-params',
            "threads={$this->maxThreads}",
            '-pix_fmt',
            'yuv420p',
            '-an',
            $outputPath,
        ];

        $this->runFfmpegProcess($args, 300);

        return is_file($outputPath) && filesize($outputPath) > 0;
    }

    /**
     * Nối danh sách các video bối cảnh bằng bộ lọc `xfade` của FFmpeg.
     * Áp dụng công thức đệ quy tính toán thời điểm bù trừ chồng lấn (`offset`) giữa các cảnh
     * để đảm bảo video cuối không bao giờ bị lệch khung hình (frame drift).
     *
     * @param  array<int, string>  $sceneVideoPaths  Danh sách đường dẫn tuyệt đối tới các file video từng cảnh
     * @param  array<int, string>  $transitionTypes  Danh sách kiểu chuyển cảnh (`fade`, `wipeleft`, `smoothleft`, `circleopen`, `dissolve`)
     * @param  string  $outputVideoPath  Đường dẫn lưu video đã nối
     * @param  float  $transitionDurationSec  Thời lượng hiệu ứng chuyển cảnh (mặc định 0.5 giây)
     */
    public function stitchScenesWithTransitions(
        array $sceneVideoPaths,
        array $transitionTypes,
        string $outputVideoPath,
        float $transitionDurationSec = 0.500
    ): bool {
        $sceneVideoPaths = array_values($sceneVideoPaths);
        $transitionTypes = array_values($transitionTypes);
        $count = count($sceneVideoPaths);

        if ($count === 0) {
            throw new RuntimeException('Danh sách video phân cảnh cần nối đang trống.');
        }

        $this->ensureParentDirectory($outputVideoPath);

        // Nếu chỉ có 1 cảnh duy nhất, chuẩn hóa langsung sang 60fps
        if ($count === 1) {
            $singleArgs = [
                '-y',
                '-threads',
                (string) $this->maxThreads,
                '-i',
                $sceneVideoPaths[0],
                '-vf',
                'fps=60,settb=AVTB,setsar=1,format=yuv420p',
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                '21',
                '-x264-params',
                "threads={$this->maxThreads}",
                '-an',
                $outputVideoPath,
            ];
            $this->runFfmpegProcess($singleArgs, 300);

            return is_file($outputVideoPath) && filesize($outputVideoPath) > 0;
        }

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
        ];

        $durations = [];
        foreach ($sceneVideoPaths as $idx => $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Không tìm thấy file video của cảnh #" . ($idx + 1) . ": {$path}");
            }
            $args[] = '-i';
            $args[] = $path;
            $durations[$idx] = $this->probeMediaDurationSeconds($path);
        }

        $filterParts = [];

        // Bước 1: Chuẩn hóa từng luồng video về cùng 60fps, timebase AVTB, SAR 1:1
        // Cộng bù thêm $transitionDurationSec bằng tpad clone ở cuối mỗi cảnh (trừ cảnh cuối)
        // để tổng thời lượng hình ảnh luôn khớp 100% với tổng thời lượng giọng đọc sau khi xfade chồng lấn!
        for ($i = 0; $i < $count; $i++) {
            if ($i < $count - 1) {
                $filterParts[] = sprintf(
                    '[%d:v]fps=60,settb=AVTB,setsar=1,format=yuv420p,tpad=stop_mode=clone:stop_duration=%.3f[v%d]',
                    $i,
                    $transitionDurationSec,
                    $i
                );
                // Khi đã cộng tpad_duration vào cuối clip i, thời lượng thực tế của clip i tăng thêm $transitionDurationSec
                $durations[$i] += $transitionDurationSec;
            } else {
                $filterParts[] = sprintf(
                    '[%d:v]fps=60,settb=AVTB,setsar=1,format=yuv420p[v%d]',
                    $i,
                    $i
                );
            }
        }

        // Bước 2: Áp dụng công thức đệ quy tính offset cho chuỗi xfade:
        // Offset_k = (Tổng thời lượng các clip từ 0 -> k-1) - k * T_trans
        $accumulatedDuration = $durations[0];
        $prevStreamLabel = '[v0]';

        for ($k = 1; $k < $count; $k++) {
            $rawTransition = strtolower(trim((string) ($transitionTypes[$k - 1] ?? 'fade')));
            $transition = in_array($rawTransition, self::SUPPORTED_TRANSITIONS, true)
                ? $rawTransition
                : 'fade';

            $offset = max(0.100, $accumulatedDuration - ($k * $transitionDurationSec));
            $outLabel = ($k === $count - 1) ? '[v_stitched]' : "[vx{$k}]";

            $filterParts[] = sprintf(
                '%s[v%d]xfade=transition=%s:duration=%.3f:offset=%.3f%s',
                $prevStreamLabel,
                $k,
                $transition,
                $transitionDurationSec,
                $offset,
                $outLabel
            );

            $accumulatedDuration += $durations[$k];
            $prevStreamLabel = $outLabel;
        }

        $filterComplex = implode(';', $filterParts);

        $args = array_merge($args, [
            '-filter_complex',
            $filterComplex,
            '-map',
            '[v_stitched]',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '21',
            '-x264-params',
            "threads={$this->maxThreads}",
            '-r',
            '60',
            '-pix_fmt',
            'yuv420p',
            '-an',
            $outputVideoPath,
        ]);

        $this->runFfmpegProcess($args, 600);

        return is_file($outputVideoPath) && filesize($outputVideoPath) > 0;
    }

    /**
     * Trộn âm thanh đa tầng bằng `filter_complex`:
     *  - Giọng đọc tiếng Việt: 0dB (volume = 1.0)
     *  - Các file SFX đặt tại đúng timestamp tương ứng: -6dB (volume = 0.501)
     *  - Nhạc nền BGM: -18dB (volume = 0.126) + tích hợp `sidechaincompress` (Audio Ducking)
     *    để tự động hạ nhỏ nhạc nền khi có giọng đọc tiếng Việt cất lên và nâng dần khi kết thúc câu.
     *
     * @param  string  $videoPath  Đường dẫn file video hình ảnh đã ghép nối
     * @param  string  $voiceTrackPath  Đường dẫn file giọng đọc tổng thể (hoặc cảnh)
     * @param  array<int, array{file_path: string, start_ms: int}>  $sfxTimeline  Danh sách SFX kèm mốc mili-giây bắt đầu
     * @param  string|null  $bgmTrackPath  Đường dẫn file nhạc nền BGM (tùy chọn)
     * @param  string  $finalOutputPath  Đường dẫn xuất file `.mp4` đã trộn âm thanh
     */
    public function mixAudioLayers(
        string $videoPath,
        string $voiceTrackPath,
        array $sfxTimeline,
        ?string $bgmTrackPath,
        string $finalOutputPath
    ): bool {
        if (! is_file($videoPath)) {
            throw new RuntimeException("Không tìm thấy file video đầu vào để trộn âm thanh: {$videoPath}");
        }
        if (! is_file($voiceTrackPath)) {
            throw new RuntimeException("Không tìm thấy file giọng đọc tiếng Việt: {$voiceTrackPath}");
        }

        $this->ensureParentDirectory($finalOutputPath);

        $videoDurationSec = $this->probeMediaDurationSeconds($videoPath);

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
            '-i',
            $videoPath,       // Input [0:v]
            '-i',
            $voiceTrackPath,  // Input [1:a] -> Voiceover (0dB)
        ];

        $nextInputIndex = 2;

        // Bắt buộc kích hoạt nhạc nền BGM: Nếu $bgmTrackPath trống hoặc không tồn tại, tự động lấy storage/app/sfx_library/ambient_bgm.mp3
        $resolvedBgmPath = ($bgmTrackPath !== null && trim($bgmTrackPath) !== '' && is_file($bgmTrackPath))
            ? $bgmTrackPath
            : storage_path('app/sfx_library/ambient_bgm.mp3');

        if (! is_file($resolvedBgmPath)) {
            $fallbackBgm = storage_path('app/sfx_library/bgm/ambient_bgm.mp3');
            if (is_file($fallbackBgm)) {
                $resolvedBgmPath = $fallbackBgm;
            }
        }

        $hasBgm = is_file($resolvedBgmPath);
        $bgmInputIndex = null;

        if ($hasBgm) {
            $args[] = '-stream_loop';
            $args[] = '-1';
            $args[] = '-i';
            $args[] = $resolvedBgmPath;
            $bgmInputIndex = $nextInputIndex++;
        }

        $validSfxItems = [];
        foreach ($sfxTimeline as $sfxItem) {
            $sfxFile = (string) ($sfxItem['file_path'] ?? '');
            $startMs = max(0, (int) ($sfxItem['start_ms'] ?? 0));
            if ($sfxFile !== '' && is_file($sfxFile)) {
                $args[] = '-i';
                $args[] = $sfxFile;
                $validSfxItems[] = [
                    'input_index' => $nextInputIndex++,
                    'start_ms' => $startMs,
                ];
            }
        }

        $filterParts = [];

        // 1. Luồng giọng đọc chính (0dB), nhân đôi thành 2 nhánh nếu có BGM để làm tín hiệu điều khiển sidechaincompress
        if ($hasBgm) {
            $filterParts[] = sprintf(
                '[1:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=0dB,apad=whole_dur=%.3f,asplit=2[vo_main][vo_sc]',
                $videoDurationSec
            );
            // 2. Luồng nhạc nền BGM (-18dB) + sidechaincompress (Audio Ducking tự động hạ âm lượng khi có giọng đọc tiếng Việt)
            $filterParts[] = sprintf(
                '[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,atrim=0:%.3f,asetpts=PTS-STARTPTS,volume=-18dB[bgm_base]',
                $bgmInputIndex,
                $videoDurationSec
            );
            $filterParts[] = '[bgm_base][vo_sc]sidechaincompress=threshold=0.08:ratio=4:attack=45:release=400:makeup=2[bgm_ducked]';
        } else {
            $filterParts[] = sprintf(
                '[1:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=0dB,apad=whole_dur=%.3f[vo_main]',
                $videoDurationSec
            );
        }

        // 3. Các luồng hiệu ứng âm thanh SFX (-6dB) đặt đúng timestamp bằng adelay
        $sfxLabels = [];
        foreach ($validSfxItems as $idx => $sfxMeta) {
            $label = "[sfx_{$idx}]";
            $delayMs = $sfxMeta['start_ms'];
            $filterParts[] = sprintf(
                '[%d:a]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=-6dB,adelay=%d|%d%s',
                $sfxMeta['input_index'],
                $delayMs,
                $delayMs,
                $label
            );
            $sfxLabels[] = $label;
        }

        // 4. Hợp nhất tất cả các lớp âm thanh qua amix và giới hạn đỉnh âm lượng bằng alimiter
        $mixInputs = ['[vo_main]'];
        if ($hasBgm) {
            $mixInputs[] = '[bgm_ducked]';
        }
        foreach ($sfxLabels as $sfxLabel) {
            $mixInputs[] = $sfxLabel;
        }

        $totalAudioInputs = count($mixInputs);
        if ($totalAudioInputs === 1) {
            $filterParts[] = '[vo_main]alimiter=limit=0.95[a_final]';
        } else {
            $filterParts[] = sprintf(
                '%samix=inputs=%d:duration=first:dropout_transition=2:normalize=0,alimiter=limit=0.95[a_final]',
                implode('', $mixInputs),
                $totalAudioInputs
            );
        }

        $args = array_merge($args, [
            '-filter_complex',
            implode(';', $filterParts),
            '-map',
            '0:v:0',
            '-map',
            '[a_final]',
            '-c:v',
            'copy',
            '-c:a',
            'aac',
            '-b:a',
            '192k',
            '-shortest',
            '-movflags',
            '+faststart',
            $finalOutputPath,
        ]);

        $this->runFfmpegProcess($args, 600);

        return is_file($finalOutputPath) && filesize($finalOutputPath) > 0;
    }

    /**
     * Đóng dấu (Burn-in) file phụ đề `.ass` vào video bằng filter `ass` của FFmpeg (sử dụng `libass`).
     *
     * @param  string  $videoPath  Đường dẫn file video đầu vào
     * @param  string  $assSubtitlePath  Đường dẫn file phụ đề `.ass`
     * @param  string  $finalOutputPath  Đường dẫn lưu file video `.mp4` hoàn chỉnh cuối cùng
     */
    public function burnSubtitles(
        string $videoPath,
        string $assSubtitlePath,
        string $finalOutputPath
    ): bool {
        if (! is_file($videoPath)) {
            throw new RuntimeException("Không tìm thấy file video để đóng phụ đề: {$videoPath}");
        }
        if (! is_file($assSubtitlePath)) {
            throw new RuntimeException("Không tìm thấy file phụ đề .ass: {$assSubtitlePath}");
        }

        $this->ensureParentDirectory($finalOutputPath);

        // Chuyển sang đường dẫn tương đối (không chứa dấu ':' ổ đĩa Windows) nếu nằm trong thư mục dự án
        $normalizedBase = rtrim(str_replace('\\', '/', base_path()), '/') . '/';
        $normalizedAss = str_replace('\\', '/', $assSubtitlePath);
        if (str_starts_with($normalizedAss, $normalizedBase)) {
            $escapedAssPath = substr($normalizedAss, strlen($normalizedBase));
        } else {
            $escapedAssPath = str_replace(':', '\\:', $normalizedAss);
        }

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
            '-i',
            $videoPath,
            '-vf',
            "ass='{$escapedAssPath}'",
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-crf',
            '21',
            '-x264-params',
            "threads={$this->maxThreads}",
            '-c:a',
            'copy',
            '-movflags',
            '+faststart',
            $finalOutputPath,
        ];

        $this->runFfmpegProcess($args, 600);

        return is_file($finalOutputPath) && filesize($finalOutputPath) > 0;
    }

    /**
     * Ghép nối tuần tự nhiều file giọng đọc `.mp3` của từng phân cảnh thành một trục giọng đọc duy nhất.
     *
     * @param  array<int, string>  $voiceFilePaths  Danh sách đường dẫn tuyệt đối tới các file `.mp3`
     * @param  string  $outputAudioPath  Đường dẫn lưu file `.mp3` hoặc `.wav` đã nối
     */
    public function concatenateVoiceTracks(array $voiceFilePaths, string $outputAudioPath): bool
    {
        $voiceFilePaths = array_values(array_filter($voiceFilePaths, static fn (string $p): bool => is_file($p)));
        $count = count($voiceFilePaths);

        if ($count === 0) {
            throw new RuntimeException('Không có file giọng đọc hợp lệ để ghép nối.');
        }

        $this->ensureParentDirectory($outputAudioPath);

        if ($count === 1) {
            copy($voiceFilePaths[0], $outputAudioPath);

            return is_file($outputAudioPath);
        }

        $args = [
            '-y',
            '-threads',
            (string) $this->maxThreads,
        ];

        $concatInputs = [];
        foreach ($voiceFilePaths as $idx => $path) {
            $args[] = '-i';
            $args[] = $path;
            $concatInputs[] = "[{$idx}:a]";
        }

        $filterComplex = sprintf(
            '%sconcat=n=%d:v=0:a=1[a_concat]',
            implode('', $concatInputs),
            $count
        );

        $args = array_merge($args, [
            '-filter_complex',
            $filterComplex,
            '-map',
            '[a_concat]',
            '-c:a',
            'libmp3lame',
            '-q:a',
            '2',
            $outputAudioPath,
        ]);

        $this->runFfmpegProcess($args, 300);

        return is_file($outputAudioPath) && filesize($outputAudioPath) > 0;
    }

    /**
     * Đo thời lượng chính xác của file media (giây) bằng `ffprobe`.
     */
    public function probeMediaDurationSeconds(string $mediaPath): float
    {
        $command = [
            $this->ffprobeBinary,
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $mediaPath,
        ];

        $process = new Process($command);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                sprintf('Lỗi ffprobe khi đo thời lượng file [%s]: %s', $mediaPath, trim($process->getErrorOutput()))
            );
        }

        $seconds = (float) trim($process->getOutput());

        return $seconds > 0.0 ? $seconds : 1.0;
    }

    /**
     * Thực thi lệnh FFmpeg thông qua `Symfony\Component\Process\Process`
     * với tiền tố `nice -n 10` (trên Linux/CentOS 8) và giới hạn `-threads 6`.
     *
     * @param  array<int, string>  $ffmpegArguments
     */
    private function runFfmpegProcess(array $ffmpegArguments, int $timeoutSeconds = 600): void
    {
        @set_time_limit(0);
        // Trên môi trường Linux/CentOS 8, luôn bọc tiền tố `nice -n 10` trước binary ffmpeg
        if (PHP_OS_FAMILY === 'Linux') {
            $command = array_merge(
                ['nice', '-n', (string) $this->nicePriority, $this->ffmpegBinary],
                $ffmpegArguments
            );
        } else {
            $command = array_merge([$this->ffmpegBinary], $ffmpegArguments);
        }

        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                sprintf(
                    'Lệnh FFmpeg thất bại (Exit Code %d): %s',
                    $process->getExitCode() ?? -1,
                    trim($process->getErrorOutput() ?: $process->getOutput())
                )
            );
        }
    }

    /**
     * Đảm bảo thư mục cha của file đầu ra đã tồn tại.
     */
    private function ensureParentDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Không thể tạo thư mục lưu trữ: {$dir}");
        }
    }
}
