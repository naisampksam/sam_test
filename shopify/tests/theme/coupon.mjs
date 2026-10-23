import { chromium } from 'playwright';
import fs from 'fs';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const page = (f) => fs.readFileSync('out/' + f, 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
let codes = [];
const updates = [];
const drawer = () => `<div data-cart-drawer><div class="cart-drawer__footer"><div class="cart-coupon" data-cart-coupon><div class="cart-coupon__row"><input data-cart-coupon-input><button type="button" data-cart-coupon-apply>Apply</button></div><p data-cart-coupon-msg hidden></p>${codes.length ? `<ul data-cart-coupon-codes="${codes.join(',')},">${codes.map((c) => `<li>${c}<button type="button" data-cart-coupon-remove="${c}">×</button></li>`).join('')}</ul>` : ''}</div></div></div>`;
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const errs = [];
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
for (const [name, file] of [['drawer', 'home.html'], ['cart page', 'cart.html']]) {
  codes = [];
  const p = await b.newPage({ viewport: { width: 390, height: 860 } });
  p.on('pageerror', (e) => errs.push(name + ': ' + e.message));
  await p.route('http://shop.test/**', async (route) => {
    const u = new URL(route.request().url());
    if (u.pathname.startsWith('/assets/')) return route.fulfill({ path: THEME + u.pathname.slice(8) });
    if (u.pathname === '/cart/update.js') {
      const body = JSON.parse(route.request().postData());
      updates.push(body.discount);
      const list = body.discount.split(',').filter(Boolean);
      const discount_codes = list.map((code) => ({ code, applicable: code.startsWith('HF') || code === 'WELCOME10' }));
      codes = discount_codes.filter((d) => d.applicable).map((d) => d.code);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ item_count: 2, discount_codes, sections: { 'cart-drawer': drawer() } }) });
    }
    return route.fulfill({ contentType: 'text/html', body: page(file) });
  });
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('fx-intro-seen', '1'); } catch (e) {} });
  await p.goto('http://shop.test/');
  await p.waitForTimeout(800);
  if (name === 'drawer') await p.evaluate(() => document.querySelector('[data-cart-drawer]').classList.add('is-open'));
  const box = () => p.locator(name === 'drawer' ? '[data-cart-drawer] [data-cart-coupon]' : 'main [data-cart-coupon]').first();
  if (name === 'drawer') {
    await box().locator('input').fill('hf10-abc123'); await box().locator('[data-cart-coupon-apply]').click(); await p.waitForTimeout(400);
    const vmsg = await box().locator('[data-cart-coupon-msg]').textContent();
    ok('drawer: valid code (typed lowercase) is applied and shown as a chip', codes.includes('HF10-ABC123') && await box().locator('[data-cart-coupon-remove]').count() === 1, JSON.stringify(vmsg));
    await box().locator('input').fill('FAKE50'); await box().locator('input').press('Enter'); await p.waitForTimeout(500);
    const imsg = await box().locator('[data-cart-coupon-msg]').textContent();
    ok('drawer: invalid code is refused and the valid one is kept', /FAKE50/.test(imsg) && codes.join(',') === 'HF10-ABC123', JSON.stringify(imsg));
    await box().locator('[data-cart-coupon-remove]').first().click(); await p.waitForTimeout(400);
    ok('drawer: × removes the code', codes.length === 0 && await box().locator('[data-cart-coupon-remove]').count() === 0);
    await p.screenshot({ path: 'out/coupon-drawer.png' });
  } else {
    await box().screenshot({ path: 'out/coupon-page.png' });
    let reloaded = false; p.on('framenavigated', () => { reloaded = true; });
    await box().locator('input').fill('FAKE50'); await box().locator('input').press('Enter'); await p.waitForTimeout(500);
    const pmsg = await box().locator('[data-cart-coupon-msg]').textContent();
    ok('cart page: Enter on an invalid code shows a message and does not submit checkout', /FAKE50/.test(pmsg) && !reloaded, JSON.stringify(pmsg));
    await box().locator('input').fill('WELCOME10'); await box().locator('[data-cart-coupon-apply]').click(); await p.waitForTimeout(800);
    ok('cart page: valid code reloads the page to show the discount', reloaded);
  }
  await p.close();
}
ok('codes are sent comma-separated to /cart/update.js', updates.some((u) => u === 'HF10-ABC123,FAKE50'), JSON.stringify(updates));
ok('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
