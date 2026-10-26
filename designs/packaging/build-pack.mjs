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
};
const INK = '#141212', PAPER = '#EFE8DC', RED = '#D7261E', MUTED = '#7A706A';
const B = 12.5; // bleed
const f = (n) => Math.round(n * 100) / 100;

function adv(str, font, size, ls = 0) {
  const fo = F[font];
  return [...str].reduce((w, ch) => w + (fo.charToGlyph(ch).advanceWidth / fo.unitsPerEm) * size, 0) + ls * Math.max(0, [...str].length - 1);
}
function txt(str, { x, y, size, font = 'bebas', ls = 0, anchor = 'middle', central = false, fill = INK }) {
  const fo = F[font];
  const w = adv(str, font, size, ls);
  let cx = anchor === 'middle' ? x - w / 2 : anchor === 'end' ? x - w : x;
  const base = central ? y + ((fo.ascender + fo.descender) / 2 / fo.unitsPerEm) * size : y;
  let d = '';
  for (const ch of str) {
    const g = fo.charToGlyph(ch);
    d += g.getPath(cx, base, size).toPathData(2);
    cx += (g.advanceWidth / fo.unitsPerEm) * size + ls;
  }
  return `<path d="${d}" fill="${fill}"/>`;
}
// Word-wrapped paragraph; returns { svg, height }.
function para(text, { x, y, width, size, font = 'inter', lead = 1.5, fill = INK, anchor = 'start' }) {
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
function sealSolid(x, y, s, glyph = '#FFFFFF', bg = RED) {
  const g = [...'俳句'].map((c, i) => txt(c, { x, y: y - s * 0.22 + i * s * 0.44, size: s * 0.42, font: 'boku', central: true, fill: glyph })).join('');
  return `<rect x="${f(x - s / 2)}" y="${f(y - s / 2)}" width="${s}" height="${s}" rx="${f(s * 0.07)}" fill="${bg}"/>
    <rect x="${f(x - s * 0.42)}" y="${f(y - s * 0.42)}" width="${f(s * 0.84)}" height="${f(s * 0.84)}" rx="${f(s * 0.04)}" fill="none" stroke="${glyph}" stroke-width="${f(s * 0.03)}"/>${g}`;
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
  return (sealArt || sealSolid)(x0 + sealH / 2, y, sealH) + txt('HAIKUFIT', { x: x0 + sealH * 1.15, y: y + size * 0.35, size, ls: size * 0.13, anchor: 'start', fill: ink });
}

// ---------- Dojo Run game (haikufit.in/pages/dojo-run) ----------
// Real phone screenshots of the game (390 x 844 pt @3x) and a QR code to the game page.
const GAME = path.join(HERE, 'game');
const SHOTS = { start: 'dojo-run-start.png', play: 'dojo-run-play.png', reward: 'dojo-run-reward.png' };
const QR = JSON.parse(fs.readFileSync(path.join(GAME, 'qr-dojo-run.json'), 'utf8'));
function qr(x, y, size, ink = INK) { // QR modules as vector squares (quiet zone not included)
  const n = QR.rows.length, m = size / n;
  let d = '';
  QR.rows.forEach((row, r) => [...row].forEach((v, c) => { if (v === '1') d += `M${f(x + c * m)},${f(y + r * m)}h${f(m + 0.02)}v${f(m + 0.02)}h${f(-m - 0.02)}Z`; }));
  return `<path d="${d}" fill="${ink}"/>`;
}
// A phone (frame + screenshot) drawn at width w, top-left (x, y).
function phone(x, y, w, shot, angle = 0) {
  const b = w * 0.045, sw = w - 2 * b, sh = sw * 2532 / 1170, h = sh + 2 * b, id = `ph${ids++}`;
  const href = pathToFileURL(path.join(GAME, SHOTS[shot])).href;
  return `<g transform="rotate(${angle} ${f(x + w / 2)} ${f(y + h / 2)})">
    <rect x="${f(x)}" y="${f(y)}" width="${f(w)}" height="${f(h)}" rx="${f(w * 0.14)}" fill="#0B0A0A" stroke="#4A4542" stroke-width="${f(w * 0.012)}"/>
    <clipPath id="${id}"><rect x="${f(x + b)}" y="${f(y + b)}" width="${f(sw)}" height="${f(sh)}" rx="${f(w * 0.1)}"/></clipPath>
    <g clip-path="url(#${id})"><rect x="${f(x + b)}" y="${f(y + b)}" width="${f(sw)}" height="${f(sh)}" fill="#F3EFE6"/>
      <image href="${href}" x="${f(x + b)}" y="${f(y + b + sw * 0.1)}" width="${f(sw)}" height="${f(sh)}" preserveAspectRatio="xMidYMid slice"/></g>
    <rect x="${f(x + w * 0.39)}" y="${f(y + b + w * 0.022)}" width="${f(w * 0.22)}" height="${f(w * 0.055)}" rx="${f(w * 0.0275)}" fill="#0B0A0A"/></g>`;
}
const TIERS = [['₹50 OFF', '300 PTS'], ['₹100 OFF', '600 PTS'], ['₹150 OFF', '1000 PTS']];
const TERMS = 'One reward per order. Minimum order ₹999. HAIKUFIT may change or end this offer at any time.';

// ---------- pieces: [name, width, height (incl. bleed), trim description, svg body] ----------
const P = {};

// Thank-you card 4x6 in
// Clean system for both sides: cream stock, 0.375 in safe margin (content x 50-375), one left edge, hairline rules.
const LINE = '#CFC6B8';
P['thankyou-card-front'] = [425, 625, '4 x 6 in + 0.125 in bleed', `
  ${rect(425, 625, PAPER)}
  ${logo(212.5, 92, 30, INK, 'middle')}
  <circle cx="212.5" cy="232" r="40" fill="${RED}"/>
  ${vcol('ありがとう', { x: 212.5, y: 312, size: 30, fill: INK })}
  <rect x="200.5" y="466" width="24" height="1.5" fill="${RED}"/>
  ${txt('THANK YOU', { x: 212.5, y: 512, size: 40, ls: 9 })}
  ${txt('FOR WEARING THE POEM', { x: 212.5, y: 538, size: 11, ls: 4.5, fill: MUTED })}`];

P['thankyou-card-back'] = [425, 625, '4 x 6 in + 0.125 in bleed, QR code links to haikufit.in/pages/dojo-run', (() => {
  const x = 50, r = 375, cx = 250;
  const msg = para('Every HaikuFit design carries real Japanese, each word checked for its meaning, and is printed to be worn hard and kept for years. We hope it becomes a favourite.', { x, y: 156, width: 325, size: 10, lead: 1.6, fill: '#3A3532' });
  const terms = para(TERMS, { x, y: 518, width: 325, size: 7, lead: 1.5, fill: MUTED });
  const win = 'WIN UP TO ';
  return `${rect(425, 625, PAPER)}
    ${logo(x, 70, 26, INK)}
    ${txt('haikufit.in', { x: r, y: 74, size: 8.5, font: 'inter', anchor: 'end', fill: MUTED })}
    ${txt('THANK YOU FOR YOUR ORDER', { x, y: 136, size: 26, ls: 3, anchor: 'start' })}
    ${msg.svg}
    <rect x="${x}" y="212" width="325" height="0.75" fill="${LINE}"/>
    ${txt('道場', { x, y: 240, size: 11, font: 'mincho', ls: 2, anchor: 'start', fill: RED })}
    ${txt('DOJO RUN  ·  THE HAIKUFIT GAME', { x: x + 28, y: 240, size: 11, ls: 2.5, anchor: 'start', fill: MUTED })}
    ${txt('PLAY THE GAME.', { x, y: 272, size: 30, ls: 1.5, anchor: 'start' })}
    ${txt(win, { x, y: 302, size: 30, ls: 1.5, anchor: 'start' })}
    ${txt('₹150 OFF.', { x: x + adv(win, 'bebas', 30, 1.5), y: 302, size: 30, ls: 1.5, anchor: 'start', fill: RED })}
    ${phone(x, 322, 84, 'play')}
    ${phone(x + 94, 322, 84, 'reward')}
    ${TIERS.map(([t, p], i) => { const y = 340 + i * 22; return txt(t, { x: cx, y, size: 15, ls: 1, anchor: 'start' }) + txt(p, { x: r, y, size: 10, ls: 1.5, anchor: 'end', fill: MUTED }) + `<rect x="${cx}" y="${y + 7}" width="${r - cx}" height="0.75" fill="${LINE}"/>`; }).join('')}
    ${qr(cx, 414, 68)}
    ${txt('SCAN', { x: cx + 80, y: 436, size: 15, ls: 2, anchor: 'start' })}
    ${txt('TO PLAY', { x: cx + 80, y: 452, size: 15, ls: 2, anchor: 'start' })}
    <path d="M${cx + 80},466 h20 m-5,-4.5 l5,4.5 l-5,4.5" fill="none" stroke="${RED}" stroke-width="1.6"/>
    ${terms.svg}
    <rect x="${x}" y="548" width="325" height="0.75" fill="${LINE}"/>
    ${txt('CARE', { x, y: 574, size: 11, ls: 3, anchor: 'start' })}
    ${txt('Wash cold, inside out  ·  No hot tumble dry  ·  Never iron the print', { x: x + 34, y: 573.5, size: 8.5, font: 'inter', anchor: 'start', fill: MUTED })}`;
})()];

// Hang tag 2 x 3.5 in, 0.25 in hole centred 0.45 in below the top edge (kept clear)
P['hangtag-front'] = [225, 375, '2 x 3.5 in + 0.125 in bleed, 0.25 in hole at top centre', `
  ${rect(225, 375, INK)}
  ${logo(112.5, 205, 46, PAPER, 'middle')}
  <rect x="92.5" y="262" width="40" height="3" fill="${RED}"/>`];
P['hangtag-back'] = [225, 375, '2 x 3.5 in + 0.125 in bleed, 0.25 in hole at top centre', (() => {
  const rows = ['DESIGN', 'SIZE', 'COLOUR'].map((l, i) => {
    const y = 132 + i * 38;
    return txt(l, { x: 32, y, size: 12, ls: 3, anchor: 'start', fill: MUTED }) + `<rect x="32" y="${y + 8}" width="161" height="0.9" fill="${INK}"/>`;
  }).join('');
  const note = para('Every HaikuFit tee carries a real Japanese word. Look it up: we checked it.', { x: 32, y: 262, width: 161, size: 10, lead: 1.5 });
  return `${rect(225, 375, PAPER)}${rows}${note.svg}${logo(112.5, 334, 22, INK, 'middle')}`;
})()];

// Round sticker 2 in (die-cut circle), bleed to 2.25 in
P['sticker-round'] = [225, 225, '2 in circle + 0.125 in bleed (die-cut)', `
  <circle cx="112.5" cy="112.5" r="112.5" fill="${RED}"/>
  <circle cx="112.5" cy="112.5" r="77" fill="none" stroke="#FFFFFF" stroke-width="2.2"/>
  ${ring('HAIKUFIT · HAIKUFIT · HAIKUFIT · ', 112.5, 112.5, 82, 15.5, '#FFFFFF', 0, true)}
  ${vcol('俳句', { x: 112.5, y: 86, size: 50, fill: '#FFFFFF' })}`];

// Tissue paper: seamless 8 in repeat
P['tissue-pattern-tile'] = [800, 800, '8 x 8 in seamless repeat tile (no bleed)', (() => {
  let els = '';
  for (let j = 0; j < 4; j++) for (let i = 0; i < 4; i++) {
    const cx = 100 + i * 200 + (j % 2) * 100, cy = 100 + j * 200;
    for (const dx of [-800, 0, 800]) for (const dy of [-800, 0, 800]) {
      const x = cx + dx, y = cy + dy;
      if (x < -90 || x > 890 || y < -90 || y > 890) continue;
      els += (i + j) % 2 === 0 ? sealSolid(x, y, 40) : txt('HAIKUFIT', { x, y: y + 7, size: 22, ls: 4, fill: INK });
    }
  }
  return `${rect(800, 800, '#F4EFE6')}${els}`;
})()];

// Packing tape: 2 in wide, 12 in repeat strip (3 x 4 in units)
P['packing-tape-strip'] = [1200, 200, '2 in wide tape, 12 in repeat (no bleed)', `
  ${rect(1200, 200, INK)}
  ${[0, 1, 2].map((k) => logo(k * 400 + 200, 100, 70, PAPER, 'middle')).join('')}`];

// Mailer box 12 x 9 x 3 in: face artwork to place on the box maker's dieline
P['mailer-box-lid-outside'] = [1225, 925, '12 x 9 in lid face + 0.125 in bleed', `
  ${rect(1225, 925, INK)}
  ${logo(612.5, 462.5, 230, PAPER, 'middle')}
  <rect x="562.5" y="640" width="100" height="5" fill="${RED}"/>`];
P['mailer-box-lid-inside'] = [1225, 925, '12 x 9 in inside of lid + 0.125 in bleed', `
  ${rect(1225, 925, PAPER)}
  ${vcol('古池や', { x: 1010, y: 190, size: 64 })}
  ${vcol('蛙飛び込む', { x: 905, y: 190, size: 64 })}
  ${vcol('水の音', { x: 800, y: 190, size: 64 })}
  ${sealSolid(800, 430, 58)}
  ${['An old pond —', 'a frog jumps in,', 'the sound of water.'].map((l, i) => txt(l, { x: 150, y: 330 + i * 54, size: 40, font: 'mincho', anchor: 'start' })).join('')}
  <rect x="150" y="510" width="60" height="5" fill="${RED}"/>
  ${txt('Matsuo Bashō, 1686', { x: 150, y: 556, size: 22, font: 'interSemi', anchor: 'start' })}
  ${para('The most famous haiku ever written, by a poet born into a samurai family. Every HaikuFit piece carries the same idea: a few true words, worn close.', { x: 150, y: 596, width: 520, size: 18, lead: 1.55, fill: MUTED }).svg}
  ${logo(150, 790, 44, INK)}`];
P['mailer-box-front'] = [1225, 325, '12 x 3 in front panel + 0.125 in bleed', `
  ${rect(1225, 325, INK)}${logo(612.5, 162.5, 150, PAPER, 'middle')}`];
P['mailer-box-side'] = [925, 325, '9 x 3 in side panel + 0.125 in bleed', `
  ${rect(925, 325, INK)}
  ${sealSolid(462.5, 130, 96)}
  ${txt('OPEN SLOWLY · THE POEM IS INSIDE', { x: 462.5, y: 252, size: 26, ls: 8, fill: '#BDB3A6' })}`];

// Poly mailer 12 x 15.5 in (front), back with a 6 x 4 in label panel
P['poly-mailer-front'] = [1225, 1575, '12 x 15.5 in + 0.125 in bleed (top 2 in = seal flap)', `
  ${rect(1225, 1575, INK)}
  ${txt('OPEN SLOWLY · THE POEM IS INSIDE', { x: 612.5, y: 150, size: 30, ls: 10, fill: '#8F857B' })}
  <circle cx="612.5" cy="700" r="390" fill="${RED}"/>
  ${logo(612.5, 1330, 170, PAPER, 'middle')}`];
P['poly-mailer-back'] = [1225, 1575, '12 x 15.5 in + 0.125 in bleed, white 6 x 4 in shipping label panel', `
  ${rect(1225, 1575, INK)}
  ${logo(612.5, 260, 90, PAPER, 'middle')}
  <rect x="312.5" y="560" width="600" height="400" rx="14" fill="#FFFFFF"/>
  ${txt('SHIPPING LABEL', { x: 612.5, y: 772, size: 34, ls: 10, fill: '#C9C2B8' })}
  ${txt('FROM: HAIKUFIT', { x: 612.5, y: 1060, size: 30, ls: 8, fill: '#BDB3A6' })}
  ${sealSolid(612.5, 1300, 90)}`];

// Tagless neck labels printed inside the back neck: 3 x 1.5 in, transparent, per size and ink
for (const size of ['S', 'M', 'L', 'XL', 'XXL']) {
  for (const [mode, ink] of [['light-tee', INK], ['dark-tee', PAPER]]) {
    P[`neck-label-${size}-${mode}`] = [300, 150, '3 x 1.5 in print inside back neck (transparent, no bleed)', `
      ${logo(24, 62, 46, ink)}
      ${txt('WASH COLD · INSIDE OUT', { x: 24, y: 118, size: 12, ls: 2.5, anchor: 'start', fill: ink })}
      <rect x="208" y="30" width="1.6" height="94" fill="${ink}"/>
      ${txt(size, { x: 256, y: 104, size: size.length > 2 ? 44 : 62, fill: ink })}`];
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
// the game screenshots themselves, for social posts and the website
for (const [k, file] of Object.entries(SHOTS)) fs.copyFileSync(path.join(GAME, file), path.join(HERE, 'print', `game-screen-${k}.png`));
