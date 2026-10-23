import { chromium } from 'playwright';
const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
let fired = false; p.on('dialog', async (d) => { fired = true; await d.dismiss(); });
const errs = []; p.on('pageerror', (e) => errs.push(e.message));
await p.addInitScript(() => {
  try {
    localStorage.setItem('theme:popup-dismissed', String(Date.now())); sessionStorage.setItem('fx-intro-seen', '1');
    localStorage.setItem('hf_dojo_reward', JSON.stringify({ code: 'DOJO5"><img src=x onerror=alert(1)>', label: '<img src=y onerror=alert(2)>', value: 50, kind: 'amount', at: Date.now() }));
    window.__xss = false;
  } catch (e) {}
});
await p.goto('file://' + process.cwd() + '/out/page-dojo.html'); await p.waitForTimeout(1500);
const pill = await p.locator('.reward-pill').count();
const injected = await p.evaluate(() => document.querySelectorAll('.reward-pill img, .dojo-run img[src="x"], .dojo-run img[src="y"]').length);
const ok = (n, c, x = '') => console.log(c ? 'PASS' : 'FAIL', n, x);
ok('a tampered saved reward still shows as text', pill > 0);
ok('no HTML from saved data is injected into the page', injected === 0);
ok('no script from saved data runs', !fired);
ok('no JS errors', errs.length === 0, errs.join(' | '));
await b.close();
