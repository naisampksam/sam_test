// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Every page, as every kind of user, in light and dark, on a computer and a phone:
// the page opens (or is refused properly), shows no PHP error or warning, no browser error, and nothing spills sideways on a phone.
// Also: performance shows only the person's own team, and people switched off are not tracked.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const PHP_ERR = /(Fatal error|Parse error|Warning: |Notice: |Deprecated: |Uncaught |Stack trace:)/;
(async () => {
  const id = t => sql(`SELECT IFNULL(MAX(id), 0) FROM ${t}`);
  const order = sql('SELECT IFNULL(MAX(id), 0) FROM orders WHERE deleted_at IS NULL'), bill = id('bills'), task = id('tasks'), uid = sql("SELECT id FROM users WHERE username='anu'");
  const PAGES = ['dashboard.php', 'orders.php', 'order.php?new=1', `order.php?id=${order}`, `order.php?id=${order}&edit=1`, 'print_list.php', 'bills.php', `bill.php?id=${bill}`,
    `bill_print.php?id=${bill}`, 'dues.php', 'quotes.php', 'quote.php', 'bill.php?new=1', 'stock.php', 'expenses.php', 'accounts.php', 'designs.php', 'labels.php', `slip.php?id=${order}`,
    'estimate.php', 'performance.php', 'performance.php?p=today', 'performance.php?p=last', 'tasks.php', 'tasks.php?view=table', 'tasks.php?view=calendar', 'tasks.php?new=1', `tasks.php?id=${task}`,
    'account.php', 'admin/users.php', `admin/user_edit.php?id=${uid}`, 'admin/catalog.php', 'admin/customers.php', 'admin/settings.php', 'admin/backup.php', 'admin/storage.php',
    'admin/import.php', 'admin/staff_links.php', 'admin/estimate_products.php', 'attendance/', 'attendance/admin', 'index.php', 'tasks'];
  const b = await chromium.launch();
  const run = async (user, pw, w, theme, pages) => {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 } });
    if (theme) await ctx.addCookies([{ name: 'looma_theme', value: theme, url: U }]);
    const p = await ctx.newPage(); const jsErr = [];
    p.on('pageerror', e => jsErr.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', user); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState();
    const bad = [], refused = [], wide = [];
    for (const pg of pages) {
      jsErr.length = 0;
      const res = await p.goto(U + pg, { waitUntil: 'load' }).catch(e => null);
      const status = res ? res.status() : 0;
      const body = await p.locator('body').innerText().catch(() => '');
      if (status >= 500 || PHP_ERR.test(body)) bad.push(`${pg} → ${status} ${(body.match(PHP_ERR) || [''])[0]} ${body.slice(body.search(PHP_ERR), body.search(PHP_ERR) + 160).replace(/\s+/g, ' ')}`);
      else if (status === 403 || /Not allowed|Only admins|not turned on/i.test(body)) refused.push(pg);
      if (jsErr.length) bad.push(`${pg} → browser error: ${jsErr.join(' | ').slice(0, 160)}`);
      if (w < 500 && !/print|slip\.php/.test(pg)) {
        const sw = await p.evaluate(() => document.documentElement.scrollWidth).catch(() => 0);
        if (sw > w + 2) wide.push(`${pg} (${sw}px)`);
      }
    }
    await ctx.close();
    return { bad, refused, wide };
  };
  const report = (label, r) => {
    ok(!r.bad.length, `${label}: every page opens with no PHP or browser error` + (r.bad.length ? '\n      ' + r.bad.join('\n      ') : ''));
    if (r.wide) ok(!r.wide.length, `${label}: nothing wider than the phone` + (r.wide.length ? ': ' + r.wide.join(', ') : ''));
    if (r.refused.length) console.log(`     (${label} — refused, as expected for their access: ${r.refused.join(', ')})`);
  };
  report('admin, computer, light', await run('admin', 'admin12345', 1280, 'light', PAGES));
  report('admin, computer, dark', await run('admin', 'admin12345', 1280, 'dark', PAGES));
  report('admin, phone, automatic', await run('admin', 'admin12345', 390, null, PAGES));
  const staffPages = PAGES.filter(p => !/^admin\//.test(p));
  report('production staff (rahul), phone, dark', await run('rahul', 'pass1234', 390, 'dark', staffPages));
  report('manager (anu), computer, light', await run('anu', 'pass1234', 1280, 'light', staffPages));
  report('creative staff (vedit), phone, light', await run('vedit', 'pass1234', 390, 'light', staffPages));
  // staff must never reach admin pages
  const r2 = await run('rahul', 'pass1234', 1280, 'light', ['admin/users.php', 'admin/settings.php', 'admin/backup.php', 'admin/staff_links.php', 'admin/storage.php']);
  ok(r2.refused.length === 5 && !r2.bad.length, 'production staff are refused every admin page: ' + r2.refused.length + ' of 5');

  // ---- performance: own team only; people switched off are not tracked
  const login = async (u, pw) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); return p; };
  const team = async p => { await p.goto(U + 'performance.php'); return (await p.locator('.team-table tbody').innerText()).replace(/\s+/g, ' '); };
  const r = await login('rahul', 'pass1234');
  const rt = await team(r);
  ok(/Rahul/.test(rt) && /Anu/.test(rt) && !/Vinu|Diya/.test(rt), 'production staff see only the production team: ' + rt.slice(0, 120));
  const v = await login('vedit', 'pass1234');
  const vt = await team(v);
  ok(/Vinu/.test(vt) && /Diya/.test(vt) && !/Rahul|Anu|Faiz/.test(vt), 'creative staff see only the creative team: ' + vt.slice(0, 120));
  const a = await login('admin', 'admin12345');
  await a.goto(U + `admin/user_edit.php?id=${uid}`); await a.uncheck('[name=track_perf]'); await a.click('button.primary:has-text("Save")'); await a.waitForLoadState();
  ok(sql(`SELECT track_perf FROM users WHERE id=${uid}`) === '0', 'admin switches off tracking for Anu');
  ok(!/Anu/.test(await team(r)), 'Anu no longer in the production team table');
  const n = await login('anu', 'pass1234');
  await n.goto(U + 'performance.php');
  ok(/not tracked/.test(await n.locator('main').innerText()) && await n.locator('.task-pct.score').count() === 0, 'Anu sees “not tracked” and no points badge');
  await a.goto(U + 'performance.php');
  ok(!/>Anu</.test(await a.locator('.page-head select').innerHTML()), 'Anu not in the admin’s people list');
  ok(/Production team/.test(await a.locator('.page-head select').innerHTML()) && /Creative team/.test(await a.locator('.page-head select').innerHTML()), 'admin picks people by team');
  sql(`UPDATE users SET track_perf=1 WHERE id=${uid}`);
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
