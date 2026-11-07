---
name: glb-scale-normalizer
description: >-
  Hướng dẫn xử lý tài nguyên sản phẩm 3D và hình ảnh trên CPU CentOS 8: tách nền ảnh sản phẩm
  bằng thư viện Python rembg (ONNX Runtime CPU), chuẩn hóa kích thước thực tế của mô hình 3D (.glb)
  từ mm/cm sang chuẩn mét (1 unit = 1m) bằng trimesh, cache vĩnh viễn theo mã sản phẩm, và hiển thị
  thước đo 3D trực quan bằng thẻ Google <model-viewer>.
---

# GLB Scale Normalizer & CPU Background Removal Skill

Skill này quy định quy trình xử lý hình ảnh sản phẩm và chuẩn hóa mô hình 3D `.glb` trên máy chủ CentOS 8 (34+ luồng CPU, không dùng GPU) phục vụ chế độ **Product Video**.

---

## 1. Tách Nền Ảnh Sản Phẩm Trên CPU Bằng `rembg` ($0.00)

Trước khi đưa ảnh sản phẩm vào `fal-ai/trellis` hoặc ghép vào bối cảnh, ảnh gốc cần được tách nền sạch sẽ thành PNG прозрачный (RGBA).

### Giới hạn luồng ONNX Runtime trên CentOS 8
Mặc định `onnxruntime` có thể chiếm toàn bộ 34 luồng CPU. Phải giới hạn `OMP_NUM_THREADS=6` và `ORT_NUM_THREADS=6` để bảo vệ LiteSpeed:

```python
#!/usr/bin/env python3
import os
os.environ["OMP_NUM_THREADS"] = "6"
os.environ["ORT_NUM_THREADS"] = "6"

from rembg import remove, new_session
from PIL import Image

def strip_background(input_path: str, output_path: str) -> None:
    session = new_session("u2net")
    with Image.open(input_path) as img:
        result = remove(img, session=session, alpha_matting=True)
        result.save(output_path, format="PNG")
```

---

## 2. Chuẩn Hóa Kích Thước Thực Tế File `.glb` Bằng `trimesh`

Các mô hình 3D sinh ra từ AI (`fal-ai/trellis` hoặc `tripo3d/h3.1`) luôn bị chuẩn hóa ngẫu nhiên trong hộp đơn vị $[-0.5, 0.5]$ hoặc $[-1, 1]$ mà không biết kích thước vật lý thực của sản phẩm (ví dụ: chai nước hoa cao `120 mm` khác hoàn toàn tủ lạnh cao `1800 mm`).

### Quy chuẩn hệ tọa độ đích (glTF 2.0 / `<model-viewer>`)
- **Đơn vị chuẩn**: `1 unit = 1.0 mét (m)` = `1000 mm`.
- **Tâm mô hình**: Đáy sản phẩm đặt tại mặt phẳng $Y = 0$, tâm theo trục $X = 0, Z = 0$.
- **Ma trận Scale**:
  - Người dùng nhập kích thước thực tế: `width_mm` (trục $X$), `height_mm` (trục $Y$), `depth_mm` (trục $Z$).
  - Đổi sang mét:
    $$W_m = \frac{\text{width\_mm}}{1000.0}, \quad H_m = \frac{\text{height\_mm}}{1000.0}, \quad D_m = \frac{\text{depth\_mm}}{1000.0}$$

