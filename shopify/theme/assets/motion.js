/**
 * HAIKUFIT motion: fluid, interruptible sheets and drawers.
 *
 * - Springs (damping + response, Apple-style) instead of fixed-time transitions, so every
 *   animation starts from where the element is on screen and can be grabbed or reversed.
 * - Swipe to dismiss with momentum: the finger's release velocity is projected forward to
 *   decide close vs. snap back, and handed to the spring so there is no seam.
 * - Rubber-banding past the open position instead of a hard stop.
 * - prefers-reduced-motion: no springs or gestures, the theme's plain transitions are kept.
 *
 * Drives: cart drawer (swipe right to close), mobile menu (slides from the left, swipe left
 * to close) and the modal sheets: size finder, quick add, size chart, sign-up popup
 * (bottom sheets on phones, swipe down to close).
 */
(() => {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reduce.matches || !window.requestAnimationFrame) return;
  document.documentElement.classList.add('has-springs');
  // iOS only shows :active press states when a touch listener exists
  document.addEventListener('touchstart', () => {}, { passive: true });

  const phone = () => window.innerWidth < 750;

  /* ---------------------------------------------------------------- spring */
  // state = { x, v, raf }. Animates state.x to target; interruptible: calling again
  // re-targets from the current position and velocity.
  function springTo(state, target, { damping = 1, response = 0.35, velocity, restDelta = 0.5, onUpdate, onRest } = {}) {
    cancelAnimationFrame(state.raf);
    if (velocity !== undefined) state.v = velocity;
    const k = Math.pow((2 * Math.PI) / response, 2);
    const c = (4 * Math.PI * damping) / response;
    let last = performance.now();
    const frame = (now) => {
      const dt = Math.min((now - last) / 1000, 0.064);
      last = now;
      const steps = Math.max(1, Math.ceil(dt * 240));
      const h = dt / steps;
      for (let i = 0; i < steps; i++) {
        const a = -k * (state.x - target) - c * state.v;
        state.v += a * h;
        state.x += state.v * h;
      }
      if (Math.abs(state.x - target) < restDelta && Math.abs(state.v) < restDelta * 20) {
        state.x = target; state.v = 0; state.raf = 0;
        if (onUpdate) onUpdate(state.x);
        if (onRest) onRest();
        return;
      }
      if (onUpdate) onUpdate(state.x);
      state.raf = requestAnimationFrame(frame);
    };
    state.raf = requestAnimationFrame(frame);
  }
  const stop = (state) => { cancelAnimationFrame(state.raf); state.raf = 0; };

  // Where a flick would come to rest (Apple's deceleration projection)
  const project = (v, rate = 0.998) => ((v / 1000) * rate) / (1 - rate);
  // Soft boundary: the further past it, the less the element follows
  const rubberband = (over, dim, c = 0.55) => (over * dim * c) / (dim + c * Math.abs(over));

  /* --------------------------------------------------------------- swipe */
  // Touch swipe along one axis. dir = +1 (right/down) or -1 (left/up) is the dismiss
  // direction. Takes over only after 10px of clear movement that way, so taps and
  // normal scrolling are untouched.
  function swipe(el, { axis, dir, canStart, onStart, onMove, onEnd }) {
    let start = null, taken = false, ignored = false, hist = [];
    el.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1) { start = null; return; }
      const t = e.touches[0];
      start = { x: t.clientX, y: t.clientY, target: e.target };
      taken = false; ignored = canStart && !canStart(e.target); hist = [];
    }, { passive: true });
    el.addEventListener('touchmove', (e) => {
      if (!start || ignored) return;
      const t = e.touches[0];
      const d = axis === 'x' ? t.clientX - start.x : t.clientY - start.y;
      const cross = axis === 'x' ? t.clientY - start.y : t.clientX - start.x;
      if (!taken) {
        if (Math.abs(d) < 10 && Math.abs(cross) < 10) return;
        if (d * dir > 0 && Math.abs(d) > Math.abs(cross) * 1.2) {
          taken = true;
          start[axis] += 10 * dir; // no jump from the 10px threshold
          if (onStart) onStart();
        } else { ignored = true; return; }
      }
      e.preventDefault();
      const delta = (axis === 'x' ? t.clientX : t.clientY) - start[axis];
      hist.push({ p: delta, t: e.timeStamp });
      while (hist.length > 2 && e.timeStamp - hist[0].t > 100) hist.shift();
      onMove(delta);
    }, { passive: false });
    const end = () => {
      if (taken) {
        const a = hist[0], b = hist[hist.length - 1];
        const v = a && b && b.t > a.t ? ((b.p - a.p) / (b.t - a.t)) * 1000 : 0;
        onEnd(b ? b.p : 0, v);
      }
      start = null; taken = false;
    };
    el.addEventListener('touchend', end);
    el.addEventListener('touchcancel', end);
  }

  // Nearest scrolled-down container between the touch target and the sheet
  const scrolledInside = (target, root) => {
    for (let n = target; n && n !== root.parentNode; n = n.parentNode) {
      if (n.nodeType === 1 && n.scrollTop > 0 && n.scrollHeight > n.clientHeight) return true;
    }
    return false;
  };

  /* ---------------------------------------------------------- cart drawer */
  const cart = { x: 0, v: 0, raf: 0 };
  let cartFlick; // release velocity of a swipe, handed to the closing spring
  const cartEls = () => {
    const drawer = document.querySelector('[data-cart-drawer]');
    return drawer && { drawer, panel: drawer.querySelector('.cart-drawer__panel'), overlay: drawer.querySelector('.cart-drawer__overlay') };
  };
  const cartWidth = () => { const e = cartEls(); return (e && e.panel && e.panel.offsetWidth) || window.innerWidth; };
  const paintCart = (x) => {
    const e = cartEls();
    if (!e || !e.panel) return;
    const w = cartWidth();
    e.panel.style.transform = `translate3d(${x}px,0,0)`;
    if (e.overlay) e.overlay.style.opacity = String(Math.max(0, Math.min(1, 1 - x / w)));
  };
  const clearCart = () => {
    const e = cartEls();
    if (!e) return;
    if (e.panel) e.panel.style.transform = '';
    if (e.overlay) e.overlay.style.opacity = '';
  };

  function cartOpen(velocity) {
    const e = cartEls();
    if (!e) return;
    // fully closed before (not mid-animation): start off-screen
    if (!cart.raf && !e.drawer.classList.contains('is-closing')) { cart.x = cartWidth(); cart.v = 0; }
    e.drawer.classList.remove('is-closing');
    paintCart(cart.x);
    springTo(cart, 0, { damping: velocity ? 0.8 : 1, response: 0.35, velocity, onUpdate: paintCart, onRest: clearCart });
  }
  function cartClose(velocity) {
    const e = cartEls();
    if (!e) return;
    if (!cart.raf && !e.drawer.classList.contains('is-closing')) { cart.x = 0; cart.v = 0; }
    e.drawer.classList.add('is-closing');
    paintCart(cart.x);
    springTo(cart, cartWidth(), {
      damping: 1, response: 0.32, velocity, onUpdate: paintCart,
      onRest: () => { const el = cartEls(); if (el) el.drawer.classList.remove('is-closing'); clearCart(); }
    });
  }
  // theme.js toggles .is-open; we animate whatever it asks for
  const watchCart = () => {
    const drawer = document.querySelector('[data-cart-drawer]');
    if (!drawer || drawer.dataset.springs) return;
    drawer.dataset.springs = '1';
    let wasOpen = drawer.classList.contains('is-open');
    new MutationObserver(() => {
      const open = drawer.classList.contains('is-open');
      if (open === wasOpen) return;
      wasOpen = open;
      if (open) cartOpen(); else { cartClose(cartFlick); cartFlick = undefined; }
    }).observe(drawer, { attributes: true, attributeFilter: ['class'] });
    swipe(drawer, {
      axis: 'x', dir: 1,
      canStart: (t) => !!t.closest('.cart-drawer__panel') && drawer.classList.contains('is-open') && !t.closest('input, textarea, select'),
      onStart: () => stop(cart),
      onMove: (dx) => { cart.x = dx >= 0 ? dx : -rubberband(-dx, cartWidth()); paintCart(cart.x); },
      onEnd: (dx, v) => {
        const w = cartWidth();
        if (dx + project(v) > w * 0.5) {
          // close through the theme (unlocks page scroll, aria), keeping the finger's speed
          cartFlick = v;
          const closer = drawer.querySelector('[data-cart-drawer-close]');
          if (closer) closer.click(); else { cartFlick = undefined; cartOpen(v); }
        } else {
          cartOpen(v);
        }
      }
    });
  };

  /* ---------------------------------------------------------- mobile menu */
  const watchMenu = () => {
    const details = document.querySelector('.header__drawer[data-drawer]');
    const panel = details && details.querySelector('.drawer');
    const summary = details && details.querySelector('summary');
    if (!panel || !summary || details.dataset.springs) return;
    details.dataset.springs = '1';
    const m = { x: 0, v: 0, raf: 0 };
    let closing = false;
    const width = () => panel.offsetWidth || window.innerWidth;
    const paint = (x) => { panel.style.transform = `translate3d(${x}px,0,0)`; };
    const clear = () => { panel.style.transform = ''; };
    const open = (velocity) => {
      if (!details.open) { details.open = true; m.x = -width(); m.v = 0; }
      closing = false;
      paint(m.x);
      springTo(m, 0, { damping: velocity ? 0.8 : 1, response: 0.35, velocity, onUpdate: paint, onRest: clear });
    };
    const close = (velocity) => {
      if (!details.open) return;
      if (!m.raf && !closing) { m.x = 0; m.v = 0; }
      closing = true;
      springTo(m, -width(), {
        damping: 1, response: 0.3, velocity, onUpdate: paint,
        onRest: () => { closing = false; details.open = false; clear(); }
      });
    };
    // the summary (hamburger) toggles; animate instead of an instant show/hide
    summary.addEventListener('click', (e) => {
      e.preventDefault();
      if (details.open && !closing) close(); else open();
    });
    // closed some other way (Escape, another panel): drop any animation
    details.addEventListener('toggle', () => { if (!details.open) { stop(m); closing = false; clear(); } });
    swipe(panel, {
      axis: 'x', dir: -1,
      onStart: () => stop(m),
      onMove: (dx) => { m.x = dx <= 0 ? dx : rubberband(dx, width()); paint(m.x); },
      onEnd: (dx, v) => { if (-(dx + project(v)) > width() * 0.35) close(v); else open(v); }
    });
    // tapping a link closes the menu with the same motion
    panel.addEventListener('click', (e) => { if (e.target.closest('a[href]')) close(); });
  };

  /* --------------------------------------------------------------- sheets */
  // size finder, quick add, size chart, sign-up popup
  const SHEETS = '.sf, .quick-add, .popup, .dialog, .rail-sheet';
  const proto = window.HTMLDialogElement && HTMLDialogElement.prototype;
  if (proto && !proto.__hfSprings) {
    proto.__hfSprings = true;
    const realShow = proto.showModal;
    const realClose = proto.close;
    const st = new WeakMap();
    const S = (d) => { if (!st.has(d)) st.set(d, { x: 0, v: 0, raf: 0, closing: false, wired: false }); return st.get(d); };

    // phones: bottom sheet, x = px pushed down. larger screens: x = 0..1 hidden amount (fade + scale)
    const paint = (d, x) => {
      if (phone()) {
        d.style.transform = `translate3d(0,${x}px,0)`;
        d.style.opacity = '';
        d.style.setProperty('--sheet-shown', String(Math.max(0, Math.min(1, 1 - x / (d.offsetHeight || 1)))));
      } else {
        d.style.transform = `scale(${1 - 0.04 * x})`;
        d.style.opacity = String(1 - x);
        d.style.setProperty('--sheet-shown', String(1 - x));
      }
    };
    const clear = (d) => { d.style.transform = ''; d.style.opacity = ''; d.style.removeProperty('--sheet-shown'); };
    const hidden = (d) => (phone() ? d.offsetHeight || window.innerHeight : 1);
    const opts = (d, extra) => Object.assign({ restDelta: phone() ? 0.5 : 0.002, onUpdate: (x) => paint(d, x) }, extra);

    const sheetIn = (d, velocity) => {
      const s = S(d);
      s.closing = false;
      springTo(s, 0, opts(d, { damping: velocity ? 0.8 : 1, response: 0.35, velocity, onRest: () => clear(d) }));
    };
    const sheetOut = (d, velocity, done) => {
      const s = S(d);
      s.closing = true;
      springTo(s, hidden(d), opts(d, { damping: 1, response: 0.3, velocity, onRest: () => { s.closing = false; done(); clear(d); } }));
    };

    const wire = (d) => {
      const s = S(d);
      if (s.wired) return;
      s.wired = true;
      // Escape: animate out instead of vanishing
      d.addEventListener('cancel', (e) => { e.preventDefault(); d.close(); });
      swipe(d, {
        axis: 'y', dir: 1,
        canStart: (t) => phone() && !scrolledInside(t, d) && !t.closest('input, textarea, select, canvas'),
        onStart: () => stop(s),
        onMove: (dy) => { s.x = dy >= 0 ? dy : -rubberband(-dy, d.offsetHeight); paint(d, s.x); },
        onEnd: (dy, v) => { if (dy + project(v) > d.offsetHeight * 0.4) d.close(undefined, v); else sheetIn(d, v); }
      });
    };

    proto.showModal = function () {
      if (!this.matches(SHEETS)) return realShow.apply(this, arguments);
      const s = S(this);
      wire(this);
      if (!this.open) {
        realShow.apply(this, arguments);
        stop(s); s.x = hidden(this); s.v = 0;
        paint(this, s.x);
      }
      sheetIn(this); // also rescues a sheet that was mid-close
    };
    proto.close = function (returnValue, velocity) {
      if (!this.matches(SHEETS) || !this.open) return realClose.apply(this, arguments);
      const s = S(this);
      if (s.closing && velocity === undefined) return;
      if (!s.raf) { s.x = 0; s.v = 0; }
      sheetOut(this, velocity, () => realClose.call(this, returnValue));
    };
    // lets callers tell a sheet that is animating out from one that is really open
    Object.defineProperty(proto, 'isClosing', { get() { return !!(st.get(this) && st.get(this).closing); } });
  }

  /* ------------------------------------------------------ cart count pop */
  const watchBubble = () => {
    document.querySelectorAll('[data-cart-count]').forEach((count) => {
      if (count.dataset.springs) return;
      count.dataset.springs = '1';
      const bubble = count.closest('[data-cart-count-bubble]') || count;
      const s = { x: 1, v: 0, raf: 0 };
      let last = parseInt(count.textContent, 10) || 0;
      new MutationObserver(() => {
        const n = parseInt(count.textContent, 10) || 0;
        if (n > last) {
          // something landed in the bag: a small pop that settles (completion feedback)
          s.x = 1.35; s.v = 0;
          springTo(s, 1, { damping: 0.55, response: 0.4, restDelta: 0.002, onUpdate: (x) => { bubble.style.transform = `scale(${x})`; }, onRest: () => { bubble.style.transform = ''; } });
        }
        last = n;
      }).observe(count, { childList: true, characterData: true, subtree: true });
    });
  };

  const init = () => { watchCart(); watchMenu(); watchBubble(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  // the cart drawer is re-rendered by the Section Rendering API: re-attach if replaced
  new MutationObserver(() => { watchCart(); watchBubble(); }).observe(document.documentElement, { childList: true, subtree: true });
})();
