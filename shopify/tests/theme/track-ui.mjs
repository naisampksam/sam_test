import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const errs = []; const opened = [];
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
for (const w of [390, 1280]) {
  const ctx = await b.newContext({ viewport: { width: w, height: 1000 }, isMobile: w < 500, hasTouch: w < 500 });
  const p = await ctx.newPage(); p.on('pageerror', (e) => errs.push(e.message));
  await p.addInitScript(() => { try { localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('fx-intro-seen', '1'); } catch (e) {} window.open = (u) => { window.__opened = u; }; });
  await p.route('https://rewards.test/**', (route) => {
    const body = JSON.parse(route.request().postData() || '{}');
    const res = body.order.includes('1024') ? { order: '#1024', placedAt: '2026-10-12T10:00:00Z', cancelled: false, shipments: [] }
      : body.order.includes('1023') ? { order: '#1023', placedAt: '2026-10-10T10:00:00Z', cancelled: false, shipments: [
          { courier: 'Delhivery', awb: '1234567890123', url: 'https://www.delhivery.com/track/package/1234567890123', status: 'IN_TRANSIT', eta: '2026-10-15T10:00:00Z' },
          { courier: 'DTDC', awb: 'D12345678', url: '', status: 'DELIVERED', deliveredAt: '2026-10-13T10:00:00Z' }] }
      : { error: 'not_found' };
    route.fulfill({ headers: { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'application/json' }, body: JSON.stringify(res) });
  });
  await p.goto('file://' + process.cwd() + '/out/page-track-order.html'); await p.waitForTimeout(800);
  const card = p.locator('[data-track]');
  await card.scrollIntoViewIfNeeded(); await card.screenshot({ path: `out/track-form-${w}.png` });
  await p.fill('[data-track-order-form] [name=order]', '#1023'); await p.fill('[data-track-order-form] [name=contact]', '9876543210');
  await p.click('[data-track-order-form] button[type=submit]'); await p.waitForTimeout(900);
  const links = await p.$$eval('.track-result__go', (as) => as.map((a) => a.textContent.trim() + ' -> ' + a.href));
  ok(`[${w}] order lookup lists every shipment with its courier link`, links.length === 2 && /Delhivery.*-> https:\/\/www\.delhivery\.com/.test(links[0]) && /DTDC/.test(links[1]), JSON.stringify(links));
  await p.locator('[data-track]').screenshot({ path: `out/track-result-${w}.png` });
  await p.fill('[data-track-order-form] [name=order]', '1024'); await p.click('[data-track-order-form] button[type=submit]'); await p.waitForTimeout(700);
  ok(`[${w}] unshipped order explains it is being packed`, /pack/i.test(await p.locator('.track-result__note').textContent()));
  await p.fill('[data-track-order-form] [name=order]', '5555'); await p.click('[data-track-order-form] button[type=submit]'); await p.waitForTimeout(700);
  ok(`[${w}] wrong order gives a friendly not-found message`, /find|check/i.test(await p.locator('[data-track-msg]').textContent()));
  await p.click('[data-track-switch]');
  for (const awb of ['1234567890123', 'D12345678', 'XYZ-99881']) {
    await p.fill('[data-track-awb]', awb);
    const det = await p.locator('[data-track-detected]').textContent();
    await p.click('[data-track-awb-form] button[type=submit]');
    const url = await p.evaluate(() => window.__opened) || '';
    const want = awb.startsWith('1234') ? /delhivery/ : awb.startsWith('D') ? /dtdc/ : /ship24|track/;
    ok(`[${w}] AWB ${awb} detected (${det.trim()}) and opens the right tracker`, want.test(url) && url.includes(awb), url);
  }
  ok(`[${w}] no horizontal overflow`, !(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth)));
  await ctx.close();
}
ok('no JS errors', errs.length === 0, errs.join(' | '));

await b.close();
