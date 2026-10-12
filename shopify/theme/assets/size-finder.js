/* HAIKUFIT · Find your size
 * A four-tap quiz: gender → height & weight → body type → how oversized.
 * The answer is matched to the product's own size chart (chest + length, inches).
 * No camera, nothing leaves the browser; the answers are remembered for the next product.
 */
(() => {
  'use strict';

  const KEY = 'hf_size_profile';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, t) => a + (b - a) * t;

  /* ---------- body types and fit levels ---------- */
  const BODIES = {
    male: [
      { id: 'lean', name: 'Lean', note: 'Slim frame, narrow shoulders', s: 16, w: 10.5, h: 11.5, adj: -3 },
      { id: 'athletic', name: 'Athletic', note: 'Broad shoulders, slim waist', s: 21, w: 12, h: 12.5, adj: 3 },
      { id: 'average', name: 'Average', note: 'Straight, balanced build', s: 18.5, w: 15, h: 14.5, adj: 0 },
      { id: 'broad', name: 'Broad', note: 'Big chest or a bit of belly', s: 21, w: 19, h: 17.5, adj: 5 },
    ],
    female: [
      { id: 'slim', name: 'Slim', note: 'Narrow frame throughout', s: 13.5, w: 9.5, h: 13.5, adj: -3 },
      { id: 'athletic', name: 'Athletic', note: 'Toned, straight shape', s: 16, w: 11, h: 14.5, adj: 1 },
      { id: 'curvy', name: 'Curvy', note: 'Defined waist, fuller hips', s: 14.5, w: 10.5, h: 19, adj: 3 },
      { id: 'full', name: 'Full', note: 'Fuller all over', s: 17, w: 16, h: 20, adj: 5 },
    ],
  };
  const FITS = [
    { id: 'classic', name: 'Classic', note: 'Close to the body', ease: 2, px: 2 },
    { id: 'relaxed', name: 'Relaxed', note: 'A little room', ease: 3.5, px: 5 },
    { id: 'oversized', name: 'Oversized', note: 'As designed · drop shoulder', ease: 5.5, px: 9, star: true },
    { id: 'baggy', name: 'Extra baggy', note: 'Big, boxy street look', ease: 8.5, px: 14 },
  ];
  const KANJI = ['一', '二', '三', '四'];

  function parseChart(text) {
    const rows = String(text || '').split(/\r?\n/).map((r) => r.split(',').map((c) => c.trim())).filter((r) => r.length > 1 && r[0]);
    if (rows.length < 2) return null;
    const head = rows[0].map((h) => h.toLowerCase());
    const ci = head.findIndex((h) => h.includes('chest'));
    const li = head.findIndex((h) => h.includes('length'));
    if (ci < 0) return null;
    const out = { sizes: [], chest: {}, length: {} };
    rows.slice(1).forEach((r) => {
      const c = parseFloat(r[ci]);
      if (!r[0] || Number.isNaN(c)) return;
      out.sizes.push(r[0]);
      out.chest[r[0]] = c;
      if (li >= 0 && !Number.isNaN(parseFloat(r[li]))) out.length[r[0]] = parseFloat(r[li]);
    });
    if (!out.sizes.length) return null;
    if (head.join(' ').includes('cm') || out.chest[out.sizes[0]] > 70) {
      out.sizes.forEach((z) => { out.chest[z] /= 2.54; if (out.length[z]) out.length[z] /= 2.54; });
    }
    return out;
  }

  const ftIn = (cm) => { const t = Math.round(cm / 2.54); return `${Math.floor(t / 12)}′${t % 12}″`; };

  /* ---------- sketches ---------- */
  // ink-line figure; optional tee drawn over it with extra width "ease" (viewBox units)
  function figure(gender, body, opts = {}) {
    // exaggerate widths so body types read clearly at card size
    const K = 1.32;
    const s = body.s * K; const w = body.w * K; const h = body.h * K;
    // male torso bulges at the belly for the broad type
    const belly = body.id === 'broad' ? w * 0.25 : 0;
    const scaleY = opts.scaleY || 1;
    const ink = '#0E0E0E';
    const skin = '#EAD9C2';
    const female = gender === 'female';
    const y = (v) => v;
    const torso = female
      ? `M${50 - s} 46 C${50 - s - 1} 60 ${50 - w - 2} 70 ${50 - w} 86 C${50 - w} 96 ${50 - h} 104 ${50 - h} 116 L${50 + h} 116 C${50 + h} 104 ${50 + w} 96 ${50 + w} 86 C${50 + w + 2} 70 ${50 + s + 1} 60 ${50 + s} 46 Z`
      : `M${50 - s} 46 C${50 - s} 62 ${50 - w - 1 - belly} 80 ${50 - w - belly} 96 L${50 - h} 116 L${50 + h} 116 L${50 + w + belly} 96 C${50 + w + 1 + belly} 80 ${50 + s} 62 ${50 + s} 46 Z`;
    const arm = (side) => {
      const x0 = 50 + side * s;
      const x1 = 50 + side * (Math.max(s, w) + 6);
      return `M${x0} ${y(48)} C${x0 + side * 5} 64 ${x1} 84 ${x1 - side * 1} 112`;
    };
    const leg = (side) => `M${50 + side * (h - 1)} 114 L${50 + side * (h - 4)} 186 M${50 + side * 2} 120 L${50 + side * 4} 186`;
    // shape details per body type
    let details = '';
    const id = body.id || '';
    if (id === 'athletic' && !female) {
      details = `<path d="M${50 - s * 0.62} 60 Q${50 - s * 0.3} 67 50 62 Q${50 + s * 0.3} 67 ${50 + s * 0.62} 60" fill="none" stroke="${ink}" stroke-width="1.4" stroke-linecap="round"/>
        <path d="M47 74 h6 M47 81 h6 M47 88 h6" stroke="${ink}" stroke-width="1.2" stroke-linecap="round" opacity=".55"/>`;
    } else if (id === 'broad') {
      details = `<path d="M${50 - w * 0.8} 86 Q50 ${102 + w * 0.25} ${50 + w * 0.8} 86" fill="none" stroke="${ink}" stroke-width="1.4" stroke-linecap="round" opacity=".6"/>`;
    } else if (female && (id === 'curvy' || id === 'full')) {
      details = `<path d="M${50 - s * 0.55} 60 Q${50 - s * 0.3} 66 50 61 Q${50 + s * 0.3} 66 ${50 + s * 0.55} 60" fill="none" stroke="${ink}" stroke-width="1.3" stroke-linecap="round" opacity=".6"/>`;
    } else if (female) {
      details = `<path d="M${50 - s * 0.45} 60 Q50 64 ${50 + s * 0.45} 60" fill="none" stroke="${ink}" stroke-width="1.2" stroke-linecap="round" opacity=".5"/>`;
    }
    let tee = '';
    if (opts.ease !== undefined) {
      const e = opts.ease * K;
      const bodyHalf = Math.max(s, w + belly, h * 0.9) + e;
      const sh = s + e * 0.55 + 1;
      const sleeve = 10 + e * 0.5;
      const hemY = 118 + e * 0.6;
      tee = `<path d="M${50 - sh} 44 L${50 - sh - sleeve} ${62 + e * 0.6} L${50 - sh - sleeve + 6} ${70 + e * 0.8} L${50 - bodyHalf + 1} 64 L${50 - bodyHalf} ${hemY} L${50 + bodyHalf} ${hemY} L${50 + bodyHalf - 1} 64 L${50 + sh + sleeve - 6} ${70 + e * 0.8} L${50 + sh + sleeve} ${62 + e * 0.6} L${50 + sh} 44 C${50 + 6} 48 ${50 - 6} 48 ${50 - sh} 44 Z" fill="${ink}" stroke="${ink}" stroke-width="1.5" stroke-linejoin="round"/>
        <circle cx="${50 + bodyHalf * 0.45}" cy="62" r="2.6" fill="#C8102E"/>`;
    }
    const hair = female
      ? `<path d="M38 26 C36 8 64 8 62 26 C64 36 62 46 60 48 L40 48 C38 46 36 36 38 26 Z" fill="${ink}"/>`
      : `<path d="M39 22 C39 10 61 10 61 22 C58 16 42 16 39 22 Z" fill="${ink}"/>`;
    return `<svg class="sf-fig" viewBox="0 0 100 200" aria-hidden="true">
      <ellipse cx="50" cy="191" rx="${22 + w * 0.6}" ry="3.5" fill="rgba(0,0,0,.12)"/>
      <g transform="translate(50 190) scale(1 ${scaleY}) translate(-50 -190)">
        ${female ? hair : ''}
        <g fill="none" stroke="${ink}" stroke-width="2.4" stroke-linecap="round">
          <path d="${leg(-1)}"/><path d="${leg(1)}"/>
        </g>
        <path d="${torso}" fill="${skin}" stroke="${ink}" stroke-width="2.2" stroke-linejoin="round"/>
        ${tee ? '' : details}
        <g fill="none" stroke="${ink}" stroke-width="2.6" stroke-linecap="round"><path d="${arm(-1)}"/><path d="${arm(1)}"/></g>
        ${tee}
        <rect x="46.5" y="34" width="7" height="10" fill="${skin}" stroke="${ink}" stroke-width="1.6"/>
        <circle cx="50" cy="24" r="11" fill="${skin}" stroke="${ink}" stroke-width="2.2"/>
        ${female ? '' : hair}
        <path d="M44 25.5 h3 M53 25.5 h3" stroke="${ink}" stroke-width="1.6" stroke-linecap="round"/>
        <path d="M39 18 Q50 13 61 18" stroke="#C8102E" stroke-width="2.6" fill="none" stroke-linecap="round"/>
      </g>
    </svg>`;
  }

  // body shape from height + weight, for the live preview on step 2
  function bodyFromBmi(gender, heightCm, weightKg) {
    const bmi = weightKg / Math.pow(heightCm / 100, 2);
    const t = clamp((bmi - 17) / 15, 0, 1);
    return gender === 'female'
      ? { s: lerp(13, 17.5, t), w: lerp(9, 17, t), h: lerp(13, 21, t), id: '' }
      : { s: lerp(16, 21.5, t), w: lerp(10, 19, t), h: lerp(11, 17.5, t), id: t > 0.75 ? 'broad' : '' };
  }

  /* ---------- the quiz ---------- */
  class SizeFinder {
    constructor(root) {
      this.root = root;
      this.cfg = JSON.parse(root.querySelector('[data-size-finder-config]').textContent);
      this.chart = parseChart(this.cfg.chart);
      this.sizes = (this.cfg.sizes && this.cfg.sizes.length ? this.cfg.sizes : (this.chart ? this.chart.sizes : [])).filter((z) => this.chart && this.chart.chest[z]);
      try { this.profile = JSON.parse(localStorage.getItem(KEY)) || null; } catch (e) { this.profile = null; }
      this.a = Object.assign({ gender: null, height: 172, weight: 68, body: null, fit: 'oversized' }, this.profile || {});
      root.querySelector('[data-size-finder-open]').addEventListener('click', () => this.open());
      this.updateCta();
    }

    updateCta() {
      const sub = this.root.querySelector('[data-size-finder-sub]');
      if (!this.profile || !sub) return;
      const r = this.compute(this.profile);
      if (!r) return;
      this.root.classList.add('has-size');
      sub.innerHTML = `Your size: <b>${esc(r.size)}</b> (${esc(FITS.find((f) => f.id === this.profile.fit).name.toLowerCase())}) · tap to change`;
    }

    /* ----- maths ----- */
    chestInches(a) {
      const body = (BODIES[a.gender] || BODIES.male).find((b) => b.id === a.body) || { adj: 0 };
      const cm = a.gender === 'female'
        ? 0.45 * a.weight + 0.18 * a.height + 30
        : 0.45 * a.weight + 0.25 * a.height + 20;
      return (cm + body.adj) / 2.54;
    }

    compute(a) {
      if (!this.chart || !this.sizes.length || !a.gender) return null;
      const fit = FITS.find((f) => f.id === a.fit) || FITS[2];
      const chest = this.chestInches(a);
      const need = chest + fit.ease;
      let i = this.sizes.findIndex((z) => this.chart.chest[z] >= need - 0.15);
      const tooBig = i < 0;
      if (tooBig) i = this.sizes.length - 1;
      let size = this.sizes[i];
      // tall shoppers: make sure the tee is long enough
      let tall = null;
      const needLen = (a.height * (a.gender === 'female' ? 0.152 : 0.158)) / 2.54;
      if (this.chart.length[size] && this.chart.length[size] + 0.75 < needLen && this.sizes[i + 1]) tall = this.sizes[i + 1];
      const ease = this.chart.chest[size] - chest;
      return { size, chest, ease, fit, tooBig, tall, closer: this.sizes[i - 1] || null, looser: this.sizes[i + 1] || null };
    }

    /* ----- dialog ----- */
    build() {
      const d = document.createElement('dialog');
      d.className = 'sf';
      d.setAttribute('aria-label', 'Find your size');
      d.innerHTML = `
        <div class="sf__top">
          <button type="button" class="sf__back" data-back aria-label="Back">←</button>
          <ol class="sf__steps" aria-hidden="true">${KANJI.map((k, i) => `<li data-dot="${i}">${k}</li>`).join('')}<li data-dot="4">寸</li></ol>
          <button type="button" class="sf__close" data-close aria-label="Close">×</button>
        </div>
        <div class="sf__body" data-sf-body></div>`;
      document.body.appendChild(d);
      this.d = d;
      d.querySelector('[data-close]').addEventListener('click', () => d.close());
      d.querySelector('[data-back]').addEventListener('click', () => this.go(Math.max(0, this.step - 1), -1));
      d.addEventListener('click', (e) => { if (e.target === d) d.close(); });
    }

    open() {
      if (!this.d) this.build();
      this.d.showModal();
      this.go(this.profile ? 4 : 0, 1);
    }

    go(step, dir = 1) {
      this.step = step;
      const body = this.d.querySelector('[data-sf-body]');
      this.d.querySelector('[data-back]').style.visibility = step > 0 && step < 4 ? 'visible' : 'hidden';
      this.d.querySelectorAll('[data-dot]').forEach((li) => {
        const n = parseInt(li.dataset.dot, 10);
        li.classList.toggle('is-done', n < step);
        li.classList.toggle('is-now', n === step);
      });
      const html = [this.stepGender, this.stepMeasures, this.stepBody, this.stepFit, this.stepResult][step].call(this);
      body.innerHTML = `<div class="sf__step sf__step--in${dir < 0 ? ' sf__step--back' : ''}">${html}</div>`;
      this.bind(step);
    }

    stepGender() {
      return `<p class="sf__kicker">Step 1 of 4</p>
        <h2 class="sf__title">Who's the warrior?</h2>
        <p class="sf__sub">Our tees are unisex. This just helps us read your shape.</p>
        <div class="sf__cards sf__cards--2">
          ${['male', 'female'].map((g) => `<button type="button" class="sf__card${this.a.gender === g ? ' is-picked' : ''}" data-gender="${g}">
            ${figure(g, BODIES[g][g === 'male' ? 2 : 1])}
            <b>${g === 'male' ? 'Male' : 'Female'}</b>
          </button>`).join('')}
        </div>`;
    }

    stepMeasures() {
      return `<p class="sf__kicker">Step 2 of 4</p>
        <h2 class="sf__title">Height & weight</h2>
        <div class="sf__measure">
          <div class="sf__preview" data-preview></div>
          <div class="sf__sliders">
            <label class="sf__slider">
              <span>Height</span>
              <output data-h-out></output>
              <input type="range" min="140" max="205" step="1" value="${this.a.height}" data-h>
            </label>
            <label class="sf__slider">
              <span>Weight</span>
              <output data-w-out></output>
              <input type="range" min="35" max="140" step="1" value="${this.a.weight}" data-w>
            </label>
            <p class="sf__hint">Drag the sliders. Your warrior changes with you.</p>
          </div>
        </div>
        <button type="button" class="button sf__next" data-next>Next</button>`;
    }

    stepBody() {
      const g = this.a.gender || 'male';
      return `<p class="sf__kicker">Step 3 of 4</p>
        <h2 class="sf__title">Which one looks most like you?</h2>
        <div class="sf__cards sf__cards--4">
          ${BODIES[g].map((b) => `<button type="button" class="sf__card${this.a.body === b.id ? ' is-picked' : ''}" data-body="${b.id}">
            ${figure(g, b)}
            <b>${b.name}</b><small>${b.note}</small>
          </button>`).join('')}
        </div>`;
    }

    stepFit() {
      const g = this.a.gender || 'male';
      const body = BODIES[g].find((b) => b.id === this.a.body) || BODIES[g][2];
      return `<p class="sf__kicker">Step 4 of 4</p>
        <h2 class="sf__title">How oversized do you like it?</h2>
        <div class="sf__cards sf__cards--4">
          ${FITS.map((f) => `<button type="button" class="sf__card sf__card--fit${this.a.fit === f.id ? ' is-picked' : ''}" data-fit="${f.id}">
            ${f.star ? '<span class="sf__star">Most loved</span>' : ''}
            ${figure(g, body, { ease: f.px })}
            <b>${f.name}</b><small>${f.note}</small>
          </button>`).join('')}
        </div>`;
    }

    stepResult() {
      const r = this.compute(this.a);
      if (!r) return '<p class="sf__sub">Size chart unavailable for this product.</p>';
      const g = this.a.gender;
      const body = BODIES[g].find((b) => b.id === this.a.body) || BODIES[g][2];
      const rows = this.sizes.map((z) => `<tr class="${z === r.size ? 'is-rec' : ''}"><th>${esc(z)}</th><td>${this.chart.chest[z].toFixed(1)}″</td><td>${this.chart.length[z] ? `${this.chart.length[z].toFixed(1)}″` : '–'}</td></tr>`).join('');
      const alts = [];
      if (r.tall) alts.push(`<button type="button" class="sf__alt" data-pick="${esc(r.tall)}">Tall warrior? <b>${esc(r.tall)}</b> gives more length</button>`);
      if (r.closer) alts.push(`<button type="button" class="sf__alt" data-pick="${esc(r.closer)}">Closer fit: <b>${esc(r.closer)}</b></button>`);
      if (r.looser) alts.push(`<button type="button" class="sf__alt" data-pick="${esc(r.looser)}">Even looser: <b>${esc(r.looser)}</b></button>`);
      return `<div class="sf__result">
          <div class="sf__result-fig">${figure(g, body, { ease: r.fit.px, scaleY: clamp(this.a.height / 172, 0.9, 1.1) })}</div>
          <div class="sf__result-main">
            <p class="sf__kicker">Your HAIKUFIT size</p>
            <div class="sf__seal" data-seal><span>${esc(r.size)}</span></div>
            <p class="sf__fitname">${esc(r.fit.name)} fit · ${esc(ftIn(this.a.height))} · ${this.a.weight} kg</p>
            ${r.tooBig ? '<p class="sf__note">This is our largest size; it will fit a little closer than you chose.</p>' : ''}
          </div>
        </div>
        <div class="sf__alts">${alts.join('')}</div>
        <table class="sf__table"><thead><tr><th>Size</th><th>Chest</th><th>Length</th></tr></thead><tbody>${rows}</tbody></table>
        <div class="sf__actions">
          <button type="button" class="button sf__use" data-use="${esc(r.size)}">Select ${esc(r.size)}</button>
          <button type="button" class="button button--secondary sf__add" data-add="${esc(r.size)}">Add ${esc(r.size)} to cart</button>
        </div>
        <button type="button" class="sf__restart" data-restart>Start over</button>
        <p class="sf__fine">Estimated from your answers and this tee's size chart (garment measurements). Free size exchange within 7 days.</p>
        <div class="sf__petals" aria-hidden="true">${Array.from({ length: 14 }, (_, i) => `<i style="--x:${(i * 37) % 100}%;--d:${1.6 + (i % 5) * 0.35}s;--r:${(i * 53) % 360}deg"></i>`).join('')}</div>`;
    }

    bind(step) {
      const $ = (sel) => this.d.querySelector(sel);
      const $$ = (sel) => this.d.querySelectorAll(sel);
      if (step === 0) {
        $$('[data-gender]').forEach((b) => b.addEventListener('click', () => {
          const changed = this.a.gender !== b.dataset.gender;
          this.a.gender = b.dataset.gender;
          if (changed) {
            this.a.body = null;
            if (!this.profile) { this.a.height = this.a.gender === 'female' ? 160 : 172; this.a.weight = this.a.gender === 'female' ? 56 : 68; }
          }
          b.classList.add('is-picked');
          setTimeout(() => this.go(1), 220);
        }));
      }
      if (step === 1) {
        const h = $('[data-h]'); const w = $('[data-w]');
        const draw = () => {
          this.a.height = parseInt(h.value, 10);
          this.a.weight = parseInt(w.value, 10);
          $('[data-h-out]').textContent = `${ftIn(this.a.height)} · ${this.a.height} cm`;
          $('[data-w-out]').textContent = `${this.a.weight} kg`;
          const shape = bodyFromBmi(this.a.gender, this.a.height, this.a.weight);
          $('[data-preview]').innerHTML = figure(this.a.gender, shape, { scaleY: clamp(this.a.height / 172, 0.82, 1.12) });
          [h, w].forEach((el) => el.style.setProperty('--p', `${((el.value - el.min) / (el.max - el.min)) * 100}%`));
        };
        h.addEventListener('input', draw);
        w.addEventListener('input', draw);
        draw();
        $('[data-next]').addEventListener('click', () => this.go(2));
      }
      if (step === 2) {
        $$('[data-body]').forEach((b) => b.addEventListener('click', () => {
          this.a.body = b.dataset.body;
          b.classList.add('is-picked');
          setTimeout(() => this.go(3), 220);
        }));
      }
      if (step === 3) {
        $$('[data-fit]').forEach((b) => b.addEventListener('click', () => {
          this.a.fit = b.dataset.fit;
          b.classList.add('is-picked');
          this.profile = { gender: this.a.gender, height: this.a.height, weight: this.a.weight, body: this.a.body, fit: this.a.fit, at: Date.now() };
          try { localStorage.setItem(KEY, JSON.stringify(this.profile)); } catch (e) { /* ignore */ }
          this.updateCta();
          setTimeout(() => this.go(4), 220);
        }));
      }
      if (step === 4) {
        const pickInTable = (size) => {
          this.d.querySelectorAll('.sf__table tr').forEach((tr) => tr.classList.toggle('is-rec', tr.firstElementChild && tr.firstElementChild.textContent === size));
          this.d.querySelector('[data-seal] span').textContent = size;
          $('[data-use]').dataset.use = size; $('[data-use]').textContent = `Select ${size}`;
          $('[data-add]').dataset.add = size; $('[data-add]').textContent = `Add ${size} to cart`;
          const seal = $('[data-seal]'); seal.classList.remove('is-stamped'); void seal.offsetWidth; seal.classList.add('is-stamped');
        };
        $('[data-seal]').classList.add('is-stamped');
        $$('[data-pick]').forEach((b) => b.addEventListener('click', () => pickInTable(b.dataset.pick)));
        const restart = $('[data-restart]');
        if (restart) restart.addEventListener('click', () => this.go(0, -1));
        const use = $('[data-use]');
        if (use) use.addEventListener('click', () => { this.selectOnPage(use.dataset.use); this.d.close(); });
        const add = $('[data-add]');
        if (add) add.addEventListener('click', () => this.addToCart(add.dataset.add, add));
      }
    }

    pageForm() { return this.root.closest('form') || document; }

    selectOnPage(size) {
      const input = [...this.pageForm().querySelectorAll(`[data-option-index="${this.cfg.sizeIndex}"] input`)].find((i) => i.value === size);
      if (input && !input.checked) input.click();
    }

    async addToCart(size, btn) {
      this.selectOnPage(size);
      const form = this.pageForm();
      const vjson = (form.closest('section, [id^="shopify-section"]') || document).querySelector('[data-variants-json]') || document.querySelector('[data-variants-json]');
      const variants = vjson ? JSON.parse(vjson.textContent) : [];
      const colorInput = this.cfg.colorIndex > -1 ? form.querySelector(`[data-option-index="${this.cfg.colorIndex}"] input:checked`) : null;
      const v = variants.find((x) => {
        const o = x.options || [x.option1, x.option2, x.option3];
        return o[this.cfg.sizeIndex] === size && (!colorInput || o[this.cfg.colorIndex] === colorInput.value);
      });
      if (!v || !v.available) { btn.textContent = 'Sold out in this colour'; btn.disabled = true; return; }
      if (!window.theme || !window.theme.addToCart) { this.d.close(); return; }
      btn.classList.add('is-loading');
      try {
        const fd = new FormData();
        fd.append('id', v.id);
        fd.append('quantity', '1');
        this.d.close();
        await window.theme.addToCart(fd);
      } catch (e) {
        btn.textContent = 'Try again';
      } finally {
        btn.classList.remove('is-loading');
      }
    }
  }

  const init = () => document.querySelectorAll('[data-size-finder]').forEach((root) => {
    if (root.dataset.ready) return;
    root.dataset.ready = 'true';
    root.sizeFinder = new SizeFinder(root);
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('shopify:section:load', init);
})();
