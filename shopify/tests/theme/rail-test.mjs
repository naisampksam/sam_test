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
  const tf = (i) => { const g = items[i] && items[i].querySelector('[data-rail-garment]'); const m = g && g.style.transform.match(/rotateY\((-?[\d.]+)deg\) scale\(([\d.]+)\)/); return m ? { turn: +m[1], scale: +m[2] } : null; };
  return { n: items.length, act, title: document.querySelector('[data-rail-title]').textContent, counter: document.querySelector('[data-rail-current]').textContent, mid: tf(act), next: tf(act + 1) };
});

let { p, ctx } = await open();
let s = await state(p);
ok('rail shows up to 10 tees (gift box and couple combos skipped)', s.n === 10, `n=${s.n}`);
ok('starts on the second tee, facing front and larger', s.act === 1 && s.mid && Math.abs(s.mid.turn) < 3 && s.mid.scale > 1.12, JSON.stringify(s.mid));
ok('neighbours hang side-on', s.next && Math.abs(s.next.turn) > 55, JSON.stringify(s.next));
ok('title and counter follow the middle tee', s.title.length > 3 && s.counter === '02', `${s.counter} ${s.title}`);
const rod = await p.evaluate(() => { const k = document.querySelector('.rail-rod__bar'); const r = k.getBoundingClientRect(); const bg = getComputedStyle(document.querySelector('[data-katana-rail]')).backgroundColor; return { w: r.width, h: r.height, bg }; });
ok('a steel rail runs edge to edge on phones, on a band matching the photo background', rod.w >= 380 && rod.h <= 8 && rod.bg === 'rgb(239, 233, 221)', JSON.stringify(rod));

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
  const turn = Math.abs(parseFloat((g.match(/rotateY\((-?[\d.]+)deg/) || [])[1]));
  t.scrollBy({ left: -w * 0.5, behavior: 'instant' }); t.style.scrollSnapType = '';
  return turn > 15 && turn < 55;
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
ok('reduced motion: tees do not turn or sway', r);
await ctx.close();

ok('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
