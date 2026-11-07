# Hệ Thống Tạo Video Ngắn Tự Động Bằng AI (AI Short Video Generator)

Hệ thống sản xuất Video Ngắn AI siêu tiết kiệm chi phí (**Zero/Low-Cost AI Strategy**) được thiết kế chuyên biệt cho hạ tầng **CentOS 8 (glibc 2.28, LiteSpeed `lsphp82`, MySQL, Redis, PM2 v7.0.3, 128 GB RAM, 34+ CPU Threads)**.

---

## Kiến Trúc & Chiến Lược Tối Ưu Chi Phí Cốt Lõi

| Thành phần | Công nghệ / Model sử dụng | Chi phí |
| :--- | :--- | :--- |
| **Phân tích Chủ đề & Vision Sản phẩm** | Google Gemini 2.5 Flash API (Free Tier) | **$0.000** |
| **Giọng đọc Tiếng Việt & Phụ đề VTT** | Microsoft Edge Neural TTS CLI (`vi-VN-NamMinhNeural` & `vi-VN-HoaiMyNeural`) | **$0.000** |
| **Tách nền ảnh Sản phẩm (PNG RGBA)** | Python `rembg` (ONNX Runtime CPU, giới hạn `OMP_NUM_THREADS=6`) | **$0.000** |
| **Kho Hiệu ứng Âm thanh (SFX & BGM)** | Kho Local SFX/BGM tại `storage/app/sfx_library/` | **$0.000** |
| **Tạo khối 3D Sản phẩm (`.glb`)** | `fal-ai/trellis` + Chuẩn hóa kích thước thực tế (`mm` $\rightarrow$ `1m`) bằng `trimesh` + **Cache SHA-256 vĩnh viễn** | **$0.020** *(Lần đầu)* / **$0.000** *(Cache)* |
| **Hình ảnh / Chuyển động (Eco Mode)** | `fal-ai/flux/schnell` + FFmpeg 60fps `zoompan` (upscale `4320px`) | **$0.003 / cảnh** |
| **Video AI (Full AI Video Mode)** | `fal-ai/ltx-video/image-to-video` từ ảnh Keyframe / 4 góc xoay 3D Turntable | **$0.020 / clip 5s** |
| **Lắp ráp & Kết xuất Video 60fps** | `nice -n 10 ffmpeg -threads 6` (`xfade` + `sidechaincompress` Audio Ducking + `.ass` Karaoke) | **$0.000** |

---

## Triển Khai Nhanh Trên Máy Chủ CentOS 8

```bash
# 1. Cài đặt môi trường CentOS 8 (FFmpeg 7.x Static, Python 3.11, edge-tts, rembg, trimesh)
chmod +x scripts/setup_centos8_env.sh scripts/deploy_and_verify_centos8.sh
sudo bash scripts/setup_centos8_env.sh

# 2. Cài đặt phụ thuộc Composer & Cấu hình .env
cp .env.example .env
composer install --no-dev --optimize-autoloader
/usr/local/lsws/lsphp82/bin/php artisan key:generate
/usr/local/lsws/lsphp82/bin/php artisan migrate --force

# 3. Khởi động 3 Queue Workers trên PM2 v7.0.3 & Đăng ký Cronjob dọn rác tự động
sudo bash scripts/deploy_and_verify_centos8.sh
```

Xem chi tiết quy trình nghiệm thu từng bước tại: [docs/END_TO_END_VERIFICATION_CENTOS8.md](docs/END_TO_END_VERIFICATION_CENTOS8.md).
