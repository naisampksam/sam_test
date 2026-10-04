// Production estimate: live fabric + cost calculation for estimate.php.
(function () {
  'use strict';
  var form = document.getElementById('estForm');
  if (!form) return;

  var PRESETS = {
    // size, chest (full round), length, sleeve length, sleeve width (flat at armhole) — inches
    regular: [['S', 38, 27, 7.5, 7.5], ['M', 40, 28, 8, 8], ['L', 42, 29, 8, 8.25], ['XL', 44, 30, 8.5, 8.5], ['XXL', 46, 31, 8.5, 9]],
    oversized: [['S', 42, 28, 9.5, 9], ['M', 44, 29, 10, 9.5], ['L', 46, 30, 10, 10], ['XL', 48, 31, 10.5, 10.5], ['XXL', 50, 32, 10.5, 11]]
  };
  var DEFAULTS = {
    style: 'regular', gsm: 180, fabric_form: 'open', roll_width: 72, edge_waste: 1, fabric_price: 420, wastage: 5,
    rib_g: 12, rib_price: 450, seam_w: 1, len_allow: 2.5, slv_len_allow: 1.5, slv_w_allow: 1,
    c_cmt: 35, c_print: 0, c_embroidery: 0, c_labels: 3, c_trims: 2, c_finishing: 3, c_packing: 3, c_other: 0, fixed: 0,
    buffer: 10, profit: 40, gst: 5,
    sizes: PRESETS.regular.map(function (r) { return { size: r[0], qty: 0, chest: r[1], length: r[2], sleeve: r[3], sleeve_w: r[4] }; })
  };
  var COSTS = [['c_cmt', 'Cutting & stitching'], ['c_print', 'Printing'], ['c_embroidery', 'Embroidery'], ['c_labels', 'Labels'],
    ['c_trims', 'Thread & trims'], ['c_finishing', 'Ironing & finishing'], ['c_packing', 'Packing'], ['c_other', 'Transport / other']];
  var IN2_TO_M2 = 0.00064516;

  var saved = null;
  try { saved = JSON.parse(document.getElementById('estSaved').textContent); } catch (e) {}
  var st = Object.assign({}, DEFAULTS, saved || {});
  st.sizes = (saved && saved.sizes ? saved.sizes : DEFAULTS.sizes).map(function (r) { return Object.assign({}, r); });

  var rows = document.getElementById('sizeRows');
  var $ = function (id) { return document.getElementById(id); };
  var n = function (v) { v = parseFloat(v); return isFinite(v) ? v : 0; };
  var rs = function (v, d) { return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: d === undefined ? 2 : d, maximumFractionDigits: d === undefined ? 2 : d }); };
  var fx = function (v, d) { return v.toLocaleString('en-IN', { maximumFractionDigits: d }); };
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // ---- inputs bound to st
  form.querySelectorAll('[data-k]').forEach(function (inp) {
    var k = inp.dataset.k;
    if (inp.type === 'radio') inp.checked = st[k] === inp.value;
    else inp.value = st[k];
  });
  form.addEventListener('input', onChange);
  form.addEventListener('change', onChange);
  function onChange(e) {
    var t = e.target;
    if (t.dataset.k) {
      st[t.dataset.k] = t.type === 'number' ? t.value : t.value;
      if (t.type === 'radio' && !t.checked) return;
    } else if (t.dataset.col) {
      var i = +t.closest('tr').dataset.i;
      st.sizes[i][t.dataset.col] = t.value;
    } else return;
    calc();
  }

  // ---- size rows
  function renderRows() {
    rows.innerHTML = '';
    st.sizes.forEach(function (r, i) {
      var tr = document.createElement('tr');
      tr.dataset.i = i;
      tr.innerHTML =
        '<td><input class="est-cell est-size" data-col="size" value="' + esc(r.size) + '"></td>' +
        ['qty', 'chest', 'length', 'sleeve', 'sleeve_w'].map(function (c) {
          return '<td class="num"><input class="est-cell" type="number" inputmode="decimal" min="0" step="' + (c === 'qty' ? '1' : 'any') + '" data-col="' + c + '" value="' + esc(r[c]) + '"></td>';
        }).join('') +
        '<td class="num" data-out="g"></td><td class="num" data-out="cost"></td><td class="num" data-out="price"></td><td class="num" data-out="amt"></td>' +
        '<td><button type="button" class="icon-btn" data-del-size title="Remove size" aria-label="Remove size">✕</button></td>';
      rows.appendChild(tr);
    });
  }
  rows.addEventListener('click', function (e) {
    if (!e.target.closest('[data-del-size]')) return;
    st.sizes.splice(+e.target.closest('tr').dataset.i, 1);
    renderRows(); calc();
  });
  form.querySelector('[data-add-size]').addEventListener('click', function () {
    var last = st.sizes[st.sizes.length - 1] || { chest: 40, length: 28, sleeve: 8, sleeve_w: 8 };
    st.sizes.push({ size: '', qty: 0, chest: n(last.chest) + 2, length: n(last.length) + 1, sleeve: last.sleeve, sleeve_w: n(last.sleeve_w) + 0.5 });
    renderRows(); calc();
    rows.lastChild.querySelector('input').focus();
  });
  form.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var qty = {};
      st.sizes.forEach(function (r) { qty[String(r.size).toUpperCase()] = r.qty; });
      st.sizes = PRESETS[b.dataset.preset].map(function (p) {
        return { size: p[0], qty: qty[p[0]] || 0, chest: p[1], length: p[2], sleeve: p[3], sleeve_w: p[4] };
      });
      renderRows(); calc();
    });
  });

  // ---- the sums
  /** Fabric for one piece of a size: panels side by side across the usable roll width. */
  function fabricFor(r) {
    var usable = (st.fabric_form === 'tube' ? 2 * n(st.roll_width) : n(st.roll_width)) - n(st.edge_waste);
    var bodyW = n(r.chest) / 2 + n(st.seam_w), bodyL = n(r.length) + n(st.len_allow);
    var slvW = 2 * n(r.sleeve_w) + n(st.slv_w_allow), slvL = n(r.sleeve) + n(st.slv_len_allow);
    var nb = bodyW > 0 ? Math.floor(usable / bodyW) : 0, ns = slvW > 0 ? Math.floor(usable / slvW) : 0;
    if (usable <= 0 || nb < 1 || (n(r.sleeve) > 0 && ns < 1)) return { err: 'Fabric is narrower than a panel' };
    // 2 body panels (front + back) and 2 sleeves per piece; fabric length used in inches
    var lenIn = 2 * bodyL / nb + (n(r.sleeve) > 0 ? 2 * slvL / ns : 0);
    var grams = lenIn * usable * IN2_TO_M2 * n(st.gsm) * (1 + n(st.wastage) / 100);
    return { grams: grams, lenIn: lenIn * (1 + n(st.wastage) / 100), nb: nb, ns: ns, usable: usable, util: nb * bodyW / usable };
  }

  function calc() {
    var totalQty = 0, totalG = 0, totalLen = 0, totalAmt = 0, totalCost = 0, warn = [];
    st.sizes.forEach(function (r) { totalQty += Math.max(0, Math.round(n(r.qty))); });
    var making = COSTS.reduce(function (s, c) { return s + n(st[c[0]]); }, 0);
    var fixedPc = totalQty ? n(st.fixed) / totalQty : 0;
    var rib = n(st.rib_g) / 1000 * n(st.rib_price);
    var buf = n(st.buffer) / 100, profit = n(st.profit);
    var results = st.sizes.map(function (r, i) {
      var f = fabricFor(r), tr = rows.children[i];
      var out = function (k, v) { tr.querySelector('[data-out="' + k + '"]').innerHTML = v; };
      if (f.err) { out('g', '<span class="err-text">too wide</span>'); out('cost', ''); out('price', ''); out('amt', ''); warn.push((r.size || 'A size') + ': ' + f.err.toLowerCase() + ' — check roll width.'); return null; }
      var fabric = f.grams / 1000 * n(st.fabric_price);
      var cost = fabric + rib + making + fixedPc;
      var price = cost * (1 + buf) + profit;
      var q = Math.max(0, Math.round(n(r.qty)));
      out('g', fx(f.grams, 0) + ' g'); out('cost', rs(cost)); out('price', '<b>' + rs(price) + '</b>'); out('amt', q ? rs(price * q, 0) : '–');
      totalG += f.grams * q; totalLen += f.lenIn * q; totalAmt += price * q; totalCost += cost * q;
      if (f.util < 0.75) warn.push((r.size || 'A size') + ': only ' + Math.round(f.util * 100) + '% of the roll width is used for the body (' + f.nb + ' panel' + (f.nb > 1 ? 's' : '') + ' across). A different roll width would waste less.');
      return { r: r, f: f, fabric: fabric, cost: cost, price: price, q: q };
    });

    // Piece shown in the breakdown: the size with most pieces, else the middle size.
    var ok = results.filter(Boolean);
    var pick = ok.slice().sort(function (a, b) { return b.q - a.q; })[0];
    if (pick && !pick.q) pick = ok[Math.floor(ok.length / 2)];
    var avgPrice = totalQty ? totalAmt / totalQty : (pick ? pick.price : 0);
    var gst = n(st.gst) / 100;

    $('footQty').textContent = totalQty;
    $('footG').textContent = totalQty ? fx(totalG / 1000, 1) + ' kg' : '';
    $('footAmt').textContent = totalQty ? rs(totalAmt, 0) : '';
    $('rPrice').textContent = avgPrice ? rs(avgPrice) : '–';
    $('rPriceSub').textContent = avgPrice ? (gst ? rs(avgPrice * (1 + gst)) + ' with ' + fx(gst * 100, 1) + '% GST' : 'per piece') : 'per piece';
    $('rTotal').textContent = totalQty ? rs(totalAmt, 0) : '–';
    $('rTotalSub').textContent = totalQty ? totalQty + ' pcs' + (gst ? ' · ' + rs(totalAmt * (1 + gst), 0) + ' with GST' : '') : 'enter quantities';
    $('rFabric').textContent = totalQty ? fx(totalG / 1000, 1) + ' kg' : '–';
    $('rFabricSub').textContent = totalQty ? 'about ' + fx(totalLen / 39.37, 0) + ' m of ' + (st.fabric_form === 'tube' ? 'tube' : 'open') + ' fabric · ' + rs(totalG / 1000 * n(st.fabric_price), 0) : '';
    var profitTotal = totalAmt - totalCost;
    $('rProfit').textContent = totalQty ? rs(profitTotal, 0) : '–';
    $('rProfitSub').textContent = totalQty ? rs(profit, 0) + '/pc + ' + fx(buf * 100, 1) + '% buffer' : '';
    $('estBar').textContent = avgPrice ? rs(avgPrice) + '/pc' + (totalQty ? ' · ' + rs(totalAmt, 0) : '') : '';

    // breakdown of one piece
    var bd = $('breakdown');
    if (pick) {
      $('bdSize').textContent = '· size ' + (pick.r.size || '?') + ' (' + fx(pick.f.grams, 0) + ' g fabric, ' + pick.f.nb + ' body panels across the roll)';
      var lines = [['Fabric (' + fx(pick.f.grams, 0) + ' g × ' + rs(n(st.fabric_price), 0) + '/kg)', pick.fabric], ['Neck rib (' + fx(n(st.rib_g), 0) + ' g)', rib]];
      COSTS.forEach(function (c) { if (n(st[c[0]])) lines.push([c[1], n(st[c[0]])]); });
      if (fixedPc) lines.push(['One-time costs ÷ ' + totalQty + ' pcs', fixedPc]);
      var html = lines.map(function (l) { return '<tr><td>' + esc(l[0]) + '</td><td class="num">' + rs(l[1]) + '</td></tr>'; }).join('');
      html += '<tr class="sub"><td><b>Cost</b></td><td class="num"><b>' + rs(pick.cost) + '</b></td></tr>';
      html += '<tr><td>Buffer ' + fx(buf * 100, 1) + '%</td><td class="num">' + rs(pick.cost * buf) + '</td></tr>';
      html += '<tr><td>Profit</td><td class="num">' + rs(profit) + '</td></tr>';
      html += '<tr class="total"><td><b>Price per piece</b></td><td class="num"><b>' + rs(pick.price) + '</b></td></tr>';
      if (gst) html += '<tr><td>With ' + fx(gst * 100, 1) + '% GST</td><td class="num">' + rs(pick.price * (1 + gst)) + '</td></tr>';
      bd.innerHTML = html;
    } else {
      $('bdSize').textContent = ''; bd.innerHTML = '';
    }
    $('estWarn').textContent = warn.join(' ');
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
  var STYLE = { regular: 'Round-neck T-shirt, regular fit', oversized: 'Round-neck T-shirt, oversized', polo: 'Polo T-shirt', other: 'Custom garment' };
  document.querySelectorAll('[data-print-quote]').forEach(function (b) {
    b.addEventListener('click', function () {
      var c = calc(), sheet = $('quoteSheet');
      var name = form.querySelector('[name=name]').value.trim(), cust = form.querySelector('[name=customer]').value.trim();
      var lines = c.results.filter(function (x) { return x && x.q; });
      var html = '<h1>' + esc(form.dataset.company) + '</h1><p class="q-sub">Quotation · ' + new Date().toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) + '</p>';
      html += '<p>' + (cust ? '<b>To:</b> ' + esc(cust) + '<br>' : '') + (name ? '<b>For:</b> ' + esc(name) + '<br>' : '') +
        esc(STYLE[st.style] || '') + ' · ' + fx(n(st.gsm), 0) + ' GSM</p>';
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

  renderRows();
  calc();
})();
