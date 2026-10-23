import worker from '../../dojo-rewards/worker.js';
const m = new Map(); const KV = { get: async (k) => m.get(k) ?? null, put: async (k, v) => m.set(k, v), delete: async (k) => m.delete(k) };
const env = { SHOP: 'x.myshopify.com', CLIENT_ID: 'a', CLIENT_SECRET: 'b', SIGNING_SECRET: 'z'.repeat(40), ALLOWED_ORIGINS: 'https://haikufit.in', REWARDS: KV };
let lastQ = '';
const ORDERS = {
  '1023': { name: '#1023', createdAt: '2026-10-10T10:00:00Z', cancelledAt: null, displayFulfillmentStatus: 'FULFILLED', email: 'arjun@mail.com', phone: '+919876543210', customer: { email: 'arjun@mail.com', phone: null }, shippingAddress: { phone: '098765 43210' },
    fulfillments: [{ status: 'SUCCESS', displayStatus: 'IN_TRANSIT', createdAt: '2026-10-11T10:00:00Z', deliveredAt: null, estimatedDeliveryAt: '2026-10-15T10:00:00Z', trackingInfo: [{ company: 'Delhivery', number: '1234567890123', url: 'https://www.delhivery.com/track/package/1234567890123' }] }] },
  '1024': { name: '#1024', createdAt: '2026-10-12T10:00:00Z', cancelledAt: null, displayFulfillmentStatus: 'UNFULFILLED', email: 'b@mail.com', phone: null, customer: null, shippingAddress: { phone: '9000000001' }, fulfillments: [] }
};
globalThis.fetch = async (url, init) => {
  if (String(url).endsWith('access_token')) return new Response(JSON.stringify({ access_token: 't', expires_in: 86399 }));
  const { variables } = JSON.parse(init.body); lastQ = variables.q;
  const n = (variables.q.match(/name:#?(\w+)/) || [])[1];
  return new Response(JSON.stringify({ data: { orders: { nodes: ORDERS[n] ? [ORDERS[n]] : [] } } }));
};
const call = async (body, ip = '1.1.1.1') => (await worker.fetch(new Request('https://w.dev/track', { method: 'POST', headers: { Origin: 'https://haikufit.in', 'CF-Connecting-IP': ip }, body: JSON.stringify(body) }), env)).json();
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
let r = await call({ order: '#1023', contact: 'ARJUN@mail.com ' });
ok('email match -> shipment with courier + link', r.order === '#1023' && r.shipments[0].courier === 'Delhivery' && r.shipments[0].url.includes('delhivery'), JSON.stringify(r).slice(0, 160));
ok('only safe fields returned (no address/email)', !JSON.stringify(r).includes('arjun@') && !JSON.stringify(r).includes('98765'));
r = await call({ order: '1023', contact: '98765 43210' }); ok('phone match (any format)', r.order === '#1023');
r = await call({ order: '1023', contact: '+91 9876543211' }); ok('wrong phone -> not_found', r.error === 'not_found');
r = await call({ order: '9999', contact: 'x@y.com' }); ok('unknown order -> same not_found', r.error === 'not_found');
r = await call({ order: '1024', contact: '9000000001' }); ok('not shipped yet -> no shipments', r.order === '#1024' && r.shipments.length === 0);
r = await call({ order: '1023"; DROP', contact: 'a@b.c' }); ok('order number sanitised in query', !/["; ]/.test(lastQ.replace('name:#', '').replace(' OR name:', '')), lastQ);
for (let i = 0; i < 12; i++) r = await call({ order: '1023', contact: 'a@b.c' }, '9.9.9.9');
ok('rate limit: 10 failed lookups/hour', r.error === 'too_many');
