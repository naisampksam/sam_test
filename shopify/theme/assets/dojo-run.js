/* HAIKUFIT · Dojo Run
 * An endless-runner (in the spirit of the Chrome offline game) with a samurai hero.
 * Reach score tiers to unlock discount codes. Personal best is kept in localStorage.
 */
(() => {
  'use strict';

  const BASE_H = 300;
  const MIN_W = 560;
  const GROUND = 46;           // ground strip height (logical px)
  const GRAVITY = 2750;
  const JUMP_V = 880;
  const START_SPEED = 400;
  const ACCEL = 8.5;
  const MAX_SPEED = 1180;
  const COLORS = {
    ink: '#0E0E0E', shu: '#C8102E', washi: '#F3EFE6', steel: '#E6E8EE', gold: '#B8893B', petal: '#F2B6C3'
  };
  // sky palettes cycle with score: dawn -> day -> dusk -> night
  const SKIES = [
    { top: '#F6E7D6', bottom: '#F3EFE6', sun: '#C8102E', far: 'rgba(14,14,14,.16)', mid: 'rgba(14,14,14,.28)', near: 'rgba(14,14,14,.08)', night: 0 },
    { top: '#EFE6D6', bottom: '#F7F3EA', sun: '#C8102E', far: 'rgba(14,14,14,.14)', mid: 'rgba(14,14,14,.26)', near: 'rgba(14,14,14,.07)', night: 0 },
    { top: '#E9805E', bottom: '#F5C9A0', sun: '#A50D25', far: 'rgba(60,10,20,.32)', mid: 'rgba(40,8,14,.5)', near: 'rgba(40,8,14,.18)', night: 0.25 },
    { top: '#0D0E1A', bottom: '#2B2342', sun: '#F3EFE6', far: 'rgba(0,0,0,.45)', mid: 'rgba(0,0,0,.6)', near: 'rgba(0,0,0,.3)', night: 1 }
  ];
  const SKY_STEP = 260;

  const rand = (a, b) => a + Math.random() * (b - a);
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const lerp = (a, b, t) => a + (b - a) * t;
  const pad = (n) => String(Math.floor(n)).padStart(5, '0');
  const store = {
    get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* storage blocked */ } }
  };
  const hexToRgb = (hex) => {
    const n = parseInt(hex.slice(1), 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  };
  const mixHex = (a, b, t) => {
    const x = hexToRgb(a); const y = hexToRgb(b);
    return `rgb(${Math.round(lerp(x[0], y[0], t))},${Math.round(lerp(x[1], y[1], t))},${Math.round(lerp(x[2], y[2], t))})`;
  };
  const mixRgba = (a, b, t) => {
    const p = (s) => s.match(/[\d.]+/g).map(Number);
    const x = p(a); const y = p(b);
    return `rgba(${Math.round(lerp(x[0], y[0], t))},${Math.round(lerp(x[1], y[1], t))},${Math.round(lerp(x[2], y[2], t))},${lerp(x[3], y[3], t).toFixed(3)})`;
  };

  /* --------------------------------------------------------------------
   * Sound: taiko drums, sword swishes, steel clangs, a war horn and a gong,
   * all synthesised with Web Audio (no files to download).
   * ------------------------------------------------------------------ */
  const Sound = {
    ctx: null,
    out: null,
    noiseBuf: null,
    on: true,

    ensure() {
      if (!this.on) return null;
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      if (!this.ctx) {
        try {
          this.ctx = new AC();
          const comp = this.ctx.createDynamicsCompressor();
          comp.threshold.value = -14; comp.ratio.value = 4;
          this.out = this.ctx.createGain();
          this.out.gain.value = 0.6;
          this.out.connect(comp); comp.connect(this.ctx.destination);
          const len = this.ctx.sampleRate;
          this.noiseBuf = this.ctx.createBuffer(1, len, this.ctx.sampleRate);
          const d = this.noiseBuf.getChannelData(0);
          for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
        } catch (e) { this.ctx = null; return null; }
      }
      if (this.ctx.state === 'suspended') this.ctx.resume().catch(() => {});
      return this.ctx;
    },

    setOn(on) {
      this.on = on;
      if (this.out) this.out.gain.setTargetAtTime(on ? 0.6 : 0, this.ctx.currentTime, 0.02);
      if (on) this.ensure();
    },

    env(g, t, peak, attack, decay) {
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(peak, t + attack);
      g.gain.exponentialRampToValueAtTime(0.0001, t + attack + decay);
    },

    tone(type, freq, t, peak, attack, decay, endFreq, dest) {
      const c = this.ctx;
      const o = c.createOscillator(); const g = c.createGain();
      o.type = type;
      o.frequency.setValueAtTime(freq, t);
      if (endFreq) o.frequency.exponentialRampToValueAtTime(endFreq, t + attack + decay * 0.6);
      this.env(g, t, peak, attack, decay);
      o.connect(g); g.connect(dest || this.out);
      o.start(t); o.stop(t + attack + decay + 0.05);
      return o;
    },

    noise(t, peak, attack, decay, filter, f1, f2, q) {
      const c = this.ctx;
      const src = c.createBufferSource(); src.buffer = this.noiseBuf;
      const fl = c.createBiquadFilter(); fl.type = filter; fl.Q.value = q || 1;
      fl.frequency.setValueAtTime(f1, t);
      if (f2) fl.frequency.exponentialRampToValueAtTime(f2, t + attack + decay);
      const g = c.createGain();
      this.env(g, t, peak, attack, decay);
      src.connect(fl); fl.connect(g); g.connect(this.out);
      src.start(t, Math.random() * 0.5); src.stop(t + attack + decay + 0.05);
    },

    // big drum: pitch-dropping body + stick attack
    don(t, vol, freq) {
      const f = freq || 72;
      this.tone('sine', f * 2.3, t, vol, 0.004, 0.55, f);
      this.tone('triangle', f * 1.5, t, vol * 0.35, 0.003, 0.18, f * 0.9);
      this.noise(t, vol * 0.3, 0.002, 0.06, 'lowpass', 1400);
    },
    // rim hit
    ka(t, vol) {
      this.noise(t, vol, 0.001, 0.045, 'bandpass', 2600, 0, 3);
      this.tone('square', 820, t, vol * 0.15, 0.001, 0.03);
    },
    clang(t, vol, base) {
      const b = base || 520;
      [1, 2.76, 5.4, 8.93].forEach((m, i) => this.tone(i ? 'sine' : 'triangle', b * m, t, vol / (i + 1.2), 0.002, 0.7 - i * 0.12));
      this.noise(t, vol * 0.6, 0.001, 0.08, 'highpass', 3000);
    },
    pluck(t, freq, vol) {
      const c = this.ctx;
      const o = c.createOscillator(); const fl = c.createBiquadFilter(); const g = c.createGain();
      o.type = 'sawtooth'; o.frequency.value = freq;
      fl.type = 'lowpass'; fl.frequency.setValueAtTime(freq * 6, t); fl.frequency.exponentialRampToValueAtTime(freq * 1.2, t + 0.4);
      this.env(g, t, vol, 0.003, 0.9);
      o.connect(fl); fl.connect(g); g.connect(this.out);
      o.start(t); o.stop(t + 1);
    },

    play(name) {
      const c = this.ensure();
      if (!c) return;
      const t = c.currentTime + 0.01;
      // Miyako-bushi scale (E F A B C E) for the koto phrases
      const koto = [330, 349, 440, 494, 523, 659, 698, 880, 988];
      switch (name) {
        case 'start': {
          // conch-shell war horn over three drum hits
          const o = c.createOscillator(); const fl = c.createBiquadFilter(); const g = c.createGain();
          const lfo = c.createOscillator(); const lg = c.createGain();
          o.type = 'sawtooth';
          o.frequency.setValueAtTime(128, t); o.frequency.linearRampToValueAtTime(147, t + 0.25);
          o.frequency.setValueAtTime(147, t + 0.8); o.frequency.linearRampToValueAtTime(132, t + 1.1);
          lfo.frequency.value = 5.5; lg.gain.value = 2.5; lfo.connect(lg); lg.connect(o.frequency);
          fl.type = 'lowpass'; fl.frequency.value = 850; fl.Q.value = 2;
          g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.22, t + 0.18);
          g.gain.setValueAtTime(0.22, t + 0.8); g.gain.exponentialRampToValueAtTime(0.0001, t + 1.15);
          o.connect(fl); fl.connect(g); g.connect(this.out);
          o.start(t); lfo.start(t); o.stop(t + 1.2); lfo.stop(t + 1.2);
          this.don(t, 0.7); this.don(t + 0.3, 0.7); this.don(t + 0.6, 0.95, 60);
          break;
        }
        case 'jump':
          this.noise(t, 0.18, 0.02, 0.16, 'bandpass', 420, 1900, 2);
          break;
        case 'land':
          this.tone('sine', 120, t, 0.12, 0.002, 0.08, 60);
          break;
        case 'slash':
          this.noise(t, 0.32, 0.01, 0.17, 'bandpass', 5200, 1100, 4);
          this.tone('sine', 2400, t, 0.03, 0.01, 0.12, 1400);
          break;
        case 'hit':
          this.clang(t, 0.3, 560 + Math.random() * 80);
          break;
        case 'milestone':
          this.ka(t, 0.25); this.ka(t + 0.09, 0.2);
          break;
        case 'unlock':
          // gong + rising koto phrase
          [1, 1.48, 2.03, 2.76, 3.5].forEach((m, i) => this.tone('sine', 98 * m, t, 0.22 / (i + 1), 0.01, 2.4 - i * 0.3));
          this.noise(t, 0.15, 0.005, 0.5, 'lowpass', 900);
          [2, 3, 4, 5, 7].forEach((n, i) => this.pluck(t + 0.12 + i * 0.09, koto[n], 0.16));
          break;
        case 'death':
          this.don(t, 1, 52);
          this.clang(t, 0.22, 300);
          this.noise(t, 0.3, 0.005, 0.7, 'lowpass', 2400, 300);
          [6, 4, 2, 1].forEach((n, i) => this.pluck(t + 0.35 + i * 0.16, koto[n] / 2, 0.12));
          break;
        case 'victory':
          [0, 2, 3, 5, 7, 8].forEach((n, i) => this.pluck(t + i * 0.08, koto[n], 0.15));
          this.don(t, 0.6); this.don(t + 0.48, 0.8, 60);
          break;
        case 'toggle':
          this.ka(t, 0.25); this.don(t + 0.05, 0.4, 90);
          break;
        default:
      }
    }
  };

  /* --------------------------------------------------------------------
   * Scenery: pre-generated silhouettes that tile horizontally
   * ------------------------------------------------------------------ */
  function buildScenery() {
    const far = [];   // mountains incl. Fuji
    for (let i = 0; i < 4; i++) {
      far.push({ x: i * 420 + rand(0, 120), w: rand(260, 420), h: rand(70, 120), fuji: i === 1 });
    }
    const mid = [];   // hills with pagodas / torii / pines
    for (let i = 0; i < 6; i++) {
      mid.push({ x: i * 260 + rand(0, 80), kind: ['pagoda', 'torii', 'pine', 'pine', 'castle', 'torii'][i], s: rand(0.8, 1.15) });
    }
    const near = [];  // bamboo stalks
    for (let i = 0; i < 14; i++) near.push({ x: i * 88 + rand(0, 40), h: rand(120, 240), w: rand(5, 8) });
    const tufts = [];
    for (let i = 0; i < 26; i++) tufts.push({ x: i * 40 + rand(0, 20), k: Math.random() });
    return { far, farW: 1680, mid, midW: 1560, near, nearW: 1232, tufts, tuftW: 1040 };
  }

  /* --------------------------------------------------------------------
   * Game
   * ------------------------------------------------------------------ */
  class DojoRun {
    constructor(root) {
      this.root = root;
      this.stage = root.querySelector('[data-dojo-stage]');
      this.canvas = root.querySelector('canvas');
      this.ctx = this.canvas.getContext('2d');
      const cfgEl = root.querySelector('[data-dojo-config]');
      this.cfg = cfgEl ? JSON.parse(cfgEl.textContent) : {};
      // With a rewards server, each winner gets a unique single-use Shopify code;
      // without one, the fixed codes from the theme settings are used.
      this.api = '';
      try { if (this.cfg.rewardApi) this.api = new URL(this.cfg.rewardApi.trim()).origin; } catch (e) { this.api = ''; } // worker lives at the root
      this.tiers = (this.cfg.tiers || []).filter((t) => t.score > 0 && t.percent > 0 && (this.api || t.code)).sort((a, b) => a.score - b.score);
      this.ui = {
        score: root.querySelector('[data-dojo-score]'),
        best: root.querySelector('[data-dojo-best]'),
        start: root.querySelector('[data-screen="start"]'),
        over: root.querySelector('[data-screen="over"]'),
        pause: root.querySelector('[data-screen="pause"]'),
        toast: root.querySelector('[data-dojo-toast]'),
        tierPills: [...root.querySelectorAll('[data-tier-pill]')]
      };
      this.best = parseInt(store.get('hf_dojo_best'), 10) || 0;
      const pref = store.get('hf_dojo_sound');
      Sound.on = pref === null ? this.cfg.sound !== false : pref === '1';
      this.soundButtons = [...root.querySelectorAll('[data-dojo-sound]')];
      this.renderSound();
      this.scenery = buildScenery();
      this.petals = Array.from({ length: 22 }, () => this.newPetal(true));
      this.particles = [];
      this.floaters = [];
      this.keys = { duck: false, jumpHeld: false };
      this.state = 'ready';
      this.visible = true;
      this.last = 0;
      this.reset();
      this.resize();
      this.bind();
      this.renderBest();
      this.renderTierPills(0);
      this.renderStartMeta();
      requestAnimationFrame((t) => this.frame(t));
    }

    reset() {
      this.speed = START_SPEED;
      this.score = 0;
      this.time = 0;
      this.dist = 0;
      this.obstacles = [];
      this.spawnIn = 1.1;
      this.lastArrival = 0;
      this.pending = null;
      this.shake = 0;
      this.flash = 0;
      this.nextMilestone = 100;
      this.unlockedIndex = -1;
      this.player = { x: 84, y: 0, vy: 0, ground: true, duck: false, run: 0, slash: 0, slashCd: 0, coyote: 0, buffer: 0, dead: false, deadT: 0, rot: 0 };
    }

    /* ---------------- sizing ---------------- */
    resize() {
      const rect = this.canvas.getBoundingClientRect();
      const cssW = Math.max(1, rect.width);
      const cssH = Math.max(1, rect.height);
      this.dpr = Math.min(window.devicePixelRatio || 1, 2);
      this.canvas.width = Math.round(cssW * this.dpr);
      this.canvas.height = Math.round(cssH * this.dpr);
      let scale = cssH / BASE_H;
      if (cssW / scale < MIN_W) scale = cssW / MIN_W;
      this.scale = scale;
      this.W = cssW / scale;
      this.H = cssH / scale;
      this.groundY = this.H - GROUND;
    }

    /* ---------------- input ---------------- */
    bind() {
      const isActive = () => this.state === 'running' || this.root.contains(document.activeElement);
      window.addEventListener('keydown', (e) => {
        if (!isActive() || e.target.closest('input, textarea')) return;
        const k = e.code;
        if (e.target.closest('[data-dojo-sound]') && (k === 'Space' || k === 'Enter')) return;
        if (k === 'KeyM') { this.toggleSound(); return; }
        if (k === 'Space' || k === 'ArrowUp' || k === 'KeyW') {
          e.preventDefault();
          if (!e.repeat) this.pressJump();
        } else if (k === 'ArrowDown' || k === 'KeyS') {
          e.preventDefault();
          this.keys.duck = true;
        } else if (k === 'KeyX' || k === 'KeyJ' || k === 'KeyK') {
          if (!e.repeat) this.pressSlash();
        } else if (k === 'Enter' && this.state !== 'running') {
          e.preventDefault();
          this.start();
        } else if (k === 'KeyP' || k === 'Escape') {
          if (this.state === 'running') this.pause(); else if (this.state === 'paused') this.resume();
        }
      });
      window.addEventListener('keyup', (e) => {
        const k = e.code;
        if (k === 'Space' || k === 'ArrowUp' || k === 'KeyW') this.releaseJump();
        if (k === 'ArrowDown' || k === 'KeyS') this.keys.duck = false;
      });

      // Tap / click on the stage = jump; swipe down = duck
      let touchY = null;
      this.canvas.addEventListener('pointerdown', (e) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        touchY = e.clientY;
        this.stage.focus({ preventScroll: true });
        this.pressJump();
      });
      this.canvas.addEventListener('pointermove', (e) => {
        if (touchY !== null && e.clientY - touchY > 28) { this.keys.duck = true; this.releaseJump(); }
      });
      const endTouch = () => { touchY = null; this.keys.duck = false; this.releaseJump(); };
      this.canvas.addEventListener('pointerup', endTouch);
      this.canvas.addEventListener('pointercancel', endTouch);
      this.canvas.addEventListener('pointerleave', endTouch);
      this.canvas.addEventListener('contextmenu', (e) => e.preventDefault());

      // On-screen controls
      this.root.querySelectorAll('[data-dojo-btn]').forEach((btn) => {
        const action = btn.dataset.dojoBtn;
        btn.addEventListener('pointerdown', (e) => {
          e.preventDefault();
          if (action === 'jump') this.pressJump();
          if (action === 'duck') { this.keys.duck = true; if (this.state !== 'running') this.start(); }
          if (action === 'slash') this.pressSlash();
          btn.classList.add('is-pressed');
        });
        const up = () => {
          if (action === 'jump') this.releaseJump();
          if (action === 'duck') this.keys.duck = false;
          btn.classList.remove('is-pressed');
        };
        btn.addEventListener('pointerup', up);
        btn.addEventListener('pointercancel', up);
        btn.addEventListener('pointerleave', up);
        btn.addEventListener('contextmenu', (e) => e.preventDefault());
      });

      this.root.querySelectorAll('[data-dojo-start]').forEach((b) => b.addEventListener('click', () => { this.stage.focus({ preventScroll: true }); this.start(); }));
      this.root.addEventListener('click', (e) => {
        if (e.target.closest('[data-dojo-sound]')) {
          this.toggleSound();
          if (this.state === 'running' || this.state === 'paused') this.stage.focus({ preventScroll: true });
          return;
        }
        const copy = e.target.closest('[data-dojo-copy]');
        if (!copy) return;
        const code = copy.dataset.dojoCopy;
        const done = () => { copy.classList.add('is-copied'); copy.querySelector('span').textContent = this.cfg.strings.copied; };
        if (navigator.clipboard) navigator.clipboard.writeText(code).then(done, done); else done();
      });

      if ('ResizeObserver' in window) new ResizeObserver(() => this.resize()).observe(this.canvas);
      else window.addEventListener('resize', () => this.resize());

      document.addEventListener('visibilitychange', () => { if (document.hidden && this.state === 'running') this.pause(); });
      if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
          this.visible = entries[0].isIntersecting;
          if (!this.visible && this.state === 'running') this.pause();
        }, { threshold: 0.25 }).observe(this.stage);
      }
    }

    toggleSound() {
      Sound.setOn(!Sound.on);
      store.set('hf_dojo_sound', Sound.on ? '1' : '0');
      this.renderSound();
      if (Sound.on) Sound.play('toggle');
    }

    renderSound() {
      const s = this.cfg.strings || {};
      this.soundButtons.forEach((b) => {
        b.setAttribute('aria-pressed', String(Sound.on));
        b.classList.toggle('is-muted', !Sound.on);
        const label = b.querySelector('[data-dojo-sound-label]');
        if (label) label.textContent = Sound.on ? (s.soundOn || 'Sound on') : (s.soundOff || 'Sound off');
        b.setAttribute('aria-label', Sound.on ? (s.mute || 'Mute sound') : (s.unmute || 'Turn sound on'));
      });
    }

    pressJump() {
      if (this.state === 'ready' || this.state === 'over') { if (this.canRestart()) this.start(); return; }
      if (this.state === 'paused') { this.resume(); return; }
      if (this.state !== 'running') return;
      const p = this.player;
      this.keys.jumpHeld = true;
      if (p.ground || p.coyote > 0) this.jump();
      else p.buffer = 0.12;
    }

    releaseJump() {
      this.keys.jumpHeld = false;
      const p = this.player;
      if (this.state === 'running' && p.vy > 340) p.vy = 340; // short hop when released early
    }

    pressSlash() {
      if (this.state !== 'running') return;
      const p = this.player;
      if (p.slashCd > 0) return;
      p.slash = 0.24;
      p.slashCd = 0.85;
      Sound.play('slash');
    }

    jump() {
      const p = this.player;
      p.vy = JUMP_V;
      p.ground = false;
      p.coyote = 0;
      p.buffer = 0;
      Sound.play('jump');
      for (let i = 0; i < 8; i++) this.dust(p.x + rand(-6, 10), 0, rand(-120, 40), rand(40, 160));
    }

    canRestart() { return this.state !== 'over' || performance.now() - this.overAt > 450; }

    /* ---------------- state ---------------- */
    start() {
      if (this.runsLeft() <= 0) { this.showRested(); return; }
      this.useRun();
      this.reset();
      this.state = 'running';
      this.root.classList.add('is-running');
      this.root.classList.remove('is-over');
      this.hide(this.ui.start); this.hide(this.ui.over); this.hide(this.ui.pause);
      this.renderTierPills(0);
      Sound.play('start');
      this.ticket = this.api ? this.post('start', {}).catch(() => null) : null;
    }

    /* ---------------- daily battles & saved reward ---------------- */
    today() { const d = new Date(); return `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`; }

    runsLeft() {
      const max = this.cfg.dailyRuns || 0;
      if (!max) return Infinity;
      let data = {};
      try { data = JSON.parse(store.get('hf_dojo_runs')) || {}; } catch (e) { data = {}; }
      return data.date === this.today() ? Math.max(0, max - data.n) : max;
    }

    useRun() {
      if (!this.cfg.dailyRuns) return;
      const used = this.cfg.dailyRuns - this.runsLeft() + 1;
      store.set('hf_dojo_runs', JSON.stringify({ date: this.today(), n: used }));
    }

    savedReward() {
      try {
        const r = JSON.parse(store.get('hf_dojo_reward'));
        if (!r || !r.code) return null;
        if (r.expiresAt) return new Date(r.expiresAt).getTime() > Date.now() ? r : null;
        if (!this.api && this.tiers.some((t) => t.code === r.code)) return r;
      } catch (e) { /* none */ }
      return null;
    }

    // Keep the best reward ever won, and put it on the cart right away so there is
    // no reason to keep grinding before buying.
    saveReward(tier) {
      const current = this.savedReward();
      if (current && current.percent >= tier.percent) return current;
      return this.keepReward({ code: tier.code, percent: tier.percent, at: Date.now() });
    }

    keepReward(reward) {
      store.set('hf_dojo_reward', JSON.stringify(reward));
      this.applyDiscount(reward.code);
      document.dispatchEvent(new CustomEvent('dojo:reward', { detail: reward }));
      return reward;
    }

    post(path, body) {
      return fetch(`${this.api}/${path}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
      }).then((r) => r.json().catch(() => ({ error: `http_${r.status}` })));
    }

    // Ask the rewards server for a unique code for this run, then show it.
    claimReward(tier, score, slot) {
      const s = this.cfg.strings;
      // the small reference tells the store owner what went wrong
      const fail = (msg, ref) => {
        if (window.console) console.warn('Dojo Run reward:', ref);
        slot.outerHTML = `<p class="dojo-run__claim-error">${msg}<small>Ref: ${String(ref || 'unknown').replace(/[^\w .:-]/g, '').slice(0, 60)}</small></p>`;
      };
      Promise.resolve(this.ticket)
        .then((ticket) => {
          if (!ticket || !ticket.run) throw new Error(`start_${(ticket && ticket.error) || 'unreachable'}`);
          return this.post('claim', { run: ticket.run, sig: ticket.sig, score });
        })
        .then((res) => {
          if (!slot.isConnected) return;
          if (!res || !res.code) {
            const err = res && res.error;
            fail(err === 'limit' || err === 'sold_out' ? s.claimLimit : err === 'implausible' || err === 'bad_ticket' || err === 'expired' ? s.claimInvalid : s.claimFailed, `claim_${err || 'empty'}${res && res.message ? ` ${res.message}` : ''}`);
            return;
          }
          const reward = this.keepReward({ code: res.code, percent: res.percent, at: Date.now(), expiresAt: res.expiresAt });
          slot.outerHTML = this.rewardCard(reward, true);
          this.renderStartMeta();
        })
        .catch((e) => { if (slot.isConnected) fail(s.claimFailed, e instanceof TypeError ? 'network_blocked' : e && e.message); });
    }

    applyDiscount(code) {
      if (!window.fetch || location.protocol === 'file:') return;
      fetch(`${this.cfg.root}discount/${encodeURIComponent(code)}?redirect=${encodeURIComponent(this.cfg.root)}`, { credentials: 'same-origin' }).catch(() => {});
    }

    shopUrl(code) {
      return `${this.cfg.root}discount/${encodeURIComponent(code)}?redirect=${encodeURIComponent(this.cfg.shopUrl)}`;
    }

    rewardCard(reward, fresh) {
      const s = this.cfg.strings;
      return `<div class="dojo-run__reward">
          <p class="dojo-run__reward-label">${(fresh ? s.unlocked : s.saved).replace('__PERCENT__', reward.percent)}</p>
          <button type="button" class="dojo-run__code" data-dojo-copy="${reward.code}"><b>${reward.code}</b><span>${s.copy}</span></button>
          <p class="dojo-run__applied">${s.applied}${reward.expiresAt ? `<br>${s.expires.replace('__DATE__', new Date(reward.expiresAt).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }))}` : ''}</p>
          <a class="button dojo-run__apply" href="${this.shopUrl(reward.code)}">${s.apply.replace('__PERCENT__', reward.percent)}</a>
        </div>`;
    }

    againButton() {
      const s = this.cfg.strings;
      const left = this.runsLeft();
      if (left <= 0) return `<p class="dojo-run__rested">${s.rested}</p>`;
      const label = left === Infinity ? s.again : `${s.again} · ${s.left.replace('__N__', left)}`;
      return `<button type="button" class="button button--secondary dojo-run__again" data-dojo-start>${label}</button>`;
    }

    renderStartMeta() {
      const el = this.root.querySelector('[data-dojo-start-meta]');
      if (!el) return;
      const s = this.cfg.strings;
      const parts = [];
      const reward = this.savedReward();
      if (reward) parts.push(s.saved.replace('__PERCENT__', reward.percent) + ` (<b>${reward.code}</b>)`);
      const left = this.runsLeft();
      if (left !== Infinity) parts.push(s.left.replace('__N__', left));
      el.innerHTML = parts.join(' · ');
      el.hidden = !parts.length;
    }

    showRested() {
      const s = this.cfg.strings;
      const reward = this.savedReward();
      let html = `<p class="dojo-run__over-title">${s.restedTitle}</p><p class="dojo-run__over-meta">${s.rested}</p>`;
      if (reward) html += this.rewardCard(reward, false);
      else html += `<a class="button dojo-run__apply" href="${this.cfg.shopUrl}">${s.shopAll}</a>`;
      this.hide(this.ui.start);
      this.root.classList.add('is-over');
      this.ui.over.querySelector('[data-dojo-over-body]').innerHTML = html;
      this.show(this.ui.over);
    }

    pause() {
      this.state = 'paused';
      this.show(this.ui.pause);
    }

    resume() {
      this.state = 'running';
      this.hide(this.ui.pause);
      this.last = 0;
    }

    gameOver() {
      if (this.state !== 'running') return;
      const p = this.player;
      p.dead = true;
      p.vy = 520;
      this.shake = 0.45;
      this.flash = 1;
      for (let i = 0; i < 26; i++) this.spark(p.x + 14, p.y + 40, COLORS.shu);
      this.state = 'dying';
      Sound.play('death');
      this.overAt = performance.now();
      setTimeout(() => this.showResults(), 750);
    }

    showResults() {
      this.state = 'over';
      this.overAt = performance.now();
      this.root.classList.remove('is-running');
      this.root.classList.add('is-over');
      const score = Math.floor(this.score);
      const tierWon = !!this.tierFor(score);
      const newBest = score > this.best;
      if (newBest) { this.best = score; store.set('hf_dojo_best', String(score)); }
      if (tierWon || newBest) setTimeout(() => Sound.play('victory'), 150);
      this.renderBest();

      const s = this.cfg.strings;
      const tier = this.tierFor(score);
      const next = this.tiers.find((t) => score < t.score);
      const recordScore = this.cfg.record && this.cfg.record.score ? this.cfg.record.score : 0;
      let html = `<p class="dojo-run__over-title">${score >= (tier ? tier.score : Infinity) ? s.victory : s.defeated}</p>`;
      html += `<p class="dojo-run__over-score"><span>${s.score}</span><b>${score}</b></p>`;
      html += `<p class="dojo-run__over-meta">${newBest ? `<em>${s.newBest}</em>` : `${s.best}: ${this.best}`}</p>`;

      if (recordScore && score > recordScore) {
        html += `<p class="dojo-run__record-beaten">${s.recordBeaten}</p>`;
      }

      const prior = this.savedReward();
      const claim = this.api && tier && (!prior || prior.percent < tier.percent);
      const reward = claim ? null : tier && !this.api ? this.saveReward(tier) : prior;
      if (claim) {
        html += `<div class="dojo-run__reward dojo-run__reward--loading" data-dojo-claim><p class="dojo-run__reward-label">${s.unlocked.replace('__PERCENT__', tier.percent)}</p><p class="dojo-run__applied">${s.claiming}</p></div>`;
        const better = this.tiers.find((t) => t.percent > tier.percent);
        if (better) html += `<p class="dojo-run__next">${s.nextTier.replace('__SCORE__', better.score).replace('__PERCENT__', better.percent)}</p>`;
      } else if (reward) {
        html += this.rewardCard(reward, !!tier && reward.code === tier.code);
        const better = this.tiers.find((t) => t.percent > reward.percent);
        if (better) html += `<p class="dojo-run__next">${s.nextTier.replace('__SCORE__', better.score).replace('__PERCENT__', better.percent)}</p>`;
      } else if (next) {
        const pct = Math.round((score / next.score) * 100);
        html += `<div class="dojo-run__progress"><span style="width:${clamp(pct, 2, 100)}%"></span></div>`;
        html += `<p class="dojo-run__next">${s.reachTier.replace('__SCORE__', next.score).replace('__PERCENT__', next.percent)}</p>`;
      }
      html += this.againButton();
      this.ui.over.querySelector('[data-dojo-over-body]').innerHTML = html;
      if (claim) this.claimReward(tier, score, this.ui.over.querySelector('[data-dojo-claim]'));
      const again = this.ui.over.querySelector('[data-dojo-start]');
      if (again) again.addEventListener('click', () => { this.stage.focus({ preventScroll: true }); this.start(); });
      this.show(this.ui.over);
    }

    tierFor(score) {
      let t = null;
      this.tiers.forEach((tier) => { if (score >= tier.score) t = tier; });
      return t;
    }

    show(el) { if (el) { el.hidden = false; requestAnimationFrame(() => el.classList.add('is-visible')); } }
    hide(el) { if (el) { el.classList.remove('is-visible'); el.hidden = true; } }

    toast(text, big) {
      const t = this.ui.toast;
      if (!t) return;
      t.textContent = text;
      t.classList.remove('is-showing', 'is-big');
      void t.offsetWidth;
      t.classList.add('is-showing');
      if (big) t.classList.add('is-big');
    }

    renderBest() {
      if (this.ui.best) this.ui.best.textContent = `HI ${pad(this.best)}`;
    }

    renderTierPills(score) {
      this.ui.tierPills.forEach((pill) => {
        pill.classList.toggle('is-won', score >= parseInt(pill.dataset.tierPill, 10));
      });
    }

    /* ---------------- spawning ---------------- */
    difficulty() { return clamp((this.speed - START_SPEED) / (MAX_SPEED - START_SPEED), 0, 1); }

    pickType() {
      const s = this.score;
      const pool = [['spikes', 4], ['rock', 2]];
      if (s > 40) pool.push(['lantern', 2]);
      if (s > 110) pool.push(['arrow', 2.6]);
      if (s > 230) pool.push(['shuriken', 2.2]);
      if (s > 380) pool.push(['ninja', 2.4]);
      if (s > 650) pool.push(['spikes3', 1.6], ['volley', 1.4]);
      const total = pool.reduce((a, p) => a + p[1], 0);
      let r = Math.random() * total;
      for (const [type, w] of pool) { r -= w; if (r <= 0) return type; }
      return 'spikes';
    }

    makeObstacle(type) {
      const x = this.W + 30;
      switch (type) {
        case 'spikes': { const n = Math.random() < 0.5 ? 1 : 2; return { type: 'spikes', n, x, w: 17 * n + 4, h: 30, y: 0, vx: 0 }; }
        case 'spikes3': return { type: 'spikes', n: 3, x, w: 55, h: 30, y: 0, vx: 0 };
        case 'rock': return { type: 'rock', x, w: 44, h: 30, y: 0, vx: 0 };
        case 'lantern': return { type: 'lantern', x, w: 30, h: 66, y: 0, vx: 0 };
        case 'arrow': return { type: 'arrow', x, w: 54, h: 8, y: 47, vx: rand(200, 300), slashable: true };
        case 'volley': return { type: 'arrow', x, w: 54, h: 8, y: Math.random() < 0.5 ? 47 : 14, vx: rand(260, 340), slashable: true, low: true };
        case 'shuriken': return { type: 'shuriken', x, w: 22, h: 22, y: Math.random() < 0.55 ? 8 : 44, vx: rand(140, 220), spin: 0, slashable: true };
        case 'ninja': return { type: 'ninja', x, w: 26, h: 54, y: 0, vy: 0, vx: rand(80, 160), run: 0, slashable: true, leaps: this.score > 700 && Math.random() < 0.45, leapt: false };
        default: return { type: 'spikes', n: 1, x, w: 21, h: 30, y: 0, vx: 0 };
      }
    }

    spawn(dt) {
      this.spawnIn -= dt;
      if (this.spawnIn > 0) return;
      const ob = this.pending || this.makeObstacle(this.pickType());
      const v = this.speed + (ob.vx || 0);
      const arrival = this.time + (ob.x - this.player.x) / v;
      const d = this.difficulty();
      const minGap = lerp(0.78, 0.5, d);
      if (arrival < this.lastArrival + minGap) {
        // too close to the previous threat: wait until this one can be dodged fairly
        this.pending = ob;
        this.spawnIn = this.lastArrival + minGap - arrival + 0.01;
        return;
      }
      this.pending = null;
      this.obstacles.push(ob);
      this.lastArrival = arrival + (ob.w / v);
      this.spawnIn = rand(lerp(0.55, 0.32, d), lerp(1.45, 0.95, d));
    }

    /* ---------------- update ---------------- */
    update(dt) {
      const p = this.player;
      if (this.state === 'running') {
        this.time += dt;
        this.speed = Math.min(MAX_SPEED, this.speed + ACCEL * dt);
        const before = this.score;
        this.score += (this.speed * dt) / 40;
        this.checkMilestones(before, this.score);
        this.spawn(dt);
      }
      const moving = this.state === 'running' || this.state === 'dying';
      const scroll = this.state === 'running' ? this.speed : this.state === 'dying' ? this.speed * 0.15 : 70;
      this.dist += scroll * dt;

      // player physics
      if (this.state === 'running') {
        p.duck = this.keys.duck;
        p.slash = Math.max(0, p.slash - dt);
        p.slashCd = Math.max(0, p.slashCd - dt);
        p.coyote = Math.max(0, p.coyote - dt);
        p.buffer = Math.max(0, p.buffer - dt);
        if (!p.ground) {
          p.vy -= GRAVITY * dt * (p.duck ? 2.2 : 1);
          p.y += p.vy * dt;
          if (p.y <= 0) {
            p.y = 0; p.vy = 0; p.ground = true;
            Sound.play('land');
            for (let i = 0; i < 5; i++) this.dust(p.x + rand(-4, 14), 0, rand(-160, -20), rand(20, 90));
            if (p.buffer > 0) this.jump();
          }
        }
        p.run += dt * (this.speed / 46);
        if (p.ground && Math.random() < dt * 14) this.dust(p.x - 4, 0, rand(-220, -120), rand(10, 60), 0.5);
      } else if (this.state === 'dying') {
        p.vy -= GRAVITY * dt;
        p.y = Math.max(0, p.y + p.vy * dt);
        p.rot = Math.min(1.5, p.rot + dt * 5);
      } else {
        p.run += dt * 2.2;
      }

      // obstacles
      if (moving) {
        for (const ob of this.obstacles) {
          const v = (this.state === 'running' ? this.speed : this.speed * 0.15) + (ob.vx || 0);
          ob.x -= v * dt;
          if (ob.type === 'shuriken') ob.spin += dt * 18;
          if (ob.type === 'ninja') {
            ob.run += dt * 12;
            if (ob.leaps && !ob.leapt && ob.x - p.x < rand(170, 260)) { ob.vy = 760; ob.leapt = true; }
            if (ob.vy || ob.y > 0) {
              ob.vy -= GRAVITY * dt;
              ob.y = Math.max(0, ob.y + ob.vy * dt);
              if (ob.y === 0) ob.vy = 0;
            }
          }
          if (!ob.passed && ob.x + ob.w < p.x) {
            ob.passed = true;
          }
        }
        this.obstacles = this.obstacles.filter((ob) => ob.x + ob.w > -60 && !ob.dead);
      }

      if (this.state === 'running') {
        this.handleSlash();
        if (this.hitTest()) this.gameOver();
      }

      // particles & fx
      this.particles.forEach((pt) => {
        pt.life -= dt;
        pt.vy -= (pt.g || 900) * dt;
        pt.x += (pt.vx - (moving ? scroll * 0.15 : 0)) * dt;
        pt.y += pt.vy * dt;
        if (pt.y < 0 && pt.ground) { pt.y = 0; pt.vy *= -0.3; pt.vx *= 0.6; }
      });
      this.particles = this.particles.filter((pt) => pt.life > 0);
      this.floaters.forEach((f) => { f.life -= dt; f.y += 50 * dt; });
      this.floaters = this.floaters.filter((f) => f.life > 0);
      const wind = moving ? scroll * 0.25 : 20;
      this.petals.forEach((pt, i) => {
        pt.x -= (pt.vx + wind) * dt;
        pt.y += pt.vy * dt;
        pt.rot += pt.vr * dt;
        pt.phase += dt * 2;
        if (pt.x < -20 || pt.y > this.H + 10) this.petals[i] = this.newPetal(false);
      });
      this.shake = Math.max(0, this.shake - dt);
      this.flash = Math.max(0, this.flash - dt * 2.2);
    }

    hitTest() {
      const p = this.player;
      const h = p.duck && p.ground ? 30 : p.duck ? 40 : 58;
      const box = { x1: p.x + 6, x2: p.x + 24, y1: p.y + 2, y2: p.y + h };
      return this.obstacles.some((ob) => {
        if (ob.dead) return false;
        const inset = ob.type === 'spikes' || ob.type === 'rock' ? 5 : 3;
        const o = { x1: ob.x + inset, x2: ob.x + ob.w - inset, y1: ob.y + 1, y2: ob.y + ob.h - (ob.type === 'spikes' ? 6 : 2) };
        return box.x1 < o.x2 && box.x2 > o.x1 && box.y1 < o.y2 && box.y2 > o.y1;
      });
    }

    handleSlash() {
      const p = this.player;
      if (p.slash <= 0) return;
      const reach = { x1: p.x + 14, x2: p.x + 104, y1: p.y - 4, y2: p.y + 82 };
      this.obstacles.forEach((ob) => {
        if (!ob.slashable || ob.dead) return;
        const hit = ob.x < reach.x2 && ob.x + ob.w > reach.x1 && ob.y < reach.y2 && ob.y + ob.h > reach.y1;
        if (!hit) return;
        ob.dead = true;
        Sound.play('hit');
        this.score += 15;
        for (let i = 0; i < 14; i++) this.spark(ob.x + ob.w / 2, ob.y + ob.h / 2, i % 2 ? COLORS.steel : COLORS.shu);
        this.floaters.push({ x: ob.x, y: ob.y + ob.h + 10, text: '+15 SLASH', life: 0.8 });
      });
    }

    checkMilestones(before, after) {
      if (after >= this.nextMilestone) {
        this.nextMilestone += 100;
        this.blinkScore = 0.9;
        Sound.play('milestone');
      }
      const idx = this.tiers.findIndex((t) => before < t.score && after >= t.score);
      if (idx > -1) {
        const tier = this.tiers[idx];
        this.unlockedIndex = idx;
        this.flash = 0.9;
        Sound.play('unlock');
        this.toast(this.cfg.strings.toastUnlock.replace('__PERCENT__', tier.percent), true);
        if (!this.api) this.saveReward(tier); // server codes are issued when the run ends
        for (let i = 0; i < 40; i++) this.spark(rand(0, this.W), rand(40, 200), i % 3 ? COLORS.gold : COLORS.shu, true);
        this.renderTierPills(after);
      }
    }

    /* ---------------- particles ---------------- */
    dust(x, y, vx, vy, life) {
      this.particles.push({ x, y, vx, vy, life: life || rand(0.3, 0.6), max: 0.6, r: rand(1.5, 3.5), color: 'dust', g: 700, ground: true });
    }

    spark(x, y, color, floaty) {
      const a = rand(0, Math.PI * 2);
      const s = rand(120, 520);
      this.particles.push({ x, y, vx: Math.cos(a) * s, vy: Math.sin(a) * s, life: rand(0.4, 0.9), max: 0.9, r: rand(1.5, 3.5), color, g: floaty ? 120 : 900 });
    }

    newPetal(initial) {
      return { x: initial ? rand(0, this.W || 900) : (this.W || 900) + rand(0, 80), y: initial ? rand(-20, this.H || 300) : rand(-40, (this.H || 300) * 0.6), vx: rand(10, 40), vy: rand(18, 45), rot: rand(0, 6), vr: rand(-3, 3), phase: rand(0, 6), s: rand(0.7, 1.3) };
    }

    /* ---------------- render ---------------- */
    sky() {
      const t = this.score / SKY_STEP;
      const i = Math.floor(t) % SKIES.length;
      const j = (i + 1) % SKIES.length;
      const f = clamp((t % 1 - 0.8) / 0.2, 0, 1); // hold, then blend over the last 20% of each phase
      const a = SKIES[i]; const b = SKIES[j];
      return {
        top: mixHex(a.top, b.top, f), bottom: mixHex(a.bottom, b.bottom, f), sun: mixHex(a.sun, b.sun, f),
        far: mixRgba(a.far, b.far, f), mid: mixRgba(a.mid, b.mid, f), near: mixRgba(a.near, b.near, f),
        night: lerp(a.night, b.night, f)
      };
    }

    frame(ts) {
      const dt = this.last ? Math.min(0.034, (ts - this.last) / 1000) : 0.016;
      this.last = ts;
      if (this.state !== 'paused' && this.visible) this.update(dt);
      if (this.visible) this.draw();
      if (this.ui.score) {
        this.blinkScore = Math.max(0, (this.blinkScore || 0) - dt);
        this.ui.score.textContent = pad(this.score);
        this.ui.score.classList.toggle('is-blink', this.blinkScore > 0 && Math.floor(this.blinkScore * 8) % 2 === 0);
      }
      requestAnimationFrame((t) => this.frame(t));
    }

    draw() {
      const ctx = this.ctx;
      const k = this.dpr * this.scale;
      ctx.setTransform(k, 0, 0, k, 0, 0);
      const sky = this.sky();
      if (this.shake > 0) {
        const m = this.shake * 14;
        ctx.translate(rand(-m, m), rand(-m, m));
      }
      const W = this.W; const H = this.H; const gy = this.groundY;

      // sky
      const g = ctx.createLinearGradient(0, 0, 0, gy);
      g.addColorStop(0, sky.top);
      g.addColorStop(1, sky.bottom);
      ctx.fillStyle = g;
      ctx.fillRect(-20, -20, W + 40, H + 40);

      // stars at night
      if (sky.night > 0.3) {
        ctx.fillStyle = `rgba(243,239,230,${(sky.night - 0.3) * 0.9})`;
        for (let i = 0; i < 40; i++) {
          const sx = ((i * 137.5 - this.dist * 0.01) % W + W) % W;
          const sy = (i * 53) % (gy - 80);
          ctx.fillRect(sx, sy, 1.6, 1.6);
        }
      }

      // sun / moon
      const sunX = W * 0.74 - (this.dist * 0.01) % 40;
      const sunY = gy - 150;
      ctx.fillStyle = sky.sun;
      ctx.globalAlpha = 0.9;
      ctx.beginPath(); ctx.arc(sunX, sunY, 54, 0, Math.PI * 2); ctx.fill();
      ctx.globalAlpha = 1;
      // cloud bands across the sun
      ctx.fillStyle = sky.bottom;
      ctx.globalAlpha = 0.55;
      ctx.fillRect(sunX - 80, sunY + 10, 150, 5);
      ctx.fillRect(sunX - 50, sunY + 24, 120, 4);
      ctx.globalAlpha = 1;

      this.drawFar(sky);
      this.drawMid(sky);
      this.drawNear(sky);

      // ground
      ctx.fillStyle = sky.night > 0.5 ? '#14121c' : COLORS.ink;
      ctx.fillRect(-20, gy, W + 40, H - gy + 20);
      ctx.fillStyle = sky.night > 0.5 ? '#2a2638' : '#2a2622';
      const off = this.dist % 64;
      for (let x = -off; x < W + 64; x += 64) {
        ctx.fillRect(x, gy + 14, 22, 2);
        ctx.fillRect(x + 34, gy + 28, 12, 2);
      }
      ctx.fillStyle = COLORS.shu;
      ctx.fillRect(-20, gy, W + 40, 2);
      this.drawTufts(sky);

      // obstacles
      this.obstacles.forEach((ob) => this.drawObstacle(ob, sky));

      // player
      this.drawSamurai(sky);

      // particles
      this.particles.forEach((pt) => {
        const alpha = clamp(pt.life / (pt.max || 0.6), 0, 1);
        ctx.globalAlpha = alpha;
        ctx.fillStyle = pt.color === 'dust' ? (sky.night > 0.5 ? '#6b6478' : '#8a7f70') : pt.color;
        ctx.beginPath(); ctx.arc(pt.x, gy - pt.y, pt.r, 0, Math.PI * 2); ctx.fill();
      });
      ctx.globalAlpha = 1;

      // floating score text
      ctx.font = '700 14px Oswald, "Shippori Mincho B1", sans-serif';
      ctx.textAlign = 'center';
      this.floaters.forEach((f) => {
        ctx.globalAlpha = clamp(f.life / 0.8, 0, 1);
        ctx.fillStyle = COLORS.shu;
        ctx.fillText(f.text, f.x, gy - f.y);
      });
      ctx.globalAlpha = 1;

      // petals in front
      this.petals.forEach((pt) => {
        ctx.save();
        ctx.translate(pt.x + Math.sin(pt.phase) * 8, pt.y);
        ctx.rotate(pt.rot);
        ctx.scale(pt.s, pt.s * (0.6 + 0.4 * Math.abs(Math.sin(pt.phase))));
        ctx.fillStyle = COLORS.petal;
        ctx.beginPath();
        ctx.moveTo(0, -5); ctx.quadraticCurveTo(5, -2, 0, 5); ctx.quadraticCurveTo(-5, -2, 0, -5);
        ctx.fill();
        ctx.restore();
      });

      // flash
      if (this.flash > 0) {
        ctx.fillStyle = this.state === 'dying' || this.player.dead ? `rgba(200,16,46,${this.flash * 0.35})` : `rgba(255,236,190,${this.flash * 0.45})`;
        ctx.fillRect(-20, -20, W + 40, H + 40);
      }
    }

    drawFar(sky) {
      const ctx = this.ctx; const gy = this.groundY; const sc = this.scenery;
      const off = (this.dist * 0.06) % sc.farW;
      ctx.fillStyle = sky.far;
      for (let rep = -1; rep < Math.ceil(this.W / sc.farW) + 1; rep++) {
        sc.far.forEach((m) => {
          const x = m.x + rep * sc.farW - off;
          if (x > this.W + 50 || x + m.w < -50) return;
          const base = gy - 34;
          ctx.beginPath();
          if (m.fuji) {
            const h = m.h + 46;
            ctx.moveTo(x, base);
            ctx.lineTo(x + m.w * 0.4, base - h);
            ctx.lineTo(x + m.w * 0.6, base - h);
            ctx.lineTo(x + m.w, base);
            ctx.fill();
            // snow cap
            ctx.save();
            ctx.fillStyle = sky.night > 0.5 ? 'rgba(243,239,230,.35)' : 'rgba(255,255,255,.75)';
            ctx.beginPath();
            ctx.moveTo(x + m.w * 0.4, base - h);
            ctx.lineTo(x + m.w * 0.6, base - h);
            ctx.lineTo(x + m.w * 0.68, base - h + 30);
            ctx.lineTo(x + m.w * 0.6, base - h + 22);
            ctx.lineTo(x + m.w * 0.53, base - h + 32);
            ctx.lineTo(x + m.w * 0.46, base - h + 22);
            ctx.lineTo(x + m.w * 0.33, base - h + 30);
            ctx.closePath();
            ctx.fill();
            ctx.restore();
          } else {
            ctx.moveTo(x, base);
            ctx.quadraticCurveTo(x + m.w * 0.25, base - m.h * 0.7, x + m.w * 0.45, base - m.h);
            ctx.quadraticCurveTo(x + m.w * 0.7, base - m.h * 0.6, x + m.w, base);
            ctx.fill();
          }
        });
      }
    }

    drawMid(sky) {
      const ctx = this.ctx; const gy = this.groundY; const sc = this.scenery;
      const off = (this.dist * 0.2) % sc.midW;
      ctx.fillStyle = sky.mid;
      // rolling hill band
      ctx.beginPath();
      ctx.moveTo(-20, gy);
      for (let x = -20; x <= this.W + 20; x += 20) {
        const wx = x + this.dist * 0.2;
        ctx.lineTo(x, gy - 22 - Math.sin(wx / 140) * 10 - Math.sin(wx / 53) * 4);
      }
      ctx.lineTo(this.W + 20, gy);
      ctx.fill();
      for (let rep = -1; rep < Math.ceil(this.W / sc.midW) + 1; rep++) {
        sc.mid.forEach((o) => {
          const x = o.x + rep * sc.midW - off;
          if (x > this.W + 120 || x < -160) return;
          this.drawLandmark(o.kind, x, gy - 26, o.s, sky);
        });
      }
    }

    drawLandmark(kind, x, base, s, sky) {
      const ctx = this.ctx;
      ctx.save();
      ctx.translate(x, base);
      ctx.scale(s, s);
      if (kind === 'pagoda') {
        for (let i = 0; i < 4; i++) {
          const y = -i * 22; const w = 52 - i * 9;
          ctx.fillRect(-w / 2 + 6, y - 16, w - 12, 16);
          ctx.beginPath();
          ctx.moveTo(-w / 2 - 6, y - 14); ctx.quadraticCurveTo(0, y - 26, w / 2 + 6, y - 14);
          ctx.lineTo(w / 2 - 4, y - 20); ctx.lineTo(-w / 2 + 4, y - 20); ctx.fill();
        }
        ctx.fillRect(-1.5, -112, 3, 26);
      } else if (kind === 'torii') {
        ctx.fillStyle = sky.night > 0.5 ? 'rgba(120,20,30,.7)' : 'rgba(200,16,46,.55)';
        ctx.fillRect(-26, -62, 6, 62);
        ctx.fillRect(20, -62, 6, 62);
        ctx.fillRect(-32, -50, 64, 5);
        ctx.beginPath();
        ctx.moveTo(-40, -66); ctx.quadraticCurveTo(0, -60, 40, -66); ctx.lineTo(38, -58); ctx.quadraticCurveTo(0, -54, -38, -58); ctx.fill();
      } else if (kind === 'pine') {
        ctx.fillRect(-2, -40, 4, 40);
        [[-22, -40, 26], [-4, -58, 30], [-18, -70, 22]].forEach(([dx, dy, w]) => {
          ctx.beginPath(); ctx.ellipse(dx + w / 2, dy, w, 7, 0, 0, Math.PI * 2); ctx.fill();
        });
      } else if (kind === 'castle') {
        ctx.fillRect(-34, -30, 68, 30);
        ctx.beginPath(); ctx.moveTo(-44, -28); ctx.lineTo(44, -28); ctx.lineTo(28, -42); ctx.lineTo(-28, -42); ctx.fill();
        ctx.fillRect(-20, -62, 40, 22);
        ctx.beginPath(); ctx.moveTo(-30, -60); ctx.lineTo(30, -60); ctx.lineTo(16, -76); ctx.lineTo(-16, -76); ctx.fill();
      }
      ctx.restore();
    }

    drawNear(sky) {
      const ctx = this.ctx; const gy = this.groundY; const sc = this.scenery;
      const off = (this.dist * 0.45) % sc.nearW;
      ctx.fillStyle = sky.near;
      for (let rep = -1; rep < Math.ceil(this.W / sc.nearW) + 1; rep++) {
        sc.near.forEach((b) => {
          const x = b.x + rep * sc.nearW - off;
          if (x > this.W + 20 || x < -20) return;
          ctx.fillRect(x, gy - b.h, b.w, b.h);
          for (let y = gy - b.h + 26; y < gy; y += 30) ctx.fillRect(x - 1, y, b.w + 2, 2);
          ctx.beginPath(); ctx.ellipse(x + b.w + 9, gy - b.h + 12, 12, 3, -0.5, 0, Math.PI * 2); ctx.fill();
        });
      }
    }

    drawTufts(sky) {
      const ctx = this.ctx; const gy = this.groundY; const sc = this.scenery;
      const off = this.dist % sc.tuftW;
      ctx.strokeStyle = sky.night > 0.5 ? '#2a2638' : COLORS.ink;
      ctx.lineWidth = 1.5;
      for (let rep = -1; rep < Math.ceil(this.W / sc.tuftW) + 1; rep++) {
        sc.tufts.forEach((t) => {
          const x = t.x + rep * sc.tuftW - off;
          if (x < -10 || x > this.W + 10) return;
          ctx.beginPath();
          ctx.moveTo(x, gy); ctx.lineTo(x - 3, gy - 6 - t.k * 4);
          ctx.moveTo(x + 2, gy); ctx.lineTo(x + 3, gy - 8 - t.k * 5);
          ctx.moveTo(x + 4, gy); ctx.lineTo(x + 8, gy - 5);
          ctx.stroke();
        });
      }
    }

    drawObstacle(ob, sky) {
      const ctx = this.ctx; const gy = this.groundY;
      const x = ob.x; const y = gy - ob.y;
      const outline = sky.night > 0.5;
      ctx.save();
      if (ob.type === 'spikes') {
        for (let i = 0; i < ob.n; i++) {
          const sx = x + 2 + i * 17;
          const h = ob.h - (i % 2) * 5;
          ctx.fillStyle = '#2f4a2c';
          ctx.beginPath(); ctx.moveTo(sx, y); ctx.lineTo(sx + 15, y); ctx.lineTo(sx + 15, y - h + 6); ctx.lineTo(sx, y - h); ctx.closePath(); ctx.fill();
          ctx.fillStyle = '#d9c9a0';
          ctx.beginPath(); ctx.moveTo(sx, y - h); ctx.lineTo(sx + 15, y - h + 6); ctx.lineTo(sx + 9, y - h + 5); ctx.closePath(); ctx.fill();
          ctx.fillStyle = 'rgba(0,0,0,.35)';
          ctx.fillRect(sx, y - h * 0.45, 15, 2);
        }
        ctx.fillStyle = COLORS.shu;
        ctx.fillRect(x, y - 9, ob.w, 3);
      } else if (ob.type === 'rock') {
        ctx.fillStyle = outline ? '#3a3548' : '#3b3631';
        ctx.beginPath();
        ctx.moveTo(x, y); ctx.lineTo(x + 6, y - 20); ctx.lineTo(x + 18, y - ob.h); ctx.lineTo(x + 32, y - 24); ctx.lineTo(x + ob.w, y); ctx.closePath(); ctx.fill();
        ctx.fillStyle = 'rgba(255,255,255,.12)';
        ctx.beginPath(); ctx.moveTo(x + 8, y - 18); ctx.lineTo(x + 18, y - ob.h + 2); ctx.lineTo(x + 20, y - 18); ctx.fill();
      } else if (ob.type === 'lantern') {
        ctx.fillStyle = outline ? '#4a4558' : '#575049';
        ctx.fillRect(x + 4, y - 8, 22, 8);
        ctx.fillRect(x + 11, y - 34, 8, 26);
        ctx.fillRect(x + 4, y - 40, 22, 6);
        ctx.fillRect(x + 6, y - 54, 18, 14);
        ctx.beginPath(); ctx.moveTo(x - 2, y - 54); ctx.lineTo(x + 32, y - 54); ctx.lineTo(x + 15, y - ob.h); ctx.closePath(); ctx.fill();
        const glow = 0.55 + sky.night * 0.45 + Math.sin(this.time * 9) * 0.08;
        ctx.fillStyle = `rgba(255,170,60,${glow})`;
        ctx.fillRect(x + 11, y - 51, 8, 8);
        if (sky.night > 0.2) {
          const rg = ctx.createRadialGradient(x + 15, y - 47, 2, x + 15, y - 47, 34);
          rg.addColorStop(0, `rgba(255,170,60,${0.35 * sky.night})`);
          rg.addColorStop(1, 'rgba(255,170,60,0)');
          ctx.fillStyle = rg; ctx.fillRect(x - 20, y - 82, 70, 70);
        }
      } else if (ob.type === 'arrow') {
        const ay = y - ob.h / 2;
        ctx.strokeStyle = 'rgba(200,16,46,.25)';
        ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(x + ob.w, ay); ctx.lineTo(x + ob.w + 40, ay); ctx.stroke();
        ctx.strokeStyle = outline ? '#d8d0c0' : '#5b4630';
        ctx.lineWidth = 2.5;
        ctx.beginPath(); ctx.moveTo(x + 8, ay); ctx.lineTo(x + ob.w, ay); ctx.stroke();
        ctx.fillStyle = COLORS.steel;
        ctx.beginPath(); ctx.moveTo(x, ay); ctx.lineTo(x + 11, ay - 4); ctx.lineTo(x + 11, ay + 4); ctx.closePath(); ctx.fill();
        ctx.fillStyle = COLORS.shu;
        ctx.beginPath(); ctx.moveTo(x + ob.w - 12, ay); ctx.lineTo(x + ob.w, ay - 6); ctx.lineTo(x + ob.w + 2, ay); ctx.lineTo(x + ob.w, ay + 6); ctx.closePath(); ctx.fill();
      } else if (ob.type === 'shuriken') {
        ctx.translate(x + ob.w / 2, y - ob.h / 2);
        ctx.rotate(-ob.spin);
        ctx.fillStyle = outline ? '#e6e8ee' : '#4b4f57';
        ctx.beginPath();
        for (let i = 0; i < 4; i++) {
          const a = (i * Math.PI) / 2;
          ctx.lineTo(Math.cos(a) * 12, Math.sin(a) * 12);
          ctx.lineTo(Math.cos(a + Math.PI / 4) * 4, Math.sin(a + Math.PI / 4) * 4);
        }
        ctx.closePath(); ctx.fill();
        ctx.fillStyle = COLORS.shu;
        ctx.beginPath(); ctx.arc(0, 0, 2.5, 0, Math.PI * 2); ctx.fill();
      } else if (ob.type === 'ninja') {
        this.drawNinja(ob, x, y, outline);
      }
      ctx.restore();
    }

    drawNinja(ob, x, y, outline) {
      const ctx = this.ctx;
      const body = outline ? '#1d1a26' : '#121218';
      const cx = x + ob.w / 2;
      const swing = Math.sin(ob.run) * 0.8;
      ctx.lineCap = 'round';
      ctx.strokeStyle = body;
      ctx.lineWidth = 6;
      // legs (running toward the player, i.e. facing left)
      ctx.beginPath();
      ctx.moveTo(cx, y - 24); ctx.lineTo(cx - 8 * Math.sin(swing), y - 10); ctx.lineTo(cx - 10 * Math.sin(swing) - 3, y);
      ctx.moveTo(cx, y - 24); ctx.lineTo(cx + 8 * Math.sin(swing), y - 10); ctx.lineTo(cx + 10 * Math.sin(swing) - 3, y);
      ctx.stroke();
      // torso
      ctx.fillStyle = body;
      ctx.beginPath(); ctx.moveTo(cx - 8, y - 44); ctx.lineTo(cx + 9, y - 42); ctx.lineTo(cx + 6, y - 22); ctx.lineTo(cx - 6, y - 22); ctx.closePath(); ctx.fill();
      // head + mask slit
      ctx.beginPath(); ctx.arc(cx - 1, y - 50, 7.5, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#e8d9c4';
      ctx.fillRect(cx - 8, y - 52, 8, 3);
      ctx.fillStyle = COLORS.shu;
      ctx.fillRect(cx - 7, y - 51.5, 2, 2);
      // headband tails
      ctx.strokeStyle = COLORS.shu; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(cx + 6, y - 53); ctx.quadraticCurveTo(cx + 14, y - 56 + Math.sin(ob.run * 1.5) * 3, cx + 20, y - 52); ctx.stroke();
      // arm + blade forward
      ctx.strokeStyle = body; ctx.lineWidth = 4;
      ctx.beginPath(); ctx.moveTo(cx - 2, y - 40); ctx.lineTo(cx - 12, y - 34); ctx.stroke();
      ctx.strokeStyle = '#cfd3db'; ctx.lineWidth = 2;
      ctx.beginPath(); ctx.moveTo(cx - 12, y - 34); ctx.lineTo(cx - 30, y - 42); ctx.stroke();
    }

    drawSamurai(sky) {
      const ctx = this.ctx; const p = this.player; const gy = this.groundY;
      const x = p.x + 14; const y = gy - p.y;
      const ink = COLORS.ink;
      const outline = sky.night > 0.5;
      const running = this.state === 'running' && p.ground;
      const idle = this.state === 'ready' || this.state === 'over' || this.state === 'paused';
      const ducking = p.duck && p.ground && !p.dead;
      const phase = p.run;
      const t = performance.now() / 1000;

      ctx.save();
      ctx.translate(x, y);
      if (p.dead) { ctx.translate(0, -10); ctx.rotate(-p.rot); ctx.translate(0, 10); }
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';

      // shadow
      ctx.save();
      ctx.scale(1, 0.25);
      ctx.fillStyle = 'rgba(0,0,0,.25)';
      ctx.beginPath(); ctx.arc(0, p.y * 4 + 4, clamp(16 - p.y / 10, 6, 16), 0, Math.PI * 2); ctx.fill();
      ctx.restore();

      const hipY = ducking ? -18 : -28;
      const lean = ducking ? 0.5 : running ? 0.22 : 0.05;
      const shoulder = { x: Math.sin(lean) * (ducking ? 16 : 22), y: hipY - Math.cos(lean) * (ducking ? 16 : 22) };
      const headY = shoulder.y - 9 + (idle ? Math.sin(t * 2.5) * 0.8 : 0);
      const headX = shoulder.x + 2;

      if (outline) { ctx.shadowColor = 'rgba(243,239,230,.55)'; ctx.shadowBlur = 6; }

      // legs: wide hakama strokes
      const legW = 8;
      ctx.strokeStyle = '#1b1b22';
      ctx.lineWidth = legW;
      const leg = (s) => {
        let kx; let ky; let fx; let fy;
        if (!p.ground && !p.dead) {
          kx = 8 * s + 4; ky = hipY + 12; fx = -2 + 6 * s; fy = hipY + 22;
        } else if (ducking) {
          kx = 12 * s + 4; ky = -10; fx = 6 * s; fy = 0;
        } else if (running) {
          const a = Math.sin(phase + (s > 0 ? 0 : Math.PI));
          kx = a * 10 + 2; ky = hipY + 14 - Math.max(0, a) * 3;
          fx = a * 13 - 2; fy = Math.min(0, -Math.max(0, -Math.cos(phase + (s > 0 ? 0 : Math.PI))) * 7);
        } else {
          kx = 5 * s; ky = hipY + 14; fx = 7 * s; fy = 0;
        }
        ctx.beginPath(); ctx.moveTo(0, hipY); ctx.lineTo(kx, ky); ctx.lineTo(fx, fy); ctx.stroke();
        ctx.fillStyle = '#e8d9c4';
        ctx.fillRect(fx - 2, fy - 2, 7, 3); // tabi sock / sandal
      };
      leg(-1);
      leg(1);

      // torso (kimono)
      ctx.fillStyle = ink;
      ctx.beginPath();
      ctx.moveTo(-7, hipY + 2);
      ctx.lineTo(shoulder.x - 9, shoulder.y);
      ctx.lineTo(shoulder.x + 9, shoulder.y + 1);
      ctx.lineTo(9, hipY + 2);
      ctx.closePath(); ctx.fill();
      // shoulder armour plates (sode)
      ctx.fillStyle = '#2c2a33';
      ctx.beginPath(); ctx.ellipse(shoulder.x - 6, shoulder.y + 5, 6, 4, -0.6, 0, Math.PI * 2); ctx.fill();
      // red obi sash
      ctx.fillStyle = COLORS.shu;
      ctx.save();
      ctx.translate(0, hipY);
      ctx.rotate(lean * 0.3);
      ctx.fillRect(-8, -5, 18, 5);
      ctx.restore();
      // kimono collar
      ctx.strokeStyle = '#e8d9c4'; ctx.lineWidth = 1.5;
      ctx.beginPath(); ctx.moveTo(shoulder.x - 3, shoulder.y + 1); ctx.lineTo(shoulder.x + 2, shoulder.y + 8); ctx.lineTo(shoulder.x + 6, shoulder.y + 1); ctx.stroke();

      // head
      ctx.fillStyle = '#e8d2b4';
      ctx.beginPath(); ctx.arc(headX, headY, 7, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = ink;
      ctx.beginPath(); ctx.arc(headX - 1, headY - 2, 7.2, Math.PI * 0.95, Math.PI * 2.05); ctx.fill(); // hair
      ctx.beginPath(); ctx.ellipse(headX - 6, headY - 7, 4, 2.4, -0.5, 0, Math.PI * 2); ctx.fill(); // topknot
      ctx.fillRect(headX + 3, headY - 1, 2, 1.6); // eye
      // hachimaki headband + flowing tails
      ctx.fillStyle = COLORS.shu;
      ctx.fillRect(headX - 7, headY - 4, 14, 3);
      const wave = Math.sin(t * 14) * 3;
      const flow = running || !p.ground ? 1 : 0.4;
      ctx.strokeStyle = COLORS.shu; ctx.lineWidth = 2.4;
      ctx.beginPath();
      ctx.moveTo(headX - 7, headY - 3);
      ctx.quadraticCurveTo(headX - 16, headY - 6 + wave, headX - 26 * flow - 4, headY - 3 - wave * flow);
      ctx.moveTo(headX - 7, headY - 2);
      ctx.quadraticCurveTo(headX - 14, headY + 2 - wave, headX - 22 * flow - 4, headY + 4 + wave * flow);
      ctx.stroke();

      // arms + katana
      let handX; let handY; let bladeAngle;
      if (p.slash > 0) {
        const k = 1 - p.slash / 0.24; // 0 -> 1
        bladeAngle = lerp(-2.3, 0.55, k);
        handX = shoulder.x + 10; handY = shoulder.y + 4;
      } else if (!p.ground && !p.dead) {
        bladeAngle = -2.0; handX = shoulder.x + 4; handY = shoulder.y - 8;
      } else if (ducking) {
        bladeAngle = -0.05; handX = shoulder.x + 12; handY = shoulder.y + 6;
      } else if (running) {
        bladeAngle = 2.75 + Math.sin(phase) * 0.06; handX = shoulder.x + 8; handY = shoulder.y + 12;
      } else {
        bladeAngle = idle ? -1.15 : 2.7; handX = shoulder.x + 8; handY = shoulder.y + 10;
      }
      ctx.strokeStyle = ink; ctx.lineWidth = 4;
      ctx.beginPath(); ctx.moveTo(shoulder.x, shoulder.y + 3); ctx.lineTo(handX, handY); ctx.stroke();

      // slash arc trail
      if (p.slash > 0) {
        const k = 1 - p.slash / 0.24;
        ctx.save();
        ctx.shadowBlur = 0;
        ctx.strokeStyle = `rgba(200,16,46,${0.75 * (1 - k * 0.6)})`;
        ctx.lineWidth = 10;
        ctx.beginPath(); ctx.arc(handX, handY, 52, -2.3, lerp(-2.3, 0.55, k)); ctx.stroke();
        ctx.strokeStyle = `rgba(255,255,255,${0.9 * (1 - k * 0.5)})`;
        ctx.lineWidth = 3;
        ctx.beginPath(); ctx.arc(handX, handY, 54, -2.3, lerp(-2.3, 0.55, k)); ctx.stroke();
        ctx.restore();
      }

      // katana: handle (tsuka), guard (tsuba), blade
      ctx.save();
      ctx.translate(handX, handY);
      ctx.rotate(bladeAngle);
      ctx.fillStyle = '#1a1a1a';
      ctx.fillRect(-9, -1.8, 12, 3.6);
      ctx.fillStyle = COLORS.shu;
      ctx.fillRect(-8, -1, 2, 2); ctx.fillRect(-4, -1, 2, 2);
      ctx.fillStyle = COLORS.gold;
      ctx.beginPath(); ctx.ellipse(3, 0, 1.6, 4.2, 0, 0, Math.PI * 2); ctx.fill();
      const bladeGrad = ctx.createLinearGradient(4, -2, 4, 2);
      bladeGrad.addColorStop(0, '#ffffff');
      bladeGrad.addColorStop(1, '#9aa1ad');
      ctx.fillStyle = bladeGrad;
      ctx.beginPath();
      ctx.moveTo(4.5, -1.6); ctx.quadraticCurveTo(26, -2.4, 46, -4.5); ctx.lineTo(48, -3.2); ctx.quadraticCurveTo(26, 0.8, 4.5, 1.6);
      ctx.closePath(); ctx.fill();
      // glint
      const glint = (t * 1.6) % 3;
      if (glint < 1) {
        ctx.fillStyle = `rgba(255,255,255,${0.9 - glint * 0.9})`;
        ctx.fillRect(6 + glint * 38, -2.4, 4, 1.2);
      }
      ctx.restore();

      // front arm on top
      ctx.strokeStyle = '#26242c'; ctx.lineWidth = 3.5;
      ctx.beginPath(); ctx.moveTo(shoulder.x + 4, shoulder.y + 3); ctx.lineTo(handX - 2, handY + 1); ctx.stroke();

      ctx.restore();
    }
  }

  const init = () => document.querySelectorAll('[data-dojo-run]').forEach((root) => {
    if (root.dataset.dojoReady) return;
    root.dataset.dojoReady = 'true';
    root.dojoRun = new DojoRun(root);
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  document.addEventListener('shopify:section:load', init);
})();
