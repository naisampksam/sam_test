// /health is locked with ADMIN_KEY, shows setup details only with the right key, and never leaks codes.
import worker from '../../dojo-rewards/worker.js';
const kv = () => { const m = new Map(); return { get: async (k) => m.get(k) ?? null, put: async (k, v) => m.set(k, v), delete: async (k) => m.delete(k), list: async () => ({ keys: [] }) }; };
let mode = 'ok';
globalThis.fetch = async (url, init) => {
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  const q = JSON.parse(init.body).query;
  if (q.includes('accessScopes')) return new Response(JSON.stringify({ data: { shop: { name: 'HAIKUFIT' }, currentAppInstallation: { accessScopes: [{ handle: 'write_discounts' }, { handle: 'read_discounts' }] } } }));
  if (q.includes('discountCodeDelete')) return new Response(JSON.stringify({ data: { discountCodeDelete: { deletedCodeDiscountId: 'gid://1', userErrors: [] } } }));
  if (mode === 'bad') return new Response(JSON.stringify({ errors: [{ message: 'invalid value for context.all' }] }));
  return new Response(JSON.stringify({ data: { discountCodeBasicCreate: { codeDiscountNode: { id: 'gid://1' }, userErrors: [] } } }));
};
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
const base = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), ALLOWED_ORIGINS: 'https://haikufit.in' };
const call = async (url, env) => { const r = await worker.fetch(new Request(url), env); return { status: r.status, headers: r.headers, body: await r.json() }; };

let r = await call('https://w.dev/health', { ...base, REWARDS: kv() });
ok('no ADMIN_KEY set: only says running, no details', r.body.running && !r.body.checks, JSON.stringify(r.body).slice(0, 120));
r = await call('https://w.dev/health?key=short-key-1', { ...base, ADMIN_KEY: 'short-key-1', REWARDS: kv() });
ok('ADMIN_KEY shorter than 16 characters: refused, asks for a longer one', r.status === 401 && !r.body.checks && /too short/.test(r.body.next), JSON.stringify(r.body).slice(0, 140));
r = await call('https://w.dev/health?key=wrong', { ...base, ADMIN_KEY: 'k'.repeat(32), REWARDS: kv() });
ok('wrong key: 401 and no details', r.status === 401 && !r.body.checks);
ok('health is never cached or indexed', /no-store/.test(r.headers.get('cache-control') || '') && /noindex/.test(r.headers.get('x-robots-tag') || ''));
r = await call(`https://w.dev/health?key=${'k'.repeat(32)}`, { ...base, ADMIN_KEY: 'k'.repeat(32), REWARDS: kv() });
ok('right key: setup checks shown', !!r.body.checks, Object.keys(r.body.checks || {}).slice(0, 4).join(','));
ok('no secret values in the report', !JSON.stringify(r.body).includes('z'.repeat(40)) && !JSON.stringify(r.body).includes('"b"'));
r = await call(`https://w.dev/health?test&key=${'k'.repeat(32)}`, { ...base, ADMIN_KEY: 'k'.repeat(32), REWARDS: kv() });
ok('?test creates and deletes a real test code', /✓/.test(JSON.stringify(r.body.checks.create_test_code || '')), JSON.stringify(r.body.checks.create_test_code));
mode = 'bad';
r = await call(`https://w.dev/health?test&key=${'k'.repeat(32)}`, { ...base, ADMIN_KEY: 'k'.repeat(32), REWARDS: kv() });
ok('?test reports a Shopify error clearly', r.body.ok === false && /✗/.test(JSON.stringify(r.body.checks.create_test_code || '')));
// a messy URL (extra text before the action) still reaches the right endpoint
const m = await worker.fetch(new Request('https://w.dev/health?test/start', { method: 'POST', headers: { Origin: 'https://haikufit.in' }, body: '{}' }), { ...base, REWARDS: kv() });
ok('messy URL still starts a run', (await m.json()).run !== undefined);
