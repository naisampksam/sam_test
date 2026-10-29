// Dojo Run monthly rewards in the browser:
// guest win -> sign in -> code -> order -> locked -> unlock page (review the tee) -> bonus battle -> code
import { chromium } from 'playwright';
import worker from '../../dojo-rewards/worker.js';
const store = new Map();
const env = { TIERS: '[{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]', SHOP: 'haikufit.myshopify.com', CLIENT_ID: 'c', CLIENT_SECRET: 's', SIGNING_SECRET: 'y'.repeat(40), CREDIT_SECRET: 'credit-secret-123', JUDGEME_TOKEN: 'jm', ALLOWED_ORIGINS: '', REWARDS: { get: async (k) => store.get(k) ?? null, put: async (k, v) => store.set(k, v), delete: async (k) => store.delete(k) } };
const discounts = new Map(); const orders = new Map(); let reviews = []; const tags = [];
const RONIN = { legacyResourceId: '11', handle: 'ronin-oversized-t-shirt', title: 'Ronin Oversized T-Shirt' };
globalThis.fetch = async (url, init) => {
  url = String(url);
  if (url.endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  if (url.startsWith('https://api.judge.me/')) return new Response(JSON.stringify({ reviews }));
  const { query, variables } = JSON.parse(init.body);
  const ok = (data) => new Response(JSON.stringify({ data }));
  if (query.includes('discountCodeBasicCreate')) { const id = 'gid://d/' + variables.input.code; discounts.set(id, variables.input); return ok({ discountCodeBasicCreate: { codeDiscountNode: { id }, userErrors: [] } }); }
  if (query.includes('discountCodeDelete')) { discounts.delete(variables.id); return ok({ discountCodeDelete: { deletedCodeDiscountId: variables.id } }); }
  if (query.includes('orders(first: 1')) {
    const o = orders.get(variables.q.replace('discount_code:', ''));
    return ok({ orders: { nodes: o ? [{ id: 'gid://o/1', name: '#1042', createdAt: o, lineItems: { nodes: [{ title: RONIN.title, product: { ...RONIN, featuredImage: null } }] } }] : [] } });
  }
  if (query.includes('codeDiscountNode')) return ok({ codeDiscountNode: { codeDiscount: { asyncUsageCount: 0 } } });
  if (query.includes('customer(id')) return ok({ customer: { email: 'arjun@example.com' } });
  if (query.includes('tagsAdd')) { tags.push(variables.tags[0]); return ok({ tagsAdd: { userErrors: [] } }); }
  return ok({});
};
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const errs = [];
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const ctx = await b.newContext({ viewport: { width: 390, height: 860 }, hasTouch: true, isMobile: true });
const calls = [];
await ctx.route('https://rewards.test/**', async (route) => {
  const r = route.request();
  if (r.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'Content-Type' } });
  if (!/^https:\/\/rewards\.test\/(start|claim|dojo\/redeem|dojo\/status)$/.test(r.url())) return route.fulfill({ status: 404, body: 'wrong path ' + r.url() });
  calls.push(r.url().replace('https://rewards.test/', ''));
  const res = await worker.fetch(new Request(r.url(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: r.postData() }), env);
  if (process.env.DEBUG) console.log('DEBUG', r.url().slice(21), res.status, (await res.clone().text()).slice(0, 160));
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
const meta = (p) => p.evaluate(() => document.querySelector('[data-dojo-start-meta]').innerText.replace(/\s+/g, ' '));

// 1. guest wins: asked to sign in, nothing created yet
let p = await open('page-dojo');
let r = await run(p, 41, 650);
await p.screenshot({ path: 'out/monthly-signin.png' });
ok('guest win -> "Sign in to claim ₹100 OFF" linking to login', /Sign in to claim (₹|Rs\. )100 OFF/i.test(r.text) && r.html.includes('/account/login?return_url=') && !r.saved && discounts.size === 0, r.text.slice(0, 200));
ok('win held in the browser', r.held && JSON.parse(r.held).value === 100);
ok('no daily battle counter (unlimited)', !/left today/i.test(r.text) && /Fight again/i.test(r.text));
await p.waitForTimeout(1200); await p.close(); // Chromium writes browser storage in batches

// 1b. signed in, never ordered with a code: unlock page says no review is needed
p = await open('page-dojo-unlock-member');
let fresh = await p.evaluate(() => ({ text: document.querySelector('.dojo-unlock').innerText, intro: document.querySelector('[data-dojo-unlock-intro]').offsetParent !== null }));
ok('unlock page, no Dojo order yet: chance ready, no review needed, intro hidden, play button', /chance is ready, no review needed/i.test(fresh.text) && !fresh.intro && /Play Dojo Run/i.test(fresh.text), JSON.stringify(fresh).slice(0, 220));
await p.close();

// 2. signs in, comes back: held win becomes their 30-day code
p = await open('page-dojo-member');
await p.waitForFunction(() => localStorage.getItem('hf_dojo_reward'), null, { timeout: 5000 }).catch(() => {});
let s = await p.evaluate(() => ({ saved: localStorage.getItem('hf_dojo_reward'), held: localStorage.getItem('hf_dojo_pending') }));
const code = s.saved && JSON.parse(s.saved).code;
const made = discounts.get('gid://d/' + code) || { context: { customers: { add: [] } } };
if (!code) console.log('DEBUG calls so far', calls.join(' '));
ok('after sign-in the ₹100 code is created and saved', /^HF-[0-9A-F]{10}$/.test(code || '') && !s.held && (await meta(p)).includes(code), JSON.stringify(s));
ok('code only for customer 7001, valid 30 days', made.context.customers.add[0] === 'gid://shopify/Customer/7001' && new Date(made.endsAt) - new Date(made.startsAt) === 30 * 86400000);
await p.waitForTimeout(1200); await p.close(); // Chromium writes browser storage in batches

// 3. orders with the code -> next visit shows the lock before playing
orders.set(code, new Date().toISOString());
p = await open('page-dojo-member');
await p.waitForTimeout(800);
const m = await meta(p);
ok('page load: locked message, unlock date and bonus link', /unlocks on \d+ \w+/i.test(m) && /Unlock a bonus battle/i.test(m), m);
ok('used code no longer shown or saved', !m.includes(code) && !(await p.evaluate(() => localStorage.getItem('hf_dojo_reward'))));
const before = calls.filter((c) => c === 'claim').length;
r = await run(p, 41, 700);
await p.screenshot({ path: 'out/monthly-locked.png' });
ok('win while locked: no code, unlock date, bonus battle button', /This month's reward is used/i.test(r.text) && /unlocks on \d+ \w+/i.test(r.text) && r.html.includes('/pages/dojo-unlock') && !/Reach 1000/i.test(r.text) && discounts.size === 1, r.text.slice(0, 220));
ok('locked: game does not ask the server for a code', calls.filter((c) => c === 'claim').length === before);
await p.waitForTimeout(1200); await p.close(); // Chromium writes browser storage in batches

// 4. thank-you card QR -> unlock page: signed out asks to sign in
p = await open('page-dojo-unlock');
ok('unlock page signed out -> sign in', /Sign in with the account/i.test(await p.innerText('.dojo-unlock')) && (await p.getAttribute('.dojo-unlock a.button', 'href')).includes('/account/login?return_url='));
await p.waitForTimeout(1200); await p.close(); // Chromium writes browser storage in batches

// 5. signed in: lists the tee to review; scanning alone unlocks nothing
p = await open('page-dojo-unlock-member');
let txt = await p.innerText('.dojo-unlock');
ok('unlock page, locked: review intro shown', await p.evaluate(() => document.querySelector('[data-dojo-unlock-intro]').offsetParent !== null));
ok('unlock page: locked, shows the Ronin tee from order #1042 to review', /next battle unlocks on/i.test(txt) && /Ronin Oversized T-Shirt/.test(txt) && /#1042/.test(txt) && (await p.getAttribute('.dojo-unlock__tee a', 'href')).includes('/products/ronin-oversized-t-shirt#judgeme_product_reviews'), txt);
await p.screenshot({ path: 'out/unlock-locked.png', fullPage: true });
await p.click('[data-dojo-check]'); await p.waitForTimeout(600);
txt = await p.innerText('.dojo-unlock');
ok('"check again" without a review -> still locked, explains publishing', /can't see your review yet/i.test(txt), txt.slice(0, 200));
reviews = [{ id: 1, created_at: new Date(Date.now() + 1000).toISOString(), published: true, reviewer: { email: 'arjun@example.com' }, product_external_id: 11, product_handle: RONIN.handle, product_title: RONIN.title, pictures: [] }];
await p.click('[data-dojo-check]'); await p.waitForTimeout(600);
txt = await p.innerText('.dojo-unlock');
ok('review published -> bonus battle unlocked, play button', /bonus battle is unlocked/i.test(txt) && (await p.getAttribute('.dojo-unlock a.button', 'href')).includes('/pages/dojo-run'), txt.slice(0, 200));
ok('customer tagged dojo-bonus for the email', tags.includes('dojo-bonus'), tags.join());
await p.screenshot({ path: 'out/unlock-ready.png', fullPage: true });
await p.waitForTimeout(1200); await p.close(); // Chromium writes browser storage in batches

// 6. bonus battle -> a second code this month
p = await open('page-dojo-member');
r = await run(p, 41, 700);
ok('bonus battle -> new code', /Review bonus/i.test(r.text) && /HF-[0-9A-F]{10}/.test(r.text) && r.saved && JSON.parse(r.saved).code !== code, r.text.slice(0, 200));
await p.waitForTimeout(1200); await p.close(); // Chromium writes browser storage in batches
ok('no page errors', errs.length === 0, errs.join(' | '));
await b.close();
