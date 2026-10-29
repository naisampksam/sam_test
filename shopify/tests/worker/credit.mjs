import worker from '../../dojo-rewards/worker.js';
import crypto from 'node:crypto';
const store = new Map();
const KV = { get: async (k) => store.get(k) ?? null, put: async (k, v) => store.set(k, v), delete: async (k) => store.delete(k), list: async ({ prefix }) => ({ keys: [...store.keys()].filter((k) => k.startsWith(prefix)).map((name) => ({ name })) }) };
const env = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), CREDIT_SECRET: 'credit-secret-123', ALLOWED_ORIGINS: 'https://haikufit.in', REWARDS: KV };
let productsScope = true; let discountSeq = 0; let balance = 250; const discounts = new Map(); const orderCodes = new Set(); let failCreate = false; let lastInput = null;
globalThis.fetch = async (url, init) => {
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  const { query, variables } = JSON.parse(init.body);
  const ok = (data) => new Response(JSON.stringify({ data }));
  if (query.includes('storeCreditAccounts')) return ok({ customer: { storeCreditAccounts: { nodes: [{ id: 'gid://shopify/StoreCreditAccount/9', balance: { amount: balance.toFixed(2), currencyCode: 'INR' } }] } } });
  if (query.includes('storeCreditAccountDebit')) { const a = parseFloat(variables.input.debitAmount.amount); if (a > balance) return ok({ storeCreditAccountDebit: { userErrors: [{ message: 'insufficient' }] } }); balance -= a; return ok({ storeCreditAccountDebit: { storeCreditAccountTransaction: { amount: { amount: a } }, userErrors: [] } }); }
  if (query.includes('storeCreditAccountCredit')) { balance += parseFloat(variables.input.creditAmount.amount); return ok({ storeCreditAccountCredit: { storeCreditAccountTransaction: { amount: {} }, userErrors: [] } }); }
  if (query.includes('products(first: 250')) return productsScope ? ok({ products: { nodes: [{ id: 'gid://shopify/Product/1' }, { id: 'gid://shopify/Product/2' }] } }) : new Response(JSON.stringify({ errors: [{ message: 'Access denied for products field' }] }));
  if (query.includes('codeDiscountNodeByCode')) { const e = [...discounts.entries()].find(([, d]) => d.code === variables.code); return ok({ codeDiscountNodeByCode: e ? { id: e[0], codeDiscount: { endsAt: e[1].endsAt, asyncUsageCount: e[1].used, customerGets: { value: { amount: { amount: e[1].amount } } } } } : null }); }
  if (query.includes('discountCodeBasicCreate')) { if (failCreate) return ok({ discountCodeBasicCreate: { codeDiscountNode: null, userErrors: [{ message: 'context invalid' }] } }); if ([...discounts.values()].some((d) => d.code === variables.input.code)) return ok({ discountCodeBasicCreate: { codeDiscountNode: null, userErrors: [{ message: 'Code must be unique.' }] } }); lastInput = variables.input; const id = 'gid://d/' + (++discountSeq); discounts.set(id, { used: 0, code: variables.input.code, amount: variables.input.customerGets.value.discountAmount.amount, endsAt: variables.input.endsAt }); return ok({ discountCodeBasicCreate: { codeDiscountNode: { id }, userErrors: [] } }); }
  if (query.includes('discountCodeDelete')) { discounts.delete(variables.id); return ok({ discountCodeDelete: { deletedCodeDiscountId: variables.id } }); }
  if (query.includes('orders(first: 1')) { const code = variables.q.replace('discount_code:', ''); return ok({ orders: { nodes: orderCodes.has(code) ? [{ id: 'gid://o/1' }] : [] } }); }
  if (query.includes('codeDiscountNode')) { const d = discounts.get(variables.id); return ok({ codeDiscountNode: d ? { codeDiscount: { asyncUsageCount: d.used } } : null }); }
  return ok({});
};
const sign = (id, ts, secret = env.CREDIT_SECRET) => crypto.createHmac('sha256', secret).update(`${id}:${ts}`).digest('hex');
const ts = Math.floor(Date.now() / 1000);
const me = { customer: '7001', ts, sig: sign('7001', ts) };
const call = async (path, body) => { const r = await worker.fetch(new Request('https://w.dev' + path, { method: 'POST', headers: { Origin: 'https://haikufit.in' }, body: JSON.stringify(body) }), env); return [r.status, await r.json()]; };
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);

