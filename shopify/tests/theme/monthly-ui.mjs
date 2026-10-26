// Dojo Run monthly rewards in the browser: guest win -> sign in -> code; used -> locked; review -> bonus
import { chromium } from 'playwright';
import worker from '../../dojo-rewards/worker.js';
const store = new Map();
const env = { TIERS: '[{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]', SHOP: 'haikufit.myshopify.com', CLIENT_ID: 'c', CLIENT_SECRET: 's', SIGNING_SECRET: 'y'.repeat(40), CREDIT_SECRET: 'credit-secret-123', JUDGEME_TOKEN: 'jm', ALLOWED_ORIGINS: '', REWARDS: { get: async (k) => store.get(k) ?? null, put: async (k, v) => store.set(k, v), delete: async (k) => store.delete(k) } };
const discounts = new Map(); const orders = new Map(); let reviews = [];
globalThis.fetch = async (url, init) => {
  url = String(url);
  if (url.endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  if (url.startsWith('https://api.judge.me/')) return new Response(JSON.stringify({ reviews }));
  const { query, variables } = JSON.parse(init.body);
  const ok = (data) => new Response(JSON.stringify({ data }));
  if (query.includes('discountCodeBasicCreate')) { const id = 'gid://d/' + variables.input.code; discounts.set(id, variables.input); return ok({ discountCodeBasicCreate: { codeDiscountNode: { id }, userErrors: [] } }); }
  if (query.includes('discountCodeDelete')) { discounts.delete(variables.id); return ok({ discountCodeDelete: { deletedCodeDiscountId: variables.id } }); }
  if (query.includes('orders(first: 1')) { const c = variables.q.replace('discount_code:', ''); return ok({ orders: { nodes: orders.has(c) ? [{ createdAt: orders.get(c) }] : [] } }); }
  if (query.includes('codeDiscountNode')) return ok({ codeDiscountNode: { codeDiscount: { asyncUsageCount: 0 } } });
  if (query.includes('customer(id')) return ok({ customer: { email: 'arjun@example.com' } });
  return ok({});
};
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const errs = [];
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, hasTouch: true, isMobile: true });
await ctx.route('https://rewards.test/**', async (route) => {
  const r = route.request();
  if (r.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'Content-Type' } });
  if (!/^https:\/\/rewards\.test\/(start|claim|dojo\/redeem)$/.test(r.url())) return route.fulfill({ status: 404, body: 'wrong path ' + r.url() });
  const res = await worker.fetch(new Request(r.url(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: r.postData() }), env);
  route.fulfill({ status: res.status, headers: { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'application/json' }, body: await res.text() });
});
await ctx.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); localStorage.setItem('hf_dojo_sound', '0'); } catch (e) {} });
const open = async (page) => { const p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message)); await p.goto('file://' + process.cwd() + `/out/${page}.html`); await p.waitForTimeout(1500); return p; };
const run = async (p, secs, score) => {
  await p.evaluate(() => { const d = document.querySelector('[data-dojo-run]').dojoRun; d.start(); d.hitTest = () => false; });
  await p.waitForTimeout(secs * 1000);
  await p.evaluate((sc) => { const d = document.querySelector('[data-dojo-run]').dojoRun; d.score = sc; d.gameOver(); }, score);
  await p.waitForTimeout(1600);
  return p.evaluate(() => ({ text: document.querySelector('[data-dojo-over-body]').innerText.replace(/\s+/g, ' '), html: document.querySelector('[data-dojo-over-body]').innerHTML, saved: localStorage.getItem('hf_dojo_reward'), held: localStorage.getItem('hf_dojo_pending') }));
};

// 1. guest wins: asked to sign in, nothing created yet
let p = await open('page-dojo');
let r = await run(p, 41, 650);
await p.screenshot({ path: 'out/monthly-signin.png' });
ok('guest win -> "Sign in to claim ₹100 OFF" linking to login', /Sign in to claim (₹|Rs\. )100 OFF/i.test(r.text) && r.html.includes('/account/login?return_url=') && !r.saved && discounts.size === 0, r.text.slice(0, 200));
ok('win held in the browser', r.held && JSON.parse(r.held).value === 100);
ok('no daily battle counter (unlimited)', !/left today/i.test(r.text) && /Fight again/i.test(r.text));
r = await run(p, 21, 330);
ok('lower score later keeps the ₹100 win', /Sign in to claim (₹|Rs\. )100 OFF/i.test(r.text) && JSON.parse(r.held).value === 100, r.text.slice(0, 120));
await p.close();

// 2. signs in, comes back: held win becomes their code
p = await open('page-dojo-member');
await p.waitForTimeout(800);
let s = await p.evaluate(() => ({ saved: localStorage.getItem('hf_dojo_reward'), held: localStorage.getItem('hf_dojo_pending'), meta: document.querySelector('[data-dojo-start-meta]').innerText }));
const code = s.saved && JSON.parse(s.saved).code;
ok('after sign-in the ₹100 code is created and saved', /^HF100-/.test(code || '') && !s.held && s.meta.includes(code), JSON.stringify(s));
ok('code locked to customer 7001', discounts.get('gid://d/' + code).context.customers.add[0] === 'gid://shopify/Customer/7001');

// 3. code used at checkout -> next win shows the monthly lock + review link
orders.set(code, new Date().toISOString());
await p.evaluate(() => localStorage.removeItem('hf_dojo_reward'));
r = await run(p, 41, 700);
ok('used this month -> unlock date shown, no new code', /This month's reward is used/i.test(r.text) && /unlocks on \d+ \w+/i.test(r.text) && !/Reach 1000/i.test(r.text) && discounts.size === 1, r.text.slice(0, 220));
ok('review link to the tee they bought', r.html.includes('/products/ronin-oversized-t-shirt#judgeme_product_reviews') && /Write a review/i.test(r.text), r.html.slice(-600));
await p.screenshot({ path: 'out/monthly-locked.png' });
const calls = []; p.on('request', (q) => { if (q.url().includes('/claim')) calls.push(q.url()); });
r = await run(p, 41, 700);
ok('locked: no repeat server calls in the same visit', calls.length === 0 && /reward is used/i.test(r.text), calls.length);
await p.close();

// 4. writes a photo review -> one more reward this month
reviews = [{ id: 1, created_at: new Date().toISOString(), published: true, reviewer: { email: 'arjun@example.com' }, pictures: [{ hidden: false }] }];
p = await open('page-dojo-member');
r = await run(p, 41, 700);
ok('after a review -> bonus code', /Review bonus/.test(r.text) && /HF100-/.test(r.text) && r.saved && JSON.parse(r.saved).code !== code, r.text.slice(0, 200));
await p.screenshot({ path: 'out/monthly-bonus.png' });
await p.close();
ok('no page errors', errs.length === 0, errs.join(' | '));
await b.close();
