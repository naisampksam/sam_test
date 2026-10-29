// Store credit: requests sent at the same moment must not give one customer several credit codes
import worker from '../../dojo-rewards/worker.js';
import crypto from 'node:crypto';
const store = new Map();
const delay = () => new Promise((r) => setTimeout(r, 5 + Math.random() * 20));
const KV = { get: async (k) => { await delay(); return store.get(k) ?? null; }, put: async (k, v) => { await delay(); store.set(k, v); }, delete: async (k) => store.delete(k), list: async () => ({ keys: [], list_complete: true }) };
const env = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), CREDIT_SECRET: 'cs', ALLOWED_ORIGINS: 'https://haikufit.in', REWARDS: KV };
let balance = 500; const codes = new Map();
globalThis.fetch = async (url, init) => {
  await delay();
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  const { query, variables } = JSON.parse(init.body);
  const ok = (d) => new Response(JSON.stringify({ data: d }));
  if (query.includes('storeCreditAccounts')) return ok({ customer: { storeCreditAccounts: { nodes: [{ id: 'gid://a/1', balance: { amount: balance.toFixed(2), currencyCode: 'INR' } }] } } });
  if (query.includes('storeCreditAccountDebit')) { const a = parseFloat(variables.input.debitAmount.amount); if (a > balance) return ok({ storeCreditAccountDebit: { userErrors: [{ message: 'insufficient' }] } }); balance -= a; return ok({ storeCreditAccountDebit: { storeCreditAccountTransaction: { amount: { amount: a } }, userErrors: [] } }); }
  if (query.includes('discountCodeBasicCreate')) { const c = variables.input.code; if (codes.has(c)) return ok({ discountCodeBasicCreate: { codeDiscountNode: null, userErrors: [{ message: 'Code must be unique.' }] } }); codes.set(c, variables.input.customerGets.value.discountAmount.amount); return ok({ discountCodeBasicCreate: { codeDiscountNode: { id: 'gid://d/' + c }, userErrors: [] } }); }
  if (query.includes('codeDiscountNodeByCode')) { const c = variables.code; return ok({ codeDiscountNodeByCode: codes.has(c) ? { id: 'gid://d/' + c, codeDiscount: { endsAt: new Date(Date.now() + 86400000).toISOString(), asyncUsageCount: 0, customerGets: { value: { amount: { amount: codes.get(c) } } } } } : null }); }
  if (query.includes('products(first')) return ok({ products: { nodes: [{ id: 'gid://p/1' }] } });
  return ok({});
};
const ts = Math.floor(Date.now() / 1000);
const me = { customer: '42', ts, sig: crypto.createHmac('sha256', 'cs').update(`42:${ts}`).digest('hex') };
const post = async (path, body) => (await worker.fetch(new Request('https://w.dev' + path, { method: 'POST', headers: { Origin: 'https://haikufit.in' }, body: JSON.stringify(body) }), env)).json();
const res = await Promise.all(Array.from({ length: 6 }, () => post('/credit/redeem', { ...me, amount: 100 })));
const got = [...new Set(res.map((r) => r.code).filter(Boolean))];
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
ok('ATTACK: 6 credit requests at once -> one code, ₹100 taken (cap holds)', got.length === 1 && balance === 400, `codes ${got.length}, taken ₹${500 - balance}`);
