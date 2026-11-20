import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

/**
 * Render ảnh bìa Photographic chuẩn 1080x1920 cho kênh "Sống Khỏe Mỗi Ngày".
 * Dùng Chromium render HTML/CSS Typography tiếng Việt sắc nét 100% không sai dấu.
 */
async function main() {
    const payloadPath = process.argv[2];
    if (!payloadPath || !fs.existsSync(payloadPath)) {
        console.error('Missing payload JSON file path');
        process.exit(1);
    }

    const payload = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));
    const {
        bgImagePath = '',
        outputPath,
        headline = 'SỐNG KHỎE MỖI NGÀY',
        channelName = 'Sống Khỏe Mỗi Ngày',
        subtitle = 'Kiến Thức Sức Khỏe Chính Thống',
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

    const safeHeadline = escapeHtml(headline.toUpperCase());
    const safeChannel = escapeHtml(channelName.toUpperCase());
    const safeSubtitle = escapeHtml(subtitle);

    const html = `<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8" />
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    width: 1080px;
    height: 1920px;
    overflow: hidden;
    font-family: 'Segoe UI', 'Roboto', 'Arial', sans-serif;
    position: relative;
    background: linear-gradient(160deg, #064e3b 0%, #0f172a 100%);
  }
  .bg-photo {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    filter: contrast(1.06) saturate(1.08) brightness(0.92);
  }
  .vignette {
    position: absolute;
    inset: 0;
    background: linear-gradient(
      180deg,
      rgba(6, 40, 28, 0.72) 0%,
      rgba(0, 0, 0, 0.08) 32%,
      rgba(0, 0, 0, 0.18) 58%,
      rgba(4, 24, 18, 0.90) 88%,
      rgba(2, 16, 12, 0.96) 100%
    );
  }
  .top-bar {
    position: absolute;
    top: 84px;
    left: 64px;
    right: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .brand-pill {
    display: inline-flex;
    align-items: center;
    gap: 16px;
    background: rgba(16, 185, 129, 0.94);
    color: #ffffff;
    padding: 18px 34px;
    border-radius: 999px;
    font-size: 32px;
    font-weight: 800;
    letter-spacing: 1.5px;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35);
    border: 3px solid rgba(255, 255, 255, 0.45);
  }
  .brand-dot {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fef08a;
  }
  .bottom-content {
    position: absolute;
    left: 64px;
    right: 64px;
    bottom: 130px;
    display: flex;
    flex-direction: column;
    gap: 28px;
  }
  .accent-line {
    width: 120px;
    height: 10px;
    border-radius: 6px;
    background: linear-gradient(90deg, #facc15, #34d399);
  }
  .headline {
    color: #fef08a;
    font-size: 86px;
    font-weight: 900;
    line-height: 1.18;
    text-shadow:
      0 6px 0 rgba(0, 0, 0, 0.85),
      0 14px 36px rgba(0, 0, 0, 0.78);
    letter-spacing: -0.5px;
    word-break: break-word;
  }
  .subtitle-box {
    display: inline-flex;
    align-items: center;
    align-self: flex-start;
    background: rgba(255, 255, 255, 0.14);
    backdrop-filter: blur(8px);
    border-left: 8px solid #34d399;
    padding: 18px 30px;
    border-radius: 14px;
    color: #f8fafc;
    font-size: 34px;
    font-weight: 700;
  }
</style>
</head>
<body>
  ${bgDataUri ? `<img class="bg-photo" src="${bgDataUri}" />` : ''}
  <div class="vignette"></div>
  <div class="top-bar">
    <div class="brand-pill">
      <span class="brand-dot"></span>
      <span>${safeChannel}</span>
    </div>
  </div>
  <div class="bottom-content">
    <div class="accent-line"></div>
    <div class="headline">${safeHeadline}</div>
    <div class="subtitle-box">${safeSubtitle}</div>
  </div>
</body>
</html>`;

    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
        await page.setContent(html, { waitUntil: 'load' });
        await page.screenshot({
            path: outputPath,
            type: 'jpeg',
            quality: 92,
        });
    } finally {
        await browser.close();
    }
}

main().catch((err) => {
    console.error(err);
    process.exit(1);
});
