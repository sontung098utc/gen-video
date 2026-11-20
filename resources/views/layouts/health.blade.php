<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Sống Khỏe Mỗi Ngày — 8-Agent AI Video Studio')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col">
    <header class="bg-emerald-900 text-white shadow-md sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            <a href="{{ route('health.dashboard') }}" class="flex items-center gap-3 shrink-0">
                <div class="w-10 h-10 rounded-xl bg-emerald-500 flex items-center justify-center font-black text-lg shadow">
                    SK
                </div>
                <div>
                    <div class="font-bold text-base leading-tight">Sống Khỏe Mỗi Ngày</div>
                    <div class="text-xs text-emerald-200">8-Agent AI Health Video Studio</div>
                </div>
            </a>

            <nav class="flex items-center gap-1 sm:gap-2 overflow-x-auto text-sm font-medium">
                <a href="{{ route('health.dashboard') }}"
                   class="px-3 py-2 rounded-lg transition {{ request()->routeIs('health.dashboard') ? 'bg-emerald-700 text-white' : 'text-emerald-100 hover:bg-emerald-800' }}">
                    Tổng quan
                </a>
                <a href="{{ route('health.plans') }}"
                   class="px-3 py-2 rounded-lg transition {{ request()->routeIs('health.plans*') ? 'bg-emerald-700 text-white' : 'text-emerald-100 hover:bg-emerald-800' }}">
                    Kế hoạch (Agent 1 & 2)
                </a>
                <a href="{{ route('health.knowledge') }}"
                   class="px-3 py-2 rounded-lg transition {{ request()->routeIs('health.knowledge*') ? 'bg-emerald-700 text-white' : 'text-emerald-100 hover:bg-emerald-800' }}">
                    Kho Kiến Thức
                </a>
                <a href="{{ route('health.stock') }}"
                   class="px-3 py-2 rounded-lg transition {{ request()->routeIs('health.stock*') ? 'bg-emerald-700 text-white' : 'text-emerald-100 hover:bg-emerald-800' }}">
                    Kho Video Châu Á
                </a>
                <a href="{{ route('health.qa-rules') }}"
                   class="px-3 py-2 rounded-lg transition {{ request()->routeIs('health.qa-rules*') ? 'bg-emerald-700 text-white' : 'text-emerald-100 hover:bg-emerald-800' }}">
                    Quy chuẩn Agent 8
                </a>
                <a href="{{ route('health.settings') }}"
                   class="px-3 py-2 rounded-lg transition {{ request()->routeIs('health.settings*') ? 'bg-emerald-700 text-white' : 'text-emerald-100 hover:bg-emerald-800' }}">
                    Cài đặt API
                </a>
            </nav>
        </div>
    </header>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @if (session('status'))
            <div class="mb-5 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-emerald-900 text-sm font-medium flex items-center justify-between">
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-5 rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-rose-800 text-sm">
                <div class="font-bold mb-1">Vui lòng kiểm tra lại dữ liệu nhập:</div>
                <ul class="list-disc pl-5 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="border-t border-slate-200 bg-white py-4 text-center text-xs text-slate-500">
        Sống Khỏe Mỗi Ngày — Hệ thống 8 Agent AI sản xuất Video Kiến Thức Sức Khỏe cho TikTok, Fanpage Reels & YouTube Shorts
    </footer>

    @stack('scripts')
</body>
</html>
