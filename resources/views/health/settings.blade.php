@extends('layouts.health')

@section('title', 'Cài Đặt Kênh & API Keys — Sống Khỏe Mỗi Ngày Studio')

@section('content')
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 max-w-4xl mx-auto">
        <h1 class="text-2xl font-extrabold text-slate-900 mb-1">Cài Đặt Kênh & Cổng API Giọng Đọc / Video / AI</h1>
        <p class="text-sm text-slate-600 mb-6">
            Nhập API Key cho Google Cloud TTS, Vbee (Vivibe), GenMax (<code>genmax.io/app/voices</code>), Pexels, Pixabay, Gemini & Fal.ai. Các khóa bí mật được mã hóa an toàn trong cơ sở dữ liệu (để trống nếu muốn giữ nguyên khóa cũ).
        </p>

        @php
            $groups = [
                'channel' => '1. Thông Tin Kênh Thương Hiệu (Sống Khỏe Mỗi Ngày)',
                'ai' => '2. Não Bộ AI (Agent 1, 2, 3, 8)',
                'stock' => '3. Kho Video Stock Châu Á (Agent 4 — Pexels & Pixabay)',
                'voice' => '4. Đa Giọng Đọc (Agent 5 — Google Cloud TTS, Vbee/Vivibe, GenMax)',
                'thumbnail' => '5. Sinh Ảnh Bìa Photographic (Agent 6 — Fal.ai)',
            ];
        @endphp

        <form action="{{ route('health.settings.update') }}" method="POST" class="space-y-6">
            @csrf

            @foreach ($groups as $groupKey => $groupTitle)
                <div class="p-5 rounded-2xl border border-slate-200 bg-slate-50/70 space-y-4">
                    <h2 class="text-sm font-extrabold text-emerald-900 uppercase tracking-wide">{{ $groupTitle }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach ($defs as $key => $meta)
                            @if ($meta['group'] === $groupKey)
                                <div class="{{ in_array($key, ['channel_tiktok_url', 'channel_fanpage_url', 'channel_youtube_url', 'vbee_endpoint', 'genmax_endpoint'], true) ? 'sm:col-span-2' : '' }}">
                                    <label class="flex items-center justify-between text-xs font-bold text-slate-700 mb-1">
                                        <span>{{ $meta['label'] }}</span>
                                        @if ($meta['is_secret'])
                                            <span class="text-[11px] font-semibold {{ ($hasValue[$key] ?? false) ? 'text-emerald-600' : 'text-amber-600' }}">
                                                {{ ($hasValue[$key] ?? false) ? '● Đã cấu hình' : '○ Chưa nhập' }}
                                            </span>
                                        @endif
                                    </label>
                                    <input type="{{ $meta['is_secret'] ? 'password' : 'text' }}"
                                           name="{{ $key }}"
                                           value="{{ $values[$key] ?? '' }}"
                                           placeholder="{{ $meta['is_secret'] ? (($hasValue[$key] ?? false) ? '•••••••••••••••• (Để trống để giữ nguyên)' : 'Dán API Key vào đây...') : '' }}"
                                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm bg-white">
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
@endsection
