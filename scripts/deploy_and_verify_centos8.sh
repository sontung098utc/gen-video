#!/usr/bin/env bash
# ==============================================================================
# KỊCH BẢN KHỞI ĐỘNG PM2 QUEUE WORKERS, CRONJOB DỌN RÁC & KIỂM TRA HỆ THỐNG
# Dự án: Hệ Thống Tạo Video Ngắn Tự Động Bằng AI (Sprint 4 - Production Ready)
# Máy chủ mục tiêu: CentOS 8 (LiteSpeed lsphp82, Redis, PM2 v7.0.3)
# ==============================================================================

set -euo pipefail

GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log_info()  { echo -e "${CYAN}[INFO]${NC} $1"; }
log_ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PROJECT_DIR}"

PHP_BIN="/usr/local/lsws/lsphp82/bin/php"
if [[ ! -x "${PHP_BIN}" ]]; then
    PHP_BIN="$(command -v php || echo '/usr/bin/php')"
fi

log_info "Thư mục dự án: ${PROJECT_DIR}"
log_info "Trình thông dịch PHP: ${PHP_BIN} ($(${PHP_BIN} -v | head -n1))"

# ------------------------------------------------------------------------------
# 1. KHỞI TẠO CÁC THƯ MỤC LƯU TRỮ & TẠO KHO LOCAL SFX/BGM MẪU ($0.00)
# ------------------------------------------------------------------------------
log_info "Đang kiểm tra cấu trúc thư mục storage và tạo symlink storage:link..."
mkdir -p storage/app/temp
mkdir -p storage/app/temp_render
mkdir -p storage/app/public/renders
mkdir -p storage/app/public/videos
mkdir -p storage/app/public/audio
mkdir -p storage/app/public/subtitles
mkdir -p storage/app/public/models_3d/raw
mkdir -p storage/app/public/models_3d/cache
mkdir -p storage/app/public/products/originals
mkdir -p storage/app/public/products/nobg
mkdir -p storage/app/public/products/turntable
mkdir -p storage/app/sfx_library/bgm
mkdir -p storage/app/sfx_library/transitions
mkdir -p storage/app/sfx_library/impacts
mkdir -p storage/logs/pm2

"${PHP_BIN}" artisan storage:link --force >/dev/null 2>&1 || true

# Tạo tự động các file âm thanh SFX & BGM chuẩn (ambient_bgm.mp3, whoosh.mp3, pop.mp3) bằng FFmpeg lavfi nếu kho đang trống
FFMPEG_BIN="/usr/local/bin/ffmpeg"
if [[ ! -x "${FFMPEG_BIN}" ]]; then
    FFMPEG_BIN="$(command -v ffmpeg || echo '/usr/local/bin/ffmpeg')"
fi

