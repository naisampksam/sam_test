// Cart "You're saving" breakdown: every saving listed, and the parts add up to the total.
import { chromium } from 'playwright';
import fs from 'fs';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const errs = [];
const num = (t) => { const m = String(t).match(/\d[\d,]*(?:\.\d+)?/); return m ? parseFloat(m[0].replace(/,/g, '')) : 0; };
for (const [name, file, open] of [['drawer', 'home.html', true], ['cart page', 'cart.html', false]]) {
  const html = fs.readFileSync('out/' + file, 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage();
  p.on('pageerror', (e) => errs.push(e.message));
  await p.route('http://shop.test/**', (r) => { const u = new URL(r.request().url()); if (u.pathname.startsWith('/assets/')) return r.fulfill({ path: THEME + u.pathname.slice(8) }); return r.fulfill({ contentType: 'text/html', body: html }); });
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('hf_intro', '1'); } catch (e) {} });
  await p.goto('http://shop.test/'); await p.waitForTimeout(700);
  if (open) { await p.evaluate(() => document.querySelector('[data-cart-drawer]').classList.add('is-open')); await p.waitForTimeout(700); }
  const root = open ? '[data-cart-drawer] .savings' : 'main .savings';
  const rows = await p.$$eval(`${root} .savings__list li`, (lis) => lis.map((li) => [...li.children].map((c) => c.textContent.trim())));
  const total = num(await p.textContent(`${root} .savings__total`));
  const sum = rows.filter((r) => r[1].startsWith('−')).reduce((s, r) => s + num(r[1]), 0);
  const labels = rows.map((r) => r[0]).join(' | ');
  ok(`${name}: lists sale price, bundle, Dojo reward, store credit and free delivery`, /Sale price/.test(labels) && /Bundle/.test(labels) && /Dojo Run reward \(HF100/.test(labels) && /Store credit/.test(labels) && /Delivery/.test(labels), labels);
  ok(`${name}: the parts add up to the total saving`, Math.abs(sum - total) < 0.01, `${sum} vs ${total}`);
  await p.click(`${root} .savings__total`); await p.waitForTimeout(150);
  ok(`${name}: tapping the total folds the breakdown away`, !(await p.$eval(root, (d) => d.open)));
  await p.click(`${root} .savings__total`);
  const box = await p.$(root); await box.scrollIntoViewIfNeeded(); await box.screenshot({ path: `out/savings-${name.replace(' ', '-')}.png` });
}
ok('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
