// HaikuFit logo concepts. All text is converted to outlines.
// Usage: node build-logo.mjs   -> svg/*.svg (each concept in ink-on-light and paper-on-dark versions)
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const opentype = require(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src', 'node_modules', 'opentype.js'));
const HERE = path.dirname(fileURLToPath(import.meta.url));
const FONTS = path.join(HERE, '..', 'src', 'fonts');
const FONT = {
  bebas: opentype.loadSync(path.join(FONTS, 'Bebas_Neue.ttf')),
  mincho: opentype.loadSync(path.join(FONTS, 'Shippori_Mincho_B1_800.ttf')),
  boku: opentype.loadSync(path.join(FONTS, 'Yuji_Boku.ttf')),
};
const RED = '#D7261E';
const MODES = { light: { ink: '#141212', bg: '#EFE8DC' }, dark: { ink: '#EFE8DC', bg: '#141212' } };
const f = (n) => Math.round(n * 10) / 10;

// Outlined text; returns { d, width }. anchor: start | middle | end.
function txt(str, { x, y, size, font = 'bebas', ls = 0, anchor = 'middle', central = false }) {
  const F = FONT[font];
  const glyphs = [...str].map((ch) => F.charToGlyph(ch));
  const adv = glyphs.map((g) => (g.advanceWidth / F.unitsPerEm) * size);
  const width = adv.reduce((a, b) => a + b, 0) + ls * (glyphs.length - 1);
  let cx = anchor === 'middle' ? x - width / 2 : anchor === 'end' ? x - width : x;
  const base = central ? y + ((F.ascender + F.descender) / 2 / F.unitsPerEm) * size : y;
  let d = '';
  glyphs.forEach((g, i) => { d += g.getPath(cx, base, size).toPathData(1); cx += adv[i] + ls; });
  return { d, width };
}
const path_ = (d, fill) => `<path d="${d}" fill="${fill}"/>`;

// Hanko seal with knocked-out vertical kanji.
function seal(x, y, s, chars, id) {
  const k = [...chars];
  const glyphs = k.map((c, i) => path_(txt(c, { x, y: y - s * 0.22 + i * s * 0.44, size: s * 0.42, font: 'boku', central: true }).d, '#000')).join('');
  return `<mask id="${id}" maskUnits="userSpaceOnUse" x="-2000" y="-2000" width="6000" height="6000"><rect x="-2000" y="-2000" width="6000" height="6000" fill="#fff"/>${glyphs}
    <rect x="${f(x - s * 0.42)}" y="${f(y - s * 0.42)}" width="${f(s * 0.84)}" height="${f(s * 0.84)}" rx="${f(s * 0.04)}" fill="none" stroke="#000" stroke-width="${f(s * 0.03)}"/></mask>
    <rect x="${f(x - s / 2)}" y="${f(y - s / 2)}" width="${s}" height="${s}" rx="${f(s * 0.07)}" fill="${RED}" mask="url(#${id})"/>`;
}

// Open brush circle (ensō) as one tapered filled shape: thick start, thinning tail, small gap.
function enso(cx, cy, R, w) {
  const a0 = -1.75, span = Math.PI * 1.84, n = 120, outer = [], inner = [];
  for (let i = 0; i <= n; i++) {
    const t = i / n, a = a0 + span * t;
    const ww = w * (t < 0.06 ? 0.55 + (t / 0.06) * 0.45 : t > 0.78 ? Math.max(0.12, 1 - (t - 0.78) / 0.22 * 0.88) : 1) * (1 + 0.06 * Math.sin(t * 9));
    outer.push([cx + Math.cos(a) * (R + ww / 2), cy + Math.sin(a) * (R + ww / 2)]);
    inner.unshift([cx + Math.cos(a) * (R - ww / 2), cy + Math.sin(a) * (R - ww / 2)]);
  }
  return `M${[...outer, ...inner].map((p) => p.map(f).join(',')).join('L')}Z`;
}

const CONCEPTS = {
  // 1. Seal: red hanko with 俳句 above the wordmark
  seal: { vb: [0, 0, 1000, 760], draw: ({ ink }, id) => `
    ${seal(500, 230, 300, '俳句', id)}
    ${path_(txt('HAIKUFIT', { x: 500, y: 590, size: 190, ls: 26 }).d, ink)}
    ${path_(txt('JAPANESE STREETWEAR', { x: 500, y: 668, size: 46, ls: 17 }).d, ink)}` },
  // 2. 5·7·5: the syllable pattern of a haiku as three bars, middle bar red
  bars: { vb: [0, 0, 1600, 520], draw: ({ ink }) => {
    const u = 52, x0 = 140, h = 52, gap = 40, y0 = 140;
    const bars = [[5, ink], [7, RED], [5, ink]].map(([n, c], i) => `<rect x="${x0}" y="${y0 + i * (h + gap)}" width="${n * u}" height="${h}" rx="${h / 2}" fill="${c}"/>`).join('');
    return `${bars}
    ${path_(txt('HAIKUFIT', { x: 600, y: 312, size: 150, font: 'mincho', ls: 8, anchor: 'start' }).d, ink)}
    ${path_(txt('5 · 7 · 5   JAPANESE STREETWEAR', { x: 606, y: 392, size: 38, ls: 12, anchor: 'start' }).d, ink)}`;
  } },
  // 3. Ensō: brush circle with a red seal dot, wordmark below
  enso: { vb: [0, 0, 1000, 900], draw: ({ ink }) => `
    ${path_(enso(500, 320, 220, 62), ink)}
    <rect x="${500 + 150}" y="${320 + 120}" width="78" height="78" rx="6" fill="${RED}"/>
    ${path_(txt('HAIKUFIT', { x: 500, y: 760, size: 150, font: 'mincho', ls: 14 }).d, ink)}` },
  // 4. Cut: wordmark sliced by a katana; halves slide along the cut, a red glint shows only inside the letters
  cut: { vb: [0, 0, 1600, 520], draw: ({ ink }, id) => {
    const word = txt('HAIKUFIT', { x: 800, y: 360, size: 300, ls: 22 }).d;
    const A = [672, 100], B = [790, 400];
    const L = Math.hypot(B[0] - A[0], B[1] - A[1]), ux = (B[0] - A[0]) / L, uy = (B[1] - A[1]) / L, nx = -uy, ny = ux;
    const P1 = [A[0] - ux * 3000, A[1] - uy * 3000], P2 = [B[0] + ux * 3000, B[1] + uy * 3000];
    const side = (k) => `M${f(P1[0])},${f(P1[1])} L${f(P2[0])},${f(P2[1])} L${f(P2[0] + nx * 3000 * k)},${f(P2[1] + ny * 3000 * k)} L${f(P1[0] + nx * 3000 * k)},${f(P1[1] + ny * 3000 * k)}Z`;
    const g = 7, s = 14; // half-gap across the cut, slide along it
    return `<clipPath id="${id}a"><path d="${side(1)}"/></clipPath><clipPath id="${id}b"><path d="${side(-1)}"/></clipPath>
      <clipPath id="${id}w"><path d="${word}"/></clipPath>
      <g clip-path="url(#${id}a)"><g transform="translate(${f(nx * g - ux * s)},${f(ny * g - uy * s)})">${path_(word, ink)}</g></g>
      <g clip-path="url(#${id}b)"><g transform="translate(${f(-nx * g + ux * s)},${f(-ny * g + uy * s)})">${path_(word, ink)}</g></g>
      <g clip-path="url(#${id}w)"><path d="M${f(P1[0])},${f(P1[1])} L${f(P2[0])},${f(P2[1])}" stroke="${RED}" stroke-width="7"/></g>
      ${path_(txt('JAPANESE STREETWEAR', { x: 800, y: 450, size: 42, ls: 16 }).d, ink)}`;
  } },
};

// Small square marks for favicons, labels, nape prints.
const MARKS = {
  'seal-mark': { vb: [0, 0, 400, 400], draw: (m, id) => seal(200, 200, 340, '俳句', id) },
  'bars-mark': { vb: [0, 0, 400, 400], draw: ({ ink }) => [[5, ink], [7, RED], [5, ink]].map(([n, c], i) => `<rect x="${200 - 7 * 22}" y="${110 + i * 70}" width="${n * 44}" height="44" rx="22" fill="${c}"/>`).join('') },
  'enso-mark': { vb: [0, 0, 400, 400], draw: ({ ink }) => `${path_(enso(200, 200, 140, 40), ink)}<rect x="296" y="276" width="50" height="50" rx="4" fill="${RED}"/>` },
};

const OUT = path.join(HERE, 'svg');
fs.mkdirSync(OUT, { recursive: true });
let n = 0;
for (const [name, c] of Object.entries({ ...CONCEPTS, ...MARKS })) {
  for (const [mode, colours] of Object.entries(MODES)) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${c.vb[2]}" height="${c.vb[3]}" viewBox="${c.vb.join(' ')}">\n<!-- HaikuFit logo: ${name}, ${mode} (${colours.ink} + ${RED}); transparent background -->\n${c.draw(colours, `m${n++}`)}\n</svg>\n`;
    fs.writeFileSync(path.join(OUT, `haikufit-${name}-${mode}.svg`), svg);
  }
}
console.log('wrote', fs.readdirSync(OUT).length, 'logo files');
