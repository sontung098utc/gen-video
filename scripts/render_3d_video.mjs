#!/usr/bin/env node
/**
 * Bộ kết xuất Video Sản Phẩm 3D (.glb) 60fps bằng Headless Chromium/Edge WebGL (<model-viewer>) + FFmpeg.
 * - Mỗi phân cảnh có góc máy 3D (orbit azimuth/elevation/zoom) và ánh sáng studio khác nhau hoàn toàn.
 * - Chống trùng lặp 100% trên TikTok Shop vì mỗi video là một cảnh quay 3D WebGL mới hoàn toàn.
 */

import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { spawn, spawnSync } from 'node:child_process';

function parseArgs(argv) {
    const args = {};
    for (let i = 2; i < argv.length; i += 2) {
        const key = argv[i]?.replace(/^--/, '');
        const val = argv[i + 1];
        if (key && val !== undefined) args[key] = val;
    }
    return args;
}

function findBrowserExecutable() {
    const candidates = process.platform === 'win32'
        ? [
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        ]
        : [
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/local/bin/chromium',
        ];
    return candidates.find((p) => fs.existsSync(p)) || null;
}

function buildStudioHtml(cfg) {
    const themes = {
        dark_gold: 'radial-gradient(circle at 50% 38%, #312e81 0%, #0f172a 58%, #020617 100%)',
        emerald_medical: 'radial-gradient(circle at 50% 38%, #065f46 0%, #0f172a 60%, #020617 100%)',
        royal_indigo: 'radial-gradient(circle at 50% 38%, #4c1d95 0%, #0f172a 60%, #020617 100%)',
        warm_amber: 'radial-gradient(circle at 50% 38%, #78350f 0%, #111827 60%, #020617 100%)',
    };
    const bg = themes[cfg.theme] || themes.dark_gold;
    const w = cfg.width;
    const h = cfg.height;
    const title = String(cfg.productName || 'Sản Phẩm 3D').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const badge = String(cfg.badgeText || '3D REAL-SCALE').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    return `<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body {
    width: ${w}px; height: ${h}px; overflow: hidden;
    background: ${bg};
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    color: #fff;
  }
  .stage {
    position: relative; width: 100%; height: 100%;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
  }
  .glow {
    position: absolute; width: ${Math.round(w * 0.72)}px; height: ${Math.round(w * 0.72)}px;
    border-radius: 9999px;
    background: radial-gradient(circle, rgba(56,189,248,0.22) 0%, rgba(168,85,247,0.08) 45%, transparent 72%);
    top: 22%; left: 50%; transform: translateX(-50%);
    pointer-events: none;
  }
  .pedestal {
    position: absolute; bottom: 26%; left: 50%; transform: translateX(-50%) rotateX(74deg);
    width: ${Math.round(w * 0.56)}px; height: ${Math.round(w * 0.56)}px;
    border-radius: 9999px;
    border: 3px solid rgba(255,255,255,0.22);
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(15,23,42,0.5) 70%, transparent 100%);
    box-shadow: 0 0 60px rgba(56,189,248,0.25);
  }
  .header-badge {
    position: absolute; top: ${Math.round(h * 0.06)}px; left: 50%; transform: translateX(-50%);
    padding: 10px 24px; border-radius: 9999px;
    background: rgba(15,23,42,0.72); border: 1px solid rgba(56,189,248,0.45);
    font-size: ${Math.round(w * 0.026)}px; font-weight: 700; letter-spacing: 0.08em;
    text-transform: uppercase; color: #7dd3fc; white-space: nowrap;
  }
  .product-title {
    position: absolute; top: ${Math.round(h * 0.11)}px; width: 88%; text-align: center;
    font-size: ${Math.round(w * 0.044)}px; font-weight: 800; line-height: 1.25;
    text-shadow: 0 4px 20px rgba(0,0,0,0.6);
  }
  model-viewer {
    width: ${Math.round(w * 0.88)}px;
    height: ${Math.round(h * 0.62)}px;
    margin-top: ${Math.round(h * 0.06)}px;
    background-color: transparent;
    --poster-color: transparent;
  }
</style>
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/3.5.0/model-viewer.min.js"></script>
</head>
<body>
<div class="stage">
  <div class="glow"></div>
  <div class="pedestal"></div>
  <div class="header-badge">${badge}</div>
  <div class="product-title">${title}</div>
  <model-viewer id="mv"
    src="/model.glb"
    camera-orbit="${cfg.startAzimuth}deg ${cfg.startElevation}deg ${cfg.startRadius}%"
    field-of-view="32deg"
    shadow-intensity="1.2"
    exposure="1.15"
    environment-image="neutral"
    interaction-prompt="none">
  </model-viewer>
</div>
<script>
  window.__modelReady = false;
  const mv = document.getElementById('mv');
  mv.addEventListener('load', () => { window.__modelReady = true; });
  window.setCameraOrbit = (az, el, rad) => {
    mv.cameraOrbit = az.toFixed(2) + 'deg ' + el.toFixed(2) + 'deg ' + rad.toFixed(2) + '%';
    if (typeof mv.jumpCameraToGoal === 'function') mv.jumpCameraToGoal();
  };
</script>
</body>
</html>`;
}

