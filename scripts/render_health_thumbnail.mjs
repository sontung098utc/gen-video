import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

/**
 * Render ảnh bìa Photographic chuẩn 1080x1920 theo đúng nhận diện kênh "Sống Khỏe Mỗi Ngày":
 *  - Nền trên: Ánh nắng xanh lá tươi sáng tràn đầy sức sống (sunny green bokeh).
 *  - Logo trên cùng: Badge trắng bo tròn + 2 lá xanh + chữ "SỐNG KHỎE MỖI NGÀY".
 *  - Phần trên-giữa: Tiêu đề IN HOA cực to, màu VÀNG RỰC & TRẮNG có viền đen dày nổi bật.
 *  - Dải ruy-băng đỏ nhấn mạnh ngay dưới tiêu đề chính.
 *  - Nửa dưới: Ảnh chụp Photographic thực tế sắc nét, tươi sáng.
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

function splitHeadlineForImpact(rawHeadline) {
    const words = String(rawHeadline || '').trim().toUpperCase().split(/\s+/).filter(Boolean);
    if (words.length <= 2) {
        return { top: words.join(' '), bottom: '' };
    }
    const splitAt = Math.min(3, Math.ceil(words.length / 2));
    return {
        top: words.slice(0, splitAt).join(' '),
        bottom: words.slice(splitAt).join(' '),
    };
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
        headline = '5 SAI LẦM KHI CHĂM SÓC SỨC KHỎE',
        channelName = 'Sống Khỏe Mỗi Ngày',
        badgeRed = '',
    } = payload;

    let bgDataUri = '';
    if (bgImagePath && fs.existsSync(bgImagePath)) {
        const ext = path.extname(bgImagePath).toLowerCase();
        const mime = ext === '.png' ? 'image/png' : 'image/jpeg';
        const b64 = fs.readFileSync(bgImagePath).toString('base64');
        bgDataUri = `data:${mime};base64,${b64}`;
    }

    const escapeHtml = (str) =>
        String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');

    const parts = splitHeadlineForImpact(headline);
    const wordCount = String(headline || '').trim().split(/\s+/).filter(Boolean).length;
    const yellowSize = wordCount > 7 ? 84 : (wordCount > 5 ? 98 : 114);
    const whiteSize = wordCount > 7 ? 72 : (wordCount > 5 ? 84 : 94);
    const safeTop = escapeHtml(parts.top);
    const safeBottom = escapeHtml(parts.bottom);
    const safeChannel = escapeHtml(channelName.toUpperCase());
    const safeBadgeRed = escapeHtml((badgeRed || '').toUpperCase());

    const html = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body {
    width: 1080px;
    height: 1920px;
    overflow: hidden;
    font-family: 'Montserrat', 'Segoe UI', 'Arial', sans-serif;
    position: relative;
    background: linear-gradient(180deg, #dcfce7 0%, #86efac 32%, #166534 100%);
  }
  .bg-photo {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: brightness(1.06) contrast(1.06) saturate(1.15);
  }
  /* Lớp phủ ánh nắng xanh tươi ở nửa trên để chữ vàng-trắng nổi bật rực rỡ, giữ nửa dưới ảnh chụp sắc nét */
  .sunny-top-glow {
    position: absolute;
    inset: 0;
    background: linear-gradient(
      180deg,
      rgba(22, 101, 52, 0.78) 0%,
      rgba(34, 197, 94, 0.56) 26%,
      rgba(134, 239, 172, 0.28) 46%,
      rgba(0, 0, 0, 0.00) 60%,
      rgba(0, 0, 0, 0.14) 100%
    );
  }
  .sunflare {
    position: absolute;
    top: -120px;
    left: -80px;
    width: 560px;
    height: 560px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(254, 249, 195, 0.72) 0%, rgba(254, 249, 195, 0) 70%);
    pointer-events: none;
  }
  .sunflare-right {
    position: absolute;
    top: 40px;
    right: -120px;
    width: 480px;
    height: 480px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(187, 247, 208, 0.55) 0%, rgba(187, 247, 208, 0) 70%);
    pointer-events: none;
  }
  .header-stack {
    position: relative;
    z-index: 10;
    padding: 68px 48px 0 48px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 24px;
  }
  .brand-pill {
    display: inline-flex;
    align-items: center;
    gap: 14px;
    background: #ffffff;
    color: #14532d;
    padding: 14px 34px;
    border-radius: 999px;
    font-size: 32px;
    font-weight: 900;
    letter-spacing: 1px;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.24);
    border: 4px solid #86efac;
  }
  .leaf-icon {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
  }
  .headline-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    width: 100%;
  }
  .headline-yellow {
    color: #fde047;
    font-size: ${yellowSize}px;
    font-weight: 900;
    line-height: 1.12;
    paint-order: stroke fill;
    -webkit-text-stroke: 16px #062c16;
    text-shadow:
      0 8px 0 #062c16,
      0 14px 28px rgba(0, 0, 0, 0.55);
    letter-spacing: -0.5px;
  }
  .headline-white {
    color: #ffffff;
    font-size: ${whiteSize}px;
    font-weight: 900;
    line-height: 1.16;
    paint-order: stroke fill;
    -webkit-text-stroke: 14px #062c16;
    text-shadow:
      0 7px 0 #062c16,
      0 12px 24px rgba(0, 0, 0, 0.52);
    letter-spacing: -0.3px;
  }
  .red-ribbon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(180deg, #ef4444 0%, #b91c1c 100%);
    color: #ffffff;
    padding: 16px 40px;
    border-radius: 18px;
    font-size: 40px;
    font-weight: 900;
    letter-spacing: 0.8px;
    border: 4px solid #fef08a;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
    margin-top: 6px;
  }
</style>
</head>
<body>
  ${bgDataUri ? `<img class="bg-photo" src="${bgDataUri}" />` : ''}
  <div class="sunny-top-glow"></div>
  <div class="sunflare"></div>
  <div class="sunflare-right"></div>

  <div class="header-stack">
    <div class="brand-pill">
      <svg class="leaf-icon" viewBox="0 0 24 24" fill="none">
        <path d="M17 8C8 10 5.9 16.17 3.82 21.34L5.71 22L6.66 19.7C7.14 19.87 7.64 20 8 20C19 20 22 3 22 3C21 5 14 5.25 9 6.25C4 7.25 2 11.5 2 13.5C2 15.5 3.75 17.25 3.75 17.25C7 8 17 8 17 8Z" fill="#16a34a"/>
      </svg>
      <span>${safeChannel}</span>
    </div>

    <div class="headline-wrap">
      <div class="headline-yellow">${safeTop}</div>
      ${safeBottom ? `<div class="headline-white">${safeBottom}</div>` : ''}
    </div>

    ${safeBadgeRed ? `<div class="red-ribbon">${safeBadgeRed}</div>` : ''}
  </div>
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
