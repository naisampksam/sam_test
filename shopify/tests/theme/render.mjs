import { createRequire as __cr } from 'module';
const require_crypto = () => __cr(import.meta.url)('crypto');
// Renders the real HAIKUFIT Shopify theme with liquidjs + mocked Shopify objects/filters/tags.
import { Liquid, Tag } from 'liquidjs';
import fs from 'node:fs';
import path from 'node:path';

const THEME = new URL('../../theme', import.meta.url).pathname;
const OUT = path.join(path.dirname(new URL(import.meta.url).pathname), 'out');
fs.mkdirSync(OUT, { recursive: true });
const ASSET = 'file://' + THEME + '/assets/';
const read = (p) => fs.readFileSync(path.join(THEME, p), 'utf8');
const locale = JSON.parse(read('locales/en.default.json'));
const products = JSON.parse(fs.readFileSync(new URL('./fixtures/products.json', import.meta.url), 'utf8'));
if (process.env.GIFT) products.push({ id: 9901, title: 'Warrior Gift Box', handle: 'warrior-gift-box', url: '/products/warrior-gift-box', available: true, price: 14900, compare_at_price: null, options: ['Title'], options_with_values: [], has_only_default_variant: true, variants: [{ id: 99011, title: 'Default Title', price: 14900, available: true, options: ['Default Title'] }], selected_or_first_available_variant: { id: 99011, price: 14900, available: true }, images: [], media: [], tags: [], collections: [] });
if (process.env.RATING) for (const p of products) p.metafields = { reviews: { rating: { value: { rating: "4.8", scale_min: "1.0", scale_max: "5.0" } }, rating_count: { value: 24 } } };
const errors = [];

