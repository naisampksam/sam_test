// HaikuFit packaging artwork: thank-you card, hang tag, sticker, tissue, tape, mailer box, poly mailer, neck labels.
// Units are 1/100 in; every piece includes 0.125 in bleed where it is cut. Rendered to 300 dpi PNG in print/.
// Usage: node build-pack.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const HERE = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.join(HERE, '..', 'src');
const opentype = require(path.join(SRC, 'node_modules', 'opentype.js'));
let playwright;
try { playwright = require('playwright'); } catch { playwright = require('/opt/node22/lib/node_modules/playwright'); }

const F = {
  bebas: opentype.loadSync(path.join(SRC, 'fonts', 'Bebas_Neue.ttf')),
  mincho: opentype.loadSync(path.join(SRC, 'fonts', 'Shippori_Mincho_B1_800.ttf')),
  boku: opentype.loadSync(path.join(SRC, 'fonts', 'Yuji_Boku.ttf')),
  inter: opentype.loadSync('/usr/share/fonts/opentype/inter/Inter-Regular.otf'),
  interSemi: opentype.loadSync('/usr/share/fonts/opentype/inter/Inter-SemiBold.otf'),
  // haikufit.in type: Oswald headings, Assistant body
  oswald: opentype.loadSync(path.join(SRC, 'fonts', 'Oswald_600.ttf')),
  oswaldMed: opentype.loadSync(path.join(SRC, 'fonts', 'Oswald_500.ttf')),
  body: opentype.loadSync(path.join(SRC, 'fonts', 'Assistant_400.ttf')),
  bodySemi: opentype.loadSync(path.join(SRC, 'fonts', 'Assistant_600.ttf')),
};
// haikufit.in theme: --ink, --washi, --color-sale (shu red), --color-muted; the logo keeps its own seal red
const INK = '#0E0E0E', PAPER = '#F3EFE6', RED = '#C8102E', LOGO_RED = '#D7261E', MUTED = '#75736F', SOFT = '#B5B0A7', EDGE = '#3A3836';
const B = 12.5; // bleed
const f = (n) => Math.round(n * 100) / 100;

function glyph(font, ch) { // [glyph, font], falling back to Inter for characters the font lacks
  const g = F[font].charToGlyph(ch);
  return g.index === 0 && ch !== ' ' ? [F.inter.charToGlyph(ch), F.inter] : [g, F[font]];
}
function adv(str, font, size, ls = 0) {
  return [...str].reduce((w, ch) => { const [g, fo] = glyph(font, ch); return w + (g.advanceWidth / fo.unitsPerEm) * size; }, 0) + ls * Math.max(0, [...str].length - 1);
}
function txt(str, { x, y, size, font = 'oswald', ls = 0, anchor = 'middle', central = false, fill = INK }) {
  const fo = F[font];
  const w = adv(str, font, size, ls);
  let cx = anchor === 'middle' ? x - w / 2 : anchor === 'end' ? x - w : x;
  const base = central ? y + ((fo.ascender + fo.descender) / 2 / fo.unitsPerEm) * size : y;
  let d = '';
  for (const ch of str) {
    const [g, gf] = glyph(font, ch);
    d += g.getPath(cx, base, size).toPathData(2);
    cx += (g.advanceWidth / gf.unitsPerEm) * size + ls;
  }
  return `<path d="${d}" fill="${fill}"/>`;
}
// Word-wrapped paragraph; returns { svg, height }.
function para(text, { x, y, width, size, font = 'body', lead = 1.5, fill = INK, anchor = 'start' }) {
  const words = text.split(' '), lines = [];
  let line = '';
  for (const w of words) {
    const t = line ? line + ' ' + w : w;
    if (adv(t, font, size) > width && line) { lines.push(line); line = w; } else line = t;
  }
  if (line) lines.push(line);
  const ax = anchor === 'middle' ? x + width / 2 : x;
  return { svg: lines.map((l, i) => txt(l, { x: ax, y: y + i * size * lead, size, font, anchor, fill })).join(''), height: lines.length * size * lead };
}
const vcol = (chars, { x, y, size, fill = INK, gap = 1.02 }) => [...chars].map((c, i) => txt(c, { x, y: y + i * size * gap, size, font: 'boku', central: true, fill })).join('');
let ids = 0;
function seal(x, y, s, chars = '俳句', color = RED) {
  const id = `s${ids++}`, k = [...chars];
  const g = k.map((c, i) => txt(c, { x, y: y - s * 0.22 + i * s * 0.44, size: s * 0.42, font: 'boku', central: true, fill: '#000' })).join('');
  return `<mask id="${id}" maskUnits="userSpaceOnUse" x="-5000" y="-5000" width="20000" height="20000"><rect x="-5000" y="-5000" width="20000" height="20000" fill="#fff"/>${g}
    <rect x="${f(x - s * 0.42)}" y="${f(y - s * 0.42)}" width="${f(s * 0.84)}" height="${f(s * 0.84)}" rx="${f(s * 0.04)}" fill="none" stroke="#000" stroke-width="${f(s * 0.03)}"/></mask>
    <rect x="${f(x - s / 2)}" y="${f(y - s / 2)}" width="${s}" height="${s}" rx="${f(s * 0.07)}" fill="${color}" mask="url(#${id})"/>`;
}
// The 5·7·5 mark: bar widths 5,7,5 units, middle bar red. (x, y) = top-left, or centred when centre=true.
function bars(x, y, u, h, gap, ink, centre = false) {
  const x0 = centre ? x - 3.5 * u : x;
  return [[5, ink], [7, RED], [5, ink]].map(([n, c], i) => `<rect x="${f(x0)}" y="${f(y + i * (h + gap))}" width="${f(n * u)}" height="${f(h)}" rx="${f(h / 2)}" fill="${c}"/>`).join('');
}
// Text set around a circle, reading clockwise from the top.
function ring(text, cx, cy, r, size, fill, ls = 0, full = false) {
  const fo = F.bebas;
  if (full) ls = (2 * Math.PI * r - adv(text, 'bebas', size, 0)) / [...text].length; // spread evenly around the whole circle
  const total = adv(text, 'bebas', size, ls);
  let a = -total / 2 / r; // start angle so the text is centred at the top
  let out = '';
  for (const ch of text) {
    const g = fo.charToGlyph(ch), w = (g.advanceWidth / fo.unitsPerEm) * size;
    const mid = a + w / 2 / r;
    out += `<path d="${g.getPath(-w / 2, 0, size).toPathData(2)}" fill="${fill}" transform="translate(${cx},${cy}) rotate(${f((mid * 180) / Math.PI)}) translate(0,${-r})"/>`;
    a += (w + ls) / r;
  }
  return out;
}
const rect = (w, h, fill) => `<rect x="0" y="0" width="${w}" height="${h}" fill="${fill}"/>`;

