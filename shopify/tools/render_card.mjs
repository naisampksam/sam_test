// Renders print/thank-you-card/card.html to card.pdf (A6, 2 pages) and 300 dpi PNGs of each side.
// Run after tools/build_thank_you_card.py:  node tools/render_card.mjs
import { createRequire } from 'node:module';
import path from 'node:path';

const { chromium } = createRequire(import.meta.url)('playwright'); // honours NODE_PATH

const dir = path.join(path.dirname(new URL(import.meta.url).pathname), '..', 'print', 'thank-you-card');
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const page = await browser.newPage({ deviceScaleFactor: 300 / 96 });
await page.goto('file://' + path.join(dir, 'card.html'));
await page.evaluate(() => document.fonts.ready);
await page.pdf({ path: path.join(dir, 'card.pdf'), width: '105mm', height: '148mm', printBackground: true, preferCSSPageSize: true });
const cards = page.locator('.card');
await page.addStyleTag({ content: 'body { background: none } .card { margin: 0 }' });
await cards.nth(0).screenshot({ path: path.join(dir, 'card-front.png') });
await cards.nth(1).screenshot({ path: path.join(dir, 'card-back.png') });
await browser.close();
console.log('wrote card.pdf, card-front.png, card-back.png in', dir);
