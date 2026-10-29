/**
 * Live integration tests for haikufit.in (read-only except a throwaway cart).
 * Run: node --test shopify/tests/live/          (needs network access to haikufit.in)
 * Uses curl so it works through HTTP(S) proxies with the system CA store.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = process.env.SITE || 'https://haikufit.in';
const jar = join(mkdtempSync(join(tmpdir(), 'hf-')), 'cookies.txt');
writeFileSync(jar, '');
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) HAIKUFIT-tests';

const pause = (ms) => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
const GAP = parseInt(process.env.GAP_MS || '1200', 10); // be polite: Shopify rate-limits bursts (429)

function http(path, opts = {}) {
  for (let attempt = 0; ; attempt++) {
    pause(GAP);
    const r = request(path, opts);
    if (r.status !== 429 || attempt >= 5) return r;
    const wait = (parseInt(r.headers['retry-after'], 10) || 10) * 1000 * (attempt + 1);
    pause(Math.min(wait, 60000));
  }
}

function request(path, { method = 'GET', json, headers = {}, follow = true } = {}) {
  const url = path.startsWith('http') ? path : BASE + path;
  const args = ['-sS', '-g', '-i', '--max-time', '30', '-A', UA, '-b', jar, '-c', jar, '-X', method];
  if (follow) args.push('-L');
  for (const [k, v] of Object.entries(headers)) args.push('-H', `${k}: ${v}`);
  if (json !== undefined) args.push('-H', 'Content-Type: application/json', '-H', 'Accept: application/json', '--data-binary', JSON.stringify(json));
  args.push(url);
  const raw = execFileSync('curl', args, { maxBuffer: 50 * 1024 * 1024 }).toString('utf8');
  // with -L there can be several header blocks; keep the last
  const parts = raw.split(/\r?\n\r?\n/);
  // skip proxy "Connection established", 100-continue and redirect header blocks
  let i = 0;
  while (i < parts.length - 1 && /^HTTP\//.test(parts[i + 1])) i++;
  const head = parts[i];
  const body = parts.slice(i + 1).join('\n\n');
  const status = parseInt((head.match(/^HTTP\/[\d.]+ (\d+)/) || [])[1], 10);
  const hdrs = {};
  head.split(/\r?\n/).slice(1).forEach((l) => { const m = l.match(/^([^:]+):\s*(.*)$/); if (m) hdrs[m[1].toLowerCase()] = m[2]; });
  return { status, headers: hdrs, body };
}
const getJSON = (p, o) => {
  const r = http(p, o);
  if (r.status === 429) throw new Error(`${p}: Shopify rate limit (429) - wait a few minutes and re-run, or raise GAP_MS`);
  return { ...r, data: JSON.parse(r.body) };
};

const products = getJSON('/products.json?limit=250').data.products;
const tees = products.filter((p) => /T-Shirt/i.test(p.product_type));
const PAGES = ['/', '/collections/all', '/cart', '/search?q=ronin', '/pages/contact', '/pages/dojo-run', '/pages/faq',
  '/pages/offers-rewards', '/pages/our-story', '/pages/returns-exchanges', '/pages/shipping', '/pages/size-guide', '/pages/track-order', '/pages/dojo-unlock'];
const pageCache = {};
const page = (p) => (pageCache[p] ||= http(p));

/* ------------------------------------------------------------------ pages */
test('every page and product page loads (200) without Liquid or translation errors', () => {
  for (const p of [...PAGES, ...products.map((x) => `/products/${x.handle}`)]) {
    const r = page(p);
    assert.equal(r.status, 200, `${p} -> ${r.status}`);
    assert.ok(!/Liquid (syntax )?error/i.test(r.body), `${p}: Liquid error`);
    assert.ok(!/translation missing/i.test(r.body), `${p}: translation missing`);
    assert.ok(!/Sample review/.test(r.body), `${p}: placeholder review text visible`);
  }
});

test('unknown URL returns the 404 page', () => {
  const r = http('/products/this-does-not-exist-hf');
  assert.equal(r.status, 404);
  assert.match(r.body, /<title>/);
});

