import { chromium } from 'playwright';
import fs from 'fs';
import worker from '../../dojo-rewards/worker.js';
const THEME = new URL('../../theme/assets/', import.meta.url).pathname;
const html = fs.readFileSync('out/cart-member.html', 'utf8').replaceAll('file://' + THEME, 'http://shop.test/assets/');
const store = new Map();
const env = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), CREDIT_SECRET: 'credit-secret-123', ALLOWED_ORIGINS: 'http://shop.test', REWARDS: { get: async (k) => store.get(k) ?? null, put: async (k, v) => store.set(k, v), delete: async (k) => store.delete(k), list: async () => ({ keys: [] }) } };
let balance = 250; const discounts = new Map(); let cartCodes = [];
const nodeFetch = globalThis.fetch;
globalThis.fetch = async (url, init) => {
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  const { query, variables } = JSON.parse(init.body);
  const ok = (data) => new Response(JSON.stringify({ data }));
  if (query.includes('storeCreditAccounts')) return ok({ customer: { storeCreditAccounts: { nodes: [{ id: 'acc', balance: { amount: balance.toFixed(2), currencyCode: 'INR' } }] } } });
  if (query.includes('storeCreditAccountDebit')) { balance -= parseFloat(variables.input.debitAmount.amount); return ok({ storeCreditAccountDebit: { storeCreditAccountTransaction: {}, userErrors: [] } }); }
  if (query.includes('storeCreditAccountCredit')) { balance += parseFloat(variables.input.creditAmount.amount); return ok({ storeCreditAccountCredit: { storeCreditAccountTransaction: {}, userErrors: [] } }); }
  if (query.includes('discountCodeBasicCreate')) { discounts.set('d1', variables.input.code); return ok({ discountCodeBasicCreate: { codeDiscountNode: { id: 'd1' }, userErrors: [] } }); }
  if (query.includes('discountCodeDelete')) { discounts.delete('d1'); return ok({ discountCodeDelete: { deletedCodeDiscountId: 'd1' } }); }
  if (query.includes('codeDiscountNode')) return ok({ codeDiscountNode: discounts.has('d1') ? { codeDiscount: { asyncUsageCount: 0 } } : null });
  return ok({});
};
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const p = await b.newPage({ viewport: { width: 390, height: 900 } });
const errs = []; p.on('pageerror', (e) => errs.push(e.message));
let reloads = 0;
await p.route('http://shop.test/**', async (route) => {
  const u = new URL(route.request().url());
  if (u.pathname.startsWith('/assets/')) return route.fulfill({ path: THEME + u.pathname.slice(8) });
  if (u.pathname === '/cart/update.js') {
    const body = JSON.parse(route.request().postData());
    const asked = body.discount.split(',').filter(Boolean);
    const clash = asked.some((c) => c.startsWith('HF')) && asked.some((c) => c.startsWith('CR-'));
    cartCodes = asked;
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ item_count: 3, discount_codes: asked.map((code) => ({ code, applicable: !(clash && code.startsWith('CR-')) })), sections: {} }) });
  }
  reloads++;
  return route.fulfill({ contentType: 'text/html', body: html });
});
await p.route('https://rewards.test/**', async (route) => {
  const r = route.request();
  if (r.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': 'http://shop.test', 'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Allow-Methods': 'POST' } });
  const res = await worker.fetch(new Request(r.url(), { method: 'POST', headers: { Origin: 'http://shop.test', 'Content-Type': 'application/json' }, body: r.postData() }), env);
  route.fulfill({ status: res.status, headers: { 'Access-Control-Allow-Origin': 'http://shop.test', 'Content-Type': 'application/json' }, body: await res.text() });
});
await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('fx-intro-seen', '1'); } catch (e) {} });
await p.goto('http://shop.test/cart'); await p.waitForTimeout(800);
await p.evaluate(() => { const box = document.querySelector('main [data-cart-coupon]'); box.insertAdjacentHTML('beforeend', '<ul data-cart-coupon-codes="HF10-DE32VW,"><li>HF10-DE32VW</li></ul>'); });
const card = p.locator('main [data-credit-use]');
await card.scrollIntoViewIfNeeded(); await card.screenshot({ path: 'out/credit-card.png' });
await card.locator('[data-credit-redeem]').click(); await p.waitForTimeout(1200);
console.log('credit msg:', await p.locator('main [data-credit-msg]').textContent(), '| coupon msg hidden:', await p.locator('main [data-cart-coupon-msg]').isHidden());
console.log('after Use: cart codes', JSON.stringify(cartCodes), '| balance', balance, '| page reloaded', reloads > 1);
console.log('errors', errs);
await b.close();