let [st, r] = await call('/credit/redeem', { ...me, amount: 180 });
ok('redeem ₹180 asked, capped at ₹100 per order, balance 150', st === 200 && /^CR-[0-9A-F]{10}$/.test(r.code) && r.amount === '100.00' && balance === 150, JSON.stringify(r) + ' bal ' + balance);
ok('code locked to this customer, single use, fixed ₹180, combines', lastInput.context.customers.add[0] === 'gid://shopify/Customer/7001' && lastInput.usageLimit === 1 && lastInput.customerGets.value.discountAmount.amount === '100.00' && lastInput.combinesWith.productDiscounts);
ok('credit code is a product discount on every product, so it stacks with a Dojo reward', JSON.stringify(lastInput.customerGets.items) === JSON.stringify({ products: { productsToAdd: ['gid://shopify/Product/1', 'gid://shopify/Product/2'] } }) && lastInput.combinesWith.orderDiscounts && lastInput.combinesWith.productDiscounts, JSON.stringify(lastInput.customerGets.items));
const first = r.code;
[st, r] = await call('/credit/redeem', { ...me, amount: 70 });
ok('asking again returns the same code (no double spend)', r.code === first && balance === 150, JSON.stringify(r));
[st, r] = await call('/credit/cancel', { ...me, code: first.toLowerCase() });
ok('removing the code gives the credit back', r.refunded === '100.00' && balance === 250 && discounts.size === 0, JSON.stringify(r) + ' bal ' + balance);
[st, r] = await call('/credit/redeem', { customer: '7002', ts, sig: me.sig });
ok('someone else\'s customer id with my signature -> rejected', r.error === 'bad_customer', JSON.stringify(r));
[st, r] = await call('/credit/redeem', { customer: '7001', ts, sig: sign('7001', ts, 'guess') });
ok('forged signature -> rejected', r.error === 'bad_customer');
const old = ts - 3 * 86400;
[st, r] = await call('/credit/redeem', { customer: '7001', ts: old, sig: sign('7001', old) });
ok('stale cart link (3 days) -> rejected', r.error === 'bad_customer');
// used code: then new redeem works and old is forgotten
[st, r] = await call('/credit/redeem', { ...me });
const second = r.code; ok('redeem again: still max ₹100', r.amount === '100.00' && balance === 150);
[...discounts.values()][0].used = 1;
[st, r] = await call('/credit/redeem', { ...me });
ok('after the code was used at checkout: next order can use another ₹100', r.amount === '100.00' && balance === 50, JSON.stringify(r));
const third = r.code; [...discounts.values()];
[st, r] = await call('/credit/cancel', { ...me, code: second });
ok('cannot cancel a used code', r.error === 'no_pending');
await call('/credit/cancel', { ...me, code: third });
// expiry refund via sweep
balance = 100;
[st, r] = await call('/credit/redeem', { ...me });
const rec = JSON.parse(store.get('credit:7001')); rec.expires = Date.now() - 1000; store.set('credit:7001', JSON.stringify(rec));
await worker.scheduled({}, env, { waitUntil: (p) => p });
await new Promise((res) => setTimeout(res, 50));
ok('unused expired code refunded by the scheduled sweep', balance === 100 && !store.has('credit:7001'), 'bal ' + balance);
// Cashfree-style order: code on an order but Shopify's usage count still 0 -> must NOT refund
balance = 100;
[st, r] = await call('/credit/redeem', { ...me });
orderCodes.add(r.code);
{ const rc = JSON.parse(store.get('credit:7001')); rc.expires = Date.now() - 1000; store.set('credit:7001', JSON.stringify(rc)); }
await worker.scheduled({}, env, { waitUntil: (p) => p });
await new Promise((res) => setTimeout(res, 50));
ok('code used on a one-click (Cashfree) order is not refunded', balance === 0 && !store.has('credit:7001'), 'bal ' + balance);
balance = 100;
failCreate = true;
[st, r] = await call('/credit/redeem', { ...me });
ok('Shopify refuses code -> debit undone, error shown', st === 500 && balance === 100, JSON.stringify(r).slice(0, 120));

// without the read_products scope the credit code still works (as an order discount)
{
  productsScope = false; store.delete('product_ids'); store.delete('credit:7001'); balance = 100; failCreate = false;
  const [st2, r2] = await call('/credit/redeem', { ...me });
  ok('no read_products scope: credit still works as an order discount', st2 === 200 && JSON.stringify(lastInput.customerGets.items) === JSON.stringify({ all: true }), JSON.stringify(r2));
}
