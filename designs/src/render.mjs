// Renders ../svg/*.svg to transparent PNGs with headless Chromium.
// Usage: node render.mjs [scale=3.5] [outDir=../print] [nameFilter]
//   back prints: scale 3.5 -> 4200x6300 px (14x21 in @ 300 dpi). chest/embroidery: scale 3 (1 unit = 1/100 in).
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
let playwright;
try { playwright = require('playwright'); } catch { playwright = require('/opt/node22/lib/node_modules/playwright'); }

const HERE = path.dirname(fileURLToPath(import.meta.url));
const scale = Number(process.argv[2] || 3.5);
const outDir = path.resolve(HERE, process.argv[3] || '../print');
const filter = process.argv[4] || '';
fs.mkdirSync(outDir, { recursive: true });

const svgDir = path.join(HERE, '..', 'svg');
const files = fs.readdirSync(svgDir).filter((n) => n.endsWith('.svg') && n.includes(filter));

const browser = await playwright.chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1200, height: 1800 }, deviceScaleFactor: scale });
const page = await ctx.newPage();
for (const name of files) {
  const src = fs.readFileSync(path.join(svgDir, name), 'utf8');
  const [, w, h] = src.match(/width="(\d+)" height="(\d+)"/);
  await page.setViewportSize({ width: Number(w), height: Number(h) });
  await page.goto(pathToFileURL(path.join(svgDir, name)).href);
  await page.evaluate(() => document.fonts.ready);
  const out = path.join(outDir, name.replace('.svg', '.png'));
  await page.screenshot({ path: out, omitBackground: true });
  console.log('rendered', path.relative(process.cwd(), out));
}
await browser.close();
