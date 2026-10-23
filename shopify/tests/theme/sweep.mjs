import { chromium } from 'playwright';
import fs from 'fs';
const pages = fs.readdirSync('out').filter((f) => f.endsWith('.html') && !/dojo-track|track-order-api|custom/.test(f));
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const report = [];
for (const w of [360, 390, 768, 1280]) {
  const ctx = await b.newContext({ viewport: { width: w, height: 900 }, isMobile: w < 500, hasTouch: w < 500 });
  for (const f of pages) {
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', (e) => errs.push('JS: ' + e.message));
    p.on('console', (m) => { if (m.type() === 'error' && !/net::|Failed to load resource|ERR_|favicon|fonts\.|shopify\.com|cdn\.shopify/i.test(m.text())) errs.push('console: ' + m.text().slice(0, 120)); });
    await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('fx-intro-seen', '1'); } catch (e) {} });
    await p.goto('file://' + process.cwd() + '/out/' + f); await p.waitForTimeout(500);
    await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 40)); } });
    const r = await p.evaluate(() => ({
      overflow: document.documentElement.scrollWidth - innerWidth,
      broken: [...document.images].filter((i) => i.complete && i.naturalWidth === 0 && i.src.startsWith('file:')).map((i) => i.src.split('/').pop()),
      emptyLinks: [...document.querySelectorAll('a')].filter((a) => !a.getAttribute('href') && !a.hasAttribute('role')).length,
      noAlt: [...document.images].filter((i) => !i.hasAttribute('alt')).length,
      liquidLeak: /{{|{%|Liquid error|translation missing/i.test(document.body.innerText)
    }));
    if (errs.length || r.overflow > 1 || r.broken.length || r.liquidLeak) report.push({ w, f, errs: errs.slice(0, 3), ...r });
    await p.close();
  }
  await ctx.close();
}
console.log(report.length ? JSON.stringify(report, null, 1) : `clean: ${pages.length} pages × 4 widths, no JS errors, no overflow, no broken images, no Liquid leaks`);
await b.close();
