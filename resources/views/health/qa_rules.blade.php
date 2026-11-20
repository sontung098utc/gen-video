@extends('layouts.health')

@section('title', 'Bộ Quy Chuẩn Kiểm Duyệt — Trưởng Phòng Agent AI 8')

@section('content')
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <div class="mb-5">
            <div class="inline-flex items-center gap-2 text-xs font-bold text-rose-700 bg-rose-50 px-3 py-1 rounded-full mb-2">
                Agent AI 8 — Trưởng Phòng Kiểm Duyệt (QA Gatekeeper)
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900">Bộ Quy Chuẩn Kiểm Duyệt Video</h1>
            <p class="text-sm text-slate-600 mt-1">
                Agent AI 8 đọc trực tiếp 3 tài liệu quy chuẩn dưới đây để chấm điểm mọi video. Nếu vi phạm hoặc chưa đạt điểm chuẩn, Trưởng phòng sẽ từ chối và bắt buộc các Agent liên quan làm lại.
            </p>
        </div>

        <form action="{{ route('health.qa-rules.update') }}" method="POST" class="space-y-6">
            @csrf
            @foreach ($files as $filename => $label)
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-1.5">{{ $label }} <span class="text-xs font-normal text-slate-400">({{ $filename }})</span></label>
                    <textarea name="{{ str_replace('.', '_', $filename) }}" rows="9"
                              class="w-full rounded-xl border border-slate-300 px-4 py-3 font-mono text-xs leading-relaxed bg-slate-50 focus:bg-white focus:border-emerald-500">{{ $contents[$filename] ?? '' }}</textarea>
                </div>
            @endforeach

            <button type="submit" class="px-6 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm shadow">
                Lưu Bộ Quy Chuẩn Cho Agent AI 8
            </button>
        </form>
    </div>
@endsection
