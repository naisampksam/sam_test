// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Orders → “To process print”: every order made but not print processed yet. Not done on the order day → write why
// (e.g. design not finalized); the reason shows on the card and the order page. Also: Embroidery → DST is back for staff.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); return p; };
  const a = await login('admin', 'admin12345');
  // two open orders with something to print: one made yesterday, one today, neither print processed
  const ids = sql(`SELECT o.id FROM orders o WHERE o.deleted_at IS NULL AND o.shipped = 0 AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.plain = 0) ORDER BY o.id DESC LIMIT 2`).split('\n');
  ok(ids.length === 2, 'two open orders to test with: ' + ids.join(', '));
  const [old, fresh] = ids;
  const keep = sql(`SELECT GROUP_CONCAT(CONCAT_WS('|', id, created_at, print_processed, IFNULL(print_processed_at, ''), IFNULL(print_processed_by, ''), printed) SEPARATOR ';') FROM orders WHERE id IN (${ids})`);
  sql(`UPDATE orders SET print_processed = 0, print_processed_at = NULL, printed = 0, pp_reason = '', pp_reason_by = NULL, pp_reason_at = NULL WHERE id IN (${ids})`);
  sql(`UPDATE orders SET created_at = NOW() - INTERVAL 1 DAY WHERE id = ${old}`);
  sql(`UPDATE orders SET created_at = NOW() WHERE id = ${fresh}`);

  // 1. the tab, next to “To print”
  await a.goto(U + 'orders.php');
  const tabs = await a.locator('nav.tabs a').allInnerTexts();
  ok(/To process print/.test(tabs[1]) && /To print/.test(tabs[2]), 'tab “To process print” sits before “To print”: ' + tabs.slice(0, 3).map(t => t.replace(/\s+/g, ' ')).join(' | '));
  await a.click('nav.tabs a:has-text("To process print")'); await a.waitForLoadState();
  const want = +sql(`SELECT COUNT(*) FROM orders o WHERE o.deleted_at IS NULL AND o.print_processed = 0 AND o.printed = 0 AND o.shipped = 0 AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.plain = 0)`);
  ok(+(await a.locator('nav.tabs a.on .count').innerText()) === want, `count = orders not print processed (${want})`);
  const cardOld = a.locator(`.order-card[data-id="${old}"]`), cardNew = a.locator(`.order-card[data-id="${fresh}"]`);
  ok(await cardOld.count() === 1 && await cardNew.count() === 1, 'both orders are listed');
  ok(await cardOld.locator('.pp-reason.need').count() === 1 && /Not print processed since/.test(await cardOld.locator('.pp-since').innerText()), 'yesterday’s order: “⚠ Not print processed since …” in orange');
  ok(await cardNew.locator('.pp-reason').count() === 1 && await cardNew.locator('.pp-reason.need').count() === 0, 'today’s order: a reason can be written, not marked late');
  ok(await a.locator('#dl_pp_reasons option[value="Design not finalized"]').count() === 1, 'suggestions include “Design not finalized”');

  // 2. write why
  await cardOld.locator('[name=reason]').fill('Design not finalized');
  await cardOld.locator('.pp-reason button').click(); await a.waitForTimeout(600);
  ok(sql(`SELECT CONCAT(pp_reason, '|', pp_reason_by IS NOT NULL) FROM orders WHERE id = ${old}`) === 'Design not finalized|1', 'reason saved with who wrote it');
  ok(await cardOld.locator('.pp-reason.need').count() === 0 && /✓ .+ · /.test(await cardOld.locator('.pp-by').innerText()), 'card no longer orange, shows who and when: ' + await cardOld.locator('.pp-by').innerText());
  // the card link still opens the order, the box does not
  await a.reload();
  ok(await a.inputValue(`.order-card[data-id="${old}"] [name=reason]`) === 'Design not finalized', 'reason still there after reload');
  // 3. other tabs: only late or explained orders show the box
  await a.goto(U + 'orders.php?tab=open');
  ok(await a.locator(`.order-card[data-id="${old}"] .pp-reason`).count() === 1 && await a.locator(`.order-card[data-id="${fresh}"] .pp-reason`).count() === 0, '“All open”: the late order shows its reason; today’s does not');
  // 4. order page
  await a.goto(U + 'order.php?id=' + old);
  ok(await a.inputValue('.ticks-panel [name=reason]') === 'Design not finalized', 'order page shows the reason under the ticks');
  // 5. print processed → leaves the tab
  await a.click('.ticks-panel button.tick[data-stage="print_processed"]'); await a.waitForTimeout(600);
  await a.goto(U + 'orders.php?tab=process');
  ok(await a.locator(`.order-card[data-id="${old}"]`).count() === 0, 'once print processed it leaves “To process print”');

  // 6. staff who can see print processed see the tab; Embroidery → DST is in their menu
  const r = await login('rahul', 'pass1234');
  await r.goto(U + 'orders.php');
  ok(await r.locator('nav.tabs a:has-text("To process print")').count() === 1, 'staff (Rahul) see “To process print”');
  const n = await login('anu', 'pass1234');
  const res = await n.goto(U + 'embroidery.php');
  ok(res.status() === 200 && await n.locator('input[type=file][name=design]').count() === 1, 'Embroidery → DST opens for staff (upload box there)');
  ok(await n.locator('a[href$="embroidery.php"]').count() >= 1, 'Embroidery → DST is in the menu');

  // put things back
  keep.split(';').forEach(x => { const [id, c, pp, ppa, ppb, pr] = x.split('|');
    sql(`UPDATE orders SET created_at = '${c}', print_processed = ${pp}, print_processed_at = ${ppa ? `'${ppa}'` : 'NULL'}, print_processed_by = ${ppb || 'NULL'}, printed = ${pr}, pp_reason = '', pp_reason_by = NULL, pp_reason_at = NULL WHERE id = ${id}`); });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
