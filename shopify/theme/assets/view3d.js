/* HAIKUFIT · 360° 3D tee viewer
 * Builds a soft 3D tee from the product's front and back cutouts (curved front and back
 * panels that meet at the side seams), lit like a studio shot. Drag to spin, pinch or
 * scroll to zoom, switch colours. three.js is loaded only when the viewer opens.
 */
(() => {
  'use strict';

  const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
  let threePromise = null;
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function loadThree(url) {
    if (window.THREE) return Promise.resolve(window.THREE);
    if (!threePromise) {
      threePromise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = url || THREE_URL;
        s.onload = () => resolve(window.THREE);
        s.onerror = reject;
        document.head.appendChild(s);
      });
      threePromise.catch(() => { threePromise = null; });
    }
    return threePromise;
  }

  function loadImage(url) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      if (new URL(url, location.href).origin !== location.origin) img.crossOrigin = 'anonymous';
      img.onload = () => resolve(img);
      img.onerror = reject;
      img.src = url;
    });
  }

  // a plain panel in the fabric colour, shaped like the given cutout (used when a side has no photo)
  function plainFrom(img) {
    const c = document.createElement('canvas');
    c.width = img.naturalWidth; c.height = img.naturalHeight;
    const x = c.getContext('2d');
    x.translate(c.width, 0); x.scale(-1, 1);
    x.drawImage(img, 0, 0);
    const probe = document.createElement('canvas');
    probe.width = 40; probe.height = 48;
    const p = probe.getContext('2d');
    p.drawImage(img, 0, 0, 40, 48);
    const d = p.getImageData(3, 10, 4, 4).data;
    x.setTransform(1, 0, 0, 1, 0, 0);
    x.globalCompositeOperation = 'source-atop';
    x.fillStyle = `rgb(${d[0]},${d[1]},${d[2]})`;
    x.fillRect(0, 0, c.width, c.height);
    return c;
  }

  class Viewer3D {
    constructor(root) {
      this.root = root;
      this.cfg = JSON.parse(root.querySelector('[data-view3d-config]').textContent);
      this.s = this.cfg.strings;
      Object.values(this.cfg.map).forEach((sides) => Object.keys(sides).forEach((k) => { if (!sides[k]) delete sides[k]; }));
      this.colors = Object.keys(this.cfg.map);
      this.color = this.colors[0];
      const btn = root.querySelector('[data-view3d-open]');
      if (btn) btn.addEventListener('click', () => this.open());
    }

    url(file) { return /^(https?:)?\/\//.test(file) ? file : this.cfg.assetBase + file; }

    build() {
      const s = this.s;
      const d = document.createElement('dialog');
      d.className = 'tryon view3d';
      d.setAttribute('aria-label', s.view3d);
      d.innerHTML = `
        <div class="tryon__stage view3d__stage" data-stage>
          <div class="view3d__floor" aria-hidden="true"></div>
          <p class="tryon__status" data-status>${esc(s.loading3d)}</p>
          <button type="button" class="tryon__icon tryon__close" data-close aria-label="${esc(s.close)}">×</button>
          <span class="tryon__badge">360° 3D</span>
          <p class="view3d__hint" data-hint>${esc(s.hint3d)}</p>
        </div>
        <div class="tryon__panel">
          <div class="tryon__row tryon__colors" data-colors></div>
          <div class="tryon__actions">
            <button type="button" class="tryon__snap" data-spin aria-pressed="true"><span aria-hidden="true">⟳</span> ${esc(s.autoSpin)}</button>
            <button type="button" class="button tryon__add" data-find-from3d hidden>${esc(s.findSize)}</button>
          </div>
        </div>`;
      document.body.appendChild(d);
      this.d = d;
      this.stage = d.querySelector('[data-stage]');
      this.statusEl = d.querySelector('[data-status]');
      d.querySelector('[data-close]').addEventListener('click', () => d.close());
      d.addEventListener('close', () => this.stop());
      const spin = d.querySelector('[data-spin]');
      spin.addEventListener('click', () => {
        this.autoSpin = !this.autoSpin;
        spin.setAttribute('aria-pressed', this.autoSpin ? 'true' : 'false');
      });
      const finder = document.querySelector('[data-size-finder-open]');
      const findBtn = d.querySelector('[data-find-from3d]');
      if (finder) {
        findBtn.hidden = false;
        findBtn.addEventListener('click', () => { d.close(); finder.click(); });
      }
      this.renderColors();
    }

    renderColors() {
      const box = this.d.querySelector('[data-colors]');
      box.innerHTML = this.colors.map((c) => {
        const sw = (window.theme && window.theme.swatches && window.theme.swatches[c]) || c.replace(/\s+/g, '');
        return `<button type="button" class="tryon__chip${c === this.color ? ' is-active' : ''}" data-color="${esc(c)}" aria-pressed="${c === this.color}"><span style="background:${esc(sw)}"></span>${esc(c)}</button>`;
      }).join('');
      box.querySelectorAll('[data-color]').forEach((b) => b.addEventListener('click', () => {
        this.color = b.dataset.color;
        this.renderColors();
        this.loadTextures();
      }));
    }

    async open() {
      if (!this.d) this.build();
      this.d.showModal();
      this.running = true;
      this.autoSpin = true;
      this.statusEl.hidden = false;
      try {
        const THREE = await loadThree(this.cfg.threeUrl);
        if (!this.renderer) this.setup(THREE);
        this.resize();
        await this.loadTextures();
        this.statusEl.hidden = true;
      } catch (e) {
        this.statusEl.textContent = this.s.error3d;
        return;
      }
      cancelAnimationFrame(this.raf);
      this.last = performance.now();
      this.raf = requestAnimationFrame((t) => this.loop(t));
    }

    stop() {
      this.running = false;
      cancelAnimationFrame(this.raf);
    }

    setup(THREE) {
      this.THREE = THREE;
      const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
      renderer.outputEncoding = THREE.sRGBEncoding;
      renderer.domElement.className = 'view3d__canvas';
      this.stage.insertBefore(renderer.domElement, this.stage.firstChild);
      this.renderer = renderer;

      const scene = new THREE.Scene();
      this.scene = scene;
      this.camera = new THREE.PerspectiveCamera(30, 1, 0.1, 50);
      this.camera.position.set(0, 0.05, 4.2);

      scene.add(new THREE.HemisphereLight(0xfff8ee, 0x3a3532, 0.95));
      const key = new THREE.DirectionalLight(0xffffff, 0.75);
      key.position.set(2.2, 3, 4);
      scene.add(key);
      const rim = new THREE.DirectionalLight(0xffe2c8, 0.5);
      rim.position.set(-3, 1.5, -3);
      scene.add(rim);

      this.group = new THREE.Group();
      scene.add(this.group);
      this.rotY = -0.35;
      this.velocity = 0;
      this.zoom = 4.2;
      this.bindPointer();
      if (!this.onResize) { this.onResize = () => this.resize(); window.addEventListener('resize', this.onResize); }
    }

    // curved panel: deepest at the chest centre, meeting the other panel at the side seams
    panel(width, height, depth, sleeve) {
      const THREE = this.THREE;
      const geo = new THREE.PlaneGeometry(width, height, 64, 64);
      const pos = geo.attributes.position;
      for (let i = 0; i < pos.count; i++) {
        const x = pos.getX(i);
        const y = pos.getY(i);
        const nx = x / (width / 2);                  // -1 .. 1
        const ny = (y + height / 2) / height;        // 0 bottom .. 1 top
        const body = Math.max(0, 1 - Math.pow(Math.abs(nx) / sleeve, 2));
        let z = depth * Math.sqrt(body);
        // sleeves: thin tubes that taper to the cuff
        if (Math.abs(nx) > sleeve) z = depth * 0.12 * (1 - (Math.abs(nx) - sleeve) / (1 - sleeve));
        // shoulders round over, hem relaxes slightly
        z *= 0.82 + 0.18 * Math.sin(Math.PI * Math.min(1, ny * 1.15));
        // gentle fabric ripple
        z += 0.008 * Math.sin(nx * 9 + ny * 5) * Math.sqrt(body);
        pos.setZ(i, z);
      }
      geo.computeVertexNormals();
      return geo;
    }

    async loadTextures() {
      const THREE = this.THREE;
      const sides = this.cfg.map[this.color] || {};
      const token = Symbol('load');
      this.loading = token;
      const [frontImg, backImg] = await Promise.all([
        sides.front ? loadImage(this.url(sides.front)) : null,
        sides.back ? loadImage(this.url(sides.back)) : null,
      ]);
      if (this.loading !== token) return;
      const front = frontImg || plainFrom(backImg);
      const back = backImg || plainFrom(frontImg);
      const tex = (src) => {
        const t = new THREE.Texture(src);
        t.encoding = THREE.sRGBEncoding;
        t.anisotropy = 4;
        t.needsUpdate = true;
        return t;
      };
      while (this.group.children.length) {
        const m = this.group.children.pop();
        m.geometry.dispose();
        if (m.material.map) m.material.map.dispose();
        m.material.dispose();
      }
      const W = 1.7;
      const make = (src, flip) => {
        const w = src.naturalWidth || src.width;
        const h = src.naturalHeight || src.height;
        const H = W * (h / w);
        const mat = new THREE.MeshStandardMaterial({ map: tex(src), alphaTest: 0.55, roughness: 0.92, metalness: 0, side: THREE.FrontSide });
        const mesh = new THREE.Mesh(this.panel(W, H, 0.34, 0.6), mat);
        mesh.position.y = (1.95 - H) / 2;   // align the shoulders of both panels
        if (flip) mesh.rotation.y = Math.PI;
        return mesh;
      };
      this.group.add(make(front, false));
      this.group.add(make(back, true));
      // inside of the tee, seen through the neck and hem
      const inner = new THREE.MeshStandardMaterial({ color: 0x1b1a1f, roughness: 1, side: THREE.BackSide, alphaTest: 0.45 });
      this.group.children.slice(0, 2).forEach((m) => {
        const innerMesh = new THREE.Mesh(m.geometry, inner.clone());
        innerMesh.material.map = m.material.map;
        innerMesh.material.color.set(0x4a4650);
        innerMesh.position.copy(m.position);
        innerMesh.rotation.copy(m.rotation);
        this.group.add(innerMesh);
      });
    }

    bindPointer() {
      const el = this.renderer.domElement;
      let last = null;
      let pinch = null;
      const pts = new Map();
      el.addEventListener('pointerdown', (e) => {
        pts.set(e.pointerId, e);
        this.dragging = true;
        last = { x: e.clientX, t: performance.now() };
        this.autoSpin = false;
        this.d.querySelector('[data-spin]').setAttribute('aria-pressed', 'false');
        this.d.querySelector('[data-hint]').classList.add('is-hidden');
        el.setPointerCapture(e.pointerId);
      });
      el.addEventListener('pointermove', (e) => {
        if (!pts.has(e.pointerId)) return;
        pts.set(e.pointerId, e);
        if (pts.size === 2) {
          const [a, b] = [...pts.values()];
          const dist = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
          if (pinch) this.zoom = Math.min(6, Math.max(2.6, this.zoom * (pinch / dist)));
          pinch = dist;
          return;
        }
        if (!last) return;
        const now = performance.now();
        const dx = e.clientX - last.x;
        this.rotY += dx * 0.012;
        this.velocity = (dx * 0.012) / Math.max(1, now - last.t) * 16;
        last = { x: e.clientX, t: now };
      });
      const up = (e) => { pts.delete(e.pointerId); pinch = null; if (!pts.size) { last = null; this.dragging = false; } };
      el.addEventListener('pointerup', up);
      el.addEventListener('pointercancel', up);
      el.addEventListener('wheel', (e) => { e.preventDefault(); this.zoom = Math.min(6, Math.max(2.6, this.zoom + e.deltaY * 0.003)); }, { passive: false });
    }

    resize() {
      if (!this.renderer) return;
      const r = this.stage.getBoundingClientRect();
      this.renderer.setSize(r.width, r.height, false);
      this.camera.aspect = r.width / Math.max(1, r.height);
      this.camera.updateProjectionMatrix();
    }

    loop(t) {
      if (!this.running) return;
      const dt = Math.min(0.05, (t - this.last) / 1000);
      this.last = t;
      const side = Math.pow(Math.sin(this.rotY), 2);          // 0 facing front/back, 1 side-on
      if (this.autoSpin) {
        // linger on the front and back, sweep quickly past the sides
        this.rotY += dt * (0.35 + 2.6 * side);
      } else if (Math.abs(this.velocity) > 0.0005) {
        this.rotY += this.velocity;
        this.velocity *= 0.94;
      } else if (!this.dragging && side > 0.04) {
        // settle on the nearest face
        const target = Math.round(this.rotY / Math.PI) * Math.PI;
        this.rotY += (target - this.rotY) * Math.min(1, dt * 6);
      }
      this.group.rotation.y = this.rotY;
      this.group.position.y = Math.sin(t / 900) * 0.02;
      this.camera.position.z += (this.zoom - this.camera.position.z) * 0.15;
      this.renderer.render(this.scene, this.camera);
      this.raf = requestAnimationFrame((n) => this.loop(n));
    }
  }

  const init = () => document.querySelectorAll('[data-view3d-root]').forEach((root) => {
    if (root.dataset.view3dReady) return;
    root.dataset.view3dReady = 'true';
    root.viewer3d = new Viewer3D(root);
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('shopify:section:load', init);
})();
