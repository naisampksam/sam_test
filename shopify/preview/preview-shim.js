/* Preview-only: simulates Shopify cart endpoints in the browser. */
(() => {
  const tiers = [[2, 10], [3, 15], [5, 20]];
  const threshold = 99900;
  const fmt = (c) => '₹' + (c / 100).toLocaleString('en-IN', { minimumFractionDigits: 2 });
  const byId = {};
  Object.values(window.PREVIEW_PRODUCTS).forEach((p) => p.variants.forEach((v) => { byId[v.id] = { p, v }; }));
  const cart = { items: [] };
  const count = () => cart.items.reduce((n, i) => n + i.quantity, 0);
  const total = () => cart.items.reduce((n, i) => n + i.quantity * i.v.price, 0);

  window.theme = {
    routes: { cart: '/cart', cartAdd: '/cart/add', cartChange: '/cart/change' },
    strings: { addToCart: 'Add to cart', soldOut: 'Sold out', unavailable: 'Unavailable', error: 'Something went wrong.',
      discount: '__PERCENT__% OFF', youSave: 'You save __AMOUNT__', lowStock: 'Hurry, only __COUNT__ left in stock', copied: 'Copied!',
      perTee: '__PRICE__ per tee', viewDetails: 'View full details', deliveryBy: '__START__ – __END__',
      deliveryTo: 'Estimated delivery to __PIN__: __DATES__', invalidPincode: 'Please enter a valid 6-digit pincode.' },
    cartType: 'drawer', moneyFormat: '₹{{amount}}',
    swatches: { black: '#111111', white: '#FFFFFF', beige: '#E8DCC6', 'bottle green': '#0F4D2C', chocolate: '#5A3A26', lavender: '#C3A6E8', navy: '#1B2340', 'royal blue': '#1E3FD0', red: '#D7261E' },
  };

  const icon = (d) => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">${d}</svg>`;
  function drawer() {
    const n = count();
    let body;
    if (!n) {
      body = `<div class="cart-drawer__empty"><p>Your cart is empty.</p><p class="cart-drawer__empty-offer">Buy 2, get 10% OFF</p><a href="index.html" class="button">Continue shopping</a></div>`;
    } else {
      const next = tiers.find(([q]) => n < q);
      const best = tiers.filter(([q]) => n >= q).pop();
      const max = tiers[tiers.length - 1][0];
      const msg = next ? `Add ${next[0] - n} more item${next[0] - n > 1 ? 's' : ''} to get ${next[1]}% OFF` : `🎉 You've unlocked ${best[1]}% OFF — applied at checkout`;
      const steps = tiers.map(([q, d]) => `<span class="offer-progress__step${n >= q ? ' is-done' : ''}"><b>${d}%</b><small>${q} items</small></span>`).join('');
      const t = total();
      const ship = t >= threshold ? "🎉 You've unlocked free shipping!" : `You're ${fmt(threshold - t)} away from free shipping!`;
      const save = cart.items.reduce((s, i) => s + (i.v.compare_at_price - i.v.price) * i.quantity, 0) + (best ? Math.round(t * best[1] / 100) : 0);
      const items = cart.items.map((i, idx) => `<li class="cart-drawer__item" data-line="${idx + 1}"><a href="${i.p.url}" class="cart-drawer__media"><img src="${i.v.featured_image.src}" alt=""></a>
        <div class="cart-drawer__details"><a href="${i.p.url}" class="cart-drawer__name">${i.p.title}</a><p class="muted">${i.v.title}</p>
        <div class="cart-drawer__row"><div class="quantity quantity--small"><button type="button" class="quantity__button" data-cart-change="${i.quantity - 1}">−</button><span class="quantity__input">${i.quantity}</span><button type="button" class="quantity__button" data-cart-change="${i.quantity + 1}">+</button></div>
        <div class="cart-drawer__price"><s class="muted">${fmt(i.v.compare_at_price * i.quantity)}</s><strong>${fmt(i.v.price * i.quantity)}</strong></div></div>
        <button type="button" class="cart-drawer__remove link-underline muted" data-cart-change="0">Remove</button></div></li>`).join('');
      const disc = best ? `<p class="cart-drawer__line"><span>${icon('<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Z"/>')} Bundle ${best[1]}% OFF</span><span>−${fmt(Math.round(t * best[1] / 100))}</span></p>` : '';
      const final = best ? t - Math.round(t * best[1] / 100) : t;
      body = `<div class="cart-drawer__body"><div class="offer-progress"><div class="offer-progress__row"><p>${msg}</p><div class="offer-progress__steps">${steps}</div><div class="progress"><span style="width:${Math.min(100, n * 100 / max)}%"></span></div></div>
        <div class="offer-progress__row"><p>${ship}</p><div class="progress"><span style="width:${Math.min(100, t * 100 / threshold)}%"></span></div></div></div>
        <ul class="cart-drawer__items">${items}</ul></div>
        <div class="cart-drawer__footer">${disc}<p class="cart-drawer__savings">You're saving ${fmt(save)} on this order 🎉</p>
        <p class="cart-drawer__line cart-drawer__subtotal"><span>Subtotal</span><strong>${fmt(final)}</strong></p>
        <p class="muted cart-drawer__taxes">Taxes included. Coupon codes &amp; shipping are applied at checkout.</p>
        <button type="button" class="button button--full button--checkout">${icon('<rect x="4" y="10" width="16" height="11" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>')} Check out · ${fmt(final)}</button>
        <ul class="trust-mini"><li>100% secure checkout</li><li>Cash on delivery</li><li>Easy 7-day exchange</li></ul></div>`;
    }
    return `<div id="shopify-section-cart-drawer"><div class="cart-drawer" data-cart-drawer aria-hidden="true"><div class="cart-drawer__overlay" data-cart-drawer-close></div>
      <div class="cart-drawer__panel" role="dialog" tabindex="-1"><div class="cart-drawer__header"><p class="cart-drawer__title">Your cart <span>(${n})</span></p>
      <button type="button" class="cart-drawer__close" data-cart-drawer-close aria-label="Close">${icon('<path d="M6 6l12 12M18 6 6 18"/>')}</button></div>${body}</div></div></div>`;
  }
  const json = (data) => new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } });

  window.fetch = async (url, opts = {}) => {
    const path = String(url);
    if (path.startsWith('/products/')) return json(window.PREVIEW_PRODUCTS[path.split('/')[2].replace('.js', '')]);
    if (path === '/cart.js') return json({ item_count: count() });
    if (path === '/cart/add.js') {
      const fd = opts.body;
      const id = parseInt(fd.get('id'), 10);
      const qty = parseInt(fd.get('quantity'), 10) || 1;
      const hit = byId[id];
      const line = cart.items.find((i) => i.v.id === id);
      if (line) line.quantity += qty; else cart.items.push({ ...hit, quantity: qty });
      return json({ id, sections: { 'cart-drawer': drawer() } });
    }
    if (path === '/cart/change.js') {
      const { line, quantity } = JSON.parse(opts.body);
      if (quantity <= 0) cart.items.splice(line - 1, 1); else cart.items[line - 1].quantity = quantity;
      return json({ item_count: count(), sections: { 'cart-drawer': drawer() } });
    }
    return new Response('', { status: 404 });
  };
  document.addEventListener('DOMContentLoaded', () => {
    const holder = document.getElementById('shopify-section-cart-drawer');
    holder.outerHTML = drawer();
  });
})();
