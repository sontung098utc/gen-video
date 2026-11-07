@extends('layouts.app')

@section('title', 'Studio Kiểm Duyệt & Sản Xuất Video — ' . $project->title)

@section('content')
<div x-data="studioController(@js($initialPayload))"
     x-init="initPolling()"
     class="space-y-8">

    <!-- =================================================================== -->
    <!-- 1. THANH HEADER STUDIO & CÔNG TẮC CHUYỂN ĐỔI STEP-BY-STEP <-> AUTO -->
    <!-- =================================================================== -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <span class="px-2.5 py-0.5 rounded-md text-xs font-semibold uppercase"
                      :class="project.project_type === 'product' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'bg-sky-500/20 text-sky-300 border border-sky-500/30'"
                      x-text="project.project_type === 'product' ? 'Video Sản Phẩm 3D' : 'Video Kiến Thức'">
                </span>
                <span class="px-2.5 py-0.5 rounded-md text-xs font-mono bg-slate-800 text-slate-300" x-text="'Khung hình ' + project.aspect_ratio"></span>
                <span class="px-2.5 py-0.5 rounded-md text-xs font-mono bg-emerald-500/15 text-emerald-300 border border-emerald-500/30"
                      x-text="'Chi phí thực tế: $' + Number(project.total_cost_usd || 0).toFixed(4)">
                </span>
            </div>
            <h1 class="font-display text-xl sm:text-2xl font-bold text-white" x-text="project.title"></h1>
        </div>

        <!-- Công tắc gạt nhanh Step-by-Step <-> Full-Auto & Trạng thái Polling -->
        <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-3 px-4 py-2.5 rounded-xl bg-slate-950 border border-slate-800">
                <div class="text-right">
                    <span class="block text-xs font-semibold text-white"
                          x-text="project.workflow_mode === 'auto' ? 'Tự động hoàn toàn (Full-Auto)' : 'Duyệt từng bước (Step-by-Step)'">
                    </span>
                    <span class="block text-[11px] text-slate-400">Bấm để chuyển chế độ tức thì</span>
                </div>
                <button type="button"
                        @click="toggleWorkflowMode()"
                        :disabled="isSubmitting"
                        :class="project.workflow_mode === 'auto' ? 'bg-cyan-500' : 'bg-amber-500'"
                        class="relative inline-flex h-7 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200">
                    <span :class="project.workflow_mode === 'auto' ? 'translate-x-7' : 'translate-x-0'"
                          class="pointer-events-none inline-block h-6 w-6 transform rounded-full bg-white shadow ring-0 transition duration-200"></span>
                </button>
            </div>

            <div class="px-3.5 py-2 rounded-xl bg-slate-950 border border-slate-800 text-xs flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full"
                      :class="{
                          'bg-amber-400 animate-ping': project.is_checkpoint,
                          'bg-indigo-400 animate-pulse': project.is_processing,
                          'bg-emerald-400': project.status === 'completed',
                          'bg-rose-500': project.status === 'failed'
                      }"></span>
                <span class="font-medium text-slate-200" x-text="project.status_label"></span>
            </div>
        </div>
    </div>

    <!-- Thông báo Toast Phản hồi API -->
    <div x-show="toastMessage" x-cloak
         class="p-4 rounded-xl border text-sm flex items-center justify-between transition"
         :class="toastType === 'error' ? 'bg-rose-500/15 border-rose-500/40 text-rose-200' : 'bg-emerald-500/15 border-emerald-500/40 text-emerald-200'">
        <span x-text="toastMessage"></span>
        <button type="button" @click="toastMessage = ''" class="text-xs underline ml-4">Đóng</button>
    </div>

    <!-- Hiển thị cảnh báo nếu dự án gặp lỗi FAILED -->
    <template x-if="project.status === 'failed' && project.error_message">
        <div class="p-5 rounded-2xl bg-rose-950/60 border border-rose-500/40 text-rose-200 space-y-2">
            <div class="font-semibold text-sm flex items-center gap-2">
                <span>Tiến trình gặp sự cố cần kiểm tra:</span>
            </div>
            <p class="text-xs font-mono bg-slate-950/80 p-3 rounded-xl border border-rose-500/20" x-text="project.error_message"></p>
        </div>
    </template>

    <!-- =================================================================== -->
    <!-- 2. THANH TIẾN TRÌNH STEPPER 5 CHẶNG (REAL-TIME STEPPER)            -->
    <!-- =================================================================== -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl">
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
            <template x-for="step in steps" :key="step.number">
                <div class="relative p-3.5 rounded-xl border transition flex items-center gap-3"
                     :class="{
                         'bg-amber-500/15 border-amber-400 text-amber-200 shadow-lg shadow-amber-500/10': project.current_stage_number === step.number && project.is_checkpoint,
                         'bg-indigo-600/20 border-indigo-500 text-white': project.current_stage_number === step.number && !project.is_checkpoint && project.status !== 'completed',
                         'bg-emerald-500/10 border-emerald-500/30 text-emerald-300': project.current_stage_number > step.number || project.status === 'completed',
                         'bg-slate-950/60 border-slate-800 text-slate-500': project.current_stage_number < step.number && project.status !== 'completed'
                     }">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center font-display font-bold text-xs shrink-0"
                         :class="{
                             'bg-amber-400 text-slate-950': project.current_stage_number === step.number && project.is_checkpoint,
                             'bg-indigo-500 text-white': project.current_stage_number === step.number && !project.is_checkpoint && project.status !== 'completed',
                             'bg-emerald-500 text-slate-950': project.current_stage_number > step.number || project.status === 'completed',
                             'bg-slate-800 text-slate-400': project.current_stage_number < step.number && project.status !== 'completed'
                         }"
                         x-text="step.number">
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs font-semibold truncate" x-text="step.title"></div>
                        <div class="text-[11px] opacity-80 truncate" x-text="step.subtitle"></div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Thanh Loading khi PM2 Worker đang xử lý ngầm -->
        <div x-show="project.is_processing" x-cloak class="mt-4 pt-4 border-t border-slate-800 flex items-center justify-between text-xs text-indigo-300">
            <div class="flex items-center gap-2.5">
                <svg class="w-4 h-4 animate-spin text-indigo-400" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span>PM2 Worker đang thực thi: <strong x-text="project.status_label"></strong> (Tự động cập nhật mỗi 2.5 giây...)</span>
            </div>
            <span class="font-mono text-[11px] text-slate-400">nice -n 10 • threads=6</span>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- 3. CỔNG KIỂM DUYỆT 1 (CP1): PHÊ DUYỆT SẢN PHẨM & MẪU 3D (.GLB)      -->
    <!-- =================================================================== -->
    <div x-show="project.status === 'waiting_approval_product' || (project.project_type === 'product' && activeInspectorTab === 'cp1')"
         x-cloak
         class="space-y-6">
        <div class="flex items-center justify-between bg-amber-500/10 border border-amber-500/30 rounded-2xl px-5 py-3.5">
            <div class="flex items-center gap-3">
                <span class="px-2.5 py-1 rounded-lg bg-amber-400 text-slate-950 font-display font-bold text-xs">CỔNG DUYỆT 1 (CP1)</span>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-white">Phê Duyệt Hồ Sơ Sản Phẩm & Mô Hình 3D Chuẩn Kích Thước</h2>
                    <p class="text-xs text-amber-200/80">Xoay kiểm tra mẫu 3D .glb bên trái và tinh chỉnh phân tích khách hàng bên phải trước khi lên kịch bản.</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Bên trái (7 cột): Khung 3D tương tác <model-viewer> + 4 ảnh Turntable -->
            <div class="lg:col-span-7">
                <x-model-3d-viewer />
            </div>

            <!-- Bên phải (5 cột): Kết quả AI phân tích sản phẩm & khách hàng mục tiêu -->
            <div class="lg:col-span-5 bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col justify-between space-y-5 shadow-xl">
                <div class="space-y-4">
                    <div class="border-b border-slate-800 pb-3">
                        <h3 class="text-sm font-semibold text-white">Kết Quả Phân Tích Sản Phẩm & Khách Hàng (Gemini Vision)</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Bạn có thể chỉnh sửa trực tiếp các thông tin dưới đây để định hướng kịch bản ở Bước 2.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Tên sản phẩm thương mại</label>
                        <input type="text" x-model="cp1Form.product_name"
                               class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3.5 py-2 text-sm text-white focus:border-amber-400 focus:outline-none">
                    </div>

                    <div class="grid grid-cols-3 gap-2.5">
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Rộng X (mm)</label>
                            <input type="number" step="0.1" x-model.number="cp1Form.width_mm"
                                   class="w-full rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs font-mono text-white">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Cao Y (mm)</label>
                            <input type="number" step="0.1" x-model.number="cp1Form.height_mm"
                                   class="w-full rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs font-mono text-white">
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Sâu Z (mm)</label>
                            <input type="number" step="0.1" x-model.number="cp1Form.depth_mm"
                                   class="w-full rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs font-mono text-white">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Nhóm đối tượng khách hàng mục tiêu (Target Audience)</label>
                        <textarea rows="2" x-model="cp1Form.target_audience"
                                  class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3.5 py-2 text-xs text-white focus:border-amber-400 focus:outline-none"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Chân dung khách hàng (Customer Persona)</label>
                        <textarea rows="2" x-model="cp1Form.customer_persona"
                                  class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3.5 py-2 text-xs text-white focus:border-amber-400 focus:outline-none"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-300 mb-1">Điểm nhấn bán hàng độc nhất (USP — mỗi dòng 1 ý)</label>
                        <textarea rows="3" x-model="cp1Form.usp_text"
                                  class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3.5 py-2 text-xs text-white focus:border-amber-400 focus:outline-none"></textarea>
                    </div>
                </div>

                <button type="button"
                        @click="submitApproveProduct()"
                        :disabled="isSubmitting || project.status !== 'waiting_approval_product'"
                        class="w-full py-3.5 px-5 rounded-xl bg-amber-400 hover:bg-amber-300 disabled:opacity-50 text-slate-950 font-display font-bold text-sm shadow-lg shadow-amber-400/20 transition flex items-center justify-center gap-2">
                    <span>Phê Duyệt Mẫu 3D & Chuyển Sang Lên Kịch Bản →</span>
                </button>
            </div>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- 4. CỔNG KIỂM DUYỆT 2 (CP2): PHÊ DUYỆT KỊCH BẢN & BỐI CẢNH           -->
    <!-- =================================================================== -->
    <div x-show="project.status === 'waiting_approval_script'" x-cloak class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-amber-500/10 border border-amber-500/30 rounded-2xl px-5 py-3.5">
            <div class="flex items-center gap-3">
                <span class="px-2.5 py-1 rounded-lg bg-amber-400 text-slate-950 font-display font-bold text-xs">CỔNG DUYỆT 2 (CP2)</span>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-white">Phê Duyệt Kịch Bản Tiếng Việt & Phân Chia Bối Cảnh</h2>
                    <p class="text-xs text-amber-200/80">Chỉnh sửa câu thoại, Visual Prompt, thêm/xóa hoặc sắp xếp lại thứ tự các phân cảnh.</p>
                </div>
            </div>
            <button type="button"
                    @click="addNewSceneCard()"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-amber-300 border border-amber-400/40 text-xs font-semibold transition">
                + Thêm Phân Cảnh Mới
            </button>
        </div>

        <!-- Ô sửa Master Style Prompt -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-2">
            <label class="block text-xs font-semibold uppercase tracking-wider text-indigo-300">
                Master Style Prompt (Phong cách nghệ thuật đồng nhất xuyên suốt các cảnh)
            </label>
            <textarea rows="2"
                      x-model="project.master_style_prompt"
                      placeholder="VD: Cinematic 8k photorealistic commercial lighting, shallow depth of field, Unreal Engine 5 render style..."
                      class="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none"></textarea>
        </div>

        <!-- Danh sách các Thẻ Bối Cảnh (Scene Cards) hỗ trợ kéo thả & di chuyển Lên/Xuống -->
        <div class="space-y-4">
            <template x-for="(scene, idx) in scenes" :key="scene.id || ('new_' + idx)">
                <div draggable="true"
                     @dragstart="dragStartIndex = idx"
                     @dragover.prevent
                     @drop="dropSceneCard(idx)"
                     class="bg-slate-900/90 border border-slate-800 hover:border-slate-700 rounded-2xl p-5 space-y-4 transition shadow-lg">

                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 pb-3">
                        <div class="flex items-center gap-2.5">
                            <span class="cursor-grab px-2.5 py-1 rounded-lg bg-indigo-600/20 border border-indigo-500/40 text-indigo-300 font-display font-bold text-xs"
                                  x-text="'CẢNH #' + (idx + 1)"></span>
                            <span class="text-xs text-slate-500">Kéo thả thẻ hoặc bấm nút mũi tên để đổi thứ tự</span>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <button type="button" @click="moveScene(idx, -1)" :disabled="idx === 0"
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-30 text-xs text-slate-200">↑ Lên</button>
                            <button type="button" @click="moveScene(idx, 1)" :disabled="idx === scenes.length - 1"
                                    class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 disabled:opacity-30 text-xs text-slate-200">↓ Xuống</button>
                            <button type="button" @click="removeSceneCard(idx)" :disabled="scenes.length <= 1"
                                    class="px-2.5 py-1 rounded-lg bg-rose-500/15 hover:bg-rose-500/30 disabled:opacity-30 text-xs text-rose-300 ml-2">Xóa cảnh</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- Lời thoại tiếng Việt -->
                        <div>
                            <label class="block text-xs font-semibold text-emerald-400 mb-1.5">
                                Lời thoại tiếng Việt (Narration Text — Edge-TTS)
                            </label>
                            <textarea rows="3"
                                      x-model="scene.narration_text"
                                      placeholder="Nhập câu thoại tiếng Việt tự nhiên cho phân cảnh này..."
                                      class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3.5 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none"></textarea>
                        </div>

                        <!-- Câu lệnh tạo hình ảnh Visual Prompt -->
                        <div>
                            <label class="block text-xs font-semibold text-cyan-400 mb-1.5">
                                Câu lệnh tạo hình ảnh AI (Visual Prompt — Tiếng Anh)
                            </label>
                            <textarea rows="3"
                                      x-model="scene.visual_prompt"
                                      placeholder="Detailed English prompt for Flux Schnell / LTX-Video..."
                                      class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3.5 py-2.5 text-sm text-white focus:border-cyan-500 focus:outline-none"></textarea>
                        </div>
                    </div>

                    <!-- Hàng tùy chọn Chuyển cảnh xfade, Chuyển động Camera & Gợi ý SFX -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-1">
                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Hiệu ứng chuyển cảnh (FFmpeg xfade)</label>
                            <select x-model="scene.transition_type"
                                    class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:border-indigo-500 focus:outline-none">
                                <option value="fade">fade (Mờ dần mượt mà)</option>
                                <option value="wipeleft">wipeleft (Quét sang trái)</option>
                                <option value="smoothleft">smoothleft (Trượt mượt sang trái)</option>
                                <option value="circleopen">circleopen (Mở vòng tròn)</option>
                                <option value="dissolve">dissolve (Hòa tan điểm ảnh)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Chuyển động Camera 60fps (Eco Mode)</label>
                            <select x-model="scene.camera_motion"
                                    class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:border-indigo-500 focus:outline-none">
                                <option value="zoom_in">zoom_in (Phóng nhẹ vào tâm)</option>
                                <option value="zoom_out">zoom_out (Thu nhỏ từ tâm)</option>
                                <option value="pan_right">pan_right (Lia máy sang phải)</option>
                                <option value="pan_left">pan_left (Lia máy sang trái)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-[11px] text-slate-400 mb-1">Từ khóa hiệu ứng âm thanh (Local SFX)</label>
                            <input type="text"
                                   x-model="scene.sfx_prompt"
                                   placeholder="whoosh, unbox, click, sparkle, pop, typing..."
                                   class="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs font-mono text-white focus:border-indigo-500 focus:outline-none">
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-end">
            <button type="button"
                    @click="submitApproveScript()"
                    :disabled="isSubmitting"
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-amber-400 hover:bg-amber-300 disabled:opacity-50 text-slate-950 font-display font-bold text-sm shadow-xl shadow-amber-400/20 transition">
                Phê Duyệt Kịch Bản & Chuyển Sang Tạo Giọng Đọc →
            </button>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- 5. CỔNG KIỂM DUYỆT 3 (CP3): PHÊ DUYỆT GIỌNG ĐỌC & ÂM THANH           -->
    <!-- =================================================================== -->
    <div x-show="project.status === 'waiting_approval_audio'" x-cloak class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-amber-500/10 border border-amber-500/30 rounded-2xl px-5 py-3.5">
            <div class="flex items-center gap-3">
                <span class="px-2.5 py-1 rounded-lg bg-amber-400 text-slate-950 font-display font-bold text-xs">CỔNG DUYỆT 3 (CP3)</span>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-white">Phê Duyệt Giọng Đọc Tiếng Việt (Edge-TTS) & Hiệu Ứng Âm Thanh</h2>
                    <p class="text-xs text-amber-200/80">Nghe thử giọng đọc từng cảnh, kiểm tra thời lượng mili-giây (ffprobe) và hiệu ứng SFX đính kèm.</p>
                </div>
            </div>

            <!-- Chọn đổi giọng đọc NamMinh / HoaiMy -->
            <div class="flex items-center gap-2">
                <select x-model="project.voice_id"
                        class="rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-xs text-white">
                    <option value="vi-VN-NamMinhNeural">Giọng Nam: vi-VN-NamMinhNeural</option>
                    <option value="vi-VN-HoaiMyNeural">Giọng Nữ: vi-VN-HoaiMyNeural</option>
                </select>
                <button type="button"
                        @click="submitApproveAudio(true)"
                        :disabled="isSubmitting"
                        class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-cyan-300 border border-cyan-500/30">
                    Đổi giọng & Tạo lại Audio ($0)
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <template x-for="(scene, idx) in scenes" :key="scene.id">
                <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-3.5">
                    <div class="flex items-center justify-between">
                        <span class="px-2.5 py-1 rounded-lg bg-emerald-500/15 text-emerald-300 font-display font-bold text-xs"
                              x-text="'CẢNH #' + scene.scene_order"></span>
                        <span class="font-mono text-xs text-cyan-400"
                              x-text="'Thời lượng: ' + scene.duration_ms + ' ms (' + scene.duration_seconds + 's)'"></span>
                    </div>

                    <p class="text-sm text-slate-200 italic bg-slate-950 p-3 rounded-xl border border-slate-800/80"
                       x-text="'“' + scene.narration_text + '”'"></p>

                    <!-- Audio Player nghe thử giọng đọc tiếng Việt -->
                    <div>
                        <label class="block text-[11px] text-slate-400 mb-1">Trình phát giọng đọc tiếng Việt (.mp3):</label>
                        <template x-if="scene.voice_url">
                            <audio controls class="w-full h-10 rounded-lg" :src="scene.voice_url"></audio>
                        </template>
                    </div>

                    <!-- Đổi từ khóa SFX hoặc tạo lại âm thanh riêng cảnh này -->
                    <div class="pt-2 border-t border-slate-800/80 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-400">SFX gán tự động:</span>
                            <span class="px-2 py-0.5 rounded bg-slate-800 font-mono text-amber-300"
                                  x-text="scene.sfx_file_name || ('Từ khóa: ' + (scene.sfx_prompt || 'whoosh'))"></span>
                        </div>
                        <button type="button"
                                @click="regenerateSingleScene(scene, true, false)"
                                :disabled="regeneratingSceneId === scene.id"
                                class="text-indigo-400 hover:text-indigo-300 font-medium underline">
                            Tạo lại giọng cảnh này
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-end">
            <button type="button"
                    @click="submitApproveAudio(false)"
                    :disabled="isSubmitting"
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-amber-400 hover:bg-amber-300 disabled:opacity-50 text-slate-950 font-display font-bold text-sm shadow-xl shadow-amber-400/20 transition">
                Phê Duyệt Âm Thanh & Bắt Đầu Tạo Video AI →
            </button>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- 6. CỔNG KIỂM DUYỆT 4 (CP4): XEM TRƯỚC VIDEO TỪNG CẢNH & REGENERATE   -->
    <!-- =================================================================== -->
    <div x-show="project.status === 'waiting_approval_scenes'" x-cloak class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-amber-500/10 border border-amber-500/30 rounded-2xl px-5 py-3.5">
            <div class="flex items-center gap-3">
                <span class="px-2.5 py-1 rounded-lg bg-amber-400 text-slate-950 font-display font-bold text-xs">CỔNG DUYỆT 4 (CP4)</span>
                <div>
                    <h2 class="text-sm sm:text-base font-bold text-white">Xem Trước Từng Đoạn Video AI & Tái Tạo Cảnh Lẻ</h2>
                    <p class="text-xs text-amber-200/80">Xem thử từng clip. Nếu có cảnh chưa ưng ý, bấm "Tạo lại Cảnh này" ($0.003 - $0.02) mà không cần chạy lại cả dự án.</p>
                </div>
            </div>
        </div>

        <!-- Lưới Grid 3 cột hiển thị Video Preview từng bối cảnh -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <template x-for="scene in scenes" :key="scene.id">
                <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden flex flex-col justify-between shadow-xl">
                    <div>
                        <!-- Header thẻ video -->
                        <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between">
                            <span class="font-display font-bold text-xs text-white" x-text="'CẢNH #' + scene.scene_order"></span>
                            <span class="text-[11px] font-mono text-slate-400" x-text="scene.duration_seconds + 's • xfade: ' + scene.transition_type"></span>
                        </div>

                        <!-- Trình phát Video Preview của phân cảnh -->
                        <div class="relative bg-slate-950 flex items-center justify-center overflow-hidden"
                             :class="project.aspect_ratio === '16:9' ? 'aspect-video' : 'aspect-[9/14] max-h-[380px]'">
                            <template x-if="scene.video_url">
                                <video controls loop playsinline preload="metadata"
                                       :poster="scene.image_url || ''"
                                       :src="scene.video_url"
                                       class="w-full h-full object-contain"></video>
                            </template>
                            <template x-if="!scene.video_url">
                                <div class="text-center p-6 text-xs text-slate-500">
                                    Đang kết xuất clip cho cảnh này...
                                </div>
                            </template>
                        </div>

                        <!-- Chỉnh nhanh Visual Prompt & Chuyển cảnh trước khi Regenerate -->
                        <div class="p-4 space-y-3">
                            <p class="text-xs text-slate-300 line-clamp-2 italic" x-text="'“' + scene.narration_text + '”'"></p>
                            <div>
                                <label class="block text-[10px] uppercase tracking-wider text-slate-400 mb-1">Visual Prompt (Chỉnh sửa nếu muốn tạo lại):</label>
                                <textarea rows="2" x-model="scene.visual_prompt"
                                          class="w-full rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-white"></textarea>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[11px] text-slate-400">Hiệu ứng nối cảnh:</span>
                                <select x-model="scene.transition_type"
                                        class="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1 text-xs text-white">
                                    <option value="fade">fade</option>
                                    <option value="wipeleft">wipeleft</option>
                                    <option value="smoothleft">smoothleft</option>
                                    <option value="circleopen">circleopen</option>
                                    <option value="dissolve">dissolve</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Nút Tái Tạo Riêng Cảnh Này (Regenerate Single Scene) -->
                    <div class="p-4 pt-0">
                        <button type="button"
                                @click="regenerateSingleScene(scene, false, true)"
                                :disabled="regeneratingSceneId === scene.id"
                                class="w-full py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-indigo-600 disabled:opacity-50 text-white text-xs font-semibold transition flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" :class="regeneratingSceneId === scene.id ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            <span x-text="regeneratingSceneId === scene.id ? 'Đang gửi yêu cầu tạo lại...' : 'Tạo lại Cảnh này (Regenerate)'"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex justify-end">
            <button type="button"
                    @click="submitApproveScenes()"
                    :disabled="isSubmitting"
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gradient-to-r from-amber-400 to-emerald-400 hover:from-amber-300 hover:to-emerald-300 disabled:opacity-50 text-slate-950 font-display font-bold text-sm shadow-xl shadow-emerald-500/20 transition">
                Phê Duyệt Tất Cả Cảnh & Tiến Hành Ghép Video Hoàn Chỉnh →
            </button>
        </div>
    </div>

    <!-- =================================================================== -->
    <!-- 7. MÀN HÌNH KẾT QUẢ CUỐI CÙNG & TRÌNH PHÁT VIDEO HOÀN CHỈNH          -->
    <!-- =================================================================== -->
    <div x-show="project.status === 'completed'" x-cloak class="space-y-6">
        <div class="bg-emerald-500/10 border border-emerald-500/30 rounded-2xl px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 rounded-lg bg-emerald-400 text-slate-950 font-display font-bold text-xs">HOÀN THÀNH XUẤT BẢN</span>
                <div>
                    <h2 class="text-base font-bold text-white">Video Hoàn Chỉnh Đã Sẵn Sàng Phát Hành!</h2>
                    <p class="text-xs text-emerald-200/80">Đã tích hợp chuyển cảnh xfade 60fps, âm thanh đa lớp (Auto-Ducking BGM) và phụ đề Karaoke tiếng Việt.</p>
                </div>
            </div>
            <template x-if="project.final_video_url">
                <a :href="project.final_video_url"
                   download
                   class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-display font-bold text-sm shadow-lg shadow-emerald-500/25 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    <span>Tải Video Về Máy (.MP4)</span>
                </a>
            </template>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Trình phát Video lớn (tỷ lệ 9:16 dọc hoặc 16:9 ngang) -->
            <div class="lg:col-span-5 bg-slate-900 border border-slate-800 rounded-2xl p-4 flex flex-col items-center shadow-2xl">
                <template x-if="project.final_video_url">
                    <video controls autoplay loop playsinline
                           :src="project.final_video_url"
                           :class="project.aspect_ratio === '16:9' ? 'w-full aspect-video' : 'w-full max-w-[340px] aspect-[9/16]'"
                           class="rounded-xl bg-black shadow-lg"></video>
                </template>
            </div>

            <!-- Bảng thống kê chi tiết của Video & Nhật ký API (Generation Logs) -->
            <div class="lg:col-span-7 space-y-6">
                <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 space-y-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-slate-300">Bảng Thống Kê Kỹ Thuật & Chi Phí Thực Tế</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-xs text-slate-400">Tổng thời lượng</span>
                            <p class="text-xl font-mono font-bold text-white mt-1" x-text="project.total_duration_seconds + ' giây'"></p>
                        </div>
                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-xs text-slate-400">Tổng số phân cảnh</span>
                            <p class="text-xl font-mono font-bold text-indigo-400 mt-1" x-text="project.scenes_count + ' cảnh'"></p>
                        </div>
                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-xs text-slate-400">Chi phí API thực tế</span>
                            <p class="text-xl font-mono font-bold text-emerald-400 mt-1" x-text="'$' + Number(project.total_cost_usd || 0).toFixed(4)"></p>
                        </div>
                        <div class="p-4 rounded-xl bg-slate-950 border border-slate-800">
                            <span class="text-xs text-slate-400">Thời gian xử lý</span>
                            <p class="text-xl font-mono font-bold text-cyan-400 mt-1" x-text="project.total_processing_seconds + ' giây'"></p>
                        </div>
                    </div>
                </div>

                <!-- Bảng lịch sử gọi API (Generation Logs) -->
                <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between">
                        <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-300">Nhật Ký Kiểm Toán Chi Phí API (Generation Logs)</h4>
                        <span class="text-[11px] text-slate-400">Gemini 2.5 Flash ($0) • Edge-TTS ($0) • Fal.ai</span>
                    </div>
                    <div class="overflow-x-auto max-h-72">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-950 text-slate-400 border-b border-slate-800">
                                <tr>
                                    <th class="py-2.5 px-4">Provider / Model</th>
                                    <th class="py-2.5 px-4">Tác vụ</th>
                                    <th class="py-2.5 px-4">Độ trễ</th>
                                    <th class="py-2.5 px-4 text-right">Chi phí (USD)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <template x-for="log in generationLogs" :key="log.id">
                                    <tr>
                                        <td class="py-2.5 px-4 font-mono text-slate-200" x-text="log.provider + ' / ' + log.model_name"></td>
                                        <td class="py-2.5 px-4 text-slate-400" x-text="log.action_type"></td>
                                        <td class="py-2.5 px-4 font-mono text-slate-400" x-text="log.latency_ms + ' ms'"></td>
                                        <td class="py-2.5 px-4 font-mono text-right text-emerald-400" x-text="'$' + Number(log.cost_usd).toFixed(4)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
