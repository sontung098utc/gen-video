#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script chuẩn hóa kích thước vật lý thực tế của mô hình 3D (.glb) bằng `trimesh` & `numpy`,
đồng thời kết xuất 4 ảnh góc nhìn (Turntable 0°, 90°, 180°, 270°) trên CPU CentOS 8.

Quy chuẩn:
  - Chuyển đổi từ milimét (width_mm, height_mm, depth_mm) sang mét (1 unit = 1m theo chuẩn glTF 2.0).
  - Áp dụng ma trận Scale độc lập trên 3 trục X, Y, Z.
  - Đưa tâm đáy mô hình về gốc tọa độ (0, 0, 0) (X=0, Y_min=0, Z=0).
  - Xuất 4 ảnh PNG góc xoay Turntable (0°, 90°, 180°, 270°) phục vụ Keyframe AI Video và Web Preview.
"""

import os
import sys

# Giới hạn tối đa 6 luồng CPU để bảo vệ LiteSpeed trên CentOS 8
os.environ["OMP_NUM_THREADS"] = os.environ.get("OMP_NUM_THREADS", "6")
os.environ["ORT_NUM_THREADS"] = os.environ.get("ORT_NUM_THREADS", "6")
os.environ["OPENBLAS_NUM_THREADS"] = os.environ.get("OPENBLAS_NUM_THREADS", "6")
os.environ["MKL_NUM_THREADS"] = os.environ.get("MKL_NUM_THREADS", "6")

import argparse
import io
import json
import math
from pathlib import Path
from typing import List

import numpy as np
import trimesh
from PIL import Image, ImageDraw


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Chuẩn hóa kích thước thực tế file .glb (1 unit = 1m) và chụp 4 ảnh Turntable."
    )
    parser.add_argument("--input", required=True, type=str, help="Đường dẫn file .glb thô từ Fal Trellis.")
    parser.add_argument("--output", required=True, type=str, help="Đường dẫn lưu file .glb đã chuẩn hóa.")
    parser.add_argument("--width-mm", required=True, type=float, help="Chiều rộng thực tế (mm - trục X).")
    parser.add_argument("--height-mm", required=True, type=float, help="Chiều cao thực tế (mm - trục Y).")
    parser.add_argument("--depth-mm", required=True, type=float, help="Chiều sâu thực tế (mm - trục Z).")
    parser.add_argument(
        "--render-turntable-dir",
        required=False,
        default="",
        type=str,
        help="Thư mục lưu 4 ảnh snapshot góc xoay Turntable (0°, 90°, 180°, 270°).",
    )
    return parser.parse_args()


def _software_render_turntable_png(
    mesh: trimesh.Trimesh,
    angle_deg: int,
    output_png_path: Path,
    canvas_size: int = 1024,
) -> None:
    """
    Bộ kết xuất phần mềm (Pure-CPU Software Rasterizer sử dụng Painter's Algorithm + Lambertian Shading)
    dành cho máy chủ vật lý CentOS 8 không có GPU rời và không có X11 Display.
    """
    rad = math.radians(angle_deg)
    cos_a, sin_a = math.cos(rad), math.sin(rad)

    # Ma trận xoay quanh trục Y (góc turntable) kết hợp góc nhìn nghiêng nhẹ 15 độ từ trên xuống
    rot_y = np.array([
        [cos_a, 0.0, sin_a],
        [0.0, 1.0, 0.0],
        [-sin_a, 0.0, cos_a],
    ], dtype=np.float64)

    pitch = math.radians(15.0)
    cos_p, sin_p = math.cos(pitch), math.sin(pitch)
    rot_x = np.array([
        [1.0, 0.0, 0.0],
        [0.0, cos_p, -sin_p],
        [0.0, sin_p, cos_p],
    ], dtype=np.float64)

    rotation = rot_x @ rot_y

    vertices = np.asarray(mesh.vertices, dtype=np.float64)
    faces = np.asarray(mesh.faces, dtype=np.int64)

    # Giảm số lượng mặt tam giác nếu mesh quá dày để tăng tốc render trên CPU
    max_faces = 18000
    if len(faces) > max_faces:
        step = max(1, len(faces) // max_faces)
        faces = faces[::step]

    # Đưa tâm hình học về (0, 0, 0) khi chiếu lên khung hình
    center = vertices.mean(axis=0)
    verts_centered = (vertices - center) @ rotation.T

    max_span = float(np.ptp(verts_centered, axis=0).max())
    if max_span <= 1e-8:
        max_span = 1.0

    scale = (canvas_size * 0.72) / max_span
    proj_x = verts_centered[:, 0] * scale + (canvas_size / 2.0)
    proj_y = (canvas_size / 2.0) - verts_centered[:, 1] * scale
    depth_z = verts_centered[:, 2]

    # Sắp xếp các mặt tam giác từ xa tới gần (Painter's Algorithm)
    face_depths = depth_z[faces].mean(axis=1)
    sorted_indices = np.argsort(face_depths)
    sorted_faces = faces[sorted_indices]

    # Tính pháp tuyến và độ sáng Lambertian
    v0 = verts_centered[sorted_faces[:, 0]]
    v1 = verts_centered[sorted_faces[:, 1]]
    v2 = verts_centered[sorted_faces[:, 2]]
    normals = np.cross(v1 - v0, v2 - v0)
    norms = np.linalg.norm(normals, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    normals = normals / norms

    light_dir = np.array([0.35, 0.65, 0.67], dtype=np.float64)
    light_dir /= np.linalg.norm(light_dir)
    intensities = np.clip(np.dot(normals, light_dir), 0.18, 1.0)

    # Lấy màu sắc đỉnh/mặt nếu có từ UV/Visual, nếu không dùng tông màu studio xanh-bạc hiện đại
    base_rgb = np.array([215.0, 225.0, 240.0], dtype=np.float64)
    try:
        if hasattr(mesh.visual, "face_colors") and mesh.visual.face_colors is not None:
            fc = np.asarray(mesh.visual.face_colors)[sorted_indices, :3].astype(np.float64)
            if fc.shape[0] == len(sorted_faces):
                base_rgb = fc
    except Exception:
        pass

    img = Image.new("RGBA", (canvas_size, canvas_size), (15, 23, 42, 0))
    draw = ImageDraw.Draw(img)

    for idx, face in enumerate(sorted_faces):
        pts = [
            (float(proj_x[face[0]]), float(proj_y[face[0]])),
            (float(proj_x[face[1]]), float(proj_y[face[1]])),
            (float(proj_x[face[2]]), float(proj_y[face[2]])),
        ]
        shade = float(intensities[idx])
        if base_rgb.ndim == 2:
            rgb = base_rgb[idx] * shade
        else:
            rgb = base_rgb * shade
        color = (int(np.clip(rgb[0], 0, 255)), int(np.clip(rgb[1], 0, 255)), int(np.clip(rgb[2], 0, 255)), 255)
        draw.polygon(pts, fill=color)

    output_png_path.parent.mkdir(parents=True, exist_ok=True)
    img.save(output_png_path, format="PNG")


def render_turntable_snapshots(scene: trimesh.Scene, output_dir: str) -> List[str]:
    """
    Chụp tự động 4 ảnh góc nhìn (0°, 90°, 180°, 270°) ra định dạng PNG.
    Ưu tiên dùng scene.save_image() nếu có OpenGL/OSMesa, tự động fallback sang Software Rasterizer.
    """
    target_dir = Path(output_dir).resolve()
    target_dir.mkdir(parents=True, exist_ok=True)

    angles = [0, 90, 180, 270]
    rendered_paths: List[str] = []

    # Hợp nhất scene thành một Trimesh phục vụ kết xuất
    combined_mesh = scene.dump(concatenate=True)
    if isinstance(combined_mesh, list):
        combined_mesh = trimesh.util.concatenate(combined_mesh)

    for angle in angles:
        out_file = target_dir / f"turntable_{angle:03d}.png"
        rendered_ok = False

        try:
            rotated_scene = scene.copy()
            rad = math.radians(angle)
            rot_matrix = trimesh.transformations.rotation_matrix(rad, [0, 1, 0])
            rotated_scene.apply_transform(rot_matrix)
            png_bytes = rotated_scene.save_image(resolution=(1024, 1024), visible=False)
            if png_bytes:
                with Image.open(io.BytesIO(png_bytes)) as im:
                    im.convert("RGBA").save(out_file, format="PNG")
                rendered_ok = True
        except Exception:
            rendered_ok = False

        if not rendered_ok:
            _software_render_turntable_png(
                mesh=combined_mesh,
                angle_deg=angle,
                output_png_path=out_file,
                canvas_size=1024,
            )

        rendered_paths.append(str(out_file))

    return rendered_paths


def normalize_glb_model(
    input_path: str,
    output_path: str,
    width_mm: float,
    height_mm: float,
    depth_mm: float,
    turntable_dir: str = "",
) -> dict:
    src = Path(input_path).resolve()
    dst = Path(output_path).resolve()

    if not src.is_file():
        raise FileNotFoundError(f"Không tìm thấy file .glb đầu vào: {src}")

    if width_mm <= 0 or height_mm <= 0 or depth_mm <= 0:
        raise ValueError("Kích thước thực tế (width_mm, height_mm, depth_mm) phải lớn hơn 0.")

    dst.parent.mkdir(parents=True, exist_ok=True)

    # Tải mô hình .glb dưới dạng Scene để bảo toàn toàn bộ chất liệu PBR và Texture
    scene = trimesh.load(str(src), force="scene")
    original_extents = np.asarray(scene.extents, dtype=np.float64)

    if original_extents is None or original_extents.shape != (3,) or np.any(original_extents <= 1e-8):
        raise ValueError("Mô hình 3D .glb có Bounding Box không hợp lệ hoặc rỗng.")

    # Quy đổi kích thước thực tế từ milimét (mm) sang mét (1 unit = 1m theo chuẩn glTF 2.0)
    target_meters = np.array([
        width_mm / 1000.0,
        height_mm / 1000.0,
        depth_mm / 1000.0,
    ], dtype=np.float64)

    # Bước 1: Đưa tâm Bounding Box hiện tại về gốc tọa độ (0, 0, 0)
    current_bounds = np.asarray(scene.bounds, dtype=np.float64)
    current_center = current_bounds.mean(axis=0)
    center_transform = trimesh.transformations.translation_matrix(-current_center)
    scene.apply_transform(center_transform)

    # Bước 2: Tính toán ma trận tỷ lệ (Scale Matrix) độc lập trên 3 trục X, Y, Z
    scale_factors = target_meters / original_extents
    scale_matrix = np.diag([
        float(scale_factors[0]),
        float(scale_factors[1]),
        float(scale_factors[2]),
        1.0,
    ])
    scene.apply_transform(scale_matrix)

    # Bước 3: Căn tâm đáy mô hình về đúng gốc tọa độ (0, 0, 0) -> X_center = 0, Y_min = 0, Z_center = 0
    scaled_bounds = np.asarray(scene.bounds, dtype=np.float64)
    min_bounds, max_bounds = scaled_bounds[0], scaled_bounds[1]
    floor_transform = trimesh.transformations.translation_matrix([
        -((min_bounds[0] + max_bounds[0]) / 2.0),
        -min_bounds[1],
        -((min_bounds[2] + max_bounds[2]) / 2.0),
    ])
    scene.apply_transform(floor_transform)

    # Bước 4: Xuất file .glb đã chuẩn hóa
    glb_bytes = scene.export(file_type="glb")
    with open(dst, "wb") as f:
        f.write(glb_bytes)

    final_extents = np.asarray(scene.extents, dtype=np.float64)

    # Bước 5: Kết xuất 4 ảnh góc xoay Turntable (0°, 90°, 180°, 270°) nếu có chỉ định thư mục
    turntable_images: List[str] = []
    if turntable_dir and turntable_dir.strip():
        turntable_images = render_turntable_snapshots(scene=scene, output_dir=turntable_dir)

    return {
        "status": "success",
        "input_glb": str(src),
        "output_glb": str(dst),
        "original_extents_units": np.round(original_extents, 6).tolist(),
        "scale_factors_xyz": np.round(scale_factors, 6).tolist(),
        "normalized_extents_meters": np.round(final_extents, 6).tolist(),
        "normalized_extents_mm": np.round(final_extents * 1000.0, 2).tolist(),
        "turntable_snapshots": turntable_images,
    }


def main() -> int:
    args = parse_args()
    try:
        result = normalize_glb_model(
            input_path=args.input,
            output_path=args.output,
            width_mm=args.width_mm,
            height_mm=args.height_mm,
            depth_mm=args.depth_mm,
            turntable_dir=args.render_turntable_dir,
        )
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except Exception as exc:
        error_payload = {
            "status": "error",
            "message": str(exc),
        }
        print(json.dumps(error_payload, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
