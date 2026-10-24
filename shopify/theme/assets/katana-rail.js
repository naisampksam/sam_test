/**
 * Clothing rail: tees hanging from a steel rail.
 * - Follows the scroll 1:1: the tee nearest the middle opens up to face you, the others
 *   hang side-on (narrow, no 3D distortion). Continuous, so it can be grabbed and reversed.
 * - Desktop with a mouse: the whole rail fits; hover a tee to open it, click for the close-up.
 * - Tap the middle tee for a close-up (every colour, price, quick add); tap another to bring it to the middle.
 * - prefers-reduced-motion: tees stay flat.
 */
(() => {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // desktop with a mouse: hover to browse (the whole rail fits), click for the close-up
  const hoverMQ = window.matchMedia('(hover: hover) and (pointer: fine) and (min-width: 990px)');
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const pad = (n) => String(n).padStart(2, '0');

  document.querySelectorAll('[data-katana-rail]').forEach((rail) => {
    const track = rail.querySelector('[data-rail-track]');
    const items = [...rail.querySelectorAll('[data-rail-item]')];
    if (!track || !items.length) return;
    let data = [];
    try { data = JSON.parse(rail.querySelector('[data-rail-data]').textContent); } catch (e) { data = []; }

    const titleEl = rail.querySelector('[data-rail-title]');
    const priceEl = rail.querySelector('[data-rail-price]');
    const currentEl = rail.querySelector('[data-rail-current]');
    const dots = rail.querySelector('[data-rail-dots]');
    if (dots) dots.innerHTML = items.map(() => '<i></i>').join('');
    const dotEls = dots ? [...dots.children] : [];

    let active = -1;
    let lastX = track.scrollLeft;
    let raf = 0;

    const setActive = (i) => {
      if (i === active) return;
      active = i;
      items.forEach((it, n) => it.classList.toggle('is-active', n === i));
      dotEls.forEach((d, n) => d.classList.toggle('is-on', n === i));
      const p = data[i] || {};
      if (titleEl) titleEl.textContent = p.title || '';
      if (priceEl) priceEl.textContent = p.price || '';
      if (currentEl) currentEl.textContent = pad(i + 1);
    };

    const paint = () => {
      const mid = track.scrollLeft + track.clientWidth / 2;
      let best = 0;
      let bestD = Infinity;
      items.forEach((it, i) => {
        const w = it.offsetWidth || 1;
        const d = (it.offsetLeft + w / 2 - mid) / w; // 0 = in the middle, ±1 = one place away
        if (Math.abs(d) < bestD) { bestD = Math.abs(d); best = i; }
        const g = it.querySelector('[data-rail-garment]');
        if (!g) return;
        if (reduce || hoverMQ.matches) { g.style.transform = ''; return; }
        // side-on = narrower; faces you as it reaches the middle
        const f = Math.min(Math.abs(d), 1);
        const ease = f * f * (3 - 2 * f); // smoothstep: settles gently into the middle
        g.style.transform = `scaleX(${(1 - 0.46 * ease).toFixed(3)})`;
      });
      if (!hoverMQ.matches) setActive(best);
    };

    const loop = () => {
      const moved = track.scrollLeft !== lastX;
      lastX = track.scrollLeft;
      paint();
      raf = moved ? requestAnimationFrame(loop) : 0;
    };
    const kick = () => { if (!raf) raf = requestAnimationFrame(loop); };

    track.addEventListener('scroll', kick, { passive: true });
    window.addEventListener('resize', paint);

    const centre = (i, smooth = true) => {
      const it = items[clamp(i, 0, items.length - 1)];
      track.scrollTo({ left: it.offsetLeft + it.offsetWidth / 2 - track.clientWidth / 2, behavior: smooth && !reduce ? 'smooth' : 'auto' });
    };

    /* mouse drag on desktop (touch scrolls natively) */
    let drag = null;
    let dragged = false;
    track.addEventListener('pointerdown', (e) => {
      if (e.pointerType !== 'mouse' || e.button !== 0 || hoverMQ.matches) return;
      drag = { x: e.clientX, left: track.scrollLeft };
      dragged = false;
      track.style.scrollSnapType = 'none';
    });
    window.addEventListener('pointermove', (e) => {
      if (!drag) return;
      const dx = e.clientX - drag.x;
      if (Math.abs(dx) > 5) dragged = true;
      track.scrollLeft = drag.left - dx;
    });
    window.addEventListener('pointerup', () => {
      if (!drag) return;
      drag = null;
      track.style.scrollSnapType = '';
      centre(active);
    });

    /* keyboard */
    track.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        const next = clamp(active + (e.key === 'ArrowRight' ? 1 : -1), 0, items.length - 1);
        centre(next);
        const btn = items[next].querySelector('[data-rail-open]');
        if (btn) btn.focus({ preventScroll: true });
      }
    });

    /* close-up sheet */
    const sheet = rail.querySelector('[data-rail-sheet]');
    const sImg = sheet && sheet.querySelector('[data-rail-sheet-img]');
    const sTitle = sheet && sheet.querySelector('[data-rail-sheet-title]');
    const sPrice = sheet && sheet.querySelector('[data-rail-sheet-price]');
    const sCounter = sheet && sheet.querySelector('[data-rail-sheet-counter]');
    const sViews = sheet && sheet.querySelector('[data-rail-sheet-views]');
    const sLink = sheet && sheet.querySelector('[data-rail-sheet-link]');
    const sAdd = sheet && sheet.querySelector('[data-rail-quick-add]');

    const show = (i) => {
      const p = data[i];
      if (!sheet || !p) return;
      const views = (p.views || []).filter((v) => v.src);
      const pick = (n) => {
        const v = views[n];
        if (!v) return;
        sImg.src = v.src;
        sImg.alt = `${p.title}${v.label ? `, ${v.label}` : ''}`;
        [...sViews.children].forEach((b, k) => b.setAttribute('aria-selected', String(k === n)));
      };
      sTitle.textContent = p.title;
      sPrice.textContent = p.price;
      sCounter.textContent = `${pad(i + 1)} / ${pad(items.length)}`;
      sLink.href = p.url;
      sAdd.dataset.quickAdd = p.url; // the theme's quick add takes it from here
      sViews.innerHTML = '';
      views.forEach((v, n) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'tab');
        b.textContent = v.label || String(n + 1);
        b.addEventListener('click', () => pick(n));
        sViews.appendChild(b);
      });
      sViews.hidden = views.length < 2;
      // open on the colour that hangs on the rail
      const hung = items[i].querySelector('img');
      const start = Math.max(0, views.findIndex((v) => hung && hung.currentSrc && v.src.split('?')[0].split('/').pop() === hung.currentSrc.split('?')[0].split('/').pop()));
      pick(start);
      if (sheet.showModal) sheet.showModal();
    };

    items.forEach((it, i) => {
      const btn = it.querySelector('[data-rail-open]');
      btn.addEventListener('click', (e) => {
        if (dragged) { e.preventDefault(); dragged = false; return; }
        if (hoverMQ.matches) { setActive(i); show(i); return; }
        if (i !== active) centre(i);
        else show(i);
      });
      // desktop: hovering (or keyboard focus) opens the tee to face you
      it.addEventListener('mouseenter', () => { if (hoverMQ.matches) setActive(i); });
      btn.addEventListener('focus', () => { if (hoverMQ.matches) setActive(i); });
    });
    const onMode = () => { paint(); if (!hoverMQ.matches) centre(active < 0 ? 0 : active, false); };
    if (hoverMQ.addEventListener) hoverMQ.addEventListener('change', onMode);
    if (sheet) {
      sheet.querySelector('[data-rail-close]').addEventListener('click', () => sheet.close());
      sheet.addEventListener('click', (e) => { if (e.target === sheet) sheet.close(); });
      sAdd.addEventListener('click', () => sheet.close()); // then the theme opens quick add
    }

    // start on the second tee (like a rail you walk up to), or the first if there is only one
    requestAnimationFrame(() => {
      if (hoverMQ.matches) { setActive(Math.min(1, items.length - 1)); paint(); return; }
      centre(Math.min(1, items.length - 1), false); paint();
    });
  });
})();
