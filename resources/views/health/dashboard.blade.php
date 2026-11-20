@extends('layouts.health')

@section('title', 'Sống Khỏe Mỗi Ngày — Tổng Quan 8-Agent Studio')

@section('content')
    {{-- Banner kênh Sống Khỏe Mỗi Ngày --}}
    <div class="bg-gradient-to-r from-emerald-800 via-emerald-700 to-teal-700 rounded-2xl p-6 text-white shadow-lg mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wider bg-white/15 px-3 py-1 rounded-full mb-2">
                    <span class="w-2 h-2 rounded-full bg-yellow-300"></span>
                    Hệ thống 8-Agent AI Tự Động Hóa Kênh Sức Khỏe
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold">{{ $channel['name'] }}</h1>
                <p class="text-emerald-100 text-sm mt-1">
                    Sản xuất video kiến thức sức khỏe chính thống (Đông y, Tây y, Khoa học, Y tế) chuẩn cho 3 nền tảng: Fanpage Reels, YouTube Shorts & TikTok.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ $channel['tiktok'] }}" target="_blank" class="px-3.5 py-2 rounded-xl bg-black/30 hover:bg-black/45 text-xs font-semibold border border-white/20 transition">
                    TikTok ↗
                </a>
                <a href="{{ $channel['fanpage'] }}" target="_blank" class="px-3.5 py-2 rounded-xl bg-blue-600/80 hover:bg-blue-600 text-xs font-semibold border border-white/20 transition">
                    Fanpage Reels ↗
                </a>
                <a href="{{ $channel['youtube'] }}" target="_blank" class="px-3.5 py-2 rounded-xl bg-red-600/85 hover:bg-red-600 text-xs font-semibold border border-white/20 transition">
                    YouTube Shorts ↗
                </a>
            </div>
        </div>
    </div>

    {{-- Thống kê nhanh --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Kế hoạch Tháng (A1)</div>
            <div class="text-2xl font-extrabold text-emerald-700 mt-1">{{ $stats['total_plans'] }}</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Tổng Video đã tạo</div>
            <div class="text-2xl font-extrabold text-slate-800 mt-1">{{ $stats['total_videos'] }}</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Agent 8 Đã Duyệt</div>
            <div class="text-2xl font-extrabold text-emerald-600 mt-1">{{ $stats['approved_videos'] }}</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Tài liệu Y khoa</div>
            <div class="text-2xl font-extrabold text-teal-700 mt-1">{{ $stats['knowledge_docs'] }}</div>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">Kho Video Châu Á</div>
            <div class="text-2xl font-extrabold text-amber-600 mt-1">{{ $stats['local_stock'] }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Cột trái: Form sản xuất nhanh 1 video 8-Agent --}}
        <div class="lg:col-span-5">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-slate-900">Sản Xuất Nhanh Video 8-Agent</h2>
                    <a href="{{ route('health.plans') }}" class="text-xs font-semibold text-emerald-700 hover:underline">
                        Hoặc lập Kế hoạch Tháng →
                    </a>
                </div>

                <form action="{{ route('health.quick-produce') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tiêu đề / Chủ đề sức khỏe *</label>
                        <input type="text" name="title" required
                               placeholder="VD: 3 thói quen uống nước buổi sáng giúp ổn định huyết áp theo Đông - Tây y"
                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Ý chính / Ghi chú kiến thức (tùy chọn)</label>
                        <textarea name="topic_or_brief" rows="2"
                                  placeholder="Ghi chú thêm góc nhìn Đông y, Tây y hoặc đối tượng người xem..."
                                  class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-emerald-500"></textarea>
                    </div>

                    {{-- Agent 5: Giọng đọc & Tốc độ --}}
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                        <div class="text-xs font-bold text-emerald-800 uppercase tracking-wide">Agent AI 5 — Giọng đọc & Tốc độ</div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Nguồn giọng đọc</label>
                                <select name="voice_provider" id="quickVoiceProvider" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-sm bg-white">
                                    @foreach ($voiceCatalog as $provKey => $provInfo)
                                        <option value="{{ $provKey }}">{{ $provInfo['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Tốc độ đọc</label>
                                <select name="voice_speed" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-sm bg-white">
                                    <option value="0.90">0.90x (Chậm rãi, rõ ràng)</option>
                                    <option value="1.00" selected>1.00x (Tiêu chuẩn tự nhiên)</option>
                                    <option value="1.08">1.08x (Nhanh nhẹ nhàng)</option>
                                    <option value="1.15">1.15x (Nhịp TikTok nhanh)</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Chọn giọng đọc (Khóa duy nhất 1 giọng toàn video)</label>
                            <select name="voice_id" id="quickVoiceId" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-sm bg-white"></select>
                            <input type="text" name="custom_voice_id" id="quickCustomVoiceId"
                                   placeholder="Nhập Voice ID từ genmax.io/app/voices..."
                                   class="hidden mt-2 w-full rounded-lg border border-slate-300 px-2.5 py-2 text-sm">
                        </div>
                    </div>

                    {{-- Agent 7: Phụ đề tùy chọn --}}
                    <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-emerald-800 uppercase tracking-wide">Agent AI 7 — Phụ đề & Hậu kỳ CapCut</span>
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                                <input type="checkbox" name="subtitle_enabled" value="1" checked class="rounded text-emerald-600 w-4 h-4">
                                Bật phụ đề trên video
                            </label>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1">Mẫu phụ đề</label>
                            <select name="subtitle_style" class="w-full rounded-lg border border-slate-300 px-2.5 py-2 text-sm bg-white">
                                @foreach ($subtitleStyles as $styleKey => $styleMeta)
                                    <option value="{{ $styleKey }}">{{ $styleMeta['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <button type="submit"
                            class="w-full py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow transition">
                        Khởi Chạy Dây Chuyền 8-Agent Sản Xuất Video
                    </button>
                </form>
            </div>
        </div>

        {{-- Cột phải: Danh sách video & trạng thái duyệt Agent 8 --}}
        <div class="lg:col-span-7 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-bold text-slate-900">Video Đang Sản Xuất & Kiểm Duyệt (Agent 3 → 8)</h2>
                    <span class="text-xs text-slate-500">15 video gần nhất</span>
                </div>

                @if ($projects->isEmpty())
                    <div class="text-center py-10 text-slate-500 text-sm">
                        Chưa có video nào. Hãy tạo Kế hoạch Tháng ở mục <strong>Kế hoạch (Agent 1 & 2)</strong> hoặc dùng form sản xuất nhanh bên trái!
                    </div>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($projects as $p)
                            @php
                                $qa = $p->latestQaReview;
                                $qaBadge = match($p->qa_status) {
                                    'approved' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                                    'rejected' => 'bg-rose-100 text-rose-800 border-rose-200',
                                    'running' => 'bg-amber-100 text-amber-800 border-amber-200',
                                    'failed' => 'bg-red-100 text-red-800 border-red-200',
                                    default => 'bg-slate-100 text-slate-600 border-slate-200',
                                };
                                $qaLabel = match($p->qa_status) {
                                    'approved' => 'Agent 8: ĐÃ DUYỆT (' . ($qa?->overall_score ?? 0) . 'đ)',
                                    'rejected' => 'Agent 8: CẦN SỬA (' . ($qa?->overall_score ?? 0) . 'đ)',
                                    'running' => 'Đang chạy 8-Agent...',
                                    'failed' => 'Lỗi xử lý',
                                    default => 'Chờ kiểm duyệt',
                                };
                            @endphp
                            <div class="py-3.5 flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <a href="{{ route('health.studio', $p->id) }}" class="font-bold text-slate-900 hover:text-emerald-700 text-sm block truncate">
                                        #{{ $p->id }} — {{ $p->title }}
                                    </a>
                                    <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-slate-500">
                                        <span>Giọng: {{ $p->voice_provider ?? 'edge_tts' }} ({{ $p->voice_speed ?? 1.0 }}x)</span>
                                        <span>•</span>
                                        <span>Phụ đề: {{ $p->subtitle_enabled ? ($p->subtitle_style ?? 'capcut_yellow') : 'Tắt' }}</span>
                                        <span>•</span>
                                        <span>{{ $p->created_at?->format('d/m H:i') }}</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold border {{ $qaBadge }}">
                                        {{ $qaLabel }}
                                    </span>
                                    <a href="{{ route('health.studio', $p->id) }}"
                                       class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-emerald-700 text-white text-xs font-semibold transition">
                                        Mở Studio
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const voiceCatalog = @json($voiceCatalog);
    const provSelect = document.getElementById('quickVoiceProvider');
    const voiceSelect = document.getElementById('quickVoiceId');
    const customInput = document.getElementById('quickCustomVoiceId');

    function syncQuickVoices() {
        const prov = provSelect.value;
        const voices = (voiceCatalog[prov] && voiceCatalog[prov].voices) ? voiceCatalog[prov].voices : {};
        voiceSelect.innerHTML = '';
        Object.entries(voices).forEach(([id, label]) => {
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = label;
            voiceSelect.appendChild(opt);
        });
        toggleCustomVoice();
    }

    function toggleCustomVoice() {
        customInput.classList.toggle('hidden', voiceSelect.value !== 'custom');
    }

    provSelect.addEventListener('change', syncQuickVoices);
    voiceSelect.addEventListener('change', toggleCustomVoice);
    syncQuickVoices();
</script>
@endpush
