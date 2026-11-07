/**
 * ============================================================================
 * CẤU HÌNH PM2 v7.0.3 QUẢN LÝ LARAVEL 11 QUEUE WORKERS TRÊN CENTOS 8
 * ============================================================================
 * Hạ tầng mục tiêu:
 *   - CentOS 8 (glibc 2.28), 128 GB RAM, 34+ luồng CPU (ksoftirqd/33)
 *   - Web Server: LiteSpeed chạy lsphp82 (/usr/local/lsws/lsphp82/bin/php)
 *   - Redis Queue Driver
 *
 * Kiến trúc 3 nhóm Worker tách biệt để bảo vệ LiteSpeed Web Server:
 *   1. genvideo-ai-orchestration : Gọi Gemini 2.5 Flash, Edge-TTS CLI, Fal.ai REST API
 *   2. genvideo-3d-processing    : Tách nền ảnh bằng rembg (ONNX CPU) & chuẩn hóa .glb bằng trimesh
 *   3. genvideo-ffmpeg-render    : Kết xuất video 60fps bằng nice -n 10 ffmpeg -threads 6
 *
 * Cách khởi chạy trên CentOS 8:
 *   pm2 start ecosystem.config.js
 *   pm2 save
 * ============================================================================
 */

const LSPHP_BINARY = process.env.PHP_BINARY || '/usr/local/lsws/lsphp82/bin/php';

module.exports = {
  apps: [
    // ------------------------------------------------------------------------
    // WORKER 1: AI ORCHESTRATION QUEUE (Gemini 2.5 Flash, Edge-TTS, Fal.ai)
    // Tác vụ chủ yếu là Network I/O -> Chạy 2 instances để tăng thông lượng
    // ------------------------------------------------------------------------
    {
      name: 'genvideo-ai-orchestration',
      script: 'artisan',
      interpreter: LSPHP_BINARY,
      args: 'queue:work redis --queue=ai-orchestration --sleep=2 --tries=3 --backoff=10,30,60 --timeout=300 --max-jobs=250 --max-time=3600',
      instances: 2,
      exec_mode: 'fork',
      autorestart: true,
      watch: false,
      max_memory_restart: '512M',
      env: {
        APP_ENV: 'production',
        QUEUE_CONNECTION: 'redis',
      },
      error_file: './storage/logs/pm2/ai-orchestration-error.log',
      out_file: './storage/logs/pm2/ai-orchestration-out.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    },

    // ------------------------------------------------------------------------
    // WORKER 2: 3D & IMAGE CPU PROCESSING QUEUE (rembg ONNX & trimesh GLB)
    // Giới hạn nghiêm ngặt 6 luồng CPU cho ONNX Runtime / OpenMP để tránh nghẽn
    // ------------------------------------------------------------------------
    {
      name: 'genvideo-3d-processing',
      script: 'artisan',
      interpreter: LSPHP_BINARY,
      args: 'queue:work redis --queue=3d-processing --sleep=3 --tries=2 --backoff=15,45 --timeout=600 --max-jobs=100 --max-time=3600',
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      watch: false,
      max_memory_restart: '2G',
      env: {
        APP_ENV: 'production',
        QUEUE_CONNECTION: 'redis',
        OMP_NUM_THREADS: '6',
        ORT_NUM_THREADS: '6',
        MKL_NUM_THREADS: '6',
        OPENBLAS_NUM_THREADS: '6',
      },
      error_file: './storage/logs/pm2/3d-processing-error.log',
      out_file: './storage/logs/pm2/3d-processing-out.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    },

    // ------------------------------------------------------------------------
    // WORKER 3: FFMPEG RENDER QUEUE (nice -n 10 ffmpeg -threads 6)
    // Chỉ chạy 1 instance duy nhất tại một thời điểm để kiểm soát tải CPU
    // ------------------------------------------------------------------------
    {
      name: 'genvideo-ffmpeg-render',
      script: 'artisan',
      interpreter: LSPHP_BINARY,
      args: 'queue:work redis --queue=ffmpeg-render --sleep=3 --tries=2 --backoff=30 --timeout=1200 --max-jobs=50 --max-time=3600',
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      watch: false,
      max_memory_restart: '2G',
      env: {
        APP_ENV: 'production',
        QUEUE_CONNECTION: 'redis',
        FFMPEG_MAX_THREADS: '6',
        FFMPEG_NICE_PRIORITY: '10',
      },
      error_file: './storage/logs/pm2/ffmpeg-render-error.log',
      out_file: './storage/logs/pm2/ffmpeg-render-out.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
    },
  ],
};
