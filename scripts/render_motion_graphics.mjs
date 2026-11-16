#!/usr/bin/env node
/**
 * Hypit-inspired HTML/Chromium Motion Graphics Renderer ($0.00)
 *
 * Tự động phát hiện trình duyệt Chromium/Edge/Chrome trên Windows & Linux
 * (hỗ trợ puppeteer/playwright nếu có sẵn, hoặc chạy trực tiếp `--headless --screenshot` không cần npm install).
 * Kết hợp FFmpeg tạo video Motion Graphics 60fps mượt mà hoặc xuất ảnh PNG overlay.
 *
 * Usage:
 *   node scripts/render_motion_graphics.mjs --config <path/to/config.json> --output <path/to/output.mp4|png>
 */

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execFileSync, spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

function parseArgs(argv) {
  const args = {};
  for (let i = 2; i < argv.length; i += 1) {
    const key = argv[i];
    if (key === '--config' || key === '-c') {
      args.config = argv[++i];
    } else if (key === '--output' || key === '-o') {
      args.output = argv[++i];
    }
  }
  return args;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function fileToDataUri(filePath) {
  if (!filePath || typeof filePath !== 'string' || !fs.existsSync(filePath)) {
    return null;
  }
  const ext = path.extname(filePath).toLowerCase();
  const mimeMap = {
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.svg': 'image/svg+xml',
  };
  const mime = mimeMap[ext] || 'image/png';
  const buf = fs.readFileSync(filePath);
  return `data:${mime};base64,${buf.toString('base64')}`;
}

function detectBrowserBinary() {
  const envCandidates = [
    process.env.CHROME_BIN,
    process.env.EDGE_BIN,
    process.env.PUPPETEER_EXECUTABLE_PATH,
  ].filter(Boolean);

  for (const candidate of envCandidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  if (process.platform === 'win32') {
    const winCandidates = [
      'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
      'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
      'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
      'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
      path.join(os.homedir(), 'AppData\\Local\\Google\\Chrome\\Application\\chrome.exe'),
    ];
    for (const candidate of winCandidates) {
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }
  } else {
    const linuxNames = ['google-chrome', 'chromium', 'chromium-browser', 'microsoft-edge'];
    for (const name of linuxNames) {
      try {
        const resolved = execFileSync('which', [name], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
        if (resolved && fs.existsSync(resolved)) {
          return resolved;
        }
      } catch {
        // Ignore missing binary
      }
    }
    const linuxPaths = [
      '/usr/bin/google-chrome',
      '/usr/bin/chromium',
      '/usr/bin/chromium-browser',
      '/usr/bin/microsoft-edge',
    ];
    for (const candidate of linuxPaths) {
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }
  }

  return null;
}

function getDimensions(config) {
  const aspectRatio = config.aspectRatio === '16:9' ? '16:9' : '9:16';
  const width = Number(config.width) || (aspectRatio === '16:9' ? 1920 : 1080);
  const height = Number(config.height) || (aspectRatio === '16:9' ? 1080 : 1920);
  return { aspectRatio, width, height };
}

function buildBaseStyles(width, height, isLandscape) {
  return `
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      width: ${width}px;
      height: ${height}px;
      overflow: hidden;
      font-family: 'Segoe UI', 'Inter', 'Roboto', 'Noto Sans', Arial, sans-serif;
      background: #070913;
      color: #f8fafc;
      -webkit-font-smoothing: antialiased;
    }
    .canvas {
      position: relative;
      width: 100%;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: ${isLandscape ? '64px 110px' : '110px 64px'};
      background:
        radial-gradient(circle at 18% 18%, rgba(99, 102, 241, 0.30), transparent 45%),
        radial-gradient(circle at 82% 78%, rgba(236, 72, 153, 0.24), transparent 48%),
        radial-gradient(circle at 50% 50%, rgba(14, 165, 233, 0.14), transparent 60%),
        linear-gradient(155deg, #070913 0%, #0f172a 52%, #111827 100%);
    }
    .grid-bg {
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
      background-size: 48px 48px;
      mask-image: radial-gradient(circle at center, black 40%, transparent 90%);
      pointer-events: none;
    }
    .top-bar {
      position: absolute;
      top: ${isLandscape ? '42px' : '72px'};
      left: ${isLandscape ? '80px' : '64px'};
      right: ${isLandscape ? '80px' : '64px'};
      display: flex;
      justify-content: space-between;
      align-items: center;
      z-index: 2;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 12px 24px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.16);
      backdrop-filter: blur(14px);
      font-size: 22px;
      font-weight: 700;
      letter-spacing: 0.04em;
      color: #e2e8f0;
      text-transform: uppercase;
    }
    .pill-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: #22d3ee;
      box-shadow: 0 0 14px #22d3ee;
    }
    .footer-tag {
      position: absolute;
      bottom: ${isLandscape ? '38px' : '72px'};
      left: ${isLandscape ? '80px' : '64px'};
      right: ${isLandscape ? '80px' : '64px'};
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: rgba(203, 213, 225, 0.72);
      font-size: 20px;
      font-weight: 600;
      z-index: 2;
    }
  `;
}

function renderCommentStickerHtml(data, dims) {
  const isLandscape = dims.aspectRatio === '16:9';
  const author = escapeHtml(data.author || 'Khách hàng');
  const handle = escapeHtml(data.handle || '@nguoi_xem');
  const replyLabel = escapeHtml(data.replyLabel || 'Trả lời bình luận');
  const question = escapeHtml(data.text || data.question || data.hook || 'Sản phẩm này có điểm gì nổi bật?');
  const caption = escapeHtml(data.subtitle || data.narration || '');
  const likes = escapeHtml(data.likes || '2.4K');
  const sceneLabel = escapeHtml(data.sceneLabel || 'COMMENT HOOK');
  const initials = author.trim().slice(0, 2).toUpperCase() || 'KH';

  return `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<style>
  ${buildBaseStyles(dims.width, dims.height, isLandscape)}
  .card-wrap {
    position: relative;
    z-index: 2;
    width: ${isLandscape ? '1240px' : '920px'};
    display: flex;
    flex-direction: column;
    gap: 36px;
    align-items: center;
  }
  .tiktok-bubble {
    width: 100%;
    background: #ffffff;
    color: #0f172a;
    border-radius: 40px;
    padding: ${isLandscape ? '44px 52px' : '52px 48px'};
    box-shadow:
      0 32px 80px rgba(0, 0, 0, 0.55),
      0 0 0 2px rgba(255, 255, 255, 0.9),
      0 0 60px rgba(244, 63, 94, 0.25);
    position: relative;
  }
  .tiktok-bubble::after {
    content: '';
    position: absolute;
    bottom: -24px;
    left: 84px;
    width: 48px;
    height: 48px;
    background: #ffffff;
    transform: rotate(45deg);
    border-radius: 6px;
  }
  .reply-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: #fff1f2;
    color: #e11d48;
    padding: 10px 22px;
    border-radius: 999px;
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 28px;
  }
  .author-row {
    display: flex;
    align-items: center;
    gap: 22px;
    margin-bottom: 26px;
  }
  .avatar {
    width: 82px;
    height: 82px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f43f5e, #8b5cf6);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 800;
    flex-shrink: 0;
  }
  .author-meta {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .author-name {
    font-size: 30px;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .verified {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #0ea5e9;
    color: #ffffff;
    font-size: 16px;
    font-weight: 900;
  }
  .author-handle {
    font-size: 24px;
    color: #64748b;
    font-weight: 600;
  }
  .question-text {
    font-size: ${isLandscape ? '44px' : '48px'};
    line-height: 1.32;
    font-weight: 800;
    color: #0f172a;
    letter-spacing: -0.015em;
  }
  .meta-bar {
    margin-top: 28px;
    display: flex;
    align-items: center;
    gap: 28px;
    color: #64748b;
    font-size: 24px;
    font-weight: 700;
  }
  .narration-box {
    width: 100%;
    margin-top: 18px;
    padding: 30px 38px;
    border-radius: 28px;
    background: rgba(15, 23, 42, 0.72);
    border: 1px solid rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(18px);
    font-size: ${isLandscape ? '30px' : '32px'};
    line-height: 1.45;
    color: #e2e8f0;
    font-weight: 600;
  }
</style>
</head>
<body>
  <div class="canvas">
    <div class="grid-bg"></div>
    <div class="top-bar">
      <div class="pill"><span class="pill-dot"></span>${sceneLabel}</div>
      <div class="pill">TikTok Q&amp;A</div>
    </div>

    <div class="card-wrap">
      <div class="tiktok-bubble">
        <div class="reply-badge">💬 ${replyLabel}</div>
        <div class="author-row">
          <div class="avatar">${initials}</div>
          <div class="author-meta">
            <div class="author-name">${author} <span class="verified">✓</span></div>
            <div class="author-handle">${handle}</div>
          </div>
        </div>
        <div class="question-text">“${question}”</div>
        <div class="meta-bar">
          <span>❤️ ${likes}</span>
          <span>•</span>
          <span>Đang trả lời trực tiếp</span>
        </div>
      </div>
      ${caption ? `<div class="narration-box">${caption}</div>` : ''}
    </div>

    <div class="footer-tag">
      <span>Motion Graphics Engine</span>
      <span>60 FPS Studio</span>
    </div>
  </div>
</body>
</html>`;
}

function renderRankingBoardHtml(data, dims) {
  const isLandscape = dims.aspectRatio === '16:9';
  const title = escapeHtml(data.title || 'BẢNG XẾP HẠNG NỔI BẬT');
  const subtitle = escapeHtml(data.subtitle || 'Đánh giá chi tiết theo tiêu chí thực tế');
  const sceneLabel = escapeHtml(data.sceneLabel || 'TIER LIST');
  const defaultItems = [
    { tier: 'S', name: 'Hiệu năng & Thiết kế vượt trội', detail: 'Tối ưu trải nghiệm hàng ngày', highlight: true },
    { tier: 'A', name: 'Độ bền & Hoàn thiện cao cấp', detail: 'Vật liệu chắc chắn, sắc nét', highlight: false },
    { tier: 'B', name: 'Chi phí hợp lý trong phân khúc', detail: 'Tiết kiệm dài hạn', highlight: false },
    { tier: 'C', name: 'Dễ dàng làm quen & sử dụng', detail: 'Phù hợp người mới', highlight: false },
  ];
  const items = Array.isArray(data.items) && data.items.length > 0 ? data.items.slice(0, 5) : defaultItems;

  const tierColors = {
    S: 'linear-gradient(135deg, #f43f5e, #fb7185)',
    A: 'linear-gradient(135deg, #f97316, #fb923c)',
    B: 'linear-gradient(135deg, #eab308, #facc15)',
    C: 'linear-gradient(135deg, #10b981, #34d399)',
    D: 'linear-gradient(135deg, #0ea5e9, #38bdf8)',
  };

  const rowsHtml = items.map((item, idx) => {
    const tier = escapeHtml(item.tier || String(idx + 1));
    const badgeBg = tierColors[tier.toUpperCase()] || 'linear-gradient(135deg, #6366f1, #8b5cf6)';
    const name = escapeHtml(item.name || item.title || `Hạng mục ${idx + 1}`);
    const detail = escapeHtml(item.detail || item.description || '');
    const isHighlight = Boolean(item.highlight || idx === 0);

    return `
      <div class="tier-row ${isHighlight ? 'is-highlight' : ''}">
        <div class="tier-badge" style="background: ${badgeBg};">${tier}</div>
        <div class="tier-content">
          <div class="tier-name">${name}</div>
          ${detail ? `<div class="tier-detail">${detail}</div>` : ''}
        </div>
        ${isHighlight ? '<div class="top-tag">TOP PICK</div>' : ''}
      </div>
    `;
  }).join('');

  return `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<style>
  ${buildBaseStyles(dims.width, dims.height, isLandscape)}
  .board {
    position: relative;
    z-index: 2;
    width: ${isLandscape ? '1380px' : '940px'};
    background: rgba(15, 23, 42, 0.82);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 36px;
    padding: ${isLandscape ? '44px 52px' : '54px 42px'};
    box-shadow: 0 36px 90px rgba(0, 0, 0, 0.55);
    backdrop-filter: blur(20px);
  }
  .board-head {
    margin-bottom: 34px;
  }
  .board-title {
    font-size: ${isLandscape ? '48px' : '50px'};
    font-weight: 900;
    letter-spacing: -0.02em;
    background: linear-gradient(90deg, #ffffff 0%, #93c5fd 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 10px;
  }
  .board-sub {
    font-size: 26px;
    color: #94a3b8;
    font-weight: 600;
  }
  .rows {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
  .tier-row {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 20px 24px;
    border-radius: 24px;
    background: rgba(30, 41, 59, 0.72);
    border: 1px solid rgba(255, 255, 255, 0.08);
  }
  .tier-row.is-highlight {
    background: rgba(56, 189, 248, 0.12);
    border: 1.5px solid rgba(56, 189, 248, 0.55);
    box-shadow: 0 12px 32px rgba(14, 165, 233, 0.18);
  }
  .tier-badge {
    width: 84px;
    height: 84px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    font-weight: 900;
    color: #ffffff;
    flex-shrink: 0;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.3);
  }
  .tier-content {
    flex: 1;
    min-width: 0;
  }
  .tier-name {
    font-size: ${isLandscape ? '30px' : '32px'};
    font-weight: 800;
    color: #f8fafc;
    margin-bottom: 4px;
  }
  .tier-detail {
    font-size: 22px;
    color: #cbd5e1;
    font-weight: 500;
  }
  .top-tag {
    padding: 10px 18px;
    border-radius: 999px;
    background: #22d3ee;
    color: #082f49;
    font-size: 18px;
    font-weight: 900;
    letter-spacing: 0.05em;
  }
</style>
</head>
<body>
  <div class="canvas">
    <div class="grid-bg"></div>
    <div class="top-bar">
      <div class="pill"><span class="pill-dot"></span>${sceneLabel}</div>
      <div class="pill">RANKING BOARD</div>
    </div>

    <div class="board">
      <div class="board-head">
        <div class="board-title">${title}</div>
        <div class="board-sub">${subtitle}</div>
      </div>
      <div class="rows">${rowsHtml}</div>
    </div>

    <div class="footer-tag">
      <span>Hypit Ranking Layout</span>
      <span>60 FPS Studio</span>
    </div>
  </div>
</body>
</html>`;
}

function renderProductSpecHtml(data, dims) {
  const isLandscape = dims.aspectRatio === '16:9';
  const productName = escapeHtml(data.productName || data.title || 'Sản Phẩm Cao Cấp');
  const sku = escapeHtml(data.sku || '3D-SPEC');
  const widthMm = Number(data.widthMm || 0);
  const heightMm = Number(data.heightMm || 0);
  const depthMm = Number(data.depthMm || 0);
  const hasDimensions = widthMm > 0 || heightMm > 0 || depthMm > 0;
  const dimensionText = hasDimensions
    ? `${widthMm || '-'} × ${heightMm || '-'} × ${depthMm || '-'} mm`
    : escapeHtml(data.dimensionText || 'Kích thước chuẩn 1:1');
  const description = escapeHtml(data.description || data.subtitle || 'Thiết kế tinh gọn, hoàn thiện chính xác từng chi tiết.');
  const usps = Array.isArray(data.usps) && data.usps.length > 0
    ? data.usps.slice(0, 4)
    : ['Chuẩn tỷ lệ 3D', 'Hoàn thiện cao cấp', 'Tối ưu trải nghiệm'];
  const sceneLabel = escapeHtml(data.sceneLabel || 'PRODUCT SPEC');
  const imgUri = fileToDataUri(data.imagePath);

  const uspHtml = usps.map((usp) => `<div class="usp-badge">✦ ${escapeHtml(usp)}</div>`).join('');

  return `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<style>
  ${buildBaseStyles(dims.width, dims.height, isLandscape)}
  .spec-card {
    position: relative;
    z-index: 2;
    width: ${isLandscape ? '1400px' : '940px'};
    background: rgba(15, 23, 42, 0.84);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 40px;
    padding: ${isLandscape ? '48px 56px' : '56px 46px'};
    display: ${isLandscape ? 'grid' : 'flex'};
    ${isLandscape ? 'grid-template-columns: 440px 1fr; gap: 48px; align-items: center;' : 'flex-direction: column; gap: 36px;'}
    box-shadow: 0 36px 90px rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(22px);
  }
  .visual-stage {
    position: relative;
    width: 100%;
    height: ${isLandscape ? '460px' : '480px'};
    border-radius: 30px;
    background:
      radial-gradient(circle at 50% 45%, rgba(56, 189, 248, 0.25), rgba(15, 23, 42, 0.9) 70%);
    border: 1px solid rgba(56, 189, 248, 0.35);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px;
    overflow: hidden;
  }
  .visual-stage img {
    max-width: 85%;
    max-height: 75%;
    object-fit: contain;
    filter: drop-shadow(0 24px 36px rgba(0, 0, 0, 0.55));
  }
  .cube-placeholder {
    width: 180px;
    height: 180px;
    border-radius: 32px;
    background: linear-gradient(135deg, #38bdf8, #818cf8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 64px;
    font-weight: 900;
    color: #ffffff;
    box-shadow: 0 24px 50px rgba(56, 189, 248, 0.35);
  }
  .dim-ruler {
    margin-top: 22px;
    padding: 10px 22px;
    border-radius: 999px;
    background: rgba(8, 47, 73, 0.85);
    border: 1px solid rgba(56, 189, 248, 0.55);
    color: #7dd3fc;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 0.04em;
  }
  .info-col {
    display: flex;
    flex-direction: column;
    gap: 24px;
  }
  .sku-tag {
    display: inline-flex;
    align-self: flex-start;
    padding: 8px 18px;
    border-radius: 999px;
    background: rgba(99, 102, 241, 0.22);
    border: 1px solid rgba(129, 140, 248, 0.45);
    color: #c7d2fe;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: 0.06em;
  }
  .prod-title {
    font-size: ${isLandscape ? '48px' : '52px'};
    font-weight: 900;
    line-height: 1.18;
    color: #ffffff;
  }
  .prod-desc {
    font-size: 26px;
    line-height: 1.45;
    color: #cbd5e1;
    font-weight: 500;
  }
  .usp-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 6px;
  }
  .usp-badge {
    padding: 14px 22px;
    border-radius: 18px;
    background: rgba(16, 185, 129, 0.14);
    border: 1px solid rgba(52, 211, 153, 0.4);
    color: #6ee7b7;
    font-size: 22px;
    font-weight: 700;
  }
</style>
</head>
<body>
  <div class="canvas">
    <div class="grid-bg"></div>
    <div class="top-bar">
      <div class="pill"><span class="pill-dot"></span>${sceneLabel}</div>
      <div class="pill">3D DIMENSIONS</div>
    </div>

    <div class="spec-card">
      <div class="visual-stage">
        ${imgUri ? `<img src="${imgUri}" alt="${productName}" />` : '<div class="cube-placeholder">3D</div>'}
        <div class="dim-ruler">📏 ${dimensionText}</div>
      </div>
      <div class="info-col">
        <div class="sku-tag">SKU: ${sku}</div>
        <div class="prod-title">${productName}</div>
        <div class="prod-desc">${description}</div>
        <div class="usp-grid">${uspHtml}</div>
      </div>
    </div>

    <div class="footer-tag">
      <span>Product Spec Sheet</span>
      <span>60 FPS Studio</span>
    </div>
  </div>
</body>
</html>`;
}

function renderBoxCaptionHtml(data, dims) {
  const isLandscape = dims.aspectRatio === '16:9';
  const sceneLabel = escapeHtml(data.sceneLabel || 'KEY TAKEAWAY');
  const title = escapeHtml(data.title || 'Điểm Nhấn Quan Trọng');
  const subtitle = escapeHtml(data.subtitle || data.text || data.narration || '');
  const tags = Array.isArray(data.tags) && data.tags.length > 0 ? data.tags.slice(0, 3) : [];
  const tagsHtml = tags.map((t) => `<span class="tag-chip">#${escapeHtml(t)}</span>`).join('');

  return `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<style>
  ${buildBaseStyles(dims.width, dims.height, isLandscape)}
  .hero-box {
    position: relative;
    z-index: 2;
    width: ${isLandscape ? '1360px' : '920px'};
    padding: ${isLandscape ? '64px 72px' : '72px 54px'};
    border-radius: 42px;
    background: rgba(15, 23, 42, 0.80);
    border: 1.5px solid rgba(255, 255, 255, 0.18);
    box-shadow:
      0 40px 100px rgba(0, 0, 0, 0.6),
      inset 0 1px 0 rgba(255, 255, 255, 0.16);
    backdrop-filter: blur(24px);
    display: flex;
    flex-direction: column;
    gap: 30px;
  }
  .accent-bar {
    width: 110px;
    height: 10px;
    border-radius: 999px;
    background: linear-gradient(90deg, #22d3ee, #818cf8, #f472b6);
  }
  .hero-title {
    font-size: ${isLandscape ? '60px' : '64px'};
    line-height: 1.22;
    font-weight: 900;
    letter-spacing: -0.02em;
    color: #ffffff;
  }
  .hero-sub {
    font-size: ${isLandscape ? '34px' : '36px'};
    line-height: 1.5;
    color: #cbd5e1;
    font-weight: 500;
  }
  .tag-row {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-top: 8px;
  }
  .tag-chip {
    padding: 10px 22px;
    border-radius: 999px;
    background: rgba(34, 211, 238, 0.14);
    border: 1px solid rgba(34, 211, 238, 0.35);
    color: #67e8f9;
    font-size: 22px;
    font-weight: 700;
  }
</style>
</head>
<body>
  <div class="canvas">
    <div class="grid-bg"></div>
    <div class="top-bar">
      <div class="pill"><span class="pill-dot"></span>${sceneLabel}</div>
      <div class="pill">MOTION CARD</div>
    </div>

    <div class="hero-box">
      <div class="accent-bar"></div>
      <div class="hero-title">${title}</div>
      ${subtitle ? `<div class="hero-sub">${subtitle}</div>` : ''}
      ${tagsHtml ? `<div class="tag-row">${tagsHtml}</div>` : ''}
    </div>

    <div class="footer-tag">
      <span>HTML5 Motion Graphics</span>
      <span>60 FPS Studio</span>
    </div>
  </div>
</body>
</html>`;
}

function buildHtmlForTemplate(template, data, dims) {
  switch (template) {
    case 'comment_sticker':
      return renderCommentStickerHtml(data, dims);
    case 'ranking_board':
      return renderRankingBoardHtml(data, dims);
    case 'product_spec':
      return renderProductSpecHtml(data, dims);
    case 'box_caption':
    default:
      return renderBoxCaptionHtml(data, dims);
  }
}

async function renderHtmlToPngWithOptionalNodeDriver(htmlPath, pngPath, dims) {
  // Ưu tiên puppeteer hoặc playwright nếu hệ thống đã cài sẵn
  try {
    const puppeteer = await import('puppeteer');
    const browser = await puppeteer.default.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    });
    try {
      const page = await browser.newPage();
      await page.setViewport({ width: dims.width, height: dims.height, deviceScaleFactor: 1 });
      await page.goto(pathToFileURL(htmlPath).href, { waitUntil: 'load' });
      await page.screenshot({ path: pngPath, type: 'png' });
      return true;
    } finally {
      await browser.close();
    }
  } catch {
    // Puppeteer không có sẵn, thử playwright
  }

  try {
    const playwright = await import('playwright');
    const browser = await playwright.chromium.launch({ headless: true });
    try {
      const page = await browser.newPage({ viewport: { width: dims.width, height: dims.height } });
      await page.goto(pathToFileURL(htmlPath).href, { waitUntil: 'load' });
      await page.screenshot({ path: pngPath, type: 'png' });
      return true;
    } finally {
      await browser.close();
    }
  } catch {
    // Playwright không có sẵn, chuyển sang CLI trình duyệt hệ thống
  }

  return false;
}

function renderHtmlToPngWithBrowserCli(browserBin, htmlPath, pngPath, dims) {
  const fileUrl = pathToFileURL(htmlPath).href;
  const userDataDir = fs.mkdtempSync(path.join(os.tmpdir(), 'motion_gfx_profile_'));

  const commonArgs = [
    '--disable-gpu',
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--hide-scrollbars',
    `--user-data-dir=${userDataDir}`,
    `--window-size=${dims.width},${dims.height}`,
    `--screenshot=${pngPath}`,
    fileUrl,
  ];

  try {
    let result = spawnSync(browserBin, ['--headless=new', ...commonArgs], {
      stdio: 'pipe',
      timeout: 30000,
    });

    if (result.status !== 0 || !fs.existsSync(pngPath) || fs.statSync(pngPath).size === 0) {
      result = spawnSync(browserBin, ['--headless', ...commonArgs], {
        stdio: 'pipe',
        timeout: 30000,
      });
    }

    if (result.status !== 0 || !fs.existsSync(pngPath) || fs.statSync(pngPath).size === 0) {
      const stderr = result.stderr ? result.stderr.toString('utf8') : '';
      throw new Error(`Headless browser screenshot failed (code ${result.status}): ${stderr}`);
    }
  } finally {
    fs.rmSync(userDataDir, { recursive: true, force: true });
  }
}

function runFfmpeg(ffmpegBinary, nicePriority, args) {
  const isLinux = process.platform === 'linux';
  const cmd = isLinux ? 'nice' : ffmpegBinary;
  const cmdArgs = isLinux ? ['-n', String(nicePriority), ffmpegBinary, ...args] : args;

  const result = spawnSync(cmd, cmdArgs, { stdio: 'pipe', timeout: 180000 });
  if (result.status !== 0) {
    const stderr = result.stderr ? result.stderr.toString('utf8') : '';
    throw new Error(`FFmpeg failed (code ${result.status}): ${stderr}`);
  }
}

function encodePngToAnimatedMp4(pngPath, outputMp4Path, config, dims) {
  const fps = Number(config.fps) || 60;
  const durationMs = Math.max(1000, Number(config.durationMs) || 5000);
  const durationSec = Math.max(1.0, Number((durationMs / 1000).toFixed(3)));
  const totalFrames = Math.max(fps, Math.ceil(durationSec * fps));
  const maxThreads = Math.max(1, Math.min(8, Number(config.maxThreads) || 6));
  const nicePriority = Number(config.nicePriority) || 10;
  const ffmpegBinary = config.ffmpegBinary || 'ffmpeg';
  const resolution = `${dims.width}x${dims.height}`;
  const upscaleWidth = process.platform === 'linux' ? 3240 : 2160;
  const cameraMotion = String(config.cameraMotion || 'zoom_in');

  let zoompanExpr;
  if (cameraMotion === 'zoom_out') {
    zoompanExpr = `scale=${upscaleWidth}:-1,zoompan=z='if(eq(on,1),1.12,max(1.0,zoom-0.00035))':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=${totalFrames}:s=${resolution}:fps=${fps},setsar=1`;
  } else if (cameraMotion === 'pan_right') {
    zoompanExpr = `scale=${upscaleWidth}:-1,zoompan=z='1.08':x='(iw-iw/zoom)*(on/${totalFrames})':y='(ih-ih/zoom)/2':d=${totalFrames}:s=${resolution}:fps=${fps},setsar=1`;
  } else if (cameraMotion === 'pan_left') {
    zoompanExpr = `scale=${upscaleWidth}:-1,zoompan=z='1.08':x='(iw-iw/zoom)*(1-on/${totalFrames})':y='(ih-ih/zoom)/2':d=${totalFrames}:s=${resolution}:fps=${fps},setsar=1`;
  } else {
    zoompanExpr = `scale=${upscaleWidth}:-1,zoompan=z='min(zoom+0.00035,1.12)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=${totalFrames}:s=${resolution}:fps=${fps},setsar=1`;
  }

  const fadeOutStart = Math.max(0.2, durationSec - 0.35).toFixed(3);
  const vf = `${zoompanExpr},fade=t=in:st=0:d=0.30,fade=t=out:st=${fadeOutStart}:d=0.30,format=yuv420p`;

  const args = [
    '-y',
    '-threads', String(maxThreads),
    '-loop', '1',
    '-i', pngPath,
    '-vf', vf,
    '-t', durationSec.toFixed(3),
    '-r', String(fps),
    '-c:v', 'libx264',
    '-preset', 'veryfast',
    '-crf', '20',
    '-x264-params', `threads=${maxThreads}`,
    '-pix_fmt', 'yuv420p',
    '-an',
    outputMp4Path,
  ];

  runFfmpeg(ffmpegBinary, nicePriority, args);
}

function encodeFallbackSolidMp4(outputMp4Path, config, dims) {
  const fps = Number(config.fps) || 60;
  const durationMs = Math.max(1000, Number(config.durationMs) || 5000);
  const durationSec = Math.max(1.0, Number((durationMs / 1000).toFixed(3)));
  const maxThreads = Math.max(1, Math.min(8, Number(config.maxThreads) || 6));
  const nicePriority = Number(config.nicePriority) || 10;
  const ffmpegBinary = config.ffmpegBinary || 'ffmpeg';

  const args = [
    '-y',
    '-threads', String(maxThreads),
    '-f', 'lavfi',
    '-i', `color=c=#0f172a:s=${dims.width}x${dims.height}:r=${fps}:d=${durationSec.toFixed(3)}`,
    '-vf', 'format=yuv420p',
    '-c:v', 'libx264',
    '-preset', 'veryfast',
    '-crf', '22',
    '-an',
    outputMp4Path,
  ];

  runFfmpeg(ffmpegBinary, nicePriority, args);
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.config || !args.output) {
    console.error('Usage: node render_motion_graphics.mjs --config <config.json> --output <output.mp4|png>');
    process.exit(1);
  }

  const configPath = path.resolve(args.config);
  const outputPath = path.resolve(args.output);
  if (!fs.existsSync(configPath)) {
    throw new Error(`Config file not found: ${configPath}`);
  }

  const rawConfig = fs.readFileSync(configPath, 'utf8').replace(/^\uFEFF/, '');
  const config = JSON.parse(rawConfig);
  const template = String(config.template || 'box_caption');
  const data = config.data || {};
  const dims = getDimensions(config);

  fs.mkdirSync(path.dirname(outputPath), { recursive: true });

  const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'motion_gfx_'));
  const htmlPath = path.join(workDir, 'template.html');
  const pngPath = outputPath.toLowerCase().endsWith('.png')
    ? outputPath
    : path.join(workDir, 'frame.png');

  try {
    const html = buildHtmlForTemplate(template, data, dims);
    fs.writeFileSync(htmlPath, html, 'utf8');

    let renderedPng = await renderHtmlToPngWithOptionalNodeDriver(htmlPath, pngPath, dims);
    if (!renderedPng) {
      const browserBin = detectBrowserBinary();
      if (browserBin) {
        renderHtmlToPngWithBrowserCli(browserBin, htmlPath, pngPath, dims);
        renderedPng = true;
      }
    }

    if (outputPath.toLowerCase().endsWith('.png')) {
      if (!renderedPng || !fs.existsSync(outputPath)) {
        throw new Error('Could not render PNG: neither puppeteer/playwright nor Chrome/Edge binary found.');
      }
      console.log(JSON.stringify({ ok: true, mode: 'png', template, output: outputPath }));
      return;
    }

    if (renderedPng && fs.existsSync(pngPath)) {
      encodePngToAnimatedMp4(pngPath, outputPath, config, dims);
    } else {
      encodeFallbackSolidMp4(outputPath, config, dims);
    }

    if (!fs.existsSync(outputPath) || fs.statSync(outputPath).size === 0) {
      throw new Error(`Output video was not created: ${outputPath}`);
    }

    console.log(JSON.stringify({ ok: true, mode: 'mp4', template, output: outputPath }));
  } finally {
    fs.rmSync(workDir, { recursive: true, force: true });
  }
}

main().catch((err) => {
  console.error(err?.stack || err?.message || String(err));
  process.exit(1);
});
