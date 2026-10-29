// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Tasks workspace at /tasks: who gets the page, the Notion-style editor (typing, "/" menu, markdown, links, images,
// connections, timing, comments, ✓ edited), saving, two people editing, board drag, table / calendar, and the work score.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  sql("DELETE FROM task_blocks WHERE task_id IN (SELECT id FROM tasks WHERE title LIKE 'NT %'); DELETE FROM task_notes WHERE task_id IN (SELECT id FROM tasks WHERE title LIKE 'NT %'); DELETE FROM task_files WHERE task_id IN (SELECT id FROM tasks WHERE title LIKE 'NT %'); DELETE FROM tasks WHERE title LIKE 'NT %'");
  const vid = sql("SELECT id FROM users WHERE username='vedit'");
  ok(vid !== '', 'video editor account exists (run staff_e2e first)');
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw, w = 1280) => { const p = await (await b.newContext({ viewport: { width: w, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(u + ': ' + e.message)); p.on('dialog', d => d.accept());
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button'); await p.waitForLoadState(); return p; };
  const saved = async p => { await p.waitForFunction(() => /Saved/.test(document.getElementById('npSave').textContent), null, { timeout: 8000 }).catch(() => {}); };
  const a = await login('admin', 'admin12345');

  // ---- who gets the Tasks page
  ok(/"task_page":1/.test(sql("SELECT caps FROM users WHERE username='vedit'")), 'video editor has the Tasks page');
  ok(!/task_page/.test(sql("SELECT caps FROM users WHERE username='rahul'")), 'orders staff (rahul) has no Tasks page');
  await a.goto(U + 'tasks');
  ok(/Tasks/.test(await a.title()) && await a.locator('.task-board').count() === 1, '/tasks opens the workspace');
  await a.goto(U + 'tasks?new=1');
  const giveTo = await a.locator('[name=assigned_to]').innerText();
  ok(/Vinu/.test(giveTo) && /Rahul/.test(giveTo) && !/Founder/.test(giveTo), 'tasks can be given to any staff, never to an admin');

  // ---- new video task from the template
  await a.selectOption('[name=assigned_to]', vid);
  ok(await a.inputValue('[name=template]') === 'video', 'video task starts from the Video script template');
  await a.fill('[name=title]', 'NT Reel: oversized drop'); await a.uncheck('[name=needs_review]');
  await a.click('.task-form button.primary'); await a.waitForLoadState();
  const tid = sql("SELECT id FROM tasks WHERE title='NT Reel: oversized drop'");
  ok(/tasks\?id=/.test(a.url()) && await a.locator('.nb').count() >= 8, 'opens the page with the script outline: ' + await a.locator('.nb').count() + ' blocks');

  // ---- typing, Enter, markdown, "/" menu
  const lines = a.locator('.nb.t-p .nb-text');
  await lines.nth(0).click(); await a.keyboard.type('Wait till you see this fit.');
  await lines.nth(1).click(); await a.keyboard.type('Heavy 240 GSM cotton, dropped shoulders.');
  await a.keyboard.press('Enter'); await a.keyboard.type('Comes in 6 colours.');
  await a.keyboard.press('Enter'); await a.keyboard.type('- Black');
  await a.keyboard.press('Enter'); await a.keyboard.type('Sand');
  ok(await a.locator('.nb.t-bullet').count() === 2, '"- " makes a bulleted list and Enter continues it');
  await a.keyboard.press('Enter'); await a.keyboard.press('Enter');
  ok(await a.locator('.nb.t-bullet').count() === 2, 'Enter on an empty list line ends the list');
  await a.keyboard.type('/todo');
  ok(await a.locator('.np-menu:not([hidden]) .np-mi').count() >= 1 && /To-do/.test(await a.locator('.np-menu').innerText()), '"/" opens the block menu and filters it');
  await a.keyboard.press('Enter');
  await a.keyboard.type('Film the back print');
  ok(await a.locator('.nb.t-todo').count() === 2, '"/todo" + Enter turns the line into a to-do');
  await a.locator('.nb.t-todo .nb-check').first().check();
  await saved(a);
  const html = sql(`SELECT GROUP_CONCAT(CONCAT(type,':',html) ORDER BY pos SEPARATOR ' | ') FROM task_blocks WHERE task_id=${tid}`);
  ok(/p:Wait till you see this fit\./.test(html) && /p:Comes in 6 colours\./.test(html) && /bullet:Black \| bullet:Sand/.test(html) && /todo:Film the back print/.test(html), 'saved in order: ' + html.slice(0, 220));
  ok(sql(`SELECT COUNT(*) FROM task_blocks WHERE task_id=${tid} AND type='todo' AND checked=1`) === '1', 'ticked to-do saved');

  // ---- formatting: bold with the toolbar
  const hook = a.locator('.nb-text', { hasText: 'Wait till you see this fit.' });
  await hook.click({ clickCount: 3 }); await sleep(150);
  ok(await a.locator('.np-fmt:not([hidden])').count() === 1, 'selecting text shows the formatting toolbar');
  await a.click('.np-fmt [data-f=bold]'); await saved(a);
  ok(/<b>Wait till you see this fit\.<\/b>/.test(sql(`SELECT html FROM task_blocks WHERE task_id=${tid} AND html LIKE '%Wait till%'`)), 'bold kept (only safe tags)');

  // ---- extras on a line: link, image, connect, timing, comment, ✓ edited
  const row = a.locator('.nb', { hasText: 'Heavy 240 GSM' });
  await row.hover(); await row.locator('.nb-handle').click();
  ok(/Turn into/i.test(await a.locator('.np-menu').innerText()), 'line menu: turn into / colour / actions');
  await a.locator('.np-menu .np-mi', { hasText: 'Add link' }).click();
  await a.fill('.np-dialog [name=u]', 'https://www.instagram.com/reel/abc123'); await a.fill('.np-dialog [name=t]', 'Reference reel');
  await a.click('.np-dialog button[value=ok]');
  ok(/Reference reel/.test(await row.locator('.nb-extras').innerText()), 'link chip on the line');
  // image upload via the hidden file input
  const png = __dirname + '/shots/ref.png'; await a.screenshot({ path: png, clip: { x: 0, y: 0, width: 300, height: 200 } });
  await row.hover(); await row.locator('.nb-handle').click();
  const [chooser] = await Promise.all([a.waitForEvent('filechooser'), a.locator('.np-menu .np-mi', { hasText: 'Reference image' }).click()]);
  await chooser.setFiles(png);
  await a.waitForSelector('.nb-thumb img'); await saved(a);
  ok(await row.locator('.nb-thumb img').count() === 1, 'reference image attached to the line');
  const imgSrc = await row.locator('.nb-thumb img').getAttribute('src');
  // connect an order
  const anyOrder = sql('SELECT id FROM orders WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
  await row.hover(); await row.locator('.nb-handle').click();
  await a.locator('.np-menu .np-mi', { hasText: 'Connect order' }).click();
  await a.fill('.np-find', anyOrder); await a.waitForSelector('.np-found .np-mi');
  await a.locator('.np-found .np-mi').first().click();
  ok(/📦 LA/.test(await row.locator('.nb-extras').innerText()), 'order connected to the line');
  // timing & shot note
  await row.hover(); await row.locator('.nb-handle').click();
  await a.locator('.np-menu .np-mi', { hasText: 'Timing' }).click();
  await a.fill('.np-dialog [name=sec]', '4'); await a.fill('.np-dialog [name=shot]', 'Close-up of the fabric');
  await a.click('.np-dialog button[value=ok]');
  ok(/Close-up of the fabric/.test(await row.innerText()) && /⏱ 4s/.test(await row.locator('.nb-sec').innerText()), 'timing and shot note shown');
  await saved(a);
  const props = JSON.parse(sql(`SELECT props FROM task_blocks WHERE task_id=${tid} AND html LIKE 'Heavy 240%'`));
  ok(props.links[0].u === 'https://www.instagram.com/reel/abc123' && props.imgs.length === 1 && props.conn[0].k === 'order' && props.sec === 4 && props.shot === 'Close-up of the fabric', 'line extras saved: ' + JSON.stringify(props));
  ok((await a.request.get(U + imgSrc.replace(/^\//, ''))).status() === 200, 'reference image is served');
  // comment on the line
  await row.hover(); await row.locator('.nb-cmt').click();
  await a.fill('.np-side textarea', 'Say 240 GSM slower'); await a.click('.np-side form button');
  await a.waitForFunction(() => /Say 240 GSM slower/.test(document.querySelector('.np-side-list').textContent));
  await a.click('.np-side [data-close]');
  ok(/💬 1/.test(await row.locator('.nb-cmt').innerText()), 'comment count on the line');

  // ---- reload: everything is still there
  await a.reload(); await a.waitForSelector('.nb');
  const row2 = a.locator('.nb', { hasText: 'Heavy 240 GSM' });
  ok(/Reference reel/.test(await row2.innerText()) && await row2.locator('.nb-thumb img').count() === 1 && /💬 1/.test(await row2.locator('.nb-cmt').innerText()), 'after reload: link, image, comment');
  ok(/Say 240 GSM slower/.test(await a.locator('.np-activity').innerText()), 'line comments listed under the page');
  await a.screenshot({ path: __dirname + '/shots/notion_page.png', fullPage: true });

  // ---- the editor (another person) works on it; changes from both are kept
  const v = await login('vedit', 'pass1234', 390);
  ok(/\/tasks/.test(v.url()), 'editor lands on /tasks');
  await v.goto(U + 'tasks?id=' + tid); await v.waitForSelector('.nb');
  const vrow = v.locator('.nb', { hasText: 'Comes in 6 colours' });
  await vrow.locator('.nb-done').click();
  await saved(v);
  ok(/✎ 1\/\d+ edited/.test(await v.locator('#npStats').innerText()), 'editor ticks a line as edited: ' + await v.locator('#npStats').innerText());
  // admin types at the same time (old copy of the page) — both changes stay
  await a.locator('.nb-text', { hasText: 'Film the back print' }).click(); await a.keyboard.press('End'); await a.keyboard.type(' and the tag');
  await saved(a); await sleep(300);
  ok(/"done":true/.test(sql(`SELECT props FROM task_blocks WHERE task_id=${tid} AND html LIKE 'Comes in%'`)) && sql(`SELECT COUNT(*) FROM task_blocks WHERE task_id=${tid} AND html LIKE 'Film the back print and the tag'`) === '1', 'two people editing: both changes kept');
  await v.screenshot({ path: __dirname + '/shots/notion_phone.png', fullPage: true });

  // ---- paste several lines → several blocks
  await a.locator('.nb-text', { hasText: 'Film the back print' }).click(); await a.keyboard.press('End'); await a.keyboard.press('Enter');
  await a.evaluate(() => { const dt = new DataTransfer(); dt.setData('text/plain', 'Line one\nLine two\n- Point'); document.activeElement.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); });
  await saved(a);
  ok(sql(`SELECT GROUP_CONCAT(CONCAT(type,':',html) ORDER BY pos SEPARATOR '|') FROM task_blocks WHERE task_id=${tid} AND html IN ('Line one','Line two','Point')`) === 'todo:Line one|p:Line two|bullet:Point', 'pasted lines become separate lines');

  // ---- delete a line with Backspace, move with Alt+↑
  const cnt = +sql(`SELECT COUNT(*) FROM task_blocks WHERE task_id=${tid}`);
  await a.locator('.nb-text', { hasText: 'Point' }).click(); await a.keyboard.press('Home');
  await a.keyboard.press('Backspace'); await a.keyboard.press('Backspace');
  await saved(a);
  ok(+sql(`SELECT COUNT(*) FROM task_blocks WHERE task_id=${tid}`) === cnt - 1 && sql(`SELECT COUNT(*) FROM task_blocks WHERE task_id=${tid} AND html='Line twoPoint'`) === '1', 'Backspace at the start joins the line to the one above');

  // ---- properties
  await a.fill('[data-prop=length]', '30 sec'); await a.locator('[data-prop=length]').dispatchEvent('change'); await sleep(500);
  ok(/"length":"30 sec"/.test(sql(`SELECT extra FROM tasks WHERE id=${tid}`)), 'property saved from the page');

  // ---- board: drag to Working on it; table; calendar
  sql(`UPDATE tasks SET due_date=CURDATE() WHERE id=${tid}`);
  await a.goto(U + 'tasks');
  await Promise.all([a.waitForNavigation(), a.evaluate(id => {
    const dt = new DataTransfer(), card = document.querySelector(`.task-card[data-task="${id}"]`), col = document.querySelector('.task-col[data-status=doing]');
    card.dispatchEvent(new DragEvent('dragstart', { dataTransfer: dt, bubbles: true }));
    col.dispatchEvent(new DragEvent('dragover', { dataTransfer: dt, bubbles: true, cancelable: true }));
    col.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
  }, tid)]);
  ok(sql(`SELECT status FROM tasks WHERE id=${tid}`) === 'doing', 'drag a card to "Working on it"');
  ok(/✎ 1\/\d+ lines edited|☑/.test(await a.locator(`.task-card[data-task="${tid}"]`).innerText()), 'card shows script progress');
  await a.goto(U + 'tasks?view=table');
  ok(/NT Reel: oversized drop/.test(await a.locator('.np-table').innerText()), 'table view');
  await a.goto(U + 'tasks?view=calendar');
  ok(/NT Reel/.test(await a.locator('.np-cal-day.today').innerText()), 'calendar shows it on its due date');
  await a.goto(U + 'tasks?q=240+GSM');
  ok(/NT Reel/.test(await a.locator('.task-board').innerText()), 'search finds words inside the page');

  // ---- give again copies the page
  await a.goto(U + 'tasks?new=1&from=' + tid);
  await a.click('.task-form button.primary'); await a.waitForLoadState();
  const copy = sql("SELECT MAX(id) FROM tasks WHERE title='NT Reel: oversized drop'");
  ok(copy !== tid && /Heavy 240 GSM/.test(await a.locator('#npEditor').innerText()) && !/"done":true/.test(sql(`SELECT GROUP_CONCAT(props) FROM task_blocks WHERE task_id=${copy}`)), 'Give again copies the page (✓ edited cleared)');

  // ---- orders staff: no Tasks page, a work score instead
  const r = await login('rahul', 'pass1234', 390);
  await r.goto(U + 'tasks'); await r.waitForLoadState();
  ok(/Production team/.test(await r.locator('.page-head').innerText()) && await r.locator('.task-board').count() === 0 && await r.locator('body.tm').count() === 1, 'orders staff get the Production team Task Manager');
  ok(/Points this month/i.test(await r.locator('.stats').innerText()) && /counted automatically/.test(await r.locator('body').innerText()), 'with their points and automatic work');
  ok(await r.locator('text=Add my task').count() === 0 && await r.locator('[contenteditable]').count() === 0, 'nothing to enter or adjust there');
  ok(/⭐\s*\d+/.test(await r.locator('.task-pct').innerText()) && /points this month/.test(await r.locator('.task-pct').getAttribute('title')), 'top badge shows points: ' + await r.locator('.task-pct').getAttribute('title'));
  await r.goto(U + 'performance.php');
  ok(/Work score/.test(await r.locator('.score-panel').innerText()) && !/Pending tasks/.test(await r.locator('.stats').innerText()), 'performance: score, no task boxes');
  await r.screenshot({ path: __dirname + '/shots/score_phone.png', fullPage: true });
  // points follow the rules the admin sets
  await a.goto(U + 'performance.php?user=' + sql("SELECT id FROM users WHERE username='rahul'"));
  const before = +(await a.locator('.score-big').innerText()).match(/\d+/)[0];
  await a.click('text=⚙ Points for each piece of work');
  for (const k of ['order', 'order_pc', 'processed', 'processed_pc', 'printed', 'packed', 'shipped', 'task']) await a.fill(`[name="rule[${k}]"]`, '0');
  await a.fill('[name="rule[order]"]', '100'); await a.click('text=💾 Save points'); await a.waitForLoadState();
  const rid = sql("SELECT id FROM users WHERE username='rahul'");
  const made = +sql(`SELECT COUNT(*) FROM orders WHERE created_by=${rid} AND deleted_at IS NULL AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')`);
  const after = +(await a.locator('.score-big').innerText()).match(/\d+/)[0];
  ok(after === made * 100, `points follow the admin's rules (${made} orders × 100 = ${after}, was ${before})`);
  ok(/Points/i.test(await a.locator('.team-table thead').innerText()), 'team table ranks by points');
  ok(!/Founder/.test(await a.locator('.team-table').innerText()), 'admins are not in the team table (only staff are monitored)');
  await a.goto(U + 'performance.php');
  ok(!/Founder/.test(await a.locator('h1').innerText()) && /Staff performance/.test(await a.locator('.topbar').innerText()), 'admin opens Staff performance on a staff member: ' + await a.locator('h1').innerText());
  sql("DELETE FROM settings WHERE k='score_points'");
  // default points, counted by hand from the database for anu this month:
  // order 1 + 1 per T-shirt; print-processed 1 + 0.5 per T-shirt; printed 1 per piece; packed 1; shipped 0; late work half
  const nid = sql("SELECT id FROM users WHERE username='anu'"), m0 = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
  const tees = "(SELECT IFNULL(SUM(quantity),0) FROM order_items WHERE order_id=o.id AND item_type IN ('print','plain'))";
  const exp = +sql(`SELECT
    (SELECT 5 * COUNT(*) + IFNULL(SUM(${tees}),0) FROM orders o WHERE created_by=${nid} AND deleted_at IS NULL AND created_at >= ${m0})
  + (SELECT COUNT(*) + 0.5 * IFNULL(SUM(${tees}),0) FROM orders o WHERE print_processed_by=${nid} AND deleted_at IS NULL AND print_processed_at >= ${m0})
  + (SELECT IFNULL(SUM(it.quantity * IF(o.due_date IS NOT NULL AND DATE(it.printed_at) > o.due_date, 0.5, 1)),0) FROM order_items it JOIN orders o ON o.id=it.order_id WHERE it.printed_by=${nid} AND o.deleted_at IS NULL AND it.printed_at >= ${m0})
  + (SELECT IFNULL(SUM(IF(o.due_date IS NOT NULL AND DATE(o.packed_at) > o.due_date, 0.5, 1)),0) FROM orders o WHERE packed_by=${nid} AND deleted_at IS NULL AND packed_at >= ${m0})
  + (SELECT IFNULL(SUM(IF(due_date IS NOT NULL AND DATE(done_at) > due_date, 5, 10)),0) FROM tasks WHERE assigned_to=${nid} AND status='done' AND created_by <> assigned_to AND done_at >= ${m0})`);
  await a.goto(U + 'performance.php?user=' + nid);
  const shown = +(await a.locator('.score-big').innerText()).match(/\d+/)[0];
  ok(shown === Math.round(exp) && exp > 0, `default points: shown ${shown}, counted ${exp}`);
  // the admin gives a production task to orders staff — the same task to two people
  await a.goto(U + 'tasks?new=1');
  await a.selectOption('[name=assigned_to]', rid);
  ok(await a.inputValue('[name=kind]') === 'production' && await a.inputValue('[name=template]') === 'production', 'orders staff: production task with the Production plan page');
  await a.fill('[name=title]', 'NT Plan production: Looma Paris order');
  await a.click('.also-to summary'); await a.check(`.also-to input[value="${nid}"]`);
  await a.click('.task-form button.primary'); await a.waitForLoadState();
  ok(sql("SELECT GROUP_CONCAT(assigned_to ORDER BY assigned_to) FROM tasks WHERE title='NT Plan production: Looma Paris order'") === [nid, rid].sort((x, y) => x - y).join(','), 'given to both people, a copy each');
  ok(+sql("SELECT COUNT(*) FROM task_blocks WHERE task_id IN (SELECT id FROM tasks WHERE title='NT Plan production: Looma Paris order')") >= 20, 'each copy has the production plan page');
  await r.goto(U + 'tasks'); await r.waitForLoadState();
  ok(/Looma Paris/.test(await r.locator('.pt-tasks').innerText()) && /New/i.test(await r.locator('.pt-tasks').innerText()), 'the new task from the admin is listed');
  ok(/⭐/.test(await r.locator('.task-pct').innerText()), 'and they keep their work score badge');
  await r.screenshot({ path: __dirname + '/shots/production_tasks.png', fullPage: true });
  const ptid = sql(`SELECT id FROM tasks WHERE title='NT Plan production: Looma Paris order' AND assigned_to=${rid}`);
  await r.locator('.pt-row', { hasText: 'Looma Paris' }).locator('button[value=done]').click(); await r.waitForLoadState();
  ok(/\/tasks$/.test(r.url()), 'Complete keeps them on their task list');
  if (sql(`SELECT status FROM tasks WHERE id=${ptid}`) === 'review') { await a.goto(U + 'tasks?id=' + ptid); await a.click('button[name=status][value=done]'); await a.waitForLoadState(); }
  await r.goto(U + 'tasks');
  ok(/Looma Paris/.test(await r.locator('.pt-done').innerText()), 'finished task moves to Done tasks');
  await r.goto(U + 'performance.php'); await r.click('.score-how summary');
  ok(/Task done \(given by someone else\)\s*1\s*10\s*10/.test((await r.locator('.score-how table').innerText()).replace(/\t/g, ' ')), 'finished task earns 10 points');

  // ---- clean up
  await a.goto(U + 'tasks?id=' + copy);
  await a.click('.np-more summary'); await a.click('text=🗑 Delete task'); await a.waitForLoadState();
  ok(sql(`SELECT COUNT(*) FROM task_blocks WHERE task_id=${copy}`) === '0', 'deleting a task removes its page');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