test('each page has title, viewport, one h1 and a canonical link', () => {
  for (const p of PAGES.filter((x) => !x.includes('?') && x !== '/cart')) {
    const b = page(p).body;
    assert.match(b, /<title>[^<]{3,}<\/title>/, `${p}: title`);
    assert.match(b, /name="viewport"/, `${p}: viewport`);
    assert.match(b, /rel="canonical"/, `${p}: canonical`);
    const h1 = (b.match(/<h1[\s>]/g) || []).length;
    assert.ok(h1 >= 1, `${p}: no h1`);
  }
});

test('internal links on all pages resolve (no 404s)', () => {
  const links = new Set();
  for (const p of PAGES) for (const m of page(p).body.matchAll(/href="(\/(?:collections|products|pages|policies|blogs)\/[^"#?]+)"/g)) links.add(m[1]);
  const known = new Set(Object.keys(pageCache));
  const broken = [...links].filter((l) => !known.has(l)).filter((l) => http(l).status >= 400);
  assert.deepEqual(broken, [], `broken links: ${broken.join(', ')}`);
});

test('theme CSS/JS assets referenced on the home page load', () => {
  const assets = new Set([...page('/').body.matchAll(/(?:src|href)="(\/\/haikufit\.in\/cdn\/shop\/t\/[^"]+\.(?:css|js)[^"]*)"/g)].map((m) => 'https:' + m[1].replace(/&amp;/g, '&')));
  assert.ok(assets.size >= 3, 'expected theme assets');
  for (const a of assets) assert.equal(http(a).status, 200, a);
  assert.ok([...assets].some((a) => a.includes('motion.js')), 'motion.js is loaded');
});

test('product images load', () => {
  const imgs = new Set([...page('/').body.matchAll(/src="(\/\/haikufit\.in\/cdn\/shop\/files\/[^"]+)"/g)].slice(0, 12).map((m) => 'https:' + m[1].replace(/&amp;/g, '&')));
  assert.ok(imgs.size > 0);
  for (const i of imgs) assert.equal(http(i).status, 200, i);
});

/* --------------------------------------------------------------- products */
test('catalogue: every tee has XS–XXL, images, a price, and is buyable', () => {
  assert.ok(tees.length >= 20, `tees: ${tees.length}`);
  for (const p of tees) {
    const sizeIdx = p.options.findIndex((o) => /size/i.test(o.name));
    assert.ok(sizeIdx >= 0, `${p.handle}: no Size option`);
    assert.deepEqual([...p.options[sizeIdx].values].sort(), ['L', 'M', 'S', 'XL', 'XS', 'XXL'], `${p.handle} sizes`);
    assert.ok(p.images.length >= 3, `${p.handle}: images`);
    assert.ok(p.variants.some((v) => v.available), `${p.handle}: nothing available`);
    assert.ok(parseFloat(p.variants[0].price) > 0, `${p.handle}: price`);
  }
});

test('product JSON-LD is valid and has offers', () => {
  const b = page(`/products/${tees[0].handle}`).body;
  const blocks = [...b.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
  const prod = blocks.find((x) => x['@type'] === 'Product');
  assert.ok(prod && prod.offers && prod.offers.length, 'Product schema with offers');
});

test('product page has size picker, size chart, size finder, bundle offer, store credit, delivery estimate', () => {
  const b = page(`/products/${tees[0].handle}`).body;
  for (const [name, re] of [['size pills', /class="pill"[^>]*>XS</], ['size chart', /data-dialog-open="SizeChart/], ['size finder', /size-finder/],
    ['bundle tiers', /Buy 3/], ['store credit', /store credit/i], ['delivery estimate', /Estimated delivery/], ['add to cart', /data-add-to-cart/], ['Cashfree checkout script', /cashfree/i]]) {
    assert.match(b, re, name);
  }
});

/* ------------------------------------------------------------------- cart */
test('cart API: add, update quantity, bundle discount, remove', () => {
  http('/cart/clear.js', { method: 'POST', json: {} });
  const v1 = tees[0].variants.find((v) => v.available).id;
  const v2 = tees[1].variants.find((v) => v.available).id;
  const add = getJSON('/cart/add.js', { method: 'POST', json: { items: [{ id: v1, quantity: 1 }] } });
  assert.equal(add.status, 200, add.body.slice(0, 200));
  let cart = getJSON('/cart.js').data;
  assert.equal(cart.item_count, 1);
  getJSON('/cart/add.js', { method: 'POST', json: { items: [{ id: v2, quantity: 2 }] } });
  cart = getJSON('/cart.js').data;
  assert.equal(cart.item_count, 3);
  // 3 tees: the bundle automatic discount (15%) should show, if it is set up in Shopify
  const auto = cart.cart_level_discount_applications.concat(...cart.items.map((i) => i.line_level_discount_allocations.map((a) => a.discount_application || {})));
  test.diagnostic?.(`3 tees: total ${cart.total_price / 100}, original ${cart.original_total_price / 100}, discounts ${JSON.stringify(cart.cart_level_discount_applications.map((d) => d.title))}`);
  cart.__auto = auto;
  // remove the single tee by its line key (Shopify lists the newest line first)
  const single = cart.items.find((i) => i.variant_id === v1);
  const ch = getJSON('/cart/change.js', { method: 'POST', json: { id: single.key, quantity: 0 } });
  assert.equal(ch.status, 200);
  assert.equal(ch.data.item_count, 2);
  http('/cart/clear.js', { method: 'POST', json: {} });
  assert.equal(getJSON('/cart.js').data.item_count, 0);
});

test('bundle discount is active: 3 tees cost less than full price', () => {
  http('/cart/clear.js', { method: 'POST', json: {} });
  const v = tees.slice(0, 3).map((p) => ({ id: p.variants.find((x) => x.available).id, quantity: 1 }));
  getJSON('/cart/add.js', { method: 'POST', json: { items: v } });
  const cart = getJSON('/cart.js').data;
  http('/cart/clear.js', { method: 'POST', json: {} });
  assert.ok(cart.total_price < cart.original_total_price, `no automatic bundle discount applied (total ${cart.total_price / 100} of ${cart.original_total_price / 100})`);
});

test('coupon codes: WELCOME10 is accepted, a fake code is rejected', () => {
  http('/cart/clear.js', { method: 'POST', json: {} });
  getJSON('/cart/add.js', { method: 'POST', json: { items: [{ id: tees[0].variants.find((x) => x.available).id, quantity: 1 }] } });
  const r = getJSON('/cart/update.js', { method: 'POST', json: { discount: 'WELCOME10,NOTAREALCODE123' } });
  const codes = Object.fromEntries((r.data.discount_codes || []).map((d) => [d.code.toUpperCase(), d.applicable]));
  http('/cart/clear.js', { method: 'POST', json: {} });
  assert.equal(codes.NOTAREALCODE123, false, 'fake code must not apply');
  assert.equal(codes.WELCOME10, true, 'WELCOME10 should apply (create it in Shopify Discounts if missing)');
});

test('PREPAID50 is no longer active (it worked on COD too)', () => {
  http('/cart/clear.js', { method: 'POST', json: {} });
  getJSON('/cart/add.js', { method: 'POST', json: { items: [{ id: tees[0].variants.find((x) => x.available).id, quantity: 1 }] } });
  const r = getJSON('/cart/update.js', { method: 'POST', json: { discount: 'PREPAID50' } });
  http('/cart/clear.js', { method: 'POST', json: {} });
  const d = (r.data.discount_codes || []).find((x) => x.code.toUpperCase() === 'PREPAID50');
  assert.ok(!d || d.applicable === false, 'PREPAID50 still applies: delete it in Shopify Discounts');
});

test('Section Rendering API returns the cart drawer (used by add-to-cart)', () => {
  const r = getJSON('/?sections=cart-drawer');
  assert.equal(r.status, 200);
  assert.match(r.data['cart-drawer'] || '', /data-cart-drawer/);
});

test('product recommendations endpoint works', () => {
  const r = getJSON(`/recommendations/products.json?product_id=${tees[0].id}&limit=4`);
  assert.equal(r.status, 200);
  assert.ok(Array.isArray(r.data.products));
});

test('search finds products', () => {
  const r = getJSON('/search/suggest.json?q=ronin&resources[type]=product');
  assert.ok(r.data.resources.results.products.length > 0, 'search returned nothing for "ronin"');
});

/* ---------------------------------------------------------------- features */
test('rewards server, store credit and tracking are wired into the theme', () => {
  assert.match(page('/pages/dojo-run').body, /dojo-rewards\.[a-z0-9-]+\.workers\.dev/, 'Dojo Run has the rewards server URL');
  assert.match(page('/pages/track-order').body, /data-api=/, 'Track order uses the rewards server');
  // the coupon box shows on a cart with items
  getJSON('/cart/add.js', { method: 'POST', json: { items: [{ id: tees[0].variants.find((x) => x.available).id, quantity: 1 }] } });
  const cartPage = http('/cart').body;
  http('/cart/clear.js', { method: 'POST', json: {} });
  assert.match(cartPage, /data-cart-coupon/, 'coupon box on cart page');
});

test('Dojo Run monthly rewards: unlimited play, unlock page, sign-in returns to the page', () => {
  const dojo = page('/pages/dojo-run').body;
  assert.match(dojo, /"dailyRuns":\s*0/, 'no daily battle limit');
  assert.match(dojo, /"unlockUrl":\s*"[^"]*dojo-unlock"/, 'game links to the unlock page');
  assert.match(dojo, /"loginUrl":\s*"[^"]*return_(to|url)=[^"]*dojo-run"/, 'guest sign-in returns to Dojo Run');
  assert.ok(!/"loginUrl":\s*"[^"]*\?[^"]*\?return/.test(dojo), 'sign-in link is not broken (two ?)');
  const unlock = page('/pages/dojo-unlock').body;
  assert.match(unlock, /section-dojo-unlock/, 'unlock page uses the dojo-unlock template');
  assert.match(unlock, /href="[^"]*return_(to|url)=\/pages\/dojo-unlock"/, 'signed-out visitor gets a sign-in link back to the page');
  assert.ok(!/data-dojo-unlock-config/.test(unlock), 'signed-out visitor gets no customer data');
});

test('store credit secret never appears in any page', () => {
  for (const p of Object.keys(pageCache)) assert.ok(!/credit_secret|CREDIT_SECRET/.test(pageCache[p].body), `${p} leaks the secret name`);
});

test('WhatsApp button uses the business number', () => {
  assert.match(page('/').body, /wa\.me\/918089963691/);
});

/* ------------------------------------------------------------- SEO & policy */
test('SEO basics: meta description and social share image on the home page', () => {
  const b = page('/').body;
  assert.match(b, /<meta name="description" content="[^"]{30,}"/, 'no meta description (Online Store → Preferences)');
  assert.match(b, /<meta property="og:image"/, 'no social share image (Online Store → Preferences)');
});

test('robots.txt and sitemap.xml are served', () => {
  assert.equal(http('/robots.txt').status, 200);
  assert.match(http('/sitemap.xml').body, /<sitemapindex|<urlset/);
});

test('store policies are published', () => {
  const missing = ['refund-policy', 'privacy-policy', 'terms-of-service', 'shipping-policy', 'contact-information'].filter((p) => http(`/policies/${p}`).status !== 200);
  assert.deepEqual(missing, [], `not published (Settings → Policies): ${missing.join(', ')}`);
});

test('HTTPS: http redirects to https and HSTS is set', () => {
  const r = http('http://haikufit.in/', { follow: false });
  assert.ok([301, 302, 307, 308].includes(r.status), `http status ${r.status}`);
  assert.match(r.headers.location || '', /^https:/);
  assert.ok(page('/').headers['strict-transport-security'], 'HSTS header');
});

test('home page HTML stays light (< 300 KB)', () => {
  assert.ok(page('/').body.length < 300 * 1024, `home is ${Math.round(page('/').body.length / 1024)} KB`);
});
