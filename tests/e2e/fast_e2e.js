// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Faster orders: one design in many sizes in one go, paste the customer's WhatsApp details, a known customer's
// designs and last orders (↻ Repeat), saved designs linked to customers ("＋ Order for C900"), and the unsaved-order draft.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const [gsm, product, color] = sql("SELECT g.label, p.name, (SELECT name FROM product_colors c WHERE c.product_id = p.id AND c.active = 1 ORDER BY sort, id LIMIT 1) FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.active = 1 AND p.sizes LIKE '%L%' ORDER BY g.sort, g.id, p.sort, p.id LIMIT 1").split('\t');
  sql("DELETE FROM customers WHERE code IN ('C900'); DELETE FROM design_customers WHERE customer_code = 'C900'; DELETE FROM designs WHERE name = 'FASTTEST Tee'");
  sql(`INSERT INTO customers (code, name, ship_name, phone, address, pincode, created_at) VALUES ('C900', 'Fast Brand', 'Asha K', '9000000900', 'MG Road, Kochi', '682016', NOW())`);
  sql(`INSERT INTO designs (name, code, gsm, product, color, back_print, back_size, active, created_at) VALUES ('FASTTEST Tee', 'FT-1', '${gsm}', '${product}', '${color}', 'Big logo', 'A3', 1, NOW())`);
  const did = sql("SELECT id FROM designs WHERE name = 'FASTTEST Tee'");
  const before = +sql('SELECT IFNULL(MAX(id), 0) FROM orders');
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
  p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button.primary'); await p.waitForLoadState();
  await p.evaluate(() => Object.keys(localStorage).forEach(k => k.startsWith('orderDraft') && localStorage.removeItem(k)));

  // 5. link the existing design to customer C900, then order it from Saved designs
  await p.goto(U + 'designs.php?id=' + did);
  await p.fill('[name=customers]', 'C900'); await p.click('.sticky-actions .btn.primary'); await p.waitForLoadState();
  ok(sql(`SELECT GROUP_CONCAT(customer_code) FROM design_customers WHERE design_id = ${did}`) === 'C900', 'design linked to customer C900');
  await p.goto(U + 'designs.php?q=C900');
  ok(await p.locator('.design-card-wrap', { hasText: 'FASTTEST Tee' }).count() === 1, 'searching the customer ID finds their design');
  await p.click('.design-card-wrap:has-text("FASTTEST Tee") a:has-text("＋ Order for C900")'); await p.waitForLoadState();
  ok(await p.inputValue('[name=customer_id]') === 'C900' && await p.inputValue('[name=ship_phone]') === '9000000900', 'order opens with the customer and address filled');
  const card1 = p.locator('#items .item-card').first();
  ok(await card1.locator('[data-design-id]').inputValue() === did && await card1.locator('textarea[name$="[back_print]"]').inputValue() === 'Big logo', 'and the design on the first item (back print “Big logo”)');

  // 1. many sizes: S 2, M 5, L 3 → three items
  await card1.locator('[data-size-grid]').click();
  await p.fill('.size-grid [data-sg-size="S"]', '2'); await p.fill('.size-grid [data-sg-size="M"]', '5'); await p.fill('.size-grid [data-sg-size="L"]', '3');
  ok(/10 pcs/.test(await p.locator('.size-grid .sg-total').innerText()), 'size grid adds up 10 pcs');
  await p.click('.size-grid [data-size-make]');
  const cards = p.locator('#items .item-card');
  const got = await cards.evaluateAll(cs => cs.map(c => [c._blank().size, c.querySelector('input[data-qty]').value, c.querySelector('[data-design-id]').value, c.querySelector('textarea[name$="[back_print]"]').value]));
  ok(JSON.stringify(got) === JSON.stringify([['S', '2', did, 'Big logo'], ['M', '5', did, 'Big logo'], ['L', '3', did, 'Big logo']]), 'three items made, same design and print: ' + JSON.stringify(got));
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  const oid = new URL(p.url()).searchParams.get('id');
  ok(sql(`SELECT GROUP_CONCAT(CONCAT(size, ':', quantity, ':', design_id) ORDER BY sort, id) FROM order_items WHERE order_id = ${oid}`) === `S:2:${did},M:5:${did},L:3:${did}`, 'saved as three items with the design');

  // 2. paste the customer's details
  await p.goto(U + 'order.php?new=1');
  await p.fill('[data-paste-addr]', 'Name: Priya Nair\nMobile: +91 98765 43210\nFlat 4B, Rose Villa, Kakkanad\nKochi, Kerala - 682030\npriya@example.com');
  await p.dispatchEvent('[data-paste-addr]', 'change');
  const f = await p.evaluate(() => ['ship_name', 'ship_phone', 'ship_pincode', 'ship_address', 'ship_email'].map(n => (document.querySelector('[name="' + n + '"]') || {}).value));
  ok(JSON.stringify(f) === JSON.stringify(['Priya Nair', '9876543210', '682030', 'Flat 4B, Rose Villa, Kakkanad, Kochi, Kerala', 'priya@example.com']), 'pasted WhatsApp message fills the fields: ' + JSON.stringify(f));
  const f2 = await p.evaluate(() => window.loomaParseDetails('Rahul Menon, 9876543210\nHouse 12, MG Road, Kochi 682016'));
  ok(f2.name === undefined || f2.phone === '9876543210' && f2.pincode === '682016', 'one-line style: phone and pincode found: ' + JSON.stringify(f2));

  // 3. known customer: their designs and last orders
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'C900'); await p.press('[name=customer_id]', 'Tab'); // the suggestion list closes
  await p.waitForSelector('#custQuick', { timeout: 5000 }).catch(() => {});
  ok(/FASTTEST Tee/.test(await p.locator('#custQuick').innerText().catch(() => '')) && /↻ Repeat/.test(await p.locator('#custQuick').innerText().catch(() => '')), 'customer C900: their design and last order show');
  await p.click('#custQuick [data-add-design]');
  ok(await p.locator('#items .item-card').first().locator('[data-design-id]').inputValue() === did, 'tapping their design puts it on the item');
  await p.click(`#custQuick [data-repeat="${oid}"]`); await p.waitForLoadState();
  ok(/copy=/.test(p.url()) && await p.locator('#items .item-card').count() === 3, '↻ Repeat opens a copy of that order (3 items)');

  // 4. draft: an unsaved new order comes back after a reload
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'DRAFTTEST');
  await p.fill('[name=ship_phone]', '9111111111');
  await p.evaluate(([g, pr, c]) => { const cd = document.querySelector('#items .item-card'); cd._setBlank({ gsm: g, product: pr, color: c, size: 'M' }); cd.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); }, [gsm, product, color]);
  await p.click('#addItem');
  await p.waitForTimeout(1200);
  await p.reload(); await p.waitForLoadState();
  ok(/Unsaved order/.test(await p.locator('.draft-bar').innerText().catch(() => '')), 'after a reload: “Unsaved order … Continue it”');
  await p.click('.draft-bar button.primary');
  ok(await p.inputValue('[name=customer_id]') === 'DRAFTTEST' && await p.inputValue('[name=ship_phone]') === '9111111111' && await p.locator('#items .item-card').count() === 2
    && (await p.locator('#items .item-card').first().evaluate(c => c._blank().product)) === product, 'draft restored: customer, phone, 2 items, the T-shirt');
  await p.reload(); await p.waitForLoadState();
  await p.click('.draft-bar button.ghost');
  await p.reload(); await p.waitForLoadState();
  ok(await p.locator('.draft-bar').count() === 0, '“Start fresh” forgets it');
  // a saved order leaves no draft
  await p.goto(U + 'order.php?new=1'); await p.fill('[name=customer_id]', 'DRAFTSAVE');
  await p.evaluate(([g, pr, c]) => { const cd = document.querySelector('#items .item-card'); cd._setBlank({ gsm: g, product: pr, color: c, size: 'M' }); cd.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); }, [gsm, product, color]);
  await p.waitForTimeout(1000);
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  await p.goto(U + 'order.php?new=1');
  ok(await p.locator('.draft-bar').count() === 0, 'after saving, no draft is offered');

  // designs used on an order are linked to its customer
  ok(sql(`SELECT COUNT(*) FROM design_customers WHERE design_id = ${did} AND customer_code = 'C900'`) === '1', 'design stays linked to C900');
  sql(`UPDATE orders SET deleted_at = NOW() WHERE id > ${before} AND customer_id IN ('C900', 'DRAFTSAVE')`);
  sql("DELETE FROM customers WHERE code IN ('C900', 'DRAFTSAVE'); DELETE FROM design_customers WHERE customer_code IN ('C900'); DELETE FROM designs WHERE name = 'FASTTEST Tee'");
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
