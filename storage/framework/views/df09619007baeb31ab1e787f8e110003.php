
<div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden shadow-xl flex flex-col">
    <!-- Thanh tiêu đề khung 3D -->
    <div class="px-5 py-3.5 border-b border-slate-800 flex items-center justify-between bg-slate-900">
        <div class="flex items-center gap-2.5">
            <span class="w-2.5 h-2.5 rounded-full bg-cyan-400"></span>
            <h3 class="text-sm font-semibold text-white">
                Khung Xem Mẫu 3D Chuẩn Tỷ Lệ Thực Tế (<code class="text-cyan-400 text-xs">&lt;model-viewer&gt;</code>)
            </h3>
        </div>
        <span class="px-2.5 py-1 rounded-md text-[11px] font-mono bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">
            glTF 2.0 • 1 unit = 1.00m
        </span>
    </div>

    <!-- Vùng tương tác 3D + Lưới tọa độ tỷ lệ thực -->
    <div class="relative h-[430px] w-full bg-slate-950 grid-floor-overlay flex items-center justify-center">
        <!-- Bảng thông số Kích thước thực tế (Rộng x Cao x Sâu mm) phủ lên góc trái trên -->
        <div class="absolute top-3.5 left-3.5 z-20 bg-slate-900/90 backdrop-blur-md border border-slate-700/80 rounded-xl p-3 shadow-lg space-y-1.5">
            <div class="text-[11px] font-semibold uppercase tracking-wider text-cyan-400 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/>
                </svg>
                Kích Thước Vật Lý Thực Tế
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs">
                <div class="px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-800">
                    <span class="block text-[10px] text-slate-400">Rộng (X)</span>
                    <span class="font-mono font-bold text-white" x-text="(productProfile?.width_mm || 0) + ' mm'"></span>
                </div>
                <div class="px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-800">
                    <span class="block text-[10px] text-slate-400">Cao (Y)</span>
                    <span class="font-mono font-bold text-emerald-400" x-text="(productProfile?.height_mm || 0) + ' mm'"></span>
                </div>
                <div class="px-2.5 py-1.5 rounded-lg bg-slate-950 border border-slate-800">
                    <span class="block text-[10px] text-slate-400">Sâu (Z)</span>
                    <span class="font-mono font-bold text-amber-400" x-text="(productProfile?.depth_mm || 0) + ' mm'"></span>
                </div>
            </div>
            <div class="text-[11px] text-slate-400 font-mono">
                Quy đổi mét:
                <span class="text-slate-200"
                      x-text="((productProfile?.width_mm || 0)/1000).toFixed(3) + 'm × ' + ((productProfile?.height_mm || 0)/1000).toFixed(3) + 'm × ' + ((productProfile?.depth_mm || 0)/1000).toFixed(3) + 'm'">
                </span>
            </div>
        </div>

        <!-- Chỉ dẫn thao tác chuột góc phải trên -->
        <div class="absolute top-3.5 right-3.5 z-20 hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-900/80 border border-slate-800 text-[11px] text-slate-300">
            <span>Chuột trái: Xoay 360°</span>
            <span>•</span>
            <span>Con lăn: Phóng to/Thu nhỏ</span>
        </div>

        <!-- Thẻ Google <model-viewer> hiển thị file .glb đã chuẩn hóa -->
        <template x-if="productProfile && productProfile.normalized_glb_url">
            <model-viewer
                id="studio-glb-viewer"
                :src="productProfile.normalized_glb_url"
                :poster="productProfile.nobg_image_url || ''"
                alt="Mô hình 3D sản phẩm đã chuẩn hóa kích thước thực tế"
                camera-controls
                auto-rotate
                rotation-per-second="25deg"
                shadow-intensity="1.2"
                exposure="1.05"
                environment-image="neutral"
                style="width: 100%; height: 100%; background-color: transparent;">

                <!-- Nhãn thước đo thực tế đính kèm trên mô hình 3D -->
                <button slot="hotspot-dim-height"
                        data-position="0 0.08 0"
                        data-normal="0 1 0"
                        class="px-2.5 py-1 rounded-full bg-indigo-600/90 text-white text-[11px] font-mono font-semibold shadow-lg border border-indigo-400/50 pointer-events-none">
                    <span x-text="(productProfile.width_mm || 0) + '×' + (productProfile.height_mm || 0) + '×' + (productProfile.depth_mm || 0) + ' mm'"></span>
                </button>
            </model-viewer>
        </template>

        <!-- Trạng thái chờ khi chưa có file .glb -->
        <template x-if="!productProfile || !productProfile.normalized_glb_url">
            <div class="text-center p-6">
                <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center mx-auto mb-3 text-cyan-400">
                    <svg class="w-6 h-6 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                </div>
                <p class="text-sm font-medium text-slate-300">Đang dựng khối 3D (.glb) & chuẩn hóa tỷ lệ bằng Python trimesh...</p>
                <p class="text-xs text-slate-500 mt-1">Ảnh tách nền PNG RGBA và mô hình 3D sẽ tự động hiển thị ngay khi hoàn tất.</p>
            </div>
        </template>

        <!-- Thanh lưới tọa độ đáy khung 3D -->
        <div class="absolute bottom-3 left-3.5 right-3.5 flex items-center justify-between text-[11px] font-mono text-slate-400 pointer-events-none">
            <span>Tâm đáy: (X=0.00, Y_min=0.00, Z=0.00)</span>
            <span x-show="productProfile?.cache_key" x-text="'Cache SHA256: ' + (productProfile?.cache_key || '').substring(0, 14) + '...'"></span>
        </div>
    </div>

    <!-- Bộ 4 ảnh góc xoay Turntable (0°, 90°, 180°, 270°) do script Python trích xuất -->
    <div class="p-4 border-t border-slate-800 bg-slate-900/60">
        <div class="flex items-center justify-between mb-2.5">
            <span class="text-xs font-semibold text-slate-300">
                Ảnh Snapshot 4 Góc Xoay Turntable (0° • 90° • 180° • 270°) — Dùng làm Keyframe AI Video
            </span>
            <template x-if="productProfile?.nobg_image_url">
                <a :href="productProfile.nobg_image_url" target="_blank" class="text-xs text-indigo-400 hover:underline">
                    Xem ảnh tách nền PNG RGBA
                </a>
            </template>
        </div>

        <div class="grid grid-cols-4 gap-3">
            <template x-if="productProfile?.turntable_snapshots && productProfile.turntable_snapshots.length > 0">
                <template x-for="snap in productProfile.turntable_snapshots" :key="snap.angle">
                    <a :href="snap.url" target="_blank"
                       class="group relative aspect-square rounded-xl overflow-hidden bg-slate-950 border border-slate-800 hover:border-cyan-400/60 transition">
                        <img :src="snap.url" :alt="'Góc ' + snap.angle + '°'" class="w-full h-full object-contain p-1.5 group-hover:scale-105 transition">
                        <span class="absolute bottom-1.5 right-1.5 px-2 py-0.5 rounded bg-slate-900/90 text-[10px] font-mono text-cyan-300 border border-slate-700"
                              x-text="snap.angle + '°'"></span>
                    </a>
                </template>
            </template>

            <template x-if="!productProfile?.turntable_snapshots || productProfile.turntable_snapshots.length === 0">
                <template x-for="angle in [0, 90, 180, 270]" :key="angle">
                    <div class="aspect-square rounded-xl bg-slate-950 border border-dashed border-slate-800 flex flex-col items-center justify-center text-slate-600 text-xs font-mono">
                        <span x-text="angle + '°'"></span>
                        <span class="text-[10px] mt-0.5">Đang chờ</span>
                    </div>
                </template>
            </template>
        </div>
    </div>
</div>
<?php /**PATH C:\Users\sontu\OneDrive\Desktop\genvideo\resources\views/components/model-3d-viewer.blade.php ENDPATH**/ ?>