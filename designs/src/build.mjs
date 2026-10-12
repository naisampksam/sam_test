// Generates the samurai back-print and chest-print SVGs.
// Usage: node build.mjs   ->  writes ../svg/<design>-<light|dark>.svg and ../svg/<design>-chest-<mode>.svg
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import opentype from 'opentype.js';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(HERE, '..', 'svg');
fs.mkdirSync(OUT, { recursive: true });

// All text is converted to vector outlines so printers and embroidery digitizers never need the fonts.
const load = (file) => opentype.loadSync(path.join(HERE, 'fonts', file));
const FONT = { Boku: load('Yuji_Boku.ttf'), Syuku: load('Yuji_Syuku.ttf'), Bebas: load('Bebas_Neue.ttf') };

// Outlined text. anchor: start|middle; central: y is the em-box centre (like dominant-baseline="central").
function txt(str, { x, y, size, family = 'Bebas', fill, anchor = 'middle', ls = 0, central = false }) {
  const primary = FONT[family];
  const glyphs = [...str].map((ch) => {
    const fnt = primary.charToGlyphIndex(ch) ? primary : FONT.Boku;
    const g = fnt.charToGlyph(ch);
    return { fnt, g, adv: (g.advanceWidth / fnt.unitsPerEm) * size };
  });
  const width = glyphs.reduce((w, q) => w + q.adv, 0) + ls * (glyphs.length - 1);
  let cx = anchor === 'middle' ? x - width / 2 : x;
  const { ascender, descender, unitsPerEm } = primary;
  const base = central ? y + ((ascender + descender) / 2 / unitsPerEm) * size : y;
  let d = '';
  for (const q of glyphs) {
    d += q.g.getPath(cx, base, size).toPathData(1);
    cx += q.adv + ls;
  }
  return `<path d="${d}" fill="${fill}"/>`;
}

// Ink colour per shirt type; red is shared.
const MODES = {
  light: { ink: '#141212' }, // white, beige, lavender, light acid wash
  dark: { ink: '#EFE8DC' }, // black, navy, bottle green, chocolate, royal blue
};
const RED = '#D7261E';

