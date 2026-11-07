---
name: ffmpeg-compositor
description: >-
  Hướng dẫn lắp ráp và kết xuất video bằng FFmpeg 7.x trên máy chủ CentOS 8 chia sẻ tài nguyên.
  Bao gồm quy tắc giới hạn CPU (nice -n 10 ffmpeg -threads 6), công thức zoompan 60fps mượt mà cho
  Eco Mode, chuỗi filter_complex xfade chuẩn xác không lệch frame, và trộn âm thanh đa lớp
  (amix + sidechaincompress tự động ducking nhạc nền khi có giọng đọc tiếng Việt).
---

# FFmpeg Compositor Skill (CentOS 8 Resource-Safe Edition)

Skill này quy định các công thức toán học và cú pháp `filter_complex` cho trình kết xuất video FFmpeg 7.x trên máy chủ vật lý CentOS 8 (128GB RAM, 34+ CPU threads, không có GPU rời, chạy chung với LiteSpeed Web Server).

---

## 1. Quy Tắc An Toàn Tài Nguyên Trên CentOS 8 (BẮT BUỘC)

Để tuyệt đối không gây nghẽn tiến trình `lsphp` của LiteSpeed và MySQL trên cùng máy chủ:
1. **Độ ưu tiên CPU thấp**: Luôn bọc lệnh FFmpeg qua `nice -n 10` (hoặc `ionice -c 2 -n 7 nice -n 10`).
2. **Giới hạn luồng xử lý**: Luôn truyền `-threads 6` ở cả cấp độ global decoding và cấp độ encoder `libx264` (`-x264-params threads=6`).
3. **Chuẩn đầu ra thương mại (TikTok / Reels / Shorts)**:
   - Độ phân giải: `1080x1920` (9:16 dọc) hoặc `1920x1080` (16:9 ngang).
   - Frame rate: `60 fps` (`-r 60`), Pixel format: `yuv420p` (`-pix_fmt yuv420p`).
   - Codec: `-c:v libx264 -preset veryfast -crf 21 -movflags +faststart`.

```bash
nice -n 10 /usr/local/bin/ffmpeg -y -threads 6 \
  [INPUTS...] \
  -filter_complex "[FILTER_GRAPH]" \
  -map "[v_final]" -map "[a_final]" \
  -c:v libx264 -preset veryfast -crf 21 -x264-params threads=6 \
  -r 60 -pix_fmt yuv420p -c:a aac -b:a 192k -movflags +faststart \
  output.mp4
```

---

## 2. Công Thức `zoompan` 60fps Mượt Mà Cho Ảnh Tĩnh (Eco Mode)

Trong **Eco Mode**, ảnh tĩnh từ `fal-ai/flux/schnell` được biến thành video chuyển động 60fps.
Để tránh hiện tượng rung giật (jitter/stutter) do làm tròn pixel nguyên của bộ lọc `zoompan`, phải upscale ảnh lên gấp 4 lần chiều rộng (`4320px`) trước khi `zoompan`, sau đó hạ về `1080x1920` và ép `setsar=1,fps=60`.

### Công thức toán học
Giả sử thời lượng cảnh thứ $i$ là $D_i$ giây, tổng số khung hình ở 60fps là $F_i = \lceil D_i \times 60 \rceil$:
- **Zoom In (Phóng vào tâm)**:
  ```text
  scale=4320:-1,zoompan=z='min(zoom+0.0005,1.25)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={FRAMES}:s=1080x1920:fps=60,setsar=1,format=yuv420p
  ```
- **Zoom Out (Thu nhỏ từ tâm)**:
  ```text
  scale=4320:-1,zoompan=z='if(eq(on,1),1.25,max(1.0,zoom-0.0005))':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={FRAMES}:s=1080x1920:fps=60,setsar=1,format=yuv420p
  ```
- **Pan Left-to-Right (Lia máy ngang mượt)**:
  ```text
  scale=4320:-1,zoompan=z='1.15':x='(iw-iw/zoom)*(on/{FRAMES})':y='(ih-ih/zoom)/2':d={FRAMES}:s=1080x1920:fps=60,setsar=1,format=yuv420p
  ```

---

## 3. Tính Toán `xfade` Offset Không Lệch Frame

