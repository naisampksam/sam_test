import { chromium } from 'playwright';
import fs from 'fs';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const page = (f) => fs.readFileSync('out/' + f, 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const res = []; const ok = (n, c, x = '') => { res.push(c); console.log(c ? 'PASS' : 'FAIL', n, x); };
async function open(file, opts = {}) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: opts.reduce ? 'reduce' : 'no-preference' });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
  await p.route('http://shop.test/**', (r) => { const u = new URL(r.request().url()); if (u.pathname.startsWith('/assets/')) return r.fulfill({ path: THEME + u.pathname.slice(8) }); if (u.pathname.endsWith('.js') && u.pathname.startsWith('/products/')) return r.fulfill({ contentType: 'application/json', body: '{}' }); return r.fulfill({ contentType: 'text/html', body: page(file) }); });
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('hf_intro', '1'); } catch (e) {} });
  await p.goto('http://shop.test/'); await p.waitForTimeout(700);
  const cdp = await ctx.newCDPSession(p);
  const swipe = async (x0, y0, x1, y1, ms = 160, steps = 8) => {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: x0, y: y0 }] });
    for (let i = 1; i <= steps; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: x0 + (x1 - x0) * i / steps, y: y0 + (y1 - y0) * i / steps }] }); await p.waitForTimeout(ms / steps); }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  };
  return { p, ctx, errs, swipe };
}
const tf = (p, sel) => p.evaluate((s) => { const e = document.querySelector(s); return e ? new DOMMatrix(getComputedStyle(e).transform).m41 : null; }, sel);

// ---------- cart drawer
let { p, ctx, errs, swipe } = await open('home.html');
ok('springs enabled', await p.evaluate(() => document.documentElement.classList.contains('has-springs')));
await p.evaluate(() => document.querySelector('[data-cart-drawer]') && null);
const openCart = () => p.click('a[href="/cart"], [data-cart-link], .header__icon--cart').catch(() => p.evaluate(() => document.querySelector('[data-cart-drawer]').classList.add('is-open')));
await openCart(); await p.waitForTimeout(60);
const mid = await tf(p, '.cart-drawer__panel');
await p.waitForTimeout(700);
ok('cart opens with a moving spring', mid > 5 && mid < 390, 'x at 60ms=' + Math.round(mid));
ok('cart settles open, inline styles cleared', await p.evaluate(() => { const d = document.querySelector('[data-cart-drawer]'); return d.classList.contains('is-open') && !d.querySelector('.cart-drawer__panel').style.transform; }));
await swipe(150, 160, 200, 162, 200, 6); await p.waitForTimeout(700);
ok('element at drag point is in the cart panel', await p.evaluate(() => !!document.elementFromPoint(150,160).closest('.cart-drawer__panel')));
ok('small slow swipe snaps back open', await p.evaluate(() => document.querySelector('[data-cart-drawer]').classList.contains('is-open')) && Math.abs(await tf(p, '.cart-drawer__panel')) < 1);
await swipe(120, 160, 230, 164, 90, 5); await p.waitForTimeout(800);
const st = await p.evaluate(() => { const d = document.querySelector('[data-cart-drawer]'); return { open: d.classList.contains('is-open'), closing: d.classList.contains('is-closing'), vis: getComputedStyle(d).visibility, body: document.body.classList.contains('drawer-open') }; });
ok('quick flick (110px) closes with momentum via theme close', !st.open && !st.closing && st.vis === 'hidden' && !st.body, JSON.stringify(st));
// interrupt: open, close at once, reopen mid-flight
await openCart(); await p.waitForTimeout(80);
await p.evaluate(() => document.querySelector('[data-cart-drawer-close]').click()); await p.waitForTimeout(60);
const midClose = await tf(p, '.cart-drawer__panel');
await openCart(); await p.waitForTimeout(800);
ok('interrupted close reverses from where it is', await p.evaluate(() => document.querySelector('[data-cart-drawer]').classList.contains('is-open')) && Math.abs(await tf(p, '.cart-drawer__panel')) < 1, 'was at ' + Math.round(midClose));
// cart bubble pop
await p.evaluate(() => { const c = document.querySelector('[data-cart-count]'); c.textContent = String((parseInt(c.textContent, 10) || 0) + 1); }); await p.waitForTimeout(40);
const pop = await p.evaluate(() => document.querySelector('[data-cart-count-bubble]').style.transform);
await p.waitForTimeout(900);
ok('cart count pops then settles', /scale/.test(pop) && !(await p.evaluate(() => document.querySelector('[data-cart-count-bubble]').style.transform)), pop);
ok('no JS errors (home)', errs.length === 0, errs.join(' | '));
await ctx.close();

