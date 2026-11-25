// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// A partner's Shopify sends orders by webhook (signed with their key; no access to their store): wrong signatures are
// refused, repeats and old order numbers ignored; an unlinked product waits for "Link" (once), colour/size come from
// the variant ("Lavander / 2XL" → Lavender, XXL), COD amount from Shopify; cancellations; missing order numbers;
// the Shopify orders export fills gaps; change requests from the portal reach the team.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
const SECRET = 'shpss_test_secret_0123456789abcdef';
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = async () => { const c = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const a = await ctx();
  await a.goto(U + 'login.php'); await a.fill('[name=username]', 'admin'); await a.fill('[name=password]', 'admin12345'); await a.click('button.primary'); await a.waitForLoadState();
  await a.goto(U + 'partner_orders.php');
  // clean slate: partner "Shop Crew" (SHOPT) with one saved design
  const old = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM partners WHERE code = 'SHOPT'");
  if (old) {
    sql(`UPDATE orders SET deleted_at = NOW() WHERE id IN (SELECT order_id FROM partner_orders WHERE partner_id IN (${old}) AND order_id IS NOT NULL)`);
    sql(`DELETE FROM partner_orders WHERE partner_id IN (${old})`); sql(`DELETE FROM partner_users WHERE partner_id IN (${old})`); sql(`DELETE FROM shop_links WHERE partner_id IN (${old})`);
    sql(`UPDATE designs SET active = 0 WHERE id IN (SELECT design_id FROM design_customers WHERE customer_code = 'SHOPT')`); sql("DELETE FROM design_customers WHERE customer_code = 'SHOPT'");
    sql(`DELETE FROM partners WHERE id IN (${old})`);
  }
  // a T-shirt with a 5+ letter colour and XXL
  const cat = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; foreach (catalog() as $g) foreach ($g["products"] as $p) { $c = array_values(array_filter(array_column($p["colors"], "name"), fn($n) => strlen($n) >= 5)); if ($c && in_array("XXL", $p["sizes"])) { echo json_encode([$g["gsm"], $p["name"], $c[0]]); exit; } }'`).toString());
  const [gsm, prod, colour] = cat;
  const typo = colour.slice(0, -2) + colour.slice(-1) + colour.slice(-2, -1); // two letters swapped, e.g. "Lavedner"
  await a.goto(U + 'admin/partners.php?new=1');
  await a.fill('[name=name]', 'Shop Crew'); await a.fill('[name=code]', 'SHOPT'); await a.fill('[name=slip_brand]', 'Shop Crew');
  await a.click('button:has-text("Save partner")'); await a.waitForLoadState();
  const pid = new URL(a.url()).searchParams.get('id');
  sql(`INSERT INTO designs (name, code, extra, active, created_at) VALUES ('Akatsuki Tee', '', '{}', 1, NOW())`);
  const did = sql("SELECT MAX(id) FROM designs WHERE name = 'Akatsuki Tee'");
  sql(`INSERT INTO design_customers (design_id, customer_code, created_at) VALUES (${did}, 'SHOPT', NOW())`);

  // 1. Shopify settings: key, start number → private webhook address
  await a.check('[name=shop_on]'); await a.fill('[name=shop_secret]', SECRET); await a.fill('[name=shop_from]', '2700');
  await a.click('button:has-text("Save Shopify settings")'); await a.waitForLoadState();
  const hook = await a.inputValue('#shopUrl');
  ok(/partner\/shopify\.php\?k=[a-f0-9]{32}$/.test(hook), 'webhook address made: ' + hook.replace(/k=.*/, 'k=…'));
  ok(await a.inputValue('[name=shop_secret]') === '••••••••', 'signing key saved, not shown again');
  const send = async (body, topic = 'orders/create', key = SECRET) => {
    const raw = JSON.stringify(body);
    const r = await a.request.post(hook, { data: raw, headers: { 'Content-Type': 'application/json', 'X-Shopify-Topic': topic, 'X-Shopify-Hmac-Sha256': crypto.createHmac('sha256', key).update(raw).digest('base64') } });
    return [r.status(), r.status() === 200 ? (await r.json()).result : ''];
  };
  const order = (no, extra = {}) => Object.assign({ id: 900000 + no, name: '#OC' + no, order_number: no, email: 'c' + no + '@x.in', note: '',
    payment_gateway_names: ['Razorpay'], total_price: '799.00', total_outstanding: '0.00', tags: '',
    shipping_address: { name: 'Vysakh P R', address1: 'B 505, Brigade Paramount', address2: 'Near CV Raman Nagar', city: 'Bengaluru', province: 'Karnataka', zip: '560093', phone: '+91 99471 11312' },
    line_items: [{ title: 'Akatsuki – Shadows of Dawn', variant_title: typo + ' / 2XL', quantity: 2, requires_shipping: true }] }, extra);

  // 2. signatures, repeats, old numbers
  ok((await send(order(2701), 'orders/create', 'wrong-key-wrong-key-wrong'))[0] === 401, 'wrong signature → refused (401)');
  const r1 = await send(order(2701, { payment_gateway_names: ['Cash on Delivery (COD)'], total_outstanding: '699.00', tags: 'urgent' }));
  ok(r1[0] === 200 && r1[1] === 'created OC2701', 'signed Shopify order → created OC2701');
  ok((await send(order(2701)))[1] === 'duplicate', 'same order sent again → ignored');
  ok((await send(order(2650)))[1] === 'ignored: before 2700', 'order before the start number → ignored');
  const po = sql("SELECT CONCAT_WS('|', id, ship_name, ship_phone, ship_pincode, landmark, payment_type, cod_amount, urgent, source) FROM partner_orders WHERE ref = 'OC2701' AND partner_id = " + pid).split('|');
  ok(po[2] === '9947111312' && po[3] === '560093' && po[4] === 'Near CV Raman Nagar' && po[5] === 'cod' && po[6] === '699.00' && po[7] === '1' && po[8] === 'shopify',
    'address, phone (+91 removed), landmark from address 2, COD 699, urgent tag: ' + po.slice(1).join('|'));

  // 3. unlinked product: link once, then colour/size come from the variant
  await a.goto(U + 'partner_orders.php');
  const card = a.locator(`.po-card[data-po="${po[0]}"]`);
  ok(await card.locator('.po-link').count() === 1 && await card.locator('button:has-text("✓ Accept")').isDisabled(), 'unlinked product: “Link …” shown, Accept disabled');
  await card.locator('[name=design_id]').selectOption(did); await card.locator('[name=tshirt]').selectOption(gsm + '|' + prod);
  await card.locator('button:has-text("Link")').click(); await a.waitForLoadState();
  const it = JSON.parse(sql(`SELECT items FROM partner_orders WHERE id = ${po[0]}`))[0];
  ok(+it.design_id === +did && it.gsm === gsm && it.color === colour && it.size === 'XXL', `variant “${typo} / 2XL” → ${it.color}, ${it.size}`);
  const r2 = await send(order(2703, { line_items: [{ title: 'Akatsuki – Shadows of Dawn', variant_title: colour + ' / M', quantity: 1 }] }));
  const it2 = JSON.parse(sql(`SELECT items FROM partner_orders WHERE ref = 'OC2703' AND partner_id = ${pid}`))[0];
  ok(r2[1] === 'created OC2703' && +it2.design_id === +did, 'next order with that product: linked by itself');
  // a variant Looma can't read → pick it once on the card
  await send(order(2704, { line_items: [{ title: 'Akatsuki – Shadows of Dawn', variant_title: 'Rainbow / Free size', quantity: 1 }] }));
  await a.goto(U + 'partner_orders.php');
  const c4 = a.locator('.po-card', { hasText: 'OC2704' });
  ok(await c4.locator('text=Pick what Shopify didn').count() === 1, 'unclear colour / size: asked on the card');
  await c4.locator('select[name$="[color]"]').selectOption(colour); await c4.locator('select[name$="[size]"]').selectOption('M'); await c4.locator('button:has-text("Save")').click(); await a.waitForLoadState();
  ok(!(await a.locator('.po-card', { hasText: 'OC2704' }).locator('button:has-text("✓ Accept")').isDisabled()), 'filled → ready to accept');

  // 4. missing numbers + Shopify export fills the gap
  await a.goto(U + 'partner_orders.php?partner=' + pid);
  ok(/#2702/.test(await a.locator('.alert.warn').innerText()), 'missing Shopify order #2702 shown');
  const csv = __dirname + '/shots/shopify-export.csv';
  fs.writeFileSync(csv, 'Name,Email,Financial Status,Lineitem quantity,Lineitem name,Shipping Name,Shipping Address1,Shipping Address2,Shipping City,Shipping Zip,Shipping Province,Shipping Phone,Payment Method,Notes,Id\n'
    + `#OC2702,x@y.in,paid,1,Akatsuki – Shadows of Dawn - ${colour} / L,Anu K,12 MG Road,Opp. park,Kochi,'682001,Kerala,9876543210,Razorpay,,9002702\n`
    + `#OC2701,x@y.in,paid,2,Akatsuki – Shadows of Dawn - ${colour} / XXL,Vysakh,B 505,,Bengaluru,560093,KA,9947111312,COD,,900002701\n`);
  await a.locator('summary:has-text("Upload a Shopify orders export")').click();
  await a.selectOption('.po-import [name=partner_id]', pid); await a.setInputFiles('.po-import [name=export]', csv); await a.click('.po-import button'); await a.waitForLoadState();
  { const msg = (await a.locator('.alert').allInnerTexts()).join(' / '); ok(/1 order added/.test(msg), 'export: 1 order added, the rest skipped — ' + msg.slice(0, 200)); }
  const it3 = JSON.parse(sql(`SELECT items FROM partner_orders WHERE ref = 'OC2702' AND partner_id = ${pid}`))[0];
  ok(it3.size === 'L' && it3.color === colour && sql(`SELECT ship_pincode FROM partner_orders WHERE ref = 'OC2702' AND partner_id = ${pid}`) === '682001', 'export order: linked product, size L, pincode 682001');

  // 5. accept; cancellations
  await a.goto(U + 'partner_orders.php');
  await a.locator(`.po-card[data-po="${po[0]}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  const oid = sql(`SELECT order_id FROM partner_orders WHERE id = ${po[0]}`);
  ok(+oid > 0 && sql(`SELECT CONCAT(order_ref, '|', payment_type, '|', cod_amount, '|', (SELECT SUM(quantity) FROM order_items WHERE order_id = ${oid})) FROM orders WHERE id = ${oid}`) === `OC2701|cod|699.00|2`, 'accepted → order OC2701, COD 699, 2 pcs');
  ok((await send(order(2703), 'orders/cancelled'))[1] === 'cancelled' && sql(`SELECT status FROM partner_orders WHERE ref = 'OC2703' AND partner_id = ${pid}`) === 'cancelled', 'cancelled in Shopify while waiting → cancelled');
  ok((await send(order(2701), 'orders/cancelled'))[1] === 'cancel flagged', 'cancelled in Shopify after accepting → flagged for the team');
  await a.goto(U + 'order.php?id=' + oid);
  ok(/asked for a change/.test(await a.locator('.alert.warn').innerText()) && /Cancelled in Shopify/.test(await a.locator('.alert.warn').innerText()), 'order page shows “Cancelled in Shopify — stop this order”');
  await a.goto(U + 'partner_orders.php?tab=changes');
  await a.locator(`.po-card[data-po="${po[0]}"] [name=reply]`).fill('Stopped before printing');
  await a.locator(`.po-card[data-po="${po[0]}"] button:has-text("✓ Done")`).click(); await a.waitForLoadState();
  ok(sql(`SELECT CONCAT(change_state, '|', change_reply) FROM partner_orders WHERE id = ${po[0]}`) === 'done|Stopped before printing', 'team answers the change: done + reply');

  // 6. the partner: Shopify orders in their list (read-only); change request from the portal
  await a.goto(U + 'admin/partners.php?id=' + pid);
  await a.fill('.pt-newlogin [name=username]', 'shopcrew'); await a.fill('.pt-newlogin [name=password]', 'shop12345'); await a.click('.pt-newlogin button'); await a.waitForLoadState();
  const t = await ctx();
  await t.goto(U + 'partner/'); await t.fill('[name=username]', 'shopcrew'); await t.fill('[name=password]', 'shop12345'); await t.click('.pt-login button'); await t.waitForLoadState();
  ok(await t.locator('.pt-order').count() >= 4, 'partner sees their Shopify orders in the portal');
  const p2 = sql(`SELECT id FROM partner_orders WHERE ref = 'OC2702' AND partner_id = ${pid}`);
  await t.goto(U + 'partner/order.php?id=' + p2);
  ok(/From your Shopify/.test(await t.locator('.pt-actions').innerText()) && await t.locator('.pt-actions a:has-text("Change")').count() === 0, 'Shopify order: change or cancel it in Shopify (read-only here)');
  // accept OC2702, then ask for a change from the portal
  await a.goto(U + 'partner_orders.php');
  await a.locator(`.po-card[data-po="${p2}"] button:has-text("✓ Accept")`).click(); await a.waitForLoadState();
  await t.goto(U + 'partner/order.php?id=' + p2);
  await t.locator('summary:has-text("Request a change")').click(); await t.fill('[name=change]', 'Size XL instead of L please'); await t.click('button:has-text("Send to Looma")'); await t.waitForLoadState();
  ok(/Looking at it/.test(await t.locator('.pt-kv').last().innerText()), 'partner: change request sent, “Looking at it…”');
  await a.goto(U + 'partner_orders.php');
  ok(/Change requests\s*1/.test(await a.locator('nav.tabs').innerText()), 'team: “Change requests 1” tab');
  await a.goto(U + 'partner_orders.php?tab=changes');
  await a.locator(`.po-card[data-po="${p2}"] button:has-text("Not possible")`).click(); await a.waitForLoadState();
  await t.reload();
  ok(/Not possible/.test(await t.locator('.pt-kv').last().innerText()), 'partner sees “Not possible”');

  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
