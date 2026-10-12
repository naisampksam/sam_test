// Renders ../svg/*.svg to transparent PNGs with headless Chromium.
// Usage: node render.mjs [scale=auto] [outDir=../print] [nameFilter]
//   auto: every file at 300 dpi of its real print size (svg data-print-in, e.g. 14x21 in -> 4200x6300 px).
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
let playwright;
try { playwright = require('playwright'); } catch { playwright = require('/opt/node22/lib/node_modules/playwright'); }

const HERE = path.dirname(fileURLToPath(import.meta.url));
const scaleArg = process.argv[2] || 'auto';
const outDir = path.resolve(HERE, process.argv[3] || '../print');
const filter = process.argv[4] || '';
fs.mkdirSync(outDir, { recursive: true });

const svgDir = path.join(HERE, '..', 'svg');
const files = fs.readdirSync(svgDir).filter((n) => n.endsWith('.svg') && n.includes(filter));

const browser = await playwright.chromium.launch();
for (const name of files) {
  const src = fs.readFileSync(path.join(svgDir, name), 'utf8');
  const [, w, h] = src.match(/width="(\d+)" height="(\d+)"/);
  const inches = Number((src.match(/data-print-in="([\d.]+)x/) || [])[1] || 0);
  const scale = scaleArg === 'auto' ? (inches * 300) / Number(w) : Number(scaleArg);
  const ctx = await browser.newContext({ viewport: { width: Number(w), height: Number(h) }, deviceScaleFactor: scale });
  const page = await ctx.newPage();
  await page.goto(pathToFileURL(path.join(svgDir, name)).href);
  await page.evaluate(() => document.fonts.ready);
  const out = path.join(outDir, name.replace('.svg', '.png'));
  await page.screenshot({ path: out, omitBackground: true });
  await ctx.close();
  console.log('rendered', path.relative(process.cwd(), out));
}
await browser.close();