// Confirmed HaikuFit logo: red 俳句 seal (white kanji + inner frame) beside HAIKUFIT in Bebas Neue.
// Proportions from the approved artwork: cap height = 0.4 x seal, letter spacing 0.13 em, gap 0.15 x seal.
function sealSolid(x, y, s, ink = '#FFFFFF', bg = LOGO_RED) {
  const g = [...'俳句'].map((c, i) => txt(c, { x, y: y - s * 0.22 + i * s * 0.44, size: s * 0.42, font: 'boku', central: true, fill: ink })).join('');
  return `<rect x="${f(x - s / 2)}" y="${f(y - s / 2)}" width="${s}" height="${s}" rx="${f(s * 0.07)}" fill="${bg}"/>
    <rect x="${f(x - s * 0.42)}" y="${f(y - s * 0.42)}" width="${f(s * 0.84)}" height="${f(s * 0.84)}" rx="${f(s * 0.04)}" fill="none" stroke="${ink}" stroke-width="${f(s * 0.03)}"/>${g}`;
}
// Same seal with the kanji and frame cut out (truly transparent), for one-colour artwork on any background.
let maskId = 0;
function sealKnockout(x, y, s, fill) {
  const id = `ko${maskId++}`;
  return `<mask id="${id}" maskUnits="userSpaceOnUse" x="${f(x - s)}" y="${f(y - s)}" width="${2 * s}" height="${2 * s}">${sealSolid(x, y, s, '#000000', '#FFFFFF')}</mask>
    <rect x="${f(x - s / 2)}" y="${f(y - s / 2)}" width="${s}" height="${s}" fill="${fill}" mask="url(#${id})"/>`;
}
function logoWidth(sealH) { const size = sealH / 0.7 * 0.4; return sealH * 1.15 + adv('HAIKUFIT', 'bebas', size, size * 0.13); }
function logo(x, y, sealH, ink, anchor = 'start', sealArt = null) { // y = vertical centre; sealArt(cx, cy, s) overrides the red seal
  const size = sealH / 0.7 * 0.4, w = logoWidth(sealH);
  const x0 = anchor === 'middle' ? x - w / 2 : anchor === 'end' ? x - w : x;
  return (sealArt || sealSolid)(x0 + sealH / 2, y, sealH) + txt('HAIKUFIT', { x: x0 + sealH * 1.15, y: y + size * 0.35, size, font: 'bebas', ls: size * 0.13, anchor: 'start', fill: ink });
}

