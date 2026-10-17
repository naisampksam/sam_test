// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw, w = 1200) => { const ctx = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await ctx.newPage(); p.ctx = ctx; p.on('pageerror', e => errs.push(u + ': ' + e.message)); p.on('dialog', d => d.accept());
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button'); await p.waitForLoadState(); return p; };
  const a = await login('admin', 'admin12345');
  const vid = sql("SELECT id FROM users WHERE username='vedit'");
  // ---- 7. approval
  await a.goto(U + 'tasks.php?new=1');
  await a.selectOption('[name=assigned_to]', vid); await a.fill('[name=title]', 'Approval test reel');
  ok(await a.isChecked('[name=needs_review]'), '"Needs my approval" ticked by default for others');
  await a.click('.task-form button.primary'); await a.waitForLoadState();
  const tid = sql("SELECT id FROM tasks WHERE title='Approval test reel' ORDER BY id DESC LIMIT 1");
  const v = await login('vedit', 'pass1234', 390);
  await v.goto(U + 'tasks.php?id=' + tid);
  ok(/send for approval/i.test(await v.locator('.status-btns').innerText()), 'editor sees "Done — send for approval"');
  await v.click('button[name=status][value=done]'); await v.waitForLoadState();
  ok(sql(`SELECT status FROM tasks WHERE id=${tid}`) === 'review', 'editor "done" → For review');
  ok(/Waiting for/.test(await v.locator('.approve-note').innerText()), 'editor sees waiting for approval');
  await a.goto(U + 'tasks.php?id=' + tid);
  await a.fill('.task-status-form [name=note]', 'Add the logo at the end');
  await a.click('button[name=approve][value=back]'); await a.waitForLoadState();
  ok(sql(`SELECT status FROM tasks WHERE id=${tid}`) === 'doing' && /Sent back: Add the logo/.test(sql(`SELECT GROUP_CONCAT(note) FROM task_notes WHERE task_id=${tid}`)), 'sent back with note');
  await v.goto(U + 'tasks.php?id=' + tid); await v.click('button[name=status][value=done]'); await v.waitForLoadState();
  await a.goto(U + 'tasks.php?id=' + tid); await a.click('button[name=status][value=done]'); await a.waitForLoadState();
  ok(sql(`SELECT status FROM tasks WHERE id=${tid}`) === 'done' && /Approved by/.test(sql(`SELECT GROUP_CONCAT(note) FROM task_notes WHERE task_id=${tid}`)), 'approved → Done');
  // ---- 8. stock warning
  const st = sql("SELECT CONCAT(gsm,'|',product,'|',color,'|',size,'|',qty) FROM stock_items WHERE category='Blank T-shirt' AND active=1 AND track=1 AND qty > 0 ORDER BY qty LIMIT 1").split('|');
  await a.goto(U + 'order.php?new=1');
  await a.evaluate(s => { const c = document.querySelector('#items .item-card'); c._setBlank({ gsm: s[0], product: s[1], color: s[2], size: s[3] }); const q = c.querySelector('[name$="[quantity]"]'); q.value = Math.floor(+s[4]) + 3; q.dispatchEvent(new Event('input', { bubbles: true })); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); }, st);
  await a.waitForTimeout(900);
  const hint = await a.locator('.item-card .stock-hint').innerText();
  ok(/⚠ Only \d+ .* in stock — 3 short/.test(hint), 'shortage warning: ' + hint.split('·')[0]);
  sql(`UPDATE stock_items SET qty = -2 WHERE gsm='${st[0]}' AND product='${st[1]}' AND color='${st[2]}' AND size='${st[3]}'`);
  await a.goto(U + 'dashboard.php');
  ok(/Stock below zero/.test(await a.locator('body').innerText()), 'dashboard lists negative stock');
  sql(`UPDATE stock_items SET qty = ${st[4]} WHERE gsm='${st[0]}' AND product='${st[1]}' AND color='${st[2]}' AND size='${st[3]}'`);
  // ---- 9. duplicate order with photos + COD
  sql("UPDATE orders SET payment_type='cod', cod_amount=999, ret_phone='9000000001' WHERE id=108");
  const imgsBefore = sql('SELECT COUNT(*) FROM order_images WHERE order_id=108');
  await a.goto(U + 'order.php?new=1&copy=108');
  ok(await a.locator('#items .up-tile.copied').count() === +imgsBefore, 'duplicate shows the photos: ' + imgsBefore);
  ok(await a.isChecked('[data-pay-type] input[value=cod]') && await a.inputValue('[data-cod-input]') === '999', 'COD + amount copied');
  await a.click('.sticky-actions .btn.primary'); await a.waitForURL(/order\.php\?id=\d+/);
  const nid = new URL(a.url()).searchParams.get('id');
  ok(sql(`SELECT COUNT(*) FROM order_images WHERE order_id=${nid}`) === imgsBefore && sql(`SELECT ret_phone FROM orders WHERE id=${nid}`) === '9000000001', 'new order has the photos and return details');
  // ---- 10. print several labels
  for (const nm of ['Label One', 'Label Two']) { await a.goto(U + 'labels.php?new=1'); await a.fill('[name="f[label][ship_name]"]', nm); await a.click('button[name=save]'); await a.waitForLoadState(); }
  await a.goto(U + 'labels.php');
  await a.check('#selAll');
  const [pp] = await Promise.all([a.ctx.waitForEvent('page'), a.click('#printSel button')]);
  await pp.waitForLoadState();
  ok(await pp.locator('.slip').count() >= 2 && /Label One/i.test(await pp.locator('body').innerText()), 'two labels in one print page');
  await pp.close();
  // ---- 11. proforma gone from filter, housekeeping
  await a.goto(U + 'bills.php');
  const hasPf = sql("SELECT COUNT(*) FROM bills WHERE type='proforma'") !== '0';
  ok((await a.locator('select[name=type] option[value=proforma]').count() > 0) === hasPf, 'proforma filter only when old proformas exist');
  sql("INSERT INTO temp_uploads (user_id, filename, original_name, created_at) VALUES (1, 'nope/x.jpg', 'x', DATE_SUB(NOW(), INTERVAL 3 DAY)); UPDATE settings SET v='2000-01-01' WHERE k='housekeeping_day'");
  await a.goto(U + 'dashboard.php');
  ok(sql("SELECT COUNT(*) FROM temp_uploads WHERE filename='nope/x.jpg'") === '0', 'daily clean-up removed old unused upload');
  // ---- 12. on-time text
  await v.goto(U + 'performance.php');
  const onT = await v.locator('.stat').first().innerText();
  ok(/of \d+ on time|%|—/.test(onT), 'on-time: ' + onT.replace(/\s+/g, ' '));
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
