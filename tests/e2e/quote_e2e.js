// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const counts = () => sql("SELECT CONCAT_WS(',', (SELECT COUNT(*) FROM orders),(SELECT COUNT(*) FROM order_items),(SELECT COUNT(*) FROM bills),(SELECT COUNT(*) FROM bill_items),(SELECT COUNT(*) FROM stock_items),(SELECT COUNT(*) FROM stock_moves),(SELECT COUNT(*) FROM customers),(SELECT COUNT(*) FROM order_log),(SELECT COUNT(*) FROM bill_payments))");
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
  const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  const before = counts();
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'QUOTENEW');
  await p.fill('[name=customer_name]', 'Rahul Menon');
  await p.fill('[name=ship_phone]', '9876500000');
  await p.evaluate(() => {
    const card = document.querySelector('#items .item-card');
    card._setBlank({ gsm: '250 GSM', product: 'Oversized Fit - French Terry', color: 'Black', size: 'XXXL' });
    const q = card.querySelector('[name$="[quantity]"]'); q.value = 10; q.dispatchEvent(new Event('input', { bubbles: true }));
    card.querySelector('.print-details').open = true;
  });
  const place = k => p.locator('.item-card').first().locator('.print-place', { has: p.locator(`[name$="[${k}]"]`) });
  for (const k of ['back_print', 'chest_print']) await p.locator('.item-card').first().locator(`[data-pp-toggle=${k}]`).click();
  await place('back_print').locator('[data-ps]').selectOption({ index: 2 });
  await place('chest_print').locator('[data-pm]').selectOption('emb');
  await place('chest_print').locator('[data-pst]').fill('8000');
  await place('chest_print').locator('input[name$="[dg]"]').fill('300');
  await p.fill('[name="bill[shipping]"]', '80');
  await p.waitForFunction(() => /₹\s?[1-9]/.test(document.getElementById('billTotalBar').textContent), null, { timeout: 10000 });
  await p.waitForTimeout(800);
  const preview = (await p.locator('#billTotalBar').innerText()).replace(/[^\d.]/g, '');
  const [qp] = await Promise.all([ctx.waitForEvent('page'), p.click('[data-quote]')]);
  await qp.waitForLoadState();
  qp.on('pageerror', e => errs.push('quote: ' + e.message));
  const txt = await qp.locator('.sheet').innerText();
  ok(/QUOTATION/.test(txt) && !/INVOICE/i.test(txt), 'looks like a quotation, not a bill');
  ok(/Rahul Menon/.test(txt) && new RegExp('Valid till\\s+' + (d => [d.getDate(), d.getMonth() + 1, d.getFullYear()].map((x, i) => i < 2 ? String(x).padStart(2, '0') : x).join('-'))(new Date(Date.now() + 7 * 864e5)) + ' \\(1 week\\)').test(txt) && /valid for 1 week/.test(txt), 'valid 1 week: ' + (txt.match(/Valid till.*/)||[''])[0]);
  ok(/Embroidery digitizing \(one time\)/.test(txt), 'embroidery digitizing line is there');
  ok(/XXXL/.test(txt) && /Printing/.test(txt) && /Shipping/.test(txt), 'T-shirt, printing and shipping lines');
  const qt = (await qp.locator('tr.total td.r').innerText()).replace(/[^\d.]/g, '');
  ok(Math.round(+qt) === Math.round(+preview), `quotation total ₹${qt} = order bill preview ₹${preview}`);
  ok(await qp.locator('[data-share=pdf]').count() === 1 && await qp.locator('body').getAttribute('data-wa') === '919876500000', 'WhatsApp send buttons + customer number');
  ok(/Total: ₹/.test(await qp.locator('body').getAttribute('data-text')), 'WhatsApp message has the total');
  await qp.screenshot({ path: __dirname + '/shots/quote_order.png', fullPage: true });
  ok(counts() === before, 'nothing saved: ' + before + ' → ' + counts());
  // the order form still works after the quotation
  ok(!(await p.locator('.sticky-actions .btn.primary').isDisabled()), 'order form buttons still active');
  ok(await p.inputValue('[name=customer_name]') === 'Rahul Menon', 'order form kept what was typed');
  await qp.close();
  // New bill page
  await p.goto(U + 'bills.php');
  ok(await p.locator('a[href="bill.php?new=1"]').innerText() === '📝 New quotation', 'Bills page: New quotation button');
  await p.goto(U + 'bill.php?new=1');
  ok(/New quotation/.test(await p.locator('h1').innerText()), 'page is New quotation');
  ok(await p.locator('#billForm .sticky-actions button').count() === 1 && await p.locator('#billForm [data-quote]').count() === 1, 'no Save bill button, only Create quotation');
  ok(await p.locator('[name=make_order]').count() === 0, 'no make-order tick');
  await p.fill('[name=bill_name]', 'Priya');
  await p.fill('[name="lines[0][description]"]', 'Custom hoodie').catch(() => {});
  const first = p.locator('[name$="[description]"]').first();
  await first.fill('Custom hoodie');
  await p.locator('[name$="[qty]"]').first().fill('5');
  await p.locator('[name$="[rate]"]').first().fill('600');
  await p.locator('[name$="[gst_rate]"]').first().fill('5');
  const [qb] = await Promise.all([ctx.waitForEvent('page'), p.click('[data-quote]')]);
  await qb.waitForLoadState();
  const t2 = await qb.locator('.sheet').innerText();
  ok(/Custom hoodie/.test(t2) && /Priya/.test(t2) && /3,150\.00/.test(t2), 'bill page quotation: 5 × 600 + 5% = 3,150');
  await qb.screenshot({ path: __dirname + '/shots/quote_bill.png', fullPage: true });
  ok(counts() === before, 'still nothing saved');
  // Enter key on the page makes a quotation, never a bill
  const [qe] = await Promise.all([ctx.waitForEvent('page'), p.locator('[name$="[rate]"]').first().press('Enter')]);
  await qe.waitForLoadState();
  ok(/QUOTATION/.test(await qe.locator('.sheet').innerText()) && counts() === before, 'Enter key → quotation, nothing saved');
  // a forced save of a new bill is refused
  const tok = await p.inputValue('#billForm [name=csrf]');
  await p.evaluate(t => { const f = document.createElement('form'); f.method = 'post'; f.action = 'bill.php?new=1'; f.innerHTML = '<input name=csrf value="' + t + '"><input name=do value=save><input name="lines[0][description]" value=X><input name="lines[0][rate]" value=5>'; document.body.appendChild(f); f.submit(); }, tok);
  await p.waitForLoadState();
  ok(counts() === before && /automatically/.test(await p.locator('.alert').first().innerText()), 'saving a new bill is refused');
  // existing bills can still be edited & saved
  const bid = sql('SELECT MAX(id) FROM bills');
  await p.goto(U + 'bill.php?id=' + bid + '&edit=1');
  ok(await p.locator('#billForm .btn.primary').innerText() === '💾 Save changes', 'existing bill: Save changes still there');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
