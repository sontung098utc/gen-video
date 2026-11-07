<?php $__env->startSection('title', 'Danh Sách Dự Án Video AI — GenVideo Studio'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <!-- Tiêu đề & Nút tạo dự án -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl sm:text-3xl font-bold text-white">Bảng Điều Khiển Dự Án Video AI</h1>
            <p class="text-sm text-slate-400 mt-1">
                Quản lý tiến trình sản xuất video ngắn qua 4 Cổng kiểm duyệt (Step-by-Step) hoặc Tự động hoàn toàn (Full-Auto).
            </p>
        </div>
        <a href="<?php echo e(route('projects.create')); ?>"
           class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-sm shadow-lg shadow-indigo-600/25 transition">
            + Khởi Tạo Dự Án Mới
        </a>
    </div>

    <!-- Thẻ Thống Kê Tổng Quan -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800">
            <span class="text-xs font-medium text-slate-400">Tổng số dự án</span>
            <p class="text-2xl font-display font-bold text-white mt-1"><?php echo e($stats['total_projects']); ?></p>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800">
            <span class="text-xs font-medium text-amber-400">Đang chờ duyệt (Checkpoints 1-4)</span>
            <p class="text-2xl font-display font-bold text-amber-300 mt-1"><?php echo e($stats['waiting_approval']); ?></p>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800">
            <span class="text-xs font-medium text-emerald-400">Video đã hoàn thành</span>
            <p class="text-2xl font-display font-bold text-emerald-300 mt-1"><?php echo e($stats['completed_videos']); ?></p>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900/80 border border-slate-800">
            <span class="text-xs font-medium text-cyan-400">Tổng chi phí API thực tế</span>
            <p class="text-2xl font-mono font-bold text-cyan-300 mt-1">$<?php echo e(number_format($stats['total_spent_usd'], 4)); ?></p>
        </div>
    </div>

    <!-- Danh sách dự án -->
    <?php if($projects->isEmpty()): ?>
        <div class="rounded-2xl bg-slate-900/60 border border-dashed border-slate-800 p-12 text-center">
            <p class="text-base font-semibold text-slate-300">Chưa có dự án video nào được tạo.</p>
            <p class="text-sm text-slate-500 mt-1 mb-6">Hãy bắt đầu với một chủ đề Kiến thức hoặc tải ảnh Sản phẩm để tạo mô hình 3D & Video tự động.</p>
            <a href="<?php echo e(route('projects.create')); ?>"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold">
                Tạo dự án đầu tiên ngay
            </a>
        </div>
    <?php else: ?>
        <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-800 text-[11px] font-semibold uppercase tracking-wider text-slate-400 bg-slate-900">
                            <th class="py-3.5 px-4">ID / Dự án</th>
                            <th class="py-3.5 px-4">Phân loại & Tỷ lệ</th>
                            <th class="py-3.5 px-4">Chế độ duyệt</th>
                            <th class="py-3.5 px-4">Trạng thái hiện tại</th>
                            <th class="py-3.5 px-4">Số cảnh</th>
                            <th class="py-3.5 px-4">Chi phí thực tế</th>
                            <th class="py-3.5 px-4 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70 text-sm">
                        <?php $__currentLoopData = $projects; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $project): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="hover:bg-slate-900/50 transition">
                                <td class="py-4 px-4">
                                    <div class="font-semibold text-white"><?php echo e($project->title); ?></div>
                                    <div class="text-xs text-slate-500 mt-0.5">#<?php echo e($project->id); ?> • <?php echo e($project->created_at?->format('H:i d/m/Y')); ?></div>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium <?php echo e($project->project_type->value === 'product' ? 'bg-purple-500/15 text-purple-300 border border-purple-500/30' : 'bg-sky-500/15 text-sky-300 border border-sky-500/30'); ?>">
                                        <?php echo e($project->project_type->value === 'product' ? 'Sản Phẩm 3D' : 'Kiến Thức'); ?>

                                    </span>
                                    <span class="ml-1.5 text-xs font-mono text-slate-400"><?php echo e($project->aspect_ratio); ?></span>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="text-xs font-medium <?php echo e($project->workflow_mode->value === 'auto' ? 'text-cyan-400' : 'text-slate-300'); ?>">
                                        <?php echo e($project->workflow_mode->label()); ?>

                                    </span>
                                </td>
                                <td class="py-4 px-4">
                                    <?php
                                        $badgeColor = match (true) {
                                            $project->status->isCheckpoint() => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                            $project->status->value === 'completed' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                            $project->status->value === 'failed' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                            default => 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30',
                                        };
                                    ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border <?php echo e($badgeColor); ?>">
                                        <?php echo e($project->status->label()); ?>

                                    </span>
                                </td>
                                <td class="py-4 px-4 font-mono text-xs text-slate-300">
                                    <?php echo e($project->scenes_count); ?> cảnh
                                </td>
                                <td class="py-4 px-4 font-mono text-xs text-emerald-400 font-semibold">
                                    $<?php echo e(number_format($project->total_cost_usd, 4)); ?>

                                </td>
                                <td class="py-4 px-4 text-right">
                                    <a href="<?php echo e(route('projects.studio', ['id' => $project->id])); ?>"
                                       class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-indigo-600 text-white text-xs font-semibold transition">
                                        Mở Studio →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-slate-800">
                <?php echo e($projects->links()); ?>

            </div>
        </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\sontu\OneDrive\Desktop\genvideo\resources\views/projects/index.blade.php ENDPATH**/ ?>