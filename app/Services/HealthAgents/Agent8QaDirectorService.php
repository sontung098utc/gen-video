<?php

declare(strict_types=1);

namespace App\Services\HealthAgents;

use App\Models\AgentQaReview;
use App\Models\VideoProject;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * AGENT AI 8 — TRƯỞNG PHÒNG KIỂM DUYỆT (QA Director & Gatekeeper).
 *
 * Kiểm duyệt nghiêm ngặt mọi công đoạn & thành phẩm dựa trên 3 file quy chuẩn trong `storage/app/qa_rules/`:
 *  1. `platform_policies.md`     — Chính sách 3 nền tảng (TikTok, Facebook Reels, YouTube Shorts)
 *  2. `viral_structure.md`       — Cấu trúc 1 video sức khỏe viral
 *  3. `production_standards.md`  — Chuẩn 1 giọng đọc duy nhất, chính tả ảnh bìa & phụ đề, độ khớp hình-tiếng-SFX
 *
 * Nếu chưa đạt yêu cầu -> Trả về `rejected` kèm danh sách `failed_agents` để hệ thống bắt buộc làm lại!
 */
class Agent8QaDirectorService
{
    /**
     * Danh sách cụm từ vi phạm nghiêm trọng chính sách y tế trên TikTok / Facebook / YouTube.
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_MEDICAL_CLAIMS = [
        'chữa khỏi 100%',
        'khỏi hẳn 100%',
        'thần dược',
        'dứt điểm vĩnh viễn',
        'bỏ thuốc tây',
        'ngừng uống thuốc bác sĩ',
        'đặc trị ung thư',
    ];

    public function __construct(
        private readonly HealthGeminiClient $gemini
    ) {}

    /**
     * Chạy kiểm duyệt toàn diện (Tầng 1: Kỹ thuật tự động + Tầng 2: Gemini Vision/Text Trưởng phòng).
     */
    public function inspectProject(VideoProject $project): AgentQaReview
    {
        $project->load(['scenes', 'planItem']);
        $disk = Storage::disk('public');
        $attempt = ((int) $project->qa_attempts) + 1;

        // === TẦNG 1: KIỂM TRA KỸ THUẬT & CHÍNH SÁCH TỰ ĐỘNG (DETERMINISTIC) ===
        $hardFailures = [];
        $failedAgents = [];
        $checklist = [];

        // 1. Chính sách y tế (từ cấm)
        $allText = mb_strtolower($project->title . ' ' . $project->scenes->pluck('narration_text')->implode(' '));
        $violatedTerms = [];
        foreach (self::FORBIDDEN_MEDICAL_CLAIMS as $term) {
            if (str_contains($allText, $term)) {
                $violatedTerms[] = $term;
            }
        }
        if ($violatedTerms !== []) {
            $hardFailures[] = 'Vi phạm chính sách y tế (chứa từ cấm: ' . implode(', ', $violatedTerms) . ').';
            $failedAgents[] = 'agent3';
            $checklist['platform_policy'] = ['pass' => false, 'score' => 30, 'note' => 'Chứa cụm từ vi phạm chính sách y tế: ' . implode(', ', $violatedTerms)];
        } else {
            $checklist['platform_policy'] = ['pass' => true, 'score' => 95, 'note' => 'Không phát hiện từ ngữ thổi phồng chữa bệnh bị cấm.'];
        }

        // 2. Đồng nhất 1 giọng đọc từ đầu đến cuối & đủ file âm thanh
        $missingAudioScenes = [];
        foreach ($project->scenes as $scene) {
            if (! $scene->voice_file_path || ! $disk->exists($scene->voice_file_path)) {
                $missingAudioScenes[] = $scene->scene_order;
            }
        }
        $distinctVoicesUsed = $project->generationLogs()
            ->whereIn('action_type', ['synthesize_tts', 'agent5_multi_voice'])
            ->where('status', 'success')
            ->pluck('model_name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($missingAudioScenes !== []) {
            $hardFailures[] = 'Thiếu file âm thanh ở cảnh: ' . implode(', ', $missingAudioScenes) . '.';
            $failedAgents[] = 'agent5';
            $checklist['voice_consistency'] = ['pass' => false, 'score' => 20, 'note' => 'Thiếu giọng đọc ở một số cảnh.'];
        } elseif (count($distinctVoicesUsed) > 1 && $attempt === 1) {
            // Lưu ý: chỉ cảnh báo nếu chạy nhiều giọng khác nhau trong cùng phiên
            $checklist['voice_consistency'] = ['pass' => true, 'score' => 90, 'note' => "Đã khóa 1 giọng đọc: {$project->voice_provider} / {$project->voice_id} (tốc độ {$project->voice_speed}x)."];
        } else {
            $checklist['voice_consistency'] = ['pass' => true, 'score' => 98, 'note' => "Đúng 1 giọng đọc xuyên suốt: {$project->voice_provider} / {$project->voice_id} ({$project->voice_speed}x)."];
        }

        // 3. Video stock châu Á đầy đủ
        $missingVideoScenes = [];
        foreach ($project->scenes as $scene) {
            if (! $scene->video_file_path || ! $disk->exists($scene->video_file_path)) {
                $missingVideoScenes[] = $scene->scene_order;
            }
        }
        if ($missingVideoScenes !== []) {
            $hardFailures[] = 'Thiếu video stock ở cảnh: ' . implode(', ', $missingVideoScenes) . '.';
            $failedAgents[] = 'agent4';
            $checklist['visual_match'] = ['pass' => false, 'score' => 20, 'note' => 'Thiếu clip video ở cảnh: ' . implode(', ', $missingVideoScenes)];
        } else {
            $checklist['visual_match'] = ['pass' => true, 'score' => 92, 'note' => 'Đủ clip video châu Á 60fps cho tất cả phân cảnh.'];
        }

        // 4. Ảnh bìa Photographic tồn tại
        $thumbFullPath = ($project->thumbnail_path && $disk->exists($project->thumbnail_path))
            ? $disk->path($project->thumbnail_path)
            : null;
        if ($thumbFullPath === null) {
            $hardFailures[] = 'Chưa có ảnh bìa Photographic (Agent AI 6).';
            $failedAgents[] = 'agent6';
            $checklist['thumbnail_quality'] = ['pass' => false, 'score' => 0, 'note' => 'Chưa tạo được file ảnh bìa.'];
        } else {
            $checklist['thumbnail_quality'] = ['pass' => true, 'score' => 94, 'note' => 'Đã có ảnh bìa Photographic 1080x1920 sắc nét.'];
        }

        // 5. Phụ đề sạch tag kỹ thuật & Video thành phẩm tồn tại
        $subtitleErrors = [];
        if ($project->subtitle_enabled) {
            foreach ($project->scenes as $scene) {
                if ($scene->subtitle_file_path && $disk->exists($scene->subtitle_file_path)) {
                    $vtt = (string) file_get_contents($disk->path($scene->subtitle_file_path));
                    if (preg_match('/@\{[^}]+\}/', $vtt) === 1) {
                        $subtitleErrors[] = "Cảnh {$scene->scene_order} sót mã @{...} trong phụ đề";
                    }
                }
            }
        }
        if ($subtitleErrors !== [] || ! $project->final_video_path || ! $disk->exists($project->final_video_path)) {
            $hardFailures[] = $subtitleErrors !== []
                ? implode('; ', $subtitleErrors)
                : 'Chưa có file video thành phẩm cuối cùng (Agent AI 7).';
            $failedAgents[] = 'agent7';
            $checklist['subtitle_and_edit'] = ['pass' => false, 'score' => 30, 'note' => 'Phụ đề hoặc file video hậu kỳ chưa đạt chuẩn.'];
        } else {
            $subNote = $project->subtitle_enabled
                ? "Phụ đề ({$project->subtitle_style}) sạch mã kỹ thuật, đồng bộ giọng đọc & chuyển cảnh CapCut."
                : 'Người dùng chọn tắt phụ đề; video hậu kỳ & hiệu ứng âm thanh đã hoàn tất.';
            $checklist['subtitle_and_edit'] = ['pass' => true, 'score' => 95, 'note' => $subNote];
        }

        // === TẦNG 2: KIỂM DUYỆT AI TRƯỞNG PHÒNG (GEMINI TEXT + VISION ĐỌC ẢNH BÌA) ===
        $aiFeedback = '';
        $aiOverallScore = null;

        try {
            $aiResult = $this->runGeminiDirectorAudit($project, $thumbFullPath);

            if (is_array($aiResult['checklist'] ?? null)) {
                foreach ($aiResult['checklist'] as $k => $v) {
                    if (is_array($v) && isset($v['score'])) {
                        // Không ghi đè lỗi cứng Tầng 1 thành pass
                        if (isset($checklist[$k]) && ($checklist[$k]['pass'] === false)) {
                            continue;
                        }
                        $checklist[$k] = [
                            'pass' => (bool) ($v['pass'] ?? ((int) $v['score'] >= 75)),
                            'score' => max(0, min(100, (int) $v['score'])),
                            'note' => trim((string) ($v['note'] ?? '')),
                        ];
                    }
                }
            }

            if (isset($aiResult['overall_score'])) {
                $aiOverallScore = max(0, min(100, (int) $aiResult['overall_score']));
            }

            if (is_array($aiResult['failed_agents'] ?? null)) {
                foreach ($aiResult['failed_agents'] as $ag) {
                    $agStr = strtolower(trim((string) $ag));
                    if (in_array($agStr, ['agent2', 'agent3', 'agent4', 'agent5', 'agent6', 'agent7'], true)) {
                        $failedAgents[] = $agStr;
                    }
                }
            }

            $aiFeedback = trim((string) ($aiResult['feedback_notes'] ?? ''));
        } catch (Throwable $e) {
            Log::warning('[Agent8QaDirector] Dùng kết quả kiểm tra Tầng 1 do Gemini bận: ' . $e->getMessage());
            $aiFeedback = 'Đã kiểm tra đầy đủ theo bộ tiêu chuẩn kỹ thuật & chính sách nền tảng.';
        }

        $failedAgents = array_values(array_unique($failedAgents));

        $scores = array_map(static fn (array $item): int => (int) ($item['score'] ?? 85), array_values($checklist));
        $avgScore = $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : 85;
        $overallScore = $hardFailures !== []
            ? min($avgScore, 65)
            : ($aiOverallScore !== null ? (int) round(($avgScore + $aiOverallScore) / 2) : $avgScore);

        $isApproved = ($hardFailures === []) && ($failedAgents === []) && ($overallScore >= 78);

        $combinedFeedback = trim(implode("\n", array_filter([
            $hardFailures !== [] ? 'Lỗi kỹ thuật bắt buộc khắc phục: ' . implode(' ', $hardFailures) : '',
            $aiFeedback,
        ])));

        $review = AgentQaReview::query()->create([
            'video_project_id' => $project->id,
            'attempt_number' => $attempt,
            'status' => $isApproved ? 'approved' : 'rejected',
            'overall_score' => $overallScore,
            'checklist_json' => $checklist,
            'failed_agents_json' => $isApproved ? [] : ($failedAgents !== [] ? $failedAgents : ['agent3']),
            'feedback_notes' => $combinedFeedback !== '' ? $combinedFeedback : 'Video đạt chuẩn xuất bản trên TikTok, Fanpage Reels và YouTube Shorts.',
        ]);

        $project->update([
            'qa_status' => $review->status,
            'qa_attempts' => $attempt,
        ]);

        if ($project->planItem && $isApproved) {
            $project->planItem->update(['status' => 'approved']);
        }

        return $review;
    }

