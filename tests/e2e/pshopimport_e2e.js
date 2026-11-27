// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Partner imports their Shopify products (Shopify's products export CSV): T-shirt guessed from the title / tags
// (acid wash, 250 GSM …), colours matched (Chocolate → Brown), unknown colours must be chosen; import makes one product
// per Shopify product with that name; pictures are downloaded from Shopify and stored small; a Shopify order with
// “Chocolate / M” then becomes Brown, size M. Needs the server started with LOOMA_SHOP_IMG_TEST=http://127.0.0.1:8097
// (a local stand-in for Shopify's picture server). Needs partner_e2e first (partner tcrew).
const { chromium } = require(process.env.PWPATH);
const { execSync, spawn } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
const DIR = __dirname + '/shots/shopimg', CSV = __dirname + '/shots/shopify-products.csv', IMGU = 'http://127.0.0.1:8097/';
(async () => {
  fs.mkdirSync(DIR, { recursive: true });
  execSync(`php -r '$i = imagecreatetruecolor(2048, 2048); imagefill($i, 0, 0, imagecolorallocate($i, 164, 68, 28)); imagejpeg($i, "${DIR}/choc.jpg", 92);'`);
  const img = spawn('php', ['-S', '127.0.0.1:8097', '-t', DIR], { stdio: 'ignore' });
  await new Promise(r => setTimeout(r, 600));
  // catalogue: which T-shirts / colours exist (an acid wash one with Black; the French terry one with Brown)
  const cat = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; echo json_encode(catalog());'`).toString());
  const all = cat.flatMap(g => g.products.map(p => ({ key: g.gsm + '|' + p.name, gsm: g.gsm, name: p.name, colours: p.colors.map(c => c.name) })));
  const terry = all.find(p => /terry/i.test(p.name) && p.colours.includes('Brown'));
  const acid = all.find(p => /acid/i.test(p.name) && p.colours.includes('Black'));
  if (!terry || !acid) { console.log('SKIP: catalogue has no French terry with Brown / acid wash with Black'); img.kill(); process.exit(0); }
  // Shopify products export: 3 products (first row of each has the title; more rows = variants / pictures)
  const H = 'Handle,Title,Body (HTML),Vendor,Product Category,Type,Tags,Published,Option1 Name,Option1 Value,Option2 Name,Option2 Value,Option3 Name,Option3 Value,Variant SKU,Variant Price,Image Src,Image Position,Variant Image,Status';
  const rows = [
    `kanye-chocolate,Kanye Heavy Tee,<p>Heavy</p>,Crew,,T-Shirt,"oversized, 250 gsm, french terry",TRUE,Color,Chocolate,Size,S,,,K-CH-S,999,${IMGU}choc.jpg,1,,active`,
    `kanye-chocolate,,,,,,,,,Chocolate,,M,,,K-CH-M,999,,,,active`,
    `kanye-chocolate,,,,,,,,,Ice Blue,,M,,,K-IB-M,999,,,,active`,
    `acid-skull,Skull Acid Wash,,Crew,,T-Shirt,"acid wash, vintage",TRUE,Size,M,Colour,Jet Black,,,S-M,1299,https://evil.example.com/x.jpg,1,,active`,
    `acid-skull,,,,,,,,,L,,Jet Black,,,S-L,1299,,,,active`,
    `gift-card,Gift Card,,Crew,,Gift,,TRUE,Title,Default Title,,,,,,500,,,,active`,
  ];
  fs.writeFileSync(CSV, '﻿' + H + '\n' + rows.join('\n') + '\n');

  sql("UPDATE partners SET active = 1 WHERE code = 'PTEST1'");
  sql("UPDATE designs SET active = 0 WHERE name IN ('Kanye Heavy Tee', 'Skull Acid Wash', 'Gift Card') AND id IN (SELECT design_id FROM design_customers WHERE customer_code = 'PTEST1')");
  const b = await chromium.launch(); const errs = [];
  const t = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage(); t.on('pageerror', e => errs.push(e.message));
  await t.goto(U + 'partner/'); await t.fill('[name=username]', 'tcrew'); await t.fill('[name=password]', 'crew12345'); await t.click('.pt-login button'); await t.waitForLoadState();

  // 1. Products → Import from Shopify → upload; a wrong file is refused
  await t.goto(U + 'partner/designs.php');
  await t.click('a:has-text("Import from Shopify")'); await t.waitForLoadState();
  ok(/Export from Shopify/.test(await t.locator('main').innerText()), 'import page explains: export from Shopify → upload → check');
  fs.writeFileSync(__dirname + '/shots/not-shopify.csv', 'Name,Phone\nA,1\n');
  await t.setInputFiles('[name=csv]', __dirname + '/shots/not-shopify.csv'); await t.click('#ptImpUp button'); await t.waitForLoadState();
  ok(/not a Shopify products export/.test(await t.locator('.pt-note.err').innerText()), 'a file that is not a Shopify export is refused');
  await t.setInputFiles('[name=csv]', CSV); await t.click('#ptImpUp button'); await t.waitForLoadState();
  ok(await t.locator('.pt-imp').count() === 3, '3 Shopify products found');

  // 2. matches: T-shirt from the words, Chocolate → Brown, Ice Blue unknown (must choose), Jet Black → Black
  const k = t.locator('.pt-imp[data-h="kanye-chocolate"]'), s = t.locator('.pt-imp[data-h="acid-skull"]'), g = t.locator('.pt-imp[data-h="gift-card"]');
  ok(await k.locator('[data-tshirt]').inputValue() === terry.key, '“oversized, 250 gsm, french terry” → ' + terry.name + ' · ' + terry.gsm);
  ok(await k.locator('[data-col][data-from="Chocolate"]').inputValue() === 'Brown', 'their “Chocolate” → our Brown');
  ok(await k.locator('[data-col][data-from="Ice Blue"]').inputValue() === '' && await k.evaluate(e => e.classList.contains('needs')), '“Ice Blue” not known → marked, needs a choice');
  ok(await s.locator('[data-tshirt]').inputValue() === acid.key && await s.locator('[data-col][data-from="Jet Black"]').inputValue() === 'Black', '“acid wash” → ' + acid.name + '; “Jet Black” → Black');
  ok(await s.locator('.pt-imp-head img').count() === 0, 'a picture from outside Shopify is not used');
  await g.locator('.pt-imp-head input').uncheck();
  ok(/2 to import · 1 need a choice/.test(await t.locator('#ptImpCount').innerText()), 'counter: 2 to import, 1 needs a choice');
  await t.click('#ptImpGo');
  ok(await t.locator('.pt-imp').count() === 3 && /\/shop_import\.php/.test(t.url()) && sql("SELECT COUNT(*) FROM designs WHERE name = 'Kanye Heavy Tee' AND active = 1") === '0', 'Import blocked until every colour is chosen');
  await k.locator('[data-col][data-from="Ice Blue"]').selectOption(terry.colours.find(c => /blue/i.test(c)) || terry.colours[0]);
  await t.click('#ptImpGo'); await t.waitForLoadState();
  ok(/2 products imported from Shopify/.test(await t.locator('.pt-note').first().innerText()), 'imported: “2 products imported from Shopify”');

  // 3. products made: Shopify name, T-shirt, colours remembered; the picture downloads by itself, stored small
  const kd = sql("SELECT CONCAT_WS('|', d.id, d.gsm, d.product, d.color, JSON_UNQUOTE(JSON_EXTRACT(d.extra, '$.shop_colours.chocolate'))) FROM designs d JOIN design_customers dc ON dc.design_id = d.id WHERE dc.customer_code = 'PTEST1' AND d.name = 'Kanye Heavy Tee' AND d.active = 1").split('|');
  ok(kd[0] && kd[1] + '|' + kd[2] === terry.key && kd[3] === 'Brown' && kd[4] === 'Brown', 'product “Kanye Heavy Tee”: ' + terry.name + ', Brown, remembers chocolate → Brown');
  ok(sql("SELECT COUNT(*) FROM designs WHERE name = 'Gift Card' AND active = 1") === '0', 'unticked Gift Card not imported');
  await t.waitForFunction(() => /Pictures added|Done/.test((document.getElementById('ptFetch') || {}).textContent || ''), null, { timeout: 30000 }).catch(() => {});
  await t.waitForLoadState(); await t.waitForTimeout(1500);
  const f = sql(`SELECT filename FROM design_images WHERE design_id = ${kd[0]} LIMIT 1`);
  const dim = f ? execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; [$w, $h] = getimagesize(upload_dir() . "/" . $argv[1]); echo "$w|$h|" . pathinfo($argv[1], PATHINFO_EXTENSION);' ${f}`).toString().split('|') : [];
  ok(f && +dim[0] <= 1200 && dim[2] === 'webp', 'Shopify picture downloaded and stored small: ' + dim.join('|'));
  ok(await t.locator('.pt-dwrap:has-text("Kanye Heavy Tee") img').count() === 1 && /Brown/.test(await t.locator('.pt-dwrap:has-text("Kanye Heavy Tee")').innerText()), 'product card shows the picture and the colours');

  // 4. a Shopify order “Kanye Heavy Tee — Chocolate / M” → this product, Brown, M (no link needed)
  const r = execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; require "${ROOT}/inc/shopify.php"; $p = q("SELECT * FROM partners WHERE code = ?", ["PTEST1"])->fetch();
    $it = shop_resolve_items($p, [["shop_title" => "Kanye Heavy Tee", "variant" => "Chocolate / M", "qty" => 1, "design_id" => 0, "design" => "", "gsm" => "", "product" => "", "color" => "", "size" => ""]])[0];
    echo $it["design_id"], "|", $it["color"], "|", $it["size"];'`).toString().split('|');
  ok(r[0] === kd[0] && r[1] === 'Brown' && r[2] === 'M', 'Shopify order “Chocolate / M” → this product, Brown, size M: ' + r.join('|'));

  // 5. importing the same file again: already there → skipped
  await t.goto(U + 'partner/shop_import.php');
  await t.setInputFiles('[name=csv]', CSV); await t.click('#ptImpUp button'); await t.waitForLoadState();
  ok(/Already in your products/.test(await k.innerText()) && !(await k.locator('.pt-imp-head input').isChecked()), 'second import: “Already in your products” — not ticked');
  await t.click('button:has-text("Cancel")'); await t.waitForLoadState();
  ok(await t.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'fits a phone');
  ok(!errs.length, 'no page errors ' + errs.join(' | '));
  await b.close(); img.kill();
  console.log(fails ? `${fails} FAILED` : 'ALL PASSED');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
