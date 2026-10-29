/* "Unlock your bonus battle" page: shows the signed-in customer's Dojo month from the rewards server. */
(() => {
  const root = document.querySelector('[data-dojo-unlock]');
  const cfgEl = document.querySelector('[data-dojo-unlock-config]');
  if (!root || !cfgEl) return;
  let cfg;
  try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
  const s = cfg.strings;
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const day = (iso) => new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'long' });
  const rootUrl = (window.Shopify && window.Shopify.routes && window.Shopify.routes.root) || '/';
  let api = '';
  try { api = new URL(cfg.api).origin; } catch (e) { return; }

  const status = () => fetch(`${api}/dojo/status`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cfg.me) })
    .then((r) => r.json());

  const tee = (p) => `<li class="dojo-unlock__tee">
      ${p.image ? `<img src="${esc(p.image + (p.image.includes('?') ? '&' : '?') + 'width=160')}" alt="" width="64" height="64" loading="lazy">` : ''}
      <span>${esc(p.title)}</span>
      ${p.handle ? `<a class="button button--secondary" href="${esc(`${rootUrl}products/${encodeURIComponent(p.handle)}#judgeme_product_reviews`)}">${s.reviewButton}</a>` : ''}
    </li>`;

  const render = (res, checked) => {
    let html = '';
    if (!res || res.error) {
      html = `<p class="dojo-unlock__lead">${s.error}</p>`;
    } else if (res.state === 'ready') {
      html = `<p class="dojo-unlock__lead">${esc(res.bonus ? s.readyBonus : s.ready)}</p><a class="button" href="${esc(cfg.gameUrl)}">${esc(s.play)}</a>`;
    } else if (res.state === 'code') {
      const reward = esc(res.label);
      html = `<p class="dojo-unlock__lead">${s.code.replace('__CODE__', `<b>${esc(res.code)}</b>`).replace('__REWARD__', reward).replace('__DATE__', day(res.expiresAt))}</p>
        <a class="button" href="${esc(`${rootUrl}discount/${encodeURIComponent(res.code)}?redirect=${encodeURIComponent(`${rootUrl}collections/all`)}`)}">${s.shop.replace('__REWARD__', reward)}</a>`;
    } else if (res.state === 'locked') {
      html = `<p class="dojo-unlock__lead">${esc(s.locked).replace('__DATE__', `<b>${day(res.nextAt)}</b>`)}</p>`;
      const orders = res.toReview || [];
      if (orders.length) {
        html += `<p class="dojo-unlock__review">${esc(s.review)}</p>`;
        orders.forEach((o) => {
          html += `${o.order ? `<p class="dojo-unlock__order">${s.order.replace('__ORDER__', esc(o.order))}</p>` : ''}<ul class="dojo-unlock__tees">${o.products.map(tee).join('')}</ul>`;
        });
        if (checked) html += `<p class="dojo-unlock__note" role="status">${esc(s.notYet)}</p>`;
        html += `<button type="button" class="dojo-unlock__check" data-dojo-check>${s.check}</button>`;
      } else {
        html += `<p class="dojo-unlock__review">${esc(s.noReview)}</p>`;
      }
    }
    // the "review your tee" intro only makes sense while there is something to review
    const intro = document.querySelector('[data-dojo-unlock-intro]');
    if (intro) intro.hidden = !!res && (res.state === 'code' || (res.state === 'ready' && !res.bonus));
    root.innerHTML = html;
    const again = root.querySelector('[data-dojo-check]');
    if (again) {
      again.addEventListener('click', () => {
        again.disabled = true;
        again.textContent = s.checking;
        status().then((r) => render(r, true)).catch(() => render(null));
      });
    }
  };

  status().then((r) => render(r, false)).catch(() => render(null));
})();
