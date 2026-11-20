@extends('layouts.health')

@section('title', 'Kế Hoạch Nội Dung Tháng → Tuần → Ngày (Agent AI 1 & 2)')

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {{-- Form Agent 1: Tạo kế hoạch --}}
        <div class="lg:col-span-5">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <div class="inline-flex items-center gap-2 text-xs font-bold text-emerald-700 bg-emerald-50 px-3 py-1 rounded-full mb-2">
                    Agent AI 1 — Content Planner
                </div>
                <h1 class="text-xl font-extrabold text-slate-900 mb-1">Lập Kế Hoạch Tháng → Tuần → Ngày</h1>
                <p class="text-xs text-slate-500 mb-5">
                    Nhập số bài viết/ngày và danh sách chủ đề sức khỏe. Agent AI 1 sẽ kết hợp Kho Tài Liệu Y Khoa của bạn để phân bổ lịch chi tiết.
                </p>

                <form action="{{ route('health.plans.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tên kế hoạch (tùy chọn)</label>
                        <input type="text" name="name" placeholder="VD: Kế hoạch Tháng 11 — Sống Khỏe Mỗi Ngày"
                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Tháng *</label>
                            <input type="number" name="month" min="1" max="12" value="{{ now()->month }}" required
                                   class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Năm *</label>
                            <input type="number" name="year" min="2024" max="2035" value="{{ now()->year }}" required
                                   class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Số bài / ngày *</label>
                            <select name="posts_per_day" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                                <option value="1" selected>1 bài / ngày</option>
                                <option value="2">2 bài / ngày</option>
                                <option value="3">3 bài / ngày</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Số ngày trong kế hoạch *</label>
                            <select name="total_days" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                                <option value="7">7 ngày (1 tuần)</option>
                                <option value="14">14 ngày (2 tuần)</option>
                                <option value="30" selected>30 ngày (Cả tháng)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Các chủ đề trọng tâm (mỗi dòng hoặc cách nhau dấu phẩy) *</label>
                        <textarea name="topics" rows="4" required
                                  placeholder="Ổn định huyết áp & Tim mạch&#10;Kiểm soát đường huyết tiểu đường&#10;Dưỡng sinh Đông y & Trà thảo mộc&#10;Cải thiện giấc ngủ sâu tự nhiên&#10;Dinh dưỡng cho xương khớp người trung niên"
                                  class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm"></textarea>
                    </div>

                    <button type="submit"
                            class="w-full py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow transition">
                        Chạy Agent AI 1 — Xây Dựng Kế Hoạch
                    </button>
                </form>
            </div>
        </div>

        {{-- Danh sách kế hoạch đã lập --}}
        <div class="lg:col-span-7">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Danh Sách Kế Hoạch Đã Lập</h2>

                @if ($plans->isEmpty())
                    <div class="text-center py-12 text-slate-500 text-sm">
                        Chưa có kế hoạch nào. Hãy nhập số bài/ngày và chủ đề ở form bên trái để Agent AI 1 lên lịch ngay!
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($plans as $plan)
                            <div class="p-4 rounded-xl border border-slate-200 hover:border-emerald-300 transition flex items-center justify-between gap-4">
                                <div>
                                    <a href="{{ route('health.plans.show', $plan->id) }}" class="font-bold text-slate-900 hover:text-emerald-700">
                                        {{ $plan->name }}
                                    </a>
                                    <div class="text-xs text-slate-500 mt-1">
                                        Tháng {{ $plan->month }}/{{ $plan->year }} • {{ $plan->posts_per_day }} bài/ngày × {{ $plan->total_days }} ngày ({{ $plan->items_count }} bài viết)
                                    </div>
                                    @if ($plan->plan_summary)
                                        <p class="text-xs text-slate-600 mt-1.5 line-clamp-2">{{ $plan->plan_summary }}</p>
                                    @endif
                                </div>
                                <a href="{{ route('health.plans.show', $plan->id) }}"
                                   class="shrink-0 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold">
                                    Mở Lịch & Agent 2 →
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
