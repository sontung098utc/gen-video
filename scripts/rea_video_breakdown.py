#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Công cụ giải mã video từng phân cảnh (Shot-by-Shot Reverse Engineering)
tham khảo kiến trúc từ JuneYaooo/rea-video (FFmpeg + Python).

Chức năng:
1. Dùng ffprobe đọc metadata (độ phân giải, FPS, tổng thời lượng, có luồng audio hay không).
2. Dùng FFmpeg scene-cut detection (`gt(scene, threshold)`) tách chính xác từng Shot (start_ms, end_ms, duration_ms).
3. Trích xuất khung hình đầu (first) và giữa (mid) cho mỗi Shot để Gemini Multimodal phân tích ngược Prompt & Góc máy.
4. Xuất file `breakdown.json` và `VIDEO.md` (chuẩn tài liệu phân lớp của rea-video).
"""

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List


def run_cmd(cmd: List[str], timeout: int = 120) -> subprocess.CompletedProcess:
    env = os.environ.copy()
    env.setdefault("OMP_NUM_THREADS", "6")
    return subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=timeout,
        env=env,
    )


def wrap_ffmpeg(ffmpeg_bin: str, args: List[str]) -> List[str]:
    if os.name != "nt":
        return ["nice", "-n", "10", ffmpeg_bin] + args
    return [ffmpeg_bin] + args


def probe_video(ffprobe_bin: str, input_path: str) -> Dict[str, Any]:
    cmd = [
        ffprobe_bin,
        "-v",
        "error",
        "-show_format",
        "-show_streams",
        "-of",
        "json",
        input_path,
    ]
    proc = run_cmd(cmd, timeout=30)
    if proc.returncode != 0:
        raise RuntimeError(f"ffprobe thất bại: {proc.stderr.strip()}")

    data = json.loads(proc.stdout or "{}")
    fmt = data.get("format", {})
    streams = data.get("streams", [])

    video_stream = next((s for s in streams if s.get("codec_type") == "video"), {})
    audio_stream = next((s for s in streams if s.get("codec_type") == "audio"), None)

    duration_sec = float(fmt.get("duration") or video_stream.get("duration") or 10.0)
    width = int(video_stream.get("width") or 720)
    height = int(video_stream.get("height") or 1280)

    fps_raw = str(video_stream.get("r_frame_rate") or "30/1")
    fps = 30.0
    if "/" in fps_raw:
        num, den = fps_raw.split("/", 1)
        if float(den) > 0:
            fps = round(float(num) / float(den), 2)
    else:
        fps = float(fps_raw)

    return {
        "duration_sec": round(duration_sec, 3),
        "duration_ms": int(round(duration_sec * 1000)),
        "width": width,
        "height": height,
        "fps": fps,
        "has_audio": audio_stream is not None,
    }


def detect_shot_cuts(
    ffmpeg_bin: str,
    input_path: str,
    duration_sec: float,
    threshold: float = 0.28,
    max_shots: int = 12,
    threads: int = 6,
) -> List[float]:
    cmd = wrap_ffmpeg(
        ffmpeg_bin,
        [
            "-y",
            "-threads",
            str(threads),
            "-i",
            input_path,
            "-vf",
            f"select='gt(scene,{threshold})',showinfo",
            "-f",
            "null",
            "-",
        ],
    )
    proc = run_cmd(cmd, timeout=120)
    stderr_text = proc.stderr or ""

    cuts: List[float] = [0.0]
    for match in re.finditer(r"pts_time:([0-9]+(?:\.[0-9]+)?)", stderr_text):
        t = round(float(match.group(1)), 3)
        # Bỏ qua các điểm cắt quá gần nhau (< 1.0 giây) để tránh nhiễu chuyển cảnh mờ
        if t - cuts[-1] >= 1.0 and (duration_sec - t) >= 0.8:
            cuts.append(t)

    # Nếu video không có điểm cắt cảnh rõ ràng (hoặc quá ít shot), chia đều theo nhịp 3.5 giây
    if len(cuts) < 2:
        segment_len = max(2.5, min(4.0, duration_sec / 4.0))
        cuts = [0.0]
        cursor = segment_len
        while cursor < duration_sec - 0.8:
            cuts.append(round(cursor, 3))
            cursor += segment_len

    # Giới hạn tối đa max_shots để đảm bảo tốc độ và chi phí tối ưu
    if len(cuts) > max_shots:
        step = len(cuts) / max_shots
        sampled = [cuts[int(i * step)] for i in range(max_shots)]
        sampled[0] = 0.0
        cuts = sorted(list(dict.fromkeys(sampled)))

    return cuts


def extract_frame(
    ffmpeg_bin: str,
    input_path: str,
    timestamp_sec: float,
    output_jpg: Path,
    threads: int = 6,
) -> bool:
    cmd = wrap_ffmpeg(
        ffmpeg_bin,
        [
            "-y",
            "-threads",
            str(threads),
            "-ss",
            f"{max(0.0, timestamp_sec):.3f}",
            "-i",
            input_path,
            "-frames:v",
            "1",
            "-vf",
            "scale=640:-1",
            "-q:v",
            "3",
            str(output_jpg),
        ],
    )
    proc = run_cmd(cmd, timeout=30)
    return proc.returncode == 0 and output_jpg.is_file() and output_jpg.stat().st_size > 0


def write_video_md(output_dir: Path, meta: Dict[str, Any], shots: List[Dict[str, Any]]) -> Path:
    md_path = output_dir / "VIDEO.md"
    lines = [
        "# VIDEO.md — Rea-Video Shot-by-Shot Breakdown",
        "",
        "## 1. Metadata Video Gốc",
        f"- **Thời lượng**: `{meta['duration_sec']}s` ({meta['duration_ms']} ms)",
        f"- **Độ phân giải**: `{meta['width']}x{meta['height']}` @ `{meta['fps']} fps`",
        f"- **Số lượng Shot tách được**: `{len(shots)} shots`",
        f"- **Luồng Audio**: `{'Có' if meta['has_audio'] else 'Không'}`",
        "",
        "## 2. Danh Sách Phân Cảnh (Shots)",
        "",
    ]

    for shot in shots:
        lines.extend(
            [
                f"### Shot #{shot['shot_index']:02d} (`{shot['start_ms']}ms` → `{shot['end_ms']}ms`, dài `{shot['duration_ms']}ms`)",
                f"- **First Frame**: `{shot['first_frame_rel']}`",
                f"- **Mid Frame**: `{shot['mid_frame_rel']}`",
                "",
            ]
        )

    md_path.write_text("\n".join(lines), encoding="utf-8")
    return md_path


def main() -> int:
    parser = argparse.ArgumentParser(description="Rea-Video Shot-by-Shot Analyzer")
    parser.add_argument("--input", required=True, help="Đường dẫn file video đầu vào")
    parser.add_argument("--output-dir", required=True, help="Thư mục lưu khung hình và breakdown.json")
    parser.add_argument("--ffmpeg", default="ffmpeg", help="Đường dẫn binary ffmpeg")
    parser.add_argument("--ffprobe", default="ffprobe", help="Đường dẫn binary ffprobe")
    parser.add_argument("--threshold", type=float, default=0.28, help="Ngưỡng phát hiện chuyển cảnh FFmpeg")
    parser.add_argument("--max-shots", type=int, default=10, help="Số shot tối đa")
    parser.add_argument("--threads", type=int, default=6, help="Số luồng CPU tối đa cho FFmpeg")
    args = parser.parse_args()

    input_path = str(Path(args.input).resolve())
    output_dir = Path(args.output_dir).resolve()
    shots_dir = output_dir / "shots"
    shots_dir.mkdir(parents=True, exist_ok=True)

    meta = probe_video(args.ffprobe, input_path)
    cuts = detect_shot_cuts(
        ffmpeg_bin=args.ffmpeg,
        input_path=input_path,
        duration_sec=meta["duration_sec"],
        threshold=args.threshold,
        max_shots=args.max_shots,
        threads=args.threads,
    )

    shots: List[Dict[str, Any]] = []
    for idx, start_sec in enumerate(cuts):
        end_sec = cuts[idx + 1] if idx + 1 < len(cuts) else meta["duration_sec"]
        start_ms = int(round(start_sec * 1000))
        end_ms = int(round(end_sec * 1000))
        duration_ms = max(800, end_ms - start_ms)
        mid_sec = start_sec + (end_sec - start_sec) * 0.5

        shot_num = idx + 1
        first_name = f"shot_{shot_num:03d}_first.jpg"
        mid_name = f"shot_{shot_num:03d}_mid.jpg"
        first_path = shots_dir / first_name
        mid_path = shots_dir / mid_name

        extract_frame(args.ffmpeg, input_path, start_sec + 0.08, first_path, args.threads)
        extract_frame(args.ffmpeg, input_path, mid_sec, mid_path, args.threads)

        shots.append(
            {
                "shot_index": shot_num,
                "start_ms": start_ms,
                "end_ms": end_ms,
                "duration_ms": duration_ms,
                "first_frame_path": str(first_path) if first_path.is_file() else None,
                "mid_frame_path": str(mid_path) if mid_path.is_file() else None,
                "first_frame_rel": f"shots/{first_name}",
                "mid_frame_rel": f"shots/{mid_name}",
            }
        )

    video_md_path = write_video_md(output_dir, meta, shots)
    payload = {
        "meta": meta,
        "shots": shots,
        "video_md_path": str(video_md_path),
    }

    breakdown_path = output_dir / "breakdown.json"
    breakdown_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"status": "ok", "shots_count": len(shots), "breakdown_path": str(breakdown_path)}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
