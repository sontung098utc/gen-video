# Tiêu Chuẩn Kỹ Thuật & Sản Xuất Video (Agent AI 8 Gatekeeper)
Mọi video trước khi duyệt xuất bản PHẢI vượt qua 100% các tiêu chuẩn sau:

## 1. Âm Thanh & Giọng Đọc (Agent AI 5)
- **Đúng 1 giọng đọc duy nhất từ đầu đến cuối video**: Tất cả các phân cảnh phải dùng chung `voice_provider`, `voice_id` và `voice_speed`.
- Âm lượng giọng đọc rõ ràng, không méo tiếng, không bị nhạc nền lấn át (nhạc nền tự động hạ âm lượng khi có lời thoại).
- Không có bất kỳ phân cảnh nào bị thiếu file âm thanh hoặc thời lượng = 0.

## 2. Hình Ảnh & Độ Khớp Lời Thoại Từng Phân Cảnh (Agent AI 4 — PASS / FAIL)
- **Kiểm duyệt từng cảnh (PASS / FAIL)**: Mọi phân cảnh phải minh họa ĐÚNG hành động, đúng bộ phận cơ thể và đúng ngữ cảnh lời thoại:
  - Khát nước → Người uống nước (không dùng cảnh chung chung).
  - Tiểu đêm → Người thức dậy ban đêm, bật đèn/đi vệ sinh (không dùng cảnh phòng ngủ trống kéo dài).
  - Tê bì chân tay → Cận cảnh xoa bàn chân, ngón chân hoặc bàn tay (không dùng cảnh du lịch/ngồi thuyền).
  - Kiểm tra đường huyết → Máy đo đường huyết, que thử, lấy mẫu máu xét nghiệm (không dùng cảnh đeo ống nghe chung chung).
- **Không lặp cảnh / không lệch vùng miền**: Không dùng liên tiếp 2 cảnh có bố cục hoặc hành động giống nhau; loại bỏ 100% người châu Âu, châu Phi.
- Định dạng dọc chuẩn 9:16 (1080x1920), ưu tiên chuyển động thực tế, sắc nét.

## 3. Hiệu Ứng Chuyển Cảnh & Hiệu Ứng Âm Thanh CapCut (Agent AI 7)
- Chuyển cảnh (`fade`, `smoothleft`, `wipeup`, `dissolve`...) mượt mà, đúng nhịp chuyển ý, không giật cục.
- Hiệu ứng âm thanh (SFX: `whoosh`, `pop`, `ding`, `chime`) đúng lúc, đúng chỗ — không lạm dụng gây ồn.

## 4. Ảnh Bìa Photographic (Agent AI 6)
- Hình nền dạng ảnh chụp thực tế (photographic) đẹp, sáng rõ, tạo thiện cảm về sức khỏe.
- Chữ tiêu đề trên ảnh bìa phải **chuẩn chính tả tiếng Việt 100%**, không lỗi dấu, không dính mã kỹ thuật (`@{...}`, `[...]`), tương phản tốt, dễ đọc trên điện thoại.

## 5. Phụ Đề Video (Agent AI 7)
- Nếu người dùng bật phụ đề: Phụ đề phải **khớp chính xác với âm thanh và kịch bản**, không sai chính tả, không sót tag `@{...}`, mỗi cụm tối đa 4–5 từ (1–2 dòng gọn gàng trên màn hình dọc).