Khi ghép $N$ cảnh video ($V_0, V_1, \dots, V_{N-1}$) với thời lượng từng cảnh là $D_0, D_1, \dots, D_{N-1}$ (tính bằng giây, chính xác tới mili-giây) và thời lượng hiệu ứng chuyển cảnh là $T_{\text{trans}}$ (ví dụ `0.5` giây):

### Công thức đệ quy tính `offset`
- Mọi luồng video đầu vào của `xfade` **bắt buộc** phải đồng nhất `fps=60,settb=AVTB,setsar=1` trước khi nối.
- Thời lượng tổng cộng sau khi ghép cảnh $k$ ($k \ge 1$) giảm đi $k \times T_{\text{trans}}$:
  $$\text{Offset}_1 = D_0 - T_{\text{trans}}$$
  $$\text{Offset}_k = \text{Offset}_{k-1} + D_k - T_{\text{trans}} = \left(\sum_{j=0}^{k-1} D_j\right) - k \times T_{\text{trans}}$$

### Ví dụ 3 cảnh ($D_0 = 5.2\text{s}, D_1 = 4.8\text{s}, D_2 = 6.0\text{s}, T_{\text{trans}} = 0.5\text{s}$)
- $\text{Offset}_1 = 5.2 - 0.5 = 4.700\text{s}$
- $\text{Offset}_2 = (5.2 + 4.8) - 2 \times 0.5 = 9.000\text{s}$

```text
[0:v]fps=60,settb=AVTB,setsar=1[v0];
[1:v]fps=60,settb=AVTB,setsar=1[v1];
[2:v]fps=60,settb=AVTB,setsar=1[v2];
[v0][v1]xfade=transition=fade:duration=0.500:offset=4.700[vx1];
[vx1][v2]xfade=transition=smoothleft:duration=0.500:offset=9.000[v_out]
```
> **Lưu ý đồng bộ âm thanh**: Để giọng đọc từng cảnh khớp hoàn toàn với hình ảnh sau khi bị rút ngắn bởi `xfade`, mỗi clip video cảnh $i$ (trừ cảnh cuối) cần được cộng bù thêm $T_{\text{trans}}$ giây hình ảnh ở cuối (`tpad=stop_mode=clone:stop_duration=0.5`), hoặc đặt `adelay` của luồng âm thanh cảnh $k$ đúng bằng thời điểm bắt đầu thực tế của cảnh đó trên trục thời gian tổng.

---

## 4. Trộn Âm Thanh Đa Lớp (`amix` + `sidechaincompress` Auto-Ducking)

Hệ thống âm thanh gồm 3 lớp:
1. **Voiceover tiếng Việt (`[a_voice]`)**: Ghép nối tuần tự hoặc định vị theo timestamp, chuẩn hóa âm lượng `volume=1.0`.
2. **Hiệu ứng âm thanh SFX (`[a_sfx]`)**: Lấy từ `storage/app/sfx_library/`, định vị vào thời điểm xuất hiện cảnh bằng `adelay={START_MS}|{START_MS}`, âm lượng `volume=0.35`.
3. **Nhạc nền BGM (`[a_bgm]`)**: Lặp vô tận (`-stream_loop -1`), âm lượng gốc `volume=0.22`, tự động hạ nhỏ (ducking) xuống khi có tiếng đọc tiếng Việt bằng bộ lọc `sidechaincompress`.

### Chuỗi `filter_complex` âm thanh chuẩn
```text
[voice_concat]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,asplit=2[vo_sc][vo_mix];
[bgm_in]aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,volume=0.25[bgm_norm];
[bgm_norm][vo_sc]sidechaincompress=threshold=0.03:ratio=6:attack=50:release=400:makeup=1[bgm_ducked];
[vo_mix][bgm_ducked][sfx_bus]amix=inputs=3:duration=first:dropout_transition=2,alimiter=limit=0.95[a_final]
```

### Đốt phụ đề tiếng Việt (`.vtt` / `.ass`)
Sử dụng `libass` tích hợp sẵn trong `/usr/local/bin/ffmpeg`:
```text
[v_out]subtitles='/path/to/subtitles.vtt':force_style='FontName=DejaVu Sans,FontSize=16,PrimaryColour=&H0000FFFF,OutlineColour=&H00000000,BorderStyle=1,Outline=2,Shadow=1,Alignment=2,MarginV=65'[v_final]
```