// ---------- helpers ----------
function rng(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const f = (n) => Math.round(n * 10) / 10;

const filters = (seed) => `
<filter id="rough" x="-5%" y="-5%" width="110%" height="110%">
  <feTurbulence type="fractalNoise" baseFrequency="0.03" numOctaves="2" seed="${seed}" result="n"/>
  <feDisplacementMap in="SourceGraphic" in2="n" scale="9" xChannelSelector="R" yChannelSelector="G" result="d"/>
  <feTurbulence type="fractalNoise" baseFrequency="0.25" numOctaves="1" seed="${seed + 1}" result="n2"/>
  <feDisplacementMap in="d" in2="n2" scale="3" xChannelSelector="R" yChannelSelector="G"/>
</filter>
<filter id="brush" x="-10%" y="-10%" width="120%" height="120%">
  <feTurbulence type="fractalNoise" baseFrequency="0.018" numOctaves="3" seed="${seed + 2}" result="n"/>
  <feDisplacementMap in="SourceGraphic" in2="n" scale="22" xChannelSelector="R" yChannelSelector="G" result="d"/>
  <feTurbulence type="fractalNoise" baseFrequency="0.3" numOctaves="1" seed="${seed + 3}" result="n2"/>
  <feDisplacementMap in="d" in2="n2" scale="4" xChannelSelector="R" yChannelSelector="G"/>
</filter>
<filter id="grain" filterUnits="userSpaceOnUse" x="0" y="0" width="1200" height="1800">
  <feTurbulence type="fractalNoise" baseFrequency="0.55" numOctaves="2" seed="${seed + 4}" result="n"/>
  <feColorMatrix in="n" type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  1.7 0 0 0 -0.6" result="a"/>
  <feComponentTransfer in="a" result="m"><feFuncA type="discrete" tableValues="1 1 1 1 1 1 1 1 1 0"/></feComponentTransfer>
  <feComposite in="SourceGraphic" in2="m" operator="in"/>
</filter>`;

// Dry-brush stroke between two points: solid tapered core plus loose bristles.
function brushStroke({ x0, y0, x1, y1, w, color, seed, dry = 0.35, taper = 0.25 }) {
  const r = rng(seed);
  const dx = x1 - x0, dy = y1 - y0, len = Math.hypot(dx, dy);
  const ux = dx / len, uy = dy / len, nx = -uy, ny = ux;
  const pt = (t, o) => [x0 + ux * t * len + nx * o, y0 + uy * t * len + ny * o];
  // core: tapered polygon
  const steps = 24, top = [], bot = [];
  for (let i = 0; i <= steps; i++) {
    const t = i / steps;
    const edge = Math.min(1, t / taper, (1 - t) / (taper * 0.6));
    const half = (w / 2) * (0.35 + 0.65 * Math.sqrt(Math.max(0, edge))) * (0.92 + r() * 0.08);
    top.push(pt(t, half * 0.82));
    bot.unshift(pt(t, -half * 0.82));
  }
  let out = `<path d="M${[...top, ...bot].map((p) => p.map(f).join(',')).join('L')}Z" fill="${color}"/>`;
  // bristles: thin lines across the width, some ending early for dry streaks
  const n = Math.max(14, Math.round(w / 4));
  for (let i = 0; i < n; i++) {
    const o = (i / (n - 1) - 0.5) * w;
    const t0 = r() * 0.08;
    const t1 = 1 - r() * (Math.abs(o) / w > 0.35 ? 0.5 : 0.12) - (r() < dry ? r() * 0.25 : 0);
    const a = pt(t0, o), b = pt(t1, o + (r() - 0.5) * w * 0.1);
    out += `<path d="M${f(a[0])},${f(a[1])}L${f(b[0])},${f(b[1])}" stroke="${color}" stroke-width="${f((w / n) * (1.2 + r()))}" stroke-linecap="round" opacity="${f(0.75 + r() * 0.25)}"/>`;
  }
  return `<g filter="url(#brush)">${out}</g>`;
}

// Ink splatter around a point, biased along a direction (radians) if given.
function splatter({ x, y, n = 40, spread = 160, size = 10, color, seed, dir = null, arc = Math.PI * 2 }) {
  const r = rng(seed);
  let out = '';
  for (let i = 0; i < n; i++) {
    const ang = dir === null ? r() * Math.PI * 2 : dir + (r() - 0.5) * arc;
    const d = spread * Math.pow(r(), 0.7);
    const s = size * (0.25 + Math.pow(r(), 3) * 1.6) * (1 - (d / spread) * 0.5);
    const cx = x + Math.cos(ang) * d, cy = y + Math.sin(ang) * d;
    if (r() < 0.25) {
      const deg = (ang * 180) / Math.PI;
      out += `<ellipse cx="${f(cx)}" cy="${f(cy)}" rx="${f(s * 2.2)}" ry="${f(s * 0.7)}" transform="rotate(${f(deg)} ${f(cx)} ${f(cy)})" fill="${color}"/>`;
    } else out += `<circle cx="${f(cx)}" cy="${f(cy)}" r="${f(s)}" fill="${color}"/>`;
  }
  return `<g filter="url(#rough)">${out}</g>`;
}

// Vertical kanji column, one glyph per line.
function kanjiCol(chars, { x, y, size, color, family = 'Boku', gap = 1.0 }) {
  return [...chars]
    .map((c, i) => txt(c, { x, y: y + i * size * gap, size, family, fill: color, central: true }))
    .join('');
}

// Red hanko seal with knocked-out characters (shows shirt through them).
let sealN = 0;
function seal(chars, { x, y, s, family = 'Syuku', color = RED, rough = true }) {
  const id = `seal${sealN++}`;
  const c = [...chars];
  const glyphs = c.length === 1
    ? txt(c[0], { x, y: y + s * 0.04, size: s * 0.72, family, fill: '#000', central: true })
    : c.map((g, i) => txt(g, { x, y: y - s * 0.22 + i * s * 0.44, size: s * 0.44, family, fill: '#000', central: true })).join('');
  return `<mask id="${id}" maskUnits="userSpaceOnUse" x="0" y="0" width="1200" height="1800">
    <rect x="0" y="0" width="1200" height="1800" fill="#fff"/>${glyphs}
    <rect x="${x - s / 2 + s * 0.07}" y="${y - s / 2 + s * 0.07}" width="${s * 0.86}" height="${s * 0.86}" rx="${s * 0.05}" fill="none" stroke="#000" stroke-width="${s * 0.035}"/>
  </mask>
  <g${rough ? ' filter="url(#rough)"' : ''}><rect x="${x - s / 2}" y="${y - s / 2}" width="${s}" height="${s}" rx="${s * 0.08}" fill="${color}" mask="url(#${id})"/></g>`;
}

const caption = (big, small, { y, color, size = 120, ls = 34 }) =>
  txt(big, { x: 600, y, size, ls, fill: color }) + txt(small, { x: 600, y: y + size * 0.52, size: size * 0.3, ls: ls * 0.35, fill: color });

// Small red cherry petal.
const petal = (x, y, s, rot, color) =>
  `<path d="M0,0 C${s * 0.6},${-s * 0.5} ${s * 1.2},${-s * 0.2} ${s * 1.3},0 L${s * 1.05},${s * 0.12} L${s * 1.25},${s * 0.3} C${s * 1.0},${s * 0.55} ${s * 0.4},${s * 0.5} 0,0Z" transform="translate(${f(x)},${f(y)}) rotate(${f(rot)})" fill="${color}"/>`;

// Five-petal sakura blossom.
function blossom(x, y, s, rot, color, ink) {
  let p = '';
  for (let i = 0; i < 5; i++) {
    p += `<path d="M0,0 C${-s * 0.45},${-s * 0.35} ${-s * 0.4},${-s * 0.95} ${-s * 0.12},${-s} L0,${-s * 0.86} L${s * 0.12},${-s} C${s * 0.4},${-s * 0.95} ${s * 0.45},${-s * 0.35} 0,0Z" transform="rotate(${i * 72})" fill="${color}"/>`;
  }
  return `<g transform="translate(${f(x)},${f(y)}) rotate(${f(rot)})">${p}<circle r="${f(s * 0.16)}" fill="${ink}"/></g>`;
}

// ---------- samurai silhouette (local coords: faces right, feet at y=1000, height ~1000) ----------
function samurai({ ink, accent = null, pose = 'sheathed', hat = false, flip = false, x, y, scale, seed }) {
  const r = rng(seed);
  const s = [];
  // hakama, flaring and ragged at the hem
  s.push(`<path d="M-96,420 L88,420 C122,560 172,760 218,950 L184,968 L152,946 L122,976 L88,950 L54,972 C40,870 24,770 6,710 C-12,770 -28,870 -38,972 L-72,950 L-106,976 L-142,950 L-178,968 L-218,946 C-172,760 -128,560 -96,420Z"/>`);
  // pleat cuts in the hakama
  const pleats = '';
  // torso with kataginu shoulder wings
  s.push(`<path d="M-70,150 C-96,166 -110,200 -104,240 L-96,430 L86,430 C98,350 106,262 96,202 C90,166 60,146 30,140Z"/>`);
  s.push(`<path d="M-128,160 L124,160 L106,204 L-110,204Z"/>`);
  // neck + head
  s.push(`<path d="M2,112 L48,112 L52,160 L0,160Z"/>`);
  s.push(`<ellipse cx="26" cy="74" rx="50" ry="60"/>`);
  s.push(`<path d="M70,58 L90,86 L72,94Z"/>`); // nose
  if (hat) {
    // conical straw kasa
    s.push(`<path d="M-120,64 L28,-28 L176,64 Q28,92 -120,64Z"/>`);
  } else {
    s.push(`<ellipse cx="-6" cy="14" rx="28" ry="18" transform="rotate(-25 -6 14)"/>`); // topknot
    // wind-blown hair strands
    for (let i = 0; i < 4; i++) {
      const yy = 22 + i * 14, len = 90 + r() * 70;
      s.push(`<path d="M-10,${yy} Q${-len / 2},${yy - 18 + r() * 10} ${-len},${yy + 10 + r() * 20} Q${-len / 2},${yy + 4} -4,${yy + 12}Z"/>`);
    }
  }
  // swords at the hip (saya) — long katana and shorter wakizashi
  const swords = `<path d="M150,362 Q-100,470 -370,580" stroke="${ink}" stroke-width="22" stroke-linecap="round" fill="none"/>
    <path d="M128,396 Q-60,470 -230,524" stroke="${ink}" stroke-width="17" stroke-linecap="round" fill="none"/>
    <ellipse cx="66" cy="402" rx="10" ry="30" transform="rotate(-22 66 402)"/>`;
  let arms = '', blade = '';
  if (pose === 'sheathed') {
    arms = `<path d="M78,186 Q122,300 98,396" stroke="${ink}" stroke-width="50" stroke-linecap="round" fill="none"/>`;
  } else if (pose === 'chudan') {
    // both hands forward, blade pointed at the opponent
    arms = `<path d="M78,186 Q140,260 178,316" stroke="${ink}" stroke-width="48" stroke-linecap="round" fill="none"/>
      <path d="M-60,190 Q40,290 160,330" stroke="${ink}" stroke-width="44" stroke-linecap="round" fill="none"/>`;
    blade = `<path d="M150,336 L238,288" stroke="${ink}" stroke-width="20" stroke-linecap="round"/>
      <ellipse cx="240" cy="287" rx="8" ry="30" transform="rotate(-29 240 287)"/>
      <path d="M244,280 Q450,190 640,60 Q470,206 248,296Z"/>`;
  } else if (pose === 'jodan') {
    // sword raised overhead, ready to strike
    arms = `<path d="M78,186 Q110,90 70,10" stroke="${ink}" stroke-width="48" stroke-linecap="round" fill="none"/>
      <path d="M-60,190 Q-20,90 50,20" stroke="${ink}" stroke-width="44" stroke-linecap="round" fill="none"/>`;
    blade = `<path d="M60,20 L10,-60" stroke="${ink}" stroke-width="20" stroke-linecap="round"/>
      <ellipse cx="8" cy="-64" rx="30" ry="8" transform="rotate(-58 8 -64)"/>
      <path d="M2,-70 Q-120,-230 -330,-330 Q-140,-206 14,-58Z"/>`;
  }
  // headband / scarf tails streaming behind in the accent colour
  const tails = accent
    ? `<path d="M-14,${hat ? 120 : 44} C-90,${hat ? 110 : 30} -150,${hat ? 160 : 80} -250,${hat ? 130 : 50} C-170,${hat ? 150 : 74} -100,${hat ? 140 : 64} -12,${hat ? 140 : 62}Z" fill="${accent}"/>
       <path d="M-14,${hat ? 128 : 52} C-80,${hat ? 150 : 74} -140,${hat ? 210 : 130} -220,${hat ? 210 : 128} C-150,${hat ? 196 : 112} -90,${hat ? 160 : 90} -10,${hat ? 146 : 66}Z" fill="${accent}"/>`
    : '';
  const sx = flip ? -scale : scale;
  return `<g transform="translate(${x},${y}) scale(${sx},${scale}) translate(0,-1000)">
    <g filter="url(#rough)">${tails}<g fill="${ink}">${s.join('')}${swords}${arms}${blade}</g>${pleats}</g>
  </g>`;
}

// ---------- designs ----------
const DESIGNS = {
  ronin: {
    title: 'Ronin',
    chest: { kanji: '浪人', seal: '侍' },
    art: (ink) => `
      <g filter="url(#brush)"><circle cx="600" cy="640" r="330" fill="${RED}"/></g>
      ${splatter({ x: 870, y: 420, n: 26, spread: 150, size: 9, color: RED, seed: 11, dir: -0.6, arc: 1.4 })}
      ${brushStroke({ x0: 170, y0: 1186, x1: 1040, y1: 1176, w: 46, color: ink, seed: 3 })}
      ${brushStroke({ x0: 330, y0: 1236, x1: 860, y1: 1232, w: 16, color: ink, seed: 4, dry: 0.6 })}
      ${samurai({ ink, accent: RED, hat: true, x: 600, y: 1176, scale: 0.6, seed: 7 })}
      ${splatter({ x: 400, y: 1180, n: 30, spread: 220, size: 7, color: ink, seed: 12, dir: Math.PI, arc: 1.2 })}
      ${kanjiCol('浪人', { x: 1040, y: 240, size: 150, color: ink })}
      ${seal('侍', { x: 1040, y: 530, s: 76 })}
      ${caption('RONIN', '浪人 · THE MASTERLESS SAMURAI', { y: 1430, color: ink })}`,
  },
  duel: {
    title: 'Duel',
    chest: { kanji: '決闘', seal: '武' },
    art: (ink) => `
      ${brushStroke({ x0: 90, y0: 1060, x1: 1110, y1: 1040, w: 120, color: RED, seed: 21, dry: 0.5 })}
      ${splatter({ x: 1090, y: 1040, n: 40, spread: 170, size: 10, color: RED, seed: 22, dir: -0.2, arc: 1.8 })}
      ${samurai({ ink, pose: 'chudan', x: 290, y: 1100, scale: 0.56, seed: 23 })}
      ${samurai({ ink, accent: RED, pose: 'jodan', flip: true, x: 930, y: 1100, scale: 0.56, seed: 24 })}
      ${[...Array(14)].map((_, i) => { const r = rng(90 + i); return petal(180 + r() * 840, 260 + r() * 520, 16 + r() * 10, r() * 360, RED); }).join('')}
      ${kanjiCol('決闘', { x: 600, y: 330, size: 200, color: ink })}
      ${seal('武士', { x: 600, y: 710, s: 74 })}
      ${caption('THE DUEL', '決闘 · ONLY ONE WALKS AWAY', { y: 1400, color: ink })}`,
  },
  bushido: {
    title: 'Bushido',
    chest: { kanji: '武士道', seal: '誠' },
    art: (ink) => {
      const virtues = [['義', 'RIGHTEOUSNESS'], ['勇', 'COURAGE'], ['仁', 'BENEVOLENCE'], ['礼', 'RESPECT'], ['誠', 'HONESTY'], ['名誉', 'HONOR'], ['忠義', 'LOYALTY']];
      const list = virtues.map(([k, e], i) => `
        ${txt(k, { x: 830, y: 310 + i * 150, size: k.length > 1 ? 58 : 72, family: 'Boku', fill: ink, central: true })}
        ${txt(e, { x: 830, y: 370 + i * 150, size: 26, ls: 6, fill: ink })}`).join('');
      // tsuka wrap diamonds
      const wrap = [...Array(7)].map((_, i) => `<path d="M560,${196 + i * 34} l-14,17 l14,17 l14,-17Z" fill="${RED}"/>`).join('');
      return `
      ${brushStroke({ x0: 560, y0: 120, x1: 560, y1: 1520, w: 180, color: RED, seed: 31, dry: 0.45, taper: 0.06 })}
      ${splatter({ x: 560, y: 1500, n: 40, spread: 150, size: 10, color: RED, seed: 32, dir: Math.PI / 2, arc: 3.4 })}
      ${splatter({ x: 500, y: 180, n: 20, spread: 140, size: 8, color: RED, seed: 33, dir: -Math.PI / 2 - 0.4, arc: 2 })}
      <g filter="url(#rough)">
        <rect x="532" y="170" width="56" height="270" rx="12" fill="${ink}"/>${wrap}
        <rect x="490" y="438" width="140" height="24" rx="10" fill="${ink}"/>
        <rect x="538" y="462" width="44" height="30" fill="${ink}"/>
        <path d="M538,492 L582,492 C584,900 578,1260 560,1470 C548,1260 540,900 538,492Z" fill="${ink}"/>
      </g>
      ${kanjiCol('武士道', { x: 330, y: 330, size: 180, color: ink })}
      ${seal('誠', { x: 330, y: 880, s: 74 })}
      ${list}
      ${caption('BUSHIDO', 'THE WAY OF THE WARRIOR', { y: 1700, color: ink })}`;
    },
  },
  ittoryodan: {
    title: 'Ittō Ryōdan',
    chest: { kanji: '一刀両断', seal: '斬' },
    art: (ink) => {
      // ensō drawn as a ring of short overlapping dry-brush strokes
      const cx = 560, cy = 700, R = 340;
      let enso = '';
      const a0 = -1.9, a1 = a0 + Math.PI * 1.82, n = 22;
      for (let i = 0; i < n; i++) {
        const t0 = a0 + ((a1 - a0) * i) / n, t1 = a0 + ((a1 - a0) * (i + 1.25)) / n;
        const w = 78 * (i > n - 4 ? 0.55 + 0.15 * (n - i) : 1);
        enso += brushStroke({ x0: cx + Math.cos(t0) * R, y0: cy + Math.sin(t0) * R, x1: cx + Math.cos(t1) * R, y1: cy + Math.sin(t1) * R, w, color: ink, seed: 40 + i, dry: i > n - 5 ? 0.8 : 0.2, taper: 0.02 });
      }
      // split the ensō along the slash line and push the halves apart
      const L = [1060, 220], M = [150, 1140];
      const nx = 0.72, ny = 0.69, gap = 16;
      return `
      <clipPath id="halfA"><path d="M${L[0]},${L[1]} L${M[0]},${M[1]} L0,0 Z M${L[0]},${L[1]} L1200,0 L0,0Z"/></clipPath>
      <clipPath id="halfB"><path d="M${L[0]},${L[1]} L${M[0]},${M[1]} L1200,1800 L1200,0Z"/></clipPath>
      <g transform="translate(${-nx * gap},${-ny * gap})" clip-path="url(#halfA)">${enso}</g>
      <g transform="translate(${nx * gap},${ny * gap})" clip-path="url(#halfB)">${enso}</g>
      ${brushStroke({ x0: L[0], y0: L[1], x1: M[0], y1: M[1], w: 84, color: RED, seed: 61, taper: 0.45, dry: 0.3 })}
      ${splatter({ x: 170, y: 1120, n: 46, spread: 170, size: 10, color: RED, seed: 62, dir: 2.6, arc: 1.4 })}
      ${kanjiCol('一刀両断', { x: 1060, y: 520, size: 128, color: ink })}
      ${seal('斬', { x: 1060, y: 1080, s: 76 })}
      ${caption('ITTO RYODAN', 'ONE STROKE · NO SECOND CHANCE', { y: 1450, color: ink })}`;
    },
  },
  samurai: {
    title: 'Samurai',
    chest: { kanji: '侍', seal: '武' },
    art: (ink) => `
      <g filter="url(#brush)">${txt('侍', { x: 600, y: 760, size: 980, family: 'Boku', fill: RED, central: true })}</g>
      ${splatter({ x: 940, y: 320, n: 36, spread: 200, size: 11, color: RED, seed: 71, dir: -0.5, arc: 1.6 })}
      ${splatter({ x: 260, y: 1160, n: 30, spread: 170, size: 9, color: RED, seed: 72, dir: 2.6, arc: 1.4 })}
      ${samurai({ ink, x: 640, y: 1420, scale: 0.84, seed: 73 })}
      ${brushStroke({ x0: 250, y0: 1424, x1: 1000, y1: 1418, w: 30, color: ink, seed: 74, dry: 0.5 })}
      ${splatter({ x: 300, y: 1410, n: 26, spread: 160, size: 8, color: ink, seed: 75, dir: Math.PI, arc: 1.0 })}${splatter({ x: 980, y: 1410, n: 22, spread: 140, size: 8, color: ink, seed: 76, dir: 0, arc: 1.0 })}
      ${caption('SAMURAI', '侍 · THOSE WHO SERVE', { y: 1610, color: ink })}
      ${seal('武士', { x: 1000, y: 1584, s: 70 })}`,
  },
  sakura: {
    title: 'Falling Blossoms',
    chest: { kanji: '散る桜', seal: '桜' },
    art: (ink) => {
      const r = rng(81);
      const flowers = [[290, 300, 34], [420, 250, 28], [560, 330, 36], [700, 300, 26], [820, 410, 30], [250, 430, 24], [640, 440, 22]]
        .map(([x, y, s]) => blossom(x, y, s, r() * 72, RED, ink)).join('');
      // petals drifting down; some near the edge are sliced in two
      const petals = [...Array(18)].map((_, i) => { const q = rng(200 + i); return petal(160 + q() * 860, 520 + q() * 700, 14 + q() * 10, q() * 360, RED); }).join('');
      const sliced = [[430, 846], [700, 790]].map(([x, y], i) =>
        `<g transform="rotate(-12 600 820)">${petal(x - 4, y - 14, 22, 200 + i * 40, RED)}${petal(x + 8, y + 16, 22, 20 + i * 40, RED)}</g>`).join('');
      return `
      ${brushStroke({ x0: 80, y0: 200, x1: 520, y1: 310, w: 30, color: ink, seed: 82, taper: 0.05 })}
      ${brushStroke({ x0: 500, y0: 300, x1: 900, y1: 440, w: 20, color: ink, seed: 83, taper: 0.05, dry: 0.6 })}
      ${brushStroke({ x0: 380, y0: 270, x1: 450, y1: 160, w: 12, color: ink, seed: 84 })}
      ${brushStroke({ x0: 650, y0: 360, x1: 700, y1: 470, w: 10, color: ink, seed: 85 })}
      ${flowers}
      <g transform="rotate(-12 600 820)" filter="url(#rough)">
        <rect x="900" y="800" width="230" height="40" rx="12" fill="${ink}"/>
        ${[...Array(6)].map((_, i) => `<path d="M${918 + i * 34},820 l17,-14 l17,14 l-17,14Z" fill="${RED}"/>`).join('')}
        <rect x="884" y="772" width="22" height="96" rx="8" fill="${ink}"/>
        <rect x="856" y="804" width="30" height="32" fill="${ink}"/>
        <path d="M856,798 L856,842 L150,842 C116,840 92,828 74,810 C260,806 600,800 856,798Z" fill="none" stroke="${ink}" stroke-width="7" stroke-linejoin="round"/>
        <path d="M850,812 L120,814" stroke="${ink}" stroke-width="2.5"/>
        <path d="M846,830 Q806,822 766,830 Q726,838 686,830 Q646,822 606,830 Q566,838 526,830 Q486,822 446,830 Q406,838 366,830 Q326,822 286,830 Q246,836 206,828 Q170,822 140,826" stroke="${ink}" stroke-width="3" fill="none"/>
      </g>
      ${sliced}${petals}
      ${kanjiCol('散る桜', { x: 1090, y: 1000, size: 84, color: ink })}
      ${kanjiCol('残る桜も', { x: 990, y: 1000, size: 84, color: ink })}
      ${kanjiCol('散る桜', { x: 890, y: 1000, size: 84, color: ink })}
      ${seal('桜', { x: 890, y: 1290, s: 66 })}
      ${caption('CHIRU SAKURA', 'THE BLOSSOMS THAT REMAIN WILL ALSO FALL · RYOKAN', { y: 1560, color: ink, size: 110, ls: 28 })}`;
    },
  },
};

// Left-chest piece, sized in real units (1 unit = 1/100 in): 2.2 in wide, at most ~4.5 in tall.
// The same geometry serves the DTF print (textured) and the embroidery file (flat, thread colours only).
const THREADS = {
  light: { ink: '#141212', tonal: '#D9D4CB' }, // black thread + light grey tone-on-tone for white/beige tees
  dark: { ink: '#EFE8DC', tonal: '#3A3838' }, // off-white thread + charcoal tone-on-tone for black/navy tees
};
const chestArt = (d, mode, embroidery) => {
  const { ink, tonal } = THREADS[mode];
  const k = [...d.chest.kanji];
  const S = 60, Y0 = 1000, X = 640;
  const size = k.length === 1 ? 150 : Math.min(110, Math.floor((400 - S - 16) / k.length));
  const top = Y0 + 20 + size / 2;
  const sealY = top + (k.length - 0.5) * size + 12 + S / 2;
  const bottom = sealY + S / 2;
  // slim katana running down the left side; tone-on-tone thread in the embroidery version
  const kc = embroidery ? tonal : ink;
  const kx = 532, kt = Y0 + 16;
  const katana = `<g fill="${kc}">
    <rect x="${kx - 7}" y="${kt}" width="14" height="74" rx="4"/>
    <rect x="${kx - 17}" y="${kt + 76}" width="34" height="8" rx="3"/>
    <path d="M${kx - 6},${kt + 86} L${kx + 6},${kt + 86} C${kx + 7},${kt + 200} ${kx + 4},${bottom - 60} ${kx},${bottom} C${kx - 4},${bottom - 60} ${kx - 7},${kt + 200} ${kx - 6},${kt + 86}Z"/>
  </g>${embroidery ? '' : [...Array(4)].map((_, i) => `<path d="M${kx},${kt + 10 + i * 16} l-5,6 l5,6 l5,-6Z" fill="${RED}"/>`).join('')}`;
  const inner = `${katana}
    ${kanjiCol(d.chest.kanji, { x: X, y: top, size, color: ink })}
    ${seal(d.chest.seal, { x: X, y: sealY, s: S, family: embroidery ? 'Boku' : 'Syuku', rough: !embroidery })}`;
  return { vb: [500, Y0, 220, Math.ceil(bottom - Y0 + 14)], inner };
};

const svg = (inner, seed, vb = [0, 0, 1200, 1800], grain = true) => `<svg xmlns="http://www.w3.org/2000/svg" width="${vb[2]}" height="${vb[3]}" viewBox="${vb.join(' ')}">
<defs>${filters(seed)}</defs>
<g${grain ? ' filter="url(#grain)"' : ''}>${inner}</g>
</svg>`;

let i = 0;
for (const [key, d] of Object.entries(DESIGNS)) {
  for (const [mode, { ink }] of Object.entries(MODES)) {
    sealN = 0;
    fs.writeFileSync(path.join(OUT, `${key}-${mode}.svg`), svg(d.art(ink), 100 + i));
    sealN = 0;
    const c = chestArt(d, mode, false);
    fs.writeFileSync(path.join(OUT, `${key}-chest-${mode}.svg`), svg(c.inner, 300 + i, c.vb));
    sealN = 0;
    const e = chestArt(d, mode, true);
    fs.writeFileSync(path.join(OUT, `${key}-embroidery-${mode}.svg`), svg(e.inner, 300 + i, e.vb, false));
  }
  i++;
}
fs.writeFileSync(path.join(OUT, 'designs.json'), JSON.stringify(Object.fromEntries(Object.entries(DESIGNS).map(([k, d]) => [k, { title: d.title, chest: d.chest }])), null, 2));
console.log('wrote', fs.readdirSync(OUT).length, 'files to', OUT);
