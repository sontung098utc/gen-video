<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CostTierMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\WorkflowMode;
use App\Jobs\RunHealth8AgentPipelineJob;
use App\Models\AppSetting;
use App\Models\HealthContentPlan;
use App\Models\HealthKnowledgeDocument;
use App\Models\HealthPlanItem;
use App\Models\StockMediaAsset;
use App\Models\VideoProject;
use App\Services\HealthAgents\Agent1ContentPlannerService;
use App\Services\HealthAgents\Agent2SeoTitleService;
use App\Services\HealthAgents\Agent5MultiVoiceStudioService;
use App\Services\HealthAgents\Agent7CapCutCompositorService;
use App\Services\HealthAgents\HealthKnowledgeBaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Controller trung tâm điều hành "Sống Khỏe Mỗi Ngày — 8-Agent AI Video Studio".
 */
class HealthStudioController extends Controller
{
    public function dashboard(): View
    {
        $channel = [
            'name' => AppSetting::getValue('channel_name', 'Sống Khỏe Mỗi Ngày'),
            'tiktok' => AppSetting::getValue('channel_tiktok_url', 'https://www.tiktok.com/@songkhoemoingay_85'),
            'fanpage' => AppSetting::getValue('channel_fanpage_url', 'https://www.facebook.com/profile.php?id=61560244119608'),
            'youtube' => AppSetting::getValue('channel_youtube_url', 'https://www.youtube.com/channel/UCRjdRnDY-BhaLVkVw_prc9g/'),
        ];

        $projects = VideoProject::query()
            ->with(['latestQaReview', 'planItem'])
            ->latest()
            ->limit(15)
            ->get();

        $plans = HealthContentPlan::query()->withCount('items')->latest()->limit(5)->get();

        $stats = [
            'total_videos' => VideoProject::query()->count(),
            'approved_videos' => VideoProject::query()->where('qa_status', 'approved')->count(),
            'knowledge_docs' => HealthKnowledgeDocument::query()->where('is_active', true)->count(),
            'local_stock' => StockMediaAsset::query()->where('is_active', true)->count(),
            'total_plans' => HealthContentPlan::query()->count(),
        ];

        return view('health.dashboard', [
            'channel' => $channel,
            'projects' => $projects,
            'plans' => $plans,
            'stats' => $stats,
            'voiceCatalog' => Agent5MultiVoiceStudioService::catalog(),
            'subtitleStyles' => Agent7CapCutCompositorService::subtitleStyles(),
        ]);
    }

