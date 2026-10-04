// Production estimate: live fabric + cost calculation for estimate.php.
// Each product is a list of cut pieces (rectangles in inches, worked out from the size chart).
// Pieces are laid side by side across the usable roll width; the fabric length a garment
// needs, times the roll width, times GSM, gives the fabric weight — so utilisation follows the product.
(function () {
  'use strict';
  var form = document.getElementById('estForm');
  if (!form) return;

  var IN2_TO_M2 = 0.00064516;
  // What this person may see: none / view / edit per cost group (admins: edit everywhere).
  var PERM = {};
  try { PERM = JSON.parse(form.dataset.perm || '{}'); } catch (e) {}
  var lv = function (g) { return PERM[g] || 'edit'; };
  var sees = function (g) { return lv(g) !== 'none'; };
  var GROUP_OF_COL = { g_ov: 'fabric', cost_ov: 'cost', price_ov: 'price' };
  var n = function (v) { v = parseFloat(v); return isFinite(v) ? v : 0; };

  // ---- size-chart columns
  var TOP_COLS = [['chest', 'Chest', 'Full chest round'], ['length', 'Length', 'Shoulder to hem'], ['sleeve', 'Sleeve', 'Sleeve length'], ['sleeve_w', 'Sleeve width', 'Flat, at armhole']];
  var PANT_COLS = [['waist', 'Waist', 'Relaxed, full round'], ['hip', 'Hip', 'Full round'], ['length', 'Length', 'Outseam, waist to hem'], ['thigh', 'Thigh', 'Full round']];
  var TOP_HELP = 'Measurements in inches. Chest = full chest round (body width × 2). Sleeve width = flat width at the armhole.';
  var PANT_HELP = 'Measurements in inches. Waist (relaxed) and hip = full round; length = outseam from waist to hem; thigh = full round at the crotch.';

  // ---- cut pieces of one garment: [name, count, width, length, nest, fill]
  // nest < 1: tapered pieces (sleeves, legs) laid head-to-tail use less length than their bounding box.
  // fill: share of the bounding box the real shape covers (neck & armhole curves, tapers) — used for the utilisation %.
  function body(r, st) { return ['Front & back', 2, n(r.chest) / 2 + n(st.seam_w), n(r.length) + n(st.len_allow), 1, 0.9]; }
  function sleeves(r, st, nest) { return ['Sleeves', 2, 2 * n(r.sleeve_w) + n(st.slv_w_allow), n(r.sleeve) + n(st.slv_len_allow), nest, nest < 1 ? 0.75 : 0.8]; }
  function legs(r, st) {
    var len = n(r.length) + n(st.len_allow);
    return [
      ['Front legs', 2, Math.max(n(r.hip) / 4 + 2, n(r.thigh) / 2 + 1) + n(st.seam_w), len, 0.92, 0.8],
      ['Back legs', 2, Math.max(n(r.hip) / 4 + 3.5, n(r.thigh) / 2 + 2.5) + n(st.seam_w), len + 1, 0.92, 0.8]
    ];
  }
  var PRODUCTS = {
    regular: {
      label: 'Regular fit T-shirt', cols: TOP_COLS, help: TOP_HELP, hint: 'Typical fabric: 160–200 GSM single jersey.',
      defaults: { c_cmt: 35, c_acc: 0, rib_g: 12 }, acc: 'Other accessories',
      sizes: [['S', 38, 27, 7.5, 7.5], ['M', 40, 28, 8, 8], ['L', 42, 29, 8, 8.25], ['XL', 44, 30, 8.5, 8.5], ['XXL', 46, 31, 8.5, 9]],
      pieces: function (r, st) { return [body(r, st), sleeves(r, st, 1)]; }
    },
    oversized: {
      label: 'Oversized T-shirt', cols: TOP_COLS, help: TOP_HELP, hint: 'Typical fabric: 200–240 GSM. Drop shoulder, wider body and sleeves.',
      defaults: { c_cmt: 40, c_acc: 0, rib_g: 15 }, acc: 'Other accessories',
      sizes: [['S', 42, 28, 9.5, 9], ['M', 44, 29, 10, 9.5], ['L', 46, 30, 10, 10], ['XL', 48, 31, 10.5, 10.5], ['XXL', 50, 32, 10.5, 11]],
      pieces: function (r, st) { return [body(r, st), sleeves(r, st, 1)]; }
    },
    polo: {
      label: 'Polo T-shirt', cols: TOP_COLS, help: TOP_HELP, hint: 'Typical fabric: 200–240 GSM pique. Knitted collar & cuffs are bought ready (see accessories).',
      defaults: { c_cmt: 60, c_acc: 25, rib_g: 0 }, acc: 'Collar, cuffs & buttons',
      sizes: [['S', 38, 27, 8, 7.5], ['M', 40, 28, 8.5, 8], ['L', 42, 29, 8.5, 8.25], ['XL', 44, 30, 9, 8.5], ['XXL', 46, 31, 9, 9]],
      pieces: function (r, st) { return [body(r, st), sleeves(r, st, 1), ['Placket', 2, 3, 8, 1, 1]]; }
    },
    sweatshirt: {
      label: 'Sweatshirt', cols: TOP_COLS, help: TOP_HELP, hint: 'Typical fabric: 280–320 GSM fleece / french terry. Rib for neck, cuffs & waistband.',
      defaults: { c_cmt: 90, c_acc: 0, rib_g: 50 }, acc: 'Other accessories',
      sizes: [['S', 40, 26, 24, 9], ['M', 42, 27, 24.5, 9.5], ['L', 44, 28, 25, 10], ['XL', 46, 29, 25.5, 10.5], ['XXL', 48, 30, 26, 11]],
      pieces: function (r, st) { return [body(r, st), sleeves(r, st, 0.85)]; }
    },
    hoodie: {
      label: 'Hoodie', cols: TOP_COLS, help: TOP_HELP, hint: 'Typical fabric: 300–340 GSM fleece. Hood (2 pieces) and kangaroo pocket included; rib for cuffs & waistband.',
      defaults: { c_cmt: 110, c_acc: 10, rib_g: 45 }, acc: 'Drawcord & eyelets',
      sizes: [['S', 40, 26, 24, 9], ['M', 42, 27, 24.5, 9.5], ['L', 44, 28, 25, 10], ['XL', 46, 29, 25.5, 10.5], ['XXL', 48, 30, 26, 11]],
      pieces: function (r, st) {
        return [body(r, st), sleeves(r, st, 0.85),
          ['Hood', 2, n(r.chest) / 4 + 2, n(r.length) / 2 + 2, 1, 0.85],
          ['Kangaroo pocket', 1, n(r.chest) / 2 * 0.65 + 1, 10, 1, 0.85]];
      }
    },
    trackpants: {
      label: 'Track pants', cols: PANT_COLS, help: PANT_HELP, hint: 'Typical fabric: 240–300 GSM. Waistband from the same fabric; 2 side pockets.',
      defaults: { c_cmt: 70, c_acc: 15, rib_g: 0 }, acc: 'Elastic & drawcord',
      sizes: [['S', 28, 38, 38, 22], ['M', 30, 40, 39, 23], ['L', 32, 42, 40, 24], ['XL', 34, 44, 41, 25], ['XXL', 36, 46, 42, 26]],
      pieces: function (r, st) {
        return legs(r, st).concat([['Waistband', 1, 4.5, n(r.hip) + 1, 1, 1], ['Pocket bags', 2, 8, 13, 1, 0.9]]);
      }
    },
    joggers: {
      label: 'Joggers / shorts', cols: PANT_COLS, help: PANT_HELP + ' For shorts, enter the shorts length.', hint: 'Joggers have rib cuffs at the ankle (rib fabric); for shorts set rib to 0.',
      defaults: { c_cmt: 65, c_acc: 15, rib_g: 20 }, acc: 'Elastic & drawcord',
      sizes: [['S', 28, 38, 37, 22], ['M', 30, 40, 38, 23], ['L', 32, 42, 39, 24], ['XL', 34, 44, 40, 25], ['XXL', 36, 46, 41, 26]],
      pieces: function (r, st) {
        return legs(r, st).concat([['Waistband', 1, 4.5, n(r.hip) + 1, 1, 1], ['Pocket bags', 2, 8, 13, 1, 0.9]]);
      }
    }
  };
  PRODUCTS.other = PRODUCTS.regular; // estimates saved before products were added
  function product() { return PRODUCTS[st.style] || PRODUCTS.regular; }
  function presetRows(p, qty) {
    return p.sizes.map(function (s) {
      var row = { size: s[0], qty: (qty && qty[s[0]]) || 0 };
      p.cols.forEach(function (c, i) { row[c[0]] = s[i + 1]; });
      return row;
    });
  }

  var DEFAULTS = {
    style: 'regular', gsm: 180, fabric_form: 'open', roll_width: 72, edge_waste: 1, fabric_price: 420, wastage: 5,
    rib_g: 12, rib_price: 450, seam_w: 1, len_allow: 2.5, slv_len_allow: 1.5, slv_w_allow: 1,
    c_cmt: 35, c_acc: 0, c_print: 0, c_embroidery: 0, c_labels: 3, c_trims: 2, c_finishing: 3, c_packing: 3, c_other: 0, fixed: 0,
    buffer: 10, profit: 40, gst: 5
  };
  var COSTS = [['c_cmt', 'Cutting & stitching'], ['c_acc', null], ['c_print', 'Printing'], ['c_embroidery', 'Embroidery'], ['c_labels', 'Labels'],
    ['c_trims', 'Thread & trims'], ['c_finishing', 'Ironing & finishing'], ['c_packing', 'Packing'], ['c_other', 'Transport / other']];

  // Rates an admin saved as defaults, per product.
  var ADMIN = {};
  try { ADMIN = JSON.parse(form.dataset.defaults || '{}') || {}; } catch (e) {}
  function defaultsFor(style) {
    return Object.assign({}, DEFAULTS, (PRODUCTS[style] || PRODUCTS.regular).defaults, ADMIN[style] || {});
  }
  var saved = null;
  try { saved = JSON.parse(document.getElementById('estSaved').textContent); } catch (e) {}
  var st = Object.assign(defaultsFor((saved && saved.style) || 'regular'), saved || {});
  if (st.style === 'other') st.style = 'regular';
  st.extra = Array.isArray(st.extra) ? st.extra.map(function (x) { return Object.assign({}, x); }) : [];
  st.sizes = saved && saved.sizes ? saved.sizes.map(function (r) { return Object.assign({}, r); }) : presetRows(product());

  var rows = document.getElementById('sizeRows');
  var $ = function (id) { return document.getElementById(id); };
  var rs = function (v, d) { return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: d === undefined ? 2 : d, maximumFractionDigits: d === undefined ? 2 : d }); };
  var fx = function (v, d) { return v.toLocaleString('en-IN', { maximumFractionDigits: d }); };
  /** A box the user filled in (empty = work it out automatically). */
  var has = function (v) { return v !== undefined && v !== null && String(v).trim() !== ''; };
  function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // ---- inputs bound to st
  function fillInputs() {
    form.querySelectorAll('[data-k]').forEach(function (inp) {
      var k = inp.dataset.k;
      if (inp.type === 'radio') inp.checked = st[k] === inp.value;
      else inp.value = st[k];
    });
  }
  form.addEventListener('input', onChange);
  form.addEventListener('change', onChange);
  function onChange(e) {
    var t = e.target;
    if (t.dataset.k) {
      if (t.type === 'radio' && !t.checked) return;
      st[t.dataset.k] = t.value;
      if (t.dataset.k === 'style' && st.style !== shownStyle) switchProduct();
    } else if (t.dataset.col) {
      st.sizes[+t.closest('tr').dataset.i][t.dataset.col] = t.value;
    } else return;
    calc();
  }
  /** New product: its size chart (quantities kept by size) and its usual making costs. */
  var shownStyle = st.style;
  function switchProduct() {
    shownStyle = st.style;
    var p = product(), qty = {};
    st.sizes.forEach(function (r) { qty[String(r.size).toUpperCase()] = r.qty; });
    st.sizes = presetRows(p, qty);
    Object.assign(st, p.defaults, ADMIN[st.style] || {});
    if (ADMIN[st.style] && ADMIN[st.style].extra) st.extra = ADMIN[st.style].extra.map(function (x) { return Object.assign({}, x); });
    renderExtra();
    fillInputs();
    renderRows();
  }

  // ---- size rows (columns follow the product)
  function renderRows() {
    var p = product();
    $('sizeHead').innerHTML = '<tr><th>Size</th><th class="num">Qty</th>' +
      p.cols.map(function (c) { return '<th class="num" title="' + esc(c[2]) + '">' + esc(c[1]) + '</th>'; }).join('') +
      '<th class="num col-fabric">Fabric g/pc ✎</th><th class="num col-fabric">Use</th><th class="num col-cost">Cost/pc ✎</th><th class="num col-price">Price/pc ✎</th><th class="num col-price">Amount</th><th></th></tr>';
    $('footGap').colSpan = p.cols.length;
    $('sizeHelp').textContent = p.help + ' Fabric g, cost and price per piece (✎) are worked out for you — type in a box to use your own figure, clear it to go back to automatic.';
    $('productHint').textContent = p.hint;
    var acc = form.querySelector('[data-k=c_acc]').closest('.field').querySelector('.lbl');
    acc.textContent = p.acc;
    rows.innerHTML = '';
    st.sizes.forEach(function (r, i) {
      var tr = document.createElement('tr');
      tr.dataset.i = i;
      tr.innerHTML =
        '<td><input class="est-cell est-size" data-col="size" value="' + esc(r.size) + '"></td>' +
        ['qty'].concat(p.cols.map(function (c) { return c[0]; })).map(function (c) {
          return '<td class="num"><input class="est-cell" type="number" inputmode="decimal" min="0" step="' + (c === 'qty' ? '1' : 'any') + '" data-col="' + c + '" value="' + esc(r[c]) + '"></td>';
        }).join('') +
        ovCell('g_ov', r) + '<td class="num col-fabric" data-out="util"></td>' + ovCell('cost_ov', r) + ovCell('price_ov', r) + '<td class="num col-price" data-out="amt"></td>' +
        '<td><button type="button" class="icon-btn" data-del-size title="Remove size" aria-label="Remove size">✕</button></td>';
      rows.appendChild(tr);
    });
  }
  /** Worked-out value shown as the box's placeholder; typing a number overrides it for that size. */
  function ovCell(col, r) {
    var g = GROUP_OF_COL[col];
    return '<td class="num col-' + g + '"><input class="est-cell est-ov' + (has(r[col]) ? ' is-manual' : '') + '" type="number" inputmode="decimal" min="0" step="any" data-col="' + col + '" value="' + esc(r[col]) + '"' +
      (lv(g) === 'edit' ? ' title="Worked out automatically — type to set your own, clear to go back"' : ' readonly tabindex="-1"') + '></td>';
  }
  rows.addEventListener('click', function (e) {
    if (!e.target.closest('[data-del-size]')) return;
    st.sizes.splice(+e.target.closest('tr').dataset.i, 1);
    renderRows(); calc();
  });
  form.querySelector('[data-add-size]').addEventListener('click', function () {
    var p = product(), last = st.sizes[st.sizes.length - 1] || presetRows(p)[1], row = { size: '', qty: 0 };
    p.cols.forEach(function (c) { row[c[0]] = n(last[c[0]]) + (c[0] === 'sleeve' ? 0 : c[0] === 'sleeve_w' ? 0.5 : c[0] === 'length' ? 1 : 2); });
    st.sizes.push(row);
    renderRows(); calc();
    rows.lastChild.querySelector('input').focus();
  });
  form.querySelector('[data-preset]').addEventListener('click', function () {
    var qty = {};
    st.sizes.forEach(function (r) { qty[String(r.size).toUpperCase()] = r.qty; });
    st.sizes = presetRows(product(), qty);
    renderRows(); calc();
  });

  // ---- extra cost lines (own name + ₹ per piece)
  var extraBox = $('extraCosts');
  function renderExtra() {
    extraBox.innerHTML = st.extra.map(function (x, i) {
      return '<div class="extra-row" data-x="' + i + '"><input data-xk="name" value="' + esc(x.name) + '" placeholder="Cost name, e.g. Sticker / tag"><div class="est-input"><input type="number" inputmode="decimal" min="0" step="any" data-xk="amt" value="' + esc(x.amt) + '"><span class="est-suffix">₹/pc</span></div>' +
        (lv('making') === 'edit' ? '<button type="button" class="icon-btn" data-del-cost aria-label="Remove cost" title="Remove">✕</button>' : '') + '</div>';
    }).join('');
    if (lv('making') !== 'edit') extraBox.querySelectorAll('input').forEach(function (i) { i.readOnly = true; });
  }
  extraBox.addEventListener('input', function (e) {
    var row = e.target.closest('[data-x]');
    if (!row || !e.target.dataset.xk) return;
    st.extra[+row.dataset.x][e.target.dataset.xk] = e.target.value;
    calc();
  });
  extraBox.addEventListener('click', function (e) {
    if (!e.target.closest('[data-del-cost]')) return;
    st.extra.splice(+e.target.closest('[data-x]').dataset.x, 1);
    renderExtra(); calc();
  });
  form.querySelector('[data-add-cost]').addEventListener('click', function () {
    st.extra.push({ name: '', amt: '' });
    renderExtra(); calc();
    extraBox.lastChild.querySelector('input').focus();
  });

  // ---- fabric for one garment of a size
  function fabricFor(r) {
    var usable = (st.fabric_form === 'tube' ? 2 * n(st.roll_width) : n(st.roll_width)) - n(st.edge_waste);
    if (usable <= 0) return { err: 'No usable roll width' };
    var lenIn = 0, cutArea = 0, list = [];
    for (var i = 0, ps = product().pieces(r, st); i < ps.length; i++) {
      var pc = ps[i], name = pc[0], count = pc[1], w = pc[2], l = pc[3], nest = pc[4], fill = pc[5] || 1, turned = false;
      if (!(w > 0) || !(l > 0) || !count) continue;
      if (w > usable && l <= usable) { var t = w; w = l; l = t; turned = true; } // turn it across the roll
      var across = Math.floor(usable / w);
      if (across < 1) return { err: name + ' (' + fx(w, 1) + '″) is wider than the usable fabric' };
      var len = count * l / across * (across > 1 || count > 1 ? nest : 1);
      lenIn += len; cutArea += count * w * l * fill;
      list.push({ name: name, count: count, w: w, l: l, across: across, len: len, turned: turned });
    }
    var waste = 1 + n(st.wastage) / 100;
    return {
      grams: lenIn * usable * IN2_TO_M2 * n(st.gsm) * waste, lenIn: lenIn * waste, usable: usable,
      util: lenIn ? Math.min(1, cutArea / (lenIn * usable)) : 0, pieces: list
    };
  }

  function calc() {
    var totalQty = 0, totalG = 0, totalLen = 0, totalAmt = 0, totalCost = 0, warn = [];
    st.sizes.forEach(function (r) { totalQty += Math.max(0, Math.round(n(r.qty))); });
    var making = COSTS.reduce(function (s, c) { return s + n(st[c[0]]); }, 0)
      + st.extra.reduce(function (s, x) { return s + n(x.amt); }, 0);
    var fixedPc = totalQty ? n(st.fixed) / totalQty : 0;
    var rib = n(st.rib_g) / 1000 * n(st.rib_price);
    var buf = n(st.buffer) / 100, profit = n(st.profit);
    var results = st.sizes.map(function (r, i) {
      var f = fabricFor(r), tr = rows.children[i];
      var out = function (k, v) { tr.querySelector('[data-out="' + k + '"]').innerHTML = v; };
      var box = function (k, v) { var b = tr.querySelector('[data-col="' + k + '"]'); b.placeholder = v; b.classList.toggle('is-manual', has(r[k])); };
      var manual = { g: has(r.g_ov), cost: has(r.cost_ov), price: has(r.price_ov) };
      if (f.err && !manual.g && !manual.cost && !manual.price) {
        box('g_ov', 'too wide'); box('cost_ov', ''); box('price_ov', ''); out('util', ''); out('amt', '');
        warn.push((r.size || 'A size') + ': ' + f.err + ' — check the roll width, or type the fabric grams yourself.');
        return null;
      }
      if (f.err) f = { grams: 0, lenIn: 0, usable: 0, util: 0, pieces: [] };
      var grams = manual.g ? n(r.g_ov) : f.grams;
      var fabric = grams / 1000 * n(st.fabric_price);
      var autoCost = fabric + rib + making + fixedPc;
      var cost = manual.cost ? n(r.cost_ov) : autoCost;
      var autoPrice = cost * (1 + buf) + profit;
      var price = manual.price ? n(r.price_ov) : autoPrice;
      var q = Math.max(0, Math.round(n(r.qty)));
      box('g_ov', fx(f.grams, 0)); box('cost_ov', autoCost.toFixed(2)); box('price_ov', autoPrice.toFixed(2));
      out('util', f.util ? Math.round(f.util * 100) + '%' : '–'); out('amt', q ? rs(price * q, 0) : '–');
      totalG += grams * q; totalLen += (manual.g && f.grams ? f.lenIn * grams / f.grams : f.lenIn) * q; totalAmt += price * q; totalCost += cost * q;
      return { r: r, f: f, grams: grams, fabric: fabric, cost: cost, price: price, q: q, manual: manual };
    });

    // Piece shown in the breakdown: the size with most pieces, else the middle size.
    var ok = results.filter(Boolean);
    var pick = ok.slice().sort(function (a, b) { return b.q - a.q; })[0];
    if (pick && !pick.q) pick = ok[Math.floor(ok.length / 2)];
    var avgPrice = totalQty ? totalAmt / totalQty : (pick ? pick.price : 0);
    var gst = n(st.gst) / 100;
    if (pick && pick.f.util < 0.7) warn.push('Only ' + Math.round(pick.f.util * 100) + '% of the fabric ends up in the garment for size ' + (pick.r.size || '?') + '. Another roll width may cut with less waste.');

    $('footQty').textContent = totalQty;
    $('footG').textContent = totalQty ? fx(totalG / 1000, 1) + ' kg' : '';
    $('footAmt').textContent = totalQty ? rs(totalAmt, 0) : '';
    $('rPrice').textContent = avgPrice ? rs(avgPrice) : '–';
    $('rPriceSub').textContent = avgPrice ? (gst ? rs(avgPrice * (1 + gst)) + ' with ' + fx(gst * 100, 1) + '% GST' : 'per piece') : 'per piece';
    $('rTotal').textContent = totalQty ? rs(totalAmt, 0) : '–';
    $('rTotalSub').textContent = totalQty ? totalQty + ' pcs' + (gst ? ' · ' + rs(totalAmt * (1 + gst), 0) + ' with GST' : '') : 'enter quantities';
    $('rFabric').textContent = totalQty ? fx(totalG / 1000, 1) + ' kg' : '–';
    $('rFabricSub').textContent = totalQty ? 'about ' + fx(totalLen / 39.37, 0) + ' m of ' + (st.fabric_form === 'tube' ? 'tube' : 'open') + ' fabric · ' + rs(totalG / 1000 * n(st.fabric_price), 0) : '';
    var avgCost = totalQty ? totalCost / totalQty : (pick ? pick.cost : 0);
    $('rCost').textContent = avgCost ? rs(avgCost) : '–';
    $('rCostSub').textContent = totalQty ? 'average · ' + rs(totalCost, 0) + ' for ' + totalQty + ' pcs' : 'before buffer & profit';
    $('rProfit').textContent = totalQty ? rs(totalAmt - totalCost, 0) : '–';
    $('rProfitSub').textContent = totalQty ? rs(profit, 0) + '/pc + ' + fx(buf * 100, 1) + '% buffer' : '';
    $('estBar').textContent = !sees('price') ? (totalQty ? totalQty + ' pcs' : '') : avgPrice ? rs(avgPrice) + '/pc' + (totalQty ? ' · ' + rs(totalAmt, 0) : '') : '';

    // cut pieces of the picked size
    if (pick) {
      $('pcSize').textContent = '· ' + product().label + ', size ' + (pick.r.size || '?') + ' · ' + Math.round(pick.f.util * 100) + '% of the fabric used';
      $('pieces').innerHTML = pick.f.pieces.map(function (p) {
        return '<tr><td>' + esc(p.name) + (p.turned ? ' <small class="muted">(turned)</small>' : '') + '</td><td class="num">' + p.count + '</td><td class="num">' + fx(p.w, 1) + '″ × ' + fx(p.l, 1) + '″</td><td class="num">' + p.across + '</td><td class="num">' + fx(p.len, 1) + '″</td></tr>';
      }).join('') + '<tr class="sub"><td colspan="4"><b>Per garment</b> <small class="muted">+' + fx(n(st.wastage), 1) + '% wastage, ' + fx(pick.f.usable, 1) + '″ usable</small></td><td class="num"><b>' + fx(pick.f.lenIn, 1) + '″</b><br><small>' + fx(pick.f.lenIn * 2.54, 0) + ' cm</small></td></tr>';
    } else { $('pcSize').textContent = ''; $('pieces').innerHTML = ''; }

    // cost of one piece
    var bd = $('breakdown');
    if (pick) {
      $('bdSize').textContent = '· size ' + (pick.r.size || '?');
      var M = ' <span class="manual-tag">manual</span>';
      var html = '';
      if (pick.manual.cost) {
        html += '<tr class="sub"><td><b>Cost</b>' + M + '</td><td class="num"><b>' + rs(pick.cost) + '</b></td></tr>';
      } else {
        var lines = [], hiddenPart = 0;
        if (sees('fabric')) {
          lines.push(['Fabric (' + fx(pick.grams, 0) + ' g × ' + rs(n(st.fabric_price), 0) + '/kg)' + (pick.manual.g ? M : ''), pick.fabric]);
          if (rib) lines.push([esc('Rib (' + fx(n(st.rib_g), 0) + ' g)'), rib]);
        } else hiddenPart += pick.fabric + rib;
        if (sees('making')) {
          COSTS.forEach(function (c) { if (n(st[c[0]])) lines.push([esc(c[1] || product().acc), n(st[c[0]])]); });
          st.extra.forEach(function (x) { if (n(x.amt)) lines.push([esc(x.name || 'Other cost'), n(x.amt)]); });
          if (fixedPc) lines.push([esc('One-time costs ÷ ' + totalQty + ' pcs'), fixedPc]);
        } else hiddenPart += making + fixedPc;
        if (hiddenPart > 0.004) lines.push(['Other costs', hiddenPart]);
        html += lines.map(function (l) { return '<tr><td>' + l[0] + '</td><td class="num">' + rs(l[1]) + '</td></tr>'; }).join('');
        html += '<tr class="sub"><td><b>Cost</b></td><td class="num"><b>' + rs(pick.cost) + '</b></td></tr>';
      }
      if (sees('margin') && !pick.manual.price) {
        html += '<tr><td>Buffer ' + fx(buf * 100, 1) + '%</td><td class="num">' + rs(pick.cost * buf) + '</td></tr>';
        html += '<tr><td>Profit</td><td class="num">' + rs(profit) + '</td></tr>';
      } else if (sees('margin')) {
        html += '<tr><td>Margin over cost</td><td class="num">' + rs(pick.price - pick.cost) + '</td></tr>';
      }
      if (sees('price')) html += '<tr class="total"><td><b>Price per piece</b>' + (pick.manual.price ? M : '') + '</td><td class="num"><b>' + rs(pick.price) + '</b></td></tr>';
      if (gst && sees('price')) html += '<tr><td>With ' + fx(gst * 100, 1) + '% GST</td><td class="num">' + rs(pick.price * (1 + gst)) + '</td></tr>';
      bd.innerHTML = html;
    } else { $('bdSize').textContent = ''; bd.innerHTML = ''; }

    $('estWarn').textContent = sees('fabric') ? warn.join(' ') : '';
    $('formHint').textContent = st.fabric_form === 'tube'
      ? 'Tube is cut open, so usable width = 2 × ' + fx(n(st.roll_width), 1) + '″ − edge = ' + fx(2 * n(st.roll_width) - n(st.edge_waste), 1) + '″'
      : 'Usable width = ' + fx(n(st.roll_width), 1) + '″ − edge = ' + fx(n(st.roll_width) - n(st.edge_waste), 1) + '″';

    // values posted on save
    $('estData').value = JSON.stringify(st);
    $('estQty').value = totalQty;
    $('estPrice').value = avgPrice.toFixed(2);
    $('estTotal').value = totalAmt.toFixed(2);
    return { results: results, totalQty: totalQty, totalAmt: totalAmt, avgPrice: avgPrice, gst: gst };
  }

  // ---- printable quote for the customer
  document.querySelectorAll('[data-print-quote]').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = calc(), sheet = $('quoteSheet');
      var name = form.querySelector('[name=name]').value.trim(), cust = form.querySelector('[name=customer]').value.trim();
      var lines = c.results.filter(function (x) { return x && x.q; });
      var html = '<h1>' + esc(form.dataset.company) + '</h1><p class="q-sub">Quotation · ' + new Date().toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) + '</p>';
      html += '<p>' + (cust ? '<b>To:</b> ' + esc(cust) + '<br>' : '') + (name ? '<b>For:</b> ' + esc(name) + '<br>' : '') +
        esc(product().label) + ' · ' + fx(n(st.gsm), 0) + ' GSM</p>';
      html += '<table><thead><tr><th>Size</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead><tbody>';
      (lines.length ? lines : c.results.filter(Boolean)).forEach(function (x) {
        html += '<tr><td>' + esc(x.r.size) + '</td><td>' + (x.q || '–') + '</td><td>' + rs(x.price) + '</td><td>' + (x.q ? rs(x.price * x.q) : '–') + '</td></tr>';
      });
      html += '</tbody><tfoot><tr><th>Total</th><th>' + c.totalQty + '</th><th></th><th>' + rs(c.totalAmt) + '</th></tr>';
      if (c.gst) html += '<tr><td colspan="3">GST ' + fx(c.gst * 100, 1) + '%</td><td>' + rs(c.totalAmt * c.gst) + '</td></tr><tr><th colspan="3">Grand total</th><th>' + rs(c.totalAmt * (1 + c.gst)) + '</th></tr>';
      html += '</tfoot></table><p class="q-note">Prices are per piece. Valid for 15 days.</p>';
      sheet.innerHTML = html;
      sheet.hidden = false;
      document.body.classList.add('printing-quote');
      window.print();
    });
  });
  window.addEventListener('afterprint', function () { document.body.classList.remove('printing-quote'); $('quoteSheet').hidden = true; });

  /** Hide groups this person may not see; lock the ones they may only view. */
  function applyPerms() {
    var table = form.querySelector('.est-sizes');
    ['fabric', 'cost', 'price'].forEach(function (g) { table.classList.toggle('hide-' + g, !sees(g)); });
    form.parentNode.querySelectorAll('[data-pgroup]').forEach(function (el) {
      var g = el.dataset.pgroup;
      el.hidden = !sees(g) || (el.dataset.needs && !sees(el.dataset.needs));
      if (lv(g) === 'view') {
        el.querySelectorAll('input').forEach(function (i) { if (i.type === 'radio') i.disabled = true; else i.readOnly = true; });
        el.querySelectorAll('select').forEach(function (i) { i.disabled = true; });
        el.querySelectorAll('[data-add-cost]').forEach(function (b) { b.hidden = true; });
        el.classList.add('is-view');
      }
    });
  }

  fillInputs();
  renderRows();
  renderExtra();
  applyPerms();
  calc();
})();
