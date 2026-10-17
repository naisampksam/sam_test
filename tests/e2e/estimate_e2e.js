// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button');
  await p.goto(U + 'estimate.php');
  const row = s => p.locator('#sizeRows tr', { has: p.locator(`input[data-col=size][value="${s}"]`) });
  const M = row('M');
  await M.locator('[data-col=qty]').fill('100');
  const num = async (col) => parseFloat(await M.locator(`[data-col=${col}]`).getAttribute('placeholder'));
  const g0 = await num('g_ov'), c0 = await num('cost_ov'), p0 = await num('price_ov');
  ok(g0 > 0 && c0 > 0 && p0 > c0, `auto values as placeholders (g ${g0}, cost ${c0}, price ${p0})`);
  const total0 = await p.locator('#rTotal').innerText();
  // extra cost +5 -> cost exactly +5, price goes up
  await p.click('[data-add-cost]'); await p.fill('.extra-row [data-xk=name]', 'Hang tag'); await p.fill('.extra-row [data-xk=amt]', '5');
  ok(Math.abs(await num('cost_ov') - (c0 + 5)) < 0.011 && await num('price_ov') > p0, 'extra cost ₹5 added to the cost: ' + await num('cost_ov'));
  ok(/Hang tag/.test(await p.locator('#breakdown').innerText()), 'extra in breakdown');
  // grams override 250 -> fabric 105
  const cBefore = await num('cost_ov');
  await M.locator('[data-col=g_ov]').fill(String(Math.round(g0 + 33)));
  const c1 = await num('cost_ov');
  ok(c1 > cBefore, `more grams → higher cost (${cBefore} → ${c1})`);
  // cost override
  await M.locator('[data-col=cost_ov]').fill('150');
  const p150 = await num('price_ov');
  ok(p150 > 150, 'cost override 150 -> price from it: ' + p150);
  // price override
  await M.locator('[data-col=price_ov]').fill('220');
  ok(await p.locator('#rTotal').innerText() === '₹22,000', 'price override total ' + await p.locator('#rTotal').innerText());
  ok(/manual/i.test(await p.locator('#breakdown').innerText()), 'manual tag');
  await p.locator('#estResult').scrollIntoViewIfNeeded(); 
  await p.fill('[name=name]', 'Manual test'); await p.click('.sticky-actions .btn.primary'); await p.waitForLoadState();
  ok(await row('M').locator('[data-col=price_ov]').inputValue() === '220' && await p.locator('.extra-row [data-xk=amt]').inputValue() === '5', 'overrides saved');
  await row('M').locator('[data-col=price_ov]').fill('');
  ok(await p.locator('#rTotal').innerText() === '₹' + (p150 * 100).toLocaleString('en-IN'), 'cleared -> automatic again ' + await p.locator('#rTotal').innerText());
  await row('M').locator('[data-col=g_ov]').scrollIntoViewIfNeeded(); await p.screenshot({ path: __dirname + '/shots/v18_ov.png' });
  ok(!errs.length, 'no js errors ' + errs);
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})();
