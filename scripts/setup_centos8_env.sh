#!/usr/bin/env bash
# ==============================================================================
# KỊCH BẢN CÀI ĐẶT MÔI TRƯỜNG HẠ TẦNG SERVER CENTOS 8 (GLIBC 2.28)
# Dự án: Hệ Thống Tạo Video Ngắn Tự Động Bằng AI (AI Short Video Generator)
# Cấu hình mục tiêu:
#   - CentOS 8 (glibc 2.28), 128 GB RAM, 34+ CPU Threads (Không có GPU rời)
#   - Web Server: LiteSpeed + lsphp82 (PHP 8.2), MySQL, Redis, PM2 v7.0.3
#   - Công cụ cài đặt: FFmpeg 7.x Static (kèm ffprobe, libass, libx264),
#     Python 3.11 + pip (edge-tts, rembg, trimesh, numpy, onnxruntime)
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info()  { echo -e "${CYAN}[INFO]${NC} $1"; }
log_ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Kiểm tra quyền root
if [[ $EUID -ne 0 ]]; then
    log_error "Vui lòng chạy script này với quyền root (sudo bash scripts/setup_centos8_env.sh)."
    exit 1
fi

log_info "=== BẮT ĐẦU CÀI ĐẶT MÔI TRƯỜNG AI SHORT VIDEO GENERATOR TRÊN CENTOS 8 ==="

# ------------------------------------------------------------------------------
# 1. KIỂM TRA HỆ ĐIỀU HÀNH, GLIBC & TÀI NGUYÊN CPU/RAM
# ------------------------------------------------------------------------------
GLIBC_VER=$(ldd --version | head -n1 | awk '{print $NF}')
CPU_CORES=$(nproc --all)
TOTAL_RAM_GB=$(free -g | awk '/^Mem:/{print $2}')

log_info "Phiên bản glibc hiện tại: ${GLIBC_VER} | Số luồng CPU: ${CPU_CORES} | RAM: ${TOTAL_RAM_GB} GB"

# Đảm bảo kho lưu trữ CentOS 8 Vault hoạt động nếu repo mặc định đã EOL
if grep -q "mirrorlist.centos.org" /etc/yum.repos.d/CentOS-*.repo 2>/dev/null; then
    log_warn "Phát hiện mirrorlist CentOS 8 cũ, đang chuyển sang vault.centos.org..."
    sed -i 's/mirrorlist/#mirrorlist/g' /etc/yum.repos.d/CentOS-*
    sed -i 's|#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/CentOS-*
fi

dnf install -y epel-release wget curl tar xz unzip fontconfig freetype dejavu-sans-fonts || true
fc-cache -fv >/dev/null 2>&1 || true

# ------------------------------------------------------------------------------
# 2. CÀI ĐẶT FFMPEG 7.x STATIC BINARY (KÈM FFPROBE, LIBASS, LIBX264)
# ------------------------------------------------------------------------------
log_info "Đang kiểm tra và cài đặt FFmpeg 7.x Static Binary vào /usr/local/bin..."

FFMPEG_BIN="/usr/local/bin/ffmpeg"
FFPROBE_BIN="/usr/local/bin/ffprobe"
FFMPEG_ARCHIVE_URL="https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz"
TMP_FFMPEG_DIR="/tmp/ffmpeg_static_install"

install_ffmpeg_static() {
    rm -rf "${TMP_FFMPEG_DIR}"
    mkdir -p "${TMP_FFMPEG_DIR}"
    log_info "Đang tải FFmpeg 7.x Static (tương thích hoàn toàn với glibc 2.28)..."
    curl -L --fail --retry 3 "${FFMPEG_ARCHIVE_URL}" -o "${TMP_FFMPEG_DIR}/ffmpeg-static.tar.xz"
    tar -xJf "${TMP_FFMPEG_DIR}/ffmpeg-static.tar.xz" -C "${TMP_FFMPEG_DIR}" --strip-components=1

    install -m 0755 "${TMP_FFMPEG_DIR}/ffmpeg" "${FFMPEG_BIN}"
    install -m 0755 "${TMP_FFMPEG_DIR}/ffprobe" "${FFPROBE_BIN}"
    rm -rf "${TMP_FFMPEG_DIR}"
}

