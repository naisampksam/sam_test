// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Partner products (Designs): make the mock-up here (design art on a T-shirt picture in the chosen colour, front and
// back) → only small WebP mock-ups are stored (≤1200px), with T-shirt and colour saved on the design; edit a product
// (colour, remove a picture, add an uploaded photo); the order form fills a product's T-shirt and colour, "Edit"
// opens them; another partner can't edit it. Needs partner_e2e first (partner tcrew).
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
const ART = __dirname + '/shots/pproduct-art.png', PHOTO = __dirname + '/shots/pproduct-photo.jpg';
(async () => {
  // design art: a big transparent PNG with a red circle; a large ready photo
  execSync(`php -r '$i = imagecreatetruecolor(2400, 2400); imagesavealpha($i, true); imagefill($i, 0, 0, imagecolorallocatealpha($i, 0, 0, 0, 127)); imagefilledellipse($i, 1200, 1200, 2000, 2000, imagecolorallocate($i, 220, 30, 40)); imagepng($i, "${ART}");'`);
  execSync(`php -r '$i = imagecreatetruecolor(3000, 2000); for ($x = 0; $x < 3000; $x += 50) imagefilledrectangle($i, $x, 0, $x + 25, 2000, imagecolorallocate($i, $x % 255, 120, 200)); imagejpeg($i, "${PHOTO}", 95);'`);
  sql("UPDATE partners SET active = 1 WHERE code = 'PTEST1'");
  const cat = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; foreach (catalog() as $g) foreach ($g["products"] as $p) if (count($p["colors"]) > 1) { echo json_encode([$g["gsm"] . "|" . $p["name"], $p["colors"][0]["name"], $p["colors"][1]["name"]]); exit; }'`).toString());
  const [tsh, c1, c2] = cat;
  const b = await chromium.launch(); const errs = [];
  const ctx = async (w = 390) => { const c = await b.newContext({ viewport: { width: w, height: 844 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const login = async (p, u, pw) => { await p.goto(U + 'partner/'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('.pt-login button'); await p.waitForLoadState(); };
  const t = await ctx(); await login(t, 'tcrew', 'crew12345');
  sql("UPDATE designs SET active = 0 WHERE name LIKE 'Made Tee%'");

  // 1. new product: T-shirt and colour are needed
  await t.goto(U + 'partner/designs.php?new=1');
  ok(await t.locator('#pdMake').isVisible() && await t.locator('#ptDrop').isHidden(), '“Make it here” is the default; upload is the other choice');
  await t.selectOption('#pdTshirt', tsh);
  ok(await t.locator('#pdColors .pt-sw').count() > 1, 'T-shirt chosen → its colours');
  await t.locator(`#pdColors .pt-sw:has(input[value="${c1}"])`).click();
  // the Design Studio (from the website): art on the front, text on the back, on the real T-shirt photo
  // Looma's Brown is a chocolate brown: the studio recolours the website's darker Brown photo (and any colour without a photo)
  const brown = JSON.parse(await t.locator('[data-studio]').getAttribute('data-cfg')).products.flatMap(p => p.colours).filter(c => c.name === 'Brown');
  ok(brown.length && brown.every(c => /#tint=A4441C$/.test(c.front) && /#tint=A4441C$/.test(c.back)), 'Brown photo recoloured to the real chocolate brown #A4441C');
  ok(await t.locator('[data-studio]').isVisible() && await t.evaluate(() => window.ptStudio.state.product) === tsh && await t.evaluate(() => window.ptStudio.state.colour) === c1,
    'studio shows the chosen T-shirt and colour');
  await t.locator('[data-studio] [data-file]').setInputFiles(ART);
  await t.waitForFunction(() => window.ptStudio.layers() === 1);
  ok(await t.locator('[data-editor]').isVisible() && await t.locator('[data-count="front"]').innerText() === '1', 'art placed on the front, Adjust panel open');
  await t.locator('[data-presets] button:has-text("Left chest")').click();
  const w = await t.evaluate(() => window.ptStudio.state.layers[0].w);
  ok(w > 0 && w < 4, 'preset “Left chest” sizes it: ' + w.toFixed(1) + ' in');
  await t.click('[data-view="back"]'); await t.click('[data-add-text]');
  await t.waitForFunction(() => window.ptStudio.layers() === 2);
  await t.fill('[data-tx="text"]', 'CREW');
  ok(await t.evaluate(() => window.ptStudio.sides().join(',')) === 'front,back', 'design on both sides');
  // colour change in the form changes the studio's T-shirt
  await t.locator(`#pdColors .pt-sw:has(input[value="${c2}"])`).click();
  ok(await t.evaluate(() => window.ptStudio.state.colour) === c2, 'changing the colour changes the studio T-shirt');
  await t.locator(`#pdColors .pt-sw:has(input[value="${c1}"])`).click();
  await t.fill('[name=name]', 'Made Tee'); await t.fill('[name=print_link]', 'https://drive.google.com/file/d/made/view');
  await t.click('#newDesign button.pt-btn.filled'); await t.waitForLoadState();
  const did = sql("SELECT d.id FROM designs d JOIN design_customers dc ON dc.design_id = d.id WHERE dc.customer_code = 'PTEST1' AND d.name = 'Made Tee' AND d.active = 1");
  const [g, pr] = tsh.split('|');
  ok(did && sql(`SELECT CONCAT_WS('|', gsm, product, color) FROM designs WHERE id = ${did}`) === `${g}|${pr}|${c1}`, 'product saved with its T-shirt and colour on the design');
  const files = sql(`SELECT GROUP_CONCAT(filename ORDER BY id) FROM design_images WHERE design_id = ${did}`).split(',');
  ok(files.length === 2 && files.every(f => /\.webp$/.test(f)), 'front and back mock-ups saved as WebP: ' + files.join(', '));
  const dims = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; $o = []; foreach ($argv as $i => $f) if ($i) { $p = upload_dir() . "/" . $f; [$w, $h] = getimagesize($p); $o[] = [$w, $h, filesize($p), is_file(upload_dir() . "/" . thumb_path($f)) && thumb_path($f) !== $f]; } echo json_encode($o);' ${files.join(' ')}`).toString());
  ok(dims.every(d => d[0] <= 1200 && d[1] <= 1200 && d[2] < 250000 && d[3]), 'mock-ups are small: ≤1200px, under 250 KB, with a thumbnail: ' + dims.map(d => `${d[0]}×${d[1]} ${Math.round(d[2] / 1024)}KB`).join(', '));
  ok(/Made Tee/.test(await t.locator('.pt-grid').innerText()) && new RegExp(c1).test(await t.locator('.pt-dwrap:has-text("Made Tee")').innerText()), 'product card shows T-shirt and colour');

  // 2. edit: another colour, remove the back picture, add a big uploaded photo (stored small)
  await t.locator('.pt-dwrap:has-text("Made Tee") a:has-text("Edit")').click(); await t.waitForLoadState();
  ok(await t.inputValue('#pdTshirt') === tsh && await t.locator('#pdColors input:checked').getAttribute('value') === c1 && await t.inputValue('[name=name]') === 'Made Tee'
    && /made/.test(await t.inputValue('[name=print_link]')), 'Edit opens with the saved T-shirt, colour, name and link');
  ok(await t.locator('.pt-mk-old').count() === 2, 'saved pictures shown, each can be removed');
  await t.locator(`#pdColors .pt-sw:has(input[value="${c2}"])`).click();
  await t.locator('.pt-mk-old').nth(1).click();
  await t.click('.pt-mk-mode label:has-text("Upload photos")'); await t.setInputFiles('[name="photos[]"]', PHOTO);
  await t.fill('[name=name]', 'Made Tee 2');
  await t.click('#newDesign button.pt-btn.filled'); await t.waitForLoadState();
  ok(sql(`SELECT CONCAT_WS('|', name, color) FROM designs WHERE id = ${did}`) === `Made Tee 2|${c2}`, 'edit saved: new name and colour');
  const f2 = sql(`SELECT GROUP_CONCAT(filename ORDER BY id) FROM design_images WHERE design_id = ${did}`).split(',');
  ok(f2.length === 2 && f2[0] === files[0] && !f2.includes(files[1]), 'back picture removed, front kept, photo added');
  const gone = execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; echo is_file(upload_dir() . "/" . $argv[1]) ? "still" : "gone";' ${files[1]}`).toString();
  ok(gone === 'gone', 'the removed picture’s file is deleted (space freed)');
  const d3 = execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; $p = upload_dir() . "/" . $argv[1]; [$w, $h] = getimagesize($p); echo "$w|$h|" . filesize($p) . "|" . pathinfo($p, PATHINFO_EXTENSION);' ${f2[1]}`).toString().split('|');
  ok(+d3[0] === 1200 && +d3[1] === 800 && +d3[2] < 250000 && d3[3] === 'webp', `uploaded 3000×2000 photo stored as ${d3[0]}×${d3[1]} WebP, ${Math.round(d3[2] / 1024)} KB`);
  // can't remove every picture
  await t.goto(U + 'partner/designs.php?edit=' + did);
  for (const l of await t.locator('.pt-mk-old').all()) await l.click();
  await t.click('#newDesign button.pt-btn.filled'); await t.waitForLoadState();
  ok(/Add a mock-up/.test(await t.locator('.pt-note.err').innerText()) && sql(`SELECT COUNT(*) FROM design_images WHERE design_id = ${did}`) === '2', 'removing every picture is refused');

  // 3. order form: the product fills T-shirt and colour; Edit opens them
  await t.goto(U + 'partner/order.php?new=1&design=' + did);
  const line = t.locator('[data-line]').first();
  ok(await line.locator('.pt-psum').isVisible() && new RegExp(c2).test(await line.locator('.pt-psum').innerText()) && await line.locator('[data-tshirt]').isHidden(),
    'order: product shows “T-shirt · colour” in one row: ' + (await line.locator('.pt-psum').innerText()).replace(/\s+/g, ' '));
  ok(await line.locator('.pt-step').count() > 0, 'sizes are ready to tap');
  await line.locator('[data-pedit]').click();
  await line.locator(`.pt-sw:has(input[value="${c1}"])`).click();
  ok(await line.locator('.pt-sw input:checked').getAttribute('value') === c1 && await line.locator('[data-tshirt]').isVisible(), 'Edit: colour changed for this order');
  ok(sql(`SELECT color FROM designs WHERE id = ${did}`) === c2, '…the saved product keeps its colour');

  // 4. another partner can't edit it
  const o = await ctx(); await login(o, 'otherco', 'other12345');
  const r = await o.goto(U + 'partner/designs.php?edit=' + did);
  ok(r.status() === 404, 'another partner can’t open the product to edit (404)');

  // 4b. Shopify: a Shopify product with the same name as the product matches it by itself (T-shirt and colour from the product)
  const shop = execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; require "${ROOT}/inc/shopify.php"; $p = q("SELECT * FROM partners WHERE code = ?", ["PTEST1"])->fetch();
    $it = shop_resolve_items($p, [["shop_title" => "made tee 2", "variant" => "M", "qty" => 1, "design_id" => 0, "design" => "", "gsm" => "", "product" => "", "color" => "", "size" => ""]])[0];
    echo $it["design_id"], "|", $it["product"], "|", $it["color"], "|", $it["size"];'`).toString().split('|');
  ok(shop[0] === String(did) && shop[1] === pr && shop[2] === c2 && shop[3] === 'M', 'Shopify product “made tee 2” → this product, its T-shirt and colour, size from the variant: ' + shop.join('|'));

  // 4c. delete: confirm, gone from the list, pictures deleted (no order uses it)
  const pics = sql(`SELECT GROUP_CONCAT(filename) FROM design_images WHERE design_id = ${did}`).split(',');
  await t.goto(U + 'partner/designs.php');
  t.once('dialog', d => d.accept());
  await t.locator('.pt-dwrap:has-text("Made Tee 2") .pt-del-mini button').click(); await t.waitForLoadState();
  ok(/Product deleted/.test(await t.locator('.pt-note').first().innerText()) && await t.locator('.pt-dwrap:has-text("Made Tee 2")').count() === 0
    && sql(`SELECT active FROM designs WHERE id = ${did}`) === '0', 'Delete: product gone from Products (kept inactive in Saved designs)');
  const left = execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/orders.php"; foreach (array_slice($argv, 1) as $f) if (is_file(upload_dir() . "/" . $f)) echo $f;' ${pics.join(' ')}`).toString();
  ok(left === '' && sql(`SELECT COUNT(*) FROM design_images WHERE design_id = ${did}`) === '0', 'its pictures are deleted (space freed)');

  // 5. phone width: no sideways scroll on the product form, light and dark
  for (const scheme of ['light', 'dark']) {
    await t.emulateMedia({ colorScheme: scheme }); await t.goto(U + 'partner/designs.php?new=1');
    ok(await t.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `product form fits a phone (${scheme})`);
  }
  await t.screenshot({ path: __dirname + '/shots/pproduct.png', fullPage: true });
  ok(!errs.length, 'no page errors ' + errs.join(' | '));
  await b.close();
  console.log(fails ? `${fails} FAILED` : 'ALL PASSED');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
