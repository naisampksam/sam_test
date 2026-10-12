// Writes ../gallery/index.html (interactive mockup gallery) and ../README.md from content.mjs.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { DESIGNS, COLOURS, SPECS, PROMPTS, PLACEMENTS } from './content.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const MANIFEST = JSON.parse(fs.readFileSync(path.join(HERE, '..', 'svg', 'designs.json'), 'utf8'));
for (const d of DESIGNS) d.pieces = MANIFEST[d.key].pieces;
const PLACE_NAME = { 'back-center': 'Center back', 'back-full': 'Full back, collar to hem', 'front-full': 'Full front', chest: 'Left chest embroidery', sleeve: 'Sleeve print', nape: 'Nape print' };
const DATA = JSON.stringify({ DESIGNS, COLOURS, PLACE_NAME }).replace(/</g, '\\u003c');

const html = `<title>Samurai Drop 01</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Shippori+Mincho+B1:wght@600;800&family=Zen+Kaku+Gothic+New:wght@400;500;700&family=Bebas+Neue&display=swap">
<style>
/* Layout: lookbook stage (sticky, left) beside the listing sheet (right); stacked on phones. */
:root {
  --bg: #F3F1EE; --panel: #FFFFFF; --ink: #171414; --muted: #6A625E; --line: #DED8D3;
  --red: #C8231B; --red-ink: #FFFFFF; --stage: #E7E3DE;
  --display: 'Shippori Mincho B1', 'Hiragino Mincho ProN', 'Yu Mincho', Georgia, serif;
  --body: 'Zen Kaku Gothic New', 'Hiragino Sans', 'Yu Gothic', system-ui, sans-serif;
  --label: 'Bebas Neue', 'Arial Narrow', Impact, sans-serif;
}
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {
  --bg: #121110; --panel: #1A1918; --ink: #EFE8DC; --muted: #A0978E; --line: #2F2C2A;
  --red: #E4473A; --red-ink: #121110; --stage: #1F1D1C; color-scheme: dark } }
:root[data-theme="dark"] {
  --bg: #121110; --panel: #1A1918; --ink: #EFE8DC; --muted: #A0978E; --line: #2F2C2A;
  --red: #E4473A; --red-ink: #121110; --stage: #1F1D1C; color-scheme: dark }
* { box-sizing: border-box }
[hidden] { display: none !important }
body { background: var(--bg); color: var(--ink); font-family: var(--body); font-size: 15px; line-height: 1.65; }
.wrap { max-width: 1240px; margin: 0 auto; padding-inline: 20px; padding-block: 28px 64px; }
h1, h2, h3 { font-family: var(--display); font-weight: 800; line-height: 1.15; text-wrap: balance; margin: 0 }
.label { font-family: var(--label); letter-spacing: .14em; font-size: 15px; color: var(--muted); }
header { display: flex; flex-wrap: wrap; align-items: end; justify-content: space-between; gap: 16px 32px; padding-bottom: 22px; border-bottom: 1px solid var(--line); }
header h1 { font-size: clamp(30px, 4.6vw, 52px); display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap }
header h1 .jp { color: var(--red); font-size: .62em }
header p { margin: 6px 0 0; color: var(--muted); max-width: 58ch }
.legend { display: flex; gap: 18px; flex-wrap: wrap; font-size: 13px; color: var(--muted) }
.legend b { color: var(--ink); font-weight: 700 }

.picker { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; margin-block: 22px; }
.pick { all: unset; cursor: pointer; display: grid; gap: 2px; padding: 12px 12px 10px; border: 1px solid var(--line); background: var(--panel); border-radius: 4px; min-width: 0 }
.pick .k { font-family: var(--display); font-size: 26px; font-weight: 800; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.pick .n { font-family: var(--label); letter-spacing: .12em; font-size: 14px; color: var(--muted) }
.pick[aria-pressed="true"] { border-color: var(--red); box-shadow: inset 0 -3px 0 var(--red) }
.pick[aria-pressed="true"] .k { color: var(--red) }
.pick:focus-visible, .tab:focus-visible, .sw:focus-visible, .copy:focus-visible { outline: 2px solid var(--red); outline-offset: 2px }

.main { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 36px; align-items: start }
.stage-col { position: sticky; top: calc(env(safe-area-inset-top, 0px) + 12px) }
.stage { position: relative; aspect-ratio: 2 / 3; max-width: 100%; background: var(--stage); border-radius: 4px; overflow: hidden }
.stage img { position: absolute; display: block; max-width: none }
.stage .blank { inset: 0; width: 100%; height: 100% }
.stage.artwork { display: grid; place-items: center }
.stage.artwork .ov { position: static; width: 74%; max-height: 94%; object-fit: contain }
table.place { border-collapse: collapse; width: 100%; font-size: 14px; min-width: 640px }
table.place th, table.place td { text-align: left; padding: 10px 14px 10px 0; border-top: 1px solid var(--line); vertical-align: top }
table.place th { font-family: var(--label); font-weight: 400; letter-spacing: .12em; color: var(--muted); font-size: 15px }
table.place td:first-child { font-weight: 700 }
.table-wrap { overflow-x: auto }
.tabs { display: flex; gap: 6px; margin-top: 12px; flex-wrap: wrap }
.tab { all: unset; cursor: pointer; font-family: var(--label); letter-spacing: .12em; font-size: 15px; padding: 6px 12px; border: 1px solid var(--line); border-radius: 3px; color: var(--muted) }
.tab[aria-selected="true"] { background: var(--ink); color: var(--bg); border-color: var(--ink) }
.swatches { display: flex; flex-wrap: wrap; gap: 8px 14px; margin-top: 14px; align-items: center }
.sw { all: unset; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: var(--muted) }
.sw i { width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--line); box-shadow: inset 0 0 0 2px var(--panel); display: block }
.sw[aria-pressed="true"] { color: var(--ink); font-weight: 700 }
.sw[aria-pressed="true"] i { outline: 2px solid var(--ink); outline-offset: 2px }
.sw .rec { color: var(--red); font-size: 11px; margin-left: -3px }
.note { font-size: 12.5px; color: var(--muted); margin-top: 10px }

.sheet { display: grid; gap: 26px; min-width: 0 }
.hero { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 22px; align-items: start }
.vkanji { writing-mode: vertical-rl; font-family: var(--display); font-weight: 800; font-size: 64px; line-height: 1; color: var(--red); letter-spacing: .04em }
.hero h2 { font-size: 34px }
.hero .reading { font-style: italic; color: var(--muted); margin: 2px 0 10px }
.hero p { margin: 0 0 10px; max-width: 60ch }
.block { border-top: 1px solid var(--line); padding-top: 16px; display: grid; gap: 10px; min-width: 0 }
.block-head { display: flex; justify-content: space-between; align-items: center; gap: 12px }
.copy { all: unset; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--red); padding: 3px 8px; border: 1px solid currentColor; border-radius: 3px; white-space: nowrap }
.title-text { font-weight: 500; font-size: 15.5px }
.tags { display: flex; flex-wrap: wrap; gap: 6px }
.tags span { font-size: 12.5px; padding: 2px 9px; border-radius: 99px; background: var(--panel); border: 1px solid var(--line); font-variant-numeric: tabular-nums }
.tags span em { font-style: normal; color: var(--muted); margin-left: 5px; font-size: 11px }
.desc { white-space: pre-wrap; background: var(--panel); border: 1px solid var(--line); border-radius: 4px; padding: 14px 16px; font-size: 13.5px; margin: 0; overflow-wrap: anywhere }
dl.facts { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 6px 18px; margin: 0; font-size: 14px }
dl.facts dt { color: var(--muted) }
dl.facts dd { margin: 0 }
.dots { display: inline-flex; gap: 6px; vertical-align: middle }
.dots i { width: 13px; height: 13px; border-radius: 50%; border: 1px solid var(--line); display: inline-block }

section.more { margin-top: 64px; display: grid; gap: 18px }
section.more > h2 { font-size: 30px }
section.more > p { margin: 0; color: var(--muted); max-width: 70ch }
.specs { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0 32px }
.spec { border-top: 1px solid var(--line); padding-block: 14px }
.spec h3 { font-family: var(--label); font-weight: 400; letter-spacing: .12em; font-size: 17px; color: var(--red) }
.spec p { margin: 4px 0 0; font-size: 14px }
.warn { border-left: 3px solid var(--red); padding: 12px 16px; background: var(--panel); font-size: 14px; max-width: 80ch }
.prompts { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px }
.prompt { background: var(--panel); border: 1px solid var(--line); border-radius: 4px; padding: 16px; display: grid; gap: 8px; align-content: start; min-width: 0 }
.prompt h3 { font-size: 19px }
.prompt p { margin: 0; color: var(--muted); font-size: 13.5px }
.prompt code { font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 12px; line-height: 1.55; background: var(--bg); border-radius: 3px; padding: 10px; display: block; overflow-wrap: anywhere }
@media (max-width: 900px) {
  .main { grid-template-columns: minmax(0, 1fr) }
  .stage-col { position: static }
  .picker { grid-template-columns: repeat(3, minmax(0, 1fr)) }
}
@media (max-width: 480px) {
  .picker { grid-template-columns: repeat(2, minmax(0, 1fr)) }
  .prompts { grid-template-columns: minmax(0, 1fr) }
  .vkanji { font-size: 46px }
}
@media (prefers-reduced-motion: no-preference) { .stage img { transition: opacity .2s ease } }
</style>

<div class="wrap">
  <header>
    <div>
      <div class="label">Placement-led DTF prints + embroidery · oversized tee</div>
      <h1>Samurai Drop 01 <span class="jp">侍の章</span></h1>
      <p>Twelve sumi-e designs with real, meaning-checked Japanese, each planned around professional print placements: full back, center back, front, chest embroidery, sleeve and nape. Pick a design, a shirt colour and a view. Listing copy is ready to paste into Etsy.</p>
    </div>
    <div class="legend"><span><b>12</b> designs</span><span><b>2</b> ink versions each</span><span><b>7</b> shirt colours</span></div>
  </header>

  <nav class="picker" id="picker" aria-label="Designs"></nav>

  <div class="main">
    <div class="stage-col">
      <div class="stage" id="stage">
        <img class="blank" id="blank" alt="">
      </div>
      <div class="tabs" role="tablist" id="tabs">
        <button class="tab" role="tab" data-view="back">Back</button>
        <button class="tab" role="tab" data-view="front">Front</button>
        <button class="tab" role="tab" data-view="close">Embroidery close-up</button>
        <button class="tab" role="tab" data-view="artwork">Artwork</button>
      </div>
      <div class="swatches" id="swatches"></div>
      <p class="note"><span style="color:var(--red)">●</span> marks the colours recommended for this design. Mockups use your own blank tee photos; the embroidery texture is simulated.</p>
    </div>

    <div class="sheet">
      <div class="hero">
        <div class="vkanji" id="vk"></div>
        <div>
          <div class="label" id="reading"></div>
          <h2 id="name"></h2>
          <p id="meaning"></p>
          <p id="story" style="color:var(--muted)"></p>
        </div>
      </div>
      <div class="block">
        <dl class="facts">
          <dt>Placements</dt><dd id="placements"></dd>
          <dt>Best on</dt><dd id="best"></dd>
          <dt>Files</dt><dd id="files" style="overflow-wrap:anywhere"></dd>
        </dl>
      </div>
      <div class="block">
        <div class="block-head"><span class="label">Etsy title</span><button class="copy" data-copy="title">Copy</button></div>
        <div class="title-text" id="title"></div>
      </div>
      <div class="block">
        <div class="block-head"><span class="label">13 tags · each under 20 characters</span><button class="copy" data-copy="tags">Copy all</button></div>
        <div class="tags" id="tags"></div>
      </div>
      <div class="block">
        <div class="block-head"><span class="label">Description</span><button class="copy" data-copy="description">Copy</button></div>
        <pre class="desc" id="desc"></pre>
      </div>
    </div>
  </div>

  <section class="more">
    <h2>Print placements</h2>
    <p>Where each print sits on the tee (HPS = high point of the shoulder). Give this chart to your printer with the files.</p>
    <div class="table-wrap"><table class="place"><thead><tr><th>Placement</th><th>Max size</th><th>Position</th><th>Used by</th></tr></thead><tbody>
      ${PLACEMENTS.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}
    </tbody></table></div>
  </section>

  <section class="more">
    <h2>Production specs</h2>
    <div class="specs">
      ${SPECS.map(([h, p]) => `<div class="spec"><h3>${esc(h)}</h3><p>${esc(p)}</p></div>`).join('')}
    </div>
  </section>

  <section class="more">
    <h2>Next drop: AI art prompts</h2>
    <p>For more complex illustrations, generate the art in Midjourney or Ideogram, then add the kanji yourself with the build kit. The prompts forbid text on purpose.</p>
    <div class="warn"><b>Why no AI text:</b> image models invent fake characters. The two "kanji" in the red stripe of your current samurai tee are not real Japanese, and readers will notice. Every character in this drop is set from a real font and checked for meaning.</div>
    <div class="prompts">
      ${PROMPTS.map((p, i) => `<article class="prompt"><div class="block-head"><h3>${esc(p.name)}</h3><button class="copy" data-prompt="${i}">Copy prompt</button></div><p>${esc(p.idea)}</p><code id="prompt${i}">${esc(p.prompt)}</code></article>`).join('')}
    </div>
  </section>
</div>

<script>
const { DESIGNS, COLOURS, PLACE_NAME } = ${DATA};
const PROMPTS = ${JSON.stringify(PROMPTS.map((p) => p.prompt)).replace(/</g, '\\u003c')};
const state = { d: 0, colour: DESIGNS[0].colours[0], view: 'back' };
try { const s = JSON.parse(localStorage.getItem('samurai-drop') || '{}'); if (s.view) state.view = s.view; } catch (e) {}
const $ = (id) => document.getElementById(id);

$('picker').innerHTML = DESIGNS.map((d, i) => '<button class="pick" data-i="' + i + '" aria-pressed="false"><span class="k">' + d.kanji + '</span><span class="n">' + d.name + '</span></button>').join('');
$('picker').addEventListener('click', (e) => { const b = e.target.closest('.pick'); if (!b) return; state.d = +b.dataset.i; state.colour = DESIGNS[state.d].colours[0]; render(); });
$('tabs').addEventListener('click', (e) => { const b = e.target.closest('.tab'); if (!b) return; state.view = b.dataset.view; try { localStorage.setItem('samurai-drop', JSON.stringify({ view: state.view })); } catch (err) {} render(); });
$('swatches').addEventListener('click', (e) => { const b = e.target.closest('.sw'); if (!b) return; state.colour = b.dataset.c; render(); });

function render() {
  const d = DESIGNS[state.d], c = COLOURS[state.colour], mode = c.mode;
  document.querySelectorAll('.pick').forEach((b, i) => b.setAttribute('aria-pressed', i === state.d));
  document.querySelectorAll('.tab').forEach((b) => b.setAttribute('aria-selected', b.dataset.view === state.view));
  $('swatches').innerHTML = Object.entries(COLOURS).map(([k, v]) =>
    '<button class="sw" data-c="' + k + '" aria-pressed="' + (k === state.colour) + '"><i style="background:' + v.hex + '"></i>' + v.name + (d.colours.includes(k) ? '<span class="rec">●</span>' : '') + '</button>').join('');
  const chest = d.pieces.find((p) => p.place === 'chest');
  const hasFront = d.pieces.some((p) => p.view === 'front');
  if (state.view === 'close' && !chest) state.view = hasFront ? 'front' : 'back';
  document.querySelector('[data-view="close"]').hidden = !chest;
  document.querySelectorAll('.tab').forEach((b) => b.setAttribute('aria-selected', b.dataset.view === state.view));
  const stage = $('stage'), v = state.view, blank = $('blank');
  stage.className = 'stage' + (v === 'artwork' ? ' artwork' : '');
  stage.style.background = v === 'artwork' ? c.hex : '';
  stage.querySelectorAll('.ov').forEach((n) => n.remove());
  blank.hidden = v === 'artwork';
  blank.src = 'assets/blank-' + state.colour + '-' + (v === 'back' ? 'back' : 'front') + '.jpg';
  blank.style.cssText = '';
  const pct = (n, of) => (n / of * 100).toFixed(3) + '%';
  const add = (p, style) => { const im = document.createElement('img'); im.className = 'ov'; im.src = 'assets/' + p.file + '-' + mode + '.png'; im.alt = d.name + ': ' + PLACE_NAME[p.place]; if (style) im.style.cssText = style; stage.appendChild(im); };
  if (v === 'artwork') add(d.pieces[0]);
  else if (v === 'close') {
    const cx = chest.x + chest.w / 2 - 90, cy = chest.y + chest.h / 2 - 135;
    blank.style.cssText = 'width:' + pct(1024, 180) + ';height:' + pct(1536, 270) + ';left:' + pct(-cx, 180) + ';top:' + pct(-cy, 270);
    add(chest, 'left:' + pct(chest.x - cx, 180) + ';top:' + pct(chest.y - cy, 270) + ';width:' + pct(chest.w, 180));
  } else d.pieces.filter((p) => p.view === v).forEach((p) => add(p, 'left:' + pct(p.x, 1024) + ';top:' + pct(p.y, 1536) + ';width:' + pct(p.w, 1024)));
  $('vk').textContent = d.kanji;
  $('reading').textContent = d.kanji + ' · ' + d.reading;
  $('name').textContent = d.name;
  $('meaning').textContent = d.meaning;
  $('story').textContent = d.story;
  $('placements').innerHTML = d.pieces.map((p) => PLACE_NAME[p.place] + ' · ' + p.inches[0] + ' × ' + p.inches[1] + ' in' + (p.place === 'chest' ? ' (' + d.chest + ', tone-on-tone katana)' : '')).join('<br>');
  $('best').innerHTML = '<span class="dots">' + d.colours.map((k) => '<i title="' + COLOURS[k].name + '" style="background:' + COLOURS[k].hex + '"></i>').join('') + '</span> ' + d.colours.map((k) => COLOURS[k].name).join(', ');
  $('files').textContent = d.pieces.map((p) => (p.place === 'chest' ? 'svg/' : 'dtf/') + p.file + '-{light,dark}.svg').join(' · ');
  $('title').textContent = d.title;
  $('tags').innerHTML = d.tags.map((t) => '<span>' + t + '<em>' + t.length + '</em></span>').join('');
  $('desc').textContent = d.description;
}

async function copyText(text, btn) {
  const old = btn.textContent;
  try { await navigator.clipboard.writeText(text); btn.textContent = 'Copied'; }
  catch (e) { btn.textContent = 'Select and copy manually'; }
  setTimeout(() => { btn.textContent = old; }, 1600);
}
document.addEventListener('click', (e) => {
  const b = e.target.closest('.copy'); if (!b) return;
  const d = DESIGNS[state.d];
  if (b.dataset.prompt) return copyText(PROMPTS[+b.dataset.prompt], b);
  const k = b.dataset.copy;
  copyText(k === 'tags' ? d.tags.join(', ') : d[k], b);
});
render();
</script>
`;
fs.writeFileSync(path.join(HERE, '..', 'gallery', 'index.html'), html);

