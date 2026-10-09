// Bill editor: items / printing / shipping suggested while typing, live GST totals, party details, IGST for other states.
(function () {
  'use strict';
  var form = document.getElementById('billForm');
  if (!form) return;
  var STOCK = [];
  try { STOCK = JSON.parse(form.dataset.stock || '[]'); } catch (e) {}
  var byName = {};
  STOCK.forEach(function (s) { byName[norm(s.name)] = s; });
  function norm(v) { return String(v || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
  var box = document.getElementById('billLines');
  var n = function (v) { v = parseFloat(v); return isFinite(v) ? v : 0; };
  var rs = function (v) { return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var f = function (row, k) { return row.querySelector('[data-f="' + k + '"]'); };
  var counter = 1000;

  function stockHint(row) {
    var id = f(row, 'stock_id').value, hint = row.querySelector('[data-stockhint]');
    var s = row._item || (id ? STOCK.find(function (x) { return String(x.id) === id; }) : null);
    var short = !!(s && s.track && n(f(row, 'qty').value) > s.qty);
    hint.textContent = !s ? '' : (s.track
      ? '📦 In stock now: ' + s.qty + ' ' + s.unit + (short ? ' — not enough!' : ' · goes down when saved')
      : '🖨 Printing — stock is not touched') + (row._tierText ? ' · ' + row._tierText : '');
    hint.classList.toggle('err-text', short);
  }

  // Quantity price chart: lines of the same product (or print size) are priced by their total quantity.
  function reprice() {
    var rows = Array.prototype.slice.call(box.querySelectorAll('[data-line]')), tot = {};
    rows.forEach(function (r) { if (r._item && r._item.pkey) tot[r._item.pkey] = (tot[r._item.pkey] || 0) + n(f(r, 'qty').value); });
    rows.forEach(function (r) {
      var it = r._item;
      r._tierText = '';
      if (!it || !it.pkey) return;
      var q = tot[it.pkey], p = it.base;
      (it.tiers || []).forEach(function (t) { if (q >= t[0]) p = t[1]; });
      if (r.dataset.manual === '1') { r._tierText = 'your price'; return; }
      f(r, 'rate').value = p;
      var next = (it.tiers || []).find(function (t) { return q < t[0]; });
      r._tierText = '₹' + p + ' each for ' + q + ' pcs' + (next ? ' (₹' + next[1] + ' from ' + next[0] + ')' : '');
    });
    rows.forEach(stockHint);
  }

  function useItem(row, s) {
    if (s.ship) {
      // Shipping is not a bill line: it goes to the shipping charge.
      form.shipping.value = Math.round((n(form.shipping.value) + s.rate * Math.max(1, n(f(row, 'qty').value))) * 100) / 100;
      f(row, 'description').value = '';
      f(row, 'stock_id').value = '';
      f(row, 'rate').value = 0;
      var hint = row.querySelector('[data-stockhint]');
      hint.textContent = '🚚 ' + s.name + ' added to “Shipping charge” below.';
      hint.classList.remove('err-text');
      calc();
      return;
    }
    row._item = s;
    row.dataset.manual = '0';
    f(row, 'description').value = s.name;
    f(row, 'stock_id').value = s.id || '';
    f(row, 'blank').value = s.blank || '';
    f(row, 'rate').value = s.rate || f(row, 'rate').value;
    if (s.unit) f(row, 'unit').value = s.unit;
    if (s.hsn) f(row, 'hsn').value = s.hsn;
    f(row, 'gst_rate').value = s.gst;
    stockHint(row);
    calc();
  }

  function pickStock(row) {
    var s = byName[norm(f(row, 'description').value)];
    if (s && !s.ship) {
      if (row._item !== s) useItem(row, s);
    } else {
      row._item = null;
      f(row, 'stock_id').value = '';
      f(row, 'blank').value = '';
    }
    stockHint(row);
  }

  // ---------------------------------------------------------------- suggestions while typing
  var sug = null, sugInput = null, sugActive = -1;
  function hideSug() { if (sug) { sug.remove(); sug = null; sugInput = null; sugActive = -1; } }
  function showSug(input, rows, render, onPick) {
    hideSug();
    if (!rows.length || document.activeElement !== input) return;
    sug = document.createElement('div');
    sug.className = 'suggest';
    sug.setAttribute('role', 'listbox');
    sugInput = input;
    rows.forEach(function (r, i) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'suggest-row'; b.setAttribute('role', 'option');
      b.innerHTML = render(r);
      b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
      b.addEventListener('click', function () { hideSug(); onPick(r); });
      sug.appendChild(b);
    });
    input.closest('.field').appendChild(sug);
  }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  document.addEventListener('keydown', function (e) {
    if (!sug || e.target !== sugInput) return;
    var rows = sug.querySelectorAll('.suggest-row');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      sugActive = (sugActive + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
      rows.forEach(function (r, i) { r.classList.toggle('on', i === sugActive); });
      rows[sugActive].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && sugActive >= 0) {
      e.preventDefault();
      rows[sugActive].click();
    } else if (e.key === 'Escape') {
      hideSug();
    }
  });
  form.addEventListener('focusout', function () { setTimeout(function () { if (sug && document.activeElement !== sugInput) hideSug(); }, 150); });

  /** Every typed word must start a word in the name: "190 black m" finds "OVERSIZED FIT 190 BLACK -M". Whole-word hits come first. */
  function findItems(text) {
    var words = norm(text).replace(/[-–·]/g, ' ').split(' ').filter(Boolean);
    if (!words.length) return [];
    var out = [];
    STOCK.forEach(function (s) {
      var name = ' ' + norm(s.name).replace(/[-–·()]/g, ' ').replace(/\s+/g, ' ') + ' ', score = 0;
      var ok = words.every(function (w) {
        if (name.indexOf(' ' + w + ' ') !== -1) { score += 2; return true; }
        if (name.indexOf(' ' + w) !== -1) { score += 1; return true; }
        return w.length > 2 && name.indexOf(w) !== -1;
      });
      if (ok) out.push({ s: s, score: score + (s.track ? 0.5 : 0) });
    });
    out.sort(function (a, b) { return b.score - a.score; });
    return out.slice(0, 12).map(function (o) { return o.s; });
  }
  function itemSuggest(input) {
    var row = input.closest('[data-line]');
    showSug(input, findItems(input.value), function (s) {
      var tag = s.ship ? '🚚 Shipping charge — goes to shipping, not an item'
        : s.track ? '📦 ' + s.qty + ' ' + s.unit + ' in stock' : '🖨 Printing · no stock';
      return '<b>' + esc(s.name) + '</b><span' + (s.track && s.qty <= 0 ? ' class="err-text"' : '') + '>' + esc(tag) + (s.rate ? ' · ₹' + esc(s.rate) : '') + (s.tiers && s.tiers.length ? ' · ' + s.tiers.map(function (t) { return '₹' + t[1] + ' from ' + t[0]; }).join(', ') : '') + '</span>';
    }, function (s) { useItem(row, s); });
  }

  function calc() {
    reprice();
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
    if (e.target.dataset.f === 'description') { pickStock(row); itemSuggest(e.target); }
    if (e.target.dataset.f === 'rate') row.dataset.manual = '1'; // typed price: keep it
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

  // Party (customer): suggestions while typing in Customer ID or Name; a picked party fills the bill-to details.
  function fillParty(c, force) {
    var set = function (name, v) { var el = form.querySelector('[name="' + name + '"]'); if (el && v && (force || !el.value.trim())) el.value = v; };
    set('customer_code', c.code);
    set('bill_name', c.customer_name || c.name); set('bill_phone', c.phone); set('bill_address', c.address); set('bill_pincode', c.pincode); set('bill_gstin', c.gstin);
    var note = document.getElementById('partyNote');
    if (!note) {
      note = document.createElement('p'); note.className = 'hint'; note.id = 'partyNote';
      form.querySelector('[data-bill-customer]').closest('.field').appendChild(note);
    }
    note.textContent = '✓ Saved party' + (c.balance ? ' · old balance ₹' + c.balance.toLocaleString('en-IN') : '');
  }
  var timer = null;
  form.querySelectorAll('[data-party-suggest]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      clearTimeout(timer);
      var q = inp.value.trim();
      if (q.length < 1) { hideSug(); return; }
      timer = setTimeout(function () {
        fetch((window.BASE || '') + 'customer_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (rows) {
            if (inp.value.trim() !== q) return;
            showSug(inp, rows, function (c) {
              return '<b>' + esc(c.code) + '</b><span>' + esc([c.customer_name, c.name, c.phone, c.gstin].filter(Boolean).join(' · ') || 'Saved party') + '</span>';
            }, function (c) { fillParty(c, true); });
          }).catch(function () {});
      }, 200);
    });
  });
  // Customer ID typed in full (not picked): fill what is still empty.
  var cust = form.querySelector('[data-bill-customer]'), ctimer = null;
  cust.addEventListener('change', function () {
    clearTimeout(ctimer);
    var code = cust.value.trim();
    if (!code) return;
    fetch((window.BASE || '') + 'customer_search.php?code=' + encodeURIComponent(code), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res.found && cust.value.trim() === code) fillParty(res.customer, false); })
      .catch(function () {});
  });

  box.querySelectorAll('[data-line]').forEach(stockHint);
  calc();
})();
