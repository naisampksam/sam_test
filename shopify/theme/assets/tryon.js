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

  function loadLandmarker(cfg) {
    if (landmarkerPromise) return landmarkerPromise;
    const base = cfg.visionUrl.replace(/\/$/, '');
    landmarkerPromise = import(/* webpackIgnore: true */ `${base}/vision_bundle.mjs`).then(async (vision) => {
      const files = await vision.FilesetResolver.forVisionTasks(`${base}/wasm`);
      const make = (delegate) => vision.PoseLandmarker.createFromOptions(files, {
        baseOptions: { modelAssetPath: cfg.modelUrl, delegate },
        runningMode: 'VIDEO',
        numPoses: 1,
        minPoseDetectionConfidence: 0.5,
        minTrackingConfidence: 0.5,
      });
      try { return await make('GPU'); } catch (e) { return make('CPU'); }
    });
    landmarkerPromise.catch(() => { landmarkerPromise = null; });
    return landmarkerPromise;
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
      root.querySelector('[data-tryon-open]').addEventListener('click', () => this.open());
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
        </div>
        <div class="tryon__panel">
          <div class="tryon__row tryon__colors" data-colors></div>
          <div class="tryon__row tryon__toggles">
            <div class="tryon__seg" data-sides role="group" aria-label="${esc(s.view)}"></div>
            <div class="tryon__seg tryon__sizes" data-sizes role="group" aria-label="${esc(s.size)}"></div>
          </div>
          <div class="tryon__row tryon__manual" data-manual hidden>
            <label>${esc(s.adjust)} <input type="range" min="20" max="95" value="55" data-scale></label>
            <button type="button" class="tryon__link" data-auto hidden>${esc(s.autoFit)}</button>
          </div>
          <div class="tryon__actions">
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
      };
      this.ctx = this.el.canvas.getContext('2d');

      $('[data-close]').addEventListener('click', () => d.close());
      d.addEventListener('close', () => this.stop());
      d.addEventListener('cancel', () => this.stop());
      $('[data-snap]').addEventListener('click', () => this.snapshot());
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
      this.el.sizes.innerHTML = this.cfg.sizes.map((z) => `<button type="button" data-size="${esc(z)}" aria-pressed="${z === this.size}" class="${z === this.size ? 'is-active' : ''}">${esc(z)}</button>`).join('');
      this.el.colors.querySelectorAll('[data-color]').forEach((b) => b.addEventListener('click', () => { this.color = b.dataset.color; this.renderControls(); this.loadTee(); }));
      this.el.sides.querySelectorAll('[data-side]').forEach((b) => b.addEventListener('click', () => { this.side = b.dataset.side; this.renderControls(); this.loadTee(); }));
      this.el.sizes.querySelectorAll('[data-size]').forEach((b) => b.addEventListener('click', () => { this.size = b.dataset.size; this.renderControls(); }));
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
    async open() {
      if (!this.d) this.build();
      this.el.shot.hidden = true;
      this.d.showModal();
      this.running = true;
      this.photo = null;
      this.resize();
      this.loadTee();
      this.status(this.s.starting);
      this.startCamera();
      if (this.cfg.modelUrl) {
        loadLandmarker(this.cfg).then((lm) => { this.landmarker = lm; this.statusTracking(); }).catch(() => this.enableManual(this.s.noTracking));
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
        this.d.classList.remove('is-no-camera');
        this.tee = null;
        if (this.landmarker) {
          try {
            await this.landmarker.setOptions({ runningMode: 'IMAGE' });
            const res = this.landmarker.detect(img);
            await this.landmarker.setOptions({ runningMode: 'VIDEO' });
            if (!this.applyPose(res, true)) this.enableManual(this.s.noBody);
            else this.status('');
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
      img.onload = () => { this.images[url] = img; if (this.teeUrl() === url) this.teeImg = img; };
      img.src = url;
    }

    /* ---------- geometry ---------- */
    resize() {
      const r = this.el.stage.getBoundingClientRect();
      this.dpr = Math.min(window.devicePixelRatio || 1, 2);
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
      const ok = (i) => lm[i] && (lm[i].visibility === undefined || lm[i].visibility > 0.5);
      if (!ok(11) || !ok(12)) return false;
      const f = this.frame(src);
      let a = this.toCanvas(lm[11], src, f);
      let b = this.toCanvas(lm[12], src, f);
      if (a.x > b.x) [a, b] = [b, a];
      const span = Math.hypot(b.x - a.x, b.y - a.y);
      if (span < this.W * 0.06) return false;
      const img = this.teeImg;
      const aspect = img ? img.naturalHeight / img.naturalWidth : 1.18;
      const w = (span / SHOULDER_SPAN) * (SIZE_SCALE[this.size] || 1);
      let h = w * aspect;
      const mid = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
      if (ok(23) && ok(24)) {
        const h1 = this.toCanvas(lm[23], src, f);
        const h2 = this.toCanvas(lm[24], src, f);
        const torso = Math.hypot((h1.x + h2.x) / 2 - mid.x, (h1.y + h2.y) / 2 - mid.y);
        const wanted = (torso * 1.3) / (1 - SHOULDER_LINE);
        h = clamp(wanted, h * 0.85, h * 1.25);
      }
      const target = { cx: mid.x, top: mid.y - h * SHOULDER_LINE, w, h, angle: Math.atan2(b.y - a.y, b.x - a.x) };
      if (!this.tee || instant) this.tee = target;
      else {
        const k = 0.45;
        this.tee = { cx: lerp(this.tee.cx, target.cx, k), top: lerp(this.tee.top, target.top, k), w: lerp(this.tee.w, target.w, k), h: lerp(this.tee.h, target.h, k), angle: lerp(this.tee.angle, target.angle, k) };
      }
      return true;
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
        } else {
          if (!this.lostSince) this.lostSince = now;
          const lostFor = now - this.lostSince;
          if (lostFor > 1200 && this.el.manual.hidden) { this.el.guide.hidden = false; this.status(this.s.findBody); }
          if (lostFor > 5000 && this.el.manual.hidden) this.enableManual(this.s.noBody);
        }
      }
      this.draw(src);
      this.raf = requestAnimationFrame(() => this.loop());
    }

    draw(src, ctx = this.ctx) {
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.fillStyle = '#0E0E0E';
      ctx.fillRect(0, 0, this.W, this.H);
      if (src) {
        const f = this.frame(src);
        ctx.save();
        if (src.mirror) { ctx.translate(this.W, 0); ctx.scale(-1, 1); }
        ctx.drawImage(src.el, f.ox, f.oy, src.w * f.scale, src.h * f.scale);
        ctx.restore();
      }
      if (this.tee && this.teeImg && (src || this.photo)) {
        const t = this.tee;
        ctx.save();
        ctx.translate(t.cx, t.top + t.h * SHOULDER_LINE);
        ctx.rotate(t.angle);
        ctx.shadowColor = 'rgba(0,0,0,.35)';
        ctx.shadowBlur = 18 * this.dpr;
        ctx.shadowOffsetY = 6 * this.dpr;
        ctx.drawImage(this.teeImg, -t.w / 2, -t.h * SHOULDER_LINE, t.w, t.h);
        ctx.restore();
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
