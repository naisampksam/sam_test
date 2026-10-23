import { chromium } from 'playwright';
import fs from 'fs';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const html = fs.readFileSync('out/cart.html', 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const p = await b.newPage({ viewport: { width: 390, height: 860 } });
const errs = [], notes = []; let submitted = false;
p.on('pageerror', e => errs.push(e.message));
await p.route('http://shop.test/**', async (r) => {
  const u = new URL(r.request().url());
  if (u.pathname.startsWith('/assets/')) return r.fulfill({ path: THEME + u.pathname.slice(8) });
  if (u.pathname === '/cart/update.js') { const body = JSON.parse(r.request().postData()); if ('note' in body) notes.push(body.note); return r.fulfill({ contentType: 'application/json', body: '{}' }); }
  if (r.request().method() === 'POST') { submitted = true; return r.fulfill({ body: 'x' }); }
  return r.fulfill({ contentType: 'text/html', body: html });
});
await p.addInitScript(() => {
  try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('fx-intro-seen', '1'); } catch (e) {}
  // same as Cashfree: capture click on checkout and stop everything else
  document.addEventListener('click', (e) => { const btn = e.target.closest('button[name="checkout"]'); if (!btn) return; e.preventDefault(); e.stopImmediatePropagation(); window.cfOpened = true; }, true);
});
await p.goto('http://shop.test/');
await p.waitForTimeout(600);
await p.fill('textarea[name="note"]', 'Gift wrap please');
await p.click('#CartForm button[name="checkout"]');
await p.waitForTimeout(600);
const okk = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
okk('Cashfree takes over the checkout button', await p.evaluate(() => window.cfOpened) === true);
okk('order note is saved before Cashfree opens', notes.at(-1) === 'Gift wrap please', JSON.stringify(notes.at(-1)));
okk('the normal checkout form is not submitted', !submitted);
okk('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
