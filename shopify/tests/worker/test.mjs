import worker from '../../dojo-rewards/worker.js';
const store = new Map();
const KV = { get: async (k) => store.get(k) ?? null, put: async (k, v) => store.set(k, v), delete: async (k) => store.delete(k) };
const env = { SHOP: 'haikufit.myshopify.com', CLIENT_ID: 'cid', CLIENT_SECRET: 'csec', SIGNING_SECRET: 'x'.repeat(40), ALLOWED_ORIGINS: 'https://haikufit.in', TIERS: '[{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]', MIN_SUBTOTAL: '799', CODE_DAYS: '7', MAX_PER_IP: '2', DAILY_LIMIT: '200', REWARDS: KV };
const calls = [];
const realFetch = globalThis.fetch;
globalThis.fetch = async (url, init) => {
  calls.push({ url: String(url), body: init.body });
  if (String(url).endsWith('/admin/oauth/access_token')) return new Response(JSON.stringify({ access_token: 'shpat_test', expires_in: 86399 }), { status: 200 });
  const v = JSON.parse(init.body).variables.input;
  if (init.headers['X-Shopify-Access-Token'] !== 'shpat_test') return new Response('{}', { status: 401 });
  return new Response(JSON.stringify({ data: { discountCodeBasicCreate: { codeDiscountNode: { id: 'gid://shopify/DiscountCodeNode/1' }, userErrors: [] } } }));
};
let now = 1_800_000_000_000; Date.now = () => now; const RealDate = Date;
const req = (path, body, origin = 'https://haikufit.in', ip = '1.1.1.1') => worker.fetch(new Request('https://w.example' + path, { method: 'POST', headers: { Origin: origin, 'Content-Type': 'application/json', 'CF-Connecting-IP': ip }, body: JSON.stringify(body) }), env);
const j = async (r) => [r.status, await r.json()];
const ok = (name, cond, extra = '') => console.log(cond ? 'PASS' : 'FAIL', name, extra);

let t = await (await req('/start', {})).json();
ok('start returns ticket', t.run && t.sig);
now += 40_000; // 40s run
let [st, r] = await j(await req('/claim', { ...t, score: 640 }));
ok('640 pts after 40s -> ₹100 unique code', st === 200 && r.value === 100 && /^HF100-[A-Z2-9]{6}$/.test(r.code), JSON.stringify(r));
const sent = JSON.parse(calls.at(-1).body).variables.input;
ok('discount: single use, once per customer, min 799, 24 hours, all buyers', sent.usageLimit === 1 && sent.appliesOncePerCustomer && sent.minimumRequirement.subtotal.greaterThanOrEqualToSubtotal === '799.00' && sent.context.all === 'ALL' && sent.customerGets.value.discountAmount.amount === '100.00' && (new Date(sent.endsAt) - new Date(sent.startsAt)) === 24 * 3600000);
ok('one Dojo reward per order: refuses other order discounts, stacks with bundle and store credit', sent.combinesWith.orderDiscounts === false && sent.combinesWith.productDiscounts === true && sent.combinesWith.shippingDiscounts === true, JSON.stringify(sent.combinesWith));
[st, r] = await j(await req('/claim', { ...t, score: 640 }));
ok('same ticket reused -> rejected', r.error === 'used', JSON.stringify(r));
t = await (await req('/start', {})).json(); now += 5_000;
[st, r] = await j(await req('/claim', { ...t, score: 1000 }));
ok('1000 pts in 5s -> implausible', r.error === 'implausible', JSON.stringify(r));
t = await (await req('/start', {})).json(); now += 30_000;
[st, r] = await j(await req('/claim', { ...t, score: 200 }));
ok('below 300 -> no reward', r.error === 'no_reward');
t = await (await req('/start', {})).json(); now += 30_000;
[st, r] = await j(await req('/claim', { ...t, sig: t.sig.slice(0, -2) + 'AA', score: 400 }));
ok('forged signature -> rejected', r.error === 'bad_ticket');
[st, r] = await j(await req('/claim', { run: t.run, sig: t.sig, score: 400, percent: 15 }));
ok('client cannot choose reward (400 pts -> ₹50)', r.value === 50, JSON.stringify(r));
t = await (await req('/start', {})).json(); now += 70_000;
[st, r] = await j(await req('/claim', { ...t, score: 1100 }));
ok('3rd code same IP same day -> limit', r.error === 'limit', JSON.stringify(r));
t = await (await req('/start', {}, 'https://haikufit.in', '2.2.2.2')).json(); now += 40 * 60_000;
[st, r] = await j(await req('/claim', { ...t, score: 700 }, 'https://haikufit.in', '2.2.2.2'));
ok('ticket older than 30 min -> expired', r.error === 'expired');
[st, r] = await j(await req('/start', {}, 'https://evil.example'));
ok('other website -> 403', st === 403);
const pre = await worker.fetch(new Request('https://w.example/claim', { method: 'OPTIONS', headers: { Origin: 'https://haikufit.in' } }), env);
ok('CORS preflight', pre.status === 204 && pre.headers.get('Access-Control-Allow-Origin') === 'https://haikufit.in');
ok('token fetched once and cached', calls.filter((c) => c.url.includes('access_token')).length === 1);
// Shopify outage: run must stay claimable
globalThis.fetch = async (url) => String(url).endsWith('access_token') ? new Response(JSON.stringify({ access_token: 'shpat_test', expires_in: 86399 })) : new Response(JSON.stringify({ errors: [{ message: 'Throttled' }] }), { status: 503 });
t = await (await req('/start', {}, 'https://haikufit.in', '3.3.3.3')).json(); now += 40_000;
[st, r] = await j(await req('/claim', { ...t, score: 400 }, 'https://haikufit.in', '3.3.3.3'));
ok('Shopify down -> 500 error', st === 500, JSON.stringify(r));
globalThis.fetch = async (url) => String(url).endsWith('access_token') ? new Response(JSON.stringify({ access_token: 'shpat_test', expires_in: 86399 })) : new Response(JSON.stringify({ data: { discountCodeBasicCreate: { codeDiscountNode: { id: 'x' }, userErrors: [] } } }));
[st, r] = await j(await req('/claim', { ...t, score: 400 }, 'https://haikufit.in', '3.3.3.3'));
ok('same run retried after outage -> code', st === 200 && r.code, JSON.stringify(r));

// old percentage TIERS in Cloudflare are ignored: winners get fixed ₹ rewards
{
  const { readTiersForTest } = { readTiersForTest: null };
  const envOld = { ...env, TIERS: '[{"score":300,"percent":5},{"score":600,"percent":10},{"score":1000,"percent":15}]' };
  const res = await worker.fetch(new Request('https://w.dev/health?key=owner-key-1234567890', { headers: {} }), { ...envOld, ADMIN_KEY: 'owner-key-1234567890' });
  const h = await res.json();
  ok('old % TIERS are ignored, ₹50/₹100/₹150 used instead', /old % tiers ignored/.test(h.checks.TIERS) && /₹150/.test(h.checks.TIERS), h.checks.TIERS);
  const envPct = { ...envOld, ALLOW_PERCENT: 'true', ADMIN_KEY: 'owner-key-1234567890' };
  const h2 = await (await worker.fetch(new Request('https://w.dev/health?key=owner-key-1234567890'), envPct)).json();
  ok('ALLOW_PERCENT=true still allows % tiers on purpose', /15%/.test(h2.checks.TIERS), h2.checks.TIERS);
}
