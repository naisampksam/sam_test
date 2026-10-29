// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Damaged T-shirts: T-shirt damage / printing mistake / embroidery mistake, picked by product → colour → size like adding stock.
// The pieces come out of stock, show on the dashboard, and printing / embroidery mistakes are a red mark on the person's performance.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const [pid, gsm, product, color] = sql("SELECT p.id, g.label, p.name, (SELECT name FROM product_colors c WHERE c.product_id = p.id AND c.active = 1 ORDER BY sort, id LIMIT 1) FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.active = 1 AND p.sizes LIKE '%M%' ORDER BY g.sort, g.id, p.sort, p.id LIMIT 1").split('\t');
  const rahul = sql("SELECT id FROM users WHERE username='rahul'");
  const stockOf = size => +sql(`SELECT IFNULL(SUM(qty), 0) FROM stock_items WHERE category='Blank T-shirt' AND gsm='${gsm}' AND product='${product}' AND color='${color}' AND size='${size}'`);
  const startId = +sql('SELECT IFNULL(MAX(id), 0) FROM damages');
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); return p; };
  const a = await login('admin', 'admin12345');
  const record = async (p, kind, sizes, extra = {}) => {
    await p.goto(U + 'damages.php'); await p.check(`[name=kind][value=${kind}]`);
    await p.selectOption('#dmgProduct', pid); await p.click(`#dmgColors .chip[data-v="${color}"]`);
    for (const [sz, n] of Object.entries(sizes)) await p.fill(`[name="qty[${sz}]"]`, String(n));
    for (const [k, v] of Object.entries(extra)) await p.fill(`[name=${k}]`, v);
    await p.click('#damageForm button.primary'); await p.waitForLoadState();
  };
  // the form: who made it only for printing / embroidery
  await a.goto(U + 'damages.php');
  ok(await a.locator('[name=kind]:checked').count() === 0, 'why it is damaged must be picked (nothing ticked to start)');
  ok(await a.locator('label:has(#dmgProduct)').evaluate(el => el.compareDocumentPosition(document.querySelector('.dmg-kind')) & 4), 'product comes first, then why it is damaged');
  await a.check('[name=kind][value=damage]');
  ok(await a.locator('#dmgWho').isHidden() && await a.locator('[name=staff_id]').count() === 0, 'plain T-shirt damage: no red-mark note, and never asks who');
  await a.check('[name=kind][value=print]');
  ok(/your<\/b> mistake/.test(await a.locator('#dmgWho').innerHTML()) && await a.locator('#dmgWho').isVisible(), 'printing mistake: says it is the mistake of the person entering it');
  ok(/in stock/.test(await a.locator('#dmgSizes').innerText()), 'sizes show what is in stock now');
  // T-shirt damage: 2 × M out of stock
  const m0 = stockOf('M'), l0 = stockOf('L');
  await record(a, 'damage', { M: 2 }, { note: 'hole near collar' });
  ok(/2 damaged pcs recorded/.test(await a.locator('.alert').first().innerText()), 'T-shirt damage saved');
  ok(stockOf('M') === m0 - 2, `stock M ${m0} → ${stockOf('M')} (2 taken out)`);
  ok(sql(`SELECT reason FROM stock_moves ORDER BY id DESC LIMIT 1`) === 'damage', 'stock history says “damage”');
  ok(sql('SELECT IFNULL(staff_id, 0) FROM damages ORDER BY id DESC LIMIT 1') === '0', 'plain T-shirt damage is no one’s mistake');
  // nothing ticked for “why”: refused, nothing taken out
  await a.goto(U + 'damages.php'); await a.click(`#dmgColors .chip[data-v="${color}"]`); await a.fill('[name="qty[M]"]', '1');
  await a.evaluate(() => document.querySelectorAll('[name=kind]').forEach(i => { i.required = false; })); await a.evaluate(() => { document.getElementById('damageForm').submit(); });
  await a.waitForLoadState();
  ok(/Pick why it is damaged/.test(await a.locator('.alert').first().innerText()) && stockOf('M') === m0 - 2, 'no reason picked: refused, nothing taken out');
  // Rahul enters a printing mistake himself: 1 × M + 2 × L → his red mark
  const r = await login('rahul', 'pass1234');
  await record(r, 'print', { M: 1, L: 2 });
  ok(stockOf('M') === m0 - 3 && stockOf('L') === l0 - 2, 'printing mistake also comes out of stock');
  ok(/red mark on your performance/i.test(await r.locator('.alert').first().innerText()), 'Rahul is told it is a red mark on his performance');
  ok(sql(`SELECT COUNT(*) FROM damages WHERE id > ${startId} AND kind='print' AND staff_id=${rahul} AND created_by=${rahul}`) === '2', 'the mistake is put on Rahul (who entered it)');
  await a.goto(U + 'damages.php');
  // the list
  const rows = await a.locator('.dmg-table tbody tr').allInnerTexts();
  ok(rows.length >= 3 && rows.filter(r => /Printing mistake/i.test(r) && /Rahul/.test(r)).length === 2, 'records list shows each size, with who made the mistake');
  ok(await a.locator('.dmg-table tr.mistake-row').count() >= 2, 'mistakes are highlighted red in the list');
  // dashboard
  await a.goto(U + 'dashboard.php');
  const dash = (await a.locator('.dmg-stats').innerText()).replace(/\s+/g, ' ');
  ok(/Damaged this month \d+ pcs/.test(dash) && /Printing mistake \d+ pcs .*Rahul \d/.test(dash), 'dashboard shows damaged pieces and who made printing mistakes: ' + dash.slice(0, 160));
  // Rahul's performance: red mark, highlighted
  await a.goto(U + `performance.php?user=${rahul}`);
  ok(await a.locator('.mistake-panel').count() === 1 && /Printing mistake/i.test(await a.locator('.mistake-panel').innerText()), 'Rahul’s performance has the red Mistakes panel');
  ok(await a.locator('.stats .stat-alert', { hasText: 'Mistakes' }).count() === 1, 'and a red Mistakes card');
  const row = a.locator('.team-table tbody tr', { hasText: 'Rahul' });
  ok(/mistake-row/.test(await row.getAttribute('class')) && await row.locator('.mistake-mark').count() === 1, 'team table: Rahul’s row is red with the mark');
  await a.screenshot({ path: __dirname + '/shots/damage_perf.png', fullPage: true });
  // Rahul sees it on his own page; production staff can record; creative staff cannot
  await r.goto(U + 'performance.php');
  ok(await r.locator('.mistake-panel').count() === 1, 'Rahul sees his own mistakes');
  const resR = await r.goto(U + 'damages.php');
  ok(resR.status() === 200 && await r.locator('#damageForm').count() === 1 && await r.locator('.dmg-table button.danger').count() === 0, 'production staff can record damages (but not delete)');
  const v = await login('vedit', 'pass1234');
  ok((await v.goto(U + 'damages.php')).status() === 403, 'creative staff cannot');
  await a.goto(U + 'damages.php'); await a.screenshot({ path: __dirname + '/shots/damages.png', fullPage: true });
  // delete (entered by mistake): pieces go back
  a.on('dialog', d => d.accept());
  while (await a.locator('.dmg-table tbody tr', { hasText: 'hole near collar' }).count() + await a.locator('.dmg-table tbody tr', { hasText: 'Rahul' }).count()) {
    const n = +sql(`SELECT MAX(id) FROM damages WHERE id > ${startId} AND deleted_at IS NULL`); if (!n) break;
    await a.locator(`form:has(input[name=id][value="${n}"]) button`).click(); await a.waitForLoadState();
  }
  ok(stockOf('M') === m0 && stockOf('L') === l0, `deleting the records puts the pieces back (M ${stockOf('M')}, L ${stockOf('L')})`);
  await a.goto(U + `performance.php?user=${rahul}`);
  ok(await a.locator('.mistake-panel').count() === 0, 'and the red mark goes away');
  sql(`DELETE FROM damages WHERE id > ${startId}`);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
