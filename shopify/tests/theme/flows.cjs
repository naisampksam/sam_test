const { chromium } = require('playwright');
const fs = require('fs');
const R = __dirname;
const products = JSON.parse(fs.readFileSync(R + '/fixtures/products.json', 'utf8'));
const shopifyJs = Object.fromEntries(products.map((p) => [p.handle, {
  handle: p.handle, title: p.title, url: p.url, featured_image: p.featured_image,
  options: p.options_with_values.map((o) => ({ name: o.name, values: o.values })),
  variants: p.variants.map((v) => ({ id: v.id, title: v.title, options: v.options, available: v.available, price: v.price, compare_at_price: v.compare_at_price, featured_image: v.featured_image })),
}]));
const mock = (pj) => {
  const drawerHTML = () => document.getElementById('shopify-section-cart-drawer').outerHTML;
  let count = 3;
  const json = (d) => new Response(JSON.stringify(d), { status: 200, headers: { 'Content-Type': 'application/json' } });
  window.fetch = async (url, opts = {}) => {
    const u = String(url);
    if (u.startsWith('/products/')) return json(pj[u.split('/')[2].replace('.js', '')]);
    if (u === '/cart.js') return json({ item_count: count });
    if (u === '/cart/add.js') { count += parseInt(opts.body.get('quantity') || 1, 10); return json({ id: 1, sections: { 'cart-drawer': drawerHTML() } }); }
    if (u === '/cart/change.js') { const b = JSON.parse(opts.body); count = b.quantity; await new Promise((r) => setTimeout(r, 400)); return json({ item_count: count, sections: { 'cart-drawer': drawerHTML() } }); }
    if (u.startsWith('/recommendations')) return new Response('<div data-recommendations></div>');
    return new Response('', { status: 404 });
  };
};
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
  const results = [];
  const ok = (name, cond, extra = '') => results.push(`${cond ? 'PASS' : 'FAIL'}  ${name} ${extra}`);
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 850 } });
    await ctx.addInitScript(mock, shopifyJs);
    const errs = [];
    // ---- product page
    let p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message));
    await p.goto('file://' + R + '/out/product.html'); await p.waitForTimeout(400);
    const visibleBefore = await p.$$eval('[data-media-id]', (m) => m.filter((x) => !x.hidden).map((x) => x.dataset.mediaAlt));
    ok(`[${w}] gallery shows only Black photos`, visibleBefore.every((a) => a === 'black'), JSON.stringify(visibleBefore));
    await p.click('label[for$="-0-1"]'); await p.waitForTimeout(200);
    const visibleAfter = await p.$$eval('[data-media-id]', (m) => m.filter((x) => !x.hidden).map((x) => x.dataset.mediaAlt));
    ok(`[${w}] switching to White shows White photos`, visibleAfter.length && visibleAfter.every((a) => a === 'white'), JSON.stringify(visibleAfter));
    const tiers = await p.$$('.offer-tier'); await tiers[2].click(); await p.waitForTimeout(100);
    ok(`[${w}] "Buy 3" tier sets quantity 3`, (await p.inputValue('input[name="quantity"]')) === '3');
    await p.click('[data-add-to-cart]'); await p.waitForTimeout(600);
    ok(`[${w}] add to cart opens drawer`, await p.$eval('[data-cart-drawer]', (d) => d.classList.contains('is-open')));
    const drawerBox = await p.$eval('.cart-drawer__panel', (e) => { const r = e.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; });
    ok(`[${w}] drawer fits the screen`, drawerBox[0] >= 0 && drawerBox[1] <= w, JSON.stringify(drawerBox));
    const plus = await p.$('.cart-drawer [data-cart-change]:nth-of-type(2)');
    await plus.click(); await p.waitForTimeout(150);
    const loadingOpacity = await p.$eval('.cart-drawer__panel', (e) => getComputedStyle(e).opacity);
    ok(`[${w}] drawer stays opaque while updating`, loadingOpacity === '1', 'opacity=' + loadingOpacity);
    await p.waitForTimeout(600);
    await p.click('.cart-drawer__close'); await p.waitForTimeout(400);
    ok(`[${w}] drawer closes`, !(await p.$eval('[data-cart-drawer]', (d) => d.classList.contains('is-open'))));
    await p.evaluate(() => window.scrollTo(0, 2400)); await p.waitForTimeout(500);
    const sticky = await p.isVisible('[data-sticky-atc]');
    ok(`[${w}] sticky add-to-cart bar`, w < 990 ? sticky : true, `visible=${sticky}`);
    await p.close();
    // ---- collection quick add
    p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message));
    await p.goto('file://' + R + '/out/collection.html'); await p.waitForTimeout(300);
    await p.hover('.card'); await p.click('.card__quick-add'); await p.waitForSelector('[data-qa-add]');
    const qa = await p.$eval('#QuickAdd', (d) => { const r = d.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; });
    ok(`[${w}] quick add opens and fits screen`, qa[0] >= 0 && qa[1] <= w, JSON.stringify(qa));
    await p.click('[data-qa-add]'); await p.waitForTimeout(600);
    ok(`[${w}] quick add → drawer opens`, await p.$eval('[data-cart-drawer]', (d) => d.classList.contains('is-open')));
    await p.close();
    // ---- home: mobile menu, popup, countdown
    p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message));
    await p.goto('file://' + R + '/out/home.html'); await p.waitForTimeout(500);
    ok(`[${w}] no fake sale countdown (honest pricing)`, await p.evaluate(() => ![...document.querySelectorAll('[data-countdown]')].some((el) => !el.hidden && el.offsetParent !== null)));
    if (w < 990) { await p.click('.header__icon--menu'); await p.waitForTimeout(200); ok(`[${w}] mobile menu opens`, await p.isVisible('.drawer')); await p.click('.header__icon--menu'); }
    await p.waitForTimeout(8600);
    const popup = await p.$eval('#NewsletterPopup', (d) => { const r = d.getBoundingClientRect(); return { open: d.open, l: Math.round(r.left), r: Math.round(r.right) }; }).catch(() => null);
    ok(`[${w}] sign-up popup appears and fits`, popup && popup.open && popup.l >= 0 && popup.r <= w, JSON.stringify(popup));
    await p.screenshot({ path: `${R}/out/popup-${w}.png` });
    await p.close();
    ok(`[${w}] no JavaScript errors`, errs.length === 0, errs.join(' | '));
    await ctx.close();
  }
  console.log(results.join('\n'));
  await b.close();
})();
