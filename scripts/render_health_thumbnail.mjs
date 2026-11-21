import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

/**
 * Render ảnh bìa chuẩn 1080x1920 học hỏi 100% phong cách Poster ChatGPT của kênh "Sống Khỏe Mỗi Ngày":
 *  1. Logo góc trên trái (vòng tròn lá xanh + chữ SỐNG KHỎE / MỖI NGÀY) trên nền ánh nắng xanh tươi.
 *  2. Ảnh Hero Photographic phía trên (kèm icon tam giác cảnh báo đỏ "!").
 *  3. Cụm 3 dải sơn quét cọ (Brush Stroke) nghiêng nhẹ cực bắt mắt:
 *     - Dải ĐỎ: chữ TRẮNG ("CẢNH BÁO!" hoặc từ nhấn)
 *     - Dải VÀNG: chữ XANH LÁ ĐẬM cực to (cụm từ chính, vd: "5 DẤU HIỆU")
 *     - Dải XANH RÊU ĐẬM: chữ TRẮNG (vế sau, vd: "TIỀN TIỂU ĐƯỜNG")
 *     - Dòng chữ ĐỎ nghiêng + đường gạch chân cong đỏ ("đừng chủ quan" / "người lớn tuổi hay bỏ qua")
 *  4. Hàng 4 ảnh tròn viền vàng minh họa các ý chính từ phân cảnh video.
 *  5. Sóng xanh lá tầng lớp + lá xanh tươi ở chân trang.
 */
