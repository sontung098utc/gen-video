@extends('layouts.health')

@section('title', 'Cài Đặt Kênh, Giọng Đọc & API Keys — Sống Khỏe Mỗi Ngày Studio')

@section('content')
    <div class="max-w-5xl mx-auto space-y-6">
        {{-- KHỐI 1: QUẢN LÝ & THÊM GIỌNG ĐỌC TÙY CHỈNH BẰNG VOICE ID --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <div class="flex items-center justify-between mb-1">
                <h1 class="text-xl font-extrabold text-slate-900">Quản Lý Giọng Đọc Tùy Chỉnh (Theo Voice ID)</h1>
                <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                    Agent AI 5 — Đa Nhà Cung Cấp
                </span>
            </div>
            <p class="text-xs text-slate-500 mb-5">
                Thêm trực tiếp <strong>Tên giọng đọc + Voice ID</strong> của <strong>GenMax - CapCut</strong>, <strong>Vivibe / Vbee</strong>, <strong>Google Cloud TTS</strong> hoặc <strong>Edge TTS</strong>. Các giọng đọc đã lưu sẽ tự động xuất hiện đầu tiên trong danh sách chọn giọng khi sản xuất video!
            </p>

            <form action="{{ route('health.settings.voices.store') }}" method="POST" class="p-4 rounded-2xl bg-slate-50 border border-slate-200 mb-5">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tên giọng đọc *</label>
                        <input type="text" name="name" required placeholder="VD: Nam Trầm, Nữ Cute (Capcut), Thư Review..."
                               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                    </div>
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Voice ID *</label>
                        <input type="text" name="voice_id" required placeholder="VD: 7493478233703191041 hoặc thu-review"
                               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white font-mono">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Nhà cung cấp *</label>
                        <select name="provider" class="w-full rounded-xl border border-slate-300 px-2.5 py-2 text-sm bg-white">
                            <option value="genmax">GenMax - CapCut</option>
                            <option value="vbee">Vivibe / Vbee</option>
                            <option value="google_tts">Google Cloud TTS</option>
                            <option value="edge_tts">Edge Neural</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit"
                                class="w-full py-2.5 px-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow transition">
                            + Thêm giọng đọc
                        </button>
                    </div>
                </div>
            </form>

            {{-- Bảng Danh Sách Giọng Đọc Đã Lưu --}}
            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-xs font-bold text-slate-600 uppercase">
                        <tr>
                            <th class="px-4 py-3 text-left">Tên giọng đọc</th>
                            <th class="px-4 py-3 text-left">Voice ID</th>
                            <th class="px-4 py-3 text-left">Nhà cung cấp</th>
                            <th class="px-4 py-3 text-right">Xóa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($customVoices as $voice)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-2.5 font-bold text-slate-900">{{ $voice->name }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-700">{{ $voice->voice_id }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
                                        {{ $voice->providerLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <form action="{{ route('health.settings.voices.delete', $voice->id) }}" method="POST" onsubmit="return confirm('Xóa giọng đọc này?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-rose-600 hover:text-rose-800 font-extrabold text-sm px-2" title="Xóa giọng đọc">✕</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-xs text-slate-500">
                                    Chưa có giọng đọc tùy chỉnh nào. Hãy nhập Tên giọng đọc + Voice ID ở trên để lưu nhanh!
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- KHỐI 2: CÀI ĐẶT KÊNH & CỔNG API --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <h2 class="text-xl font-extrabold text-slate-900 mb-1">Cài Đặt Kênh & Cổng API Giọng Đọc / Ảnh Bìa / Video</h2>
            <p class="text-xs text-slate-500 mb-6">
                Các khóa bí mật được mã hóa an toàn trong cơ sở dữ liệu (để trống nếu muốn giữ nguyên khóa cũ).
            </p>

            @php
                $groups = [
                    'channel' => '1. Thông Tin Kênh Thương Hiệu (Sống Khỏe Mỗi Ngày)',
                    'ai' => '2. Não Bộ AI (Agent 1, 2, 3, 8)',
                    'stock' => '3. Kho Video Stock Châu Á (Agent 4 — Pexels & Pixabay)',
                    'voice' => '4. Đa Giọng Đọc (Agent 5 — Google Cloud TTS, Vivibe/Vbee, GenMax)',
                    'thumbnail' => '5. Sinh Ảnh Bìa Photographic (Agent 6 — ChatGPT / OpenAI & Muse AI)',
                ];
            @endphp

            <form action="{{ route('health.settings.update') }}" method="POST" class="space-y-6">
                @csrf

                @foreach ($groups as $groupKey => $groupTitle)
                    <div class="p-5 rounded-2xl border border-slate-200 bg-slate-50/70 space-y-4">
                        <h3 class="text-sm font-extrabold text-emerald-900 uppercase tracking-wide">{{ $groupTitle }}</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @foreach ($defs as $key => $meta)
                                @if ($meta['group'] === $groupKey)
                                    <div class="{{ in_array($key, ['channel_tiktok_url', 'channel_fanpage_url', 'channel_youtube_url', 'vbee_endpoint', 'genmax_endpoint', 'muse_ai_endpoint'], true) ? 'sm:col-span-2' : '' }}">
                                        <label class="flex items-center justify-between text-xs font-bold text-slate-700 mb-1">
                                            <span>{{ $meta['label'] }}</span>
                                            @if ($meta['is_secret'])
                                                <span class="text-[11px] font-semibold {{ ($hasValue[$key] ?? false) ? 'text-emerald-600' : 'text-amber-600' }}">
                                                    {{ ($hasValue[$key] ?? false) ? '● Đã cấu hình' : '○ Chưa nhập' }}
                                                </span>
                                            @endif
                                        </label>

                                        @if ($key === 'thumbnail_provider')
                                            <select name="thumbnail_provider" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                                                <option value="openai" @selected(($values[$key] ?? 'openai') === 'openai')>ChatGPT / OpenAI Image (gpt-image-1 / dall-e-3) — Khuyên dùng</option>
                                                <option value="muse_ai" @selected(($values[$key] ?? '') === 'muse_ai')>Muse AI (Photographic Image Generation)</option>
                                                <option value="fal" @selected(($values[$key] ?? '') === 'fal')>Fal.ai Flux Schnell (Dự phòng)</option>
                                                <option value="scene_frame" @selected(($values[$key] ?? '') === 'scene_frame')>Trích khung hình đẹp từ video (Miễn phí)</option>
                                            </select>
                                        @elseif ($key === 'openai_image_model')
                                            <select name="openai_image_model" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                                                <option value="gpt-image-1" @selected(($values[$key] ?? 'gpt-image-1') === 'gpt-image-1')>gpt-image-1 (ChatGPT thế hệ mới — Photographic cực nét)</option>
                                                <option value="dall-e-3" @selected(($values[$key] ?? '') === 'dall-e-3')>dall-e-3 (OpenAI DALL·E 3 — Natural)</option>
                                            </select>
                                        @else
                                            <input type="{{ $meta['is_secret'] ? 'password' : 'text' }}"
                                                   name="{{ $key }}"
                                                   value="{{ $values[$key] ?? '' }}"
                                                   placeholder="{{ $meta['is_secret'] ? (($hasValue[$key] ?? false) ? '•••••••••••••••• (Để trống để giữ nguyên)' : 'Dán API Key vào đây...') : '' }}"
                                                   class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm bg-white">
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow">
                        Lưu Toàn Bộ Cấu Hình
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
