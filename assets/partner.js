// Partner order form: each line = tap a design picture, pick the T-shirt, tap a colour, then + on the sizes.
// Also: paste customer details from WhatsApp, a returning customer's address from their mobile, live T-shirt count.
(function () {
  var form = document.getElementById('ptForm');
  if (!form) return;
  var CAT = JSON.parse(form.dataset.catalog || '[]'), DES = JSON.parse(form.dataset.designs || '[]');
  var LINES = JSON.parse(form.dataset.lines || '[]');
  var STOCK = JSON.parse(form.dataset.stock || '{"return":{},"blank":{}}');
  var hasReturn = Object.keys(STOCK['return'] || {}).length > 0, hasBlank = Object.keys(STOCK.blank || {}).length > 0;
  // How many are left of a T-shirt in their returns (same design) / own blanks, or null for a new T-shirt.
  function left(src, design, pr, color, size) {
    if (src === 'return') return (STOCK['return'] || {})[[design || 0, pr.gsm, pr.p.name, color, size].join('|')] || 0;
    if (src === 'blank') return (STOCK.blank || {})[[0, pr.gsm, pr.p.name, color, size].join('|')] || 0;
    return null;
  }
  var box = document.getElementById('ptLines');
  var products = [], byKey = {};
  CAT.forEach(function (g) { g.products.forEach(function (p) { var k = g.gsm + '|' + p.name; byKey[k] = { key: k, gsm: g.gsm, p: p }; products.push(byKey[k]); }); });
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var seq = 0;

  function lineHtml(n, ln) {
    var pr = byKey[ln.tshirt] || (products.length === 1 ? products[0] : null);
    var designs = DES.map(function (d) {
      return '<label class="pt-tile"><input type="radio" name="lines[' + n + '][design_id]" value="' + d.id + '"' + (String(ln.design_id) === String(d.id) ? ' checked' : '') + '>'
        + (d.thumb ? '<img src="' + esc(d.thumb) + '" alt="" loading="lazy">' : '<span class="pt-tile-ph">👕</span>') + '<span>' + esc(d.name) + '</span></label>';
    }).join('') + '<a class="pt-tile add" href="' + esc(form.dataset.newdesign) + '"><span class="pt-tile-ph">＋</span><span>New product</span></a>';
    var tsh = '<option value="">Choose…</option>' + products.map(function (x) {
      return '<option value="' + esc(x.key) + '"' + (pr && pr.key === x.key ? ' selected' : '') + '>' + esc(x.p.name + ' · ' + x.gsm) + '</option>'; }).join('');
    var src = ln.src || 'new';
    var srcs = (hasReturn || hasBlank || src !== 'new') ? '<div class="pt-seg pt-src" role="radiogroup" aria-label="Which T-shirt">'
      + [['new', 'New T-shirt'], ['return', 'From my returns'], ['blank', 'My own T-shirt']].filter(function (x) { return x[0] === 'new' || x[0] === src || (x[0] === 'return' ? hasReturn : hasBlank); })
        .map(function (x) { return '<label><input type="radio" name="lines[' + n + '][src]" value="' + x[0] + '"' + (src === x[0] ? ' checked' : '') + '><span>' + x[1] + '</span></label>'; }).join('') + '</div>' : '';
    return '<section class="pt-card pt-line" data-line="' + n + '">'
      + srcs
      + '<div class="pt-line-top"><b>Product</b><button type="button" class="pt-x" data-rm aria-label="Remove">Remove</button></div>'
      + '<div class="pt-tiles" role="radiogroup">' + designs + '</div>'
      + '<div class="pt-psum" hidden><i></i><span></span><button type="button" class="pt-btn plain pt-small" data-pedit>Edit</button></div>'
      + '<label class="pt-pick"><span>T-shirt</span><select name="lines[' + n + '][tshirt]" data-tshirt>' + tsh + '</select></label>'
      + '<div data-opts>' + optsHtml(n, pr, ln) + '</div></section>';
  }
  function optsHtml(n, pr, ln) {
    if (!pr) return '<p class="pt-hint">Choose the T-shirt to see its colours and sizes.</p>';
    var cols = pr.p.colors.map(function (c) {
      return '<label class="pt-sw" title="' + esc(c.name) + '"><input type="radio" name="lines[' + n + '][color]" value="' + esc(c.name) + '"' + (ln.color === c.name ? ' checked' : '') + '>'
        + '<i style="--c:' + esc(c.hex || '#ccc') + '"></i><span>' + esc(c.name) + '</span></label>'; }).join('');
    var q = ln.qty || {};
    var src = ln.src || 'new';
    var sizes = pr.p.sizes.map(function (s) {
      var v = parseInt(q[s], 10) || 0;
      var l = ln.color ? left(src, ln.design_id, pr, ln.color, s) : (src === 'new' ? null : 0);
      if (l !== null && !l && !v) return '';
      return '<div class="pt-step' + (v ? ' on' : '') + '"' + (l !== null ? ' data-max="' + l + '"' : '') + '><b>' + esc(s) + (l !== null ? '<small>' + l + ' left</small>' : '') + '</b><div><button type="button" data-d="-1" aria-label="One less ' + esc(s) + '">−</button>'
        + '<input name="lines[' + n + '][qty][' + esc(s) + ']" value="' + v + '" inputmode="numeric" aria-label="' + esc(s) + ' quantity">'
        + '<button type="button" data-d="1" aria-label="One more ' + esc(s) + '">+</button></div></div>'; }).join('');
    var none = src !== 'new' && !sizes.replace(/\s/g, '');
    return '<div class="pt-colpart"><div class="pt-sub2">Colour</div><div class="pt-sws" role="radiogroup">' + cols + '</div></div><div class="pt-sub2">Sizes</div>'
      + (src !== 'new' && !ln.color ? '<p class="pt-hint">Tap the colour to see what is left.</p>' : none ? '<p class="pt-hint">None of this left in ' + (src === 'return' ? 'your returns' : 'your own T-shirts') + '.</p>' : '<div class="pt-steps-grid">' + sizes + '</div>');
  }
  // A saved product brings its T-shirt and colour: the line shows them in one row, "Edit" opens the choices.
  function desOf(id) { return DES.filter(function (d) { return String(d.id) === String(id); })[0]; }
  function hexOf(pr, name) { var c = pr && pr.p.colors.filter(function (x) { return x.name === name; })[0]; return c && c.hex ? c.hex : '#ccc'; }
  function fix(line, on) {
    var sum = line.querySelector('.pt-psum'), pr = byKey[line.querySelector('[data-tshirt]').value], col = line.querySelector('.pt-sw input:checked');
    on = on && pr && col;
    line.classList.toggle('fixed', !!on); sum.hidden = !on;
    if (on) {
      sum.querySelector('span').innerHTML = esc(pr.p.name + ' · ' + pr.gsm) + '<small>' + esc(col.value) + '</small>';
      sum.querySelector('i').style.setProperty('--c', hexOf(pr, col.value));
    }
  }
  function useProduct(line, d) {
    var n = line.dataset.line, sel = line.querySelector('[data-tshirt]');
    if (!d || !d.tshirt || !byKey[d.tshirt]) { fix(line, false); return; }
    var keep = {}; [].forEach.call(line.querySelectorAll('.pt-step input'), function (i) { keep[i.name.replace(/.*\[(.+)\]$/, '$1')] = i.value; });
    var src = (line.querySelector('.pt-src input:checked') || { value: 'new' }).value;
    sel.value = d.tshirt;
    line.querySelector('[data-opts]').innerHTML = optsHtml(n, byKey[d.tshirt], { color: d.color, qty: keep, src: src, design_id: d.id });
    fix(line, true);
  }
  function add(ln) {
    var n = seq++;
    ln = ln || {};
    box.insertAdjacentHTML('beforeend', lineHtml(n, ln));
    var line = box.lastElementChild;
    // a single design is chosen for you
    if (DES.length === 1 && !ln.design_id) { var r = line.querySelector('.pt-tile input'); if (r) { r.checked = true; ln.design_id = r.value; } }
    var d = desOf(ln.design_id);
    if (d && d.tshirt && (!ln.tshirt || (ln.tshirt === d.tshirt && (!ln.color || ln.color === d.color)))) useProduct(line, d);
    tidy();
    return line;
  }
  function tidy() {
    var all = box.querySelectorAll('[data-line]');
    [].forEach.call(all, function (l) { l.querySelector('[data-rm]').hidden = all.length < 2; });
    var n = 0;
    [].forEach.call(box.querySelectorAll('.pt-step input'), function (i) { n += Math.max(0, parseInt(i.value, 10) || 0); });
    document.getElementById('ptCount').textContent = n + ' T-shirt' + (n === 1 ? '' : 's');
  }
  (LINES.length ? LINES : [{}]).forEach(function (ln) { add(ln); });
  document.getElementById('ptAddLine').addEventListener('click', function () {
    // same design and T-shirt as the last line: usually only the colour changes
    var last = box.lastElementChild, d = last && last.querySelector('.pt-tile input:checked'), t = last && last.querySelector('[data-tshirt]');
    var el = add({ design_id: d ? d.value : '', tshirt: t ? t.value : '' });
    if (last && last.classList.contains('fixed')) fix(el, false); // a second line is usually another colour: show the choices
    el.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
  });
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-d]');
    if (b) {
      var st = b.closest('.pt-step'), mx = st.dataset.max !== undefined ? +st.dataset.max : (bulkOn() ? 5000 : 500);
      var inp = b.parentNode.querySelector('input'), v = Math.max(0, Math.min(mx, (parseInt(inp.value, 10) || 0) + +b.dataset.d));
      inp.value = v; b.closest('.pt-step').classList.toggle('on', v > 0); tidy();
      if (navigator.vibrate) navigator.vibrate(8);
      return;
    }
    if (e.target.closest('[data-pedit]')) { fix(e.target.closest('[data-line]'), false); return; }
    if (e.target.closest('[data-rm]') && box.children.length > 1) { e.target.closest('[data-line]').remove(); tidy(); }
  });
  // T-shirt, colour, design or "which T-shirt" changed: redraw colours / sizes (with what is left), keeping the numbers.
  box.addEventListener('change', function (e) {
    if (!e.target.matches('[data-tshirt], .pt-sw input, .pt-tile input, .pt-src input')) return;
    var line = e.target.closest('[data-line]'), n = line.dataset.line;
    if (e.target.matches('.pt-tile input')) { var dd = desOf(e.target.value); if (dd && dd.tshirt && byKey[dd.tshirt]) { useProduct(line, dd); tidy(); return; } fix(line, false); }
    var src = (line.querySelector('.pt-src input:checked') || { value: 'new' }).value;
    if (e.target.matches('.pt-sw input') && src === 'new') return; // nothing depends on the colour
    var keep = {}; [].forEach.call(line.querySelectorAll('.pt-step input'), function (i) { keep[i.name.replace(/.*\[(.+)\]$/, '$1')] = i.value; });
    var col = line.querySelector('.pt-sw input:checked'), des = line.querySelector('.pt-tile input:checked');
    line.querySelector('[data-opts]').innerHTML = optsHtml(n, byKey[line.querySelector('[data-tshirt]').value], { color: col ? col.value : '', qty: keep, src: src, design_id: des ? des.value : 0 });
    tidy();
  });
  box.addEventListener('input', function (e) { if (e.target.matches('.pt-step input')) { e.target.closest('.pt-step').classList.toggle('on', (parseInt(e.target.value, 10) || 0) > 0); tidy(); } });
  box.addEventListener('focusin', function (e) { if (e.target.matches('.pt-step input')) e.target.select(); });

  // Deliver to: my customer / me (bulk order) — bulk fills the partner's own address, is prepaid, allows big quantities
  var ME = JSON.parse(form.dataset.me || '{}');
  function bulkOn() { var r = form.querySelector('[name=bulk]:checked'); return !!(r && r.value === '1'); }
  function dest(fill) {
    var on = bulkOn();
    [].forEach.call(form.querySelectorAll('.pt-cust-only'), function (x) { x.hidden = on; });
    [].forEach.call(form.querySelectorAll('.pt-bulk-only'), function (x) { x.hidden = !on; });
    if (on) {
      var pre = form.querySelector('[name=payment_type][value=prepaid]'); if (pre) pre.checked = true;
      form.querySelector('.pt-cod').hidden = true;
      if (fill) Object.keys(ME).forEach(function (k) { var f = form.elements[k]; if (f && ME[k]) { f.value = ME[k]; flash(f); } });
    } else if (fill) {
      Object.keys(ME).forEach(function (k) { var f = form.elements[k]; if (f && f.value === ME[k]) f.value = ''; });
    }
  }
  form.addEventListener('change', function (e) { if (e.target.name === 'bulk') dest(true); });
  dest(false);
  // Payment
  form.addEventListener('change', function (e) {
    if (e.target.name === 'payment_type') { var c = form.querySelector('.pt-cod'); c.hidden = e.target.value !== 'cod'; if (!c.hidden) c.querySelector('input').focus(); }
  });

  // Paste customer details (WhatsApp: "*Name*: …", "Address : …", "Land mark", "Pincode", "Mobile Number", "Email")
  function parse(t) {
    var out = {}, rest = [], cur = null;
    var map = [[/^(customer\s*)?name$/, 'ship_name'], [/^(full\s*)?address$/, 'ship_address'], [/^land\s*mark$/, 'landmark'], [/^(pin\s*code|pin|postal\s*code)$/, 'ship_pincode'],
      [/^(mobile|phone|contact)(\s*(no|number|num))?\.?$/, 'ship_phone'], [/^(alternate|alt|second|2nd)\s*(mobile|phone|number)/, 'ship_phone2'], [/^e-?mail$/, 'ship_email']];
    t.replace(/\r/g, '').split('\n').forEach(function (raw) {
      var line = raw.replace(/[*_]/g, '').trim(); if (!line) return;
      var m = line.match(/^([A-Za-z][A-Za-z .\-]{1,24}?)\s*[:\-–]\s*(.*)$/), key = null;
      if (m) { var lab = m[1].toLowerCase().trim(); map.some(function (x) { if (x[0].test(lab)) { key = x[1]; return true; } return false; }); }
      if (key) { cur = key; out[key] = (out[key] ? out[key] + '\n' : '') + m[2].trim(); }
      else if (cur === 'ship_address') out.ship_address += '\n' + line;
      else rest.push(line);
    });
    var all = t.replace(/[*_]/g, ' ');
    if (!out.ship_pincode) { var pm = all.match(/\b[1-9]\d{5}\b/); if (pm) out.ship_pincode = pm[0]; }
    if (!out.ship_phone) { var ph = all.replace(/[\s-]/g, '').match(/(?:\+?91)?([6-9]\d{9})/); if (ph) out.ship_phone = ph[1]; }
    if (!out.ship_name && rest.length) out.ship_name = rest[0];
    if (!out.ship_address && rest.length > 1) out.ship_address = rest.slice(1).join('\n');
    ['ship_phone', 'ship_phone2'].forEach(function (k) { if (out[k]) { var d = out[k].replace(/\D/g, ''); out[k] = d.length === 12 && d.indexOf('91') === 0 ? d.slice(2) : d; } });
    if (out.ship_pincode) out.ship_pincode = (out.ship_pincode.match(/\d{6}/) || [out.ship_pincode])[0];
    return out;
  }
  document.getElementById('ptPasteGo').addEventListener('click', function () {
    var got = parse(document.getElementById('ptPasteText').value), n = 0;
    Object.keys(got).forEach(function (k) { var f = form.elements[k]; if (f && got[k]) { f.value = got[k].trim(); n++; flash(f); } });
    if (got.ship_phone2 || got.ship_email) form.querySelectorAll('.pt-more')[0].open = true;
    var det = this.closest('details'); if (n) det.open = false;
    showKnown(n ? 'Filled ' + n + ' boxes — please check them.' : 'Couldn’t read that. Type the details below.');
  });
  function flash(el) { el.classList.remove('pt-filled'); void el.offsetWidth; el.classList.add('pt-filled'); }
  function showKnown(t) { var k = document.getElementById('ptKnown'); k.textContent = t; k.hidden = !t; }

  // Returning customer: their last address, from this partner's own orders
  var phone = form.elements.ship_phone, lastLooked = '';
  phone.addEventListener('input', function () {
    var d = phone.value.replace(/\D/g, ''); if (d.length === 12 && d.indexOf('91') === 0) d = d.slice(2);
    if (d.length !== 10 || d === lastLooked || form.elements.ship_address.value.trim()) return;
    lastLooked = d;
    fetch(form.dataset.lookup + '?phone=' + d, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (c) {
      if (!c || !c.ship_address || form.elements.ship_address.value.trim()) return;
      ['ship_name', 'ship_address', 'landmark', 'ship_pincode', 'ship_phone2', 'ship_email'].forEach(function (k) { if (c[k] && !form.elements[k].value.trim()) { form.elements[k].value = c[k]; flash(form.elements[k]); } });
      showKnown('↺ Returning customer — address from their last order. Check it is still right.');
    }).catch(function () {});
  });

  // Before sending: friendly checks where the problem is
  form.addEventListener('submit', function (e) {
    var bad = null;
    [].forEach.call(box.querySelectorAll('[data-line]'), function (l) {
      l.classList.remove('pt-bad');
      var sum = 0; [].forEach.call(l.querySelectorAll('.pt-step input'), function (i) { sum += parseInt(i.value, 10) || 0; });
      if (!l.querySelector('.pt-tile input:checked') || !l.querySelector('[data-tshirt]').value || !l.querySelector('.pt-sw input:checked') || !sum) { l.classList.add('pt-bad'); bad = bad || l; }
    });
    ['ship_phone', 'ship_name', 'ship_address', 'ship_pincode'].forEach(function (k) {
      var f = form.elements[k]; var miss = !f.value.trim() || (k === 'ship_pincode' && !/^[1-9]\d{5}$/.test(f.value.trim()));
      f.closest('label').classList.toggle('pt-bad', miss); if (miss) bad = bad || f.closest('label');
    });
    if (bad) {
      e.preventDefault();
      bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var msg = bad.matches('[data-line]') ? 'Pick the design, T-shirt, colour and at least one size.' : 'Fill in the marked box.';
      var n = document.getElementById('ptCheck'); if (!n) { n = document.createElement('p'); n.id = 'ptCheck'; n.className = 'pt-note err'; form.prepend(n); }
      n.textContent = msg;
      return;
    }
    document.getElementById('ptSend').disabled = true; document.getElementById('ptSend').textContent = 'Sending…';
  });
})();
