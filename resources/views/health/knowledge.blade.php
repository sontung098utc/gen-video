@extends('layouts.health')

@section('title', 'Kho Tài Liệu Sức Khỏe Chính Thống — Sống Khỏe Mỗi Ngày')

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-5">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <h1 class="text-lg font-extrabold text-slate-900 mb-1">Thêm Tài Liệu Y Khoa Chính Thống</h1>
                <p class="text-xs text-slate-500 mb-4">
                    Agent AI 1 (Kế hoạch) và Agent AI 3 (Biên kịch) sẽ tự động trích xuất kiến thức chuẩn xác từ kho tài liệu Đông y, Tây y, Khoa học & Y tế của bạn.
                </p>

                <form action="{{ route('health.knowledge.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tiêu đề tài liệu *</label>
                        <input type="text" name="title" required placeholder="VD: Hướng dẫn dinh dưỡng cho người tăng huyết áp — Bộ Y tế"
                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Phân loại *</label>
                            <select name="category" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                                <option value="dong_y">Đông Y Chính Thống</option>
                                <option value="tay_y">Tây Y / Y Học Hiện Đại</option>
                                <option value="dinh_duong">Dinh Dưỡng & Thực Phẩm</option>
                                <option value="khoa_hoc">Nghiên Cứu Khoa Học</option>
                                <option value="y_te" selected>Y Tế & Sức Khỏe Cộng Đồng</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Nguồn uy tín</label>
                            <input type="text" name="source_name" placeholder="VD: Bộ Y tế / WHO / Viện Dinh Dưỡng"
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Link nguồn (tùy chọn)</label>
                        <input type="url" name="source_url" placeholder="https://..."
                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tải lên file tài liệu (.txt, .md, .docx, .pdf)</label>
                        <input type="file" name="document_file" accept=".txt,.md,.docx,.pdf,.csv"
                               class="w-full text-xs border border-slate-300 rounded-xl p-2 bg-slate-50">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Hoặc dán nội dung kiến thức trực tiếp</label>
                        <textarea name="manual_text" rows="5" placeholder="Dán tóm tắt hoặc nội dung bài viết y khoa chính thống vào đây..."
                                  class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm"></textarea>
                    </div>

                    <button type="submit" class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm">
                        Lưu Vào Kho Kiến Thức
                    </button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-7">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Tài Liệu Đang Hoạt Động ({{ $docs->count() }})</h2>
                @if ($docs->isEmpty())
                    <div class="text-center py-10 text-sm text-slate-500">Chưa có tài liệu nào được tải lên.</div>
                @else
                    <div class="space-y-3">
                        @foreach ($docs as $doc)
                            <div class="p-4 rounded-xl border border-slate-200 flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 text-[11px] font-bold">
                                            {{ $doc->categoryLabel() }}
                                        </span>
                                        @if ($doc->source_name)
                                            <span class="text-xs text-slate-500">Nguồn: {{ $doc->source_name }}</span>
                                        @endif
                                    </div>
                                    <div class="font-bold text-slate-900 text-sm mt-1">{{ $doc->title }}</div>
                                    <p class="text-xs text-slate-600 mt-1 line-clamp-3">{{ $doc->extracted_text }}</p>
                                </div>
                                <form action="{{ route('health.knowledge.delete', $doc->id) }}" method="POST" onsubmit="return confirm('Xóa tài liệu này?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs text-rose-600 hover:underline font-semibold">Xóa</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
