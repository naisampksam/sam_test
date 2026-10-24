// Visual preview of the home page clothing rail with the real store photos (cream background).
// Writes out/rail-real-390.png, out/rail-real-1280.png and out/rail-swipe-390.png (frames during a swipe).
import { chromium } from 'playwright';
import fs from 'fs';
const ETSY = new URL('../../product-images/etsy/', import.meta.url).pathname;
const designs = fs.readdirSync(ETSY).filter((d) => fs.existsSync(ETSY + d + '/colour-black.jpg'));
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const prep = async (w, h) => {
  const p = await (await b.newContext({ viewport: { width: w, height: h }, isMobile: w < 500, hasTouch: w < 500, deviceScaleFactor: 2 })).newPage();
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('hf_intro', '1'); } catch (e) {} });
  await p.goto('file://' + process.cwd() + '/out/home.html'); await p.waitForTimeout(600);
  await p.evaluate(({ base, designs }) => { document.querySelectorAll('.katana-rail__img').forEach((img, i) => { img.removeAttribute('srcset'); img.src = 'file://' + base + designs[i % designs.length] + '/colour-black.jpg'; }); }, { base: ETSY, designs });
  await p.evaluate(() => window.scrollTo(0, document.querySelector('[data-katana-rail]').getBoundingClientRect().top + scrollY - 70));
  await p.waitForTimeout(1000);
  return p;
};
for (const [w, h] of [[390, 844], [1280, 860]]) {
  const p = await prep(w, h);
  if (w > 900) { await p.hover('[data-rail-item]:nth-child(5)'); await p.waitForTimeout(800); }
  await (await p.$('[data-katana-rail]')).screenshot({ path: `out/rail-real-${w}.png` });
}
// frames during one swipe
const p = await prep(390, 844);
const track = await p.$('[data-rail-track]');
const box = await (await p.$('.katana-rail__stage')).boundingBox();
const frames = [];
await p.evaluate(() => { const t = document.querySelector('[data-rail-track]'); t.style.scrollSnapType = 'none'; });
const w = await p.evaluate(() => document.querySelector('[data-rail-item]').offsetWidth);
for (let k = 0; k <= 4; k++) {
  await p.evaluate((x) => { document.querySelector('[data-rail-track]').scrollLeft += x; }, w / 4 * (k ? 1 : 0));
  await p.waitForTimeout(120);
  frames.push(await p.screenshot({ clip: { x: 0, y: box.y, width: 390, height: box.height } }));
}
frames.forEach((f, i) => fs.writeFileSync(`out/rail-frame-${i}.png`, f));
await b.close();
