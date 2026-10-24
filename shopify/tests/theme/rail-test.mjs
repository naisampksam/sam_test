// Katana rail on the home page: swipe, the middle tee faces front, tap for the close-up, quick add.
import { chromium } from 'playwright';
import fs from 'fs';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const html = fs.readFileSync('out/home.html', 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const errs = [];

async function open(opts = {}) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: opts.reduce ? 'reduce' : 'no-preference' });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  await p.route('http://shop.test/**', (r) => {
    const u = new URL(r.request().url());
    if (u.pathname.startsWith('/assets/')) return r.fulfill({ path: THEME + u.pathname.slice(8) });
    if (u.pathname.endsWith('.js')) return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ title: 'X', variants: [], options: [], images: [] }) });
    return r.fulfill({ contentType: 'text/html', body: html });
  });
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('hf_intro', '1'); } catch (e) {} });
  await p.goto('http://shop.test/'); await p.waitForTimeout(700);
  await p.evaluate(() => document.querySelector('[data-katana-rail]').scrollIntoView({ block: 'center' }));
  await p.waitForTimeout(600);
  return { p, ctx };
}
const state = (p) => p.evaluate(() => {
  const items = [...document.querySelectorAll('[data-rail-item]')];
  const act = items.findIndex((i) => i.classList.contains('is-active'));
  const tf = (i) => { const g = items[i] && items[i].querySelector('[data-rail-garment]'); const m = g && g.style.transform.match(/scaleX\(([\d.]+)\)/); return m ? { sx: +m[1], shade: +g.style.getPropertyValue('--shade') } : null; };
  return { n: items.length, act, title: document.querySelector('[data-rail-title]').textContent, counter: document.querySelector('[data-rail-current]').textContent, mid: tf(act), next: tf(act + 1) };
});

let { p, ctx } = await open();
let s = await state(p);
ok('rail shows up to 10 tees (gift box and couple combos skipped)', s.n === 10, `n=${s.n}`);
ok('starts on the second tee, facing front (full width)', s.act === 1 && s.mid && s.mid.sx > 0.98, JSON.stringify(s.mid));
ok('neighbours hang side-on: narrower, no 3D distortion', s.next && s.next.sx < 0.6 && !(await p.evaluate(() => [...document.querySelectorAll('[data-rail-garment]')].some((g) => /perspective|rotate/.test(g.style.transform)))), JSON.stringify(s.next));
ok('title and counter follow the middle tee', s.title.length > 3 && s.counter === '02', `${s.counter} ${s.title}`);
const rod = await p.evaluate(() => { const k = document.querySelector('.rail-rod__bar'); const r = k.getBoundingClientRect(); const bg = getComputedStyle(document.querySelector('[data-katana-rail]')).backgroundColor; return { w: r.width, h: r.height, bg }; });
ok('a steel rail spans the width, on a band matching the photo background', rod.w >= 330 && rod.h <= 9 && rod.bg === 'rgb(239, 233, 221)', JSON.stringify(rod));

// swipe one tee to the left
await p.evaluate(() => { const t = document.querySelector('[data-rail-track]'); t.scrollBy({ left: t.querySelector('[data-rail-item]').offsetWidth, behavior: 'instant' }); });
await p.waitForTimeout(700);
const s2 = await state(p);
ok('swiping brings the next tee to the middle and updates the title', s2.act === 2 && s2.counter === '03' && s2.title !== s.title, `${s2.counter} ${s2.title}`);
ok('mid-swipe the turn is continuous (follows the finger)', await p.evaluate(async () => {
  const t = document.querySelector('[data-rail-track]'); const w = t.querySelector('[data-rail-item]').offsetWidth;
  t.style.scrollSnapType = 'none'; t.scrollBy({ left: w * 0.5, behavior: 'instant' });
  await new Promise((r) => setTimeout(r, 120));
  const g = document.querySelectorAll('[data-rail-garment]')[2].style.transform;
  const sx = parseFloat((g.match(/scaleX\(([\d.]+)\)/) || [])[1]);
  t.scrollBy({ left: -w * 0.5, behavior: 'instant' }); t.style.scrollSnapType = '';
  return sx > 0.6 && sx < 0.95;
}));
await p.waitForTimeout(500);