function studioController(initialData) {
    return {
        project: initialData.project,
        productProfile: initialData.product_profile,
        scenes: initialData.scenes || [],
        generationLogs: initialData.generation_logs || [],
        activeInspectorTab: 'cp1',
        isSubmitting: false,
        regeneratingSceneId: null,
        dragStartIndex: null,
        pollingTimer: null,
        toastMessage: '',
        toastType: 'success',

        steps: [
            { number: 1, title: '1. Khởi tạo & 3D', subtitle: 'rembg + Trellis .glb' },
            { number: 2, title: '2. Kịch bản & Bối cảnh', subtitle: 'Gemini 2.5 Flash' },
            { number: 3, title: '3. Giọng đọc & SFX', subtitle: 'Edge-TTS Tiếng Việt' },
            { number: 4, title: '4. Video từng cảnh', subtitle: 'Flux / LTX-Video' },
            { number: 5, title: '5. Video hoàn chỉnh', subtitle: 'FFmpeg 60fps xfade' },
        ],

        cp1Form: {
            product_name: initialData.product_profile?.product_name || '',
            width_mm: initialData.product_profile?.width_mm || 100,
            height_mm: initialData.product_profile?.height_mm || 100,
            depth_mm: initialData.product_profile?.depth_mm || 100,
            target_audience: initialData.product_profile?.customer_analysis?.target_audience || '',
            customer_persona: initialData.product_profile?.customer_analysis?.customer_persona || '',
            usp_text: (initialData.product_profile?.customer_analysis?.unique_selling_points || []).join('\n'),
        },

        initPolling() {
            this.pollingTimer = setInterval(() => {
                // Chỉ tự động ghi đè form khi dự án đang ở trạng thái xử lý ngầm (is_processing)
                // hoặc vừa có một phân cảnh đơn lẻ đang được tái tạo (regeneratingSceneId)
                if (this.project.is_processing || this.regeneratingSceneId !== null) {
                    this.fetchLatestStatus();
                }
            }, 2500);
        },

        async fetchLatestStatus() {
            try {
                const res = await fetch(`/api/projects/${this.project.id}/status`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;
                const data = await res.json();

                const previousStatus = this.project.status;
                this.project = data.project;
                this.productProfile = data.product_profile;
                this.generationLogs = data.generation_logs || [];

                // Cập nhật danh sách scenes khi trạng thái chuyển đổi hoặc khi đang chờ kết xuất
                if (previousStatus !== data.project.status || this.project.is_processing || this.regeneratingSceneId !== null) {
                    this.scenes = data.scenes || [];
                    this.regeneratingSceneId = null;
                }

                if (data.product_profile && previousStatus === 'analyzing') {
                    this.cp1Form.product_name = data.product_profile.product_name || '';
                    this.cp1Form.width_mm = data.product_profile.width_mm || 100;
                    this.cp1Form.height_mm = data.product_profile.height_mm || 100;
                    this.cp1Form.depth_mm = data.product_profile.depth_mm || 100;
                    this.cp1Form.target_audience = data.product_profile.customer_analysis?.target_audience || '';
                    this.cp1Form.customer_persona = data.product_profile.customer_analysis?.customer_persona || '';
                    this.cp1Form.usp_text = (data.product_profile.customer_analysis?.unique_selling_points || []).join('\n');
                }
            } catch (e) {
                console.error('Polling status error:', e);
            }
        },

        showToast(msg, type = 'success') {
            this.toastMessage = msg;
            this.toastType = type;
        },

        async apiPost(url, payload = {}) {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(payload),
            });
            const json = await response.json();
            if (!response.ok || json.status === 'error') {
                throw new Error(json.message || 'Yêu cầu thất bại.');
            }
            return json;
        },

        async toggleWorkflowMode() {
            this.isSubmitting = true;
            try {
                const nextMode = this.project.workflow_mode === 'auto' ? 'step_by_step' : 'auto';
                const res = await this.apiPost(`/api/projects/${this.project.id}/toggle-mode`, {
                    workflow_mode: nextMode,
                });
                this.showToast(res.message, 'success');
                await this.fetchLatestStatus();
            } catch (e) {
                this.showToast(e.message, 'error');
            } finally {
                this.isSubmitting = false;
            }
        },

        // CỔNG 1 (CP1): Duyệt Sản phẩm & Mẫu 3D
        async submitApproveProduct() {
            this.isSubmitting = true;
            try {
                const res = await this.apiPost(`/api/projects/${this.project.id}/approve-product`, {
                    product_name: this.cp1Form.product_name,
                    width_mm: this.cp1Form.width_mm,
                    height_mm: this.cp1Form.height_mm,
                    depth_mm: this.cp1Form.depth_mm,
                    customer_analysis: {
                        target_audience: this.cp1Form.target_audience,
                        customer_persona: this.cp1Form.customer_persona,
                        unique_selling_points: this.cp1Form.usp_text.split('\n').map(s => s.trim()).filter(Boolean),
                    }
                });
                this.showToast(res.message, 'success');
                await this.fetchLatestStatus();
            } catch (e) {
                this.showToast(e.message, 'error');
            } finally {
                this.isSubmitting = false;
            }
        },

        // Các thao tác thêm, xóa, kéo thả sắp xếp cảnh ở CỔNG 2 (CP2)
        addNewSceneCard() {
            this.scenes.push({
                id: null,
                scene_order: this.scenes.length + 1,
                narration_text: 'Câu thoại tiếng Việt cho phân cảnh mới...',
                visual_prompt: 'Cinematic high-resolution shot, dramatic lighting, 8k',
                sfx_prompt: 'whoosh',
                camera_motion: 'zoom_in',
                transition_type: 'fade',
                duration_ms: 0,
            });
        },

        removeSceneCard(idx) {
            if (this.scenes.length <= 1) return;
            this.scenes.splice(idx, 1);
        },

        moveScene(idx, direction) {
            const target = idx + direction;
            if (target < 0 || target >= this.scenes.length) return;
            const temp = this.scenes[idx];
            this.scenes[idx] = this.scenes[target];
            this.scenes[target] = temp;
        },

        dropSceneCard(targetIdx) {
            if (this.dragStartIndex === null || this.dragStartIndex === targetIdx) return;
            const moved = this.scenes.splice(this.dragStartIndex, 1)[0];
            this.scenes.splice(targetIdx, 0, moved);
            this.dragStartIndex = null;
        },

        // CỔNG 2 (CP2): Duyệt Kịch bản & Phân cảnh
        async submitApproveScript() {
            this.isSubmitting = true;
            try {
                const res = await this.apiPost(`/api/projects/${this.project.id}/approve-script`, {
                    master_style_prompt: this.project.master_style_prompt,
                    scenes: this.scenes.map((s, i) => ({
                        id: s.id,
                        scene_order: i + 1,
                        narration_text: s.narration_text,
                        visual_prompt: s.visual_prompt,
                        sfx_prompt: s.sfx_prompt,
                        camera_motion: s.camera_motion || 'zoom_in',
                        transition_type: s.transition_type || 'fade',
                    })),
                });
                this.showToast(res.message, 'success');
                await this.fetchLatestStatus();
            } catch (e) {
                this.showToast(e.message, 'error');
            } finally {
                this.isSubmitting = false;
            }
        },

        // CỔNG 3 (CP3): Duyệt Giọng đọc & Âm thanh
        async submitApproveAudio(resynthesizeAll = false) {
            this.isSubmitting = true;
            try {
                const res = await this.apiPost(`/api/projects/${this.project.id}/approve-audio`, {
                    voice_id: this.project.voice_id,
                    resynthesize_all_voices: resynthesizeAll,
                    scenes: this.scenes.map(s => ({
                        id: s.id,
                        sfx_prompt: s.sfx_prompt,
                    })),
                });
                this.showToast(res.message, 'success');
                await this.fetchLatestStatus();
            } catch (e) {
                this.showToast(e.message, 'error');
            } finally {
                this.isSubmitting = false;
            }
        },

        // CỔNG 4 (CP4): Duyệt toàn bộ cảnh video & Bắt đầu kết xuất FFmpeg
        async submitApproveScenes() {
            this.isSubmitting = true;
            try {
                const res = await this.apiPost(`/api/projects/${this.project.id}/approve-scenes`, {
                    scenes: this.scenes.map(s => ({
                        id: s.id,
                        transition_type: s.transition_type || 'fade',
                    })),
                });
                this.showToast(res.message, 'success');
                await this.fetchLatestStatus();
            } catch (e) {
                this.showToast(e.message, 'error');
            } finally {
                this.isSubmitting = false;
            }
        },

        // Tái tạo đơn lẻ 1 phân cảnh bất kỳ (POST /api/scenes/{sceneId}/regenerate)
        async regenerateSingleScene(scene, regenAudio = false, regenVisual = true) {
            if (!scene.id) return;
            this.regeneratingSceneId = scene.id;
            try {
                const res = await this.apiPost(`/api/scenes/${scene.id}/regenerate`, {
                    narration_text: scene.narration_text,
                    visual_prompt: scene.visual_prompt,
                    sfx_prompt: scene.sfx_prompt,
                    camera_motion: scene.camera_motion,
                    transition_type: scene.transition_type,
                    regenerate_audio: regenAudio,
                    regenerate_visual: regenVisual,
                });
                this.showToast(res.message, 'success');
                if (regenAudio && !regenVisual) {
                    await this.fetchLatestStatus();
                }
            } catch (e) {
                this.regeneratingSceneId = null;
                this.showToast(e.message, 'error');
            }
        }
    };
}
</script>
@endpush
@endsection