// ---------- Dojo Run game (haikufit.in/pages/dojo-run) ----------
// QR code to the game page, plus the seigaiha wave pattern used on the website's dark sections.
const GAME = path.join(HERE, 'game');
const QR = JSON.parse(fs.readFileSync(path.join(GAME, 'qr-dojo-run.json'), 'utf8'));
function qr(x, y, size, ink = INK) { // QR modules as vector squares (quiet zone not included)
  const n = QR.rows.length, m = size / n;
  let d = '';
  QR.rows.forEach((row, r) => [...row].forEach((v, c) => { if (v === '1') d += `M${f(x + c * m)},${f(y + r * m)}h${f(m + 0.02)}v${f(m + 0.02)}h${f(-m - 0.02)}Z`; }));
  return `<path d="${d}" fill="${ink}"/>`;
}
// Same tile as the site's --seigaiha (48 x 24, washi rings on ink); tw = tile width in 1/100 in.
let patId = 0;
function seigaiha(w, h, tw, opacity = 0.1) {
  const id = `sg${patId++}`;
  const rings = [[0, 0], [48, 0], [24, 12], [0, 24], [48, 24]].map(([cx, cy]) => [22, 16, 10, 4].map((r) => `<circle cx="${cx}" cy="${cy}" r="${r}"/>`).join('')).join('');
  return `<defs><pattern id="${id}" patternUnits="userSpaceOnUse" width="${tw}" height="${tw / 2}"><svg width="${tw}" height="${tw / 2}" viewBox="0 0 48 24"><g fill="${INK}" stroke="${PAPER}" stroke-opacity="${opacity}">${rings}</g></svg></pattern></defs>
    <rect x="0" y="0" width="${w}" height="${h}" fill="url(#${id})"/>`;
}
const dark = (w, h, tw) => rect(w, h, INK) + seigaiha(w, h, tw);
// The website's card: outlined panel with a solid red offset shadow.
const panel = (x, y, w, h, off = 4) => `<rect x="${x + off}" y="${y + off}" width="${w}" height="${h}" fill="${RED}"/><rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${INK}" stroke="${PAPER}" stroke-width="1"/>`;
const rule = (x, y, w = 24, h = 2) => `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${RED}"/>`;

// ---------- pieces: [name, width, height (incl. bleed), trim description, svg body] ----------
const P = {};

// Thank-you card 4x6 in
// Black and red, on the website's system: ink with the seigaiha wave, washi text, shu-red accents, Oswald + Assistant.
// Cards keep a 0.375 in safe margin (content x 50-375).
P['thankyou-card-front'] = [425, 625, '4 x 6 in + 0.125 in bleed', `
  ${dark(425, 625, 34)}
  ${logo(212.5, 92, 28, PAPER, 'middle')}
  <circle cx="212.5" cy="292" r="96" fill="${RED}"/>
  ${txt('THANK YOU', { x: 212.5, y: 482, size: 42, ls: 8, fill: PAPER })}
  ${rule(200.5, 498)}
  ${txt('FOR WEARING THE POEM', { x: 212.5, y: 528, size: 10, ls: 4, font: 'oswaldMed', fill: SOFT })}`];