// tap a side tee: it comes to the middle (no close-up yet)
await p.locator('[data-rail-item]').nth(3).locator('[data-rail-open]').click({ position: { x: 40, y: 80 } });
await p.waitForTimeout(900);
const s3 = await state(p);
ok('tapping a side tee brings it to the middle', s3.act === 3, `act=${s3.act}`);
ok('no close-up opened by that tap', !(await p.evaluate(() => document.querySelector('[data-rail-sheet]').open)));

// tap the middle tee: close-up
await p.locator('[data-rail-item]').nth(3).locator('[data-rail-open]').click();
await p.waitForTimeout(600);
const sheet = await p.evaluate(() => { const d = document.querySelector('[data-rail-sheet]'); return { open: d.open, title: d.querySelector('[data-rail-sheet-title]').textContent, counter: d.querySelector('[data-rail-sheet-counter]').textContent, img: d.querySelector('[data-rail-sheet-img]').getAttribute('src'), qa: d.querySelector('[data-rail-quick-add]').dataset.quickAdd }; });
ok('tapping the middle tee opens the close-up with its name, counter and image', sheet.open && sheet.title === s3.title && sheet.counter === '04 / 10' && !!sheet.img, JSON.stringify(sheet).slice(0, 160));
ok('close-up quick add points at that product', /^\/products\//.test(sheet.qa || ''), sheet.qa);
await p.click('[data-rail-quick-add]'); await p.waitForTimeout(900);
ok('quick add closes the close-up and opens the size picker', await p.evaluate(() => !document.querySelector('[data-rail-sheet]').open && document.querySelector('[data-quick-add-modal]').open));
await p.evaluate(() => document.querySelector('[data-quick-add-modal]').close()); await p.waitForTimeout(500);
ok('no horizontal page overflow', !(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
await ctx.close();

// reduced motion: tees don't turn
({ p, ctx } = await open({ reduce: true }));
const r = await p.evaluate(() => [...document.querySelectorAll('[data-rail-garment]')].every((g) => !g.style.transform));
ok('reduced motion: tees stay flat', r);
await ctx.close();

// desktop with a mouse: the whole rail fits, hover opens a tee, click opens the close-up
{
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message));
  await p.route('http://shop.test/**', (r) => { const u = new URL(r.request().url()); if (u.pathname.startsWith('/assets/')) return r.fulfill({ path: THEME + u.pathname.slice(8) }); return r.fulfill({ contentType: 'text/html', body: html }); });
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('hf_intro', '1'); } catch (e) {} });
  await p.goto('http://shop.test/'); await p.waitForTimeout(700);
  await p.evaluate(() => document.querySelector('[data-katana-rail]').scrollIntoView({ block: 'center' })); await p.waitForTimeout(500);
  const fit = await p.evaluate(() => { const items = [...document.querySelectorAll('[data-rail-item]')]; const last = items[items.length - 1].getBoundingClientRect(); return { right: last.right, vw: innerWidth, scrollable: document.querySelector('[data-rail-track]').scrollWidth > document.querySelector('[data-rail-track]').clientWidth + 2 }; });
  ok('desktop: all 10 tees fit on the rail (no dragging needed)', fit.right <= fit.vw && !fit.scrollable, JSON.stringify(fit));
  await p.hover('[data-rail-item]:nth-child(6)'); await p.waitForTimeout(700);
  const h = await p.evaluate(() => { const items = [...document.querySelectorAll('[data-rail-item]')]; return { act: items.findIndex((i) => i.classList.contains('is-active')), wActive: items[5].getBoundingClientRect().width, wOther: items[0].getBoundingClientRect().width, title: document.querySelector('[data-rail-title]').textContent, sheet: document.querySelector('[data-rail-sheet]').open }; });
  ok('desktop: hovering a tee opens it to face you and updates the name (no click)', h.act === 5 && h.wActive > h.wOther * 1.8 && !h.sheet, JSON.stringify(h));
  await p.click('[data-rail-item]:nth-child(6) [data-rail-open]'); await p.waitForTimeout(600);
  ok('desktop: clicking the tee opens the close-up', await p.evaluate(() => document.querySelector('[data-rail-sheet]').open && document.querySelector('[data-rail-sheet-counter]').textContent === '06 / 10'));
  await ctx.close();
}
ok('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
