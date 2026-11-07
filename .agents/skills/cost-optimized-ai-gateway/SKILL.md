---
name: cost-optimized-ai-gateway
description: >-
  Hướng dẫn tích hợp và tối ưu chi phí cho các cổng AI cốt lõi trong hệ thống Tạo Video Ngắn Tự Động:
  Google Gemini 2.5 Flash Free Tier (NLP/Vision), Microsoft Edge Neural TTS CLI (edge-tts tiếng Việt
  kèm word-level timestamps .vtt/.srt), và Fal.ai API dùng 1 FAL_KEY duy nhất cho flux/schnell,
  ltx-video/image-to-video, và trellis 3D.
---

# Cost-Optimized AI Gateway Skill

Skill này quy định chuẩn mực kiến trúc và cách gọi các dịch vụ AI siêu tiết kiệm chi phí (Zero/Low-Cost Strategy) trên hạ tầng máy chủ **CentOS 8 (PHP 8.2 / Python 3.11)**.

---

## 1. Google Gemini 2.5 Flash API (Free Tier - $0.00)

Sử dụng mô hình `gemini-2.5-flash` thông qua REST endpoint chính thức của Google Generative Language API để phân tích chủ đề kiến thức (NLP) và phân tích đa phương thức ảnh sản phẩm (Vision).

### Cấu hình biến môi trường (`.env`)
```dotenv
GEMINI_API_KEY="AIzaSy..."
GEMINI_MODEL="gemini-2.5-flash"
GEMINI_ENDPOINT="https://generativelanguage.googleapis.com/v1beta/models"
```

### Quy chuẩn gọi API với `responseMimeType: application/json`
- Luôn ép kiểu đầu ra JSON (`generationConfig.responseMimeType = "application/json"`) kèm `responseSchema` rõ ràng để tránh lỗi parse Markdown.
- Khi gửi ảnh sản phẩm (Multimodal Vision), đọc file ảnh cục bộ, mã hóa `base64_encode`, và truyền vào phần tử `inline_data` (`mime_type` + `data`).
- Tuân thủ Rate Limit của Free Tier (15 RPM): Sử dụng `retry(3, 4000)` trên Laravel HTTP Client với exponential backoff khi gặp HTTP `429 Too Many Requests`.

```php
$response = Http::timeout(90)
    ->retry(3, 4000)
    ->post("{$endpoint}/{$model}:generateContent?key={$apiKey}", [
        'systemInstruction' => [
            'parts' => [['text' => $systemPrompt]],
        ],
        'contents' => [
            ['role' => 'user', 'parts' => $parts],
        ],
        'generationConfig' => [
            'temperature' => 0.4,
            'responseMimeType' => 'application/json',
        ],
    ]);
```

---

## 2. Microsoft Edge Neural TTS CLI (`edge-tts` - $0.00)

Chạy trực tiếp CLI `edge-tts` trên CentOS 8 để tổng hợp giọng đọc tiếng Việt tự nhiên kèm phụ đề căn chỉnh theo từng cụm từ/từ (word-level boundary timestamps).

### Danh sách giọng đọc chuẩn tiếng Việt
- **Nam**: `vi-VN-NamMinhNeural`
- **Nữ**: `vi-VN-HoaiMyNeural`

### Cú pháp lệnh CLI chuẩn và an toàn
Sử dụng `Symfony\Component\Process\Process` với mảng tham số (tuyệt đối không nối chuỗi shell thủ công để chống Command Injection):

```bash
/usr/local/bin/edge-tts \
  --voice "vi-VN-NamMinhNeural" \
  --rate="+5%" \
  --pitch="+0Hz" \
  --text "Nội dung câu thoại tiếng Việt cần đọc" \
  --write-media "/path/to/storage/app/public/audio/scene_01.mp3" \
  --write-subtitles "/path/to/storage/app/public/subtitles/scene_01.vtt"
```

### Đo thời lượng chính xác bằng `ffprobe`
Sau khi sinh file `.mp3`, bắt buộc đo thời lượng thực tế (`duration_ms`) bằng `ffprobe` để đồng bộ hóa khung hình video và offset chuyển cảnh `xfade`:

```bash
/usr/local/bin/ffprobe -v error \
  -show_entries format=duration \
  -of default=noprint_wrappers=1:nokey=1 \
  "/path/to/storage/app/public/audio/scene_01.mp3"
```
Công thức quy đổi sang mili-giây: `(int) round((float) $seconds * 1000)`.

---

## 3. Cổng Fal.ai Hợp Nhất (Single `FAL_KEY` Architecture)

Sử dụng duy nhất 1 khóa `FAL_KEY` trong `.env` với cơ chế Queue bất đồng bộ (`https://queue.fal.run`) hoặc đồng bộ (`https://fal.run`) cho cả 3 tác vụ Visual & 3D:

### Cấu hình biến môi trường (`.env`)
```dotenv
FAL_KEY="key_id:key_secret"
FAL_BASE_URL="https://fal.run"
FAL_QUEUE_URL="https://queue.fal.run"
```

### Bảng định tuyến Model & Chi phí định mức
| Tác vụ | Endpoint Model | Chi phí ước tính | Tham số chính |
| :--- | :--- | :--- | :--- |
| **Eco Mode Keyframe (2D)** | `fal-ai/flux/schnell` | `$0.003` / ảnh | `prompt`, `image_size` (`portrait_16_9` / `landscape_16_9`), `num_inference_steps: 4` |
| **Full AI Video (I2V)** | `fal-ai/ltx-video/image-to-video` | `$0.020` / clip 5s | `prompt`, `image_url`, `num_frames: 121`, `fps: 24` |
| **Tạo khối 3D (.glb)** | `fal-ai/trellis` | `$0.020` / model | `image_url` (ảnh đã tách nền PNG), `ss_guidance_strength: 7.5`, `mesh_simplify: 0.95` |

### Quy tắc ghi Log Chi Phí (`generation_logs`)
Mọi lời gọi tới Gemini, Edge-TTS và Fal.ai đều phải được ghi nhận vào bảng `generation_logs` gồm:
- `video_project_id`, `storyboard_scene_id` (nếu có)
- `provider` (`gemini`, `edge_tts`, `fal_ai`)
- `model_name` (`gemini-2.5-flash`, `fal-ai/flux/schnell`, v.v.)
- `cost_usd` (`0.0000` cho Gemini/Edge-TTS, hoặc chi phí thực tế cho Fal.ai)
- `latency_ms`, `status`, `request_payload`, `response_payload`