function findBrowserBinary() {
    const candidates = process.platform === 'win32'
        ? [
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            path.join(os.homedir(), 'AppData\\Local\\Google\\Chrome\\Application\\chrome.exe'),
        ]
        : [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

    for (const bin of candidates) {
        if (fs.existsSync(bin)) {
            return bin;
        }
    }
    return null;
}

function toDataUri(filePath) {
    if (!filePath || !fs.existsSync(filePath)) return '';
    const ext = path.extname(filePath).toLowerCase();
    const mime = ext === '.png' ? 'image/png' : 'image/jpeg';
    const b64 = fs.readFileSync(filePath).toString('base64');
    return `data:${mime};base64,${b64}`;
}

function splitHeadlineForBrushes(rawHeadline) {
    const words = String(rawHeadline || '').trim().toUpperCase().split(/\s+/).filter(Boolean);
    if (words.length <= 2) {
        return { yellow: words.join(' '), green: '' };
    }
    const splitAt = words.length >= 5 ? 3 : Math.ceil(words.length / 2);
    return {
        yellow: words.slice(0, splitAt).join(' '),
        green: words.slice(splitAt).join(' '),
    };
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function main() {
    const payloadPath = process.argv[2];
    if (!payloadPath || !fs.existsSync(payloadPath)) {
        console.error('Missing payload JSON file path');
        process.exit(1);
    }

    const browserBin = findBrowserBinary();
    if (!browserBin) {
        console.error('Chrome/Edge/Chromium binary not found');
        process.exit(1);
    }

    const rawJson = fs.readFileSync(payloadPath, 'utf8').replace(/^\uFEFF/, '');
    const payload = JSON.parse(rawJson);
    const {
        bgImagePath = '',
        outputPath,
        headline = '5 DẤU HIỆU TIỀN TIỂU ĐƯỜNG',
        channelName = 'Sống Khỏe Mỗi Ngày',
        badgeRed = 'ĐỪNG CHỦ QUAN',
        circleImages = [],
    } = payload;

    const bgDataUri = toDataUri(bgImagePath);
    const circleUris = (Array.isArray(circleImages) ? circleImages : [])
        .map(toDataUri)
        .filter(Boolean)
        .slice(0, 4);

    const chWords = String(channelName).trim().toUpperCase().split(/\s+/);
    const chLine1 = escapeHtml(chWords.slice(0, 2).join(' '));
    const chLine2 = escapeHtml(chWords.slice(2).join(' ') || 'MỖI NGÀY');

    const parts = splitHeadlineForBrushes(headline);
    const safeHookRed = 'CẢNH BÁO!';
    const safeYellow = escapeHtml(parts.yellow);
    const safeGreen = escapeHtml(parts.green);
    const safeSubRed = escapeHtml((badgeRed || 'người lớn tuổi hay bỏ qua').toLowerCase());

    const html = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,800;0,900;1,800;1,900&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body {
    width: 1080px;
    height: 1920px;
    overflow: hidden;
    font-family: 'Montserrat', 'Segoe UI', 'Arial', sans-serif;
    position: relative;
    background: linear-gradient(180deg, #f0fdf4 0%, #ffffff 55%, #dcfce7 100%);
  }

  .hero-zone {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 920px;
    overflow: hidden;
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 50%, #fef9c3 100%);
  }
  .hero-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center top;
    filter: brightness(1.04) contrast(1.05) saturate(1.1);
  }
  .hero-fade {
    position: absolute;
    inset: 0;
    background: linear-gradient(
      180deg,
      rgba(255, 255, 255, 0.25) 0%,
      rgba(255, 255, 255, 0) 28%,
      rgba(255, 255, 255, 0.72) 78%,
      #ffffff 100%
    );
  }

  .top-logo {
    position: absolute;
    top: 38px;
    left: 38px;
    z-index: 20;
    display: flex;
    align-items: center;
    gap: 14px;
    background: rgba(255, 255, 255, 0.92);
    padding: 12px 24px 12px 14px;
    border-radius: 999px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
    border: 2px solid #bbf7d0;
  }
  .logo-badge {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #065f46);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 3px solid #fef08a;
  }
  .logo-text {
    display: flex;
    flex-direction: column;
    line-height: 1.08;
    color: #065f46;
    font-weight: 900;
    font-size: 28px;
    letter-spacing: 0.5px;
  }

  .warning-float {
    position: absolute;
    top: 230px;
    left: 56px;
    z-index: 18;
    width: 150px;
    height: 150px;
    filter: drop-shadow(0 12px 24px rgba(185, 28, 28, 0.45));
    transform: rotate(-8deg);
  }

  .brush-stack {
    position: absolute;
    top: 660px;
    left: 24px;
    right: 24px;
    z-index: 25;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    transform: rotate(-2.2deg);
  }
  .brush-row {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 20px 64px;
    min-width: 740px;
    max-width: 1010px;
    text-align: center;
  }
  .brush-red {
    background: linear-gradient(90deg, rgba(220,38,38,0) 0%, #dc2626 6%, #ef4444 50%, #dc2626 94%, rgba(220,38,38,0) 100%);
    clip-path: polygon(2% 10%, 98% 2%, 100% 35%, 97% 62%, 99% 92%, 3% 98%, 0% 65%, 2% 35%);
    padding: 18px 72px;
    min-width: 680px;
    box-shadow: 0 12px 24px rgba(185, 28, 28, 0.28);
  }
  .brush-red span {
    color: #ffffff;
    font-size: 92px;
    font-weight: 900;
    font-style: italic;
    line-height: 1.05;
    text-shadow: 0 5px 12px rgba(0, 0, 0, 0.35);
    letter-spacing: 1px;
  }

  .brush-yellow {
    background: linear-gradient(90deg, rgba(250,204,21,0) 0%, #fde047 5%, #fef08a 50%, #facc15 95%, rgba(250,204,21,0) 100%);
    clip-path: polygon(1% 4%, 99% 8%, 97% 42%, 100% 72%, 98% 96%, 2% 94%, 0% 62%, 3% 30%);
    padding: 22px 68px;
    min-width: 840px;
    margin-top: -6px;
  }
  .brush-yellow span {
    color: #064e3b;
    font-size: 108px;
    font-weight: 900;
    font-style: italic;
    line-height: 1.05;
    letter-spacing: -1px;
    text-shadow: 0 3px 0 rgba(255, 255, 255, 0.65);
  }

  .brush-green {
    background: linear-gradient(90deg, rgba(6,78,59,0) 0%, #065f46 5%, #047857 50%, #064e3b 95%, rgba(6,78,59,0) 100%);
    clip-path: polygon(2% 6%, 98% 3%, 100% 45%, 97% 75%, 99% 95%, 1% 97%, 3% 60%, 0% 28%);
    padding: 20px 64px;
    min-width: 880px;
    margin-top: -6px;
  }
  .brush-green span {
    color: #ffffff;
    font-size: 76px;
    font-weight: 900;
    font-style: italic;
    line-height: 1.08;
    text-shadow: 0 4px 10px rgba(0, 0, 0, 0.35);
  }

  .subline-wrap {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .subline-red {
    color: #dc2626;
    font-size: 54px;
    font-weight: 900;
    font-style: italic;
    line-height: 1.15;
    text-shadow: 0 2px 0 #ffffff, 0 4px 12px rgba(255, 255, 255, 0.9);
  }
  .subline-swoosh {
    width: 660px;
    height: 22px;
    margin-top: 4px;
  }

  .circles-row {
    position: absolute;
    bottom: 215px;
    left: 42px;
    right: 42px;
    z-index: 22;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 20px;
  }
  .circle-item {
    width: 228px;
    height: 228px;
    border-radius: 50%;
    border: 9px solid #fde047;
    box-shadow: 0 10px 26px rgba(6, 78, 59, 0.28);
    overflow: hidden;
    background: #ecfdf5;
    flex-shrink: 0;
  }
  .circle-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .footer-waves {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 220px;
    z-index: 15;
    pointer-events: none;
  }
  .footer-leaves {
    position: absolute;
    bottom: -10px;
    left: -15px;
    width: 260px;
    height: 260px;
    z-index: 26;
    pointer-events: none;
    filter: drop-shadow(0 8px 14px rgba(0, 0, 0, 0.25));
  }
</style>
</head>
<body>
  <div class="hero-zone">
    ${bgDataUri ? `<img class="hero-photo" src="${bgDataUri}" />` : ''}
    <div class="hero-fade"></div>
  </div>

  <div class="top-logo">
    <div class="logo-badge">
      <svg width="42" height="42" viewBox="0 0 24 24" fill="none">
        <path d="M17 8C8 10 5.9 16.17 3.82 21.34L5.71 22L6.66 19.7C7.14 19.87 7.64 20 8 20C19 20 22 3 22 3C21 5 14 5.25 9 6.25C4 7.25 2 11.5 2 13.5C2 15.5 3.75 17.25 3.75 17.25C7 8 17 8 17 8Z" fill="#fef08a"/>
      </svg>
    </div>
    <div class="logo-text">
      <span>${chLine1}</span>
      <span>${chLine2}</span>
    </div>
  </div>

  <svg class="warning-float" viewBox="0 0 120 110" fill="none">
    <path d="M60 6 L114 98 Q117 104 110 104 L10 104 Q3 104 6 98 Z" fill="#dc2626" stroke="#ffffff" stroke-width="6"/>
    <rect x="55" y="34" width="10" height="36" rx="5" fill="#ffffff"/>
    <circle cx="60" cy="84" r="6" fill="#ffffff"/>
  </svg>

  <div class="brush-stack">
    <div class="brush-row brush-red"><span>${safeHookRed}</span></div>
    <div class="brush-row brush-yellow"><span>${safeYellow}</span></div>
    ${safeGreen ? `<div class="brush-row brush-green"><span>${safeGreen}</span></div>` : ''}
    <div class="subline-wrap">
      <div class="subline-red">${safeSubRed}</div>
      <svg class="subline-swoosh" viewBox="0 0 600 24" fill="none">
        <path d="M10 18 Q300 2 590 12" stroke="#dc2626" stroke-width="8" stroke-linecap="round"/>
      </svg>
    </div>
  </div>

  ${circleUris.length > 0 ? `
  <div class="circles-row">
    ${circleUris.map((uri) => `<div class="circle-item"><img src="${uri}" /></div>`).join('')}
  </div>` : ''}

  <svg class="footer-waves" viewBox="0 0 1080 220" preserveAspectRatio="none">
    <path d="M0,120 C260,30 560,190 1080,85 L1080,220 L0,220 Z" fill="#22c55e" opacity="0.85"/>
    <path d="M0,155 C340,80 680,210 1080,125 L1080,220 L0,220 Z" fill="#15803d"/>
    <path d="M0,185 C420,145 780,220 1080,165 L1080,220 L0,220 Z" fill="#064e3b"/>
  </svg>

  <svg class="footer-leaves" viewBox="0 0 200 200" fill="none">
    <path d="M15,185 C20,105 85,45 165,35 C145,105 90,165 15,185 Z" fill="#22c55e"/>
    <path d="M5,195 C45,125 110,95 180,110 C135,165 70,190 5,195 Z" fill="#16a34a"/>
  </svg>
</body>
</html>`;

    const tmpHtml = path.join(os.tmpdir(), `skmn_cover_${Date.now()}.html`);
    fs.writeFileSync(tmpHtml, html, 'utf8');

    const absOut = path.resolve(outputPath);
    fs.mkdirSync(path.dirname(absOut), { recursive: true });

    const res = spawnSync(browserBin, [
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',
        '--hide-scrollbars',
        '--window-size=1080,1920',
        `--screenshot=${absOut}`,
        pathToFileURL(tmpHtml).href,
    ], { stdio: 'pipe', timeout: 45000 });

    try { fs.unlinkSync(tmpHtml); } catch {}

    if (res.status !== 0 || !fs.existsSync(absOut)) {
        console.error('Headless browser screenshot failed:', res.stderr?.toString() || '');
        process.exit(1);
    }
}

main();
