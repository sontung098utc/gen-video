<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ProjectType;
use App\Models\ProductProfile;
use App\Models\StoryboardScene;
use App\Models\VideoProject;
use App\Services\AI\GeminiFreeLlmService;
use App\Services\Workflow\WorkflowOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * BƯỚC 2 (Queue: `ai-orchestration`): Phân tích chủ đề kiến thức hoặc hồ sơ sản phẩm
 * bằng Google Gemini 2.5 Flash Free Tier ($0.00), tạo `master_style_prompt` và khởi tạo
 * danh sách các bản ghi `StoryboardScene` trong cơ sở dữ liệu.
 */
class Step2_GenerateScriptAndScenesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $videoProjectId
    ) {
        $this->onQueue('ai-orchestration');
    }

    public function handle(
        GeminiFreeLlmService $geminiService,
        WorkflowOrchestrator $orchestrator
    ): void {
        $project = VideoProject::query()->with('productProfile')->findOrFail($this->videoProjectId);

        try {
            $reaVideoRelPath = null;
            if (preg_match('/\[rea_video_remake:([^\]]+)\]/', (string) $project->master_style_prompt, $reaMatch) === 1) {
                $reaVideoRelPath = trim($reaMatch[1]);
            }

            $referenceVideoRelPath = null;
            if (preg_match('/\[reference_video:([^\]]+)\]/', (string) $project->master_style_prompt, $refMatch) === 1) {
                $referenceVideoRelPath = trim($refMatch[1]);
            }

            if ($reaVideoRelPath !== null && \Illuminate\Support\Facades\Storage::disk('public')->exists($reaVideoRelPath)) {
                $reaThreshold = 0.28;
                if (preg_match('/\[rea_threshold:([0-9.]+)\]/', (string) $project->master_style_prompt, $thMatch) === 1) {
                    $reaThreshold = max(0.10, min(0.60, (float) $thMatch[1]));
                }
                $analysisResult = $this->runReaVideoBreakdownAndRemake(
                    project: $project,
                    reaVideoRelPath: $reaVideoRelPath,
                    threshold: $reaThreshold,
                    geminiService: $geminiService
                );
            } elseif ($referenceVideoRelPath !== null && \Illuminate\Support\Facades\Storage::disk('public')->exists($referenceVideoRelPath)) {
                $refFullPath = \Illuminate\Support\Facades\Storage::disk('public')->path($referenceVideoRelPath);
                $remakeBrief = (string) ($project->topic_or_brief ?: $project->title);
                $analysisResult = $geminiService->analyzeReferenceVideo($refFullPath, $remakeBrief, $project->id);
            } elseif ($project->project_type === ProjectType::PRODUCT) {
                $analysisResult = $this->runProductAnalysis($project, $geminiService);
            } else {
                $topic = (string) ($project->topic_or_brief ?: $project->title);
                $analysisResult = $geminiService->analyzeKnowledgeTopic($topic, $project->id);
            }

            $scenesData = (array) ($analysisResult['scenes'] ?? []);
            if ($scenesData === []) {
                throw new RuntimeException('Gemini 2.5 Flash không trả về danh sách phân cảnh (scenes) hợp lệ.');
            }

            DB::transaction(function () use ($project, $analysisResult, $scenesData): void {
                // Bảo toàn các thẻ cấu hình Hypit ([motion_graphics], [template:...], [subtitle:...]) đã chọn khi tạo dự án
                $existingTags = [];
                if (preg_match_all('/\[(?:motion_graphics|template:[a-z_]+|subtitle:[a-z_]+|reference_video:[^\]]+|rea_video_remake:[^\]]+|rea_threshold:[0-9.]+)\]/i', (string) $project->master_style_prompt, $tagMatches)) {
                    $existingTags = array_unique($tagMatches[0]);
                }
                $generatedStyle = trim((string) ($analysisResult['master_style_prompt'] ?? $project->master_style_prompt));
                $finalMasterStyle = trim(implode(' ', $existingTags) . ' ' . $generatedStyle);

                // Cập nhật tiêu đề gợi ý và master_style_prompt cho dự án
                $project->update([
                    'title' => (string) ($analysisResult['title'] ?? $project->title),
                    'master_style_prompt' => $finalMasterStyle !== '' ? $finalMasterStyle : null,
                ]);

                // Lưu kết quả phân tích chân dung khách hàng vào ProductProfile (nếu là dự án PRODUCT)
                if ($project->productProfile instanceof ProductProfile && isset($analysisResult['customer_analysis'])) {
                    $existingJson = is_array($project->productProfile->customer_analysis_json)
                        ? $project->productProfile->customer_analysis_json
                        : [];

                    $project->productProfile->update([
                        'customer_analysis_json' => array_merge($existingJson, [
                            'customer_analysis' => $analysisResult['customer_analysis'],
                        ]),
                    ]);
                }

                // Xóa các phân cảnh cũ (nếu chạy lại bước tạo kịch bản) và khởi tạo mới
                $project->scenes()->delete();

                foreach (array_values($scenesData) as $idx => $sceneItem) {
                    $order = (int) ($sceneItem['scene_order'] ?? ($idx + 1));

                    StoryboardScene::query()->create([
                        'video_project_id' => $project->id,
                        'scene_order' => $order,
                        'narration_text' => trim((string) ($sceneItem['narration_text'] ?? '')),
                        'visual_prompt' => trim((string) ($sceneItem['visual_prompt'] ?? '')),
                        'sfx_prompt' => trim((string) ($sceneItem['sfx_prompt'] ?? 'whoosh')),
                        'camera_motion' => (string) ($sceneItem['camera_motion'] ?? 'zoom_in'),
                        'transition_type' => (string) ($sceneItem['transition_type'] ?? 'fade'),
                        'transition_duration_ms' => 500,
                        'duration_ms' => 0,
                    ]);
                }
            });

            // Chuyển sang Cổng kiểm duyệt số 2 (WAITING_APPROVAL_SCRIPT) hoặc đi tiếp sang GENERATING_AUDIO
            $orchestrator->dispatchNextStep($project);
        } catch (Throwable $e) {
            $orchestrator->markAsFailed($project, 'Step 2 (Biên tập Kịch bản & Phân cảnh) thất bại: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Chạy script `scripts/rea_video_breakdown.py` (tách Shot bằng FFmpeg theo kiến trúc JuneYaooo/rea-video),
     * gửi khung hình từng Shot lên Gemini Multimodal để đảo ngược Prompt & viết kịch bản Remake khớp từng Shot,
     * và lưu báo cáo `breakdown.json` + `VIDEO.md` vào `storage/app/public/rea_video/project_{id}/`.
     *
     * @return array<string, mixed>
     */
    private function runReaVideoBreakdownAndRemake(
        VideoProject $project,
        string $reaVideoRelPath,
        float $threshold,
        GeminiFreeLlmService $geminiService
    ): array {
        $disk = Storage::disk('public');
        $inputVideoPath = $disk->path($reaVideoRelPath);
        $outputDir = $disk->path("rea_video/project_{$project->id}");
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $pythonBin = (string) config('services.python.binary', 'python');
        $ffmpegBin = (string) config('services.ffmpeg.ffmpeg_binary', '/usr/local/bin/ffmpeg');
        $ffprobeBin = (string) config('services.ffmpeg.ffprobe_binary', '/usr/local/bin/ffprobe');
        $maxThreads = max(1, min(6, (int) config('services.ffmpeg.max_threads', 6)));

        $process = new \Symfony\Component\Process\Process([
            $pythonBin,
            base_path('scripts/rea_video_breakdown.py'),
            '--input',
            $inputVideoPath,
            '--output-dir',
            $outputDir,
            '--ffmpeg',
            $ffmpegBin,
            '--ffprobe',
            $ffprobeBin,
            '--threshold',
            (string) $threshold,
            '--max-shots',
            '10',
            '--threads',
            (string) $maxThreads,
        ], base_path(), [
            'OMP_NUM_THREADS' => '6',
            'ORT_NUM_THREADS' => '6',
        ]);
        $process->setTimeout(180);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Lỗi Rea-Video Shot Breakdown: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $breakdownPath = $outputDir . DIRECTORY_SEPARATOR . 'breakdown.json';
        if (! is_file($breakdownPath)) {
            throw new RuntimeException('Không tìm thấy file breakdown.json sau khi tách Shot.');
        }

        $breakdownData = json_decode((string) file_get_contents($breakdownPath), true) ?: [];
        $remakeBrief = (string) ($project->topic_or_brief ?: $project->title);
        $analysisResult = $geminiService->analyzeReaVideoShots($breakdownData, $remakeBrief, $project->id);

        // Bổ sung thông tin giải mã AI (shot_type, camera_angle, lighting, reverse_engineered_prompt) vào breakdown.json
        $scenesByOrder = [];
        foreach ((array) ($analysisResult['scenes'] ?? []) as $sceneItem) {
            $order = (int) ($sceneItem['scene_order'] ?? 0);
            if ($order > 0) {
                $scenesByOrder[$order] = $sceneItem;
            }
        }

        foreach ($breakdownData['shots'] ?? [] as $idx => $shot) {
            $order = (int) ($shot['shot_index'] ?? ($idx + 1));
            if (isset($scenesByOrder[$order])) {
                $breakdownData['shots'][$idx]['shot_type'] = $scenesByOrder[$order]['shot_type'] ?? 'ai_video';
                $breakdownData['shots'][$idx]['camera_angle'] = $scenesByOrder[$order]['camera_angle'] ?? '';
                $breakdownData['shots'][$idx]['lighting_and_color'] = $scenesByOrder[$order]['lighting_and_color'] ?? '';
                $breakdownData['shots'][$idx]['reverse_prompt'] = $scenesByOrder[$order]['visual_prompt'] ?? '';
                $breakdownData['shots'][$idx]['remake_narration'] = $scenesByOrder[$order]['narration_text'] ?? '';
            }
        }

        file_put_contents(
            $breakdownPath,
            json_encode($breakdownData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $analysisResult;
    }

    /**
     * Gọi Gemini 2.5 Flash Vision phân tích sản phẩm kèm ảnh thực tế và kích thước (mm).
     *
     * @return array<string, mixed>
     */
    private function runProductAnalysis(VideoProject $project, GeminiFreeLlmService $geminiService): array
    {
        $profile = $project->productProfile;
        if (! $profile instanceof ProductProfile) {
            throw new RuntimeException('Không tìm thấy ProductProfile để phân tích kịch bản sản phẩm.');
        }

        $disk = Storage::disk('public');
        $resolvedImages = [];

        if ($profile->nobg_image_path !== null && $disk->exists($profile->nobg_image_path)) {
            $resolvedImages[] = $disk->path($profile->nobg_image_path);
        }

        foreach ((array) $profile->original_image_paths as $imgPath) {
            $pathStr = (string) $imgPath;
            if (is_file($pathStr)) {
                $resolvedImages[] = $pathStr;
            } elseif ($disk->exists($pathStr)) {
                $resolvedImages[] = $disk->path($pathStr);
            }
        }

        $description = sprintf(
            '%s - %s',
            $profile->product_name,
            (string) ($profile->product_description ?: $project->topic_or_brief)
        );

        return $geminiService->analyzeProduct(
            description: $description,
            imagePaths: array_values(array_unique($resolvedImages)),
            dimensions: [
                'width_mm' => $profile->width_mm,
                'height_mm' => $profile->height_mm,
                'depth_mm' => $profile->depth_mm,
            ],
            videoProjectId: $project->id
        );
    }
}
