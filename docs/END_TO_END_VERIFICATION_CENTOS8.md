# Hướng Dẫn Vận Hành PM2, Cronjob Dọn Rác & Nghiệm Thu End-to-End Trên CentOS 8

Tài liệu này hướng dẫn quy trình khởi động dịch vụ, kiểm tra an toàn tài nguyên trên máy chủ **CentOS 8 (128 GB RAM, 34+ luồng CPU, LiteSpeed `lsphp82`, PM2 v7.0.3)** và kịch bản nghiệm thu thực tế (End-to-End Verification) cho cả 2 luồng sản xuất video.

---

## 1. Khởi Động & Kiểm Tra 3 Queue Workers Trên PM2 v7.0.3

### 1.1. Khởi chạy tự động bằng Script
Trên máy chủ CentOS 8, tại thư mục gốc của dự án:

```bash
chmod +x scripts/setup_centos8_env.sh scripts/deploy_and_verify_centos8.sh
sudo bash scripts/setup_centos8_env.sh
sudo bash scripts/deploy_and_verify_centos8.sh
```

### 1.2. Quản lý trực tiếp các Worker bằng PM2
File [ecosystem.config.js](file:///c:/Users/sontu/OneDrive/Desktop/genvideo/ecosystem.config.js) định nghĩa 3 nhóm Worker tách biệt sử dụng `/usr/local/lsws/lsphp82/bin/php`:

| Tên tiến trình PM2 | Hàng đợi Redis (`--queue`) | Số Instance | Giới hạn Tài nguyên CPU / RAM | Nhiệm vụ chính |
| :--- | :--- | :---: | :--- | :--- |
| `genvideo-ai-orchestration` | `ai-orchestration` | `2` | `max_memory: 512M` | Gọi Gemini 2.5 Flash ($0), Edge-TTS CLI ($0), Fal.ai API |
| `genvideo-3d-processing` | `3d-processing` | `1` | `OMP_NUM_THREADS=6`, `ORT_NUM_THREADS=6`, `2G` | Tách nền CPU `rembg` ($0) & chuẩn hóa `.glb` bằng `trimesh` |
| `genvideo-ffmpeg-render` | `ffmpeg-render` | `1` | `nice -n 10`, `FFMPEG_MAX_THREADS=6`, `2G` | Kết xuất video 60fps `zoompan`, `xfade`, `sidechaincompress` |

Các lệnh kiểm tra trạng thái và nhật ký PM2:
```bash
# Kiểm tra trạng thái 3 nhóm Worker
pm2 status

# Xem log thời gian thực của từng Worker
pm2 logs genvideo-ai-orchestration
pm2 logs genvideo-3d-processing
pm2 logs genvideo-ffmpeg-render

# Lưu danh sách tiến trình PM2 để tự khởi động lại khi reboot server
pm2 save
```

---

## 2. Cơ Chế Dọn Rác Tự Động Sau 48 Giờ (`media:cleanup-temp`)

### 2.1. Cách hoạt động của [`CleanupTempMedia`](file:///c:/Users/sontu/OneDrive/Desktop/genvideo/app/Console/Commands/CleanupTempMedia.php)
- **Thư mục quét dọn**: `storage/app/temp/`, `storage/app/temp_render/`, `storage/app/public/renders/`, `storage/app/public/models_3d/raw/`.
- **Điều kiện xóa**: Chỉ xóa các file tạm có thời gian sửa đổi cuối cùng (`mtime`) lớn hơn **48 giờ**.
- **Bảo lưu vĩnh viễn 100%**:
  1. Toàn bộ file mô hình 3D `.glb` đã chuẩn hóa trong `storage/app/public/models_3d/cache/` và cột `normalized_glb_path` của bảng `product_profiles` (để tái sử dụng theo `cache_key` với chi phí **$0.00**).
  2. Toàn bộ file video `.mp4` hoàn chỉnh trong `storage/app/public/videos/` (`final_video_path`).
  3. Toàn bộ kho âm thanh cục bộ `storage/app/sfx_library/`.

### 2.2. Kiểm tra thủ công & Lịch chạy tự động (02:00 sáng hàng ngày)
```bash
# Chạy thử nghiệm (Dry-Run) để xem danh sách các file tạm quá 48 giờ sẽ bị xóa
/usr/local/lsws/lsphp82/bin/php artisan media:cleanup-temp --dry-run

# Chạy dọn dẹp thực tế ngay lập tức
/usr/local/lsws/lsphp82/bin/php artisan media:cleanup-temp --hours=48

# Kiểm tra danh sách lịch trình Laravel Scheduler (đã cấu hình chạy lúc 02:00 sáng)
/usr/local/lsws/lsphp82/bin/php artisan schedule:list
```

---

## 3. Quy Trình Nghiệm Thu Thực Tế Từng Bước (End-to-End Verification)

### Kịch Bản Nghiệm Thu 1: Luồng "Video Kiến Thức" (Knowledge Video — Eco Mode)
1. **Bước Khởi tạo**:
   - Truy cập `/projects/create`, chọn **Tab 1: Video Kiến Thức & Kể Chuyện**.
   - Nhập tiêu đề: *"5 Bí Mật Về Giấc Ngủ Giúp Tăng Gấp Đôi Năng Suất"*.
   - Chọn tỷ lệ `9:16 Dọc (1080×1920)`, Giọng đọc `Nam Minh (vi-VN-NamMinhNeural)`, Chế độ `Eco Mode ($0.003/cảnh)`, Chế độ vận hành `Duyệt từng bước (Step-by-Step)`.
   - Bấm **"Bắt Đầu Khởi Tạo Dự Án"**.
2. **Kiểm tra Cổng Duyệt 2 (CP2 — `waiting_approval_script`)**:
   - Màn hình Studio tự động Polling mỗi 2.5 giây. Sau khoảng 5–10 giây, `Step2_GenerateScriptAndScenesJob` hoàn tất gọi Gemini 2.5 Flash ($0.00) và mở **Cổng Duyệt 2**.
   - Kiểm tra `Master Style Prompt`, thử kéo thả đổi vị trí 2 thẻ bối cảnh hoặc sửa một câu thoại tiếng Việt, sau đó bấm **"Phê Duyệt Kịch Bản & Chuyển Sang Tạo Giọng Đọc"**.
3. **Kiểm tra Cổng Duyệt 3 (CP3 — `waiting_approval_audio`)**:
   - `Step3_GenerateVoiceAndSfxJob` gọi `edge-tts` ($0.00) và `ffprobe` đo chính xác `duration_ms` của từng câu thoại, đồng thời gán file SFX từ `storage/app/sfx_library/`.
   - Bấm Play nghe thử giọng đọc tiếng Việt trên từng thẻ cảnh, sau đó bấm **"Phê Duyệt Âm Thanh & Bắt Đầu Tạo Video AI"**.
4. **Kiểm tra Cổng Duyệt 4 (CP4 — `waiting_approval_scenes`)**:
   - `Step4_GenerateAiSceneVideosJob` gọi `fal-ai/flux/schnell` ($0.003/ảnh) và tạo chuyển động 60fps mượt mà bằng `zoompan` (upscale `4320px`).
   - Xem thử các clip trên lưới Grid. Thử bấm **"Tạo lại Cảnh này (Regenerate)"** ở Cảnh #1 để kiểm chứng tính năng tái tạo đơn lẻ.
   - Bấm **"Phê Duyệt Tất Cả Cảnh & Tiến Hành Ghép Video Hoàn Chỉnh"**.
5. **Kiểm tra Kết xuất Cuối cùng (`COMPLETED`)**:
   - Trong lúc `Step5_RenderFinalVideoJob` đang chạy trên queue `ffmpeg-render`, kiểm tra tiến trình trên server CentOS 8:
     ```bash
     ps -eo pid,ni,comm,args | grep ffmpeg
     ```
     *Tiêu chí đạt*: Tiến trình `ffmpeg` hiển thị cột `NI` (Nice) bằng `10` và có tham số `-threads 6`.
   - Khi trạng thái chuyển sang `COMPLETED`: Phát thử video `.mp4` hoàn chỉnh, kiểm tra phụ đề Karaoke màu vàng `#FFFF00`, nhạc nền tự động hạ nhỏ khi có giọng đọc (`sidechaincompress`), và đối chiếu tổng chi phí thực tế trong bảng **Nhật Ký Kiểm Toán Chi Phí API** (`~$0.015 - $0.021`).

---

### Kịch Bản Nghiệm Thu 2: Luồng "Video Sản Phẩm 3D" (Product Video — `<model-viewer>` & Cache 3D)
1. **Bước Khởi tạo**:
   - Truy cập `/projects/create`, chọn **Tab 2: Video Sản Phẩm 3D (.glb)**.
   - Nhập tên sản phẩm: *"Nước Hoa Nam Bleu Noir 100ml"*, Mã SKU: `SKU-BLEU-100`.
   - Nhập kích thước thực tế: Rộng `65 mm`, Cao `125 mm`, Sâu `38 mm`.
   - Tải lên ảnh chụp sản phẩm và bấm **"Bắt Đầu Khởi Tạo Dự Án"**.
2. **Kiểm tra Cổng Duyệt 1 (CP1 — `waiting_approval_product`)**:
   - `Step1_AnalyzeProductAnd3DJob` chạy trên queue `3d-processing` (với `OMP_NUM_THREADS=6`):
     1. Tách nền ảnh sang PNG RGBA 32-bit bằng `scripts/remove_background.py` ($0.00).
     2. Tạo mô hình `.glb` bằng `fal-ai/trellis` ($0.020).
     3. Chuẩn hóa kích thước vật lý bằng `scripts/normalize_glb_scale.py` về đúng `0.065m × 0.125m × 0.038m` và xuất 4 ảnh góc xoay Turntable (`0°`, `90°`, `180°`, `270°`).
   - Tại giao diện **Cổng Duyệt 1**:
     - Dùng chuột xoay 360° mô hình 3D trên khung `<model-viewer>`, kiểm tra nhãn kích thước `65 × 125 × 38 mm` và 4 ảnh góc xoay Turntable bên dưới.
     - Kiểm tra phần phân tích khách hàng mục tiêu & USP bên phải.
     - Gạt thử công tắc trên góc phải sang **"Tự động hoàn toàn (Full-Auto)"**: Hệ thống sẽ tự động duyệt CP1 và chạy thông suốt qua Bước 2 $\rightarrow$ Bước 3 $\rightarrow$ Bước 4 $\rightarrow$ Bước 5 mà không cần chờ bấm duyệt thủ công.
3. **Kiểm tra Cơ chế Tái sử dụng Cache 3D Vĩnh Viễn ($0.00)**:
   - Tạo thêm một dự án sản phẩm mới với cùng mã `SKU-BLEU-100`, cùng ảnh gốc và kích thước `65 × 125 × 38 mm`.
   - Kiểm tra bảng **Nhật Ký Kiểm Toán Chi Phí API (`generation_logs`)**: Hệ thống ghi nhận `model_name = 3d_cache_hit`, `status = cached`, `cost_usd = $0.0000` và hoàn tất Bước 1 ngay lập tức.
