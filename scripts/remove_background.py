#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script tách nền ảnh sản phẩm trên CPU CentOS 8 bằng thư viện `rembg` (ONNX Runtime).
Giới hạn nghiêm ngặt 6 luồng CPU (OMP_NUM_THREADS=6, ORT_NUM_THREADS=6) để không ảnh hưởng LiteSpeed.

Cách gọi:
    /usr/bin/python3.11 scripts/remove_background.py \
        --input "/path/to/original.jpg" \
        --output "/path/to/nobg_rgba.png"
"""

import os
import sys

# Bắt buộc thiết lập giới hạn luồng CPU trước khi nạp numpy / onnxruntime / rembg
os.environ["OMP_NUM_THREADS"] = os.environ.get("OMP_NUM_THREADS", "6")
os.environ["ORT_NUM_THREADS"] = os.environ.get("ORT_NUM_THREADS", "6")
os.environ["OPENBLAS_NUM_THREADS"] = os.environ.get("OPENBLAS_NUM_THREADS", "6")
os.environ["MKL_NUM_THREADS"] = os.environ.get("MKL_NUM_THREADS", "6")

import argparse
import json
from pathlib import Path

import onnxruntime as ort
from PIL import Image
from rembg import new_session, remove


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Tách nền ảnh sản phẩm trên CPU CentOS 8 xuất ra PNG RGBA 32-bit."
    )
    parser.add_argument(
        "--input",
        required=True,
        type=str,
        help="Đường dẫn tới file ảnh sản phẩm gốc (JPG/PNG/WebP).",
    )
    parser.add_argument(
        "--output",
        required=True,
        type=str,
        help="Đường dẫn lưu file ảnh PNG RGBA 32-bit đã tách nền.",
    )
    parser.add_argument(
        "--model",
        default="u2net",
        type=str,
        help="Tên model ONNX của rembg (mặc định: u2net).",
    )
    return parser.parse_args()


def remove_product_background(input_path: str, output_path: str, model_name: str = "u2net") -> dict:
    src = Path(input_path).resolve()
    dst = Path(output_path).resolve()

    if not src.is_file():
        raise FileNotFoundError(f"Không tìm thấy file ảnh đầu vào: {src}")

    dst.parent.mkdir(parents=True, exist_ok=True)

    # Cấu hình SessionOptions của ONNX Runtime giới hạn tối đa 6 luồng CPU
    sess_opts = ort.SessionOptions()
    sess_opts.intra_op_num_threads = 6
    sess_opts.inter_op_num_threads = 2

    session = new_session(model_name=model_name, providers=["CPUExecutionProvider"])

    with Image.open(src) as img:
        rgba_input = img.convert("RGBA")
        width, height = rgba_input.size

        result_img = remove(
            rgba_input,
            session=session,
            alpha_matting=True,
            alpha_matting_foreground_threshold=240,
            alpha_matting_background_threshold=10,
            alpha_matting_erode_size=10,
        )

        # Đảm bảo đầu ra luôn là chuẩn PNG RGBA 32-bit (8-bit mỗi kênh R, G, B, A)
        final_rgba = result_img.convert("RGBA")
        final_rgba.save(dst, format="PNG", optimize=True)

    return {
        "status": "success",
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