async function main() {
    const args = parseArgs(process.argv);
    if (!args.config || !args.output) {
        console.error('Usage: node render_3d_video.mjs --config <json> --output <mp4>');
        process.exit(1);
    }

    const cfg = JSON.parse(fs.readFileSync(args.config, 'utf8').replace(/^\uFEFF/, ''));
    const glbPath = cfg.glbPath;
    if (!glbPath || !fs.existsSync(glbPath)) {
        throw new Error(`Không tìm thấy file .glb: ${glbPath}`);
    }

    const browserExe = findBrowserExecutable();
    if (!browserExe) {
        throw new Error('Không tìm thấy Chrome/Edge headless để kết xuất 3D WebGL.');
    }

    const width = cfg.aspectRatio === '16:9' ? 1280 : 720;
    const height = cfg.aspectRatio === '16:9' ? 720 : 1280;
    cfg.width = width;
    cfg.height = height;

    const html = buildStudioHtml(cfg);
    const glbBytes = fs.readFileSync(glbPath);

    // HTTP Server nội bộ phục vụ stage HTML + model.glb
    const server = http.createServer((req, res) => {
        if (req.url === '/model.glb') {
            res.writeHead(200, { 'Content-Type': 'model/gltf-binary', 'Access-Control-Allow-Origin': '*' });
            res.end(glbBytes);
            return;
        }
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(html);
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const port = server.address().port;
    const cdpPort = 19222 + Math.floor(Math.random() * 1000);
    const userDataDir = fs.mkdtempSync(path.join(path.dirname(args.output), 'cdp_3d_'));
    const framesDir = fs.mkdtempSync(path.join(path.dirname(args.output), 'frames_3d_'));

    const browserProc = spawn(browserExe, [
        '--headless=new',
        '--disable-gpu-sandbox',
        '--enable-webgl',
        '--use-gl=angle',
        '--use-angle=swiftshader',
        '--enable-unsafe-swiftshader',
        '--ignore-gpu-blocklist',
        '--no-sandbox',
        `--window-size=${width},${height}`,
        `--remote-debugging-port=${cdpPort}`,
        `--user-data-dir=${userDataDir}`,
        `http://127.0.0.1:${port}/`,
    ], { stdio: 'ignore' });

    let ws;
    try {
        // Chờ CDP sẵn sàng
        let wsUrl = null;
        for (let i = 0; i < 40; i++) {
            try {
                const listRes = await fetch(`http://127.0.0.1:${cdpPort}/json`);
                const pages = await listRes.json();
                const page = pages.find((p) => p.type === 'page');
                if (page?.webSocketDebuggerUrl) {
                    wsUrl = page.webSocketDebuggerUrl;
                    break;
                }
            } catch {}
            await new Promise((r) => setTimeout(r, 250));
        }

        if (!wsUrl) {
            throw new Error('Không thể kết nối CDP WebSocket tới Headless Browser.');
        }

        ws = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve, { once: true });
            ws.addEventListener('error', reject, { once: true });
        });

        let msgId = 1;
        const sendCdp = (method, params = {}) => new Promise((resolve, reject) => {
            const id = msgId++;
            const onMsg = (ev) => {
                const data = JSON.parse(ev.data);
                if (data.id === id) {
                    ws.removeEventListener('message', onMsg);
                    if (data.error) reject(new Error(data.error.message));
                    else resolve(data.result);
                }
            };
            ws.addEventListener('message', onMsg);
            ws.send(JSON.stringify({ id, method, params }));
        });

        await sendCdp('Emulation.setDeviceMetricsOverride', {
            width, height, deviceScaleFactor: 1, mobile: false,
        });

        // Chờ model-viewer tải xong file .glb
        let ready = false;
        for (let i = 0; i < 60; i++) {
            const evalRes = await sendCdp('Runtime.evaluate', { expression: 'Boolean(window.__modelReady)', returnByValue: true });
            if (evalRes?.result?.value === true) {
                ready = true;
                break;
            }
            await new Promise((r) => setTimeout(r, 250));
        }
        if (!ready) {
            throw new Error('model-viewer không tải được file .glb trong thời gian chờ.');
        }

        // Render các frame chuyển động 3D ở 18fps rồi FFmpeg nội suy lên 60fps
        const captureFps = 18;
        const durationSec = Math.max(2.0, (cfg.durationMs || 5000) / 1000.0);
        const totalFrames = Math.max(24, Math.round(durationSec * captureFps));

        for (let f = 0; f < totalFrames; f++) {
            const t = totalFrames > 1 ? f / (totalFrames - 1) : 0;
            const az = cfg.startAzimuth + (cfg.endAzimuth - cfg.startAzimuth) * t;
            const el = cfg.startElevation + (cfg.endElevation - cfg.startElevation) * t;
            const rad = cfg.startRadius + (cfg.endRadius - cfg.startRadius) * t;

            await sendCdp('Runtime.evaluate', { expression: `window.setCameraOrbit(${az}, ${el}, ${rad});` });
            await new Promise((r) => setTimeout(r, 35));

            const shot = await sendCdp('Page.captureScreenshot', { format: 'jpeg', quality: 90 });
            const frameFile = path.join(framesDir, `frame_${String(f).padStart(4, '0')}.jpg`);
            fs.writeFileSync(frameFile, Buffer.from(shot.data, 'base64'));
        }

        const outW = cfg.aspectRatio === '16:9' ? 1920 : 1080;
        const outH = cfg.aspectRatio === '16:9' ? 1080 : 1920;
        const threads = String(Math.min(6, Number(cfg.maxThreads || 6)));
        const ffArgs = [
            '-y',
            '-threads', threads,
            '-framerate', String(captureFps),
            '-i', path.join(framesDir, 'frame_%04d.jpg'),
            '-vf', `scale=${outW}:${outH}:flags=lanczos,fps=60,setsar=1,format=yuv420p`,
            '-t', durationSec.toFixed(3),
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '20',
            '-x264-params', `threads=${threads}`,
            '-an',
            args.output,
        ];

        const ffmpegBin = cfg.ffmpegBinary || 'ffmpeg';
        const ffRes = process.platform === 'linux'
            ? spawnSync('nice', ['-n', String(cfg.nicePriority || 10), ffmpegBin, ...ffArgs], { encoding: 'utf8' })
            : spawnSync(ffmpegBin, ffArgs, { encoding: 'utf8' });

        if (ffRes.status !== 0 || !fs.existsSync(args.output)) {
            throw new Error(`FFmpeg 3D encode thất bại: ${ffRes.stderr}`);
        }

        console.log(JSON.stringify({ status: 'ok', frames: totalFrames, output: args.output }));
    } finally {
        try { ws?.close(); } catch {}
        try { browserProc.kill('SIGKILL'); } catch {}
        server.close();
        fs.rmSync(userDataDir, { recursive: true, force: true });
        fs.rmSync(framesDir, { recursive: true, force: true });
    }
}

main().catch((err) => {
    console.error(err.message || err);
    process.exit(1);
});