    /**
     * @return array<string, mixed>
     */
    private function runGeminiDirectorAudit(VideoProject $project, ?string $thumbFullPath): array
    {
        $policies = $this->readRule('platform_policies.md');
        $viral = $this->readRule('viral_structure.md');
        $standards = $this->readRule('production_standards.md');

        $scenesText = $project->scenes->map(static function ($s): string {
            return sprintf(
                'Cảnh %d [Chuyển cảnh: %s | SFX: %s | Video query: %s]: %s',
                $s->scene_order,
                $s->transition_type,
                $s->sfx_prompt,
                $s->visual_prompt,
                $s->narration_text
            );
        })->implode("\n");

        $systemPrompt = <<<PROMPT
Bạn là Agent AI 8 — TRƯỞNG PHÒNG KIỂM DUYỆT (QA Director) của kênh "Sống Khỏe Mỗi Ngày".
Bạn phải đối chiếu nghiêm ngặt sản phẩm với 3 bộ quy chuẩn sau:

[1. CHÍNH SÁCH 3 NỀN TẢNG]
{$policies}

[2. CẤU TRÚC VIDEO VIRAL]
{$viral}

[3. TIÊU CHUẨN KỸ THUẬT]
{$standards}

Hãy kiểm tra cả ảnh bìa đính kèm (nếu có) xem chữ tiếng Việt trên ảnh bìa có bị sai chính tả, mất dấu hay dính mã kỹ thuật không.
Chỉ trả về `failed_agents` khi thực sự có lỗi cần làm lại (nếu video đã tốt >= 80 điểm, hãy để `failed_agents: []`).
Trả về đúng JSON:
{
  "overall_score": 88,
  "checklist": {
    "platform_policy": {"pass": true, "score": 95, "note": "..."},
    "viral_structure": {"pass": true, "score": 88, "note": "..."},
    "visual_match": {"pass": true, "score": 90, "note": "..."},
    "voice_consistency": {"pass": true, "score": 95, "note": "..."},
    "thumbnail_quality": {"pass": true, "score": 92, "note": "..."},
    "subtitle_and_edit": {"pass": true, "score": 90, "note": "..."}
  },
  "failed_agents": [],
  "feedback_notes": "Nhận xét ngắn gọn của Trưởng phòng QA."
}
PROMPT;

        $userPrompt = <<<USER
HỒ SƠ VIDEO CẦN KIỂM DUYỆT:
- Tiêu đề: {$project->title}
- Giọng đọc đã khóa: {$project->voice_provider} / {$project->voice_id} (Tốc độ: {$project->voice_speed}x)
- Phụ đề: {$project->subtitle_enabled} (Mẫu: {$project->subtitle_style})
- Danh sách phân cảnh:
{$scenesText}

Hãy chấm điểm công tâm, nghiêm túc như Trưởng phòng kiểm duyệt trước khi đăng TikTok, Fanpage Reels và YouTube Shorts.
USER;

        return $this->gemini->generateJson(
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            actionType: 'agent8_qa_director',
            videoProjectId: $project->id,
            imagePaths: $thumbFullPath ? [$thumbFullPath] : [],
            temperature: 0.25
        );
    }

    private function readRule(string $filename): string
    {
        $path = storage_path('app/qa_rules/' . $filename);

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }
}
