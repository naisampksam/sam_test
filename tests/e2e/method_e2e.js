// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Production order tick and dispatch date: DTF orders go in the set working days (locked, the admin excepted);
// production orders and orders with embroidery / puff / HD / screen print get a dispatch date typed by whoever makes them.
// Order cards show every print method used (DTF, Embroidery, Puff, HD, Screen print, Production) and can be filtered by it.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ymd = d => d.toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
const plus = n => ymd(new Date(Date.now() + n * 864e5));
(async () => {
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw) => { const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); return p; };
  const n = await login('anu', 'pass1234'); // production staff who can create orders
  const due = () => n.locator('[name=due_date]');
  // a new order with one T-shirt; prints: {place: method}; text on each place
  const start = async (cust) => {
    await n.goto(U + 'order.php?new=1');
    await n.fill('[name=customer_id]', cust);
    await n.evaluate(() => { const c = document.querySelector('#items .item-card'); c._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' });
      c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); });
  };
  const print = async (place, method, text = 'Logo') => {
    const card = n.locator('#items .item-card').first();
    if (await card.locator(`.print-place[data-place=${place}]`).isHidden()) await card.locator(`[data-pp-toggle=${place}]`).click();
    await card.locator(`.print-place[data-place=${place}] [data-pm]`).selectOption(method);
    await card.locator(`.print-place[data-place=${place}] textarea`).fill(text);
  };
  const save = async () => { await n.click('.sticky-actions .btn.primary'); await n.waitForURL(/order\.php\?id=\d+/); return new URL(n.url()).searchParams.get('id'); };
  const row = id => sql(`SELECT CONCAT_WS('|', due_date, production, print_methods) FROM orders WHERE id=${id}`);
  const base = sql("SELECT IFNULL(MAX(id), 0) FROM orders");

  // 1. the tick sits in the first part of the form; a DTF order's date is locked
  await n.goto(U + 'order.php?new=1');
  ok(await n.locator('#step-order ~ section').first().locator('[data-production]').count() === 1, 'Production order tick is in the first part (Customer & order)');
  ok(await due().evaluate(e => e.readOnly) && /Only the admin/.test(await n.locator('[data-due-hint]').innerText()), 'DTF order: dispatch date locked — ' + await n.locator('[data-due-hint]').innerText());

  // 2. DTF only: even forced, the server keeps the set working days
  await start('PMTEST-DTF'); await print('front_print', 'dtf');
  const dtfDue = await due().inputValue();
  await n.evaluate(d => { const i = document.querySelector('[name=due_date]'); i.readOnly = false; i.value = d; }, plus(15));
  const dtf = await save();
  ok(row(dtf) === `${dtfDue}|0|dtf`, 'DTF order keeps the 2 working days even when the date is forced: ' + row(dtf));

  // 3. embroidery: the date opens and is saved
  await start('PMTEST-EMB'); await print('front_print', 'emb');
  ok(!(await due().evaluate(e => e.readOnly)) && /Embroidery needs more time/.test(await n.locator('[data-due-hint]').innerText()), 'embroidery: dispatch date can be set — ' + await n.locator('[data-due-hint]').innerText());
  await print('front_print', 'dtf');
  ok(await due().evaluate(e => e.readOnly), 'back to DTF: locked again');
  await print('front_print', 'emb'); await due().fill(plus(10));
  const emb = await save();
  ok(row(emb) === `${plus(10)}|0|emb`, 'embroidery order saved with its own dispatch date: ' + row(emb));

  // 4. production order (DTF print): the tick opens the date
  await start('PMTEST-PROD'); await print('back_print', 'dtf');
  await n.check('[data-production]');
  ok(!(await due().evaluate(e => e.readOnly)) && /Production order needs more time/.test(await n.locator('[data-due-hint]').innerText()), 'production tick: dispatch date can be set');
  await due().fill(plus(12));
  const prod = await save();
  ok(row(prod) === `${plus(12)}|1|dtf`, 'production order saved: ' + row(prod));

  // 5. DTF + embroidery + HD on one order → three badges
  await start('PMTEST-MIX'); await print('front_print', 'emb'); await print('back_print', 'hd'); await print('chest_print', 'dtf');
  await due().fill(plus(9));
  const mix = await save();
  ok(row(mix) === `${plus(9)}|0|dtf,emb,hd`, 'mixed order: ' + row(mix));
  ok((await n.locator('h2 .pm-badges').innerText()).replace(/\s+/g, ' ').toUpperCase() === 'DTF EMBROIDERY HD', 'order page shows DTF · Embroidery · HD');

  // 6. orders list: badges on the cards, and the filter
  const cardOf = id => n.locator(`.order-card[data-id="${id}"]`);
  await n.goto(U + 'orders.php?tab=all&q=PMTEST');
  ok((await cardOf(mix).locator('.pm-badge').allInnerTexts()).map(t => t.toUpperCase()).join(',') === 'DTF,EMBROIDERY,HD', 'card highlights DTF, Embroidery and HD');
  ok((await cardOf(prod).locator('.pm-badge').allInnerTexts()).map(t => t.toUpperCase()).join(',') === '🏭 PRODUCTION,DTF', 'production order card says Production');
  ok(await cardOf(emb).locator('.pm-badge.pm-emb').count() === 1 && await cardOf(dtf).locator('.pm-badge.pm-dtf').count() === 1, 'each card highlights its method');
  await n.click('.method-chips a:has-text("Embroidery")'); await n.waitForLoadState();
  let ids = await n.locator('.order-card').evaluateAll(cs => cs.map(c => c.dataset.id));
  ok(ids.includes(emb) && ids.includes(mix) && !ids.includes(dtf) && !ids.includes(prod), 'filter Embroidery: only orders with embroidery');
  await n.click('.method-chips a:has-text("HD")'); await n.waitForLoadState();
  ids = await n.locator('.order-card').evaluateAll(cs => cs.map(c => c.dataset.id));
  ok(ids.length >= 1 && ids.includes(mix) && !ids.includes(emb), 'filter HD');
  await n.click('.method-chips a:has-text("Production")'); await n.waitForLoadState();
  ids = await n.locator('.order-card').evaluateAll(cs => cs.map(c => c.dataset.id));
  ok(ids.includes(prod) && !ids.includes(mix), 'filter Production');
  ok(/method=production/.test(n.url()) && /PMTEST/.test(n.url()), 'filter keeps the search');
  await n.screenshot({ path: __dirname + '/shots/methods.png', fullPage: false });

  // 7. editing: embroidery order's date can be moved by staff; the admin can move a DTF order's date
  await n.goto(U + `order.php?id=${emb}&edit=1`);
  await due().fill(plus(14)); await n.click('.sticky-actions .btn.primary'); await n.waitForURL(/order\.php\?id=\d+$/);
  ok(row(emb).startsWith(plus(14)), 'staff move an embroidery order’s date');
  await n.goto(U + `order.php?id=${dtf}&edit=1`);
  ok(await due().evaluate(e => e.readOnly), 'staff: DTF order’s date stays locked when editing');
  const a = await login('admin', 'admin12345');
  await a.goto(U + `order.php?id=${dtf}&edit=1`);
  ok(!(await a.locator('[name=due_date]').evaluate(e => e.readOnly)) && /You are the admin/.test(await a.locator('[data-due-hint]').innerText()), 'admin can change a DTF order’s date');
  await a.fill('[name=due_date]', plus(6)); await a.click('.sticky-actions .btn.primary'); await a.waitForURL(/order\.php\?id=\d+$/);
  ok(row(dtf).startsWith(plus(6)), 'admin’s DTF date saved');
  // a date before the order date is refused
  await n.goto(U + `order.php?id=${emb}&edit=1`);
  await due().fill(plus(-30)); await n.click('.sticky-actions .btn.primary'); await n.waitForLoadState();
  ok(/can’t be before the order date/.test(await n.locator('.alert.err').innerText().catch(() => '')), 'dispatch date before the order date is refused');
  // older orders get their methods worked out
  ok(sql('SELECT COUNT(*) FROM orders WHERE print_methods IS NULL') === '0', 'every order has its print methods');

  // 8. received ticks: embroidery / HD / puff / screen print made outside; dispatch moves after the last one
  const work = n => { const d = new Date(ymd(new Date()) + 'T12:00:00'); while (n > 0) { d.setDate(d.getDate() + 1); if (d.getDay() !== 0) n--; } return d.toISOString().slice(0, 10); };
  const rp = await login('rahul', 'pass1234'); // the printer ticks what comes back
  const tickRecv = async (id, m) => { await rp.goto(U + 'orders.php?tab=all&q=PMTEST'); const t = rp.locator(`.order-card[data-id="${id}"] button.recv-tick[data-stage=recv_${m}]`);
    await t.click(); await rp.waitForFunction(b => b.classList.contains('done'), await t.elementHandle()); };
  const due0 = id => sql(`SELECT due_date FROM orders WHERE id=${id}`);
  await n.goto(U + 'orders.php?tab=all&q=PMTEST');
  ok(await cardOf(emb).locator('button.recv-tick').allInnerTexts().then(t => t.join() .includes('Embroidery received')), 'embroidery order card has “Embroidery received” next to Print processed');
  ok(await cardOf(dtf).locator('button.recv-tick').count() === 0, 'DTF order: no received tick');
  sql(`UPDATE order_items SET quantity = 5 WHERE order_id = ${emb}`);
  await tickRecv(emb, 'emb');
  ok(due0(emb) === work(2), `5 embroidery pcs: dispatch 2 working days after received (${due0(emb)})`);
  ok((await rp.locator(`.order-card[data-id="${emb}"] [data-due-text]`).innerText()).includes(String(new Date(work(2) + 'T12:00:00').getDate()).padStart(2, '0')), 'card shows the new dispatch date straight away');
  // DTF + embroidery + HD, 20 pcs: moves only after both are received, 3 days
  sql(`UPDATE order_items SET quantity = 20 WHERE order_id = ${mix}`);
  const before = due0(mix);
  await tickRecv(mix, 'emb');
  ok(due0(mix) === before, 'only embroidery received: date not moved yet');
  await tickRecv(mix, 'hd');
  ok(due0(mix) === work(3), `both received, 20 pcs: 3 working days (${due0(mix)})`);
  // 40 pcs embroidery: 4 days
  await start('PMTEST-BIG'); await print('front_print', 'emb'); await due().fill(plus(20));
  const big = await save(); sql(`UPDATE order_items SET quantity = 40 WHERE order_id = ${big}`);
  await tickRecv(big, 'emb');
  ok(due0(big) === work(4), `40 pcs: 4 working days (${due0(big)})`);
  // puff: 2 days whatever the size
  await start('PMTEST-PUFF'); await print('back_print', 'puff'); await due().fill(plus(20));
  const puff = await save(); sql(`UPDATE order_items SET quantity = 50 WHERE order_id = ${puff}`);
  await n.goto(U + 'orders.php?tab=all&q=PMTEST');
  ok(/Puff received/.test(await cardOf(puff).locator('.ticks').innerText()), 'puff order: “Puff received” tick');
  await tickRecv(puff, 'puff');
  ok(due0(puff) === work(2), `puff: 2 working days after received (${due0(puff)})`);
  await rp.screenshot({ path: __dirname + '/shots/received.png', fullPage: false });
  // order page has the tick too; history notes it
  await n.goto(U + `order.php?id=${big}`);
  ok(await n.locator('.ticks-panel button.recv-tick.done').count() === 1, 'order page shows “Embroidery received” ticked');
  ok(+sql(`SELECT COUNT(*) FROM order_log WHERE order_id=${big} AND field='received_emb'`) === 1, 'order history records it');

  sql(`UPDATE orders SET deleted_at = NOW() WHERE id > ${base} AND customer_id LIKE 'PMTEST%'`);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
