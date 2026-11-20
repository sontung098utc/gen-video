@extends('layouts.health')

@section('title', 'Kho Video Châu Á Nội Bộ — Agent AI 4')

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-5">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <h1 class="text-lg font-extrabold text-slate-900 mb-1">Tải Lên Video Châu Á Nội Bộ</h1>
                <p class="text-xs text-slate-500 mb-4">
                    Agent AI 4 sẽ ưu tiên lấy video từ kho nội bộ này trước (bối cảnh người Việt Nam / Trung Quốc, thảo dược, bác sĩ, tập dưỡng sinh...) rồi mới tìm thêm trên Pexels & Pixabay.
                </p>

                <form action="{{ route('health.stock.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Tên clip *</label>
                        <input type="text" name="title" required placeholder="VD: Cô gái Việt pha trà gừng mật ong buổi sáng"
                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Từ khóa gắn thẻ (Việt & Anh cách nhau dấu phẩy) *</label>
                        <input type="text" name="tags" required placeholder="trà gừng, thảo dược, buổi sáng, asian, herbal tea, morning, vietnamese"
                               class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Nhóm chủ đề</label>
                        <select name="category" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
                            <option value="dong_y">Đông Y & Thảo Dược</option>
                            <option value="tay_y">Bác Sĩ & Y Tế</option>
                            <option value="dinh_duong">Món Ăn & Dinh Dưỡng</option>
                            <option value="tap_luyen">Dưỡng Sinh & Tập Luyện</option>
                            <option value="lifestyle" selected>Đời Sống Gia Đình Châu Á</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">File video (.mp4, .mov, .webm) *</label>
                        <input type="file" name="video_file" required accept=".mp4,.mov,.webm"
                               class="w-full text-xs border border-slate-300 rounded-xl p-2 bg-slate-50">
                    </div>

                    <button type="submit" class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm">
                        Tải Lên Kho Video Châu Á
                    </button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-7">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <h2 class="text-lg font-bold text-slate-900 mb-4">Kho Video Đang Sẵn Sàng ({{ $assets->count() }})</h2>
                @if ($assets->isEmpty())
                    <div class="text-center py-10 text-sm text-slate-500">Chưa có video nội bộ. Hiện Agent AI 4 sẽ tự tìm trên Pexels & Pixabay với bộ lọc người châu Á.</div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach ($assets as $asset)
                            <div class="rounded-xl border border-slate-200 overflow-hidden bg-slate-50">
                                <video controls class="w-full h-44 object-cover bg-black" src="{{ asset('storage/' . $asset->file_path) }}"></video>
                                <div class="p-3">
                                    <div class="font-bold text-xs text-slate-900">{{ $asset->title }}</div>
                                    <div class="text-[11px] text-slate-500 mt-1">Tags: {{ $asset->tags }}</div>
                                    <form action="{{ route('health.stock.delete', $asset->id) }}" method="POST" class="mt-2" onsubmit="return confirm('Xóa video này?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-[11px] text-rose-600 font-semibold hover:underline">Xóa clip</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