### Script Python chuẩn hóa Bounding Box (`trimesh`)
```python
#!/usr/bin/env python3
import sys
import json
import numpy as np
import trimesh

def normalize_glb_scale(
    input_glb: str,
    output_glb: str,
    width_mm: float,
    height_mm: float,
    depth_mm: float,
    uniform_scale: bool = False
) -> dict:
    # Tải Scene hoặc Mesh từ file .glb
    scene_or_mesh = trimesh.load(input_glb, force='scene')

    # Đọc Bounding Box hiện tại (extents = [dx, dy, dz])
    extents = scene_or_mesh.extents
    if extents is None or np.any(extents <= 1e-8):
        raise ValueError("File GLB không có hình học hợp lệ hoặc kích thước bằng 0.")

    target_meters = np.array([
        width_mm / 1000.0,
        height_mm / 1000.0,
        depth_mm / 1000.0
    ], dtype=np.float64)

    # Dịch chuyển tâm mô hình về gốc tọa độ (0, 0, 0) trước khi scale
    centroid = scene_or_mesh.bounds.mean(axis=0)
    translate_to_origin = trimesh.transformations.translation_matrix(-centroid)
    scene_or_mesh.apply_transform(translate_to_origin)

    # Tính hệ số Scale theo từng trục hoặc đồng nhất theo chiều cao (Y)
    if uniform_scale:
        scale_factor = target_meters[1] / extents[1]
        scale_matrix = np.diag([scale_factor, scale_factor, scale_factor, 1.0])
    else:
        sx, sy, sz = target_meters / extents
        scale_matrix = np.diag([sx, sy, sz, 1.0])

    scene_or_mesh.apply_transform(scale_matrix)

    # Đặt đáy sản phẩm nằm trên mặt phẳng Y = 0
    min_bounds, max_bounds = scene_or_mesh.bounds
    place_on_floor = trimesh.transformations.translation_matrix([
        -(min_bounds[0] + max_bounds[0]) / 2.0,
        -min_bounds[1],
        -(min_bounds[2] + max_bounds[2]) / 2.0
    ])
    scene_or_mesh.apply_transform(place_on_floor)

    # Xuất file .glb đã chuẩn hóa
    scene_or_mesh.export(output_glb, file_type='glb')

    final_extents = scene_or_mesh.extents
    return {
        "status": "success",
        "original_extents_unit": extents.tolist(),
        "normalized_extents_meters": final_extents.tolist(),
        "normalized_extents_mm": (final_extents * 1000.0).round(2).tolist(),
        "output_glb": output_glb
    }
```

### Cơ chế Cache Vĩnh Viễn Theo Mã Sản Phẩm (`cache_key`)
- Mỗi sản phẩm có `cache_key = sha256(sku . '|' . md5_file(original_image) . '|' . width_mm . 'x' . height_mm . 'x' . depth_mm)`.
- Trước khi gọi `fal-ai/trellis` ($0.02), kiểm tra bảng `product_profiles` xem `cache_key` đã có `normalized_glb_path` tồn tại trên ổ đĩa chưa. Nếu đã có, tái sử dụng ngay lập tức với chi phí **$0.00**.

---

## 3. Tích Hợp Thẻ Google `<model-viewer>` Hiển Thị Thước Đo Thực Tế (Web UI)

Ở **Cổng kiểm duyệt số 1 (Checkpoint 1 - Duyệt hồ sơ sản phẩm & Mô hình 3D)**, hiển thị mô hình `.glb` kèm kích thước thực tế (`mm` / `cm`) ngay trên trình duyệt bằng `<model-viewer>`:

```html
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>

<model-viewer
  id="product-3d-preview"
  src="{{ asset('storage/' . $productProfile->normalized_glb_path) }}"
  camera-controls
  auto-rotate
  ar
  shadow-intensity="1"
  style="width: 100%; height: 480px; background-color: #0f172a; border-radius: 12px;">
  
  <!-- Các điểm neo (Hotspots) hiển thị kích thước thực tế -->
  <button slot="hotspot-width" class="dim-label" data-position="0 0.05 0" data-normal="0 1 0">
    Rộng: {{ $productProfile->width_mm }} mm
  </button>
  <button slot="hotspot-height" class="dim-label" data-position="0 {{ $productProfile->height_mm / 2000 }} 0" data-normal="1 0 0">
    Cao: {{ $productProfile->height_mm }} mm
  </button>
  <button slot="hotspot-depth" class="dim-label" data-position="0 0.05 0" data-normal="0 0 1">
    Sâu: {{ $productProfile->depth_mm }} mm
  </button>
</model-viewer>
```
