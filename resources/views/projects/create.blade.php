@extends('layouts.app')

@section('title', 'Khởi Tạo Dự Án Video AI Mới — GenVideo Studio')

@section('content')
<div x-data="{
        activeTab: '{{ old('project_type', 'knowledge') }}',
        aspectRatio: '{{ old('aspect_ratio', '9:16') }}',
        costTierMode: '{{ old('cost_tier_mode', 'eco_mode') }}',
        renderEngine: '{{ old('render_engine', 'motion_graphics') }}',
        motionTemplate: '{{ old('motion_template', 'auto') }}',
        subtitleStyle: '{{ old('subtitle_style', 'karaoke_box') }}',
        workflowMode: '{{ old('workflow_mode', 'step_by_step') }}',
        voiceId: '{{ old('voice_id', 'vi-VN-NamMinhNeural') }}',
        widthMm: {{ (float) old('width_mm', 85) }},
        heightMm: {{ (float) old('height_mm', 145) }},
        depthMm: {{ (float) old('depth_mm', 45) }},
        selectedFilesCount: 0,
        selectedPrFilesCount: 0,
        selectedRefVideoName: '',
        estimatedCostUsd() {
            if (this.renderEngine === 'motion_graphics' || this.renderEngine === 'money_printer_stock') {
                return '0.000';
            }
            const sceneUnit = this.costTierMode === 'eco_mode' ? 0.003 : 0.020;
            const trellis3d = this.activeTab === 'product' ? 0.020 : 0.000;
            return (trellis3d + (sceneUnit * 6)).toFixed(3);
        }
    }"
    class="max-w-4xl mx-auto space-y-8">

    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-white">Khởi Tạo Dự Án Video Ngắn AI</h1>
            <p class="text-sm text-slate-400 mt-1">
                Chọn kịch bản <strong class="text-slate-200">Video Kiến Thức</strong> hoặc <strong class="text-slate-200">Video Sản Phẩm 3D</strong> với chiến lược tối ưu chi phí siêu tiết kiệm.
            </p>
        </div>
        <a href="{{ route('projects.index') }}" class="text-xs font-medium text-slate-400 hover:text-white">
            ← Quay lại danh sách
        </a>
    </div>

    <!-- Bộ Chuyển Đổi 3 Tab: Tab 1 (Video Kiến Thức), Tab 2 (Video Sản Phẩm), Tab 3 (Clone Video Mẫu) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 p-1.5 rounded-2xl bg-slate-900 border border-slate-800">
        <button type="button"
                @click="activeTab = 'knowledge'"
                :class="activeTab === 'knowledge'
                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-800/60'"
                class="py-3.5 px-4 rounded-xl font-semibold text-sm flex items-center justify-center gap-2 transition">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
            <span>Tab 1: Video Kiến Thức</span>
        </button>

        <button type="button"
                @click="activeTab = 'product'"
                :class="activeTab === 'product'
                    ? 'bg-purple-600 text-white shadow-lg shadow-purple-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-800/60'"
                class="py-3.5 px-4 rounded-xl font-semibold text-sm flex items-center justify-center gap-2 transition">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <span>Tab 2: Video Sản Phẩm 3D</span>
        </button>

        <button type="button"
                @click="activeTab = 'clone_reference'"
                :class="activeTab === 'clone_reference'
                    ? 'bg-cyan-600 text-white shadow-lg shadow-cyan-600/30'
                    : 'text-slate-400 hover:text-white hover:bg-slate-800/60'"
                class="py-3.5 px-4 rounded-xl font-semibold text-sm flex items-center justify-center gap-2 transition">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
            </svg>
            <span>Tab 3: Làm Lại Video Mẫu</span>
        </button>
    </div>

    <!-- Form Khởi Tạo Dự Án -->
    <form action="{{ route('projects.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf
        <input type="hidden" name="project_type" :value="activeTab">
        <input type="hidden" name="aspect_ratio" :value="aspectRatio">
        <input type="hidden" name="cost_tier_mode" :value="costTierMode">
        <input type="hidden" name="render_engine" :value="renderEngine">
        <input type="hidden" name="motion_template" :value="motionTemplate">
        <input type="hidden" name="subtitle_style" :value="subtitleStyle">
        <input type="hidden" name="workflow_mode" :value="workflowMode">
        <input type="hidden" name="voice_id" :value="voiceId">
        <input type="hidden" name="voice_name" :value="voiceId">

        <!-- Khung Nội Dung Theo Tab -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 space-y-5 shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-base font-semibold text-white"
                        x-text="activeTab === 'knowledge'
                            ? '1. Thông Tin Chủ Đề Kiến Thức (Gemini 3.8 / 3.5 Flash + Word-Anchored)'
                            : (activeTab === 'product'
                                ? '1. Hồ Sơ Sản Phẩm & Kích Thước Thực Tế (Gemini Vision + Trellis 3D)'
                                : '1. Tải Lên Video Mẫu TikTok / Reels Để Clone & Làm Lại (Hypit Workflow)')">
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5"
                       x-text="activeTab === 'knowledge'
                           ? 'AI tự động phân tích chủ đề, viết lời thoại tiếng Việt cuốn hút và chia 5-8 phân cảnh.'
                           : (activeTab === 'product'
                               ? 'Tách nền CPU bằng rembg ($0), dựng mô hình .glb bằng Trellis ($0.02) và chuẩn hóa kích thước mm bằng trimesh.'
                               : 'Hệ thống trích xuất khung hình bằng FFmpeg và dùng Gemini Multimodal phân tích cấu trúc viral để làm lại thành video mới.')">
                    </p>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    Ước tính: ~$<span x-text="estimatedCostUsd()"></span> / video
                </span>
            </div>

            <!-- Tiêu đề dự án chung -->
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                    Tiêu đề dự án video <span class="text-rose-400">*</span>
                </label>
                <input type="text"
                       name="title"
                       required
                       value="{{ old('title') }}"
                       :placeholder="activeTab === 'knowledge' ? 'VD: 5 Sự Thật Thú Vị Về Não Bộ Khi Ngủ' : 'VD: Chiến Dịch Quảng Cáo Nước Hoa Cao Cấp 3D'"
                       class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">
            </div>

            <!-- NỘI DUNG RIÊNG TAB 1: VIDEO KIẾN THỨC -->
            <div x-show="activeTab === 'knowledge'" x-cloak class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                        Chủ đề chi tiết / Nội dung tóm tắt cần truyền tải
                    </label>
                    <textarea name="topic_or_brief"
                              rows="4"
                              placeholder="Nhập chủ đề, ý tưởng chính, các gạch đầu dòng hoặc đối tượng người xem mục tiêu..."
                              class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500">{{ old('topic_or_brief') }}</textarea>
                </div>
            </div>

            <!-- ẢNH SẢN PHẨM THỰC TẾ TÙY CHỌN CHO VIDEO PR GẮN GIỎ HÀNG (TAB 1 & TAB 3) -->
            <div x-show="activeTab !== 'product'" x-cloak class="p-4 rounded-xl bg-slate-950/70 border border-emerald-500/30 space-y-2.5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-emerald-300">
                        Ảnh Sản Phẩm Thực Tế (Nếu là Video PR Gắn Giỏ Hàng TikTok — Tùy chọn)
                    </span>
                    <span class="text-[11px] px-2 py-0.5 rounded bg-emerald-500/15 text-emerald-300">
                        Chống trùng lặp nội dung 100%
                    </span>
                </div>
                <p class="text-xs text-slate-400">
                    Nếu bạn tải lên ảnh sản phẩm thật tại đây, hệ thống sẽ <strong>tự động khóa không lấy video stock miễn phí trên mạng</strong> mà dựng trực tiếp ảnh sản phẩm của bạn với hiệu ứng 60fps &amp; đồ họa PR.
                </p>
                <label class="flex items-center justify-between border border-dashed border-emerald-500/40 hover:border-emerald-400 rounded-xl px-4 py-3 cursor-pointer bg-slate-900/60 transition">
                    <span class="text-xs font-medium text-slate-200"
                          x-text="selectedPrFilesCount > 0 ? ('Đã chọn ' + selectedPrFilesCount + ' ảnh sản phẩm thực tế') : 'Bấm để chọn ảnh sản phẩm thực tế (JPG / PNG / WebP)'"></span>
                    <span class="text-xs text-emerald-400 font-semibold">Chọn ảnh</span>
                    <input type="file"
                           name="pr_product_images[]"
                           multiple
                           accept="image/jpeg,image/png,image/webp"
                           @change="selectedPrFilesCount = $event.target.files.length; if (selectedPrFilesCount > 0 && renderEngine === 'money_printer_stock') { renderEngine = 'motion_graphics'; }"
                           class="hidden">
                </label>
            </div>

            <!-- NỘI DUNG RIÊNG TAB 3: CLONE / LÀM LẠI VIDEO MẪU (.MP4) -->
            <div x-show="activeTab === 'clone_reference'" x-cloak class="space-y-5">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                        Tải lên Video Mẫu từ TikTok / Reels / Shorts (MP4, MOV, WebM — Tối đa 50MB) <span class="text-rose-400">*</span>
                    </label>
                    <label class="flex flex-col items-center justify-center border-2 border-dashed border-cyan-500/40 hover:border-cyan-400 rounded-xl p-6 cursor-pointer bg-slate-950/60 transition">
                        <svg class="w-9 h-9 text-cyan-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        <span class="text-sm font-medium text-slate-200">Bấm để chọn file Video mẫu (.mp4) từ máy tính</span>
                        <span class="text-xs mt-1"
                              :class="selectedRefVideoName ? 'text-cyan-400 font-semibold' : 'text-slate-500'"
                              x-text="selectedRefVideoName ? ('Đã chọn: ' + selectedRefVideoName) : 'AI sẽ phân tích nhịp cắt, bố cục, góc máy & cấu trúc Hook của video này'"></span>
                        <input type="file"
                               name="reference_video"
                               accept="video/mp4,video/quicktime,video/webm"
                               :required="activeTab === 'clone_reference'"
                               @change="selectedRefVideoName = $event.target.files[0] ? $event.target.files[0].name : ''"
                               class="hidden">
                    </label>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                        Yêu cầu thay đổi / chủ đề mới khi làm lại (Tùy chọn)
                    </label>
                    <textarea name="remake_instructions"
                              rows="3"
                              placeholder="VD: Giữ nguyên nhịp chuyển cảnh nhanh và cấu trúc Hook 3 giây đầu như video mẫu, nhưng viết lại nội dung tiếng Việt về chủ đề 'Top 4 sản phẩm chăm sóc da bán chạy nhất' hoặc 'Review máy lọc không khí'..."
                              class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-3 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-cyan-500">{{ old('remake_instructions') }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                        Nguồn hình ảnh cho bản Video Mới
                    </label>
                    <select name="ref_visual_mode"
                            class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-2.5 text-xs text-white focus:outline-none focus:border-cyan-500">
                        <option value="replace_with_engine">Tạo cảnh quay mới hoàn toàn (theo chế độ MoneyPrinter Stock / Motion Graphics / AI ở mục 3)</option>
                        <option value="keep_reference_clips">Giữ nguyên cảnh quay của Video Mẫu & Việt hóa 100% (Thay giọng đọc Edge-TTS + Phụ đề + BGM)</option>
                    </select>
                </div>
            </div>

            <!-- NỘI DUNG RIÊNG TAB 2: VIDEO SẢN PHẨM 3D -->
            <div x-show="activeTab === 'product'" x-cloak class="space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                            Tên thương mại của Sản Phẩm <span class="text-rose-400">*</span>
                        </label>
                        <input type="text"
                               name="product_name"
                               :required="activeTab === 'product'"
                               value="{{ old('product_name') }}"
                               placeholder="VD: Nước Hoa Nam Bleu Noir 100ml"
                               class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-purple-500">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                            Mã SKU (Cache 3D vĩnh viễn)
                        </label>
                        <input type="text"
                               name="sku_code"
                               value="{{ old('sku_code') }}"
                               placeholder="VD: SKU-PERFUME-01"
                               class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-2.5 text-sm font-mono text-white placeholder-slate-500 focus:outline-none focus:border-purple-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                        Mô tả tính năng, công dụng & Điểm nổi bật (USP) của sản phẩm
                    </label>
                    <textarea name="product_description"
                              rows="3"
                              placeholder="Mô tả chất liệu, công dụng, ưu điểm vượt trội hoặc chương trình khuyến mãi..."
                              class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:border-purple-500">{{ old('product_description') }}</textarea>
                </div>

                <!-- Nhập Kích Thước Vật Lý Thực Tế (mm) -->
                <div class="p-4 rounded-xl bg-slate-950 border border-purple-500/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-purple-300">
                            Kích Thước Vật Lý Thực Tế (Đơn vị: Milimét — Chuẩn Hóa Trimesh 1 unit = 1m)
                        </span>
                        <span class="text-xs font-mono text-cyan-400"
                              x-text="(widthMm/1000).toFixed(3) + 'm × ' + (heightMm/1000).toFixed(3) + 'm × ' + (depthMm/1000).toFixed(3) + 'm'">
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Chiều Rộng — Trục X (mm) *</label>
                            <input type="number" step="0.1" min="1" name="width_mm" x-model.number="widthMm"
                                   :required="activeTab === 'product'"
                                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm font-mono text-white focus:border-purple-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Chiều Cao — Trục Y (mm) *</label>
                            <input type="number" step="0.1" min="1" name="height_mm" x-model.number="heightMm"
                                   :required="activeTab === 'product'"
                                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm font-mono text-white focus:border-purple-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Chiều Sâu — Trục Z (mm) *</label>
                            <input type="number" step="0.1" min="1" name="depth_mm" x-model.number="depthMm"
                                   :required="activeTab === 'product'"
                                   class="w-full rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm font-mono text-white focus:border-purple-500 focus:outline-none">
                        </div>
                    </div>
                </div>

                <!-- Tải Lên Nhiều Ảnh & Video Sản Phẩm -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                            Ảnh sản phẩm (JPG / PNG / WebP) <span class="text-rose-400">*</span>
                        </label>
                        <label class="flex flex-col items-center justify-center border-2 border-dashed border-slate-700 hover:border-purple-500 rounded-xl p-5 cursor-pointer bg-slate-950/60 transition">
                            <svg class="w-7 h-7 text-purple-400 mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                            <span class="text-xs font-medium text-slate-200 text-center">Chọn 1 hoặc nhiều ảnh sản phẩm</span>
                            <span class="text-[11px] text-slate-500 mt-1 text-center" x-text="selectedFilesCount > 0 ? ('Đã chọn ' + selectedFilesCount + ' ảnh') : 'Tách nền & dựng 3D .glb'"></span>
                            <input type="file"
                                   name="product_images[]"
                                   multiple
                                   accept="image/jpeg,image/png,image/webp"
                                   :required="activeTab === 'product'"
                                   @change="selectedFilesCount = $event.target.files.length"
                                   class="hidden">
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-300 mb-2">
                            Video sản phẩm (MP4/MOV — Tùy chọn)
                        </label>
                        <label class="flex flex-col items-center justify-center border-2 border-dashed border-slate-700 hover:border-cyan-500 rounded-xl p-5 cursor-pointer bg-slate-950/60 transition">
                            <svg class="w-7 h-7 text-cyan-400 mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <span class="text-xs font-medium text-slate-200 text-center">Chọn video quay sản phẩm (.mp4)</span>
                            <span class="text-[11px] text-slate-500 mt-1 text-center">Tự động trích khung hình học góc 3D</span>
                            <input type="file"
                                   name="product_video"
                                   accept="video/mp4,video/quicktime,video/webm"
                                   class="hidden">
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cấu Hình Tỷ Lệ Khung Hình, Giọng Đọc & Chế Độ Chi Phí -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Tỷ lệ khung hình & Giọng đọc -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 space-y-5">
                <h3 class="text-sm font-semibold text-white uppercase tracking-wider">2. Định Dạng Khung Hình & Giọng Đọc</h3>

                <div>
                    <label class="block text-xs text-slate-400 mb-2">Tỷ lệ khung hình xuất bản</label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button"
                                @click="aspectRatio = '9:16'"
                                :class="aspectRatio === '9:16' ? 'border-indigo-500 bg-indigo-500/15 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                                class="p-3 rounded-xl border text-left transition">
                            <div class="font-semibold text-sm">9:16 Dọc (1080×1920)</div>
                            <div class="text-[11px] opacity-75 mt-0.5">TikTok • Reels • Shorts</div>
                        </button>
                        <button type="button"
                                @click="aspectRatio = '16:9'"
                                :class="aspectRatio === '16:9' ? 'border-indigo-500 bg-indigo-500/15 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                                class="p-3 rounded-xl border text-left transition">
                            <div class="font-semibold text-sm">16:9 Ngang (1920×1080)</div>
                            <div class="text-[11px] opacity-75 mt-0.5">YouTube • Website • TV</div>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-2">Giọng đọc Neural tiếng Việt (Edge-TTS $0.00)</label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button"
                                @click="voiceId = 'vi-VN-NamMinhNeural'"
                                :class="voiceId === 'vi-VN-NamMinhNeural' ? 'border-cyan-500 bg-cyan-500/15 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                                class="p-3 rounded-xl border text-left transition">
                            <div class="font-semibold text-sm">Nam Minh (Nam)</div>
                            <div class="text-[11px] font-mono opacity-75">vi-VN-NamMinhNeural</div>
                        </button>
                        <button type="button"
                                @click="voiceId = 'vi-VN-HoaiMyNeural'"
                                :class="voiceId === 'vi-VN-HoaiMyNeural' ? 'border-cyan-500 bg-cyan-500/15 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                                class="p-3 rounded-xl border text-left transition">
                            <div class="font-semibold text-sm">Hoài My (Nữ)</div>
                            <div class="text-[11px] font-mono opacity-75">vi-VN-HoaiMyNeural</div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Chế độ Chi phí (Eco Mode vs Full AI Video) & Chế độ Duyệt -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 space-y-5">
                <h3 class="text-sm font-semibold text-white uppercase tracking-wider">3. Chiến Lược Chi Phí & Chế Độ Vận Hành</h3>

                <div class="space-y-2.5">
                    <label class="block text-xs text-slate-400">Chế độ tạo hình ảnh / chuyển động (MoneyPrinterTurbo, Hypit & Fal.ai)</label>
                    <button type="button"
                            @click="renderEngine = 'money_printer_stock'; costTierMode = 'eco_mode'"
                            :class="renderEngine === 'money_printer_stock' ? 'border-amber-500 bg-amber-500/10 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                            class="w-full p-3 rounded-xl border text-left flex items-center justify-between transition">
                        <div>
                            <div class="font-semibold text-sm text-amber-300">MoneyPrinterTurbo — Video Stock Thật ($0.00 / video)</div>
                            <div class="text-xs text-slate-400">Chỉ dùng cho Video Kiến Thức thuần túy (tự động tắt khi có ảnh SP để tránh trùng lặp TikTok)</div>
                        </div>
                        <span class="font-mono text-xs px-2 py-1 rounded bg-amber-500/20 text-amber-300">$0.000</span>
                    </button>

                    <button type="button"
                            @click="renderEngine = 'motion_graphics'; costTierMode = 'eco_mode'"
                            :class="renderEngine === 'motion_graphics' ? 'border-cyan-500 bg-cyan-500/10 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                            class="w-full p-3 rounded-xl border text-left flex items-center justify-between transition">
                        <div>
                            <div class="font-semibold text-sm text-cyan-300">Hypit Motion Graphics — Miễn Phí ($0.00 / video)</div>
                            <div class="text-xs text-slate-400">Render đồ họa chuyển động HTML/Chromium 60fps (Comment Sticker, Ranking, Spec 3D, Box)</div>
                        </div>
                        <span class="font-mono text-xs px-2 py-1 rounded bg-cyan-500/20 text-cyan-300">$0.000</span>
                    </button>

                    <button type="button"
                            @click="renderEngine = 'ai_fal'; costTierMode = 'eco_mode'"
                            :class="renderEngine === 'ai_fal' && costTierMode === 'eco_mode' ? 'border-emerald-500 bg-emerald-500/10 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                            class="w-full p-3 rounded-xl border text-left flex items-center justify-between transition">
                        <div>
                            <div class="font-semibold text-sm text-emerald-400">Eco Mode — Ảnh AI Fal.ai ($0.003 / cảnh)</div>
                            <div class="text-xs text-slate-400">Ảnh AI Flux Schnell + FFmpeg 60fps Dynamic Motion (tự fallback Motion Graphics nếu lỗi)</div>
                        </div>
                        <span class="font-mono text-xs px-2 py-1 rounded bg-emerald-500/20 text-emerald-300">$0.003</span>
                    </button>

                    <button type="button"
                            @click="renderEngine = 'ai_fal'; costTierMode = 'full_ai_video_mode'"
                            :class="renderEngine === 'ai_fal' && costTierMode === 'full_ai_video_mode' ? 'border-purple-500 bg-purple-500/10 text-white' : 'border-slate-800 bg-slate-950 text-slate-400'"
                            class="w-full p-3 rounded-xl border text-left flex items-center justify-between transition">
                        <div>
                            <div class="font-semibold text-sm text-purple-300">Full AI Video Mode ($0.020 / clip 5s)</div>
                            <div class="text-xs text-slate-400">LTX-Video Image-to-Video từ ảnh Keyframe / 3D Turntable</div>
                        </div>
                        <span class="font-mono text-xs px-2 py-1 rounded bg-purple-500/20 text-purple-300">$0.020</span>
                    </button>
                </div>

                <!-- Tùy chọn Template Motion Graphics & Kiểu Phụ Đề Hypit -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-800">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1.5">Template Đồ Họa (Hypit)</label>
                        <select x-model="motionTemplate"
                                class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <option value="auto">Tự động chọn theo từng cảnh</option>
                            <option value="comment_sticker">TikTok Comment Sticker (Câu hỏi Hook)</option>
                            <option value="ranking_board">Bảng Xếp Hạng / Top List (TierBoard)</option>
                            <option value="product_spec">Thẻ Thông Số Sản Phẩm 3D (mm & USP)</option>
                            <option value="box_caption">Thẻ Tiêu Đề Nổi Bật (Box Caption)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1.5">Kiểu Phụ Đề Tiếng Việt</label>
                        <select x-model="subtitleStyle"
                                class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <option value="karaoke_box">Box Karaoke Hypit (Nền hộp nổi bật)</option>
                            <option value="karaoke">Karaoke Vàng Truyền Thống (#FFFF00)</option>
                        </select>
                    </div>
                </div>

                <!-- Công tắc Chế Độ Vận Hành: Step-by-Step vs Full-Auto -->
                <div class="pt-2 border-t border-slate-800">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="block text-sm font-semibold text-white">Công tắc Chế độ Vận hành</span>
                            <span class="block text-xs text-slate-400"
                                  x-text="workflowMode === 'step_by_step'
                                      ? 'Duyệt từng bước qua 4 Cổng kiểm duyệt (Khuyên dùng để tinh chỉnh)'
                                      : 'Tự động hoàn toàn (Full-Auto): Chạy thông suốt tới khi xuất file MP4'">
                            </span>
                        </div>
                        <button type="button"
                                @click="workflowMode = (workflowMode === 'step_by_step' ? 'auto' : 'step_by_step')"
                                :class="workflowMode === 'auto' ? 'bg-cyan-500' : 'bg-slate-700'"
                                class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200">
                            <span :class="workflowMode === 'auto' ? 'translate-x-7' : 'translate-x-0'"
                                  class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow ring-0 transition duration-200"></span>
                        </button>
                    </div>
                    <div class="mt-2 flex items-center gap-2 text-xs">
                        <span :class="workflowMode === 'step_by_step' ? 'text-amber-400 font-semibold' : 'text-slate-500'">
                            • Duyệt từng bước (Step-by-Step)
                        </span>
                        <span class="text-slate-700">|</span>
                        <span :class="workflowMode === 'auto' ? 'text-cyan-400 font-semibold' : 'text-slate-500'">
                            • Tự động hoàn toàn (Full-Auto)
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nút bấm Khởi tạo -->
        <div class="flex items-center justify-end gap-4 pt-2">
            <button type="submit"
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gradient-to-r from-indigo-600 via-purple-600 to-cyan-500 hover:from-indigo-500 hover:to-cyan-400 text-white font-display font-bold text-base shadow-xl shadow-indigo-600/25 flex items-center justify-center gap-3 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <span>Bắt Đầu Khởi Tạo Dự Án</span>
            </button>
        </div>
    </form>
</div>
@endsection
