// shot.mjs — harness screenshot tool. Run via playground.sh (which passes the live
// port + cookie jar), or directly:
//   node shot.mjs <port> <cookies> <url-or-path> <out.png> [selector] [width] [height]
//
// <url-or-path> may be a full URL or a site path ("/", "/wp-admin/…", "/?p=3").
// With a [selector], screenshots just that element (scrolled into view); otherwise a
// full-page shot. Admin pages work because Playground is started with --login, which
// auto-authenticates the first visit; the curl cookie jar is also loaded into the
// browser context so the admin session is shared with the CLI.
//
// Uses the system Chrome (executablePath) because the repo's pinned Playwright build
// may not match the preinstalled browser bundle; the harness documents that Chrome +
// `npx playwright install ffmpeg` are the only one-time setup.

import { chromium } from 'playwright';
import { readFileSync } from 'fs';

const [, , portStr, cookiesPath, target, out, selector, w = '1280', h = '900'] = process.argv;
if (!portStr || !cookiesPath || !target || !out) {
  console.error('Usage: shot.mjs <port> <cookies> <url-or-path> <out.png> [selector] [width] [height]');
  process.exit(2);
}

const url = /^https?:\/\//.test(target) ? target : `http://127.0.0.1:${portStr}${target}`;

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
    cookies.push({
      name, value,
      domain: domain.replace(/^\./, ''),
      path: cpath || '/',
      secure: secure === 'TRUE',
      httpOnly,
      expires: expiry && expiry !== '0' ? +expiry : -1,
    });
  }
  return cookies;
}

const browser = await chromium.launch({
  executablePath: '/usr/bin/google-chrome-stable',
  args: ['--no-sandbox', '--ignore-certificate-errors'],
});
const ctx = await browser.newContext({
  ignoreHTTPSErrors: true,
  viewport: { width: +w, height: +h },
  deviceScaleFactor: 2,
});
const cookies = loadCookies(cookiesPath);
if (cookies.length) await ctx.addCookies(cookies);

const page = await ctx.newPage();
await page.goto(url, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(500);

if (selector) {
  await page.waitForSelector(selector, { timeout: 20000 }).catch(() => {});
  const el = await page.$(selector);
  if (!el) {
    console.error(`selector not found: ${selector}`);
    await browser.close();
    process.exit(3);
  }
  await el.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await el.screenshot({ path: out });
  const box = await el.boundingBox();
  console.log(JSON.stringify({ shot: out, url, selector, box }));
} else {
  await page.screenshot({ path: out, fullPage: true });
  const info = await page.evaluate(() => ({
    title: document.title,
    h1: (document.querySelector('h1')?.textContent || '').trim().slice(0, 120),
    bodyHeight: document.body.scrollHeight,
  }));
  console.log(JSON.stringify({ shot: out, url, ...info }));
}
await browser.close();
