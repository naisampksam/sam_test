// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1200, height: 900 } }); const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  // ---- 1. bill lock
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'LOCKTEST');
  await p.evaluate(() => { const c = document.querySelector('#items .item-card'); c.querySelector('input[value=plain]').click(); c._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' }); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); });
  await p.click('.proof-toggles label:has(input[value=photo])');
  await p.waitForTimeout(600);
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  const oid = new URL(p.url()).searchParams.get('id');
  const bill = sql(`SELECT CONCAT(id,'|',total) FROM bills WHERE order_id=${oid}`).split('|');
  // pay in full
  await p.fill('.pay-form [name=amount]', bill[1]); await p.click('.pay-form button.primary'); await p.waitForLoadState();
  ok(sql(`SELECT paid FROM bills WHERE id=${bill[0]}`) === bill[1], 'bill paid in full: ' + bill[1]);
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  ok(/locked — paid in full/.test(await p.locator('.bill-panel').innerText()), 'order form says the bill is locked (paid)');
  await p.evaluate(() => { const q = document.querySelector('#items .item-card [name$="[quantity]"]'); q.value = 5; q.dispatchEvent(new Event('input', { bubbles: true })); });
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+$/);
  ok(sql(`SELECT total FROM bills WHERE id=${bill[0]}`) === bill[1] && sql(`SELECT quantity FROM order_items WHERE order_id=${oid}`) === '5', 'order changed to 5 pcs, paid bill unchanged');
  // old (unpaid) bill
  sql(`DELETE FROM bill_payments WHERE bill_id=${bill[0]}; UPDATE bills SET paid=0, bill_date=DATE_SUB(CURDATE(), INTERVAL 40 DAY) WHERE id=${bill[0]}`);
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  ok(/older than 30 days/.test(await p.locator('.bill-panel').innerText()), 'old bill is locked too');
  sql(`UPDATE bills SET bill_date=CURDATE() WHERE id=${bill[0]}`);
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  ok(!/locked/.test(await p.locator('.bill-panel').innerText()), 'new unpaid bill still follows the order');
  // ---- 4. photo sent
  await p.goto(U + `order.php?id=${oid}`);
  ok(/Photo needed/i.test(await p.locator('h1').innerText()), 'red Photo needed flag');
  await p.click('button[name=action][value=proof_sent]'); await p.waitForLoadState();
  ok(/Photo sent/i.test(await p.locator('h1').innerText()) && sql(`SELECT photo_sent_by FROM orders WHERE id=${oid}`) === '1', 'marked sent: green flag, who kept');
  await p.goto(U + 'orders.php?q=LOCKTEST');
  ok(!(await p.locator(`.order-card[data-id="${oid}"]`).evaluate(e => e.classList.contains('has-proof'))) && /Photo sent/i.test(await p.locator(`.order-card[data-id="${oid}"]`).innerText()), 'order card no longer red');
  // ---- 5. batch edit
  const bid = sql('SELECT MAX(id) FROM production_batches');
  if (bid !== 'NULL' && bid !== '') {
    await p.goto(U + 'dashboard.php');
    await p.locator(`a[href="stock.php?batch=${bid}"]`).click(); await p.waitForLoadState();
    await p.fill('[name=transport_cost]', '1111'); await p.fill('[name=note]', 'fixed typo');
    await p.click('button.primary:has-text("Save batch")'); await p.waitForLoadState();
    ok(sql(`SELECT CONCAT(transport_cost,'|',note) FROM production_batches WHERE id=${bid}`) === '1111.00|fixed typo', 'batch edited');
  } else ok(false, 'no batch to edit');
  // ---- 6. saved quotations
  await p.goto(U + 'bill.php?new=1');
  await p.fill('[name=bill_name]', 'Meera'); await p.fill('[name=bill_phone]', '9811122233');
  await p.locator('[name$="[description]"]').first().fill('Custom hoodie'); await p.locator('[name$="[qty]"]').first().fill('4'); await p.locator('[name$="[rate]"]').first().fill('700');
  const counts = () => sql("SELECT CONCAT_WS(',', (SELECT COUNT(*) FROM orders),(SELECT COUNT(*) FROM bills),(SELECT COUNT(*) FROM stock_moves))");
  const before = counts();
  const [q] = await Promise.all([ctx.waitForEvent('page'), p.click('[data-quote]')]); await q.waitForLoadState();
  ok(/Draft/.test(await q.locator('.sheet').innerText()), 'unsaved quotation shows Draft');
  await q.click('button:has-text("Save quotation")'); await q.waitForURL(/quote\.php\?id=\d+/);
  const qid = new URL(q.url()).searchParams.get('id');
  const qt = await q.locator('.sheet').innerText();
  ok(/QT-\d{4}/.test(qt) && /Meera/.test(qt) && /2,940\.00/.test(qt), 'saved as numbered quotation, same total 2,940');
  ok(counts() === before, 'saving made no order, bill or stock');
  ok(await q.locator('text=Convert').count() === 0 && await q.locator('text=Make order').count() === 0, 'no convert-to-order / invoice option');
  await p.goto(U + 'quotes.php?q=Meera');
  ok(/Meera/.test(await p.locator('table').innerText()) && /2,940/.test(await p.locator('table').innerText()), 'listed in Quotations with search');
  await p.goto(U + 'quote.php?id=' + qid);
  ok(/Meera/.test(await p.locator('.sheet').innerText()) && await p.locator('[data-share=pdf]').count() === 1, 'reopen saved quotation with WhatsApp buttons');
  await p.click('button:has-text("Delete")'); await p.waitForURL(/quotes\.php/);
  ok(sql(`SELECT COUNT(*) FROM quotations WHERE id=${qid}`) === '0', 'quotation deleted');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
