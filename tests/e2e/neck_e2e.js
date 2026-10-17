// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  const setup = async (qty) => {
    await p.goto(U + 'order.php?new=1');
    await p.fill('[name=customer_id]', 'NECKTEST');
    await p.evaluate(q => {
      const card = document.querySelector('#items .item-card');
      card._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' });
      const i = card.querySelector('[name$="[quantity]"]'); i.value = q; i.dispatchEvent(new Event('input', { bubbles: true }));
      card.querySelector('.print-details').open = true;
    }, qty);
    await p.locator('.item-card [data-neck="neck"] .switch').click();
  };
  const note = () => p.locator('.item-card [data-print-note]').innerText();
  // 1 piece, no big print → ₹10
  await setup(1);
  await p.waitForTimeout(300);
  ok(/Neck label ₹10/.test(await note()), '1 pc: neck label ₹10 — ' + await note());
  ok(/Auto: ₹10 per piece/.test(await p.locator('[data-neck-note]').innerText()), 'hint shows auto price');
  // add back A3 → free
  await p.locator('.item-card [data-pp-toggle=back_print]').click();
  const back = p.locator('.item-card .print-place', { has: p.locator('[name$="[back_print]"]') });
  await back.locator('[data-ps]').selectOption({ index: 2 });
  await p.waitForTimeout(300);
  ok(/Neck label free/.test(await note()), 'with A3 back print: free — ' + await note());
  // own price
  await p.fill('[data-neck-rate]', '8');
  await p.waitForTimeout(300);
  ok(/Neck label ₹8/.test(await note()), 'typed price ₹8 used even with A3');
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  let oid = new URL(p.url()).searchParams.get('id');
  ok(sql(`SELECT neck_rate FROM order_items WHERE order_id=${oid}`) === '8.00', 'neck price saved on item');
  const pr = sql(`SELECT CONCAT(description,' @ ',rate) FROM bill_items bi JOIN bills b ON b.id=bi.bill_id WHERE b.order_id=${oid} AND description LIKE 'Printing%'`);
  ok(/Neck label/.test(pr) && / @ 143\.00$/.test(pr), 'bill: A3 ₹135 + neck ₹8 = ₹143 → ' + pr);
  // 10 pcs no print → ₹5
  await setup(10);
  await p.waitForTimeout(300);
  ok(/Neck label ₹5/.test(await note()), '10 pcs: ₹5 — ' + await note());
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  oid = new URL(p.url()).searchParams.get('id');
  const pr2 = sql(`SELECT CONCAT(description,' @ ',rate) FROM bill_items bi JOIN bills b ON b.id=bi.bill_id WHERE b.order_id=${oid} AND description LIKE 'Printing%'`);
  ok(/Neck label @ 5\.00$/.test(pr2), 'bill 10 pcs: ' + pr2);
  // edit page keeps typed price
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  await p.fill('[data-neck-rate]', '7'); await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+$/);
  ok(sql(`SELECT rate FROM bill_items bi JOIN bills b ON b.id=bi.bill_id WHERE b.order_id=${oid} AND description LIKE 'Printing%'`) === '7.00', 'edited neck price updates the bill to ₹7');
  // admin price row
  await p.goto(U + 'admin/catalog.php');
  ok(await p.locator('input[value="Neck label"]').count() === 1, 'Neck label row in printing prices');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