// ---------------------------------------------------------------- preprocess
function prep(src) {
  return src
    .replace(/{%-?\s*schema\s*-?%}[\s\S]*?{%-?\s*endschema\s*-?%}/g, '')
    .replace(/{%-?\s*#[^%]*-?%}/g, '')
    .replace(/{%(-?)\s*form\s+([\s\S]*?)(-?)%}/g, '{%$1 form_open $2 $3%}')
    .replace(/{%(-?)\s*endform\s*(-?)%}/g, '</form>')
    .replace(/{%(-?)\s*paginate\s+([\s\S]*?)(-?)%}/g, '{%$1 paginate_open $2 $3%}')
    .replace(/{%(-?)\s*endpaginate\s*(-?)%}/g, '');
}
function schemaOf(file) {
  const t = read(file);
  const m = t.match(/{%-?\s*schema\s*-?%}([\s\S]*?){%-?\s*endschema\s*-?%}/);
  return m ? JSON.parse(m[1]) : {};
}

// ---------------------------------------------------------------- engine
const engine = new Liquid({
  root: [path.join(THEME, 'snippets')],
  extname: '.liquid',
  strictFilters: true,
  fs: {
    readFileSync: (f) => prep(fs.readFileSync(f, 'utf8')),
    readFile: async (f) => prep(fs.readFileSync(f, 'utf8')),
    existsSync: fs.existsSync, exists: async (f) => fs.existsSync(f),
    resolve: (root, file, ext) => path.resolve(root, file.endsWith(ext) ? file : file + ext),
    contains: () => true, dirname: path.dirname, sep: '/',
  },
});

const kw = (args) => {
  const o = {}; const pos = [];
  for (const a of args) Array.isArray(a) && a.length === 2 && typeof a[0] === 'string' ? (o[a[0]] = a[1]) : pos.push(a);
  return { o, pos };
};
const money = (c) => 'Rs. ' + (Number(c || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const lookupT = (key) => key.split('.').reduce((n, k) => (n == null ? n : n[k]), locale);

engine.registerFilter('t', function (key, ...args) {
  const { o } = kw(args);
  let v = lookupT(key);
  if (v == null) { errors.push('missing translation: ' + key); return key; }
  if (typeof v === 'object') v = o.count === 1 ? (v.one ?? v.other) : o.count === 0 && v.zero ? v.zero : v.other;
  return String(v).replace(/{{\s*(\w+)\s*}}/g, (_, k) => (o[k] ?? ''));
});
engine.registerFilter('money', money);
import('node:crypto').then(()=>{});
engine.registerFilter('hmac_sha256', (v, key) => require_crypto().createHmac('sha256', String(key)).update(String(v)).digest('hex'));
engine.registerFilter('money_with_currency', (c) => money(c) + ' INR');
engine.registerFilter('money_without_currency', (c) => (Number(c || 0) / 100).toFixed(2));
engine.registerFilter('money_without_trailing_zeros', (c) => money(c).replace(/\.00$/, ''));
engine.registerFilter('asset_url', (f) => ASSET + f);
engine.registerFilter('shopify_asset_url', (f) => ASSET + f);
engine.registerFilter('image_url', (img) => (img == null ? '' : typeof img === 'string' ? img : img.src || img.preview_image || ''));
engine.registerFilter('image_tag', (src, ...args) => {
  const { o } = kw(args);
  const attrs = Object.entries(o).filter(([k]) => !['widths', 'sizes'].includes(k)).map(([k, v]) => `${k}="${String(v ?? '').replace(/"/g, '&quot;')}"`).join(' ');
  return `<img src="${src}" ${attrs} width="800" height="1200">`;
});
engine.registerFilter('placeholder_svg_tag', (n, cls) => `<svg class="${cls || ''}" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>`);
engine.registerFilter('payment_type_svg_tag', (t, ...args) => `<svg class="payment-icon" viewBox="0 0 38 24"><rect width="38" height="24" rx="3" fill="#ddd"/><text x="5" y="16" font-size="9">${t}</text></svg>`);
engine.registerFilter('stylesheet_tag', (u) => `<link rel="stylesheet" href="${u}">`);
engine.registerFilter('font_face', () => '');
engine.registerFilter('font_modify', (f) => f);
engine.registerFilter('color_mix', (a, b, w) => {
  const h = (x) => x.replace('#', '').match(/../g).map((v) => parseInt(v, 16));
  const A = h(a), B = h(b), p = Number(w) / 100;
  return '#' + A.map((v, i) => Math.round(v * p + B[i] * (1 - p)).toString(16).padStart(2, '0')).join('');
});
engine.registerFilter('handleize', (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''));
engine.registerFilter('handle', (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''));
engine.registerFilter('media_tag', () => '<video></video>');
engine.registerFilter('url_encode', (s) => encodeURIComponent(s));
engine.registerFilter('link_to', (t, url) => `<a href="${url}">${t}</a>`);
engine.registerFilter('time_tag', (d) => `<time>${d}</time>`);
engine.registerFilter('format_address', () => '<p>Address</p>');
engine.registerFilter('default_errors', () => '');
engine.registerFilter('payment_button', () => '<div class="shopify-payment-button"><button class="shopify-payment-button__button shopify-payment-button__button--unbranded" type="button">Buy it now</button></div>');
engine.registerFilter('format_code', (s) => s);

class FormOpen extends Tag {
  constructor(token, remain, liquid) { super(token, remain, liquid); this.args = token.args; }
  *render(ctx, emitter) {
    const id = (this.args.match(/id:\s*'([^']+)'/) || [])[1] || (this.args.match(/id:\s*(\w+)/) ? ctx.getSync([this.args.match(/id:\s*(\w+)/)[1]]) : '');
    const cls = (this.args.match(/class:\s*'([^']+)'/) || [])[1] || '';
    const type = (this.args.match(/^\s*'([^']+)'/) || [])[1];
    const extra = /data-product-form/.test(this.args) ? ' data-product-form' : '';
    ctx.bottom().form = { posted_successfully: false, errors: null };
    emitter.write(`<form method="post" action="/${type}" id="${id || ''}" class="${cls}" enctype="multipart/form-data" novalidate${extra}>`);
  }
}
engine.registerTag('form_open', FormOpen);
engine.registerTag('paginate_open', class extends Tag {
  *render(ctx) { ctx.bottom().paginate = { pages: 1, parts: [], current_page: 1 }; }
});
async function renderSection(type, id, data, ctx) {
  const file = `sections/${type}.liquid`;
  const sch = schemaOf(file);
  const settings = resolveSettings(sch.settings || [], data.settings || {});
  const bdefs = Object.fromEntries((sch.blocks || []).map((b) => [b.type, b]));
  const blocks = (data.block_order || Object.keys(data.blocks || {})).map((bid) => {
    const b = data.blocks[bid];
    return { id: bid, type: b.type, settings: resolveSettings((bdefs[b.type] || {}).settings || [], b.settings || {}), shopify_attributes: '' };
  });
  try {
    const html = await engine.parseAndRender(prep(read(file)), { ...ctx, section: { id, settings, blocks } }, { globals: GLOBALS(ctx) });
    return `<div id="shopify-section-${id}" class="shopify-section ${sch.class || ''}">${html}</div>`;
  } catch (e) {
    errors.push(`${file}: ${e.message.split('\n')[0]}`);
    return `<div class="RENDER-ERROR">${file}</div>`;
  }
}
engine.registerTag('section', class extends Tag {
  constructor(t, r, l) { super(t, r, l); this.name = t.args.trim().replace(/'/g, ''); }
  *render(ctx, emitter) { emitter.write(yield renderSection(this.name, this.name, {}, ctx.getAll())); }
});
engine.registerTag('sections', class extends Tag {
  constructor(t, r, l) { super(t, r, l); this.name = t.args.trim().replace(/'/g, ''); }
  *render(ctx, emitter) {
    const g = JSON.parse(read(`sections/${this.name}.json`));
    let out = '';
    for (const id of g.order) if (!g.sections[id].disabled) out += yield renderSection(g.sections[id].type, id, g.sections[id], ctx.getAll());
    emitter.write(out);
  }
});

// ---------------------------------------------------------------- data
const schemaSettings = JSON.parse(read('config/settings_schema.json'));
const data = JSON.parse(read('config/settings_data.json'));
const linklists = {
  'main-menu': { title: 'Main menu', links: ['New Drops', 'Bushidō', 'Warriors', 'Essentials', 'Combos', 'Custom', 'Our Story'].map((t, i) => ({ title: t, url: '#', active: false, links: i === 1 ? [{ title: 'Gi', url: '#' }, { title: 'Yū', url: '#' }] : [] })) },
  footer: { title: 'Footer', links: ['Contact', 'Shipping Policy', 'Returns & Exchange', 'Track Order'].map((t) => ({ title: t, url: '#', links: [] })) },
};
const byHandle = Object.fromEntries(products.map((p) => [p.handle, p]));
const mkCollection = (handle, title, filter) => {
  const ps = products.filter(filter);
  return { id: handle.length, handle, title, url: '/collections/' + handle, products: ps, products_count: ps.length, all_products_count: ps.length, featured_image: ps[0] && ps[0].featured_image, description: '', filters: [], sort_options: [{ value: 'manual', name: 'Featured' }, { value: 'price-ascending', name: 'Price, low to high' }], sort_by: 'manual', default_sort_by: 'manual' };
};
const collections = {
  all: mkCollection('all', 'Products', () => true),
  bestsellers: mkCollection('bestsellers', 'Bestsellers', (p) => p.tags.includes('bestseller')),
  'new-arrivals': mkCollection('new-arrivals', 'New Arrivals', (p) => p.tags.includes('new')),
  bushido: mkCollection('bushido', 'Bushidō', (p) => p.tags.includes('bushido')),
  combos: mkCollection('combos', 'Combos', (p) => p.tags.includes('combo')),
};
function resolveSettings(defs, values) {
  const out = {};
  for (const d of defs) {
    if (!d.id) continue;
    let v = d.id in values ? values[d.id] : d.default;
    if (d.type === 'link_list') v = linklists[v] || null;
    if (d.type === 'collection') v = v ? collections[v] || null : null;
    if (d.type === 'product') v = v ? byHandle[v] || null : null;
    if (d.type === 'product_list') v = (v || []).map((h) => byHandle[h]).filter(Boolean);
    if (d.type === 'image_picker') v = v || null;
    if (d.type === 'font_picker') v = { family: d.id.includes('header') ? 'Oswald' : 'Assistant', fallback_families: 'sans-serif', weight: 400 };
    out[d.id] = v ?? null;
  }
  for (const [k, v] of Object.entries(values)) if (!(k in out)) out[k] = v;
  return out;
}
const settings = resolveSettings(schemaSettings.flatMap((g) => g.settings || []), data.presets ? data.presets.Default : data.current);
if (process.env.CREDIT_TEST) { settings.dojo_reward_api = 'https://rewards.test'; settings.credit_secret = 'credit-secret-123'; }

function cartWith(items) {
  const lines = items.map(([h, vi, q]) => {
    const p = byHandle[h]; const v = p.variants[vi];
    return { product: p, product_id: p.id, variant: v, title: p.title, url: v.url, image: v.featured_image.src, quantity: q, price: v.price,
      final_price: v.price, original_price: v.price, final_line_price: v.price * q, original_line_price: v.price * q, properties: {}, line_level_discount_allocations: [], url_to_remove: '#' };
  });
  const total = lines.reduce((s, l) => s + l.final_line_price, 0);
  const cart = { items: lines, item_count: lines.reduce((s, l) => s + l.quantity, 0), total_price: total, total_discount: 0, discount_applications: [], cart_level_discount_applications: [], note: '', currency: { iso_code: 'INR' } };
  if (process.env.SAVINGS) {
    // a cart with the bundle offer, a Dojo reward and store credit applied
    cart.discount_applications = [
      { type: 'automatic', title: 'Bundle: Buy 3, 15% off', total_allocated_amount: 40455 },
      { type: 'discount_code', title: 'HF100-ZQ7K2M', total_allocated_amount: 10000 },
      { type: 'discount_code', title: 'CR-8KD2PQ7M', total_allocated_amount: 10000 }
    ];
    cart.cart_level_discount_applications = cart.discount_applications;
    cart.total_discount = 60455;
    cart.total_price = total - 60455;
  }
  return cart;
}

const GLOBALS = (c) => { const g = c && c.getAll ? c.getAll() : c; const { section, block, ...rest } = g; return rest; };
function baseCtx(pageType, extra) {
  return {
    settings, linklists, collections, all_products: byHandle, pages: { contact: { id: 1 } },
    shop: { name: 'HAIKUFIT', money_format: 'Rs. {{amount}}', enabled_payment_types: ['visa', 'master', 'rupay', 'upi'], customer_accounts_enabled: true, url: 'https://haikufit.in', description: '' },
    routes: { root_url: '/', cart_url: '/cart', cart_add_url: '/cart/add', cart_change_url: '/cart/change', all_products_collection_url: '/collections/all', collections_url: '/collections', search_url: '/search', account_url: '/account', account_login_url: '/account/login', product_recommendations_url: '/recommendations/products' },
    request: { locale: { iso_code: 'en' }, page_type: pageType, origin: 'https://haikufit.in' },
    template: { name: pageType }, canonical_url: 'https://haikufit.in', page_title: 'HAIKUFIT', content_for_header: '',
    cart: cartWith([['ronin-oversized-t-shirt', 0, 1], ['acid-wash-essential-oversized-t-shirt', 0, 2]]),
    recommendations: { performed: true, products_count: 4, products: products.slice(5, 9) },
    customer: null, search: { performed: false, terms: '' },
    ...extra,
  };
}

async function renderPage(name, templateFile, pageType, extra) {
  const ctx = baseCtx(pageType, extra);
  const tpl = JSON.parse(read(`templates/${templateFile}.json`));
  let body = '';
  for (const id of tpl.order) if (!tpl.sections[id].disabled) body += await renderSection(tpl.sections[id].type, id, tpl.sections[id], ctx);
  let html;
  try {
    html = await engine.parseAndRender(prep(read('layout/theme.liquid')), { ...ctx, content_for_layout: body }, { globals: GLOBALS(ctx) });
  } catch (e) { errors.push(`layout: ${e.message.split('\n')[0]}`); html = body; }
  // offline: drop remote font CSS (fonts are installed locally for the test)
  html = html.replace(/<link href="https:\/\/fonts\.googleapis[^>]+>/g, '');
  fs.writeFileSync(path.join(OUT, name + '.html'), html);
}

const ronin = byHandle['ronin-oversized-t-shirt'];
await renderPage('home', 'index', 'index', {});
await renderPage('product', 'product', 'product', { product: ronin, page_title: ronin.title });
await renderPage('collection', 'collection', 'collection', { collection: collections.all });
await renderPage('cart', 'cart', 'cart', {});
await renderPage('cart-member', 'cart', 'cart', { customer: { id: 7001, first_name: 'Arjun', store_credit_account: { balance: 25000 } } });
await renderPage('story', 'page.story', 'page', { page: { title: 'Our Story', content: '' } });
for (const [n, t] of [['faq', 'FAQ'], ['size-guide', 'Size guide'], ['shipping', 'Shipping'], ['returns', 'Returns & exchanges'], ['track-order', 'Track order'], ['contact', 'Contact'], ['dojo', 'Dojo Run'], ['rewards', 'Offers & rewards rules']]) {
  await renderPage('page-' + n, 'page.' + n, 'page', { page: { title: t, content: '' } });
}
await renderPage('notfound', '404', '404', {});
await renderPage('search', 'search', 'search', { search: { performed: true, terms: 'ronin', results: products.slice(0, 4), results_count: 4 } });
console.log(errors.length ? 'ERRORS:\n' + [...new Set(errors)].join('\n') : 'rendered with no Liquid errors');
