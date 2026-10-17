// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  // old bill keeps its total
  await p.goto(U + 'bill_print.php?id=127');
  const oldTot = (await p.locator('tr.total td.r').innerText()).replace(/[^\d.]/g, '');
  ok(oldTot === '5906.00' && sql('SELECT ship_gst FROM bills WHERE id=127') === '0.00', 'old bill unchanged: ₹' + oldTot);
  // new order: 1 plain tee + ₹100 shipping
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'SHIPGST');
  await p.evaluate(() => { const c = document.querySelector('#items .item-card'); c.querySelector('input[value=plain]').click(); c._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' }); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); });
  await p.fill('[name="bill[shipping]"]', '100');
  await p.waitForFunction(() => /₹\s?[1-9]/.test(document.getElementById('billTotalBar').textContent), null, { timeout: 10000 });
  await p.waitForTimeout(600);
  const prev = await p.locator('#billPreview').innerText();
  const pv = (await p.locator('#billTotalBar').innerText()).replace(/[^\d.]/g, '');
  ok(/Shipping \(\+5% GST\)/.test(prev), 'preview shows shipping +5% GST');
  const [qp] = await Promise.all([ctx.waitForEvent('page'), p.click('[data-quote]')]); await qp.waitForLoadState();
  const qt = (await qp.locator('tr.total td.r').innerText()).replace(/[^\d.]/g, '');
  await qp.close();
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  const oid = new URL(p.url()).searchParams.get('id');
  const r = sql(`SELECT CONCAT_WS('|', taxable, tax_total, shipping, ship_gst, total) FROM bills WHERE order_id=${oid}`).split('|');
  console.log('bill:', r.join(' | '), 'preview', pv, 'quote', qt);
  const taxable = +r[0], tax = +r[1];
  ok(Math.abs(tax - (taxable * 0.05 + 5)) < 0.02, 'GST = 5% of goods + ₹5 on ₹100 shipping');
  ok(r[3] === '5.00', 'bill stores ship_gst 5');
  ok(Math.round(+r[4]) === Math.round(+pv) && Math.round(+qt) === Math.round(+pv), 'saved bill = preview = quotation');
  const bid = sql(`SELECT id FROM bills WHERE order_id=${oid}`);
  await p.goto(U + 'bill_print.php?id=' + bid);
  const pt = await p.locator('.sum').innerText();
  ok(/Shipping.*\+5% GST/.test(pt) && (await p.locator('tr.total td.r').innerText()).replace(/[^\d.]/g, '') === (+r[4]).toFixed(2), 'printed bill: shipping +5% GST, same total');
  // bill edit page live total matches
  await p.goto(U + `bill.php?id=${bid}&edit=1`);
  await p.waitForTimeout(400);
  ok((await p.locator('#billBar').innerText()).replace(/[^\d.]/g, '') === (+r[4]).toFixed(2), 'bill edit page total matches');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
