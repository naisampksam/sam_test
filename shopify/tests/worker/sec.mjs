import worker from '../../dojo-rewards/worker.js';
const m = new Map(); let quotaHit = false;
const KV = { get: async (k) => m.get(k) ?? null, put: async (k, v) => { if (quotaHit) throw new Error('KV put() limit exceeded for the day.'); m.set(k, v); }, delete: async (k) => m.delete(k) };
const env = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), ALLOWED_ORIGINS: 'https://haikufit.in', ADMIN_KEY: 'owner-key-1234567890', REWARDS: KV };
globalThis.fetch = async (url, init) => {
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  const q = JSON.parse(init.body).query;
  if (q.includes('accessScopes')) return new Response(JSON.stringify({ data: { shop: { name: 'H' }, currentAppInstallation: { accessScopes: [{ handle: 'write_discounts' }, { handle: 'read_orders' }] } } }));
  if (q.includes('orders(')) return new Response(JSON.stringify({ data: { orders: { nodes: [] } } }));
  return new Response(JSON.stringify({ data: { discountCodeBasicCreate: { codeDiscountNode: { id: 'x' }, userErrors: [] } } }));
};
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const get = async (u, e = env) => { const r = await worker.fetch(new Request('https://w.dev' + u), e); return [r.status, await r.json()]; };
const post = async (path, body, ip = '1.1.1.1') => (await worker.fetch(new Request('https://w.dev' + path, { method: 'POST', headers: { Origin: 'https://haikufit.in', 'CF-Connecting-IP': ip }, body: JSON.stringify(body) }), env)).json();
let [st, r] = await get('/health'); ok('/health without key hides everything', st === 401 && !r.checks && !r.recent_requests, JSON.stringify(r));
[st, r] = await get('/health?test&key=wrong'); ok('wrong key: no details, no test discount created', st === 401 && !r.checks);
[st, r] = await get('/health', { ...env, ADMIN_KEY: undefined }); ok('no ADMIN_KEY set: tells you to add one, shows nothing', !r.checks && /ADMIN_KEY/.test(r.next));
// a real Dojo claim, then check the log masks the code
let now = 1_800_000_000_000; Date.now = () => now;
const t = await post('/start', {}); now += 40_000;
const c = await post('/claim', { ...t, score: 400 });
[st, r] = await get('/health?key=owner-key-1234567890');
const logged = JSON.stringify(r.recent_requests);
ok('with key: details shown', st === 200 && r.checks && r.checks.SHOP.startsWith('✓'));
ok('issued code is masked in the log', c.code && !logged.includes(c.code) && /HF50-..•+/.test(logged), logged.slice(0, 160));
ok('start requests are not logged (saves storage writes)', !logged.includes('"start"'));
// tracking: 10 failed lookups allowed per hour, successes don't count
let last; for (let i = 0; i < 11; i++) last = await post('/track', { order: '1', contact: 'a@b.c' }, '7.7.7.7');
ok('11th failed tracking attempt is blocked', last.error === 'too_many');
// KV daily write limit reached
quotaHit = true;
const t2 = await post('/start', {}, '8.8.8.8'); now += 40_000;
const c2 = await post('/claim', { ...t2, score: 400 }, '8.8.8.8');
ok('storage limit reached: no unchecked code is issued', c2.error === 'busy', JSON.stringify(c2));
const tr = await post('/track', { order: '1', contact: 'a@b.c' }, '6.6.6.6');
ok('storage limit reached: tracking still answers', tr.error === 'not_found', JSON.stringify(tr));