if [[ ! -x "${FFMPEG_BIN}" ]] || ! "${FFMPEG_BIN}" -version | head -n1 | grep -Eq "ffmpeg version (7|[8-9]|N-)"; then
    install_ffmpeg_static
else
    log_ok "FFmpeg đã được cài đặt sẵn: $(${FFMPEG_BIN} -version | head -n1)"
fi

# Kiểm tra các bộ mã hóa bắt buộc: libx264, libass (đốt phụ đề tiếng Việt), xfade, sidechaincompress
log_info "Kiểm tra hỗ trợ libx264 và libass trong FFmpeg..."
"${FFMPEG_BIN}" -encoders 2>/dev/null | grep -q "libx264" && log_ok "Hỗ trợ encoder libx264: OK" || log_error "Thiếu libx264!"
"${FFMPEG_BIN}" -filters  2>/dev/null | grep -q "subtitles" && log_ok "Hỗ trợ filter subtitles (libass): OK" || log_error "Thiếu libass!"
"${FFMPEG_BIN}" -filters  2>/dev/null | grep -q "xfade" && log_ok "Hỗ trợ filter xfade: OK" || log_error "Thiếu xfade!"
"${FFMPEG_BIN}" -filters  2>/dev/null | grep -q "sidechaincompress" && log_ok "Hỗ trợ filter sidechaincompress: OK" || log_error "Thiếu sidechaincompress!"

# ------------------------------------------------------------------------------
# 3. CÀI ĐẶT PYTHON 3.11, PIP VÀ CÁC THƯ VIỆN AI/3D TRÊN CPU
# ------------------------------------------------------------------------------
log_info "Đang cài đặt Python 3.11 và pip..."
dnf install -y python3.11 python3.11-devel python3.11-pip gcc gcc-c++ make

PYTHON_BIN="/usr/bin/python3.11"
PIP_BIN="/usr/bin/pip3.11"

if [[ ! -x "${PYTHON_BIN}" ]]; then
    log_error "Không tìm thấy Python 3.11 tại ${PYTHON_BIN}!"
    exit 1
fi

log_ok "Phiên bản Python: $(${PYTHON_BIN} --version)"

log_info "Đang nâng cấp pip, setuptools, wheel và cài đặt các thư viện cốt lõi..."
"${PYTHON_BIN}" -m pip install --upgrade pip setuptools wheel

# Cài đặt các gói: edge-tts, rembg[cpu], trimesh, numpy, onnxruntime, pillow, scipy
"${PYTHON_BIN}" -m pip install \
    "edge-tts>=6.1.12" \
    "numpy>=1.26.0,<2.0.0" \
    "onnxruntime>=1.17.0" \
    "rembg>=2.0.56" \
    "trimesh[easy]>=4.4.0" \
    "Pillow>=10.3.0" \
    "scipy>=1.12.0"

# Tạo symlink cho edge-tts tại /usr/local/bin/edge-tts để Laravel gọi trực tiếp
if [[ -f "/usr/local/bin/edge-tts" ]]; then
    log_ok "CLI edge-tts đã sẵn sàng tại /usr/local/bin/edge-tts"
elif command -v edge-tts >/dev/null 2>&1; then
    ln -sf "$(command -v edge-tts)" /usr/local/bin/edge-tts
    log_ok "Đã tạo symlink /usr/local/bin/edge-tts"
fi

# Kiểm tra import các thư viện Python
"${PYTHON_BIN}" -c "
import numpy, onnxruntime, rembg, trimesh, edge_tts
print('Python Libraries Check OK:', {
    'numpy': numpy.__version__,
    'onnxruntime': onnxruntime.__version__,
    'trimesh': trimesh.__version__,
})
"
log_ok "Tất cả thư viện Python (edge-tts, rembg, trimesh, numpy, onnxruntime) hoạt động tốt trên CPU."

