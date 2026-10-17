// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const caps = sql("SELECT caps FROM users WHERE username='anu'");
  sql(`UPDATE users SET caps='{\\"create\\":1,\\"slips\\":1,\\"designs\\":1,\\"estimate\\":1,\\"tasks\\":1}' WHERE username='anu'`);
  sql("DELETE FROM tasks WHERE title='Call fabric supplier'");
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw) => { const p = await (await b.newContext({ viewport: { width: 1200, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(u + ': ' + e.message)); p.on('dialog', d => d.accept());
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button'); await p.waitForLoadState(); return p; };
  const a = await login('admin', 'admin12345');
  // admin's own task
  await a.goto(U + 'tasks.php?new=1');
  await a.selectOption('[name=assigned_to]', '1'); await a.fill('[name=title]', 'Call fabric supplier'); await a.selectOption('[name=kind]', 'general');
  await a.click('.task-form button.primary'); await a.waitForLoadState();
  const adminTask = sql("SELECT id FROM tasks WHERE title='Call fabric supplier'");
  // quick add (admin)
  await a.goto(U + 'tasks.php?new=1');
  ok(await a.locator('.quick-tasks .chip').count() >= 3, 'quick add chips from earlier tasks: ' + await a.locator('.quick-tasks .chip').count());
  await a.locator('.quick-tasks .chip', { hasText: 'Reel: new Acid Wash drop' }).click();
  ok(await a.inputValue('[name=title]') === 'Reel: new Acid Wash drop' && await a.inputValue('[name=kind]') === 'video' && await a.inputValue('[name=platform]') === 'Instagram Reel'
     && await a.inputValue('[name=length]') === '30 sec' && await a.inputValue('[name=priority]') === 'high', 'chip fills title, type, platform, length, priority');
  ok(await a.inputValue('[name=assigned_to]') === sql("SELECT id FROM users WHERE username='vedit'"), 'and the person it went to last time');
  // datalist pick
  await a.goto(U + 'tasks.php?new=1');
  await a.fill('[name=title]', 'Plan Diwali ad campaign'); await a.locator('[name=title]').evaluate(e => e.dispatchEvent(new Event('input', { bubbles: true })));
  ok(await a.inputValue('[name=kind]') === 'marketing', 'typing / picking a known title fills its type');
  // give again
  const reel = sql("SELECT id FROM tasks WHERE title LIKE 'Reel:%' LIMIT 1");
  await a.goto(U + 'tasks.php?id=' + reel); await a.click('text=↻ Give again'); await a.waitForLoadState();
  ok(await a.inputValue('[name=title]') === 'Reel: new Acid Wash drop' && await a.inputValue('[name=platform]') === 'Instagram Reel', 'Give again pre-fills the form');
  await a.fill('[name=due_date]', '2026-10-25'); await a.click('.task-form button.primary'); await a.waitForLoadState();
  ok(sql("SELECT COUNT(*) FROM tasks WHERE title='Reel: new Acid Wash drop'") === '2', 'second reel task created');
  // manager (anu, can give tasks) cannot see admin's task
  const n = await login('anu', 'pass1234');
  await n.goto(U + 'tasks.php');
  const board = await n.locator('.task-board').innerText();
  ok(!/Call fabric supplier/.test(board) && /Plan Diwali/.test(board), "manager sees staff tasks but not the admin's");
  await n.goto(U + 'tasks.php?id=' + adminTask);
  ok(/not found/i.test(await n.locator('body').innerText()), "admin's task page is not found for others");
  await n.goto(U + 'tasks.php?new=1');
  ok(!(await n.locator('.quick-tasks').innerText()).includes('Call fabric supplier'), "admin's task not in quick add for others");
  ok(!/Founder/.test(await n.locator('[name=assigned_to]').innerText()), 'admin not in the give-to list for others');
  await n.goto(U + 'performance.php');
  ok(!/Founder/.test(await n.locator('.team-table').innerText()), 'admin not in the team table for others');
  // editor quick add only own tasks
  const v = await login('vedit', 'pass1234');
  await v.goto(U + 'tasks.php?new=1');
  const qv = await v.locator('.quick-tasks').innerText();
  ok(/Reel: new Acid Wash drop/.test(qv) && !/Diwali/.test(qv) && !/fabric supplier/.test(qv), "editor's quick add: only their own tasks");
  await v.goto(U + 'performance.php');
  ok(!/Founder/.test(await v.locator('.team-table').innerText()), 'admin hidden from editor too');
  // admin still sees everything
  await a.goto(U + 'tasks.php');
  ok(/Call fabric supplier/.test(await a.locator('.task-board').innerText()), 'admin sees own task');
  await a.goto(U + 'tasks.php?new=1'); await a.locator('.quick-tasks').screenshot({ path: __dirname + '/shots/quick_add.png' });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  sql(`UPDATE users SET caps='${caps.replace(/"/g, '\\"')}' WHERE username='anu'`);
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
