@extends('layouts.health')

@section('title', "Studio #{$project->id} — {$project->title}")

@section('content')
    @if ($isProcessing)
        <meta http-equiv="refresh" content="6">
        <div class="mb-5 rounded-2xl bg-amber-50 border border-amber-200 p-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="inline-block w-3 h-3 rounded-full bg-amber-500 animate-ping"></span>
                <div class="text-sm text-amber-900 font-semibold">
                    Dây chuyền 8-Agent đang xử lý tự động ({{ $project->status->value }}). Trang sẽ tự làm mới sau mỗi 6 giây...
                </div>
            </div>
            <a href="{{ route('health.studio', $project->id) }}" class="px-3 py-1.5 rounded-lg bg-amber-600 text-white text-xs font-bold">
                Làm mới ngay
            </a>
        </div>
    @endif

    @if ($project->error_message)
        <div class="mb-5 rounded-2xl bg-rose-50 border border-rose-200 p-4 text-rose-800 text-sm">
            <div class="font-bold">Cảnh báo lỗi xử lý:</div>
            <div class="mt-1">{{ $project->error_message }}</div>
        </div>
    @endif

    {{-- Header dự án --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="text-xs text-slate-500 font-semibold">PHÒNG SẢN XUẤT & KIỂM DUYỆT 8-AGENT • DỰ ÁN #{{ $project->id }}</div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 mt-0.5">{{ $project->title }}</h1>
                <div class="flex flex-wrap items-center gap-3 mt-2 text-xs text-slate-600">
                    <span>Giọng đọc: <strong>{{ $project->voice_provider }} / {{ $project->voice_id }}</strong> ({{ $project->voice_speed }}x)</span>
                    <span>•</span>
                    <span>Phụ đề: <strong>{{ $project->subtitle_enabled ? ($project->subtitle_style ?? 'capcut_yellow') : 'Đã tắt' }}</strong></span>
                    <span>•</span>
                    <span>Thời lượng: <strong>{{ number_format(($project->total_duration_ms ?? 0) / 1000, 1) }}s</strong></span>
                </div>
            </div>

            @php
                $qaBadge = match($project->qa_status) {
                    'approved' => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                    'rejected' => 'bg-rose-100 text-rose-800 border-rose-300',
                    'running' => 'bg-amber-100 text-amber-800 border-amber-300',
                    default => 'bg-slate-100 text-slate-700 border-slate-300',
                };
            @endphp
            <div class="px-4 py-2 rounded-xl border text-sm font-extrabold {{ $qaBadge }} shrink-0">
                Trưởng phòng Agent 8:
                @if ($project->qa_status === 'approved')
                    ĐÃ DUYỆT ({{ $latestQa?->overall_score ?? 0 }}/100)
                @elseif ($project->qa_status === 'rejected')
                    CHƯA ĐẠT — CẦN LÀM LẠI ({{ $latestQa?->overall_score ?? 0 }}/100)
                @elseif ($project->qa_status === 'running')
                    ĐANG SẢN XUẤT...
                @else
                    {{ strtoupper((string) $project->qa_status) }}
                @endif
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Cột trái (5 cột): Video thành phẩm + Ảnh bìa Photographic --}}
        <div class="lg:col-span-5 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <h2 class="text-base font-bold text-slate-900 mb-3">Video Thành Phẩm (Agent AI 7 — CapCut Style)</h2>
                @if ($project->final_video_path)
                    <div class="bg-black rounded-xl overflow-hidden flex justify-center">
                        <video controls class="max-h-[520px] w-auto" src="{{ asset('storage/' . $project->final_video_path) }}"></video>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <a href="{{ asset('storage/' . $project->final_video_path) }}" download
                           class="flex-1 text-center py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold">
                            Tải Video MP4 (Đăng 3 Nền Tảng)
                        </a>
                    </div>
                @else
                    <div class="aspect-[9/16] max-h-[420px] mx-auto rounded-xl bg-slate-100 border border-dashed border-slate-300 flex items-center justify-center text-slate-500 text-xs text-center p-4">
                        Video đang được các Agent 3 → 7 xử lý...
                    </div>
                @endif
            </div>

            {{-- Ảnh bìa Photographic của Agent 6 --}}
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-base font-bold text-slate-900">Ảnh Bìa Photographic (Agent AI 6)</h2>
                    @if ($project->thumbnail_path)
                        <a href="{{ asset('storage/' . $project->thumbnail_path) }}" download
                           class="text-xs font-bold text-emerald-700 hover:underline">Tải ảnh bìa ↓</a>
                    @endif
                </div>
                @if ($project->thumbnail_path)
                    <div class="flex justify-center bg-slate-900 rounded-xl p-2">
                        <img src="{{ asset('storage/' . $project->thumbnail_path) }}" alt="Ảnh bìa Photographic"
                             class="max-h-[420px] rounded-lg shadow">
                    </div>
                @else
                    <div class="py-10 text-center text-xs text-slate-500 bg-slate-50 rounded-xl border border-dashed border-slate-300">
                        Ảnh bìa Photographic chưa được tạo.
                    </div>
                @endif
            </div>
        </div>

        {{-- Cột phải (7 cột): Phiếu Kiểm Duyệt Agent 8 + SEO 3 nền tảng + Chỉnh sửa & Kịch bản --}}
        <div class="lg:col-span-7 space-y-6">
            {{-- Phiếu kiểm duyệt của Trưởng phòng Agent AI 8 --}}
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-base font-bold text-slate-900">Phiếu Kiểm Duyệt — Trưởng Phòng Agent AI 8</h2>
                    <a href="{{ route('health.qa-rules') }}" class="text-xs font-semibold text-emerald-700 hover:underline">
                        Sửa Quy Chuẩn Kiểm Duyệt →
                    </a>
                </div>

                @if ($latestQa)
                    <div class="p-3.5 rounded-xl mb-4 {{ $latestQa->status === 'approved' ? 'bg-emerald-50 border border-emerald-200 text-emerald-900' : 'bg-rose-50 border border-rose-200 text-rose-900' }} text-xs">
                        <div class="font-bold mb-1">
                            Vòng kiểm duyệt #{{ $latestQa->attempt_number }} — Điểm tổng: {{ $latestQa->overall_score }}/100
                        </div>
                        <p class="whitespace-pre-line">{{ $latestQa->feedback_notes }}</p>
                    </div>

                    @if (is_array($latestQa->checklist_json) && $latestQa->checklist_json !== [])
                        @php
                            $labels = [
                                'platform_policy' => 'Chính sách 3 nền tảng (Y tế / Cộng đồng)',
                                'viral_structure' => 'Cấu trúc video viral & văn phong',
                                'visual_match' => 'Video mẫu châu Á khớp lời thoại',
                                'voice_consistency' => 'Âm thanh chuẩn & đúng 1 giọng đọc xuyên suốt',
                                'thumbnail_quality' => 'Ảnh bìa Photographic & chính tả tiếng Việt',
                                'subtitle_and_edit' => 'Phụ đề, chính tả, chuyển cảnh & SFX CapCut',
                            ];
                        @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            @foreach ($latestQa->checklist_json as $key => $row)
                                <div class="p-3 rounded-xl border {{ ($row['pass'] ?? false) ? 'border-emerald-200 bg-emerald-50/40' : 'border-rose-200 bg-rose-50/50' }}">
                                    <div class="flex items-center justify-between text-xs font-bold">
                                        <span>{{ $labels[$key] ?? $key }}</span>
                                        <span class="{{ ($row['pass'] ?? false) ? 'text-emerald-700' : 'text-rose-700' }}">
                                            {{ $row['score'] ?? 0 }}đ
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-slate-600 mt-1">{{ $row['note'] ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="text-xs text-slate-500 py-4">Trưởng phòng Agent AI 8 sẽ tự động chấm điểm ngay sau khi Agent 6 & 7 hoàn tất.</div>
                @endif

                {{-- Lệnh bắt buộc Agent làm lại thủ công --}}
                <form action="{{ route('health.studio.rework', $project->id) }}" method="POST" class="mt-4 pt-4 border-t border-slate-100 flex flex-col sm:flex-row gap-2">
                    @csrf
                    <select name="agent" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold bg-white">
                        <option value="all">Làm lại toàn bộ dây chuyền (Agent 3 → 8)</option>
                        <option value="agent3">Agent 3 — Viết lại Kịch bản (tự dựng lại Voice/Video)</option>
                        <option value="agent4">Agent 4 — Tìm lại Video Châu Á khác</option>
                        <option value="agent5">Agent 5 — Tạo lại Giọng đọc</option>
                        <option value="agent6">Agent 6 — Vẽ lại Ảnh bìa Photographic</option>
                        <option value="agent7">Agent 7 — Dựng lại Video / Phụ đề / SFX</option>
                    </select>
                    <input type="text" name="feedback" placeholder="Ghi chú yêu cầu sửa (tùy chọn)..."
                           class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-xs">
                    <button type="submit" class="px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold shrink-0">
                        Bắt Làm Lại
                    </button>
                </form>
            </div>

            {{-- Cấu hình lại Giọng đọc / Phụ đề / Chữ ảnh bìa --}}
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <h2 class="text-base font-bold text-slate-900 mb-3">Tùy Chỉnh Giọng Đọc, Phụ Đề & Chữ Ảnh Bìa</h2>
                <form action="{{ route('health.studio.config', $project->id) }}" method="POST" class="space-y-3 text-xs">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="font-bold text-slate-600 block mb-1">Nguồn giọng đọc</label>
                            <select name="voice_provider" id="studioVoiceProvider" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 bg-white">
                                @foreach ($voiceCatalog as $provKey => $provInfo)
                                    <option value="{{ $provKey }}" @selected(($project->voice_provider ?? 'edge_tts') === $provKey)>{{ $provInfo['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="font-bold text-slate-600 block mb-1">Giọng đọc</label>
                            <select name="voice_id" id="studioVoiceId" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 bg-white"></select>
                            <input type="text" name="custom_voice_id" id="studioCustomVoiceId" value="{{ $project->voice_id }}"
                                   placeholder="Voice ID tùy chỉnh..." class="hidden mt-1.5 w-full rounded-lg border border-slate-300 px-2 py-1.5">
                        </div>
                        <div>
                            <label class="font-bold text-slate-600 block mb-1">Tốc độ đọc</label>
                            <select name="voice_speed" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 bg-white">
                                @foreach (['0.90' => '0.90x', '1.00' => '1.00x (Chuẩn)', '1.08' => '1.08x', '1.15' => '1.15x'] as $spVal => $spLbl)
                                    <option value="{{ $spVal }}" @selected(abs(((float)($project->voice_speed ?? 1.0)) - (float)$spVal) < 0.02)>{{ $spLbl }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                        <div>
                            <label class="font-bold text-slate-600 block mb-1">Mẫu phụ đề</label>
                            <div class="flex items-center gap-2">
                                <label class="inline-flex items-center gap-1.5 font-semibold shrink-0">
                                    <input type="checkbox" name="subtitle_enabled" value="1" @checked($project->subtitle_enabled)>
                                    Bật phụ đề
                                </label>
                                <select name="subtitle_style" class="flex-1 rounded-lg border border-slate-300 px-2 py-2 bg-white">
                                    @foreach ($subtitleStyles as $sKey => $sMeta)
                                        <option value="{{ $sKey }}" @selected(($project->subtitle_style ?? 'capcut_yellow') === $sKey)>{{ $sMeta['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="font-bold text-slate-600 block mb-1">Chữ in trên Ảnh Bìa (4-7 từ)</label>
                            <input type="text" name="cover_headline"
                                   value="{{ $project->seo_meta_json['cover_headline'] ?? '' }}"
                                   class="w-full rounded-lg border border-slate-300 px-2.5 py-2">
                        </div>
                    </div>

                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                        Áp Dụng & Dựng Lại Video
                    </button>
                </form>
            </div>

            {{-- Bộ Copy SEO 3 nền tảng --}}
            @php $seo = is_array($project->seo_meta_json) ? $project->seo_meta_json : []; @endphp
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <h2 class="text-base font-bold text-slate-900 mb-3">Bộ Tiêu Đề & Hashtag Chuẩn SEO 3 Nền Tảng (Agent AI 2)</h2>
                <div class="space-y-2 text-xs">
                    <div class="p-2.5 rounded-lg bg-slate-50 border border-slate-200">
                        <strong class="text-slate-900">TikTok:</strong> {{ $seo['seo_title_tiktok'] ?? $project->title }}
                    </div>
                    <div class="p-2.5 rounded-lg bg-blue-50/50 border border-blue-200">
                        <strong class="text-blue-800">Fanpage Reels:</strong> {{ $seo['seo_title_reels'] ?? $project->title }}
                    </div>
                    <div class="p-2.5 rounded-lg bg-red-50/50 border border-red-200">
                        <strong class="text-red-800">YouTube Shorts:</strong> {{ $seo['seo_title_shorts'] ?? $project->title }}
                    </div>
                    @if (! empty($seo['hashtags']))
                        <div class="p-2.5 rounded-lg bg-emerald-50/50 border border-emerald-200 text-emerald-900 font-semibold">
                            {{ implode(' ', (array) $seo['hashtags']) }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Kịch bản chi tiết 6 cảnh --}}
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <h2 class="text-base font-bold text-slate-900 mb-3">Kịch Bản & Video Châu Á Từng Phân Cảnh (Agent 3, 4, 5)</h2>
                <div class="space-y-3">
                    @foreach ($project->scenes as $scene)
                        <div class="p-3.5 rounded-xl border border-slate-200 bg-slate-50/60 flex gap-3">
                            @if ($scene->image_file_path)
                                <img src="{{ asset('storage/' . $scene->image_file_path) }}" alt="Cảnh {{ $scene->scene_order }}"
                                     class="w-16 h-24 object-cover rounded-lg shrink-0 border border-slate-200">
                            @endif
                            <div class="min-w-0 flex-1 text-xs">
                                <div class="flex items-center justify-between font-bold text-slate-800">
                                    <span>Cảnh {{ $scene->scene_order }} ({{ number_format($scene->duration_ms / 1000, 1) }}s)</span>
                                    <span class="text-[11px] text-emerald-700">Chuyển cảnh: {{ $scene->transition_type }} | SFX: {{ $scene->sfx_prompt }}</span>
                                </div>
                                <p class="text-slate-700 mt-1">{{ $scene->narration_text }}</p>
                                <div class="text-[11px] text-slate-500 mt-1">Từ khóa Agent 4 (Châu Á): <code>{{ $scene->visual_prompt }}</code></div>
                                @if ($scene->voice_file_path)
                                    <audio controls class="mt-2 h-8 w-full max-w-xs" src="{{ asset('storage/' . $scene->voice_file_path) }}"></audio>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const studioVoiceCatalog = @json($voiceCatalog);
    const currentVoiceId = @json((string) ($project->voice_id ?? 'vi-VN-HoaiMyNeural'));
    const sProv = document.getElementById('studioVoiceProvider');
    const sVoice = document.getElementById('studioVoiceId');
    const sCustom = document.getElementById('studioCustomVoiceId');

    function syncStudioVoices() {
        const voices = (studioVoiceCatalog[sProv.value] && studioVoiceCatalog[sProv.value].voices) || {};
        sVoice.innerHTML = '';
        let matched = false;
        Object.entries(voices).forEach(([id, label]) => {
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = label;
            if (id === currentVoiceId) {
                opt.selected = true;
                matched = true;
            }
            sVoice.appendChild(opt);
        });
        if (!matched && voices['custom']) {
            sVoice.value = 'custom';
        }
        sCustom.classList.toggle('hidden', sVoice.value !== 'custom');
    }

    sProv.addEventListener('change', syncStudioVoices);
    sVoice.addEventListener('change', () => sCustom.classList.toggle('hidden', sVoice.value !== 'custom'));
    syncStudioVoices();
</script>
@endpush