# ------------------------------------------------------------------------------
# 4. KIỂM TRA LITESPEED LSPHP 8.2, REDIS SERVER VÀ PM2 v7.0.3
# ------------------------------------------------------------------------------
log_info "Đang kiểm tra LiteSpeed PHP 8.2 (lsphp82)..."
LSPHP_CANDIDATES=(
    "/usr/local/lsws/lsphp82/bin/php"
    "/usr/bin/php"
    "$(command -v php || true)"
)

FOUND_PHP=""
for candidate in "${LSPHP_CANDIDATES[@]}"; do
    if [[ -n "${candidate}" && -x "${candidate}" ]]; then
        PHP_VER=$("${candidate}" -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
        if [[ "${PHP_VER}" == "8.2" ]]; then
            FOUND_PHP="${candidate}"
            break
        fi
    fi
done

if [[ -n "${FOUND_PHP}" ]]; then
    log_ok "Tìm thấy PHP 8.2 tại: ${FOUND_PHP} ($(${FOUND_PHP} -v | head -n1))"
    # Tạo symlink /usr/local/bin/lsphp82 để PM2 gọi thống nhất
    ln -sf "${FOUND_PHP}" /usr/local/bin/lsphp82
else
    log_warn "Chưa tìm thấy lsphp82 tại /usr/local/lsws/lsphp82/bin/php. Vui lòng kiểm tra cấu hình LiteSpeed."
fi

log_info "Đang kiểm tra kết nối Redis Server..."
if command -v redis-cli >/dev/null 2>&1; then
    REDIS_PONG=$(redis-cli ping 2>/dev/null || true)
    if [[ "${REDIS_PONG}" == "PONG" ]]; then
        log_ok "Kết nối Redis thành công (PONG)."
    else
        log_warn "Redis chưa phản hồi PONG. Đang thử khởi động dịch vụ redis..."
        systemctl enable --now redis || true
    fi
else
    log_warn "Không tìm thấy redis-cli, đang cài đặt redis..."
    dnf install -y redis && systemctl enable --now redis
fi

log_info "Đang kiểm tra PM2 Process Manager..."
if command -v pm2 >/dev/null 2>&1; then
    log_ok "Phiên bản PM2 hiện tại: $(pm2 --version)"
else
    log_warn "Chưa phát hiện lệnh pm2 trong PATH của root. Hãy kiểm tra PATH của Node.js/NVM."
fi

# ------------------------------------------------------------------------------
# 5. KHỞI TẠO CẤU TRÚC THƯ MỤC LƯU TRỮ LOCAL SFX/BGM & CACHE 3D
# ------------------------------------------------------------------------------
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
log_info "Đang tạo cấu trúc thư mục lưu trữ tại ${PROJECT_ROOT}/storage/app..."

mkdir -p "${PROJECT_ROOT}/storage/app/sfx_library/bgm"
mkdir -p "${PROJECT_ROOT}/storage/app/sfx_library/transitions"
mkdir -p "${PROJECT_ROOT}/storage/app/sfx_library/impacts"
mkdir -p "${PROJECT_ROOT}/storage/app/public/audio"
mkdir -p "${PROJECT_ROOT}/storage/app/public/subtitles"
mkdir -p "${PROJECT_ROOT}/storage/app/public/models_3d/cache"
mkdir -p "${PROJECT_ROOT}/storage/app/public/products/nobg"
mkdir -p "${PROJECT_ROOT}/storage/app/public/scenes"
mkdir -p "${PROJECT_ROOT}/storage/app/public/videos"
mkdir -p "${PROJECT_ROOT}/storage/logs/pm2"

log_ok "=== HOÀN TẤT CÀI ĐẶT MÔI TRƯỜNG CENTOS 8 CHO AI SHORT VIDEO GENERATOR ==="
