// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ymd = d => d.toISOString().slice(0, 10);
(async () => {
  sql("DELETE FROM task_notes; DELETE FROM tasks; DELETE FROM users WHERE username IN ('vedit','dmark')");
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw, w = 390) => { const ctx = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => errs.push(u + ': ' + e.message)); p.on('dialog', d => d.accept());
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button'); await p.waitForLoadState(); return p; };
  const a = await login('admin', 'admin12345', 1200);
  // create video editor + marketing staff
  for (const [un, nm, preset, title] of [['vedit', 'Vinu', 'video_editor', 'Video editor'], ['dmark', 'Diya', 'marketing', 'Digital marketing']]) {
    await a.goto(U + 'admin/users.php');
    await a.fill('#add [name=name]', nm); await a.fill('#add [name=username]', un); await a.fill('#add [name=password]', 'pass1234');
    await a.fill('#add [name=job_title]', title); await a.selectOption('#add [name=preset]', preset);
    await a.click('#add button.primary'); await a.waitForLoadState();
  }
  ok(sql("SELECT CONCAT(job,'|',job_title) FROM users WHERE username='vedit'") === 'video_editor|Video editor', 'video editor account created');
  const vid = sql("SELECT id FROM users WHERE username='vedit'");
  // admin gives tasks
  await a.goto(U + 'tasks.php?new=1');
  await a.selectOption('[name=assigned_to]', vid);
  ok(await a.inputValue('[name=kind]') === 'video' && await a.locator('[data-video-fields]').isVisible(), 'picking the editor switches to a video task');
  await a.fill('[name=title]', 'Reel: new Acid Wash drop'); await a.selectOption('[name=platform]', 'Instagram Reel'); await a.fill('[name=length]', '30 sec');
  await a.fill('[name=footage]', 'https://drive.example.com/raw1'); await a.fill('[name=due_date]', ymd(new Date(Date.now() + 86400000))); await a.selectOption('[name=priority]', 'high');
  await a.uncheck('[name=needs_review]'); await a.click('button[name=another]'); await a.waitForLoadState();
  await a.fill('[name=title]', 'Thumbnail set for YouTube'); await a.fill('[name=due_date]', ymd(new Date(Date.now() - 2 * 86400000)));
  await a.uncheck('[name=needs_review]'); await a.click('.task-form button.primary'); await a.waitForLoadState();
  ok(sql(`SELECT COUNT(*) FROM tasks WHERE assigned_to=${vid}`) === '2', '2 tasks given to the editor');
  const t1 = sql("SELECT id FROM tasks WHERE title LIKE 'Reel%'");
  // a task for someone else
  await a.goto(U + 'tasks.php?new=1'); await a.selectOption('[name=assigned_to]', sql("SELECT id FROM users WHERE username='dmark'"));
  await a.fill('[name=title]', 'Plan Diwali ad campaign'); await a.click('.task-form button.primary'); await a.waitForLoadState();
  const tOther = sql("SELECT id FROM tasks WHERE title LIKE 'Plan Diwali%'");
  // editor logs in
  const v = await login('vedit', 'pass1234');
  ok(/tasks\.php/.test(v.url()), 'editor lands on tasks: ' + v.url());
  ok(/Video editing/.test(await v.locator('h1').innerText()), 'editor sees the video editing board');
  const tabs = await v.locator('.tabbar').innerText();
  ok(/My tasks/.test(tabs) && /performance/i.test(tabs) && !/Orders/.test(tabs), 'editor menu: My tasks + Your performance, no Orders');
  ok(await v.locator('.task-card').count() === 2 && await v.locator('.task-card.is-late').count() === 1, 'both tasks on the board, one marked late');
  await v.goto(U + 'tasks.php?id=' + tOther);
  ok(/not found/i.test(await v.locator('body').innerText()), "editor can't open other people's tasks");
  await v.goto(U + 'tasks.php?id=' + t1);
  await v.click('button[name=status][value=doing]'); await v.waitForLoadState();
  await v.fill('[name=result_link]', 'https://drive.example.com/final-reel'); await v.fill('.task-status-form [name=note]', 'Final cut with captions');
  await v.click('button[name=status][value=done]'); await v.waitForLoadState();
  ok(sql(`SELECT CONCAT(status,'|',result_link,'|',done_at IS NOT NULL,'|',started_at IS NOT NULL) FROM tasks WHERE id=${t1}`) === 'done|https://drive.example.com/final-reel|1|1', 'editor marked it done with the final link');
  ok(/Final cut with captions/.test(await v.locator('.task-notes').innerText()) && /working on it/i.test(await v.locator('.task-notes').innerText()), 'updates trail recorded');
  // editor adds own task, cannot assign to others
  await v.goto(U + 'tasks.php?new=1');
  ok(await v.locator('[name=assigned_to]').count() === 0, 'editor cannot give tasks to others');
  await v.fill('[name=title]', 'Organise footage folders'); await v.click('.task-form button.primary'); await v.waitForLoadState();
  ok(sql("SELECT assigned_to FROM tasks WHERE title='Organise footage folders'") === vid, 'own task is for themselves');
  // video log
  await v.goto(U + 'tasks.php?kind=video');
  ok(/Reel: new Acid Wash drop/.test(await v.locator('section:has(h2:has-text("Videos delivered"))').innerText()), 'videos delivered list');
  // performance (editor)
  await v.goto(U + 'performance.php');
  const st = await v.locator('.stats').innerText();
  console.log('editor stats:', st.replace(/\s+/g, ' '));
  ok(/TASKS DONE\s*1/i.test(st) && /PENDING TASKS\s*2/i.test(st) && /LATE TASKS\s*1/i.test(st) && /VIDEOS DELIVERED\s*1/i.test(st), 'editor sees own numbers');
  ok(!/ORDERS CREATED/i.test(st), 'no order cards for the editor');
  const team = await v.locator('.team-table').innerText();
  ok(/Vinu/.test(team) && /Diya/.test(team) && /Anu/.test(team) && !/Founder/.test(team), "editor also sees the team's work");
  ok(await v.locator('.team-table a').count() === 0, "editor can't open other people's pages");
  // admin views
  await a.goto(U + 'performance.php?user=' + vid);
  ok(/Vinu/.test(await a.locator('h1').innerText()), 'admin opens the editor’s performance');
  ok(/Thumbnail set for YouTube/.test(await a.locator('section:has(h2:has-text("Delayed right now"))').innerText()), 'admin sees who is delayed');
  await a.screenshot({ path: __dirname + '/shots/perf_admin.png', fullPage: true });
  await a.goto(U + 'dashboard.php');
  ok(/Vinu/.test(await a.locator('section:has(h2:has-text("Team"))').innerText()), 'dashboard team table lists the editor');
  await a.goto(U + 'tasks.php');
  await a.screenshot({ path: __dirname + '/shots/tasks_board.png', fullPage: true });
  // production staff
  const n = await login('anu', 'pass1234');
  await n.goto(U + 'performance.php');
  ok(/ORDERS CREATED/i.test(await n.locator('.stats').innerText()), 'production staff see order work');
  await v.screenshot({ path: __dirname + '/shots/perf_editor.png', fullPage: true });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
