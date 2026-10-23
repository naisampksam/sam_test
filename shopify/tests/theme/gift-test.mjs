import { chromium } from 'playwright';
import fs from 'fs';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const html = fs.readFileSync('out/product.html', 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });
const p = await ctx.newPage(); const errs = []; const adds = [];
p.on('pageerror', (e) => errs.push(e.message));
await p.route('http://shop.test/**', async (r) => {
  const u = new URL(r.request().url());
  if (u.pathname.startsWith('/assets/')) return r.fulfill({ path: THEME + u.pathname.slice(8) });
  if (u.pathname === '/cart/add.js') { const fd = r.request().postData(); adds.push(fd); return r.fulfill({ contentType: 'application/json', body: JSON.stringify({ sections: { 'cart-drawer': '<div data-cart-drawer class="cart-drawer"><div class="cart-drawer__overlay" data-cart-drawer-close></div><div class="cart-drawer__panel"></div></div>' } }) }); }
  if (u.pathname === '/cart.js') return r.fulfill({ contentType: 'application/json', body: '{"item_count":2}' });
  return r.fulfill({ contentType: 'text/html', body: html });
});
await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('hf_intro', '1'); } catch (e) {} });
await p.goto('http://shop.test/'); await p.waitForTimeout(800);
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const m0 = await p.textContent('[data-size-measure]');
await p.click('label.pill:text-is("L")'); await p.waitForTimeout(100);
const m1 = await p.textContent('[data-size-measure]');
ok('measurements under size update on tap', /Chest/.test(m0) && m1.startsWith('L: Chest 46″'), `${m0} -> ${m1}`);
// plain add (no gift)
await p.click('[data-add-to-cart]'); await p.waitForTimeout(500);
ok('plain add sends a normal single item', adds.length === 1 && !/items/.test(adds[0]) , adds[0] && adds[0].slice(0, 80));
await p.evaluate(() => document.querySelector('[data-cart-drawer-close]') && document.querySelector('[data-cart-drawer-close]').click()); await p.waitForTimeout(600);
// gift
ok('gift message hidden until ticked', await p.evaluate(() => document.querySelector('[data-gift-message]').hidden));
await p.click('[data-gift-box-toggle]'); await p.waitForTimeout(100);
ok('ticking shows the message box', await p.evaluate(() => !document.querySelector('[data-gift-message]').hidden));
await p.fill('[data-gift-message]', 'Happy birthday, warrior!');
await p.click('[data-add-to-cart]'); await p.waitForTimeout(500);
const last = adds[adds.length - 1] || '';
const dec = decodeURIComponent(last.replace(/\+/g, ' '));
ok('gift add: tee + box in one request with message and For', adds.length === 2 && /items\[0\]\[id\]/.test(dec) && /items\[1\]\[id\]"\s*\r?\n\r?\n99011|items\[1\]\[id\]/.test(dec) && /Happy birthday, warrior!/.test(dec) && /items\[1\]\[properties\]\[For\]/.test(dec), '');
console.log(dec.replace(/------WebKitFormBoundary[^\n]*\n/g, '|').replace(/Content-Disposition: form-data; name=/g, '').replace(/\r?\n/g, ' ').slice(0, 600));
await p.evaluate(() => window.scrollTo(0, document.querySelector('.option__measure').getBoundingClientRect().top + window.scrollY - 160));
await p.waitForTimeout(300); await p.screenshot({ path: 'out/gift-measure.png' });
ok('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
