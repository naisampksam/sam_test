import worker from '../../dojo-rewards/worker.js';
import crypto from 'node:crypto';
const store = new Map(); const meta = new Map();
let writes = 0;
const KV = {
  get: async (k) => store.get(k) ?? null,
  put: async (k, v, o) => { if (k !== 'log') writes++; store.set(k, v); if (o && o.metadata) meta.set(k, o.metadata); },
  delete: async (k) => { store.delete(k); meta.delete(k); },
  list: async ({ prefix }) => ({ keys: [...store.keys()].filter((k) => k.startsWith(prefix)).map((name) => ({ name, metadata: meta.get(name) })), list_complete: true })
};
const env = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), CREDIT_SECRET: 'credit-secret-123', ALLOWED_ORIGINS: 'https://haikufit.in', TIERS: '[{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]', JUDGEME_TOKEN: 'jm', REWARDS: KV };
const discounts = new Map(); const orders = new Map(); let reviews = []; let judgeDown = false; let lastInput = null; let jmUrl = ''; const tags = [];
let now = 1_800_000_000_000; const RealDate = Date;
globalThis.Date = class extends RealDate { constructor(...a) { super(...(a.length ? a : [now])); } static now() { return now; } };
const iso = (ms) => new RealDate(ms).toISOString();
const RONIN = { id: '11', handle: 'ronin-tee', title: 'Ronin Oversized T-Shirt' };
const SHOGUN = { id: '12', handle: 'shogun-tee', title: 'Shogun Oversized T-Shirt' };
globalThis.fetch = async (url, init) => {
  url = String(url);
  if (url.endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  if (url.startsWith('https://api.judge.me/')) { jmUrl = url; return judgeDown ? new Response('down', { status: 503 }) : new Response(JSON.stringify({ reviews })); }
  const { query, variables } = JSON.parse(init.body);
  const ok = (data) => new Response(JSON.stringify({ data }));
  if (query.includes('discountCodeBasicCreate')) { lastInput = variables.input; const id = 'gid://d/' + variables.input.code; discounts.set(id, { used: 0, code: variables.input.code }); return ok({ discountCodeBasicCreate: { codeDiscountNode: { id }, userErrors: [] } }); }
  if (query.includes('discountCodeDelete')) { discounts.delete(variables.id); return ok({ discountCodeDelete: { deletedCodeDiscountId: variables.id } }); }
  if (query.includes('orders(first: 1')) {
    const o = orders.get(variables.q.replace('discount_code:', ''));
    return ok({ orders: { nodes: o ? [{ id: o.id, name: o.name, createdAt: o.at, lineItems: { nodes: o.products.map((p) => ({ title: p.title, product: { legacyResourceId: p.id, handle: p.handle, featuredImage: { url: 'https://cdn/x.jpg' } } })) } }] : [] } });
  }
  if (query.includes('codeDiscountNode')) { const d = discounts.get(variables.id); return ok({ codeDiscountNode: d ? { codeDiscount: { asyncUsageCount: d.used } } : null }); }
  if (query.includes('customer(id')) return ok({ customer: { email: variables.id.endsWith('/7001') ? 'Ravi@Example.com' : 'other@example.com' } });
  if (query.includes('tagsAdd')) { tags.push(`${variables.id.split('/').pop()}:${variables.tags[0]}`); return ok({ tagsAdd: { userErrors: [] } }); }
  return ok({});
};
const sign = (id, ts) => crypto.createHmac('sha256', env.CREDIT_SECRET).update(`${id}:${ts}`).digest('hex');
const who = (id) => { const ts = Math.floor(now / 1000); return { customer: id, ts, sig: sign(id, ts) }; };
const call = async (path, body) => { const r = await worker.fetch(new Request('https://w.dev' + path, { method: 'POST', headers: { Origin: 'https://haikufit.in', 'CF-Connecting-IP': '9.9.9.9' }, body: JSON.stringify(body) }), env); return r.json(); };
const play = async (score, me) => { const t = await call('/start', {}); now += 70_000; return call('/claim', { ...t, score, ...(me ? { me } : {}) }); };
let orderNo = 1000;
const buy = (code, products, at = now) => { orders.set(code, { id: 'gid://o/' + (++orderNo), name: '#' + orderNo, at: iso(at), products }); };
const review = (id, product, at, extra = {}) => reviews.push({ id, created_at: iso(at), published: true, reviewer: { email: 'ravi@example.com' }, product_external_id: Number(product.id), product_handle: product.handle, product_title: product.title, pictures: [], ...extra });
const sweep = async () => { await worker.scheduled({}, env, { waitUntil: (p) => p }); await new Promise((r) => setTimeout(r, 30)); };
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const DAY = 86400000;

// guest: win is held, no storage used per game
writes = 0;
let r = await play(700);
ok('guest win -> sign in to claim (no code yet)', r.needLogin && r.pending && r.value === 100 && !r.code, JSON.stringify(r));
ok('unlimited play costs no storage writes (besides the request log)', writes === 0, 'writes ' + writes);
for (let i = 0; i < 8; i++) await play(400);
ok('play as often as you like: never a daily limit', (await play(650)).needLogin);
const held = r.pending;
r = await call('/dojo/redeem', { pending: held, ...who('7001') });
ok('after sign-in the held win becomes a ₹100 code', /^HF100-/.test(r.code) && r.value === 100, JSON.stringify(r));
ok('code: only this customer, single use', lastInput.context.customers.add[0] === 'gid://shopify/Customer/7001' && !lastInput.context.all && lastInput.usageLimit === 1);
ok('code valid for 30 days', new Date(lastInput.endsAt) - new Date(lastInput.startsAt) === 30 * DAY);
const c1 = r.code;
r = await call('/dojo/redeem', { pending: held.slice(0, -3) + 'aaa', ...who('7001') });
ok('tampered held win -> rejected', r.error === 'bad_ticket', JSON.stringify(r));
const forged = Buffer.from(JSON.stringify({ s: 1000, e: 9e9 })).toString('base64url');
r = await call('/dojo/redeem', { pending: `${forged}.${held.split('.')[1]}`, ...who('7001') });
ok('cannot raise a held win to ₹150', r.error === 'bad_ticket');
r = await call('/dojo/redeem', { pending: held, customer: '7001', ts: 1, sig: 'x' });
ok('redeem without a valid sign-in -> rejected', r.error === 'bad_customer');
r = await call('/dojo/status', { customer: '7001', ts: 1, sig: 'x' });
ok('status without a valid sign-in -> rejected', r.error === 'bad_customer');

// same code while unused; upgrade on a better score
r = await play(420, who('7001'));
ok('lower score while code unused -> same code back', r.code === c1 && r.reused, JSON.stringify(r));
r = await play(1200, who('7001'));
ok('better score -> upgraded to ₹150, old code deleted', /^HF150-/.test(r.code) && !discounts.has('gid://d/' + c1), JSON.stringify(r));
const c2 = r.code;
r = await call('/dojo/status', who('7001'));
ok('status shows the code they hold', r.state === 'code' && r.code === c2, JSON.stringify(r));

// order with the code -> locked for the month
const firstUse = now; buy(c2, [RONIN]); now += 2 * DAY;
r = await play(1200, who('7001'));
ok('used -> no new code this month, shows unlock date', r.error === 'used_this_month' && new Date(r.nextAt).getTime() - firstUse === 30 * DAY, JSON.stringify(r));
r = await call('/dojo/status', who('7001'));
ok('status: locked, asks to review the Ronin tee from that order', r.state === 'locked' && r.toReview.length === 1 && r.toReview[0].products[0].handle === 'ronin-tee' && r.toReview[0].products[0].image, JSON.stringify(r));
r = await call('/dojo/redeem', { pending: (await play(700)).pending, ...who('7001') });
ok('signing in with a held win while locked -> also locked', r.error === 'used_this_month');

// which reviews count
review(1, RONIN, firstUse - 5 * DAY);
ok('review written BEFORE the order does not count', (await call('/dojo/status', who('7001'))).state === 'locked');
review(2, SHOGUN, now - 1000);
ok('review of a tee that was not in that order does not count', (await call('/dojo/status', who('7001'))).state === 'locked');
review(3, RONIN, now - 900, { published: false });
ok('unpublished (rejected) review does not count', (await call('/dojo/status', who('7001'))).state === 'locked');
reviews.push({ id: 4, created_at: iso(now - 800), published: true, reviewer: { email: 'someone@else.com' }, product_external_id: 11, pictures: [] });
ok('someone else\'s review does not count (filter not trusted)', (await call('/dojo/status', who('7001'))).state === 'locked');
judgeDown = true;
ok('Judge.me down -> no crash, still locked', (await call('/dojo/status', who('7001'))).state === 'locked');
judgeDown = false;
env.REVIEW_NEEDS_PHOTO = 'true';
review(5, RONIN, now - 700);
ok('photo-only mode: text review does not count', (await call('/dojo/status', who('7001'))).state === 'locked');
review(6, RONIN, now - 600, { pictures: [{ hidden: false }] });
r = await call('/dojo/status', who('7001'));
ok('photo review of the Ronin tee -> bonus chance unlocked', r.state === 'ready' && r.bonus, JSON.stringify(r));
ok('Judge.me asked for this customer\'s reviews only', jmUrl.includes('reviewer_email=Ravi%40Example.com') && jmUrl.includes('shop_domain=x.myshopify.com'));
ok('customer tagged dojo-bonus (bonus email)', tags.includes('7001:dojo-bonus'), tags.join());
delete env.REVIEW_NEEDS_PHOTO;

// second chance, then the cycle repeats with the next order
r = await play(700, who('7001'));
ok('bonus chance -> a new code in the same month', /^HF100-/.test(r.code) && r.bonus, JSON.stringify(r));
const c3 = r.code;
buy(c3, [SHOGUN]); now += 3 * DAY;
r = await call('/dojo/status', who('7001'));
ok('bonus code used -> locked again, now asks for the Shogun review', r.state === 'locked' && r.toReview.length === 1 && r.toReview[0].products[0].handle === 'shogun-tee' && new Date(r.nextAt).getTime() - firstUse === 30 * DAY, JSON.stringify(r));
review(7, RONIN, now - 100);
ok('another Ronin review does not unlock the Shogun order', (await call('/dojo/status', who('7001'))).state === 'locked');
review(8, SHOGUN, now - 50);
r = await play(700, who('7001'));
ok('Shogun review -> third chance this month', /^HF100-/.test(r.code), JSON.stringify(r));
const c4 = r.code;

// month ends while away: the sweep notices and tags dojo-ready
buy(c4, [RONIN]); now += 2 * DAY;
await sweep();
r = await call('/dojo/status', who('7001'));
ok('sweep picks up the order while the customer is away', r.state === 'locked', JSON.stringify(r));
now = firstUse + 30 * DAY + 1000;
tags.length = 0;
await sweep();
ok('30 days after the first use -> tagged dojo-ready (reminder email)', tags.includes('7001:dojo-ready'), tags.join());
await sweep();
ok('reminder tag sent only once', tags.filter((t) => t === '7001:dojo-ready').length === 1, tags.join());
r = await play(700, who('7001'));
ok('new month -> a new code', /^HF100-/.test(r.code) && !r.bonus, JSON.stringify(r));

// unused code expires -> can win a new one
const c5 = r.code; now += 31 * DAY;
r = await play(700, who('7001'));
ok('unused code expired -> a fresh code', /^HF100-/.test(r.code) && r.code !== c5, JSON.stringify(r));

// different customers are independent; replaying a run gives nothing extra
const t = await call('/start', {}); now += 70_000;
const a = await call('/claim', { ...t, score: 700, me: who('7002') });
const b = await call('/claim', { ...t, score: 700, me: who('7002') });
ok('second customer gets their own code; replay returns the same code', /^HF100-/.test(a.code) && b.code === a.code && a.code !== r.code, JSON.stringify([a, b]));

// fallback for checkouts that can't match the customer
env.LOCK_TO_CUSTOMER = 'false';
r = await play(700, who('7009'));
ok('LOCK_TO_CUSTOMER=false -> code for all buyers, still tracked per customer', /^HF100-/.test(r.code) && lastInput.context.all === 'ALL' && JSON.parse(store.get('dojo:cust:7009')).code === r.code, JSON.stringify(lastInput.context));
delete env.LOCK_TO_CUSTOMER;

// expired held win
const g = await play(700); now += 3 * 3600_000;
r = await call('/dojo/redeem', { pending: g.pending, ...who('7003') });
ok('held win older than 2 hours -> expired', r.error === 'expired', JSON.stringify(r));
globalThis.Date = RealDate;