// ---------- mobile menu
({ p, ctx, errs, swipe } = await open('home.html'));
await p.tap('.header__drawer summary'); await p.waitForTimeout(60);
const m1 = await tf(p, '.header__drawer .drawer'); await p.waitForTimeout(700);
ok('menu slides in from the left', m1 < -5 && await p.evaluate(() => document.querySelector('.header__drawer').open) && Math.abs(await tf(p, '.header__drawer .drawer')) < 1, 'x at 60ms=' + Math.round(m1));
ok('page scroll locked while menu open', await p.evaluate(() => document.body.style.overflow === 'hidden'));
await swipe(300, 400, 190, 402, 90, 5); await p.waitForTimeout(800);
ok('swipe left closes menu, scroll unlocked', await p.evaluate(() => !document.querySelector('.header__drawer').open && document.body.style.overflow === ''));
await p.tap('.header__drawer summary'); await p.waitForTimeout(600); await p.tap('.header__drawer summary'); await p.waitForTimeout(60);
const stillOpen = await p.evaluate(() => document.querySelector('.header__drawer').open); await p.waitForTimeout(700);
ok('hamburger closes with motion (open during slide-out, closed after)', stillOpen && !(await p.evaluate(() => document.querySelector('.header__drawer').open)));
ok('no JS errors (menu)', errs.length === 0, errs.join(' | '));
await ctx.close();

// ---------- size finder sheet (product page)
({ p, ctx, errs, swipe } = await open('product.html'));
const sfBtn = await p.$('[data-size-finder], .size-finder__cta');
if (sfBtn) {
  await sfBtn.tap(); await p.waitForTimeout(60);
  const y0 = await p.evaluate(() => new DOMMatrix(getComputedStyle(document.querySelector('.sf')).transform).m42);
  await p.waitForTimeout(700);
  const y1 = await p.evaluate(() => new DOMMatrix(getComputedStyle(document.querySelector('.sf')).transform).m42);
  ok('size finder rises as a bottom sheet', y0 > 20 && Math.abs(y1) < 1, `y ${Math.round(y0)} -> ${Math.round(y1)}`);
  const top = await p.evaluate(() => document.querySelector('.sf').getBoundingClientRect().top);
  await swipe(195, top + 30, 195, top + 200, 100, 6); await p.waitForTimeout(800);
  ok('pull down closes the sheet', await p.evaluate(() => !document.querySelector('.sf').open));
  await sfBtn.tap(); await p.waitForTimeout(700);
  await p.keyboard.press('Escape'); await p.waitForTimeout(60);
  const during = await p.evaluate(() => document.querySelector('.sf').open); await p.waitForTimeout(700);
  ok('Escape animates the sheet out', during && await p.evaluate(() => !document.querySelector('.sf').open));
} else ok('size finder button found', false);
ok('no JS errors (product)', errs.length === 0, errs.join(' | '));
await ctx.close();

// ---------- reduced motion
({ p, ctx, errs } = await open('home.html', { reduce: true }));
ok('reduced motion: no springs', !(await p.evaluate(() => document.documentElement.classList.contains('has-springs'))));
await p.tap('.header__drawer summary'); await p.waitForTimeout(100);
ok('reduced motion: menu still opens instantly', await p.evaluate(() => document.querySelector('.header__drawer').open));
ok('no JS errors (reduced)', errs.length === 0, errs.join(' | '));
await ctx.close();
await b.close();
console.log(res.filter(Boolean).length + '/' + res.length);
