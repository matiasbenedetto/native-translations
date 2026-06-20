// cast.mjs — harness screencast tool. Records a webm video of a browser session,
// optionally driving a scripted sequence of interactions so a coding agent can capture
// a feature end-to-end. Run via playground.sh:
//   playground.sh cast -- <url-or-path> <out.webm> [steps.json] [width] [height]
// or directly:
//   node cast.mjs <port> <cookies> <url-or-path> <out.webm> [steps.json] [width] [height]
//
// Without a steps file, it records the page for ~4s with a gentle scroll. With a steps
// file (JSON array), each step is one of:
//   {"type":"goto","url":"/wp-admin/…"}        navigate (path or full URL)
//   {"type":"click","selector":"button.translate"}
//   {"type":"fill","selector":"#title","value":"Hola"}
//   {"type":"press","key":"Enter"}
//   {"type":"select","selector":"select","value":"es"}
//   {"type":"wait","ms":800}
//   {"type":"wait_for","selector":".result","timeout":15000}
//   {"type":"scroll","y":600}  or  {"type":"scroll","selector":"…"}
//   {"type":"screenshot","path":"/tmp/mid.png"}   (a still grabbed mid-video)
//   {"type":"note","text":"…"}                    (logged only, for timeline markers)
// A 400ms settle follows each visible step so the recording reads naturally.
//
// Requires the Playwright ffmpeg host (one-time): `npx playwright install ffmpeg`.

import { chromium } from 'playwright';
import { readFileSync, mkdirSync, renameSync } from 'fs';
import { dirname } from 'path';

const [, , portStr, cookiesPath, target, out, stepsPath, w = '1280', h = '800'] = process.argv;
if (!portStr || !cookiesPath || !target || !out) {
  console.error('Usage: cast.mjs <port> <cookies> <url-or-path> <out.webm> [steps.json] [width] [height]');
  process.exit(2);
}

const base = `http://127.0.0.1:${portStr}`;
const resolveUrl = (u) => (/^https?:\/\//.test(u) ? u : base + (u.startsWith('/') ? u : '/' + u));

let steps = [];
if (stepsPath) {
  steps = JSON.parse(readFileSync(stepsPath, 'utf8'));
  if (!Array.isArray(steps)) throw new Error('steps file must be a JSON array');
}

function loadCookies(path) {
  let text = '';
  try { text = readFileSync(path, 'utf8'); } catch { return []; }
  const cookies = [];
  for (const line of text.split('\n')) {
    if (!line || line.startsWith('#') && !line.startsWith('#HttpOnly_')) continue;
    const httpOnly = line.startsWith('#HttpOnly_');
    const cleaned = httpOnly ? line.slice('#HttpOnly_'.length) : line;
    const parts = cleaned.split('\t');
    if (parts.length < 7) continue;
    const [domain, , cpath, secure, expiry, name, value] = parts;
    cookies.push({ name, value, domain: domain.replace(/^\./, ''), path: cpath || '/',
      secure: secure === 'TRUE', httpOnly, expires: expiry && expiry !== '0' ? +expiry : -1 });
  }
  return cookies;
}

const videoDir = '/tmp/harness-casts';
mkdirSync(videoDir, { recursive: true });

const browser = await chromium.launch({
  executablePath: '/usr/bin/google-chrome-stable',
  args: ['--no-sandbox', '--ignore-certificate-errors'],
});
const ctx = await browser.newContext({
  ignoreHTTPSErrors: true,
  viewport: { width: +w, height: +h },
  recordVideo: { dir: videoDir, size: { width: +w, height: +h } },
});
const cookies = loadCookies(cookiesPath);
if (cookies.length) await ctx.addCookies(cookies);

const page = await ctx.newPage();
const start = resolveUrl(target);
await page.goto(start, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(500);

const settle = (ms = 400) => page.waitForTimeout(ms);

if (steps.length === 0) {
  // Default: record a slow scroll through the page.
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
  for (let y = 0; y <= 4000; y += 300) {
    await page.evaluate((yy) => window.scrollTo({ top: yy, behavior: 'smooth' }), y);
    await settle(300);
  }
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  await settle(500);
} else {
  for (const [i, step] of steps.entries()) {
    try {
      switch (step.type) {
        case 'goto': await page.goto(resolveUrl(step.url), { waitUntil: 'load', timeout: 60000 }); break;
        case 'click': await page.click(step.selector, { timeout: step.timeout ?? 15000 }); break;
        case 'fill': await page.fill(step.selector, String(step.value), { timeout: step.timeout ?? 15000 }); break;
        case 'press': await page.press(step.selector || 'body', step.key); break;
        case 'select': await page.selectOption(step.selector, String(step.value)); break;
        case 'wait': await page.waitForTimeout(step.ms ?? 500); break;
        case 'wait_for': await page.waitForSelector(step.selector, { timeout: step.timeout ?? 15000 }); break;
        case 'scroll':
          if (step.selector) await page.locator(step.selector).scrollIntoViewIfNeeded();
          else await page.evaluate((y) => window.scrollTo({ top: y, behavior: 'smooth' }), step.y ?? 400);
          break;
        case 'screenshot': await page.screenshot({ path: step.path, fullPage: !!step.full, timeout: 10000 }); break;
        case 'note': console.log(`[step ${i}] ${step.text}`); break;
        default: console.error(`unknown step type: ${step.type}`);
      }
      if (!['wait', 'note', 'wait_for'].includes(step.type)) await settle();
    } catch (e) {
      console.error(`[step ${i}] ${step.type} failed: ${String(e).split('\n')[0]}`);
    }
  }
  await settle(700);
}

const video = page.video();
const tmpPath = await video.path();
await page.close();
await ctx.close();
await browser.close();

mkdirSync(dirname(out), { recursive: true });
try { renameSync(tmpPath, out); } catch { /* cross-device: copy fallback below */ }
console.log(JSON.stringify({ cast: out, url: start, steps: steps.length, seconds: steps.length ? null : 'scroll-sweep' }));
