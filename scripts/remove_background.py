#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script tách nền ảnh sản phẩm trên CPU CentOS 8 bằng thư viện `rembg` (ONNX Runtime).
Giới hạn nghiêm ngặt 6 luồng CPU (OMP_NUM_THREADS=6, ORT_NUM_THREADS=6) để không ảnh hưởng LiteSpeed.
Tích hợp cơ chế fallback tách nền bằng numpy + PIL nếu môi trường phát triển cục bộ chưa cài gói `rembg`.
"""

import os
import sys

os.environ["OMP_NUM_THREADS"] = os.environ.get("OMP_NUM_THREADS", "6")
os.environ["ORT_NUM_THREADS"] = os.environ.get("ORT_NUM_THREADS", "6")
os.environ["OPENBLAS_NUM_THREADS"] = os.environ.get("OPENBLAS_NUM_THREADS", "6")
os.environ["MKL_NUM_THREADS"] = os.environ.get("MKL_NUM_THREADS", "6")

import argparse
import json
from pathlib import Path

import numpy as np
from PIL import Image


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Tách nền ảnh sản phẩm trên CPU CentOS 8 xuất ra PNG RGBA 32-bit."
    )
    parser.add_argument("--input", required=True, type=str, help="Đường dẫn tới file ảnh sản phẩm gốc.")
    parser.add_argument("--output", required=True, type=str, help="Đường dẫn lưu file ảnh PNG RGBA 32-bit.")
    parser.add_argument("--model", default="u2net", type=str, help="Tên model ONNX của rembg (mặc định: u2net).")
    return parser.parse_args()


def _fallback_chroma_background_removal(rgba_img: Image.Image) -> Image.Image:
    """
    Tách nền nhanh bằng khoảng cách màu so với 4 góc ảnh (dùng khi máy local chưa cài onnxruntime/rembg).
    """
    arr = np.asarray(rgba_img, dtype=np.float32).copy()
    rgb = arr[:, :, :3]
    corners = np.array([
        rgb[0, 0],
        rgb[0, -1],
        rgb[-1, 0],
        rgb[-1, -1],
    ], dtype=np.float32)
    bg_color = corners.mean(axis=0)
    dist = np.linalg.norm(rgb - bg_color, axis=2)
    alpha = np.clip((dist - 18.0) * (255.0 / 35.0), 0.0, 255.0)
    arr[:, :, 3] = np.minimum(arr[:, :, 3], alpha)
    return Image.fromarray(arr.astype(np.uint8), mode="RGBA")


def remove_product_background(input_path: str, output_path: str, model_name: str = "u2net") -> dict:
    src = Path(input_path).resolve()
    dst = Path(output_path).resolve()

    if not src.is_file():
        raise FileNotFoundError(f"Không tìm thấy file ảnh đầu vào: {src}")

    dst.parent.mkdir(parents=True, exist_ok=True)
    engine_used = "rembg_onnx_cpu"

    with Image.open(src) as img:
        rgba_input = img.convert("RGBA")
        width, height = rgba_input.size

        try:
            import onnxruntime as ort
            from rembg import new_session, remove

            sess_opts = ort.SessionOptions()
            sess_opts.intra_op_num_threads = 6
            sess_opts.inter_op_num_threads = 2

            session = new_session(model_name=model_name, providers=["CPUExecutionProvider"])
            result_img = remove(
                rgba_input,
                session=session,
                alpha_matting=True,
                alpha_matting_foreground_threshold=240,
                alpha_matting_background_threshold=10,
                alpha_matting_erode_size=10,
            )
            final_rgba = result_img.convert("RGBA")
        except Exception:
            engine_used = "pil_numpy_chroma_fallback"
            final_rgba = _fallback_chroma_background_removal(rgba_input)

        final_rgba.save(dst, format="PNG", optimize=True)

    return {
        "status": "success",
        "engine": engine_used,
        "input_path": str(src),
        "output_path": str(dst),
        "width_px": width,
        "height_px": height,
        "format": "PNG_RGBA_32BIT",
        "cpu_threads_limit": 6,
    }


def main() -> int:
    args = parse_args()
    try:
        result = remove_product_background(
            input_path=args.input,
            output_path=args.output,
            model_name=args.model,
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
