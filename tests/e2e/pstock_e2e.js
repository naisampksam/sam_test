// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Partner stock: own blank T-shirts received (print-only partner) → "My own T-shirt" in the order form with "n left",
// no T-shirt charge and Looma's stock untouched; running low; RTO puts T-shirts into their return stock (no COD
// expected); sending from returns costs packing + shipping only; print file link on a design; Excel download.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
const IMG = __dirname + '/shots/pstock-mock.png';
(async () => {
  execSync(`php -r '$i = imagecreatetruecolor(300, 300); imagefill($i, 0, 0, imagecolorallocate($i, 200, 30, 30)); imagepng($i, "${IMG}");'`);
  const b = await chromium.launch(); const errs = [];
  const ctx = async (w = 1280) => { const c = await b.newContext({ viewport: { width: w, height: 900 }, acceptDownloads: true }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const a = await ctx();
  await a.goto(U + 'login.php'); await a.fill('[name=username]', 'admin'); await a.fill('[name=password]', 'admin12345'); await a.click('button.primary'); await a.waitForLoadState();
  await a.goto(U + 'partner_stock.php');
  const old = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM partners WHERE code = 'PSTK'");
  if (old) {
    sql(`UPDATE bills SET status = 'cancelled' WHERE id IN (SELECT bill_id FROM partner_orders WHERE partner_id IN (${old}) AND bill_id IS NOT NULL)`);
    sql(`UPDATE orders SET deleted_at = NOW() WHERE id IN (SELECT order_id FROM partner_orders WHERE partner_id IN (${old}) AND order_id IS NOT NULL)`);
    for (const t of ['partner_orders', 'partner_users', 'partner_ledger', 'partner_stock', 'partner_stock_moves']) sql(`DELETE FROM ${t} WHERE partner_id IN (${old})`);
    sql(`UPDATE designs SET active = 0 WHERE id IN (SELECT design_id FROM design_customers WHERE customer_code = 'PSTK')`); sql("DELETE FROM design_customers WHERE customer_code = 'PSTK'");
    sql(`DELETE FROM partners WHERE id IN (${old})`);
  }
  const [gsm, prod, colour, s1, s2] = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; foreach (catalog() as $g) foreach ($g["products"] as $p) if ($p["colors"] && count($p["sizes"]) > 1) { echo json_encode([$g["gsm"], $p["name"], $p["colors"][0]["name"], $p["sizes"][0], $p["sizes"][1]]); exit; }'`).toString());
  await a.goto(U + 'admin/partners.php?new=1');
  await a.fill('[name=name]', 'Print Only Co'); await a.fill('[name=code]', 'PSTK'); await a.click('button:has-text("Save partner")'); await a.waitForLoadState();
  const pid = new URL(a.url()).searchParams.get('id');
  await a.fill(`[name="tshirt[${gsm}|${prod}]"]`, '300'); await a.fill('[name=print]', '100'); await a.fill('[name=packing]', '20'); await a.fill('[name=ship_first]', '60'); await a.fill('[name=ship_extra]', '20');
  await a.uncheck('[name=gst_add]'); await a.check('[name=own_blanks]'); await a.click('button:has-text("Save prices")'); await a.waitForLoadState();
  await a.fill('.pt-newlogin [name=username]', 'printonly'); await a.fill('.pt-newlogin [name=password]', 'print12345'); await a.click('.pt-newlogin button'); await a.waitForLoadState();

  // 1. partner saves a design with a print file link
  const t = await ctx(390);
  await t.goto(U + 'partner/'); await t.fill('[name=username]', 'printonly'); await t.fill('[name=password]', 'print12345'); await t.click('.pt-login button'); await t.waitForLoadState();
  await t.goto(U + 'partner/designs.php');
  await t.fill('[name=name]', 'Red Logo'); await t.setInputFiles('[name="photos[]"]', IMG); await t.fill('#newDesign [name=print_link]', 'ftp://example.com/x.png');
  await t.click('#newDesign button.pt-btn.filled'); await t.waitForLoadState();
  ok(/should start with https/.test(await t.locator('.pt-note.err').innerText()), 'print file link must be a web link');
  await t.setInputFiles('[name="photos[]"]', IMG); await t.fill('#newDesign [name=print_link]', 'https://drive.google.com/file/d/abc123/view');
  await t.click('#newDesign button.pt-btn.filled'); await t.waitForLoadState();
  const did = sql("SELECT d.id FROM designs d JOIN design_customers dc ON dc.design_id = d.id WHERE dc.customer_code = 'PSTK' AND d.active = 1");
  ok(/drive\.google\.com/.test(sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(extra, '$.print_link')) FROM designs WHERE id = ${did}`)), 'design saved with its Google Drive print file link');
  ok(/✓ Print file link/.test(await t.locator('.pt-dlink summary').innerText()), 'design card: “✓ Print file link”');

  // 2. team records blank T-shirts received: s1 × 3, s2 × 2
  await a.goto(U + 'partner_stock.php?partner=' + pid);
  await a.selectOption('[data-bt]', gsm + '|' + prod); await a.fill('#blankForm [name=color]', colour);
  await a.fill(`[name="qty[${s1}]"]`, '3'); await a.fill(`[name="qty[${s2}]"]`, '2'); await a.click('#blankForm button.primary'); await a.waitForLoadState();
  ok(sql(`SELECT GROUP_CONCAT(CONCAT(size, '=', qty) ORDER BY size) FROM partner_stock WHERE partner_id = ${pid} AND kind = 'blank'`).split(',').sort().join(',') === [`${s1}=3`, `${s2}=2`].sort().join(','), `blanks received: ${s1} 3, ${s2} 2`);
  const looma = sql(`SELECT IFNULL(SUM(qty), 0) FROM stock_items WHERE category = 'Blank T-shirt' AND product = '${prod}' AND color = '${colour}'`);

  // 3. order on their own blanks: "n left", + stops there, no T-shirt charge
  await t.goto(U + 'partner/order.php?new=1');
  const line = t.locator('[data-line]').first();
  ok(await line.locator('.pt-src label:has-text("My own T-shirt")').count() === 1 && await line.locator('.pt-src label:has-text("From my returns")').count() === 0, 'line offers “My own T-shirt” (no returns yet)');
  await line.locator('.pt-src label:has-text("My own T-shirt")').click();
  await line.locator('[data-tshirt]').selectOption(gsm + '|' + prod); await line.locator('.pt-sw').first().click();
  ok(/3 left/.test(await line.locator('.pt-steps-grid').innerText()), 'sizes show what is left: ' + (await line.locator('.pt-steps-grid').innerText()).replace(/\s+/g, ' ').slice(0, 60));
  for (let i = 0; i < 5; i++) await line.locator(`.pt-step:has-text("${s1}") [data-d="1"]`).first().click();
  ok(await line.locator(`.pt-step:has-text("${s1}") input`).first().inputValue() === '3', '+ stops at the 3 left');
  await line.locator(`.pt-step:has-text("${s1}") [data-d="-1"]`).first().click();
  await t.fill('[name=ship_phone]', '9811100001'); await t.fill('[name=ship_name]', 'Blank Buyer'); await t.fill('[name=ship_address]', 'Own Street'); await t.fill('[name=ship_pincode]', '682012');
  await t.click('#ptSend'); await t.waitForLoadState();
  const po1 = new URL(t.url()).searchParams.get('id');
  const cost = await t.locator('.pt-cost').innerText();
  ok(!/T-shirt –/.test(cost) && /Printing/.test(cost) && /Total\s*₹300/.test(cost), 'own blanks: no T-shirt charge — printing 200 + packing 20 + shipping 80 = ₹300');

  // 4. accept: their blanks go down, Looma's stock does not; print file link in the notes
  await a.goto(U + 'partner_orders.php');
  ok(await a.locator(`.po-card[data-po="${po1}"] a:has-text("Print file")`).count() === 1, 'team sees the print file link on the card');
  await a.locator(`.po-card[data-po="${po1}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  const oid1 = sql(`SELECT order_id FROM partner_orders WHERE id = ${po1}`);
  ok(+oid1 > 0 && sql(`SELECT qty FROM partner_stock WHERE partner_id = ${pid} AND kind = 'blank' AND size = '${s1}'`) === '1', `accepted: their ${s1} blanks 3 → 1`);
  ok(sql(`SELECT IFNULL(SUM(qty), 0) FROM stock_items WHERE category = 'Blank T-shirt' AND product = '${prod}' AND color = '${colour}'`) === looma
    && sql(`SELECT COUNT(*) FROM partner_orders po JOIN bill_items i ON i.bill_id = po.bill_id WHERE po.id = ${po1} AND i.stock_id IS NOT NULL`) === '0', 'Looma’s own stock untouched');
  const notes = sql(`SELECT notes FROM orders WHERE id = ${oid1}`);
  ok(/THEIR OWN BLANK/.test(notes) && /drive\.google\.com/.test(notes), 'order notes: “print on THEIR OWN BLANK T-shirts” + print file link');
  await t.goto(U + 'partner/stock.php');
  ok(/Running low/.test(await t.locator('.pt-note.warn').innerText()), 'partner: “Running low on your own T-shirts”');

  // 5. shipped COD order comes back → return stock; no COD expected
  sql(`UPDATE orders SET payment_type = 'cod', cod_amount = 499, shipped = 1, shipped_at = NOW(), tracking_no = 'AWBRTO1' WHERE id = ${oid1}`);
  await a.goto(U + 'partner_money.php');
  ok(await a.locator('table:has-text("AWBRTO1")').count() === 1, 'before RTO: listed under COD to receive');
  await a.goto(U + 'partner_orders.php?tab=accepted');
  a.once('dialog', d => d.accept()); await a.locator(`.po-card[data-po="${po1}"] button:has-text("Returned (RTO)")`).click(); await a.waitForLoadState();
  ok(sql(`SELECT qty FROM partner_stock WHERE partner_id = ${pid} AND kind = 'return' AND design_id = ${did} AND size = '${s1}'`) === '2', `RTO: 2 × ${s1} “Red Logo” in their return stock`);
  await a.goto(U + 'partner_money.php');
  ok(await a.locator('table:has-text("AWBRTO1")').count() === 0, 'after RTO: no COD expected any more');
  await t.goto(U + 'partner/order.php?id=' + po1);
  ok(/Returned/.test(await t.locator('.pt-head .pt-pill').innerText()), 'partner order shows “Returned”');

  // 6. send again from returns: packing + shipping only; Looma order item is plain (already printed)
  await t.goto(U + 'partner/stock.php');
  await t.locator('.pt-row', { hasText: 'Red Logo' }).locator('a:has-text("Send")').click(); await t.waitForLoadState();
  ok(await t.locator('.pt-src input[value=return]:checked').count() === 1 && /2 left/.test(await t.locator('.pt-steps-grid').innerText()), '“Send” opens the form from returns, “2 left”');
  await t.fill('[name=ship_phone]', '9811100002'); await t.fill('[name=ship_name]', 'Second Buyer'); await t.fill('[name=ship_address]', 'Again Road'); await t.fill('[name=ship_pincode]', '682013');
  await t.click('#ptSend'); await t.waitForLoadState();
  const po2 = new URL(t.url()).searchParams.get('id');
  ok(/Total\s*₹80/.test(await t.locator('.pt-cost').innerText()) && !/Printing/.test(await t.locator('.pt-cost').innerText()), 'from returns: packing 20 + shipping 60 = ₹80, no printing');
  await a.goto(U + 'partner_orders.php');
  ok(/From my returns/i.test(await a.locator(`.po-card[data-po="${po2}"]`).innerText()), 'team card says “From my returns”');
  await a.locator(`.po-card[data-po="${po2}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  const oid2 = sql(`SELECT order_id FROM partner_orders WHERE id = ${po2}`);
  ok(sql(`SELECT GROUP_CONCAT(item_type) FROM order_items WHERE order_id = ${oid2}`) === 'plain' && /RETURN STOCK/.test(sql(`SELECT notes FROM orders WHERE id = ${oid2}`)), 'Looma order: plain item (already printed), notes “FROM THEIR RETURN STOCK”');
  ok(sql(`SELECT qty FROM partner_stock WHERE partner_id = ${pid} AND kind = 'return' AND size = '${s1}'`) === '1', 'return stock 2 → 1');

  // 7. Excel download
  await t.goto(U + 'partner/index.php');
  await t.locator('summary:has-text("Download orders")').click();
  const [dl] = await Promise.all([t.waitForEvent('download'), t.click('.pt-export button')]);
  const csv = fs.readFileSync(await dl.path(), 'utf8');
  const csvOk = [/^\uFEFF"?Order no\."?,"?Sent on"?,Status/.test(csv), /Returned/.test(csv), /Blank Buyer/.test(csv), /AWBRTO1/.test(csv)];
  ok(csvOk.every(Boolean), 'Excel download: their orders with status, tracking, returned: ' + dl.suggestedFilename() + ' ' + JSON.stringify(csvOk) + (csvOk.every(Boolean) ? '' : ' ' + JSON.stringify(csv.slice(0, 300))));
  ok(!/Money Buyer|Yadhal/.test(csv), 'only their own orders in the file');

  await t.goto(U + 'partner/stock.php'); await t.screenshot({ path: __dirname + '/shots/pstock.png', fullPage: true });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
