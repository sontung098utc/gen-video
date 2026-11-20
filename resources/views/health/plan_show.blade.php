@extends('layouts.health')

@section('title', $plan->name . ' — Kế Hoạch Agent 1 & 2')

@section('content')
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <a href="{{ route('health.plans') }}" class="text-xs font-semibold text-emerald-700 hover:underline">← Quay lại Danh sách Kế hoạch</a>
                <h1 class="text-2xl font-extrabold text-slate-900 mt-1">{{ $plan->name }}</h1>
                <p class="text-sm text-slate-600 mt-1">{{ $plan->plan_summary }}</p>
            </div>
            <form action="{{ route('health.plans.seo', $plan->id) }}" method="POST" class="flex items-center gap-2 shrink-0">
                @csrf
                <select name="limit" class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold bg-white">
                    <option value="7">Sinh SEO cho 7 bài tiếp theo</option>
                    <option value="14">Sinh SEO cho 14 bài tiếp theo</option>
                    <option value="31">Sinh SEO cho tất cả</option>
                </select>
                <button type="submit"
                        class="px-4 py-2.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold shadow transition">
                    Chạy Agent AI 2 — Sinh Tiêu Đề SEO 3 Nền Tảng
                </button>
            </form>
        </div>

        {{-- Cấu hình mặc định khi bấm Sản xuất Video từ lịch --}}
        <div class="mt-5 pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
            <div>
                <label class="font-bold text-slate-600 block mb-1">Giọng đọc mặc định (Agent 5)</label>
                <select id="planVoiceProvider" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 bg-slate-50">
                    @foreach ($voiceCatalog as $provKey => $provInfo)
                        <option value="{{ $provKey }}">{{ $provInfo['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="font-bold text-slate-600 block mb-1">Mã giọng đọc</label>
                <select id="planVoiceId" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 bg-slate-50"></select>
            </div>
            <div>
                <label class="font-bold text-slate-600 block mb-1">Tốc độ đọc</label>
                <select id="planVoiceSpeed" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 bg-slate-50">
                    <option value="0.90">0.90x</option>
                    <option value="1.00" selected>1.00x (Chuẩn)</option>
                    <option value="1.08">1.08x</option>
                    <option value="1.15">1.15x</option>
                </select>
            </div>
            <div>
                <label class="font-bold text-slate-600 block mb-1">Phụ đề (Agent 7)</label>
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="planSubEnabled" checked class="rounded text-emerald-600 w-4 h-4">
                    <select id="planSubStyle" class="flex-1 rounded-lg border border-slate-300 px-2 py-2 bg-slate-50">
                        @foreach ($subtitleStyles as $sKey => $sMeta)
                            <option value="{{ $sKey }}">{{ $sMeta['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Hiển thị theo từng Tuần -> Ngày --}}
    @foreach ($itemsByWeek as $weekNum => $weekItems)
        <div class="mb-6">
            <h2 class="text-base font-extrabold text-emerald-900 mb-3 flex items-center gap-2">
                <span class="px-3 py-1 rounded-lg bg-emerald-100 text-emerald-800">TUẦN {{ $weekNum }}</span>
                <span class="text-xs font-normal text-slate-500">({{ $weekItems->count() }} bài viết)</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($weekItems as $item)
                    <div class="bg-white rounded-2xl border border-slate-200 p-4 shadow-sm flex flex-col justify-between gap-3">
                        <div>
                            <div class="flex items-center justify-between gap-2 text-xs mb-2">
                                <span class="font-bold text-slate-700">
                                    Ngày {{ $item->day_number }} ({{ $item->scheduled_date?->format('d/m/Y') }}) — Bài #{{ $item->slot_index }}
                                </span>
                                <span class="px-2 py-0.5 rounded-full bg-teal-50 text-teal-800 font-semibold border border-teal-200">
                                    {{ $item->angleLabel() }}
                                </span>
                            </div>

                            <div class="font-bold text-slate-900 text-sm">{{ $item->topic }}</div>
                            @if ($item->brief)
                                <p class="text-xs text-slate-600 mt-1">{{ $item->brief }}</p>
                            @endif

                            @if ($item->seo_title_tiktok)
                                <div class="mt-3 p-3 rounded-xl bg-slate-50 border border-slate-200 space-y-1.5 text-xs">
                                    <div><strong class="text-slate-900">TikTok:</strong> {{ $item->seo_title_tiktok }}</div>
                                    <div><strong class="text-blue-700">Reels:</strong> {{ $item->seo_title_reels }}</div>
                                    <div><strong class="text-red-700">Shorts:</strong> {{ $item->seo_title_shorts }}</div>
                                    @if ($item->cover_headline)
                                        <div><strong class="text-emerald-700">Chữ Ảnh Bìa:</strong> <span class="font-bold">{{ $item->cover_headline }}</span></div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                            @if ($item->video_project_id)
                                <a href="{{ route('health.studio', $item->video_project_id) }}"
                                   class="px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-emerald-700 text-white text-xs font-bold">
                                    Xem Video & Phiếu Agent 8 (#{{ $item->video_project_id }}) →
                                </a>
                            @else
                                <form action="{{ route('health.plan-items.produce', $item->id) }}" method="POST" class="produce-item-form">
                                    @csrf
                                    <input type="hidden" name="voice_provider" value="edge_tts">
                                    <input type="hidden" name="voice_id" value="vi-VN-HoaiMyNeural">
                                    <input type="hidden" name="voice_speed" value="1.00">
                                    <input type="hidden" name="subtitle_enabled" value="1">
                                    <input type="hidden" name="subtitle_style" value="capcut_yellow">
                                    <button type="submit"
                                            class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm transition">
                                        Sản Xuất Video 8-Agent Ngay
                                    </button>
                                </form>
                            @endif

                            <span class="text-xs text-slate-400 uppercase font-semibold">{{ $item->status }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
@endsection

@push('scripts')
<script>
    const planVoiceCatalog = @json($voiceCatalog);
    const pProv = document.getElementById('planVoiceProvider');
    const pVoice = document.getElementById('planVoiceId');
    const pSpeed = document.getElementById('planVoiceSpeed');
    const pSubEn = document.getElementById('planSubEnabled');
    const pSubStyle = document.getElementById('planSubStyle');

    function syncPlanVoices() {
        const voices = (planVoiceCatalog[pProv.value] && planVoiceCatalog[pProv.value].voices) || {};
        pVoice.innerHTML = '';
        Object.entries(voices).forEach(([id, label]) => {
            if (id === 'custom') return;
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = label;
            pVoice.appendChild(opt);
        });
        syncAllForms();
    }

    function syncAllForms() {
        document.querySelectorAll('.produce-item-form').forEach((form) => {
            form.querySelector('[name="voice_provider"]').value = pProv.value;
            form.querySelector('[name="voice_id"]').value = pVoice.value;
            form.querySelector('[name="voice_speed"]').value = pSpeed.value;
            form.querySelector('[name="subtitle_enabled"]').value = pSubEn.checked ? '1' : '0';
            form.querySelector('[name="subtitle_style"]').value = pSubStyle.value;
        });
    }

    pProv.addEventListener('change', syncPlanVoices);
    [pVoice, pSpeed, pSubEn, pSubStyle].forEach((el) => el.addEventListener('change', syncAllForms));
    syncPlanVoices();
</script>
@endpush