    /**
     * Tạo nhanh 1 video sức khỏe trực tiếp (không cần qua kế hoạch tháng).
     */
    public function quickProduce(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:240'],
            'topic_or_brief' => ['nullable', 'string', 'max:3000'],
            'voice_provider' => ['required', 'string', 'in:edge_tts,google_tts,vbee,genmax'],
            'voice_id' => ['required', 'string', 'max:120'],
            'custom_voice_id' => ['nullable', 'string', 'max:120'],
            'voice_speed' => ['required', 'numeric', 'min:0.75', 'max:1.35'],
            'subtitle_enabled' => ['nullable', 'boolean'],
            'subtitle_style' => ['required', 'string', 'in:capcut_yellow,box_highlight,karaoke_green,clean_white'],
        ]);

        $voiceId = $validated['voice_id'] === 'custom' && ! empty($validated['custom_voice_id'])
            ? trim((string) $validated['custom_voice_id'])
            : $validated['voice_id'];

        $project = VideoProject::query()->create([
            'title' => trim((string) $validated['title']),
            'project_type' => ProjectType::KNOWLEDGE,
            'topic_or_brief' => trim((string) ($validated['topic_or_brief'] ?? $validated['title'])),
            'aspect_ratio' => '9:16',
            'status' => ProjectStatus::DRAFT,
            'workflow_mode' => WorkflowMode::AUTO,
            'cost_tier_mode' => CostTierMode::ECO,
            'voice_provider' => $validated['voice_provider'],
            'voice_id' => $voiceId,
            'voice_speed' => (float) $validated['voice_speed'],
            'subtitle_enabled' => (bool) ($request->boolean('subtitle_enabled')),
            'subtitle_style' => $validated['subtitle_style'],
            'qa_status' => 'pending',
            'qa_attempts' => 0,
            'seo_meta_json' => [
                'seo_title_tiktok' => trim((string) $validated['title']),
                'seo_title_reels' => trim((string) $validated['title']),
                'seo_title_shorts' => trim((string) $validated['title']),
                'hashtags' => ['#songkhoemoingay', '#suckhoe', '#dongy', '#dinhduong'],
            ],
        ]);

        RunHealth8AgentPipelineJob::dispatch($project->id);

        return redirect()
            ->route('health.studio', $project->id)
            ->with('status', 'Đã khởi chạy Dây chuyền 8-Agent AI! Hệ thống đang tự động viết kịch bản, tìm video châu Á, tạo giọng đọc, vẽ ảnh bìa Photographic và gửi Trưởng phòng Agent 8 kiểm duyệt.');
    }

    // ==================== AGENT 1 & 2: KẾ HOẠCH NỘI DUNG THÁNG -> TUẦN -> NGÀY ====================

    public function plans(): View
    {
        $plans = HealthContentPlan::query()->withCount('items')->latest()->get();

        return view('health.plans', [
            'plans' => $plans,
        ]);
    }

    public function storePlan(Request $request, Agent1ContentPlannerService $agent1): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:240'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2024', 'max:2035'],
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:5'],
            'total_days' => ['required', 'integer', 'min:1', 'max:31'],
            'topics' => ['required', 'string', 'max:2000'],
        ]);

        $topics = preg_split('/[\r\n,;]+/u', (string) $validated['topics']) ?: [];

        $plan = $agent1->buildMonthlyPlan(
            name: (string) ($validated['name'] ?? ''),
            month: (int) $validated['month'],
            year: (int) $validated['year'],
            postsPerDay: (int) $validated['posts_per_day'],
            totalDays: (int) $validated['total_days'],
            topics: $topics
        );

        return redirect()
            ->route('health.plans.show', $plan->id)
            ->with('status', "Agent AI 1 đã lập xong kế hoạch \"{$plan->name}\" gồm {$plan->items->count()} bài viết theo Tháng → Tuần → Ngày!");
    }

    public function showPlan(int $id): View
    {
        $plan = HealthContentPlan::query()->with(['items.videoProject.latestQaReview'])->findOrFail($id);
        $itemsByWeek = $plan->items->groupBy('week_number');

        return view('health.plan_show', [
            'plan' => $plan,
            'itemsByWeek' => $itemsByWeek,
            'voiceCatalog' => Agent5MultiVoiceStudioService::catalog(),
            'subtitleStyles' => Agent7CapCutCompositorService::subtitleStyles(),
        ]);
    }

    public function generateSeoTitles(Request $request, int $id, Agent2SeoTitleService $agent2): RedirectResponse
    {
        $plan = HealthContentPlan::query()->findOrFail($id);
        $limit = max(1, min(31, (int) $request->input('limit', 10)));

        $count = $agent2->generateBatchForPlan($plan, $limit);

        return redirect()
            ->route('health.plans.show', $plan->id)
            ->with('status', "Agent AI 2 đã hoàn tất sinh Tiêu đề chuẩn SEO 3 nền tảng (TikTok, Reels, Shorts) cho {$count} bài viết!");
    }

    public function produceFromItem(Request $request, int $itemId, Agent2SeoTitleService $agent2): RedirectResponse
    {
        $item = HealthPlanItem::query()->findOrFail($itemId);

        // Nếu bài viết chưa có tiêu đề SEO, tự chạy Agent 2 trước
        if (empty($item->seo_title_tiktok)) {
            $item = $agent2->generateForItem($item);
        }

        $voiceProvider = (string) $request->input('voice_provider', 'edge_tts');
        $voiceId = (string) $request->input('voice_id', 'vi-VN-HoaiMyNeural');
        if ($voiceId === 'custom' && $request->filled('custom_voice_id')) {
            $voiceId = trim((string) $request->input('custom_voice_id'));
        }
        $voiceSpeed = max(0.75, min(1.35, (float) $request->input('voice_speed', 1.0)));
        $subtitleEnabled = $request->has('subtitle_enabled') ? $request->boolean('subtitle_enabled') : true;
        $subtitleStyle = (string) $request->input('subtitle_style', 'capcut_yellow');

        $project = VideoProject::query()->create([
            'title' => $item->seo_title_tiktok ?: $item->topic,
            'project_type' => ProjectType::KNOWLEDGE,
            'topic_or_brief' => $item->brief ?: $item->topic,
            'aspect_ratio' => '9:16',
            'status' => ProjectStatus::DRAFT,
            'workflow_mode' => WorkflowMode::AUTO,
            'cost_tier_mode' => CostTierMode::ECO,
            'voice_provider' => $voiceProvider,
            'voice_id' => $voiceId,
            'voice_speed' => $voiceSpeed,
            'subtitle_enabled' => $subtitleEnabled,
            'subtitle_style' => $subtitleStyle,
            'qa_status' => 'pending',
            'qa_attempts' => 0,
            'health_plan_item_id' => $item->id,
            'seo_meta_json' => [
                'seo_title_tiktok' => $item->seo_title_tiktok,
                'seo_title_reels' => $item->seo_title_reels,
                'seo_title_shorts' => $item->seo_title_shorts,
                'cover_headline' => $item->cover_headline,
                'seo_description' => $item->seo_description,
                'hashtags' => $item->hashtags_json ?? [],
            ],
        ]);

        $item->update([
            'video_project_id' => $project->id,
            'status' => 'producing',
        ]);

        RunHealth8AgentPipelineJob::dispatch($project->id);

        return redirect()
            ->route('health.studio', $project->id)
            ->with('status', "Đã bàn giao bài viết Ngày {$item->day_number} cho Dây chuyền 8-Agent AI sản xuất & kiểm duyệt!");
    }

    // ==================== PHÒNG SẢN XUẤT & KIỂM DUYỆT AGENT 3 -> 8 ====================

    public function studio(int $id): View
    {
        $project = VideoProject::query()
            ->with(['scenes', 'qaReviews', 'planItem', 'generationLogs'])
            ->findOrFail($id);

        $isProcessing = in_array($project->status, [
            ProjectStatus::DRAFT,
            ProjectStatus::GENERATING_SCRIPT,
            ProjectStatus::GENERATING_VOICE,
            ProjectStatus::GENERATING_VISUALS,
            ProjectStatus::RENDERING_FINAL,
        ], true) && $project->qa_status !== 'failed';

        return view('health.studio', [
            'project' => $project,
            'latestQa' => $project->qaReviews->first(),
            'isProcessing' => $isProcessing,
            'voiceCatalog' => Agent5MultiVoiceStudioService::catalog(),
            'subtitleStyles' => Agent7CapCutCompositorService::subtitleStyles(),
        ]);
    }

    public function updateProjectConfig(Request $request, int $id): RedirectResponse
    {
        $project = VideoProject::query()->findOrFail($id);

        $validated = $request->validate([
            'voice_provider' => ['required', 'string', 'in:edge_tts,google_tts,vbee,genmax'],
            'voice_id' => ['required', 'string', 'max:120'],
            'custom_voice_id' => ['nullable', 'string', 'max:120'],
            'voice_speed' => ['required', 'numeric', 'min:0.75', 'max:1.35'],
            'subtitle_enabled' => ['nullable', 'boolean'],
            'subtitle_style' => ['required', 'string', 'in:capcut_yellow,box_highlight,karaoke_green,clean_white'],
            'cover_headline' => ['nullable', 'string', 'max:160'],
        ]);

        $voiceId = $validated['voice_id'] === 'custom' && ! empty($validated['custom_voice_id'])
            ? trim((string) $validated['custom_voice_id'])
            : $validated['voice_id'];

        $voiceChanged = $project->voice_provider !== $validated['voice_provider']
            || $project->voice_id !== $voiceId
            || abs(((float) $project->voice_speed) - ((float) $validated['voice_speed'])) > 0.01;

        $seoMeta = is_array($project->seo_meta_json) ? $project->seo_meta_json : [];
        $coverChanged = false;
        if (! empty($validated['cover_headline']) && ($seoMeta['cover_headline'] ?? '') !== trim((string) $validated['cover_headline'])) {
            $seoMeta['cover_headline'] = trim((string) $validated['cover_headline']);
            $coverChanged = true;
        }

        $project->update([
            'voice_provider' => $validated['voice_provider'],
            'voice_id' => $voiceId,
            'voice_speed' => (float) $validated['voice_speed'],
            'subtitle_enabled' => $request->boolean('subtitle_enabled'),
            'subtitle_style' => $validated['subtitle_style'],
            'seo_meta_json' => $seoMeta,
        ]);

        $agentsToRun = ['agent7'];
        if ($voiceChanged) {
            array_unshift($agentsToRun, 'agent5', 'agent4');
        }
        if ($coverChanged) {
            $agentsToRun[] = 'agent6';
        }

        RunHealth8AgentPipelineJob::dispatch($project->id, array_values(array_unique($agentsToRun)));

        return redirect()
            ->route('health.studio', $project->id)
            ->with('status', 'Đã cập nhật cấu hình Giọng đọc / Phụ đề / Ảnh bìa và kích hoạt dựng lại + Agent 8 kiểm duyệt!');
    }

    public function reworkAgent(Request $request, int $id): RedirectResponse
    {
        $project = VideoProject::query()->findOrFail($id);
        $agent = (string) $request->input('agent', 'all');
        $feedback = trim((string) $request->input('feedback', ''));

        $targets = match ($agent) {
            'agent3' => ['agent3', 'agent5', 'agent4', 'agent7'],
            'agent4' => ['agent4', 'agent7'],
            'agent5' => ['agent5', 'agent4', 'agent7'],
            'agent6' => ['agent6'],
            'agent7' => ['agent7'],
            default => [],
        };

        RunHealth8AgentPipelineJob::dispatch($project->id, $targets, $feedback !== '' ? $feedback : null);

        return redirect()
            ->route('health.studio', $project->id)
            ->with('status', "Đã gửi lệnh yêu cầu làm lại ({$agent}) kèm phản hồi. Trưởng phòng Agent 8 sẽ tự động kiểm duyệt lại sau khi xong!");
    }

    // ==================== KHO TÀI LIỆU KIẾN THỨC SỨC KHỎE ====================

    public function knowledge(): View
    {
        $docs = HealthKnowledgeDocument::query()->latest()->get();

        return view('health.knowledge', [
            'docs' => $docs,
        ]);
    }

    public function storeKnowledge(Request $request, HealthKnowledgeBaseService $kb): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'category' => ['required', 'string', 'in:dong_y,tay_y,dinh_duong,khoa_hoc,y_te'],
            'source_name' => ['nullable', 'string', 'max:250'],
            'source_url' => ['nullable', 'string', 'max:490'],
            'manual_text' => ['nullable', 'string', 'max:100000'],
            'document_file' => ['nullable', 'file', 'mimes:txt,md,docx,pdf,csv', 'max:20480'],
        ]);

        $filePath = null;
        $extractedText = trim((string) ($validated['manual_text'] ?? ''));

        if ($request->hasFile('document_file')) {
            $file = $request->file('document_file');
            $filePath = $file->store('knowledge_docs', 'public');
            $fileText = $kb->extractTextFromUploadedFile($file);
            if ($fileText !== '') {
                $extractedText = trim($extractedText . "\n\n" . $fileText);
            }
        }

        if ($extractedText === '') {
            return back()->withErrors(['manual_text' => 'Vui lòng nhập nội dung kiến thức hoặc tải lên file .txt / .md / .docx / .pdf có chứa văn bản.']);
        }

        HealthKnowledgeDocument::query()->create([
            'title' => trim((string) $validated['title']),
            'category' => $validated['category'],
            'source_name' => trim((string) ($validated['source_name'] ?? '')),
            'source_url' => trim((string) ($validated['source_url'] ?? '')),
            'file_path' => $filePath,
            'extracted_text' => $extractedText,
            'is_active' => true,
        ]);

        return redirect()
            ->route('health.knowledge')
            ->with('status', 'Đã thêm tài liệu kiến thức sức khỏe chính thống vào Kho dữ liệu cho Agent 1 & Agent 3!');
    }

    public function deleteKnowledge(int $id): RedirectResponse
    {
        $doc = HealthKnowledgeDocument::query()->findOrFail($id);
        if ($doc->file_path) {
            Storage::disk('public')->delete($doc->file_path);
        }
        $doc->delete();

        return redirect()->route('health.knowledge')->with('status', 'Đã xóa tài liệu khỏi Kho Kiến Thức.');
    }

    // ==================== KHO VIDEO CHÂU Á NỘI BỘ (AGENT 4) ====================

    public function stockLibrary(): View
    {
        $assets = StockMediaAsset::query()->latest()->get();

        return view('health.stock_library', [
            'assets' => $assets,
        ]);
    }

    public function storeStockAsset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:240'],
            'tags' => ['required', 'string', 'max:490'],
            'category' => ['required', 'string', 'in:dong_y,tay_y,dinh_duong,tap_luyen,lifestyle'],
            'video_file' => ['required', 'file', 'mimes:mp4,mov,webm', 'max:102400'],
        ]);

        $path = $request->file('video_file')->store('stock_library', 'public');

        StockMediaAsset::query()->create([
            'title' => trim((string) $validated['title']),
            'tags' => trim((string) $validated['tags']),
            'category' => $validated['category'],
            'orientation' => '9:16',
            'file_path' => $path,
            'is_active' => true,
        ]);

        return redirect()
            ->route('health.stock')
            ->with('status', 'Đã tải video châu Á lên Kho Nội Bộ! Agent AI 4 sẽ ưu tiên sử dụng khi khớp từ khóa.');
    }

    public function deleteStockAsset(int $id): RedirectResponse
    {
        $asset = StockMediaAsset::query()->findOrFail($id);
        if ($asset->file_path) {
            Storage::disk('public')->delete($asset->file_path);
        }
        $asset->delete();

        return redirect()->route('health.stock')->with('status', 'Đã xóa video khỏi Kho Nội Bộ.');
    }

    // ==================== BỘ QUY CHUẨN TRƯỞNG PHÒNG AGENT AI 8 ====================

    public function qaRules(): View
    {
        $files = [
            'platform_policies.md' => '1. Chính Sách 3 Nền Tảng (TikTok, Fanpage Reels, YouTube Shorts)',
            'viral_structure.md' => '2. Cấu Trúc 1 Video Kiến Thức Sức Khỏe Viral',
            'production_standards.md' => '3. Tiêu Chuẩn Kỹ Thuật (1 Giọng Đọc, Chính Tả, Phụ Đề, Ảnh Bìa, SFX)',
        ];

        $contents = [];
        foreach (array_keys($files) as $file) {
            $path = storage_path('app/qa_rules/' . $file);
            $contents[$file] = is_file($path) ? (string) file_get_contents($path) : '';
        }

        return view('health.qa_rules', [
            'files' => $files,
            'contents' => $contents,
        ]);
    }

    public function updateQaRules(Request $request): RedirectResponse
    {
        $allowed = ['platform_policies.md', 'viral_structure.md', 'production_standards.md'];
        $dir = storage_path('app/qa_rules');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        foreach ($allowed as $file) {
            $key = str_replace('.', '_', $file);
            if ($request->has($key)) {
                file_put_contents($dir . '/' . $file, trim((string) $request->input($key)) . "\n");
            }
        }

        return redirect()
            ->route('health.qa-rules')
            ->with('status', 'Đã lưu cập nhật Bộ Quy Chuẩn Kiểm Duyệt cho Trưởng phòng Agent AI 8!');
    }

    // ==================== CÀI ĐẶT KÊNH & API KEYS ====================

    public function settings(): View
    {
        $defs = AppSetting::definitions();
        $values = [];
        $hasValue = [];

        foreach ($defs as $key => $meta) {
            $val = AppSetting::getValue($key, '');
            $hasValue[$key] = $val !== '';
            $values[$key] = $meta['is_secret'] ? '' : $val;
        }

        return view('health.settings', [
            'defs' => $defs,
            'values' => $values,
            'hasValue' => $hasValue,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $defs = AppSetting::definitions();

        foreach ($defs as $key => $meta) {
            $input = $request->input($key);
            if ($meta['is_secret']) {
                // Chỉ ghi đè API Key khi người dùng thực sự nhập key mới
                if (is_string($input) && trim($input) !== '') {
                    AppSetting::setValue($key, trim($input));
                }
            } else {
                if (is_string($input)) {
                    AppSetting::setValue($key, trim($input));
                }
            }
        }

        return redirect()
            ->route('health.settings')
            ->with('status', 'Đã lưu cấu hình kênh Sống Khỏe Mỗi Ngày và toàn bộ API Keys an toàn!');
    }
}
