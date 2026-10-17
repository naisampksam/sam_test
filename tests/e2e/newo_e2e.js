// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const D = __dirname + '/', U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
async function login(b, u, pw, w = 390) {
  const p = await (await b.newContext({ viewport: { width: w, height: 900 } })).newPage();
  p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button'); await p.waitForLoadState();
  return p;
}
async function setItem(p, idx, size, qty) {
  await p.evaluate(([idx, size, qty]) => {
    const c = document.querySelectorAll('#items .item-card')[idx];
    c._setBlank({ gsm: '250 GSM', product: 'Oversized Fit - French Terry', color: 'Black', size });
    c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true }));
    const q = c.querySelector('[name$="[quantity]"]'); q.value = qty; q.dispatchEvent(new Event('input', { bubbles: true }));
    c.querySelector('.print-details').open = true;
  }, [idx, size, qty]);
}
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await login(b, 'admin', 'admin12345');
  p.on('pageerror', e => errs.push(e.message));
  const stock0 = +sql("SELECT IFNULL(SUM(qty),0) FROM stock_items WHERE gsm='250 GSM' AND product='Oversized Fit - French Terry' AND color='Black' AND size='M'");
  await p.goto(U + 'order.php?new=1');
  ok(await p.locator('.steps a').count() === 3 && await p.locator('#step-bill').count() === 1, 'page has steps: Order details · Products & prints · Bill');
  await p.fill('[name=customer_id]', 'C1214');
  await setItem(p, 0, 'M', 6);
  const c1 = p.locator('.item-card').nth(0);
  const place = (c, k) => c.locator('.print-place', { has: p.locator(`[name$="[${k}]"]`) });
  await c1.locator('[data-pp-toggle=back_print]').click(); await c1.locator('[data-pp-toggle=chest_print]').click();
  await place(c1, 'back_print').locator('[data-ps]').selectOption({ index: 2 });
  await place(c1, 'back_print').locator('textarea').fill('Mountain');
  await place(c1, 'chest_print').locator('[data-pm]').selectOption('emb');
  await place(c1, 'chest_print').locator('[data-pst]').fill('8000');
  await place(c1, 'chest_print').locator('input[name$="[dg]"]').fill('300');
  await p.click('#addItem');
  await setItem(p, 1, 'L', 6);
  const c2 = p.locator('.item-card').nth(1);
  await c2.locator('[data-pp-toggle=back_print]').click();
  await place(c2, 'back_print').locator('[data-ps]').selectOption({ index: 2 });
  await place(c2, 'back_print').locator('[data-pn]').fill('2');
  await place(c2, 'back_print').locator('textarea').fill('Two prints');
  await p.fill('[name="bill[shipping]"]', '60');
  await p.waitForFunction(() => /5,909/.test((document.getElementById('billTotalBar') || {}).textContent || ''), null, { timeout: 8000 }).catch(() => {});
  const prev = (await p.locator('#billPreview').innerText()).replace(/\t/g, ' ');
  console.log(prev);
  ok(/Total ₹5,909\.00/.test(prev) && /Printing – Back ₹100 \+ Chest ₹48\s+6 × ₹148/.test(prev) && /6 × ₹200/.test(prev), 'live preview: 12 pcs ₹265, printing ₹148 and 2×A3 ₹200, digitizing ₹300, GST, shipping → ₹5,909');
  ok(/Bill ₹5,909/.test(await p.locator('#billTotalBar').innerText()), 'bottom bar shows the bill total');
  await p.locator('#step-bill').scrollIntoViewIfNeeded(); await p.screenshot({ path: D + 'shots/v95_bill_step.png' });
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  console.log('flash:', (await p.locator('.alert').first().innerText()).replace(/\s+/g, ' '));
  const oid = new URL(p.url()).searchParams.get('id');
  ok(sql(`SELECT total FROM bills WHERE order_id=${oid}`) === '5909.00', 'saved bill total = preview (₹5,909)');
  console.log(sql(`SELECT CONCAT(i.description,' | ',i.qty,' x ',i.rate) FROM bill_items i JOIN bills b ON b.id=i.bill_id WHERE b.order_id=${oid} ORDER BY i.sort`));
  ok(+sql("SELECT IFNULL(SUM(qty),0) FROM stock_items WHERE gsm='250 GSM' AND product='Oversized Fit - French Terry' AND color='Black' AND size='M'") === stock0 - 6, 'stock of Black M went down by 6');
  ok(/2 prints/.test(await p.locator('main').innerText()), 'order page shows the number of prints');
  // permission: staff with bill = view
  sql(`UPDATE users SET perms = JSON_SET(IFNULL(perms,'{}'), '$.bill', 'view', '$.item_price', 'view') WHERE username='anu'`);
  const s = await login(b, 'anu', 'pass1234');
  s.on('pageerror', e => errs.push(e.message));
  await s.goto(U + 'order.php?new=1');
  ok(await s.locator('#billPreview').count() === 1 && await s.locator('[name="bill[discount]"]').isDisabled() && await s.locator('[data-rate]').first().isDisabled(), 'staff with "view": sees bill preview and prices, cannot change them');
  sql(`UPDATE users SET perms = JSON_REMOVE(perms, '$.bill', '$.item_price') WHERE username='anu'`);
  await s.goto(U + 'order.php?new=1');
  ok(await s.locator('#step-bill').count() === 0 && await s.locator('[data-rate]').count() === 0, 'staff without permission: no bill step, no prices');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