// ---------- README ----------
const md = `# Samurai Drop 01

Twelve Japanese-streetwear designs for oversized tees, each planned around professional print placements (full back, center back, front, chest embroidery, sleeve, nape). All Japanese text is real and checked for meaning, set from real fonts and converted to vector outlines.

Open \`gallery/index.html\` to preview each design on the brand's blank tees, switch shirt colours and copy the Etsy listing text.

| Design | Kanji | Meaning | Chest | Best shirt colours |
|---|---|---|---|---|
${DESIGNS.map((d) => `| **${d.name}** | ${d.kanji} (${d.reading}) | ${d.meaning} | ${d.chest} | ${d.colours.map((k) => COLOURS[k].name).join(', ')} |`).join('\n')}

## Files

| Folder | What | Use |
|---|---|---|
| \`dtf/<design>-light.svg\` / \`-dark.svg\` | **Final DTF back prints as vectors**: flat paths, 2 inks, no effects, 14×21 in. Specks under 0.6 mm removed | Send to the DTF printer. Opens in CorelDRAW, Illustrator, Inkscape and RIPs |
| \`dtf/<design>-chest-<mode>.svg\` | Final DTF chest print as vectors, true size | DTF chest print if you skip embroidery |
| \`dtf/<design>-sleeve-<mode>.svg\` / \`-nape-\` | Sleeve and nape prints as vectors, true size | Small DTF transfers (see placement chart) |
| \`print/<design>-light.png\` / \`-dark.png\` | Back print, 4200×6300 px transparent (14×21 in @ 300 dpi) | Send to DTF printer. **light** = black ink for white/beige/lavender tees; **dark** = off-white ink for black/navy/green/chocolate tees |
| \`print/<design>-embroidery-<mode>.png\` | Flat embroidery art, 2.2 in wide @ 300 dpi | Reference for the digitizer |
| \`svg/<design>-embroidery-<mode>.svg\` | Vector embroidery art, text outlined | **Send this to the digitizer** for DST/PES |
| \`print/<design>-chest-<mode>.png\` | Textured chest art | DTF chest print if you skip embroidery |
| \`svg/*.svg\` | Editable sources (brush texture made with SVG filters, which only browsers/Inkscape show) | Edit, then rebuild \`dtf/\` |
| \`mockups/<design>-<colour>-{back,front,closeup}.jpg\` | Mockups on your blank tee photos | Etsy/Instagram listing photos |
| \`gallery/\` | Interactive preview page | Review and pick designs |
| \`src/\` | Generator scripts | Edit text and colours, then rebuild |

## Print placements

| Placement | Max size | Position | Used by |
|---|---|---|---|
${PLACEMENTS.map((r) => `| ${r.join(' | ')} |`).join('\n')}

Per design: ${DESIGNS.map((d) => `**${d.name}**: ${d.pieces.map((p) => `${PLACE_NAME[p.place]} ${p.inches[0]}×${p.inches[1]} in`).join(', ')}`).join('; ')}.

## Production specs

${SPECS.map(([h, p]) => `- **${h}:** ${p}`).join('\n')}

## Etsy listings

${DESIGNS.map((d) => `### ${d.name} · ${d.kanji}

