// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// How to (SOP): search in plain words (English or Malayalam), English / Malayalam switch, sections, links,
// and each person sees the topics for their job.
const { chromium } = require(process.env.PWPATH);
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw, w = 1280) => { const ctx = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); return p; };
  const visible = p => p.locator('.sop-topic:not([hidden])').evaluateAll(ts => ts.map(t => t.id));
  const search = async (p, text) => { await p.fill('#sopQ', text); await p.waitForTimeout(150); return visible(p); };

  const a = await login('admin', 'admin12345');
  await a.goto(U + 'orders.php');
  ok(await a.locator('header a:has-text("How to")').count() >= 1, '“How to” is in the top bar');
  await a.click('header a:has-text("How to") >> nth=0'); await a.waitForLoadState();
  const all = await visible(a);
  ok(all.length === 49, 'admin sees every topic: ' + all.length);

  // search in plain words
  let r = await search(a, 'how I can create order');
  ok(r[0] === 'create-order' && await a.locator('#create-order').evaluate(d => d.open), '“how I can create order” → “Create an order (step by step)” first, opened');
  ok(await a.locator('#create-order div.en .sop-steps li').count() === 5, 'short: 5 steps');
  // explanation: hidden until the step is pointed at
  const why = a.locator('#create-order div.en .sop-steps li >> nth=0 >> .sop-why');
  ok(await why.isHidden(), 'step explanation hidden at first');
  await a.hover('#create-order div.en .sop-steps li >> nth=0');
  ok(await why.isVisible() && /phone or name/.test(await why.innerText()), 'pointing at a step shows its explanation: ' + await why.innerText());
  r = await search(a, 'wrong stock number'); ok(r[0] === 'stock-mistake', '“wrong stock number” → fix a wrong stock number: ' + r.slice(0, 3));
  r = await search(a, 'cash on delivery'); ok(r[0] === 'cod-proof', '“cash on delivery” → COD topic: ' + r.slice(0, 3));
  r = await search(a, 'embroidery received'); ok(r[0] === 'received', '“embroidery received” → received ticks: ' + r.slice(0, 3));
  r = await search(a, 'printing orders'); ok(r.length > 0, '“printing orders” (plural) still finds topics: ' + r.slice(0, 3));
  r = await search(a, 'xyzzy'); ok(r.length === 0 && await a.locator('#sopNone').isVisible(), 'nothing found → a hint to try one word');

  // Malayalam
  await search(a, '');
  await a.check('input[name=sop_lang][value=ml]');
  ok(await a.locator('#create-order summary').innerText().then(t => /ഓർഡർ ഉണ്ടാക്കാൻ/.test(t)), 'മലയാളം: titles in Malayalam');
  ok(await a.locator('#create-order div.ml .sop-steps li').count() === 5 && await a.locator('#create-order div.en').isHidden(), 'Malayalam steps shown, English hidden');
  r = await search(a, 'സ്റ്റോക്ക് ചേർക്കാൻ'); ok(r[0] === 'add-stock', 'Malayalam search “സ്റ്റോക്ക് ചേർക്കാൻ” → add stock: ' + r.slice(0, 3));
  r = await search(a, 'create order'); ok(r[0] === 'create-order', 'English words also work while in Malayalam');
  await a.reload(); await a.waitForLoadState();
  ok(await a.locator('#sop').getAttribute('data-lang') === 'ml', 'the language choice is remembered');
  await a.check('input[name=sop_lang][value=en]');

  // what's in the app
  await search(a, '');
  ok(await a.locator('#sopOptions').isVisible() && await a.locator('.sop-opt').count() === 27, '“What’s in the app”: 27 options for the admin');
  await search(a, 'stock'); ok(await a.locator('#sopOptions').isHidden(), 'hidden while searching');
  await search(a, '');
  await a.click('.sop-opt:has-text("Damaged")'); await a.waitForTimeout(200);
  ok(await a.locator('#damaged').evaluate(d => d.open), 'tapping an option opens its steps');

  // sections and links
  await search(a, '');
  await a.click('.sop-cats a[data-cat=tasks]');
  r = await visible(a); ok(r.length === 4 && r.every(id => ['my-tasks', 'give-task', 'task-page', 'performance'].includes(id)), 'section “Tasks & performance”: ' + r);
  await a.goto(U + 'help.php#damaged'); await a.waitForTimeout(200);
  ok(await a.locator('#damaged').evaluate(d => d.open), 'a link to a topic opens it (help.php#damaged)');
  await a.goto(U + 'help.php?q=label');
  await a.waitForTimeout(150);
  ok((await visible(a)).slice(0, 2).includes('shipping-label') && await a.inputValue('#sopQ') === 'label', 'help.php?q=label opens with that search');

  // each job sees its own topics
  const rp = await login('rahul', 'pass1234', 390);
  await rp.goto(U + 'help.php');
  const rt = await visible(rp);
  ok(rt.includes('create-order') && rt.includes('damaged') && !rt.includes('staff') && !rt.includes('backup'), 'production staff: order topics, no admin topics (' + rt.length + ')');
  ok(await rp.evaluate(() => document.documentElement.scrollWidth) <= 392, 'fits a phone screen');
  ok(await rp.locator('.sop-opt').count() < 27 && await rp.locator('.sop-opt:has-text("Backup")').count() === 0, 'production staff see only their options (' + await rp.locator('.sop-opt').count() + ')');
  await rp.evaluate(() => { document.getElementById('create-order').open = true; });
  const li = rp.locator('#create-order div.en .sop-steps li >> nth=1');
  await li.click(); ok(await li.locator('.sop-why').isVisible(), 'phone: a tap shows the explanation');
  await li.click(); await rp.mouse.move(0, 0); ok(await li.locator('.sop-why').isHidden(), 'another tap hides it');
  const v = await login('vedit', 'pass1234');
  await v.goto(U + 'tasks.php');
  ok(await v.locator('.tm-nav a:has-text("How to")').count() === 1, 'Task Manager bar has “❓ How to”');
  await v.goto(U + 'help.php');
  const vt = await visible(v);
  ok(vt.includes('my-tasks') && vt.includes('clock-in') && !vt.includes('create-order'), 'creative staff: tasks and attendance, not orders (' + vt.length + ')');

  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
