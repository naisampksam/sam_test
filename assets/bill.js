// Bill editor: lines picked from stock, live GST totals, customer details, IGST for other states.
(function () {
  'use strict';
  var form = document.getElementById('billForm');
  if (!form) return;
  var STOCK = [];
  try { STOCK = JSON.parse(form.dataset.stock || '[]'); } catch (e) {}
  var byName = {};
  STOCK.forEach(function (s) { byName[s.name.toLowerCase()] = s; });
  var list = document.getElementById('dlStock');
  list.innerHTML = STOCK.map(function (s) {
    return '<option value="' + s.name.replace(/"/g, '&quot;') + '">' + (s.qty + ' ' + s.unit + ' in stock').replace(/</g, '') + '</option>';
  }).join('');
  var box = document.getElementById('billLines');
  var n = function (v) { v = parseFloat(v); return isFinite(v) ? v : 0; };
  var rs = function (v) { return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var f = function (row, k) { return row.querySelector('[data-f="' + k + '"]'); };
  var counter = 1000;

  function stockHint(row) {
    var id = f(row, 'stock_id').value, hint = row.querySelector('[data-stockhint]');
    var s = id ? STOCK.find(function (x) { return String(x.id) === id; }) : null;
    hint.textContent = s ? '📦 From stock · ' + s.qty + ' ' + s.unit + ' available' + (n(f(row, 'qty').value) > s.qty ? ' — not enough!' : '') : '';
    hint.classList.toggle('err-text', !!(s && n(f(row, 'qty').value) > s.qty));
  }

  function pickStock(row) {
    var s = byName[f(row, 'description').value.trim().toLowerCase()];
    if (s) {
      if (f(row, 'stock_id').value !== String(s.id)) {
        f(row, 'stock_id').value = s.id;
        if (s.rate) f(row, 'rate').value = s.rate;
        f(row, 'unit').value = s.unit;
        if (s.hsn) f(row, 'hsn').value = s.hsn;
        f(row, 'gst_rate').value = s.gst;
      }
    } else {
      f(row, 'stock_id').value = '';
    }
    stockHint(row);
  }

  function calc() {
    var rows = Array.prototype.slice.call(box.querySelectorAll('[data-line]')), sub = 0;
    var lines = rows.map(function (r) {
      var amt = Math.round(n(f(r, 'qty').value) * n(f(r, 'rate').value) * 100) / 100;
      r.querySelector('[data-amount]').textContent = rs(amt);
      sub += amt;
      return { amt: amt, gst: n(f(r, 'gst_rate').value) };
    });
    var disc = Math.min(Math.max(0, n(form.discount.value)), sub), taxable = 0, tax = 0;
    lines.forEach(function (l) {
      var t = sub > 0 ? Math.round((l.amt - disc * l.amt / sub) * 100) / 100 : 0;
      taxable += t; tax += Math.round(t * l.gst) / 100;
    });
    var ship = Math.max(0, n(form.shipping.value)), raw = taxable + tax + ship, total = Math.round(raw);
    var inter = form.querySelector('[data-inter]').checked;
    var html = '<tr><td>Items</td><td class="num">' + rs(sub) + '</td></tr>';
    if (disc) html += '<tr><td>Discount</td><td class="num">−' + rs(disc) + '</td></tr>';
    html += '<tr><td><b>Sales value</b> <small class="muted">(before tax &amp; shipping)</small></td><td class="num"><b>' + rs(taxable) + '</b></td></tr>';
    html += inter ? '<tr><td>IGST</td><td class="num">' + rs(tax) + '</td></tr>'
      : '<tr><td>CGST</td><td class="num">' + rs(Math.round(tax * 50) / 100) + '</td></tr><tr><td>SGST</td><td class="num">' + rs(tax - Math.round(tax * 50) / 100) + '</td></tr>';
    if (ship) html += '<tr><td>Shipping</td><td class="num">' + rs(ship) + '</td></tr>';
    if (Math.abs(total - raw) > 0.001) html += '<tr><td>Round off</td><td class="num">' + rs(total - raw) + '</td></tr>';
    html += '<tr class="total"><td><b>Total</b></td><td class="num"><b>' + rs(total) + '</b></td></tr>';
    document.querySelector('#billTotals tbody').innerHTML = html;
    document.getElementById('billBar').textContent = 'Total ' + rs(total);
  }

  box.addEventListener('input', function (e) {
    var row = e.target.closest('[data-line]');
    if (!row) return;
    if (e.target.dataset.f === 'description') pickStock(row);
    if (e.target.dataset.f === 'qty') stockHint(row);
    calc();
  });
  box.addEventListener('click', function (e) {
    if (!e.target.closest('[data-del-line]')) return;
    var rows = box.querySelectorAll('[data-line]');
    if (rows.length > 1) e.target.closest('[data-line]').remove();
    calc();
  });
  form.querySelector('[data-add-line]').addEventListener('click', function () {
    var tpl = document.getElementById('lineTpl').innerHTML.replace(/__N__/g, String(counter++));
    box.insertAdjacentHTML('beforeend', tpl);
    box.lastElementChild.querySelector('[data-f="description"]').focus();
    calc();
  });
  form.addEventListener('input', function (e) { if (e.target.matches('[data-calc]')) calc(); });
  form.addEventListener('change', function (e) { if (e.target.matches('[data-inter]')) calc(); });

  // Branding: plain bills can carry another seller's details.
  form.querySelectorAll('[data-branding]').forEach(function (r) {
    r.addEventListener('change', function () { form.querySelector('.plain-seller').hidden = form.querySelector('[data-branding][value=plain]').checked === false; });
  });

  // Other state → IGST.
  var sellerState = (form.dataset.sellerState || '').trim().toLowerCase();
  form.querySelector('[data-bill-state]').addEventListener('change', function () {
    var v = this.value.trim().toLowerCase();
    if (v && sellerState) { form.querySelector('[data-inter]').checked = v !== sellerState; calc(); }
  });

  // Saved customer → fill the bill-to details that are still empty.
  var cust = form.querySelector('[data-bill-customer]'), timer = null;
  cust.addEventListener('input', function () {
    clearTimeout(timer);
    var code = cust.value.trim();
    if (!code) return;
    timer = setTimeout(function () {
      fetch((window.BASE || '') + 'customer_search.php?code=' + encodeURIComponent(code), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.found || cust.value.trim() !== code) return;
          var c = res.customer;
          var set = function (name, v) { var el = form.querySelector('[name="' + name + '"]'); if (el && !el.value.trim() && v) el.value = v; };
          set('bill_name', c.customer_name || c.name); set('bill_phone', c.phone); set('bill_address', c.address); set('bill_pincode', c.pincode);
        }).catch(function () {});
    }, 350);
  });

  box.querySelectorAll('[data-line]').forEach(stockHint);
  calc();
})();
