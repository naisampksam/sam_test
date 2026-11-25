// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Partner money: price list (T-shirt + print + packing + shipping + COD fee, before GST), cost shown to the partner,
// wallet needs the money first (pay with UTR → team confirms), Accept makes the bill (internal without GSTIN, GST
// invoice with one) and charges the account, COD received (courier file) and payout; credit limit; re-pricing on edit.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = async (w = 1280) => { const c = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const a = await ctx();
  await a.goto(U + 'login.php'); await a.fill('[name=username]', 'admin'); await a.fill('[name=password]', 'admin12345'); await a.click('button.primary'); await a.waitForLoadState();
  await a.goto(U + 'partner_money.php');
  const old = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM partners WHERE code = 'MONEY'");
  if (old) {
    sql(`UPDATE bills SET status = 'cancelled' WHERE id IN (SELECT bill_id FROM partner_orders WHERE partner_id IN (${old}) AND bill_id IS NOT NULL)`);
    sql(`UPDATE orders SET deleted_at = NOW() WHERE id IN (SELECT order_id FROM partner_orders WHERE partner_id IN (${old}) AND order_id IS NOT NULL)`);
    for (const t of ['partner_orders', 'partner_users', 'partner_ledger']) sql(`DELETE FROM ${t} WHERE partner_id IN (${old})`);
    sql(`UPDATE designs SET active = 0 WHERE id IN (SELECT design_id FROM design_customers WHERE customer_code = 'MONEY')`); sql("DELETE FROM design_customers WHERE customer_code = 'MONEY'");
    sql(`DELETE FROM partners WHERE id IN (${old})`);
  }
  const [gsm, prod] = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; foreach (catalog() as $g) foreach ($g["products"] as $p) if ($p["colors"]) { echo json_encode([$g["gsm"], $p["name"]]); exit; }'`).toString());
  await a.goto(U + 'admin/partners.php?new=1');
  await a.fill('[name=name]', 'Money Co'); await a.fill('[name=code]', 'MONEY'); await a.click('button:has-text("Save partner")'); await a.waitForLoadState();
  const pid = new URL(a.url()).searchParams.get('id');
  ok(await a.inputValue('[name=pay_mode]') === 'credit', 'new partner starts on credit (nothing blocks until money is set)');
  sql(`INSERT INTO designs (name, code, extra, active, created_at) VALUES ('Money Tee', '', '{}', 1, NOW())`);
  const did = sql("SELECT MAX(id) FROM designs WHERE name = 'Money Tee'");
  sql(`INSERT INTO design_customers (design_id, customer_code, created_at) VALUES (${did}, 'MONEY', NOW())`);

  // 1. prices: T-shirt 300, print 100, packing 20, shipping 60 + 20 each extra, COD 30; no GST; wallet
  await a.goto(U + 'admin/partners.php?id=' + pid);
  await a.selectOption('[name=pay_mode]', 'wallet'); await a.uncheck('[name=gst_add]');
  await a.fill(`[name="tshirt[${gsm}|${prod}]"]`, '300'); await a.fill('[name=print]', '100'); await a.fill('[name=packing]', '20');
  await a.fill('[name=ship_first]', '60'); await a.fill('[name=ship_extra]', '20'); await a.fill('[name=cod_fee]', '30');
  await a.click('button:has-text("Save prices")'); await a.waitForLoadState();
  ok(/Prices and payment saved/.test(await a.locator('.alert').first().innerText()), 'prices and payment saved');
  await a.fill('.pt-newlogin [name=username]', 'moneyco'); await a.fill('.pt-newlogin [name=password]', 'money12345'); await a.click('.pt-newlogin button'); await a.waitForLoadState();

  // 2. partner sends a COD order of 2 T-shirts: cost 600 + 200 + 20 + 80 + 30 = 930
  const t = await ctx(390);
  await t.goto(U + 'partner/'); await t.fill('[name=username]', 'moneyco'); await t.fill('[name=password]', 'money12345'); await t.click('.pt-login button'); await t.waitForLoadState();
  sql("INSERT INTO settings (k, v) VALUES ('partner_whatsapp', '+91 90000 12345') ON DUPLICATE KEY UPDATE v = VALUES(v)");
  // payment QR codes: one for GST partners, one for others (Settings)
  const QG = __dirname + '/shots/qr-gst.png', QN = __dirname + '/shots/qr-nogst.png';
  execSync(`php -r '$i = imagecreatetruecolor(400, 400); imagefill($i, 0, 0, imagecolorallocate($i, 255, 255, 255)); imagefilledrectangle($i, 40, 40, 160, 160, 0); imagepng($i, "${QG}");'`);
  execSync(`php -r '$i = imagecreatetruecolor(500, 500); imagefill($i, 0, 0, imagecolorallocate($i, 255, 255, 255)); imagefilledrectangle($i, 300, 300, 460, 460, 0); imagepng($i, "${QN}");'`);
  await a.goto(U + 'admin/settings.php');
  await a.setInputFiles('[name=qr_gst]', QG); await a.setInputFiles('[name=qr_nogst]', QN); await Promise.all([a.waitForNavigation(), a.click('#partnerqr button.primary')]);
  ok(/Payment QR codes saved/.test(await a.locator('body').innerText()) && sql("SELECT COUNT(*) FROM settings WHERE k IN ('partner_qr_gst', 'partner_qr_nogst') AND v LIKE '%.png'") === '2', 'Settings: GST and non-GST payment QR codes saved');
  await t.goto(U + 'partner/order.php?new=1');
  ok(/Wallet\s*₹0\.00/.test(await t.locator('.pt-wallet').innerText()) && /money\.php#recharge/.test(await t.locator('.pt-wallet a').getAttribute('href')), 'new order (wallet): shows the wallet ₹0.00 with “Recharge”');
  await t.selectOption('[data-tshirt]', gsm + '|' + prod); await t.locator('.pt-sw').first().click();
  await t.locator('.pt-step >> nth=0 >> [data-d="1"]').click(); await t.locator('.pt-step >> nth=1 >> [data-d="1"]').click();
  await t.fill('[name=ship_phone]', '9876500001'); await t.fill('[name=ship_name]', 'Money Buyer'); await t.fill('[name=ship_address]', '1 Bank Road, Kochi'); await t.fill('[name=ship_pincode]', '682011');
  await t.click('.pt-seg label:has([value=cod])'); await t.fill('[name=cod_amount]', '999');
  await t.click('#ptSend'); await t.waitForLoadState();
  const poId = new URL(t.url()).searchParams.get('id');
  const costTxt = await t.locator('.pt-cost').innerText();
  ok(/Total\s*₹930/.test(costTxt) && /Shipping/.test(costTxt) && /COD charge/.test(costTxt) && !/GST/.test(costTxt), 'partner sees the cost: ₹930 (no GST) — ' + costTxt.replace(/\s+/g, ' ').slice(0, 160));
  // wallet empty: a pop-up to recharge — amount rounded up to the next ₹100 (930 → 1000), not below the order; the QR to scan
  const dlg = t.locator('#ptRecharge');
  ok(await dlg.isVisible() && await dlg.locator('#ptRAmt').inputValue() === '1000' && await dlg.locator('#ptRAmt').getAttribute('min') === '930', 'wallet empty: pop-up “Recharge your wallet”, amount ₹1000 (930 rounded up), at least ₹930');
  const qrSrc = await dlg.locator('img.pt-qr').getAttribute('src');
  const qrRes = await t.request.get(new URL(qrSrc, U).href);
  const qrSize = (await qrRes.body()).length, nogstSize = +execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; echo filesize(upload_dir() . "/" . setting("partner_qr_nogst"));'`).toString();
  ok(qrRes.status() === 200 && qrSize === nogstSize, 'no GSTIN → the non-GST QR code is shown to scan');
  await dlg.locator('#ptRAmt').fill('1500');
  ok(/₹1500/.test(await dlg.locator('#ptRPay').innerText()) && /amount=1500$/.test(await dlg.locator('#ptRPaid').getAttribute('href')), 'amount can be raised: “pay ₹1500”');
  await dlg.locator('#ptRAmt').fill('900'); await dlg.locator('#ptRAmt').dispatchEvent('change');
  ok(await dlg.locator('#ptRAmt').inputValue() === '930', 'not below the order: 900 → back to 930');
  await dlg.locator('#ptRAmt').fill('1000'); await dlg.locator('#ptRAmt').dispatchEvent('input');
  const wa = await dlg.locator('a.pt-wa-link').getAttribute('href');
  ok(/^https:\/\/wa\.me\/919000012345\?text=/.test(wa) && /QR code/.test(decodeURIComponent(wa)) && /MONEY/.test(decodeURIComponent(wa)), 'help: WhatsApp Looma, with the partner code');
  await dlg.locator('button:has-text("Later")').click();
  ok(await dlg.isHidden() && /Recharge ₹1,000 to send this order/.test(await t.locator('.pt-recharge').innerText()) && !/recharge=1/.test(t.url()), '“Later” closes it; the order page keeps a “Recharge ₹1,000” card');
  await t.click('.pt-recharge [data-recharge]');
  ok(await dlg.isVisible(), '“Recharge now” opens it again'); await dlg.locator('button:has-text("Later")').click();
  ok(await t.locator('.pt-recharge a:has-text("I already paid")').count() === 1, '…and “I already paid” (UTR / screenshot)');

  // 3. team can't accept yet (admin may override); partner pays with UTR; team confirms
  await a.goto(U + 'partner_orders.php');
  const card = a.locator(`.po-card[data-po="${poId}"]`);
  ok(/Needs ₹930/.test(await card.locator('.po-money').innerText()) && await card.locator('[name=override]').count() === 1, 'Accept list: “⚠ Needs ₹930 more”, admin can tick “Accept anyway”');
  await card.locator('button:has-text("✓ Accept")').click(); await a.waitForLoadState();
  ok(/Needs ₹930/.test(await a.locator('.alert.err').innerText()) && sql(`SELECT status FROM partner_orders WHERE id = ${poId}`) === 'new', 'Accept without money is refused');
  await t.click('.pt-recharge a:has-text("I already paid")'); await t.waitForLoadState();
  ok(await t.inputValue('[name=amount]') === '1000' && await t.locator('#ptPayForm img.pt-qr').count() === 1, 'pay page: amount ₹1000 (rounded up), with the QR code');
  await t.fill('[name=amount]', '900'); await t.fill('[name=ref]', 'UTR1'); await t.evaluate(() => document.querySelector('[name=amount]').removeAttribute('min'));
  await t.click('button:has-text("Send to Looma")'); await t.waitForLoadState();
  ok(/needs at least ₹930/.test(await t.locator('.pt-note.err').innerText()), 'less than the order needs is refused');
  await t.fill('[name=amount]', '1000'); await t.fill('[name=ref]', 'UTR123456'); await t.click('button:has-text("Send to Looma")'); await t.waitForLoadState();
  ok(/waiting for Looma to confirm/.test(await t.locator('.pt-balance').innerText()), 'partner: ₹1000 waiting for Looma to confirm');
  await a.goto(U + 'partner_money.php');
  await a.locator('.pm-row', { hasText: 'Money Co' }).locator('button[value=confirm]').click(); await a.waitForLoadState();
  ok(sql(`SELECT status FROM partner_ledger WHERE partner_id = ${pid} AND kind = 'payment'`) === 'ok', 'team confirms the payment');

  // 4. accept → bill (internal, no GST) + charge; balance 70
  await a.goto(U + 'partner_orders.php');
  await a.locator(`.po-card[data-po="${poId}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  const oid = sql(`SELECT order_id FROM partner_orders WHERE id = ${poId}`);
  const bill = sql(`SELECT CONCAT_WS('|', b.total, b.tax_total, b.branding, b.paid, b.customer_code, (SELECT COUNT(*) FROM bill_items i WHERE i.bill_id = b.id AND i.stock_id IS NOT NULL)) FROM partner_orders po JOIN bills b ON b.id = po.bill_id WHERE po.id = ${poId}`).split('|');
  ok(+oid > 0 && +bill[0] === 930 && +bill[1] === 0 && bill[2] === 'plain' && +bill[3] === 930 && bill[4] === 'MONEY', 'accepted: bill ₹930, no GST, internal (plain), paid from their balance: ' + bill.join('|'));
  ok(+bill[5] >= 1, 'T-shirts leave stock with the bill');
  await t.goto(U + 'partner/money.php');
  ok(/₹70\.00/.test(await t.locator('.pt-balance b').innerText()) && /\+ ₹1,000\.00/.test(await t.locator('.pt-list').first().innerText()) && /− ₹930\.00/.test(await t.locator('.pt-list').first().innerText()),
    'partner: balance ₹70, statement +1000 / −930');
  ok(await t.locator('h2:has-text("GST invoices")').count() === 0, 'no GSTIN → no invoices shown to the partner');

  // 5. a change on the Looma order re-prices the bill and the charge
  sql(`UPDATE order_items SET quantity = 2 WHERE order_id = ${oid} ORDER BY id LIMIT 1`);
  execSync(`php -r '$_SERVER["HTTP_HOST"] = "x"; require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; require "${ROOT}/inc/billing.php"; order_bill_sync(${oid}, null);'`);
  ok(sql(`SELECT amount FROM partner_ledger WHERE partner_order_id = ${poId} AND kind = 'charge'`) === '1350.00', 'one more T-shirt on the order → charge 930 + 300 + 100 + 20 = 1350');
  sql(`UPDATE order_items SET quantity = 1 WHERE order_id = ${oid} ORDER BY id LIMIT 1`);
  execSync(`php -r '$_SERVER["HTTP_HOST"] = "x"; require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; require "${ROOT}/inc/billing.php"; order_bill_sync(${oid}, null);'`);
  ok(sql(`SELECT amount FROM partner_ledger WHERE partner_order_id = ${poId} AND kind = 'charge'`) === '930.00', 'back to 2 T-shirts → charge 930 again');

  // 6. shipped → COD to receive → courier file → balance; payout
  sql(`UPDATE orders SET shipped = 1, shipped_at = NOW(), courier = 'Delhivery', tracking_no = 'AWBMONEY1' WHERE id = ${oid}`);
  await a.goto(U + 'partner_money.php');
  ok(await a.locator('table:has-text("AWBMONEY1")').count() === 1, 'shipped COD order listed under “COD to receive”');
  const rep = __dirname + '/shots/cod-remit.csv';
  fs.writeFileSync(rep, 'Waybill,COD Amount,Remitted On\nAWBMONEY1,999,2026-11-20\nAWBUNKNOWN,500,2026-11-20\n');
  await a.setInputFiles('[name=report]', rep); await a.click('form:has([name=report]) button'); await a.waitForLoadState();
  ok(/1 partner COD order received; 1 line/.test(await a.locator('.alert').first().innerText()), 'courier file: 1 received, 1 not matched');
  await t.goto(U + 'partner/money.php');
  ok(/₹1,069\.00/.test(await t.locator('.pt-balance b').innerText()), 'partner balance 70 + COD 999 = ₹1,069');
  ok(/ready/.test(await t.locator('.pt-acts a[href="#payout"]').innerText()), 'Money tiles: “Get COD money — ₹… ready”');
  await t.click('.pt-acts a[href="#payout"]'); await t.fill('#payout + form [name=note]', 'moneyco@upi'); await t.click('button:has-text("Ask for payout")'); await t.waitForLoadState();
  await a.goto(U + 'partner_money.php');
  const row = a.locator('.pm-row', { hasText: 'payout' });
  await row.locator('[name=ref]').fill('NEFT999'); await row.locator('button[value=paid]').click(); await a.waitForLoadState();
  await t.reload();
  ok(/₹0\.00/.test(await t.locator('.pt-balance b').innerText()) && /Paid out to you/.test(await t.locator('.pt-list').first().innerText()), 'payout paid → balance ₹0');

  // 7. GSTIN + credit limit 1000: GST invoice; over the limit → waits
  await a.goto(U + 'admin/partners.php?id=' + pid);
  await a.selectOption('[name=pay_mode]', 'credit'); await a.fill('[name=credit_limit]', '1000'); await a.fill('[name=gstin]', '29ABCDE1234F1Z5'); await a.check('[name=gst_add]');
  await a.click('button:has-text("Save prices")'); await a.waitForLoadState();
  sql(`UPDATE partners SET pay_mode = 'wallet' WHERE id = ${pid}`);
  const gstSize = +execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; echo filesize(upload_dir() . "/" . setting("partner_qr_gst"));'`).toString();
  ok((await (await t.request.get(U + 'partner/qr.php')).body()).length === gstSize, 'with a GSTIN → the GST QR code');
  sql(`UPDATE partners SET pay_mode = 'credit' WHERE id = ${pid}`);
  let phoneN = 10;
  const send = async () => { phoneN++; await t.goto(U + 'partner/order.php?new=1'); await t.selectOption('[data-tshirt]', gsm + '|' + prod); await t.locator('.pt-sw').first().click();
    await t.locator('.pt-step >> nth=0 >> [data-d="1"]').click(); await t.fill('[name=ship_phone]', '98765000' + phoneN); await t.fill('[name=ship_name]', 'GST Buyer ' + Math.random());
    await t.fill('[name=ship_address]', '2 Tax Street'); await t.fill('[name=ship_pincode]', '560001'); await t.click('#ptSend'); await t.waitForLoadState(); return new URL(t.url()).searchParams.get('id'); };
  const g1 = await send();
  ok(/GST/.test(await t.locator('.pt-cost').innerText()), 'with GSTIN + GST on: cost shows GST');
  await a.goto(U + 'partner_orders.php');
  await a.locator(`.po-card[data-po="${g1}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  const gb = sql(`SELECT CONCAT_WS('|', b.branding, b.bill_gstin, b.inter_state, b.tax_total > 0) FROM partner_orders po JOIN bills b ON b.id = po.bill_id WHERE po.id = ${g1}`);
  ok(gb === 'looma|29ABCDE1234F1Z5|1|1', 'GST invoice: our branding, their GSTIN, Karnataka = inter-state (IGST): ' + gb);
  await t.goto(U + 'partner/money.php');
  ok(await t.locator('h2:has-text("GST invoices")').count() === 1 && /You owe Looma/.test(await t.locator('.pt-balance').innerText()), 'partner sees the GST invoice and “You owe Looma”');
  const g2 = await send(); const g3 = await send();
  await a.goto(U + 'partner_orders.php');
  await a.locator(`.po-card[data-po="${g2}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  ok(/Over the credit limit/.test(await a.locator(`.po-card[data-po="${g3}"] .po-money`).innerText()), 'next order would pass the ₹1000 credit limit → “Over the credit limit”');

  await t.screenshot({ path: __dirname + '/shots/money.png', fullPage: true });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
