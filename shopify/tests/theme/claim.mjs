import { chromium } from 'playwright';
import worker from '../../dojo-rewards/worker.js';
const store = new Map();
const env = { TIERS: '[{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]', SHOP: 'haikufit.myshopify.com', CLIENT_ID: 'c', CLIENT_SECRET: 's', SIGNING_SECRET: 'y'.repeat(40), ALLOWED_ORIGINS: '', MIN_SUBTOTAL: '799', REWARDS: { get: async (k) => store.get(k) ?? null, put: async (k, v) => store.set(k, v), delete: async (k) => store.delete(k) } };
let shopifyUp = true; let blocked = false; const created = [];
const nodeFetch = globalThis.fetch;
globalThis.fetch = async (url, init) => {
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  if (!shopifyUp) return new Response('{}', { status: 503 });
  created.push(JSON.parse(init.body).variables.input.code);
  return new Response(JSON.stringify({ data: { discountCodeBasicCreate: { codeDiscountNode: { id: 'x' }, userErrors: [] } } }));
};
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const errs = [];
for (const w of [1280, 390]) {
  store.clear();
  const ctx = await b.newContext({ viewport: { width: w, height: 860 }, hasTouch: w < 500, isMobile: w < 500 });
  const p = await ctx.newPage();
  p.on('pageerror', (e) => errs.push(`[${w}] ${e.message}`));
  await p.route('https://rewards.test/**', async (route) => {
    const r = route.request();
    if (blocked) return route.abort();
    if (!/^https:\/\/rewards\.test\/(start|claim)$/.test(r.url())) return route.fulfill({ status: 404, body: 'wrong path ' + r.url() });
    if (r.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'Content-Type' } });
    const res = await worker.fetch(new Request(r.url(), { method: r.method(), headers: { 'Content-Type': 'application/json', 'CF-Connecting-IP': '9.9.9.' + w }, body: r.postData() }), env);
    route.fulfill({ status: res.status, headers: { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'application/json' }, body: await res.text() });
  });
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); localStorage.setItem('hf_dojo_sound', '0'); } catch (e) {} });
  await p.goto('file://' + process.cwd() + '/out/page-dojo.html'); await p.waitForTimeout(1200);
  const g = (f, a) => p.evaluate(f, a);
  const run = async (secs, score) => {
    await g(() => { const d = document.querySelector('[data-dojo-run]').dojoRun; d.start(); d.hitTest = () => false; });
    await p.waitForTimeout(secs * 1000);
    await g((sc) => { const d = document.querySelector('[data-dojo-run]').dojoRun; d.score = sc; d.gameOver(); }, score);
    await p.waitForTimeout(1600);
    return g(() => ({ text: document.querySelector('[data-dojo-over-body]').innerText.replace(/\s+/g, ' ').slice(0, 260), saved: localStorage.getItem('hf_dojo_reward'), pill: !!document.querySelector('.reward-pill') }));
  };
  const a = await run(2, 650); console.log(w, 'A cheat 650 in 2s:', a.text.includes("couldn't verify") ? 'REJECTED ✓' : 'FAIL', '| saved:', a.saved);
  const bb = await run(21, 330); const code = bb.saved && JSON.parse(bb.saved).code;
  console.log(w, 'B real 330 in 21s:', /HF50-[A-Z2-9]{6}/.test(bb.text) && code && created.includes(code) ? `CODE ${code} ✓` : 'FAIL', '| pill:', bb.pill, '| text:', bb.text);
  if (w === 1280) await p.locator('[data-dojo-run]').screenshot({ path: 'out/claim-win-1280.png' });
  const c = await run(1, 120); console.log(w, 'C low score keeps saved code:', c.text.includes(code) ? '✓' : 'FAIL');
  shopifyUp = false; await g(() => localStorage.removeItem('hf_dojo_reward'));
  const d = await run(21, 340); console.log(w, 'D Shopify down:', d.text.includes("couldn't create") ? 'friendly error ✓ ' + (d.text.match(/Ref: [^ ]+ ?[^ ]*/) || [''])[0] : 'FAIL ' + d.text);
  shopifyUp = true;
  blocked = true; await g(() => localStorage.removeItem('hf_dojo_reward'));
  const e = await run(21, 340); console.log(w, 'E network blocked:', e.text.includes('Ref: network_blocked') || e.text.includes('Ref: start_') ? 'ref shown ✓ ' + e.text.match(/Ref: [^ ]+/)[0] : 'FAIL ' + e.text);
  blocked = false;
  await ctx.close();
}
console.log('errors', errs);
await b.close();