// Back: a handwritten name line, then the two gifts as a poster (red suns, big numbers), QR strip at the foot.
P['thankyou-card-back'] = [425, 625, '4 x 6 in + 0.125 in bleed; name line for handwriting (white gel pen on uncoated stock); QR to haikufit.in/pages/dojo-run', (() => {
  const c1 = 128, c2 = 297, cy = 318, R = 54;
  const col = (cx, label, body) => txt(label, { x: cx, y: cy + R + 32, size: 14, ls: 2.5, fill: PAPER })
    + para(body, { x: cx - 74, y: cy + R + 52, width: 148, size: 9.5, lead: 1.5, fill: SOFT, anchor: 'middle' }).svg;
  return `${dark(425, 625, 34)}
    ${logo(212.5, 62, 22, PAPER, 'middle')}
    ${txt('DEAR', { x: 80, y: 128, size: 11, ls: 3, font: 'oswaldMed', anchor: 'start', fill: SOFT })}
    <rect x="116" y="128" width="229" height="0.8" fill="${PAPER}" fill-opacity="0.55"/>
    ${['Thank you for choosing HaikuFit.', 'Here are two gifts for your next order.'].map((l, i) => txt(l, { x: 212.5, y: 162 + i * 16, size: 10.5, font: 'body', fill: SOFT })).join('')}
    ${rule(200.5, 196)}
    ${txt('TWO GIFTS FOR YOU', { x: 212.5, y: 236, size: 20, ls: 4, fill: PAPER })}
    <circle cx="${c1}" cy="${cy}" r="${R}" fill="${RED}"/>
    ${txt('GET', { x: c1, y: cy - 20, size: 9, ls: 2.5, font: 'oswaldMed', fill: PAPER })}
    ${txt('₹100', { x: c1, y: cy + 12, size: 34, ls: 0.5, fill: PAPER })}
    ${txt('STORE CREDIT', { x: c1, y: cy + 28, size: 8, ls: 2, font: 'oswaldMed', fill: PAPER })}
    <circle cx="${c2}" cy="${cy}" r="${R}" fill="none" stroke="${RED}" stroke-width="2.2"/>
    ${txt('WIN UP TO', { x: c2, y: cy - 20, size: 9, ls: 2.5, font: 'oswaldMed', fill: PAPER })}
    ${txt('₹150', { x: c2, y: cy + 12, size: 34, ls: 0.5, fill: PAPER })}
    ${txt('OFF', { x: c2, y: cy + 28, size: 8, ls: 2, font: 'oswaldMed', fill: PAPER })}
    <rect x="212.1" y="${cy - 40}" width="0.8" height="160" fill="${EDGE}"/>
    ${col(c1, 'PHOTO REVIEW', 'Post a photo review of your tee on haikufit.in. Spend the credit anytime.')}
    ${col(c2, 'SECOND CHANCE', 'Play Dojo Run once more this month and win up to ₹150 off.')}
    <rect x="50" y="482" width="325" height="0.8" fill="${EDGE}"/>
    <rect x="50" y="496" width="56" height="56" fill="${PAPER}"/>
    ${qr(54, 500, 48)}
    ${txt('SCAN TO PLAY DOJO RUN', { x: 120, y: 517, size: 13, ls: 2.5, anchor: 'start', fill: PAPER })}
    ${txt('haikufit.in/pages/dojo-run', { x: 120, y: 533, size: 8.5, font: 'body', anchor: 'start', fill: SOFT })}
    ${txt('Terms apply  ·  haikufit.in/pages/offers-rewards', { x: 120, y: 547, size: 7.5, font: 'body', anchor: 'start', fill: MUTED })}`;
})()];

// Hang tag 2 x 3.5 in, 0.25 in hole centred 0.45 in below the top edge (kept clear)
P['hangtag-front'] = [225, 375, '2 x 3.5 in + 0.125 in bleed, 0.25 in hole at top centre', `
  ${dark(225, 375, 30)}
  ${logo(112.5, 205, 44, PAPER, 'middle')}
  ${rule(100.5, 258)}`];
P['hangtag-back'] = [225, 375, '2 x 3.5 in + 0.125 in bleed, 0.25 in hole at top centre', `
  ${rect(225, 375, INK)}
  <circle cx="112.5" cy="138" r="22" fill="${RED}"/>
  ${txt('WORN HARD.', { x: 112.5, y: 200, size: 20, ls: 2.5, fill: PAPER })}
  ${txt('KEPT FOR YEARS.', { x: 112.5, y: 224, size: 20, ls: 2.5, fill: PAPER })}
  ${rule(100.5, 240)}
  ${txt('WASH COLD  ·  INSIDE OUT', { x: 112.5, y: 270, size: 8, ls: 2, font: 'oswaldMed', fill: SOFT })}
  ${txt('haikufit.in', { x: 112.5, y: 296, size: 9, font: 'body', fill: SOFT })}
  ${logo(112.5, 336, 18, PAPER, 'middle')}`];

// Round sticker 2 in (die-cut circle), bleed to 2.25 in
P['sticker-round'] = [225, 225, '2 in circle + 0.125 in bleed (die-cut)', `
  <clipPath id="stk"><circle cx="112.5" cy="112.5" r="112.5"/></clipPath><g clip-path="url(#stk)">${dark(225, 225, 26)}</g>
  <circle cx="112.5" cy="112.5" r="72" fill="none" stroke="${RED}" stroke-width="2.2"/>
  ${ring('HAIKUFIT · HAIKUFIT · HAIKUFIT · ', 112.5, 112.5, 82, 15.5, PAPER, 0, true)}
  ${sealSolid(112.5, 112.5, 74)}`];