**Title:** ${d.title}

**Tags:** ${d.tags.join(', ')}

**Description:**

\`\`\`
${d.description}
\`\`\`
`).join('\n')}

## Next drop: AI art prompts

Generate the art without text, then add real kanji with the build kit. Image models invent fake characters, so never let them write the Japanese.

${PROMPTS.map((p) => `**${p.name}**: ${p.idea}

\`\`\`
${p.prompt}
\`\`\`
`).join('\n')}

## Rebuilding

\`\`\`bash
cd designs/src
npm install                 # opentype.js (text -> outlines)
node build.mjs              # writes ../svg
node render.mjs             # every piece to ../print at 300 dpi of its real print size
python3 vectorize.py        # flat DTF vector SVGs in ../dtf (needs potrace, Pillow, numpy)
python3 mockups.py          # placement mockups + gallery assets (Pillow, numpy)
node gallery.mjs            # gallery page + this README
\`\`\`

Rendering uses Playwright's Chromium. Kanji come from Yuji Boku / Yuji Syuku and Latin text from Bebas Neue (all SIL Open Font License, subset in \`src/fonts\`). To add a new character, re-download the font subset with it included; \`build.mjs\` outlines whatever the font contains.
`;
fs.writeFileSync(path.join(HERE, '..', 'README.md'), md);
console.log('wrote gallery/index.html and README.md');