if [[ -x "${FFMPEG_BIN}" ]]; then
    if [[ ! -f "storage/app/sfx_library/ambient_bgm.mp3" || ! -f "storage/app/sfx_library/whoosh.mp3" || ! -f "storage/app/sfx_library/pop.mp3" ]]; then
        log_info "Đang khởi tạo các file âm thanh Local SFX & BGM (ambient_bgm.mp3, whoosh.mp3, pop.mp3) bằng FFmpeg..."

        # 1. Nhạc nền nhẹ ambient_bgm.mp3 (hợp âm Ambient Pad ấm áp, rõ ràng, 30 giây, Stereo 44.1kHz)
        nice -n 10 "${FFMPEG_BIN}" -y -threads 2 -f lavfi \
            -i "aevalsrc='0.28*sin(2*PI*220*t)+0.22*sin(2*PI*277.18*t)+0.25*sin(2*PI*329.63*t)+0.18*sin(2*PI*440*t)*(0.6+0.4*sin(2*PI*0.25*t))':s=44100:d=30" \
            -af "aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,afade=t=in:ss=0:d=1.5,afade=t=out:st=28.5:d=1.5,volume=0.85" \
            -c:a libmp3lame -b:a 192k storage/app/sfx_library/ambient_bgm.mp3 >/dev/null 2>&1 || true
        cp -f storage/app/sfx_library/ambient_bgm.mp3 storage/app/sfx_library/bgm/ambient_bgm.mp3 2>/dev/null || true

        # 2. Tiếng động chuyển cảnh whoosh.mp3 (hiệu ứng lướt gió chuyển cảnh sắc nét, 0.55 giây)
        nice -n 10 "${FFMPEG_BIN}" -y -threads 2 -f lavfi \
            -i "anoisesrc=d=0.55:c=pink:r=44100:a=0.9" \
            -af "aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,bandpass=f=900:width_type=h:w=1400,afade=t=in:ss=0:d=0.18,afade=t=out:st=0.22:d=0.33,volume=0.95" \
            -c:a libmp3lame -b:a 192k storage/app/sfx_library/whoosh.mp3 >/dev/null 2>&1 || true
        cp -f storage/app/sfx_library/whoosh.mp3 storage/app/sfx_library/transitions/whoosh.mp3 2>/dev/null || true

        # 3. Tiếng động điểm nhấn pop.mp3 (hiệu ứng Pop/Chime hiện đại, 0.35 giây)
        nice -n 10 "${FFMPEG_BIN}" -y -threads 2 -f lavfi \
            -i "aevalsrc='0.6*sin(2*PI*(520+680*t)*t)*exp(-9*t)+0.35*sin(2*PI*1046.5*t)*exp(-12*t)':s=44100:d=0.35" \
            -af "aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo,afade=t=out:st=0.15:d=0.20,volume=0.95" \
            -c:a libmp3lame -b:a 192k storage/app/sfx_library/pop.mp3 >/dev/null 2>&1 || true
        cp -f storage/app/sfx_library/pop.mp3 storage/app/sfx_library/impacts/pop.mp3 2>/dev/null || true

        log_ok "Đã tạo sẵn ambient_bgm.mp3, whoosh.mp3 và pop.mp3 tại storage/app/sfx_library/."
    fi
fi

# ------------------------------------------------------------------------------
# 2. KHỞI CHẠY 3 QUEUE WORKERS QUA PM2 v7.0.3
# ------------------------------------------------------------------------------
log_info "Đang khởi chạy 3 nhóm Queue Workers qua PM2 (ecosystem.config.js)..."
if command -v pm2 >/dev/null 2>&1; then
    PHP_BINARY="${PHP_BIN}" pm2 startOrReload ecosystem.config.js --update-env
    pm2 save
    log_ok "Đã khởi động thành công 3 Queue Workers trên PM2:"
    pm2 status
else
    log_warn "Không tìm thấy lệnh pm2 trong PATH hiện tại. Hãy chạy: pm2 start ecosystem.config.js"
fi

# ------------------------------------------------------------------------------
# 3. THIẾT LẬP CRONJOB TỰ ĐỘNG CHO LARAVEL SCHEDULER (CHẠY CLEANUP LÚC 02:00)
# ------------------------------------------------------------------------------
log_info "Đang kiểm tra và đăng ký Cronjob cho Laravel Scheduler..."
CRON_ENTRY="* * * * * cd ${PROJECT_DIR} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"

if crontab -l 2>/dev/null | grep -Fq "${PROJECT_DIR} && ${PHP_BIN} artisan schedule:run"; then
    log_ok "Cronjob Laravel Scheduler đã tồn tại trong crontab."
else
    (crontab -l 2>/dev/null || true; echo "${CRON_ENTRY}") | crontab -
    log_ok "Đã thêm Cronjob Laravel Scheduler vào crontab (tự động chạy media:cleanup-temp lúc 02:00 sáng)."
fi

# ------------------------------------------------------------------------------
# 4. CHẠY THỬ NGHIỆM (DRY-RUN) LỆNH DỌN RÁC MEDIA TẠM
# ------------------------------------------------------------------------------
log_info "Đang kiểm tra lệnh Artisan 'media:cleanup-temp --dry-run'..."
"${PHP_BIN}" artisan media:cleanup-temp --dry-run || true

log_ok "=== HOÀN TẤT KHỞI ĐỘNG PM2 WORKERS & CRONJOB TRÊN CENTOS 8 ==="