// Tissue paper: seamless 8 in repeat, printed on black tissue
P['tissue-pattern-tile'] = [800, 800, '8 x 8 in seamless repeat tile (no bleed), black tissue', (() => {
  let els = '';
  for (let j = 0; j < 4; j++) for (let i = 0; i < 4; i++) {
    const cx = 100 + i * 200 + (j % 2) * 100, cy = 100 + j * 200;
    for (const dx of [-800, 0, 800]) for (const dy of [-800, 0, 800]) {
      const x = cx + dx, y = cy + dy;
      if (x < -90 || x > 890 || y < -90 || y > 890) continue;
      els += (i + j) % 2 === 0 ? sealSolid(x, y, 40) : txt('HAIKUFIT', { x, y: y + 7, size: 22, font: 'bebas', ls: 4, fill: PAPER });
    }
  }
  return `${dark(800, 800, 50)}${els}`;
})()];

// Packing tape: 2 in wide, 12 in repeat strip (3 x 4 in units)
P['packing-tape-strip'] = [1200, 200, '2 in wide tape, 12 in repeat (no bleed)', `
  ${rect(1200, 200, INK)}
  ${[0, 1, 2].map((k) => logo(k * 400 + 200, 100, 70, PAPER, 'middle')).join('')}`];

// Mailer box 12 x 9 x 3 in: face artwork to place on the box maker's dieline
P['mailer-box-lid-outside'] = [1225, 925, '12 x 9 in lid face + 0.125 in bleed', `
  ${dark(1225, 925, 64)}
  ${logo(612.5, 462.5, 200, PAPER, 'middle')}
  ${rule(572.5, 630, 80, 5)}`];
P['mailer-box-lid-inside'] = [1225, 925, '12 x 9 in inside of lid + 0.125 in bleed', `
  ${dark(1225, 925, 64)}
  <circle cx="975" cy="440" r="185" fill="${RED}"/>
  ${txt('A FEW TRUE WORDS,', { x: 150, y: 350, size: 62, ls: 4, anchor: 'start', fill: PAPER })}
  ${txt('WORN CLOSE.', { x: 150, y: 430, size: 62, ls: 4, anchor: 'start', fill: PAPER })}
  ${rule(150, 470, 60, 5)}
  ${para('Every HaikuFit design carries a story of courage, told in a few true words. Thank you for wearing it.', { x: 150, y: 530, width: 470, size: 21, lead: 1.5, fill: SOFT }).svg}
  ${logo(150, 790, 40, PAPER)}`];
P['mailer-box-front'] = [1225, 325, '12 x 3 in front panel + 0.125 in bleed', `
  ${dark(1225, 325, 64)}${logo(612.5, 162.5, 140, PAPER, 'middle')}`];
P['mailer-box-side'] = [925, 325, '9 x 3 in side panel + 0.125 in bleed', `
  ${dark(925, 325, 64)}
  ${sealSolid(462.5, 128, 92)}
  ${txt('OPEN SLOWLY  ·  THE POEM IS INSIDE', { x: 462.5, y: 250, size: 22, ls: 7, font: 'oswaldMed', fill: SOFT })}`];

// Poly mailer 12 x 15.5 in (front), back with a 6 x 4 in label panel
P['poly-mailer-front'] = [1225, 1575, '12 x 15.5 in + 0.125 in bleed (top 2 in = seal flap)', `
  ${dark(1225, 1575, 80)}
  ${txt('OPEN SLOWLY  ·  THE POEM IS INSIDE', { x: 612.5, y: 150, size: 26, ls: 8, font: 'oswaldMed', fill: SOFT })}
  <circle cx="612.5" cy="700" r="390" fill="${RED}"/>
  ${logo(612.5, 1330, 160, PAPER, 'middle')}`];
P['poly-mailer-back'] = [1225, 1575, '12 x 15.5 in + 0.125 in bleed, white 6 x 4 in shipping label panel', `
  ${dark(1225, 1575, 80)}
  ${logo(612.5, 260, 90, PAPER, 'middle')}
  <rect x="312.5" y="560" width="600" height="400" fill="#FFFFFF"/>
  ${txt('SHIPPING LABEL', { x: 612.5, y: 772, size: 30, ls: 8, font: 'oswaldMed', fill: '#C9C2B8' })}
  ${txt('FROM: HAIKUFIT  ·  HAIKUFIT.IN', { x: 612.5, y: 1060, size: 26, ls: 6, font: 'oswaldMed', fill: SOFT })}
  ${sealSolid(612.5, 1300, 90)}`];

