/* HAIKUFIT · Live try-on
 * Opens the shopper's camera, tracks their shoulders and hips on the device
 * (MediaPipe Pose, loaded on demand) and places the tee cutout on them.
 * Nothing is uploaded: video frames never leave the browser.
 * Falls back to "place it yourself" (drag + size slider) when tracking can't
 * load or doesn't see a body, and to a photo upload when the camera is blocked.
 */
(() => {
  'use strict';

  const SIZE_SCALE = { XS: 0.9, S: 0.95, M: 1, L: 1.05, XL: 1.1, XXL: 1.16, '3XL': 1.22 };
  const SHOULDER_SPAN = 0.47;   // shoulder joints span ≈ 47% of the cutout width (drop-shoulder oversized cut)
  const SHOULDER_LINE = 0.14;   // shoulder line sits ≈ 14% down from the top of the cutout
  let landmarkerPromise = null;

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function money(cents) {
    const fmt = (window.theme && window.theme.moneyFormat) || '₹{{amount}}';
    const value = (cents / 100);
    const withDec = value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const noDec = Math.round(value).toLocaleString('en-IN');
    return fmt.replace(/\{\{\s*amount_no_decimals\s*\}\}/, noDec).replace(/\{\{\s*amount\s*\}\}/, withDec);
  }

  function strongDevice() {
    const coarse = window.matchMedia('(pointer: coarse)').matches;
    const cores = navigator.hardwareConcurrency || 4;
    const mem = navigator.deviceMemory || 4;
    return !coarse ? cores >= 4 : (cores >= 8 && mem >= 6);
  }

  function loadLandmarker(cfg) {
    if (landmarkerPromise) return landmarkerPromise;
    const base = cfg.visionUrl.replace(/\/$/, '');
    landmarkerPromise = import(/* webpackIgnore: true */ `${base}/vision_bundle.mjs`).then(async (vision) => {
      const files = await vision.FilesetResolver.forVisionTasks(`${base}/wasm`);
      const make = (delegate) => vision.PoseLandmarker.createFromOptions(files, {
        baseOptions: { modelAssetPath: (cfg.modelFullUrl && strongDevice()) ? cfg.modelFullUrl : cfg.modelUrl, delegate },
        runningMode: 'VIDEO',
        numPoses: 1,
        minPoseDetectionConfidence: 0.5,
        minTrackingConfidence: 0.5,
      });
      try { return await make('GPU'); } catch (e) {
        try { return await make('CPU'); } catch (e2) {
          // the full model failed (memory): fall back to the lite one
          cfg.modelFullUrl = '';
          return make('CPU');
        }
      }
    });
    landmarkerPromise.catch(() => { landmarkerPromise = null; });
    return landmarkerPromise;
  }

  let segmenterPromise = null;
  function loadSegmenter(cfg) {
    if (segmenterPromise) return segmenterPromise;
    const base = cfg.visionUrl.replace(/\/$/, '');
    segmenterPromise = import(/* webpackIgnore: true */ `${base}/vision_bundle.mjs`).then(async (vision) => {
      const files = await vision.FilesetResolver.forVisionTasks(`${base}/wasm`);
      const make = (delegate) => vision.ImageSegmenter.createFromOptions(files, {
        baseOptions: { modelAssetPath: cfg.segUrl, delegate },
        runningMode: 'VIDEO',
        outputCategoryMask: true,
        outputConfidenceMasks: false,
      });
      try { return await make('GPU'); } catch (e) { return make('CPU'); }
    });
    segmenterPromise.catch(() => { segmenterPromise = null; });
    return segmenterPromise;
  }

  // categories of the selfie multiclass model
  const CAT = { background: 0, hair: 1, body: 2, face: 3, clothes: 4, other: 5 };

  // One Euro filter: steady when still, responsive when moving
  class OneEuro {
    constructor(minCutoff = 1.4, beta = 0.012, dCutoff = 1.2) {
      Object.assign(this, { minCutoff, beta, dCutoff, x: null, dx: 0, t: 0 });
    }
    alpha(cutoff, dt) { const r = 2 * Math.PI * cutoff * dt; return r / (r + 1); }
    filter(value, t) {
      if (this.x === null) { this.x = value; this.t = t; return value; }
      const dt = Math.max(1 / 120, (t - this.t) / 1000);
      this.t = t;
      const dx = (value - this.x) / dt;
      this.dx += this.alpha(this.dCutoff, dt) * (dx - this.dx);
      const cutoff = this.minCutoff + this.beta * Math.abs(this.dx);
      this.x += this.alpha(cutoff, dt) * (value - this.x);
      return this.x;
    }
  }

  // draw the source triangle s of img onto destination triangle d (affine texture mapping)
  function drawTriangle(ctx, img, s0, s1, s2, d0, d1, d2) {
    const den = s0.x * (s1.y - s2.y) + s1.x * (s2.y - s0.y) + s2.x * (s0.y - s1.y);
    if (Math.abs(den) < 1e-6) return;
    const a = (d0.x * (s1.y - s2.y) + d1.x * (s2.y - s0.y) + d2.x * (s0.y - s1.y)) / den;
    const b = (d0.y * (s1.y - s2.y) + d1.y * (s2.y - s0.y) + d2.y * (s0.y - s1.y)) / den;
    const c = (d0.x * (s2.x - s1.x) + d1.x * (s0.x - s2.x) + d2.x * (s1.x - s0.x)) / den;
    const d = (d0.y * (s2.x - s1.x) + d1.y * (s0.x - s2.x) + d2.y * (s1.x - s0.x)) / den;
    const e = (d0.x * (s1.x * s2.y - s2.x * s1.y) + d1.x * (s2.x * s0.y - s0.x * s2.y) + d2.x * (s0.x * s1.y - s1.x * s0.y)) / den;
    const f = (d0.y * (s1.x * s2.y - s2.x * s1.y) + d1.y * (s2.x * s0.y - s0.x * s2.y) + d2.y * (s0.x * s1.y - s1.x * s0.y)) / den;
    // grow the clip a hair so neighbouring triangles don't leave hairline seams
    const cx = (d0.x + d1.x + d2.x) / 3; const cy = (d0.y + d1.y + d2.y) / 3;
    const grow = (p) => { const dx = p.x - cx; const dy = p.y - cy; const l = Math.hypot(dx, dy) || 1; return { x: p.x + (dx / l) * 0.8, y: p.y + (dy / l) * 0.8 }; };
    const g0 = grow(d0); const g1 = grow(d1); const g2 = grow(d2);
    ctx.save();
    ctx.beginPath();
    ctx.moveTo(g0.x, g0.y); ctx.lineTo(g1.x, g1.y); ctx.lineTo(g2.x, g2.y);
    ctx.closePath();
    ctx.clip();
    ctx.setTransform(a, b, c, d, e, f);
    ctx.drawImage(img, 0, 0);
    ctx.restore();
  }

  const V = {
    add: (p, q) => ({ x: p.x + q.x, y: p.y + q.y }),
    sub: (p, q) => ({ x: p.x - q.x, y: p.y - q.y }),
    mul: (p, k) => ({ x: p.x * k, y: p.y * k }),
    len: (p) => Math.hypot(p.x, p.y),
    norm: (p) => { const l = Math.hypot(p.x, p.y) || 1; return { x: p.x / l, y: p.y / l }; },
    lerp: (p, q, t) => ({ x: p.x + (q.x - p.x) * t, y: p.y + (q.y - p.y) * t }),
    rot: (p, a) => ({ x: p.x * Math.cos(a) - p.y * Math.sin(a), y: p.x * Math.sin(a) + p.y * Math.cos(a) }),
  };
  const angleDiff = (a, b) => Math.atan2(Math.sin(a - b), Math.cos(a - b));

  // Layout of the tee cutouts (fractions of the cutout's width/height), measured from the photos
  const TEE = {
    bodyL: 0.17, bodyR: 0.83,          // body panel edges
    shoulderRow: 0.13,                 // where the wearer's shoulders sit
    jointL: 0.245, jointR: 0.755,      // wearer's shoulder joints
    sleeve: {                          // left sleeve quad (right sleeve mirrors it)
      rootTop: { x: 0.17, y: 0.16 }, rootBottom: { x: 0.17, y: 0.57 },
      tipTop: { x: 0.0, y: 0.40 }, tipBottom: { x: 0.035, y: 0.47 },
    },
  };

  // size chart text ("Size,Chest,Length,...\nS,42,27.5,...") -> inches per size
  function parseChart(text) {
    const rows = String(text || '').split(/\r?\n/).map((r) => r.split(',').map((c) => c.trim())).filter((r) => r.length > 1 && r[0]);
    if (rows.length < 2) return null;
    const head = rows[0].map((h) => h.toLowerCase());
    const col = (word) => head.findIndex((h) => h.includes(word));
    const ci = col('chest'); const li = col('length');
    if (ci < 0) return null;
    const out = { sizes: [], chest: {}, length: {} };
    rows.slice(1).forEach((r) => {
      const size = r[0];
      const c = parseFloat(r[ci]);
      if (!size || Number.isNaN(c)) return;
      out.sizes.push(size);
      out.chest[size] = c;
      if (li >= 0 && !Number.isNaN(parseFloat(r[li]))) out.length[size] = parseFloat(r[li]);
    });
    if (!out.sizes.length) return null;
    // centimetre charts (chest values above 70) are converted to inches
    const cm = head.join(' ').includes('cm') || out.chest[out.sizes[0]] > 70;
    if (cm) {
      out.sizes.forEach((z) => { out.chest[z] /= 2.54; if (out.length[z]) out.length[z] /= 2.54; });
    }
    return out;
  }

  const FIT_EASE = { oversized: 4, regular: 2 };   // inches of garment chest over body chest

  function heightOptions(selected) {
    let html = '';
    for (let cm = 140; cm <= 201; cm += 1) {
      const totalIn = Math.round(cm / 2.54);
      const ft = Math.floor(totalIn / 12); const inch = totalIn % 12;
      html += `<option value="${cm}"${cm === selected ? ' selected' : ''}>${ft}′${inch}″ · ${cm} cm</option>`;
    }
    return html;
  }

  function makeCanvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w; c.height = h;
    return c;
  }

  class TryOn {
    constructor(root) {
      this.root = root;
      this.cfg = JSON.parse(root.querySelector('[data-tryon-config]').textContent);
      this.s = this.cfg.strings;
      const section = root.closest('[id^="shopify-section"], section') || document;
      const vjson = section.querySelector('[data-variants-json]') || document.querySelector('[data-variants-json]');
      this.variants = vjson ? JSON.parse(vjson.textContent) : (this.cfg.variants || []);
      Object.values(this.cfg.map).forEach((sides) => Object.keys(sides).forEach((k) => { if (!sides[k]) delete sides[k]; }));
      this.colors = Object.keys(this.cfg.map);
      this.images = {};
      this.color = this.pageColor() || this.colors[0];
      this.side = 'front';
      this.size = this.pageSize() || this.cfg.sizes[Math.min(1, this.cfg.sizes.length - 1)] || 'M';
      this.tee = null;          // smoothed placement {cx, top, w, h, angle}
      this.manual = false;
      this.lost = 0;
      this.facing = 'user';
      this.chart = parseChart(this.cfg.chart);
      try { this.profile = JSON.parse(localStorage.getItem('hf_fit')) || null; } catch (e) { this.profile = null; }
      if (this.profile && this.profile.chest) this.recommend();
      root.querySelector('[data-tryon-open]').addEventListener('click', () => this.open());
      this.injectPageFinder();
    }

    /* ---------- size finder ---------- */
    // on the product page: a "Find your size" link under the size options
    injectPageFinder() {
      if (this.cfg.sizeIndex < 0 || !this.chart) return;
      const form = this.root.closest('form') || document;
      const fieldset = form.querySelector(`[data-option-index="${this.cfg.sizeIndex}"]`);
      if (!fieldset || fieldset.parentElement.querySelector('.size-finder-link')) return;
      const box = document.createElement('div');
      box.className = 'size-finder-link';
      fieldset.insertAdjacentElement('afterend', box);
      this.pageFinder = box;
      this.renderPageFinder();
    }

    renderPageFinder() {
      if (!this.pageFinder) return;
      const s = this.s;
      const rec = this.rec;
      this.pageFinder.innerHTML = `${rec ? `<span class="size-finder-link__rec">${esc(s.yourSize)}: <b>${esc(rec.size)}</b></span>` : ''}
        <button type="button" class="size-finder-link__btn" data-open-finder>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 17 17 3l4 4L7 21H3z"/><path d="m7 13 2 2m1-5 2 2m1-5 2 2"/></svg>
          ${esc(rec ? s.recheckSize : s.findSizeLink)}
        </button>`;
      this.pageFinder.querySelector('[data-open-finder]').addEventListener('click', () => this.open(true));
    }

    sizeList() {
      const fromVariants = this.cfg.sizes || [];
      return fromVariants.length ? fromVariants : (this.chart ? this.chart.sizes : []);
    }

    // garment-chest ratio between the chosen size and the size the tee photo is fitted as
    sizeScale(size) {
      const z = size || this.size;
      if (this.chart && this.chart.chest[z]) {
        const base = (this.rec && this.chart.chest[this.rec.size]) ? this.rec.size : (this.chart.chest.M ? 'M' : this.chart.sizes[Math.floor(this.chart.sizes.length / 2)]);
        return clamp(this.chart.chest[z] / this.chart.chest[base], 0.8, 1.3);
      }
      return SIZE_SCALE[z] || 1;
    }

    // body chest (inches) from height, optional weight and the measured shoulder/torso ratio
    estimateChest(heightCm, weightKg, ratio) {
      let chestPose = null;
      if (ratio) chestPose = heightCm * 0.55 * Math.pow(clamp(ratio, 0.5, 1.05) / 0.72, 0.8);
      const chestBody = weightKg ? 0.52 * weightKg + 0.26 * heightCm + 16 : null;
      let cm;
      if (chestPose && chestBody) cm = 0.6 * chestBody + 0.4 * chestPose;
      else cm = chestPose || chestBody || heightCm * 0.55;
      return cm / 2.54;
    }

    recommend() {
      const p = this.profile;
      if (!p || !this.chart) return null;
      const sizes = this.sizeList().filter((z) => this.chart.chest[z]);
      if (!sizes.length) return null;
      const pref = p.pref || 'oversized';
      const need = p.chest + FIT_EASE[pref];
      let size = sizes.find((z) => this.chart.chest[z] >= need) || sizes[sizes.length - 1];
      const i = sizes.indexOf(size);
      const alt = pref === 'oversized' ? sizes[i - 1] : sizes[i + 1];
      this.rec = { size, alt, pref, chest: p.chest, tooBig: !sizes.some((z) => this.chart.chest[z] >= need) };
      return this.rec;
    }

    fitLabel(size) {
      if (!this.rec || !this.chart || !this.chart.chest[size]) return '';
      const ease = this.chart.chest[size] - this.rec.chest;
      const s = this.s;
      if (ease < 0) return { text: s.fitTight, tone: 'bad' };
      if (ease < 1.5) return { text: s.fitSnug, tone: 'warn' };
      if (ease < 4) return { text: s.fitRelaxed, tone: 'ok' };
      if (ease < 7.5) return { text: s.fitOversized, tone: 'good' };
      return { text: s.fitVeryLoose, tone: 'warn' };
    }

    openFinder() {
      const s = this.s;
      const p = this.profile || {};
      const f = this.el.finder;
      f.innerHTML = `
        <div class="tryon__finder-card">
          <p class="tryon__finder-title">📏 ${esc(s.findSizeTitle)}</p>
          <p class="tryon__finder-sub">${esc(s.findSizeSub)}</p>
          <label class="tryon__field">${esc(s.height)}
            <select data-height>${heightOptions(p.height || 170)}</select>
          </label>
          <label class="tryon__field">${esc(s.weight)} <small>${esc(s.optional)}</small>
            <input type="number" inputmode="numeric" min="30" max="200" placeholder="${esc(s.weightPlaceholder)}" value="${p.weight || ''}" data-weight>
          </label>
          <div class="tryon__field">${esc(s.fitPref)}
            <div class="tryon__seg tryon__pref" data-pref>
              <button type="button" data-v="oversized" class="${(p.pref || 'oversized') === 'oversized' ? 'is-active' : ''}">${esc(s.prefOversized)}</button>
              <button type="button" data-v="regular" class="${p.pref === 'regular' ? 'is-active' : ''}">${esc(s.prefRegular)}</button>
            </div>
          </div>
          <button type="button" class="button tryon__measure-btn" data-measure>${esc(this.stream ? s.measureMe : s.getMySize)}</button>
          <button type="button" class="tryon__link" data-finder-cancel>${esc(s.cancel)}</button>
        </div>`;
      f.hidden = false;
      let pref = p.pref || 'oversized';
      f.querySelectorAll('[data-pref] button').forEach((b) => b.addEventListener('click', () => {
        pref = b.dataset.v;
        f.querySelectorAll('[data-pref] button').forEach((x) => x.classList.toggle('is-active', x === b));
      }));
      f.querySelector('[data-finder-cancel]').addEventListener('click', () => { f.hidden = true; });
      f.querySelector('[data-measure]').addEventListener('click', () => {
        const height = parseInt(f.querySelector('[data-height]').value, 10);
        const weight = parseFloat(f.querySelector('[data-weight]').value) || null;
        f.hidden = true;
        this.startMeasure({ height, weight: weight && weight >= 30 && weight <= 200 ? weight : null, pref });
      });
    }

    startMeasure(input) {
      this.measureInput = input;
      // without body tracking, height and weight alone give the answer
      if (!this.landmarker || this.manual || this.photo && !this.pose) { this.finishMeasure(null); return; }
      this.measuring = { samples: [], started: performance.now() };
      this.status(this.s.measuring);
    }

    sampleMeasure() {
      const m = this.measuring;
      const pose = this.pose;
      const now = performance.now();
      if (pose && pose.hips && now - pose.seen < 300) {
        const torso = V.len(V.sub(V.lerp(pose.HA, pose.HB, 0.5), V.lerp(pose.A, pose.B, 0.5)));
        if (torso > 0) m.samples.push(pose.span / torso);
        const pct = Math.min(100, Math.round((m.samples.length / 30) * 100));
        this.status(`${this.s.measuring} ${pct}%`);
        if (m.samples.length >= 30) {
          const sorted = m.samples.slice().sort((a, b) => a - b);
          this.finishMeasure(sorted[Math.floor(sorted.length / 2)]);
        }
      } else if (now - m.started > 9000) {
        // can't see the waist: fall back to height/weight
        this.finishMeasure(null);
      } else {
        this.status(this.s.measureStepBack);
      }
    }

    finishMeasure(ratio) {
      const input = this.measureInput;
      this.measuring = null;
      const chest = this.estimateChest(input.height, input.weight, ratio);
      this.profile = { height: input.height, weight: input.weight, pref: input.pref, chest, measured: !!ratio, at: Date.now() };
      try { localStorage.setItem('hf_fit', JSON.stringify(this.profile)); } catch (e) { /* ignore */ }
      this.recommend();
      this.status('');
      this.setSize(this.rec.size);
      this.showResult();
      this.renderPageFinder();
    }

    showResult() {
      const s = this.s;
      const r = this.rec;
      if (!r) return;
      const res = this.el.result;
      const rows = this.chart.sizes.filter((z) => this.sizeList().includes(z)).map((z) => `
        <tr class="${z === r.size ? 'is-rec' : ''}"><th>${esc(z)}</th><td>${this.chart.chest[z].toFixed(1)}″</td><td>${this.chart.length[z] ? this.chart.length[z].toFixed(1) + '″' : '–'}</td></tr>`).join('');
      res.innerHTML = `
        <div class="tryon__result-card">
          <button type="button" class="tryon__result-close" data-result-close aria-label="${esc(s.close)}">×</button>
          <p class="tryon__result-label">${esc(s.yourSize)}</p>
          <p class="tryon__result-size">${esc(r.size)}</p>
          <p class="tryon__result-text">${esc(r.pref === 'oversized' ? s.recOversized : s.recRegular)}${r.alt ? ` · ${esc((r.pref === 'oversized' ? s.altCloser : s.altLooser).replace('__SIZE__', r.alt))}` : ''}</p>
          ${r.tooBig ? `<p class="tryon__result-note">${esc(s.recLargest)}</p>` : ''}
          <p class="tryon__result-meta">${esc(s.estChest)}: ${r.chest.toFixed(1)}″ ${this.profile.measured ? '' : `· ${esc(s.fromHeightWeight)}`}</p>
          <table class="tryon__result-table"><thead><tr><th>${esc(s.size)}</th><th>${esc(s.chest)}</th><th>${esc(s.length)}</th></tr></thead><tbody>${rows}</tbody></table>
          <div class="tryon__result-actions">
            <button type="button" class="button" data-result-try>${esc(s.tryThisSize.replace('__SIZE__', r.size))}</button>
            <button type="button" class="tryon__link" data-result-again>${esc(s.measureAgain)}</button>
          </div>
          <p class="tryon__result-disclaimer">${esc(s.sizeDisclaimer)}</p>
        </div>`;
      res.hidden = false;
      const close = () => { res.hidden = true; };
      res.querySelector('[data-result-close]').addEventListener('click', close);
      res.querySelector('[data-result-try]').addEventListener('click', () => { this.setSize(r.size); close(); });
      res.querySelector('[data-result-again]').addEventListener('click', () => { close(); this.openFinder(); });
    }

    setSize(size) {
      if (!size) return;
      this.size = size;
      // keep the product page's size option in sync
      const form = this.root.closest('form') || document;
      const input = [...form.querySelectorAll(`[data-option-index="${this.cfg.sizeIndex}"] input`)].find((i) => i.value === size);
      if (input && !input.checked) input.click();
      if (this.el) this.renderControls();
    }

    /* ---------- page state ---------- */
    pageOptionValue(index) {
      if (index < 0) return null;
      const form = this.root.closest('form') || document;
      const checked = form.querySelector(`[data-option-index="${index}"] input:checked`);
      return checked ? checked.value : null;
    }

    pageColor() {
      const v = this.pageOptionValue(this.cfg.colorIndex);
      if (!v) return null;
      const key = v.toLowerCase();
      return this.colors.includes(key) ? key : null;
    }

    pageSize() { return this.pageOptionValue(this.cfg.sizeIndex); }

    /* ---------- dialog ---------- */
    build() {
      const s = this.s;
      const d = document.createElement('dialog');
      d.className = 'tryon';
      d.setAttribute('aria-label', s.title);
      d.innerHTML = `
        <div class="tryon__stage" data-stage>
          <video class="tryon__video" playsinline muted autoplay></video>
          <canvas class="tryon__canvas" data-canvas></canvas>
          <div class="tryon__guide" data-guide hidden>
            <svg viewBox="0 0 100 140" aria-hidden="true"><path d="M50 8a11 11 0 1 1 0 22a11 11 0 1 1 0-22ZM24 46c4-8 14-12 26-12s22 4 26 12l6 40h-10l-4-26v74H34V60l-4 26H20Z" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="4 4"/></svg>
            <p>${esc(s.guide)}</p>
          </div>
          <p class="tryon__status" data-status>${esc(s.starting)}</p>
          <button type="button" class="tryon__icon tryon__close" data-close aria-label="${esc(s.close)}">×</button>
          <button type="button" class="tryon__icon tryon__flip" data-flip aria-label="${esc(s.switchCamera)}" hidden>⟲</button>
          <span class="tryon__badge"><i></i>${esc(s.live)}</span>
          <div class="tryon__flash" data-flash></div>
          <div class="tryon__finder" data-finder hidden></div>
          <div class="tryon__result" data-result hidden></div>
        </div>
        <div class="tryon__panel">
          <div class="tryon__row tryon__colors" data-colors></div>
          <div class="tryon__row tryon__toggles">
            <div class="tryon__seg" data-sides role="group" aria-label="${esc(s.view)}"></div>
            <div class="tryon__seg tryon__sizes" data-sizes role="group" aria-label="${esc(s.size)}"></div>
          </div>
          <div class="tryon__row tryon__fitline">
            <p class="tryon__fit" data-fit></p>
            <button type="button" class="tryon__find" data-find>📏 ${esc(s.findMySize)}</button>
          </div>
          <div class="tryon__row tryon__manual" data-manual hidden>
            <label>${esc(s.adjust)} <input type="range" min="20" max="95" value="55" data-scale></label>
            <button type="button" class="tryon__link" data-auto hidden>${esc(s.autoFit)}</button>
          </div>
          <div class="tryon__actions">
            <button type="button" class="tryon__real" data-real aria-pressed="true" hidden title="${esc(s.realisticHint)}"><span aria-hidden="true">✦</span> ${esc(s.realistic)}</button>
            <button type="button" class="tryon__snap" data-snap><span aria-hidden="true">◉</span> ${esc(s.snap)}</button>
            <button type="button" class="button tryon__add" data-add>${esc(s.addToCart)}</button>
          </div>
          <p class="tryon__privacy">🔒 ${esc(s.privacy)} <button type="button" class="tryon__link" data-upload>${esc(s.upload)}</button></p>
          <input type="file" accept="image/*" data-file hidden>
        </div>
        <div class="tryon__shot" data-shot hidden>
          <img alt="${esc(s.title)}" data-shot-img>
          <div class="tryon__shot-actions">
            <a class="button" data-save download="haikufit-try-on.jpg">${esc(s.save)}</a>
            <button type="button" class="button button--secondary" data-share hidden>${esc(s.share)}</button>
            <button type="button" class="tryon__link" data-back>${esc(s.backToCamera)}</button>
          </div>
        </div>`;
      document.body.appendChild(d);
      this.d = d;
      const $ = (sel) => d.querySelector(sel);
      this.el = {
        stage: $('[data-stage]'), video: $('video'), canvas: $('[data-canvas]'), guide: $('[data-guide]'), status: $('[data-status]'),
        colors: $('[data-colors]'), sides: $('[data-sides]'), sizes: $('[data-sizes]'), manual: $('[data-manual]'), scale: $('[data-scale]'),
        auto: $('[data-auto]'), add: $('[data-add]'), file: $('[data-file]'), shot: $('[data-shot]'), shotImg: $('[data-shot-img]'),
        save: $('[data-save]'), share: $('[data-share]'), flip: $('[data-flip]'), flash: $('[data-flash]'),
        finder: $('[data-finder]'), result: $('[data-result]'), fit: $('[data-fit]'), find: $('[data-find]'),
      };
      this.el.find.addEventListener('click', () => this.openFinder());
      if (!this.chart) this.el.find.hidden = true;
      this.ctx = this.el.canvas.getContext('2d');

      $('[data-close]').addEventListener('click', () => d.close());
      d.addEventListener('close', () => this.stop());
      d.addEventListener('cancel', () => this.stop());
      $('[data-snap]').addEventListener('click', () => this.snapshot());
      this.el.real = $('[data-real]');
      this.el.real.addEventListener('click', () => {
        this.realistic = !this.realistic;
        this.el.real.setAttribute('aria-pressed', this.realistic ? 'true' : 'false');
      });
      $('[data-back]').addEventListener('click', () => { this.el.shot.hidden = true; });
      this.el.add.addEventListener('click', () => this.addToCart());
      $('[data-upload]').addEventListener('click', () => this.el.file.click());
      this.el.file.addEventListener('change', () => this.usePhoto(this.el.file.files[0]));
      this.el.flip.addEventListener('click', () => { this.facing = this.facing === 'user' ? 'environment' : 'user'; this.startCamera(); });
      this.el.scale.addEventListener('input', () => { this.manual = true; this.placeManual(); });
      this.el.auto.addEventListener('click', () => { this.manual = false; this.el.auto.hidden = true; });
      this.bindDrag();
      this.renderControls();
    }

    renderControls() {
      const s = this.s;
      this.el.colors.innerHTML = this.colors.map((c) => {
        const swatch = (window.theme && window.theme.swatches && window.theme.swatches[c]) || c.replace(/\s+/g, '');
        return `<button type="button" class="tryon__chip${c === this.color ? ' is-active' : ''}" data-color="${esc(c)}" aria-pressed="${c === this.color}" title="${esc(c)}"><span style="background:${esc(swatch)}"></span>${esc(c)}</button>`;
      }).join('');
      const sides = this.cfg.map[this.color] || {};
      if (!sides[this.side]) this.side = sides.front ? 'front' : 'back';
      this.el.sides.innerHTML = ['front', 'back'].filter((k) => sides[k]).map((k) => `<button type="button" data-side="${k}" aria-pressed="${k === this.side}" class="${k === this.side ? 'is-active' : ''}">${esc(k === 'front' ? s.front : s.back)}</button>`).join('');
      const recSize = this.rec && this.rec.size;
      this.el.sizes.innerHTML = this.sizeList().map((z) => `<button type="button" data-size="${esc(z)}" aria-pressed="${z === this.size}" class="${z === this.size ? 'is-active' : ''}${z === recSize ? ' is-rec' : ''}">${esc(z)}${z === recSize ? '<i aria-hidden="true">★</i>' : ''}</button>`).join('');
      const chartRow = this.chart && this.chart.chest[this.size];
      const fit = this.fitLabel(this.size);
      this.el.fit.innerHTML = chartRow
        ? `<b>${esc(this.size)}</b> · ${esc(s.chest)} ${this.chart.chest[this.size].toFixed(1)}″${this.chart.length[this.size] ? ` · ${esc(s.length)} ${this.chart.length[this.size].toFixed(1)}″` : ''}${fit ? ` <span class="tryon__fit-tag tryon__fit-tag--${fit.tone}">${esc(fit.text)}</span>` : ''}`
        : '';
      this.el.colors.querySelectorAll('[data-color]').forEach((b) => b.addEventListener('click', () => { this.color = b.dataset.color; this.renderControls(); this.loadTee(); }));
      this.el.sides.querySelectorAll('[data-side]').forEach((b) => b.addEventListener('click', () => { this.side = b.dataset.side; this.renderControls(); this.loadTee(); }));
      this.el.sizes.querySelectorAll('[data-size]').forEach((b) => b.addEventListener('click', () => this.setSize(b.dataset.size)));
      this.updateAdd();
    }

    variant() {
      return this.variants.find((v) => {
        const opts = v.options || [v.option1, v.option2, v.option3];
        const colorOk = this.cfg.colorIndex < 0 || (opts[this.cfg.colorIndex] || '').toLowerCase() === this.color;
        const sizeOk = this.cfg.sizeIndex < 0 || opts[this.cfg.sizeIndex] === this.size;
        return colorOk && sizeOk;
      });
    }

    updateAdd() {
      const v = this.variant();
      const b = this.el.add;
      if (!v || !v.available) { b.disabled = true; b.textContent = this.s.soldOut; return; }
      b.disabled = false;
      b.textContent = `${this.s.addToCart} · ${money(v.price)}`;
    }

    async addToCart() {
      const v = this.variant();
      if (!v || !window.theme || !window.theme.addToCart) return;
      this.el.add.classList.add('is-loading');
      try {
        const fd = new FormData();
        fd.append('id', v.id);
        fd.append('quantity', '1');
        this.d.close();
        await window.theme.addToCart(fd);
      } catch (e) {
        this.status(e.message || this.s.error);
      } finally {
        this.el.add.classList.remove('is-loading');
      }
    }

    /* ---------- open / close ---------- */
    async open(withFinder) {
      if (!this.d) this.build();
      this.renderControls();
      if (withFinder === true) setTimeout(() => this.openFinder(), 50);
      else if (this.el.finder) this.el.finder.hidden = true;
      this.el.shot.hidden = true;
      this.d.showModal();
      this.running = true;
      this.photo = null;
      this.resize();
      this.loadTee();
      this.status(this.s.starting);
      this.startCamera();
      if (this.cfg.modelUrl) {
        loadLandmarker(this.cfg).then((lm) => {
          this.landmarker = lm;
          this.statusTracking();
          if (this.cfg.segUrl && !this.segmenter) {
            loadSegmenter(this.cfg).then((seg) => {
              this.segmenter = seg;
              this.realistic = true;
              this.el.real.hidden = false;
            }).catch(() => {});
          }
        }).catch(() => this.enableManual(this.s.noTracking));
      } else {
        this.enableManual('');
      }
      if (!this.resizeBound) { this.resizeBound = () => this.resize(); window.addEventListener('resize', this.resizeBound); }
      cancelAnimationFrame(this.raf);
      this.raf = requestAnimationFrame(() => this.loop());
    }

    stop() {
      this.running = false;
      cancelAnimationFrame(this.raf);
      if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
    }

    async startCamera() {
      if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { this.cameraFailed(); return; }
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: this.facing, width: { ideal: 1280 }, height: { ideal: 1280 } },
          audio: false,
        });
        if (!this.running) { this.stop(); return; }
        this.photo = null;
        this.el.video.srcObject = this.stream;
        await this.el.video.play().catch(() => {});
        this.statusTracking();
        navigator.mediaDevices.enumerateDevices().then((list) => {
          this.el.flip.hidden = list.filter((x) => x.kind === 'videoinput').length < 2;
        }).catch(() => {});
      } catch (e) {
        this.cameraFailed();
      }
    }

    cameraFailed() {
      this.status(this.s.cameraBlocked, true);
      this.d.classList.add('is-no-camera');
    }

    usePhoto(file) {
      if (!file) return;
      const img = new Image();
      img.onload = async () => {
        if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
        this.photo = img;
        this.maskReady = false;
        this.filters = null;
        this.pose = null;
        this.fit = null;
        this.d.classList.remove('is-no-camera');
        this.tee = null;
        if (this.landmarker) {
          try {
            await this.landmarker.setOptions({ runningMode: 'IMAGE' });
            const res = this.landmarker.detect(img);
            await this.landmarker.setOptions({ runningMode: 'VIDEO' });
            if (!this.applyPose(res, true)) this.enableManual(this.s.noBody);
            else this.status('');
            if (this.segmenter) {
              await this.segmenter.setOptions({ runningMode: 'IMAGE' });
              this.segment({ el: img }, true);
              await this.segmenter.setOptions({ runningMode: 'VIDEO' });
            }
          } catch (e) { this.enableManual(this.s.noBody); }
        } else {
          this.enableManual('');
        }
      };
      img.src = URL.createObjectURL(file);
      this.el.file.value = '';
    }

    statusTracking() {
      if (this.photo) return;
      if (!this.stream) return;
      this.status(this.landmarker ? this.s.findBody : this.s.loadingTracking);
    }

    status(text, persistent) {
      this.el.status.textContent = text || '';
      this.el.status.hidden = !text;
      this.el.status.classList.toggle('is-alert', !!persistent);
    }

    enableManual(message) {
      this.el.manual.hidden = false;
      this.el.guide.hidden = true;
      this.manual = true;
      if (message !== undefined) this.status(message);
      if (!this.tee) this.placeManual();
    }

    /* ---------- tee image ---------- */
    teeUrl() {
      const entry = this.cfg.map[this.color] || {};
      const file = entry[this.side];
      if (!file) return null;
      return /^(https?:)?\/\//.test(file) ? file : this.cfg.assetBase + file;
    }

    loadTee() {
      const url = this.teeUrl();
      if (!url) return;
      if (this.images[url]) { this.teeImg = this.images[url]; return; }
      const img = new Image();
      const abs = new URL(url, location.href);
      if (abs.origin !== location.origin) img.crossOrigin = 'anonymous';
      img.onload = () => {
        img.dataset.base = this.sampleColour(img);
        this.images[url] = img;
        if (this.teeUrl() === url) this.teeImg = img;
      };
      img.src = url;
    }

    // fabric colour from the sleeves (plain on every design)
    sampleColour(img) {
      const c = makeCanvas(40, 48);
      const x = c.getContext('2d', { willReadFrequently: true });
      x.drawImage(img, 0, 0, 40, 48);
      let d;
      try { d = x.getImageData(0, 0, 40, 48).data; } catch (e) { return '#808080'; }
      let r = 0; let g = 0; let b = 0; let n = 0;
      [[2, 8, 7, 14], [33, 8, 38, 14]].forEach(([x0, y0, x1, y1]) => {
        for (let yy = y0; yy < y1; yy++) {
          for (let xx = x0; xx < x1; xx++) {
            const i = (yy * 40 + xx) * 4;
            if (d[i + 3] > 200) { r += d[i]; g += d[i + 1]; b += d[i + 2]; n++; }
          }
        }
      });
      if (!n) return '#808080';
      return `rgb(${Math.round(r / n)},${Math.round(g / n)},${Math.round(b / n)})`;
    }

    /* ---------- segmentation masks ---------- */
    updateMasks(mask) {
      const w = mask.width; const h = mask.height;
      const cats = mask.getAsUint8Array();
      if (!this.maskClothes || this.maskClothes.width !== w || this.maskClothes.height !== h) {
        this.maskClothes = makeCanvas(w, h);
        this.maskSkin = makeCanvas(w, h);
        this.maskClothesData = new ImageData(w, h);
        this.maskSkinData = new ImageData(w, h);
      }
      const cd = this.maskClothesData.data; const sd = this.maskSkinData.data;
      for (let i = 0, j = 3; i < cats.length; i++, j += 4) {
        const c = cats[i];
        cd[j] = c === CAT.clothes ? 255 : 0;
        sd[j] = c === CAT.body ? 255 : 0;
      }
      this.measureWaist(cats, w, h);
      this.maskClothes.getContext('2d').putImageData(this.maskClothesData, 0, 0);
      this.maskSkin.getContext('2d').putImageData(this.maskSkinData, 0, 0);
      this.maskReady = true;
    }

    // width of the wearer's torso three-quarters of the way down to the hips, in canvas pixels
    measureWaist(cats, w, h) {
      const n = this.lmNorm;
      const src = this.source();
      if (!n || n.hipY === null || !src) return;
      const y = Math.round(lerp(n.shoulderY, n.hipY, 0.75) * h);
      if (y < 0 || y >= h) return;
      const cx = Math.round(n.midX * w);
      const limit = Math.round(n.spanN * 0.95 * w);
      const isBody = (x) => { const c = cats[y * w + x]; return c === CAT.clothes || c === CAT.body; };
      let l = cx; let r = cx;
      while (l > Math.max(0, cx - limit) && isBody(l - 1)) l--;
      while (r < Math.min(w - 1, cx + limit) && isBody(r + 1)) r++;
      if (r - l < 4) return;
      const f = this.frame(src);
      const px = ((r - l) / w) * src.w * f.scale;
      if (!this.fit) this.fit = { waist: px };
      else this.fit.waist = lerp(this.fit.waist, px, 0.2);
    }

    segment(src, image) {
      if (!this.segmenter || !src) return;
      try {
        const res = image ? this.segmenter.segment(src.el) : this.segmenter.segmentForVideo(src.el, performance.now());
        if (res && res.categoryMask) this.updateMasks(res.categoryMask);
        if (res && res.close) res.close();
      } catch (e) { /* keep last masks */ }
    }

    buffer(name) {
      if (!this[name] || this[name].width !== this.W || this[name].height !== this.H) this[name] = makeCanvas(this.W, this.H);
      const ctx = this[name].getContext('2d');
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 1;
      ctx.clearRect(0, 0, this.W, this.H);
      return ctx;
    }

    drawSourceTo(ctx, el, src) {
      const f = this.frame(src);
      ctx.save();
      if (src.mirror) { ctx.translate(this.W, 0); ctx.scale(-1, 1); }
      ctx.imageSmoothingEnabled = true;
      ctx.drawImage(el, f.ox, f.oy, src.w * f.scale, src.h * f.scale);
      ctx.restore();
    }

    drawTeeTo(ctx) {
      ctx.drawImage(this.bufTee, 0, 0);
    }

    // grey copy of the camera frame: carries the real folds and light
    greyTo(ctx, src) {
      this.drawSourceTo(ctx, src.el, src);
      ctx.globalCompositeOperation = 'saturation';
      ctx.fillStyle = '#808080';
      ctx.fillRect(0, 0, this.W, this.H);
      ctx.globalCompositeOperation = 'source-over';
    }

    /* ---------- geometry ---------- */
    resize() {
      const r = this.el.stage.getBoundingClientRect();
      this.dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      this.el.canvas.width = Math.round(r.width * this.dpr);
      this.el.canvas.height = Math.round(r.height * this.dpr);
      this.W = this.el.canvas.width;
      this.H = this.el.canvas.height;
    }

    source() {
      if (this.photo) return { el: this.photo, w: this.photo.naturalWidth, h: this.photo.naturalHeight, mirror: false };
      const v = this.el.video;
      if (v.readyState >= 2 && v.videoWidth) return { el: v, w: v.videoWidth, h: v.videoHeight, mirror: this.facing === 'user' };
      return null;
    }

    frame(src) {
      const scale = Math.max(this.W / src.w, this.H / src.h);
      return { scale, ox: (this.W - src.w * scale) / 2, oy: (this.H - src.h * scale) / 2 };
    }

    toCanvas(p, src, f) {
      let x = f.ox + p.x * src.w * f.scale;
      if (src.mirror) x = this.W - x;
      return { x, y: f.oy + p.y * src.h * f.scale };
    }

    applyPose(res, instant) {
      const src = this.source();
      const lm = res && res.landmarks && res.landmarks[0];
      if (!src || !lm) return false;
      const vis = (i) => lm[i] && (lm[i].visibility === undefined || lm[i].visibility > 0.5);
      if (!vis(11) || !vis(12)) return false;
      const f = this.frame(src);
      this.lm = lm;
      const now = performance.now();
      if (instant || !this.filters) this.filters = {};
      // smoothed canvas positions of the joints we use
      const P = (i) => {
        const p = this.toCanvas(lm[i], src, f);
        const fx = this.filters[`${i}x`] || (this.filters[`${i}x`] = new OneEuro());
        const fy = this.filters[`${i}y`] || (this.filters[`${i}y`] = new OneEuro());
        return { x: fx.filter(p.x, now), y: fy.filter(p.y, now) };
      };
      // screen-left / screen-right sides (the selfie view is mirrored)
      let iL = 11; let iR = 12;
      if (this.toCanvas(lm[11], src, f).x > this.toCanvas(lm[12], src, f).x) { iL = 12; iR = 11; }
      const hipL = iL === 11 ? 23 : 24; const hipR = iL === 11 ? 24 : 23;
      const elbL = iL === 11 ? 13 : 14; const elbR = iL === 11 ? 14 : 13;
      const A = P(iL); const B = P(iR);
      const span = V.len(V.sub(B, A));
      if (span < this.W * 0.05) return false;
      const pose = { A, B, span, hips: vis(23) && vis(24), seen: now };
      if (pose.hips) { pose.HA = P(hipL); pose.HB = P(hipR); }
      if (vis(elbL)) pose.EA = P(elbL);
      if (vis(elbR)) pose.EB = P(elbR);
      if (vis(0)) pose.nose = P(0);
      pose.yaw = Math.abs((lm[11].z || 0) - (lm[12].z || 0)) / Math.max(0.01, Math.abs(lm[11].x - lm[12].x));
      this.pose = pose;
      this.lmNorm = { shoulderY: (lm[11].y + lm[12].y) / 2, hipY: pose.hips ? (lm[23].y + lm[24].y) / 2 : null, midX: (lm[11].x + lm[12].x) / 2, spanN: Math.abs(lm[11].x - lm[12].x) };

      // rigid placement (used for drag mode and the recolour area)
      const img = this.teeImg;
      const aspect = img ? img.naturalHeight / img.naturalWidth : 1.04;
      const w = (span / SHOULDER_SPAN) * this.sizeScale();
      let h = w * aspect;
      const mid = V.lerp(A, B, 0.5);
      if (pose.hips) {
        const torso = V.len(V.sub(V.lerp(pose.HA, pose.HB, 0.5), mid));
        h = clamp((torso * 1.3) / (1 - SHOULDER_LINE), h * 0.85, h * 1.25);
      }
      this.tee = { cx: mid.x, top: mid.y - h * SHOULDER_LINE, w, h, angle: Math.atan2(B.y - A.y, B.x - A.x) };
      return true;
    }

    /* ---------- body-following warp ---------- */
    renderTee() {
      const ctx = this.buffer('bufTee');
      const img = this.teeImg;
      const pose = this.pose;
      const warp = pose && !this.manual && (this.photo || performance.now() - pose.seen < 600);
      if (!warp) {
        const t = this.tee;
        ctx.save();
        ctx.translate(t.cx, t.top + t.h * SHOULDER_LINE);
        ctx.rotate(t.angle);
        ctx.drawImage(img, -t.w / 2, -t.h * SHOULDER_LINE, t.w, t.h);
        ctx.restore();
        return;
      }
      const IW = img.naturalWidth; const IH = img.naturalHeight;
      const px = (u, v) => ({ x: u * IW, y: v * IH });
      const size = this.sizeScale();
      const { A, B, span } = pose;
      const across = V.norm(V.sub(B, A));
      const down = { x: -across.y, y: across.x };
      const mid = V.lerp(A, B, 0.5);
      // tee pixels -> canvas pixels, from the shoulder joints
      const jointsImg = (TEE.jointR - TEE.jointL) * IW;
      const k = (span / jointsImg) * size;

      // shoulder row of the body panel
      const halfTop = ((TEE.bodyR - TEE.bodyL) / 2) * IW * k;
      const TL = V.sub(mid, V.mul(across, halfTop));
      const TR = V.add(mid, V.mul(across, halfTop));

      // hem row: length from the photo, nudged towards the wearer's real torso
      const naturalLen = (1 - TEE.shoulderRow) * IH * k;
      let hemDir = down;
      let len = naturalLen;
      let hemAcross = across;
      if (pose.hips) {
        const hipMid = V.lerp(pose.HA, pose.HB, 0.5);
        const torsoVec = V.sub(hipMid, mid);
        const torso = V.len(torsoVec);
        hemDir = V.norm(V.lerp(down, V.norm(torsoVec), 0.7));
        len = clamp(torso * 1.25 * size, naturalLen * 0.82, naturalLen * 1.2);
        hemAcross = V.norm(V.lerp(across, V.norm(V.sub(pose.HB, pose.HA)), 0.5));
      }
      let halfHem = halfTop;
      if (this.fit && this.fit.waist) {
        // boxy oversized cut: a little wider than the wearer's own waist
        const fitted = clamp(this.fit.waist * 1.18 * size, span * 1.05 * size, span * 1.55 * size) / 2;
        halfHem = lerp(halfTop, fitted, 0.5);
      }
      const hemMid = V.add(mid, V.mul(hemDir, len));
      const BL = V.sub(hemMid, V.mul(hemAcross, halfHem));
      const BR = V.add(hemMid, V.mul(hemAcross, halfHem));

      // body: bilinear grid; rows above the shoulder line extend straight up (collar)
      const bodyAt = (u, v) => {
        const t = (v - TEE.shoulderRow) / (1 - TEE.shoulderRow);
        const top = V.lerp(TL, TR, u);
        const bottom = V.lerp(BL, BR, u);
        return V.lerp(top, bottom, t);
      };
      const cols = 4; const rows = 6;
      const tri = (sa, sb, sc, da, db, dc) => drawTriangle(ctx, img, sa, sb, sc, da, db, dc);

      // sleeves first, body on top
      const sl = TEE.sleeve;
      [-1, 1].forEach((side) => {
        const mirrorX = (p) => (side < 0 ? p : { x: 1 - p.x, y: p.y });
        const rT = mirrorX(sl.rootTop); const rB = mirrorX(sl.rootBottom);
        const tT = mirrorX(sl.tipTop); const tB = mirrorX(sl.tipBottom);
        const uRoot = side < 0 ? 0 : 1;
        const vToT = (v) => v;
        const dRootTop = bodyAt(uRoot, vToT(rT.y));
        const dRootBot = bodyAt(uRoot, vToT(rB.y));
        // rest direction of the sleeve in the photo, mapped into the body frame
        const restImg = V.sub(V.lerp(tT, tB, 0.5), V.lerp(rT, rB, 0.5));
        const restLocal = { x: restImg.x * IW, y: restImg.y * IH };
        const restAngle = Math.atan2(restLocal.y, restLocal.x) + Math.atan2(across.y, across.x);
        // follow the upper arm; assume relaxed arms when the elbow isn't visible
        const shoulder = side < 0 ? A : B;
        const elbow = side < 0 ? pose.EA : pose.EB;
        const armAngle = elbow ? Math.atan2(elbow.y - shoulder.y, elbow.x - shoulder.x) : Math.atan2(down.y, down.x) + side * -0.25;
        let delta = angleDiff(armAngle, restAngle) * 0.85;
        delta = clamp(delta, -1.25, 1.25);
        const rootMid = V.lerp(dRootTop, dRootBot, 0.5);
        const map = (p, t) => {
          // point along the sleeve: rotate its offset from the root around the root
          const rootPt = V.lerp(dRootTop, dRootBot, (p.w));
          const imgVec = V.sub(V.lerp(tT, tB, p.w), V.lerp(rT, rB, p.w));
          const local = V.rot({ x: imgVec.x * IW * k, y: imgVec.y * IH * k }, Math.atan2(across.y, across.x) + delta);
          return V.add(rootPt, V.mul(local, t));
        };
        const sCols = 3; const sRows = 2;
        for (let i = 0; i < sCols; i++) {
          for (let j = 0; j < sRows; j++) {
            const t0 = i / sCols; const t1 = (i + 1) / sCols;
            const w0 = j / sRows; const w1 = (j + 1) / sRows;
            const srcPt = (t, w) => {
              const a = V.lerp(rT, rB, w); const b = V.lerp(tT, tB, w);
              const q = V.lerp(a, b, t);
              return px(q.x, q.y);
            };
            const dstPt = (t, w) => map({ w }, t);
            const s00 = srcPt(t0, w0); const s10 = srcPt(t1, w0); const s01 = srcPt(t0, w1); const s11 = srcPt(t1, w1);
            const d00 = dstPt(t0, w0); const d10 = dstPt(t1, w0); const d01 = dstPt(t0, w1); const d11 = dstPt(t1, w1);
            tri(s00, s10, s11, d00, d10, d11);
            tri(s00, s11, s01, d00, d11, d01);
          }
        }
        void rootMid;
      });

      for (let i = 0; i < cols; i++) {
        for (let j = 0; j < rows; j++) {
          const u0 = i / cols; const u1 = (i + 1) / cols;
          const v0 = j / rows; const v1 = (j + 1) / rows;
          const su = (u) => TEE.bodyL + u * (TEE.bodyR - TEE.bodyL);
          const s00 = px(su(u0), v0); const s10 = px(su(u1), v0); const s01 = px(su(u0), v1); const s11 = px(su(u1), v1);
          const d00 = bodyAt(u0, v0); const d10 = bodyAt(u1, v0); const d01 = bodyAt(u0, v1); const d11 = bodyAt(u1, v1);
          tri(s00, s10, s11, d00, d10, d11);
          tri(s00, s11, s01, d00, d11, d01);
        }
      }
    }

    /* ---------- coaching ---------- */
    coach(src) {
      const now = performance.now();
      if (this.coachAt && now - this.coachAt < 600) return;
      this.coachAt = now;
      const pose = this.pose;
      let msg = '';
      if (this.light !== undefined && this.light < 55) msg = this.s.tooDark;
      else if (pose && pose.span < this.W * 0.16) msg = this.s.closer;
      else if (pose && pose.span > this.W * 0.52) msg = this.s.farther;
      else if (pose && !pose.hips) msg = this.s.showWaist;
      else if (pose && (pose.yaw > 0.9 || (pose.nose && Math.abs(pose.nose.x - (pose.A.x + pose.B.x) / 2) > pose.span * 0.35))) msg = this.s.faceCamera;
      if (msg !== this.coachMsg) {
        this.coachMsg = msg;
        this.status(msg);
      }
      // brightness check every couple of seconds
      if (src && (!this.lightAt || now - this.lightAt > 2000)) {
        this.lightAt = now;
        if (!this.probe) this.probe = makeCanvas(16, 16);
        const p = this.probe.getContext('2d', { willReadFrequently: true });
        try {
          p.drawImage(src.el, 0, 0, 16, 16);
          const d = p.getImageData(0, 0, 16, 16).data;
          let sum = 0;
          for (let i = 0; i < d.length; i += 4) sum += d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114;
          this.light = sum / 256;
        } catch (e) { /* ignore */ }
      }
    }

    placeManual() {
      const img = this.teeImg;
      const aspect = img ? img.naturalHeight / img.naturalWidth : 1.18;
      const w = this.W * (parseInt(this.el.scale.value, 10) / 100);
      const prev = this.tee;
      this.tee = { cx: prev ? prev.cx : this.W / 2, top: prev ? prev.top : this.H * 0.28, w, h: w * aspect, angle: 0 };
    }

    bindDrag() {
      let start = null;
      const c = this.el.canvas;
      c.addEventListener('pointerdown', (e) => {
        if (!this.tee) return;
        this.manual = true;
        this.el.manual.hidden = false;
        if (this.landmarker) this.el.auto.hidden = false;
        start = { x: e.clientX, y: e.clientY, cx: this.tee.cx, top: this.tee.top };
        c.setPointerCapture(e.pointerId);
      });
      c.addEventListener('pointermove', (e) => {
        if (!start) return;
        this.tee.cx = start.cx + (e.clientX - start.x) * this.dpr;
        this.tee.top = start.top + (e.clientY - start.y) * this.dpr;
      });
      const end = () => { start = null; };
      c.addEventListener('pointerup', end);
      c.addEventListener('pointercancel', end);
    }

    /* ---------- render loop ---------- */
    loop() {
      if (!this.running) return;
      const src = this.source();
      if (src && this.landmarker && !this.photo && !this.manual) {
        let res = null;
        try { res = this.landmarker.detectForVideo(src.el, performance.now()); } catch (e) { res = null; }
        const now = performance.now();
        if (this.applyPose(res)) {
          this.lostSince = 0;
          this.el.guide.hidden = true;
          if (this.el.status.textContent === this.s.findBody) this.status('');
          if (this.measuring) this.sampleMeasure(); else this.coach(src);
        } else {
          if (this.measuring) this.sampleMeasure();
          this.coachMsg = null;
          if (!this.lostSince) this.lostSince = now;
          const lostFor = now - this.lostSince;
          if (lostFor > 1200 && this.el.manual.hidden) { this.el.guide.hidden = false; this.status(this.s.findBody); }
          if (lostFor > 5000 && this.el.manual.hidden) this.enableManual(this.s.noBody);
        }
      }
      if (src && this.segmenter && this.realistic && !this.photo) {
        this.segFrame = (this.segFrame || 0) + 1;
        if (this.segFrame % 2 === 0) this.segment(src);
      }
      this.draw(src);
      this.raf = requestAnimationFrame(() => this.loop());
    }

    draw(src, ctx = this.ctx) {
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalCompositeOperation = 'source-over';
      ctx.globalAlpha = 1;
      ctx.fillStyle = '#0E0E0E';
      ctx.fillRect(0, 0, this.W, this.H);
      if (src) this.drawSourceTo(ctx, src.el, src);
      const haveTee = this.tee && this.teeImg && src;
      const real = haveTee && this.realistic && this.maskReady;
      if (haveTee) this.renderTee();

      // 1. recolour the shopper's own clothes into the tee colour, keeping their folds and light
      if (real) {
        const r = this.buffer('bufRecolour');
        r.fillStyle = this.teeImg.dataset.base || '#808080';
        r.fillRect(0, 0, this.W, this.H);
        const g = this.buffer('bufGrey');
        this.greyTo(g, src);
        r.globalCompositeOperation = 'soft-light';
        r.drawImage(this.bufGrey, 0, 0);
        r.globalCompositeOperation = 'destination-in';
        this.drawSourceTo(r, this.maskClothes, src);
        // only the upper body around the tee: never recolour trousers or bags
        const t = this.tee;
        r.save();
        r.translate(t.cx, t.top + t.h * SHOULDER_LINE);
        r.rotate(t.angle);
        r.fillStyle = '#000';
        r.fillRect(-t.w * 0.56, -t.h * SHOULDER_LINE - t.h * 0.04, t.w * 1.12, t.h * 1.1);
        r.restore();
        ctx.drawImage(this.bufRecolour, 0, 0);
      }

      if (haveTee) {
        // 2. the product, with a soft contact shadow
        ctx.save();
        if (!real) {
          ctx.shadowColor = 'rgba(0,0,0,.35)';
          ctx.shadowBlur = 18 * this.dpr;
          ctx.shadowOffsetY = 6 * this.dpr;
        }
        this.drawTeeTo(ctx);
        ctx.restore();

        // 3. carry the real body's light and folds onto the product
        if (real) {
          const s = this.buffer('bufShade');
          this.greyTo(s, src);
          s.globalCompositeOperation = 'destination-in';
          this.drawTeeTo(s);
          ctx.save();
          ctx.globalCompositeOperation = 'soft-light';
          ctx.globalAlpha = 0.6;
          ctx.drawImage(this.bufShade, 0, 0);
          ctx.restore();
        }

        // 4. hands and forearms stay in front of the shirt
        if (real && this.lm && !this.manual) {
          const k = this.buffer('bufSkin');
          this.drawSourceTo(k, src.el, src);
          k.globalCompositeOperation = 'destination-in';
          this.drawSourceTo(k, this.maskSkin, src);
          k.globalCompositeOperation = 'destination-in';
          k.lineCap = 'round';
          k.lineJoin = 'round';
          k.lineWidth = (this.pose ? this.pose.span : this.tee.w * 0.5) * 0.42;
          k.strokeStyle = '#000';
          const f = this.frame(src);
          const P = (i) => this.toCanvas(this.lm[i], src, f);
          k.beginPath();
          [[13, 15, 19], [14, 16, 20]].forEach(([e, w, hnd]) => {
            if (!this.lm[e] || !this.lm[w]) return;
            const a = P(e); const b = P(w); const c = this.lm[hnd] ? P(hnd) : b;
            k.moveTo(a.x, a.y); k.lineTo(b.x, b.y); k.lineTo(c.x, c.y);
          });
          k.stroke();
          ctx.drawImage(this.bufSkin, 0, 0);
        }
      }
    }

    /* ---------- snapshot ---------- */
    snapshot() {
      const src = this.source();
      if (!src && !this.photo) return;
      this.el.flash.classList.remove('is-on');
      void this.el.flash.offsetWidth;
      this.el.flash.classList.add('is-on');
      const out = document.createElement('canvas');
      out.width = this.W;
      out.height = this.H;
      const ctx = out.getContext('2d');
      this.draw(src, ctx);
      // brand strip
      const pad = 14 * this.dpr;
      ctx.fillStyle = 'rgba(14,14,14,.78)';
      ctx.fillRect(0, this.H - 44 * this.dpr, this.W, 44 * this.dpr);
      ctx.fillStyle = '#F3EFE6';
      ctx.font = `600 ${15 * this.dpr}px Oswald, sans-serif`;
      ctx.textBaseline = 'middle';
      ctx.fillText(`HAIKUFIT · ${this.cfg.title}`, pad, this.H - 22 * this.dpr);
      ctx.fillStyle = '#C8102E';
      ctx.textAlign = 'right';
      ctx.fillText('haikufit.in', this.W - pad, this.H - 22 * this.dpr);
      const done = (blob) => {
        if (!blob) { this.status(this.s.error); return; }
        if (this.shotUrl) URL.revokeObjectURL(this.shotUrl);
        this.shotUrl = URL.createObjectURL(blob);
        this.el.shotImg.src = this.shotUrl;
        this.el.save.href = this.shotUrl;
        const file = new File([blob], 'haikufit-try-on.jpg', { type: 'image/jpeg' });
        const canShare = navigator.canShare && navigator.canShare({ files: [file] });
        this.el.share.hidden = !canShare;
        this.el.share.onclick = () => navigator.share({ files: [file], title: 'HAIKUFIT', text: this.s.shareText }).catch(() => {});
        this.el.shot.hidden = false;
      };
      try { out.toBlob(done, 'image/jpeg', 0.9); } catch (e) { this.status(this.s.error); }
    }
  }

  const init = () => document.querySelectorAll('[data-tryon]').forEach((root) => {
    if (root.dataset.tryonReady) return;
    root.dataset.tryonReady = 'true';
    root.tryOn = new TryOn(root);
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('shopify:section:load', init);
})();
