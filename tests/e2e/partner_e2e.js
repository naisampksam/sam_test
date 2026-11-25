// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Print-on-demand partners: the admin adds a partner and a login; the partner logs in at /partner/ (orders.loomaapparels.com),
// saves a design, sends an order; the team accepts it (a normal order: customer = partner, partner's brand on the label,
// design mock-ups attached) or not (with why); the partner sees status and tracking. Partners never reach staff pages
// or another partner's orders and photos.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const IMG = __dirname + '/shots/partner-mock.png';
(async () => {
  execSync(`php -r '$i = imagecreatetruecolor(600, 600); imagefill($i, 0, 0, imagecolorallocate($i, 20, 20, 20)); imagestring($i, 5, 200, 290, "KANYE TEST", imagecolorallocate($i, 255, 255, 255)); imagepng($i, "${IMG}");'`);
  const b = await chromium.launch(); const errs = [];
  const ctx = async () => { const c = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const a = await ctx();
  await a.goto(U + 'login.php'); await a.fill('[name=username]', 'admin'); await a.fill('[name=password]', 'admin12345'); await a.click('button.primary'); await a.waitForLoadState();
  await a.goto(U + 'partner_orders.php'); // tables are made on the first logged-in page after an update
  // clean slate
  const old = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM partners WHERE code IN ('PTEST1', 'PTEST2')");
  if (old) {
    sql(`UPDATE orders SET deleted_at = NOW() WHERE id IN (SELECT order_id FROM partner_orders WHERE partner_id IN (${old}) AND order_id IS NOT NULL)`);
    sql(`DELETE FROM partner_orders WHERE partner_id IN (${old})`); sql(`DELETE FROM partner_users WHERE partner_id IN (${old})`);
    sql(`UPDATE designs SET active = 0 WHERE id IN (SELECT design_id FROM design_customers WHERE customer_code IN ('PTEST1', 'PTEST2'))`);
    sql(`DELETE FROM design_customers WHERE customer_code IN ('PTEST1', 'PTEST2')`); sql(`DELETE FROM partners WHERE id IN (${old})`);
  }

  // 1. admin: add two partners and a login each
  const addPartner = async (name, code, prefix, brand) => {
    await a.goto(U + 'admin/partners.php?new=1');
    await a.fill('[name=name]', name); await a.fill('[name=code]', code); await a.fill('[name=prefix]', prefix); await a.fill('[name=slip_brand]', brand);
    await a.click('form button.primary:has-text("Save partner")'); await a.waitForLoadState();
    return new URL(a.url()).searchParams.get('id');
  };
  const p1 = await addPartner('Test Crew', 'PTEST1', 'TC', 'Test Crew Brand');
  const p2 = await addPartner('Other Co', 'PTEST2', 'OT', 'Other Brand');
  ok(p1 && p2, 'admin adds two partners: ' + p1 + ', ' + p2);
  ok(sql("SELECT COUNT(*) FROM customers WHERE code IN ('PTEST1', 'PTEST2')") === '2', 'each partner is also a customer (orders are made under their customer ID)');
  const addLogin = async (pid, user, pass) => {
    await a.goto(U + 'admin/partners.php?id=' + pid);
    await a.fill('.pt-newlogin [name=username]', user); await a.fill('.pt-newlogin [name=password]', pass); await a.click('.pt-newlogin button'); await a.waitForLoadState();
  };
  await a.goto(U + 'admin/partners.php?id=' + p1);
  await a.fill('.pt-newlogin [name=username]', 'tcweak'); await a.fill('.pt-newlogin [name=password]', 'short'); await a.click('.pt-newlogin button'); await a.waitForLoadState();
  ok(/at least 8 characters/.test(await a.locator('.alert.err').innerText()), 'weak password refused');
  await addLogin(p1, 'tcrew', 'crew12345'); await addLogin(p2, 'otherco', 'other12345');
  ok(sql("SELECT COUNT(*) FROM partner_users WHERE username IN ('tcrew', 'otherco')") === '2', 'logins added');
  await a.goto(U + 'admin/partners.php?id=' + p1);
  ok(await a.locator('.pt-newlogin [name=username]').count() === 1 && /admin\/partners/.test(a.url()), 'Partners page opens for the admin');

  // 2. partner logs in at /partner/ — staff pages are not theirs
  const t = await ctx();
  await t.goto(U + 'partner/');
  await t.fill('[name=username]', 'tcrew'); await t.fill('[name=password]', 'wrong-pass1'); await t.click('.pt-login button'); await t.waitForLoadState();
  ok(/Wrong username or password/.test(await t.locator('.pt-note.err').innerText()), 'wrong password refused');
  await t.fill('[name=username]', 'tcrew'); await t.fill('[name=password]', 'crew12345'); await t.click('.pt-login button'); await t.waitForLoadState();
  ok(/Test Crew/.test(await t.locator('.pt-brand').innerText()), 'partner logged in: their name in the bar');
  for (const pg of ['orders.php', 'dashboard.php', 'partner_orders.php', 'admin/partners.php', 'designs.php', 'image.php?f=2026/01/aaaaaaaaaaaaaaaaaaaaaaaa.jpg']) {
    const r = await t.goto(U + pg);
    ok(/login\.php/.test(t.url()) || r.status() >= 400, `partner can’t open staff page ${pg} → ${t.url().replace(U, '')} (${r.status()})`);
  }
  // staff login does not work on the partner page, partner login not on the staff page
  const s2 = await ctx(); await s2.goto(U + 'partner/'); await s2.fill('[name=username]', 'admin'); await s2.fill('[name=password]', 'admin12345'); await s2.click('.pt-login button'); await s2.waitForLoadState();
  ok(await s2.locator('.pt-note.err').count() === 1, 'a staff username can’t log in to the partner portal');
  await s2.goto(U + 'login.php'); await s2.fill('[name=username]', 'tcrew'); await s2.fill('[name=password]', 'crew12345'); await s2.click('button.primary'); await s2.waitForLoadState();
  ok(/login\.php/.test(s2.url()), 'a partner username can’t log in to the staff app');

  // 3. no design yet → order form says save it first; save a design
  await t.goto(U + 'partner/order.php?new=1');
  ok(/Save your first product/.test(await t.locator('.pt-empty').innerText()), 'new order without products: “Save your first product”');
  await t.goto(U + 'partner/designs.php');
  // a product: T-shirt + colour + mock-up photo (uploaded)
  const firstProduct = await t.locator('#pdTshirt option').nth(1).getAttribute('value');
  await t.selectOption('#pdTshirt', firstProduct); await t.locator('#pdColors .pt-sw').first().click();
  const firstColour = await t.locator('#pdColors .pt-sw input').first().getAttribute('value');
  await t.click('.pt-mk-mode label:has-text("Upload photos")');
  await t.fill('[name=name]', 'Kanye Test'); await t.setInputFiles('[name="photos[]"]', IMG); await t.fill('[name=note]', 'Front A3');
  await t.click('#newDesign button.pt-btn.filled'); await t.waitForLoadState();
  const did = sql("SELECT d.id FROM designs d JOIN design_customers dc ON dc.design_id = d.id WHERE dc.customer_code = 'PTEST1' AND d.name = 'Kanye Test' AND d.active = 1");
  ok(did && sql(`SELECT COUNT(*) FROM design_images WHERE design_id = ${did}`) === '1', 'design saved into Saved designs, linked to the partner, with its photo');
  const img = await t.locator('.pt-dcard img').first().getAttribute('src');
  ok((await t.request.get(new URL(img, U).href)).status() === 200, 'partner sees their design photo');
  // other partner's photo: not theirs
  const o = await ctx(); await o.goto(U + 'partner/'); await o.fill('[name=username]', 'otherco'); await o.fill('[name=password]', 'other12345'); await o.click('.pt-login button'); await o.waitForLoadState();
  ok((await o.request.get(new URL(img, U).href)).status() === 404, 'another partner can’t open that photo');
  await o.goto(U + 'partner/designs.php');
  ok(await o.locator('.pt-dcard').count() === 0, 'another partner doesn’t see the design');

  // 4. send an order: tap the design picture, T-shirt, colour, + on sizes; checks first
  await t.goto(U + 'partner/order.php?new=1');
  const line = t.locator('[data-line]').first();
  ok(await line.locator('.pt-tile input:checked').count() === 1, 'only one design: it is already chosen');
  ok(await line.locator('.pt-psum').isVisible() && await line.locator('[data-tshirt]').inputValue() === firstProduct && await line.locator('.pt-sw input:checked').getAttribute('value') === firstColour
    && await line.locator('[data-tshirt]').isHidden(), 'saved product fills its T-shirt and colour, shown in one row');
  await line.locator('[data-pedit]').click();
  ok(await line.locator('[data-tshirt]').isVisible(), '“Edit” on the product opens T-shirt and colour');
  await line.locator('[data-tshirt]').selectOption(firstProduct);
  await line.locator('.pt-sw').first().click();
  await line.locator('.pt-step >> nth=0 >> [data-d="1"]').click();
  await line.locator('.pt-step >> nth=1 >> [data-d="1"]').click(); await line.locator('.pt-step >> nth=1 >> [data-d="1"]').click();
  ok(await t.locator('#ptCount').innerText() === '3 T-shirts', 'tapping + on sizes counts: ' + await t.locator('#ptCount').innerText());
  // paste from WhatsApp fills the customer
  // customer details: one box each
  await t.fill('[name=ship_name]', 'Yadhal'); await t.fill('[name=ship_address]', 'Nila manzil, Muthalamada'); await t.fill('[name=landmark]', 'Chulliyardam');
  await t.fill('[name=ship_pincode]', '678507'); await t.fill('[name=ship_phone]', '12345');
  ok(await t.locator('#ptPasteText').count() === 0, 'no “paste customer details” box: the details are typed in each box');
  await t.click('#ptSend'); await t.waitForLoadState();
  ok(/10-digit mobile/.test(await t.locator('.pt-note.err').innerText()), 'wrong phone refused: ' + (await t.locator('.pt-note.err').innerText()).slice(0, 60));
  ok(await t.inputValue('[name=ship_name]') === 'Yadhal' && await t.locator(`[data-line] .pt-tile input[value="${did}"]:checked`).count() === 1 && await t.locator('#ptCount').innerText() === '3 T-shirts',
    'what was typed and tapped stays after the error');
  await t.fill('[name=ship_phone]', '+91 94460 32140');
  // another line copies the design and T-shirt; remove it again
  await t.click('#ptAddLine');
  ok(await t.locator('[data-line]').count() === 2 && await t.inputValue('[data-line] >> nth=1 >> [data-tshirt]') === firstProduct, '“＋ Another design or colour” copies design and T-shirt');
  // an empty line blocks sending, with the line marked
  await t.click('#ptSend');
  ok(await t.locator('[data-line].pt-bad').count() === 1 && /at least one size/.test(await t.locator('#ptCheck').innerText()), 'an incomplete line is marked before sending');
  await t.locator('[data-line] >> nth=1 >> [data-rm]').click();
  await t.click('.pt-seg label:has([value=cod])'); await t.fill('[name=cod_amount]', '699'); await t.locator('label.pt-switch:has([name=urgent])').click();
  await t.click('#ptSend'); await t.waitForLoadState();
  const po = sql("SELECT CONCAT_WS('|', id, ref, status, ship_phone, payment_type, cod_amount, urgent) FROM partner_orders WHERE partner_id = " + p1 + ' ORDER BY id DESC LIMIT 1').split('|');
  ok(po[1] === 'TC-0001' && po[2] === 'new' && po[3] === '9446032140' && po[4] === 'cod' && po[5] === '699.00' && po[6] === '1', 'order sent: TC-0001, phone cleaned to 9446032140, COD 699, urgent: ' + po.join('|'));
  ok(/Waiting for Looma/.test(await t.locator('.pt-head .pt-pill').innerText()), 'partner sees “Waiting for Looma”');
  // the same order again within a day: asks first
  await t.goto(U + 'partner/order.php?id=' + po[0] + '&edit=1'); // change while waiting
  await t.locator('summary:has-text("Courier, note")').click(); await t.fill('[name=notes]', 'Gift wrap please'); await t.click('#ptSend'); await t.waitForLoadState();
  ok(sql(`SELECT notes FROM partner_orders WHERE id = ${po[0]}`) === 'Gift wrap please', 'partner can change it while waiting');
  // another partner can't open it
  const r404 = await o.goto(U + 'partner/order.php?id=' + po[0]);
  ok(r404.status() === 404, 'another partner can’t open the order (404)');

  // 5. the team: menu count, accept → normal order
  await a.goto(U + 'orders.php');
  ok(/Partner orders \(\d+\)/.test(await a.locator('body').textContent()), 'menu shows “Partner orders (n)”');
  await a.goto(U + 'partner_orders.php');
  const card = a.locator(`.po-card[data-po="${po[0]}"]`);
  ok(/new design · check it/i.test(await card.innerText()) && /⚡ Urgent/i.test(await card.innerText()), 'Accept list: new-design flag and ⚡ Urgent shown');
  await card.locator('button:has-text("✓ Accept")').click(); await a.waitForLoadState();
  const oid = sql(`SELECT order_id FROM partner_orders WHERE id = ${po[0]} AND status = 'accepted'`);
  ok(+oid > 0, 'accepted → Looma order ' + oid);
  const row = sql(`SELECT CONCAT_WS('|', customer_id, customer_name, order_ref, ship_name, ship_phone, ship_pincode, payment_type, cod_amount, slip_brand, (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id), (SELECT COUNT(*) FROM order_images WHERE order_id = o.id)) FROM orders o WHERE id = ${oid}`).split('|');
  ok(row[0] === 'PTEST1' && row[1] === 'Test Crew' && row[2] === 'TC-0001' && row[3] === 'Yadhal' && row[4] === '9446032140' && row[5] === '678507' && row[6] === 'cod' && row[7] === '699.00' && row[8] === 'Test Crew Brand',
    'order: customer = partner, their ref, end-customer address, COD, partner brand on the label: ' + row.slice(0, 9).join('|'));
  ok(row[9] === '3' && row[10] === '2', 'items: 3 pieces in 2 sizes, design mock-up attached to both: ' + row[9] + '/' + row[10]);
  ok(/URGENT/.test(sql(`SELECT notes FROM orders WHERE id = ${oid}`)) && /Landmark: Chulliyardam/.test(sql(`SELECT ship_address FROM orders WHERE id = ${oid}`)), 'urgent in the notes, landmark in the address');
  ok(sql(`SELECT JSON_EXTRACT(extra, '$.partner_new') IS NULL FROM designs WHERE id = ${did}`) === '1', 'design no longer flagged new once accepted');
  // partner can no longer change it
  await t.goto(U + 'partner/order.php?id=' + po[0]);
  ok(/Accepted/.test(await t.locator('.pt-head .pt-pill').innerText()) && await t.locator('.pt-actions a:has-text("Change")').count() === 0, 'partner: “Accepted”, no more changing');
  // shipped with tracking → partner sees it
  sql(`UPDATE orders SET print_processed = 1, printed = 1, packed = 1, shipped = 1, shipped_at = NOW(), courier = 'Delhivery', tracking_no = 'TRK123456' WHERE id = ${oid}`);
  await t.goto(U + 'partner/index.php?tab=shipped');
  ok(/Shipped/.test(await t.locator(`.pt-order[data-po="${po[0]}"]`).innerText()) && /Delhivery TRK123456/.test(await t.locator(`.pt-order[data-po="${po[0]}"]`).innerText()), 'partner list: Shipped · Delhivery TRK123456');
  await t.goto(U + 'partner/index.php?q=TRK123456');
  ok(await t.locator('.pt-order').count() === 1, 'partner can search by tracking number');

  // 6. not accepted (with why) and cancelled
  const send = async (name) => { await t.goto(U + 'partner/order.php?new=1');
    await t.fill('[name=ship_name]', name); await t.fill('[name=ship_phone]', '9876543210'); await t.fill('[name=ship_address]', 'Some street, Kochi'); await t.fill('[name=ship_pincode]', '682001');
    await t.locator('.pt-step >> nth=0 >> [data-d="1"]').click();
    await t.click('#ptSend'); await t.waitForLoadState(); return new URL(t.url()).searchParams.get('id'); };
  const r2 = await send('Rejected Guy');
  await a.goto(U + 'partner_orders.php');
  const c2 = a.locator(`.po-card[data-po="${r2}"]`);
  await c2.locator('summary:has-text("Not accept")').click(); await c2.locator('[name=reason]').fill('Pincode not serviceable'); await c2.locator('button:has-text("Send")').click(); await a.waitForLoadState();
  await t.goto(U + 'partner/order.php?id=' + r2);
  ok(/Not accepted/.test(await t.locator('.pt-head .pt-pill').innerText()) && /Pincode not serviceable/.test(await t.locator('.pt-note.err').innerText()), 'partner sees “Not accepted” and why');
  const r3 = await send('Cancel Me');
  t.once('dialog', d => d.accept()); await t.click('button:has-text("Cancel order")'); await t.waitForLoadState();
  ok(sql(`SELECT status FROM partner_orders WHERE id = ${r3}`) === 'cancelled', 'partner cancels while waiting');
  // a double-sent order asks first
  await send('Twin'); await send('Twin');
  ok(/already sent today/.test(await t.locator('.pt-note.err').innerText()), 'same order twice in a day: asks “send it again?”');

  // 6b. bulk order: delivered to the partner (their saved address), prepaid, big quantities
  sql(`UPDATE partners SET phone = '9000011111', ret_address = 'Test Crew Warehouse, Kochi', ret_pincode = '682030' WHERE id = ${p1}`);
  await t.goto(U + 'partner/order.php?new=1');
  await t.click('.pt-dest label:has([value="1"])');
  ok(await t.inputValue('[name=ship_name]') === 'Test Crew' && /Warehouse/.test(await t.inputValue('[name=ship_address]')) && await t.inputValue('[name=ship_pincode]') === '682030', 'bulk: the partner’s own address is filled in');
  ok(await t.locator('.pt-seg label:has([value=cod])').isHidden() && await t.locator('.pt-paste').count() === 0, 'bulk: no COD, no “paste customer details”');
  await t.locator('.pt-step >> nth=0 >> input').fill('600');
  await t.click('#ptSend'); await t.waitForLoadState();
  const bk = sql(`SELECT CONCAT_WS('|', bulk, payment_type, ship_name, ship_pincode) FROM partner_orders WHERE partner_id = ${p1} ORDER BY id DESC LIMIT 1`);
  ok(bk === '1|prepaid|Test Crew|682030' && /600/.test(sql(`SELECT items FROM partner_orders WHERE partner_id = ${p1} ORDER BY id DESC LIMIT 1`)), 'bulk order of 600 sent, prepaid, to the partner: ' + bk);
  await a.goto(U + 'partner_orders.php');
  ok(await a.locator('.po-card .mini-tag:has-text("Bulk")').count() >= 1, 'team sees “📦 Bulk — to the partner”');

  // 7. switching the partner off logs them out
  await a.goto(U + 'admin/partners.php?id=' + p1);
  await a.uncheck('form:has([name=action][value=partner]) [name=active]'); await a.click('button:has-text("Save partner")'); await a.waitForLoadState();
  await t.goto(U + 'partner/index.php');
  ok(await t.locator('[name=username]').count() === 1, 'partner switched off → logged out');

  // 8. the partner address (orders.loomaapparels.com): staff pages go to the partner portal
  const host = await (await b.newContext({ extraHTTPHeaders: { 'X-Forwarded-Host': 'partners.loomaapparels.com' } })).newPage();
  const hr = await host.request.get(U + 'dashboard.php', { maxRedirects: 0 });
  ok(hr.status() === 302 && hr.headers()['location'] === 'https://partners.loomaapparels.com/partner/index.php', 'partners.loomaapparels.com/dashboard.php → https://partners.loomaapparels.com/partner/index.php (full https address)');
  const att = await host.goto(U + 'attendance/'); ok(att.status() === 404, 'attendance not on the partner address');

  await t.screenshot({ path: __dirname + '/shots/partner.png', fullPage: true });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
