<!DOCTYPE html>
<html lang="vi" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title><?php echo $__env->yieldContent('title', 'AI Short Video Studio — Hệ Thống Tạo Video Ngắn Tự Động'); ?></title>

    <!-- Google Fonts: Inter & Montserrat -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">

    <!-- TailwindCSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Montserrat', 'sans-serif'],
                    },
                }
            }
        };
    </script>

    <!-- Alpine.js v3 -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

    <!-- Google <model-viewer> v3.5 cho Khung 3D Tương Tác Cổng Kiểm Duyệt 1 -->
    <script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        .grid-floor-overlay {
            background-image:
                linear-gradient(to right, rgba(56, 189, 248, 0.12) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(56, 189, 248, 0.12) 1px, transparent 1px);
            background-size: 28px 28px;
        }
    </style>
    <?php echo $__env->yieldPushContent('styles'); ?>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col antialiased selection:bg-indigo-500 selection:text-white">

    <!-- Thanh Điều Hướng Studio -->
    <header class="sticky top-0 z-40 border-b border-slate-800/80 bg-slate-950/90 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-6">
                <a href="<?php echo e(route('projects.index')); ?>" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 via-purple-500 to-cyan-400 flex items-center justify-center shadow-lg shadow-indigo-500/20">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <span class="font-display font-bold text-lg tracking-tight text-white group-hover:text-indigo-400 transition">
                            GenVideo <span class="text-indigo-400">AI Studio</span>
                        </span>
                        <span class="block text-[11px] text-slate-400">CentOS 8 • Gemini 2.5 Flash • Edge-TTS • Fal.ai • FFmpeg 60fps</span>
                    </div>
                </a>

                <nav class="hidden md:flex items-center gap-1 ml-4">
                    <a href="<?php echo e(route('projects.index')); ?>"
                       class="px-3.5 py-2 rounded-lg text-sm font-medium transition <?php echo e(request()->routeIs('projects.index') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-900'); ?>">
                        Danh sách dự án
                    </a>
                    <a href="<?php echo e(route('projects.create')); ?>"
                       class="px-3.5 py-2 rounded-lg text-sm font-medium transition <?php echo e(request()->routeIs('projects.create') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-900'); ?>">
                        + Tạo dự án mới
                    </a>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <span class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    PM2 Workers: Sẵn sàng (threads=6)
                </span>
                <a href="<?php echo e(route('projects.create')); ?>"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-600/25 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Tạo Video Mới
                </a>
            </div>
        </div>
    </header>

    <!-- Thông báo Flash Session -->
    <?php if(session('success')): ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm flex items-center justify-between">
                <span><?php echo e(session('success')); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if($errors->any()): ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm">
                <p class="font-semibold mb-1">Vui lòng kiểm tra lại dữ liệu đầu vào:</p>
                <ul class="list-disc list-inside space-y-0.5">
                    <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- Nội Dung Chính -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php echo $__env->yieldContent('content'); ?>
    </main>

    <!-- Chân Trang -->
    <footer class="border-t border-slate-900 py-5 text-center text-xs text-slate-500">
        Hệ Thống Tạo Video Ngắn Tự Động Bằng AI • Tối Ưu Siêu Tiết Kiệm Trên Hạ Tầng CentOS 8 (LiteSpeed PHP 8.2 & PM2 Queue)
    </footer>

    <?php echo $__env->yieldPushContent('scripts'); ?>
</body>
</html>
<?php /**PATH C:\Users\sontu\OneDrive\Desktop\genvideo\resources\views/layouts/app.blade.php ENDPATH**/ ?>