// Tagless neck labels printed inside the back neck: 3 x 1.5 in, transparent, per size and ink
for (const size of ['S', 'M', 'L', 'XL', 'XXL']) {
  for (const [mode, ink] of [['light-tee', INK], ['dark-tee', PAPER]]) {
    P[`neck-label-${size}-${mode}`] = [300, 150, '3 x 1.5 in print inside back neck (transparent, no bleed)', `
      ${logo(24, 62, 46, ink)}
      ${txt('WASH COLD · INSIDE OUT', { x: 24, y: 118, size: 11, ls: 2, font: 'oswaldMed', anchor: 'start', fill: ink })}
      <rect x="208" y="30" width="1.6" height="94" fill="${ink}"/>
      ${txt(size, { x: 256, y: 104, size: size.length > 2 ? 40 : 56, fill: ink })}`];
  }
}

// The confirmed logo itself: 10 in wide transparent lockups and the seal alone
P['logo-haikufit-on-light-bg'] = [1000, 320, '10 in wide, transparent, black wordmark', logo(500, 160, 300, INK, 'middle')];
P['logo-haikufit-on-dark-bg'] = [1000, 320, '10 in wide, transparent, white wordmark', logo(500, 160, 300, '#FFFFFF', 'middle')];
P['logo-haikufit-seal'] = [320, 320, '3.2 in seal, transparent', sealSolid(160, 160, 300)];

// Black-and-white logo files for the trademark application (designs/trademark): solid backgrounds plus a
// transparent version where the kanji and frame are cut out of the seal.
const TM = {};
const mono = (glyph, bg) => (cx, cy, s) => sealSolid(cx, cy, s, glyph, bg);
TM['haikufit-logo-black-on-white'] = [1100, 420, 'full logo, black on white', `${rect(1100, 420, '#FFFFFF')}${logo(550, 210, 300, '#000000', 'middle', mono('#FFFFFF', '#000000'))}`];
TM['haikufit-logo-white-on-black'] = [1100, 420, 'full logo, white on black', `${rect(1100, 420, '#000000')}${logo(550, 210, 300, '#FFFFFF', 'middle', mono('#000000', '#FFFFFF'))}`];
TM['haikufit-logo-black-transparent'] = [1100, 420, 'full logo, black, transparent background', logo(550, 210, 300, '#000000', 'middle', (cx, cy, s) => sealKnockout(cx, cy, s, '#000000'))];
{
  const size = 220, w = Math.ceil(adv('HAIKUFIT', 'bebas', size, size * 0.13) + 200);
  TM['haikufit-wordmark-black-on-white'] = [w, 360, 'brand name only, black on white', `${rect(w, 360, '#FFFFFF')}${txt('HAIKUFIT', { x: 100, y: 180 + size * 0.35, size, ls: size * 0.13, anchor: 'start', fill: '#000000' })}`];
}
TM['haikufit-seal-black-on-white'] = [440, 440, 'seal only, black on white', `${rect(440, 440, '#FFFFFF')}${sealSolid(220, 220, 320, '#FFFFFF', '#000000')}`];

// ---------- render ----------
const browser = await playwright.chromium.launch();
async function render(pieces, out) {
  fs.mkdirSync(out, { recursive: true });
  const tmp = fs.mkdtempSync(path.join(HERE, '.tmp-'));
  const spec = {};
  for (const [name, [w, h, trim, body]] of Object.entries(pieces)) {
    const file = path.join(tmp, `${name}.svg`);
    fs.writeFileSync(file, `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">${body}</svg>`);
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 3 });
    const page = await ctx.newPage();
    await page.goto(pathToFileURL(file).href, { waitUntil: 'load' });
    await page.screenshot({ path: path.join(out, `${name}.png`), omitBackground: true });
    await ctx.close();
    spec[name] = { px: [w * 3, h * 3], inches: [w / 100, h / 100], trim };
  }
  fs.rmSync(tmp, { recursive: true });
  fs.writeFileSync(path.join(out, 'specs.json'), JSON.stringify(spec, null, 2));
  console.log('rendered', Object.keys(pieces).length, 'pieces to', out);
}
await render(P, path.join(HERE, 'print'));
await render(TM, path.join(HERE, '..', 'trademark'));
await browser.close();
