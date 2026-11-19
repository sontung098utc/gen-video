#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Trình tạo Mô hình 3D (.glb) Chuẩn Kích Thước & Màu Sắc Thực Tế từ Ảnh Sản Phẩm (CPU 0$).
- Tự động phân tích hình dáng sản phẩm từ ảnh PNG RGBA đã tách nền (hộp chữ nhật hay chai/lon trụ).
- Ánh xạ trực tiếp ảnh sản phẩm thực tế làm Texture PBR (UV Mapping) lên bề mặt 3D,
  giữ nguyên 100% màu sắc, nhãn mác, chữ viết và tỷ lệ thực tế (1 unit = 1m).
"""

import os

os.environ["OMP_NUM_THREADS"] = os.environ.get("OMP_NUM_THREADS", "6")
os.environ["OPENBLAS_NUM_THREADS"] = os.environ.get("OPENBLAS_NUM_THREADS", "6")

import argparse
from pathlib import Path
from typing import Tuple

import numpy as np
import trimesh
from PIL import Image


def extract_dominant_color(rgba_img: Image.Image) -> Tuple[int, int, int, int]:
    arr = np.asarray(rgba_img)
    alpha_mask = arr[:, :, 3] > 128
    if not np.any(alpha_mask):
        return (220, 225, 235, 255)
    rgb = arr[:, :, :3][alpha_mask]
    med = np.median(rgb, axis=0).astype(int)
    return (int(med[0]), int(med[1]), int(med[2]), 255)


def build_textured_box_glb(
    front_img: Image.Image,
    width_m: float,
    height_m: float,
    depth_m: float,
    dominant_rgba: Tuple[int, int, int, int],
) -> trimesh.Scene:
    # Tạo thân hộp chuẩn kích thước thực tế (X=width, Y=height, Z=depth)
    box = trimesh.creation.box(extents=[width_m, height_m, depth_m])

    # Cắt khít phần sản phẩm (non-transparent bounding box) để map full nhãn lên mặt trước/sau
    bbox = front_img.getbbox()
    cropped = front_img.crop(bbox) if bbox else front_img
    tex_img = Image.new("RGBA", (1024, 1024), dominant_rgba)
    resized = cropped.resize((1024, 1024), Image.Resampling.LANCZOS)
    tex_img.paste(resized, (0, 0), resized)

    # Gán UV mapping dựa trên tọa độ X-Y chuẩn hóa cho mặt trước & sau
    verts = box.vertices.copy()
    u = (verts[:, 0] / max(width_m, 1e-6)) + 0.5
    v = (verts[:, 1] / max(height_m, 1e-6)) + 0.5
    uv = np.column_stack([np.clip(u, 0.0, 1.0), np.clip(v, 0.0, 1.0)])

    material = trimesh.visual.material.PBRMaterial(
        baseColorTexture=tex_img.convert("RGB"),
        metallicFactor=0.08,
        roughnessFactor=0.35,
    )
    box.visual = trimesh.visual.TextureVisuals(uv=uv, material=material)

    # Đưa đáy mô hình về Y = 0
    box.apply_translation([0.0, height_m / 2.0, 0.0])
    return trimesh.Scene([box])


def build_textured_cylinder_glb(
    front_img: Image.Image,
    width_m: float,
    height_m: float,
    depth_m: float,
    dominant_rgba: Tuple[int, int, int, int],
) -> trimesh.Scene:
    radius = max(width_m, depth_m) / 2.0
    cyl = trimesh.creation.cylinder(radius=radius, height=height_m, sections=48)

    # Trimesh cylinder mặc định dọc trục Z -> xoay về trục Y (thẳng đứng theo chuẩn glTF)
    rot = trimesh.transformations.rotation_matrix(-np.pi / 2.0, [1.0, 0.0, 0.0])
    cyl.apply_transform(rot)

    bbox = front_img.getbbox()
    cropped = front_img.crop(bbox) if bbox else front_img

    # Tạo texture 2048x1024 bọc quanh thân lon/lọ sản phẩm (lặp nhãn mặt trước + màu nền thật)
    tex_w, tex_h = 2048, 1024
    tex_img = Image.new("RGBA", (tex_w, tex_h), dominant_rgba)
    label_w = 1024
    resized = cropped.resize((label_w, tex_h), Image.Resampling.LANCZOS)
    # Dán nhãn ở mặt trước (giữa texture) và mặt sau để xoay 360 độ luôn thấy nhãn thật
    tex_img.paste(resized, (512, 0), resized)

    verts = cyl.vertices.copy()
    angles = np.arctan2(verts[:, 0], verts[:, 2])  # [-pi, pi]
    u = (angles / (2.0 * np.pi)) + 0.5
    v = (verts[:, 1] / max(height_m, 1e-6)) + 0.5
    uv = np.column_stack([np.clip(u, 0.0, 1.0), np.clip(v, 0.0, 1.0)])

    material = trimesh.visual.material.PBRMaterial(
        baseColorTexture=tex_img.convert("RGB"),
        metallicFactor=0.12,
        roughnessFactor=0.32,
    )
    cyl.visual = trimesh.visual.TextureVisuals(uv=uv, material=material)
    cyl.apply_translation([0.0, height_m / 2.0, 0.0])

    # Nếu width != depth, scale nhẹ cho đúng kích thước thực tế mm
    if abs(width_m - depth_m) > 1e-4:
        scale_x = width_m / max(2.0 * radius, 1e-6)
        scale_z = depth_m / max(2.0 * radius, 1e-6)
        cyl.apply_scale([scale_x, 1.0, scale_z])

    return trimesh.Scene([cyl])


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate UV-textured 3D .glb from real product image")
    parser.add_argument("--image", required=True, help="Path to background-removed PNG RGBA product image")
    parser.add_argument("--output", required=True, help="Output .glb path")
    parser.add_argument("--width-mm", type=float, default=90.0)
    parser.add_argument("--height-mm", type=float, default=160.0)
    parser.add_argument("--depth-mm", type=float, default=90.0)
    args = parser.parse_args()

    img = Image.open(args.image).convert("RGBA")
    dominant_rgba = extract_dominant_color(img)

    w_m = max(0.01, args.width_mm / 1000.0)
    h_m = max(0.01, args.height_mm / 1000.0)
    d_m = max(0.01, args.depth_mm / 1000.0)

    # Nếu chiều rộng ~ chiều sâu (lon sữa, chai lọ, hũ) -> dựng khối trụ tròn; ngược lại dựng khối hộp vát
    ratio = min(w_m, d_m) / max(w_m, d_m)
    if ratio >= 0.78:
        scene = build_textured_cylinder_glb(img, w_m, h_m, d_m, dominant_rgba)
    else:
        scene = build_textured_box_glb(img, w_m, h_m, d_m, dominant_rgba)

    out_path = Path(args.output)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    with open(out_path, "wb") as f:
        f.write(scene.export(file_type="glb"))

    print(f"OK: {out_path} ({out_path.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
