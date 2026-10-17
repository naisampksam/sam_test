// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  sql("DELETE FROM production_batches; DELETE FROM settings WHERE k='piece_weights'");
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  const stockBefore = sql("SELECT IFNULL(SUM(qty),0) FROM stock_items WHERE gsm='250 GSM' AND product='Oversized Fit - French Terry' AND color='Black'");
  const add = async (fabric, rib, q, w, extra) => {
    await p.goto(U + 'stock.php');
    await p.selectOption('#asProduct', '27');
    await p.click('#asColors .chip[data-v="Black"]');
    for (const [s, n] of Object.entries(q)) await p.fill(`#asSizes [name="qty[${s}]"]`, String(n));
    await p.fill('[name=fabric_kg]', String(fabric)); await p.fill('[name=rib_kg]', String(rib));
    if (extra) for (const [k, v] of Object.entries(extra)) await p.fill(`[name=${k}]`, String(v));
    if (w) { await p.click('.as-pw summary'); for (const [s, g] of Object.entries(w)) await p.fill(`#asWeights [name="piece_g[${s}]"]`, String(g)); }
    return p.locator('#asBatchLive').innerText();
  };
  const live = await add(200, 8, { XS: 40, S: 120, M: 180, L: 160, XL: 100, XXL: 50 }, { XS: 220, S: 235, M: 250, L: 265, XL: 280, XXL: 295 }, { cut_waste_kg: 30, other_waste_kg: 5, cutting_cost: 3250 });
  console.log('live:', live);
  ok(/650 pcs/.test(live) && /3\.13 pcs per kg/.test(live) && /waste 35 kg \(16\.8%\)/.test(live) && /Making cost ₹[\d,]+ = ₹[\d.]+ per piece/.test(live), 'live line: 650 pcs, 3.13 pcs/kg, worked-out 19.6%, weighed 16.8%, cutting ₹5/pc');
  await p.click('.as-save'); await p.waitForLoadState();
  console.log('flash:', await p.locator('.alert').first().innerText());
  ok(/batch 208 kg → 3\.13 pcs\/kg, waste 16\.8%/.test(await p.locator('.alert').first().innerText()), 'saved message shows batch result');
  ok(+sql("SELECT IFNULL(SUM(qty),0) FROM stock_items WHERE gsm='250 GSM' AND product='Oversized Fit - French Terry' AND color='Black'") === +stockBefore + 650, 'stock went up by 650');
  // second batch: weights remembered
  await p.goto(U + 'stock.php'); await p.selectOption('#asProduct', '27'); await p.click('#asColors .chip[data-v="Black"]');
  ok(await p.inputValue('#asWeights [name="piece_g[M]"]') === '250', 'piece weights remembered for the product');
  await add(150, 6, { XS: 30, S: 100, M: 140, L: 120, XL: 70, XXL: 30 }, null, { cut_waste_kg: 22, other_waste_kg: 4, cutting_cost: 2450 });
  await p.click('.as-save'); await p.waitForLoadState();
  ok(sql('SELECT COUNT(*) FROM production_batches') === '2', '2 batches saved');
  // a count (not a batch) does not make a batch
  await p.goto(U + 'stock.php'); await p.selectOption('#asProduct', '27'); await p.click('#asColors .chip[data-v="Black"]');
  await p.click('label:has(input[name=kind][value=count])');
  ok(await p.locator('#asBatch').isHidden(), 'batch box hidden for "Set count"');
  // dashboard
  await p.goto(U + 'dashboard.php');
  const sec = p.locator('#production');
  const sumRow = await sec.locator('.prod-sum tbody tr').first().innerText();
  console.log('summary:', sumRow.replace(/\s+/g, ' '));
  ok(/Black/.test(sumRow) && /\b2\b/.test(sumRow) && /364 kg/.test(sumRow) && /1140/.test(sumRow) && /16\.8%/.test(sumRow) && /₹5\.00/.test(sumRow), 'summary: 2 batches, 364 kg, 1140 pcs, waste 16.8%, cutting ₹5/pc');
  const first = sec.locator('.batch').first();
  const t = await first.innerText();
  console.log('latest batch:', t.replace(/\s+/g, ' ').slice(0, 400));
  ok(/490/.test(t) && /3\.14/.test(t) && /vs 3\.13/.test(t), 'latest batch compared with the earlier one (pcs/kg)');
  ok(/WASTE \(WEIGHED\)/i.test(t) && /16\.7%/.test(t) && /vs 16\.8%/.test(t), 'weighed waste % and comparison');
  ok(/CUTTING WASTE/i.test(t) && /22 kg/.test(t) && /OTHER WASTE/i.test(t) && /₹5\.00 \/ pc/.test(t) && /NOT IN T-SHIRTS/i.test(t), 'cutting waste, other waste, cutting cost, worked-out figure');
  // cost estimator uses it
  await p.goto(U + 'estimate.php');
  const ep = p.locator('#estProd');
  ok(/250 GSM · Oversized Fit - French Terry/.test(await ep.innerText()) && /16\.8%/.test(await ep.innerText()), 'estimator shows production figures');
  await ep.locator('[data-use-waste]').first().click();
  ok(await p.inputValue('[data-k="wastage"]') === '16.8', 'Use waste → cutting wastage 16.8%');
  await ep.locator('[data-use-cut]').first().click();
  const lastExtra = p.locator('#extraCosts .extra-row').last();
  ok(/Cutting/.test(await lastExtra.locator('[data-xk=name]').inputValue()) && await lastExtra.locator('[data-xk=amt]').inputValue() === '5.00', 'Use cutting → cost line ₹5/pc');
  await ep.screenshot({ path: __dirname + '/shots/prod_est.png' });
  await p.goto(U + 'dashboard.php');
  ok(/Earlier \(1\)/.test(t) && /XXL/.test(t) && /XS/.test(t), 'XS–XXL split with earlier shares');
  await p.locator('#production').screenshot({ path: __dirname + '/shots/prod_dash.png' });
  // remove a batch
  await p.locator('#production .batch').first().locator('button[title="Remove batch"]').click(); await p.waitForLoadState();
  ok(sql('SELECT COUNT(*) FROM production_batches') === '1', 'batch removed');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
