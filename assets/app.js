// Looma Orders — progressive enhancements on top of plain forms (everything still posts as normal HTML).
(function () {
  'use strict';

  var OTHER = '__other';
  var BASE = window.BASE || '';

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    for (var k in attrs || {}) e.setAttribute(k, attrs[k]);
    if (text != null) e.textContent = text;
    return e;
  }
  function $all(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function fire(node, type) { node.dispatchEvent(new Event(type, { bubbles: true })); }

  function addOtherOption(select) {
    if (select.querySelector('option[value="' + OTHER + '"]')) return;
    select.appendChild(el('option', { value: OTHER }, 'Other… (type)'));
  }
  function addOption(select, v) {
    if (Array.prototype.some.call(select.options, function (o) { return o.value === v; })) return;
    select.insertBefore(el('option', { value: v }, v), select.querySelector('option[value="' + OTHER + '"]'));
  }

  // "Other…" on any dropdown lets staff type a value that isn't in the list.
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (s.tagName !== 'SELECT' || s.value !== OTHER) return;
    var v = (window.prompt('Type the value') || '').trim();
    if (!v) { s.value = s.dataset.prev || ''; fire(s, 'change'); return; }
    addOption(s, v);
    s.value = v;
    fire(s, 'change');
  });
  document.addEventListener('focusin', function (e) {
    if (e.target.tagName === 'SELECT') e.target.dataset.prev = e.target.value;
  });

  // ---------------------------------------------------------------- chip pickers
  // A catalog <select> stays in the form (it is what gets posted); chips are a tap-friendly face for it.
  function renderChips(select, values, emptyText) {
    var box = select.nextElementSibling && select.nextElementSibling.classList.contains('chips') ? select.nextElementSibling : null;
    if (!box) {
      box = el('div', { class: 'chips', role: 'listbox' });
      select.after(box);
      select.classList.add('has-chips');
    }
    box.innerHTML = '';
    var kind = select.dataset.cat;
    var hex = {};
    values.forEach(function (v) { if (typeof v !== 'string') hex[v.name] = v.hex; });
    var opts = Array.prototype.filter.call(select.options, function (o) { return o.value && o.value !== OTHER; });
    if (!opts.length && emptyText) box.appendChild(el('span', { class: 'chips-empty' }, emptyText));
    opts.forEach(function (o) {
      var b = el('button', { type: 'button', class: 'chip' + (kind === 'size' ? ' size' : '') + (o.value === select.value ? ' on' : ''), 'data-v': o.value, role: 'option' });
      if (kind === 'color') {
        var d = el('span', { class: 'dot' });
        d.style.background = hex[o.value] || (window.COLOR_HEX || {})[o.value] || '#ccc';
        b.appendChild(d);
      }
      b.appendChild(document.createTextNode(o.value));
      box.appendChild(b);
    });
    if (opts.length || !emptyText) box.appendChild(el('button', { type: 'button', class: 'chip other', 'data-v': OTHER }, '+ Other'));
  }
  document.addEventListener('click', function (e) {
    var chip = e.target.closest('.chips .chip');
    if (!chip) return;
    var select = chip.parentNode.previousElementSibling;
    var v = chip.dataset.v;
    if (v === OTHER) {
      var t = (window.prompt('Type the value') || '').trim();
      if (!t) return;
      addOption(select, t);
      v = t;
    }
    select.value = v;
    fire(select, 'change');
  });
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (s.tagName === 'SELECT' && s.classList.contains('has-chips') && s.nextElementSibling) {
      $all(s.nextElementSibling, '.chip').forEach(function (c) { c.classList.toggle('on', c.dataset.v === s.value); });
    }
  });

  // ---------------------------------------------------------------- catalog: GSM -> product -> color / size
  var form = document.getElementById('orderForm');
  var catalog = form && form.dataset.catalog ? JSON.parse(form.dataset.catalog) : [];

  function fill(select, values, current) {
    if (!select) return;
    select.innerHTML = '';
    select.appendChild(el('option', { value: '' }, 'Select…'));
    var found = !current;
    values.forEach(function (v) {
      var val = typeof v === 'string' ? v : v.name;
      var o = el('option', { value: val }, val);
      if (val === current) { o.selected = true; found = true; }
      select.appendChild(o);
    });
    if (!found) {
      var o = el('option', { value: current }, current);
      o.selected = true;
      select.appendChild(o);
    }
    addOtherOption(select);
  }

  function initCard(card) {
    var fixed = JSON.parse(card.dataset.values || '{}');
    var sel = {};
    ['gsm', 'product', 'color', 'size'].forEach(function (k) { sel[k] = card.querySelector('select[data-cat="' + k + '"]'); });
    function value(k) { return sel[k] ? sel[k].value : (fixed[k] || ''); }
    function gsmEntry() { return catalog.find(function (g) { return g.gsm === value('gsm'); }); }
    function productEntry() {
      var g = gsmEntry();
      return g && g.products.find(function (p) { return p.name === value('product'); });
    }
    function refresh(level) {
      if (level <= 0 && sel.gsm) {
        var gs = catalog.map(function (g) { return g.gsm; });
        fill(sel.gsm, gs, sel.gsm.dataset.value);
        renderChips(sel.gsm, gs);
      }
      var g = gsmEntry();
      if (level <= 1 && sel.product) {
        var ps = g ? g.products : [];
        fill(sel.product, ps, level === 0 ? sel.product.dataset.value : value('product'));
        renderChips(sel.product, ps, 'Choose GSM first');
      }
      var p = productEntry();
      if (level <= 2) {
        if (sel.color) {
          var cs = p ? p.colors : [];
          fill(sel.color, cs, level === 0 ? sel.color.dataset.value : value('color'));
          renderChips(sel.color, cs, 'Choose product first');
        }
        if (sel.size) {
          var ss = p && p.sizes.length ? p.sizes : ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
          fill(sel.size, ss, level === 0 ? sel.size.dataset.value : value('size'));
          renderChips(sel.size, ss);
        }
      }
    }
    if (sel.gsm) sel.gsm.addEventListener('change', function () { if (sel.product) sel.product.value = ''; refresh(1); });
    if (sel.product) sel.product.addEventListener('change', function () { refresh(2); });
    refresh(0);
    $all(card, 'select[data-other]').forEach(addOtherOption);
    // Used by "Copy item" and the design picker to set the blank in one go.
    card._setBlank = function (v) {
      ['gsm', 'product', 'color', 'size'].forEach(function (k) {
        if (sel[k]) sel[k].dataset.value = v[k] !== undefined ? v[k] : sel[k].value;
      });
      refresh(0);
    };
    card._blank = function () {
      var out = {};
      ['gsm', 'product', 'color', 'size'].forEach(function (k) { out[k] = value(k); });
      return out;
    };
    // Stock left for the chosen blank, under the size.
    if (card.classList.contains('item-card') && sel.size) {
      var stockTimer = null;
      var check = function () {
        clearTimeout(stockTimer);
        stockTimer = setTimeout(function () {
          var hint = card.querySelector('.stock-hint');
          if (!hint) {
            hint = el('p', { class: 'hint stock-hint' });
            var at = sel.size.closest('.field') || sel.size.parentNode;
            at.appendChild(hint);
          }
          var b = card._blank();
          if (!b.size && !b.product) { hint.textContent = ''; return; }
          fetch(BASE + 'stock_lookup.php?' + ['gsm', 'product', 'color', 'size'].map(function (k) { return k + '=' + encodeURIComponent(b[k]); }).join('&'), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
              var need = parseInt((card.querySelector('[name$="[quantity]"]') || {}).value || '1', 10) || 1;
              hint.classList.toggle('err-text', !!(res.found && res.qty < need) || !res.found);
              var tiers = (res.tiers || []).map(function (t) { return '₹' + t[1] + ' from ' + t[0]; }).join(', ');
              hint.textContent = (res.found ? '📦 ' + res.qty + ' ' + res.unit + ' in stock' + (res.qty < need ? ' — not enough for ' + need : '') : '📦 None in stock yet')
                + (res.price ? ' · ₹' + res.price + '/pc' + (tiers ? ' (' + tiers + ')' : '') + ' + ' + res.gst + '% GST' : '');
              card._res = res;
              schedulePreview();
              var rate = card.querySelector('[data-rate]');
              if (rate) {
                var pq = res.price || 0;
                (res.tiers || []).forEach(function (t) { if (need >= t[0]) pq = t[1]; });
                var nextT = (res.tiers || []).find(function (t) { return need < t[0]; });
                rate.placeholder = pq ? '₹' + pq : 'Auto';
                var rn = card.querySelector('[data-rate-note]');
                if (rn) rn.textContent = pq ? 'Catalog ₹' + pq + ' for ' + need + ' pc' + (need === 1 ? '' : 's') + (nextT ? ' · ₹' + nextT[1] + ' from ' + nextT[0] : '') + ' — type to change' : 'No catalog price — type the price';
              }
            }).catch(function () {});
        }, 200);
      };
      ['gsm', 'product', 'color', 'size'].forEach(function (k) { if (sel[k]) sel[k].addEventListener('change', check); });
      card.addEventListener('input', function (e) { if (e.target.matches('[name$="[quantity]"]')) check(); });
      check();
    }
  }

  if (form) $all(form, '.item-card, .design-card').forEach(initCard);
  if (form) $all(form, '.item-card').forEach(function (c) { syncCardState(c); });
  if (form) $all(form, 'select[data-other]').forEach(addOtherOption);

  // ---------------------------------------------------------------- items: add / copy / remove / totals
  var itemsBox = document.getElementById('items');
  var tpl = document.getElementById('itemTemplate');
  var newCounter = 1000;

  function renumber() {
    if (!itemsBox) return;
    var cards = $all(itemsBox, '.item-card');
    var total = 0;
    var live = 0;
    cards.forEach(function (c, i) {
      c.querySelector('.item-num').textContent = i + 1;
      var del = c.querySelector('input[name$="[delete]"]');
      if (del && del.checked) return;
      live++;
      var q = c.querySelector('input[data-qty]');
      if (q) total += parseInt(q.value, 10) || 0;
    });
    var t = document.getElementById('totalPcs');
    if (t) t.textContent = live + ' item' + (live === 1 ? '' : 's') + (total ? ' · ' + total + (total === 1 ? ' pc' : ' pcs') : '');
  }

  function newCard() {
    var wrap = document.createElement('div');
    wrap.innerHTML = tpl.innerHTML.split('__KEY__').join('n' + (newCounter++));
    return wrap.firstElementChild;
  }

  function fieldBySuffix(card, suffix) {
    return card.querySelector('[name$="' + suffix + '"]:not([type=hidden]), [name$="' + suffix + '"][data-design-id]');
  }

  if (itemsBox) {
    renumber();
    var addBtn = document.getElementById('addItem');
    if (addBtn) addBtn.addEventListener('click', function () {
      var card = newCard();
      itemsBox.appendChild(card);
      initCard(card);
      $all(card, '.print-place').forEach(printHint);
      renumber();
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    itemsBox.addEventListener('click', function (e) {
      var dup = e.target.closest('[data-dup-item]');
      var rem = e.target.closest('[data-remove-item]');
      if (dup) {
        var src = dup.closest('.item-card');
        var card = newCard();
        src.after(card);
        initCard(card);
        card._setBlank(src._blank());
        $all(src, 'input, textarea, select:not([data-cat])').forEach(function (inp) {
          if (!inp.name || inp.type === 'file' || /\[(keep|delete)\]$/.test(inp.name) || inp.name === 'delete_images[]') return;
          var suffix = inp.name.replace(/^items\[[^\]]+\]/, '');
          if (inp.type === 'radio' || inp.type === 'checkbox') {
            var t = card.querySelector('[name$="' + suffix + '"][value="' + inp.value + '"]:not([type=hidden])');
            if (t) t.checked = inp.checked;
            return;
          }
          var target = inp.type === 'hidden' ? card.querySelector('[name$="' + suffix + '"][type=hidden]') : fieldBySuffix(card, suffix);
          if (!target) return;
          if (target.tagName === 'SELECT') addOption(target, inp.value);
          target.value = inp.value;
        });
        var chosen = src.querySelector('.design-chosen');
        var chosen2 = card.querySelector('.design-chosen');
        if (chosen && chosen2 && !chosen.hidden) { chosen2.innerHTML = chosen.innerHTML; chosen2.hidden = false; }
        syncCardState(card);
        syncPlaces(card);
        renumber();
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      if (rem) {
        if ($all(itemsBox, '.item-card').length <= 1) { window.alert('An order needs at least one item.'); return; }
        rem.closest('.item-card').remove();
        renumber();
      }
    });
    itemsBox.addEventListener('change', function (e) {
      if (e.target.matches('input[name$="[delete]"]')) {
        e.target.closest('.item-card').classList.toggle('removing', e.target.checked);
        renumber();
      }
    });
    itemsBox.addEventListener('input', function (e) { if (e.target.matches('input[data-qty]')) renumber(); });
  }

  // Item type (T-shirt + print / plain / print only / DTF roll), neck label: show only what applies.
  function syncCardState(card) {
    var TYPES = ['print', 'plain', 'print_only', 'dtf_roll'];
    var t = card.querySelector('[data-type-toggle]:checked');
    if (t) {
      TYPES.forEach(function (x) { card.classList.toggle('type-' + x, x === t.value); });
      var len = card.querySelector('[data-roll-len]');
      if (len) len.required = t.value === 'dtf_roll';
    }
    $all(card, '[data-neck]').forEach(function (row) {
      var t = row.querySelector('[data-neck-toggle]'), txt = row.querySelector('.neck-text');
      if (t && txt) txt.hidden = !t.checked;
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-type-toggle]')) syncCardState(e.target.closest('.item-card'));
    if (e.target.matches('[data-neck-toggle]')) {
      var row = e.target.closest('[data-neck]');
      var txt = row.querySelector('.neck-text');
      txt.hidden = !e.target.checked;
      var inp = txt.tagName === 'INPUT' ? txt : txt.querySelector('input');
      // Switched on: the brand name starts as the order's customer name (still editable).
      if (e.target.checked && inp && (inp.value.trim() === '' || inp.dataset.auto === '1')) {
        var cn = form && form.querySelector('[name="customer_name"]');
        if (cn && cn.value.trim()) { inp.value = cn.value.trim(); inp.dataset.auto = '1'; }
      }
      if (e.target.checked && inp) inp.focus();
    }
  });

  // Quantity − / + buttons.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]');
    if (!b) return;
    var inp = b.parentNode.querySelector('input');
    inp.value = Math.max(1, (parseInt(inp.value, 10) || 0) + parseInt(b.dataset.step, 10));
    fire(inp, 'input');
  });

  // Image previews before upload.
  document.addEventListener('change', function (e) {
    if (!e.target.matches('input[data-preview]')) return;
    var holder = e.target.closest('.upload-box') || e.target;
    var preview = holder.nextElementSibling;
    if (!preview || !preview.classList.contains('preview')) return;
    preview.innerHTML = '';
    Array.prototype.forEach.call(e.target.files, function (f) {
      var img = el('img', { alt: f.name });
      img.src = URL.createObjectURL(f);
      preview.appendChild(img);
    });
  });

  // Avoid double submits on slow mobile connections.
  if (form) form.addEventListener('submit', function () {
    setTimeout(function () { $all(form, 'button').forEach(function (b) { b.disabled = true; }); }, 0);
  });

  // ---------------------------------------------------------------- saved design picker
  var modal = document.getElementById('designModal');
  if (modal && form) {
    var designs = JSON.parse(form.dataset.designs || '[]');
    var list = document.getElementById('designList');
    var search = document.getElementById('designSearch');
    var targetCard = null;

    function thumbUrl(f) { return BASE + 'image.php?f=' + encodeURIComponent(f); }
    function renderList() {
      var q = search.value.trim().toLowerCase();
      list.innerHTML = '';
      designs.filter(function (d) {
        return !q || (d.name + ' ' + d.code + ' ' + d.product + ' ' + d.gsm).toLowerCase().indexOf(q) !== -1;
      }).forEach(function (d) {
        var b = el('button', { type: 'button', class: 'design-tile', 'data-design': d.id });
        if (d.thumbs[0]) { var im = el('img', { alt: '', loading: 'lazy' }); im.src = thumbUrl(d.thumbs[0]); b.appendChild(im); }
        else b.appendChild(el('div', { class: 'design-noimg' }, '👕'));
        var info = el('div', { class: 'design-info' });
        info.appendChild(el('b', {}, d.name));
        info.appendChild(el('small', {}, [d.code, d.gsm, d.product, d.color].filter(Boolean).join(' · ') || 'Any T-shirt'));
        b.appendChild(info);
        list.appendChild(b);
      });
      if (!list.children.length) list.appendChild(el('p', { class: 'muted' }, 'No designs match.'));
    }
    function openModal(card) { targetCard = card; search.value = ''; renderList(); modal.hidden = false; setTimeout(function () { search.focus(); }, 50); }
    function closeModal() { modal.hidden = true; }

    function applyDesign(card, d) {
      // A saved design is a T-shirt + print, unless the item is already "print only".
      var cur = card.querySelector('[data-type-toggle]:checked');
      if (!cur || (cur.value !== 'print' && cur.value !== 'print_only')) {
        var tp = card.querySelector('[data-type-toggle][value="print"]');
        if (tp) tp.checked = true;
      }
      var blank = card._blank();
      card._setBlank({ gsm: d.gsm || blank.gsm, product: d.gsm ? d.product : (d.product || blank.product), color: d.color || blank.color, size: d.size || blank.size });
      [['front_print', 'front_size'], ['back_print', 'back_size'], ['chest_print', 'chest_size'], ['custom_print', 'custom_size']].forEach(function (pair) {
        var f = fieldBySuffix(card, '[' + pair[0] + ']');
        if (f) f.value = d[pair[0]] || '';
        var sz = card.querySelector('select[name$="[' + pair[1] + ']"]');
        if (sz) { if (d[pair[1]]) addOption(sz, d[pair[1]]); sz.value = d[pair[1]] || ''; }
      });
      syncPlaces(card);
      Object.keys(d.extra || {}).forEach(function (k) {
        var f = fieldBySuffix(card, '[' + k + ']');
        if (!f) return;
        if (f.type === 'checkbox') { f.checked = !!d.extra[k]; return; }
        if (f.tagName === 'SELECT' && d.extra[k]) addOption(f, d.extra[k]);
        f.value = d.extra[k] || '';
      });
      var neckRow = card.querySelector('[data-neck="neck"]');
      var neck = neckRow && neckRow.querySelector('[data-neck-toggle]');
      if (neck) {
        neck.checked = !!d.neck_label_on;
        neckRow.querySelector('.neck-text').hidden = !neck.checked;
        var nt = neckRow.querySelector('.neck-text input');
        if (nt) nt.value = d.neck_label || '';
      }
      card.querySelector('[data-design-id]').value = d.id;
      var chosen = card.querySelector('.design-chosen');
      chosen.innerHTML = '';
      var head = el('div', { class: 'design-chosen-head' });
      head.appendChild(el('span', {}, '⭐ ' + d.name));
      head.appendChild(el('button', { type: 'button', class: 'linkbtn', 'data-unpick-design': '' }, 'Remove'));
      chosen.appendChild(head);
      var strip = el('div', { class: 'preview' });
      d.thumbs.forEach(function (t) { var im = el('img', { alt: '' }); im.src = thumbUrl(t); strip.appendChild(im); });
      chosen.appendChild(strip);
      if (d.thumbs.length) chosen.appendChild(el('small', { class: 'muted' }, d.thumbs.length + ' mock-up' + (d.thumbs.length === 1 ? '' : 's') + ' will be attached when you save.'));
      chosen.hidden = false;
      card.querySelector('[data-pick-design] span').textContent = 'Change design';
      var det = card.querySelector('.print-details');
      if (det && ['front_print', 'back_print', 'chest_print', 'custom_print', 'front_size', 'back_size', 'chest_size', 'custom_size'].some(function (k) { return d[k]; })) det.open = true;
      syncCardState(card);
    }

    document.addEventListener('click', function (e) {
      var pick = e.target.closest('[data-pick-design]');
      if (pick) { openModal(pick.closest('.item-card')); return; }
      var un = e.target.closest('[data-unpick-design]');
      if (un) {
        var card = un.closest('.item-card');
        card.querySelector('[data-design-id]').value = '';
        var ch = card.querySelector('.design-chosen');
        ch.hidden = true; ch.innerHTML = '';
        card.querySelector('[data-pick-design] span').textContent = 'Pick a saved design';
        return;
      }
      var tile = e.target.closest('#designList .design-tile');
      if (tile) {
        var d = designs.find(function (x) { return String(x.id) === tile.dataset.design; });
        if (d && targetCard) applyDesign(targetCard, d);
        closeModal();
        return;
      }
      if (e.target === modal || e.target.closest('[data-close-modal]')) closeModal();
    });
    search.addEventListener('input', renderList);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  }

  // ---------------------------------------------------------------- customer suggestions
  var suggestTimer = null;
  var suggestBox = null;
  function hideSuggest() { if (suggestBox) { suggestBox.remove(); suggestBox = null; } }
  function setField(name, v) {
    var f = form && form.querySelector('[name="' + name + '"]');
    if (f && v !== undefined && v !== '') f.value = v;
  }
  document.addEventListener('input', function (e) {
    var inp = e.target;
    if (!inp.matches || !inp.matches('[data-customer-suggest]')) return;
    clearTimeout(suggestTimer);
    var q = inp.value.trim();
    if (q.length < 2) { hideSuggest(); return; }
    suggestTimer = setTimeout(function () {
      fetch(BASE + 'customer_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          hideSuggest();
          if (!rows.length || document.activeElement !== inp) return;
          suggestBox = el('div', { class: 'suggest', role: 'listbox' });
          rows.forEach(function (c) {
            var b = el('button', { type: 'button', class: 'suggest-row', role: 'option' });
            b.appendChild(el('b', {}, c.code));
            b.appendChild(el('span', {}, [c.customer_name, c.name, c.phone, c.pincode].filter(Boolean).join(' · ')));
            b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            b.addEventListener('click', function () {
              setField('customer_id', c.code);
              fillCustomerName(c.customer_name);
              setField('ship_name', c.name);
              setField('ship_phone', c.phone);
              setField('ship_address', c.address);
              setField('ship_pincode', c.pincode);
              hideSuggest();
              var note = document.getElementById('customerNote');
              if (!note) {
                note = el('p', { class: 'hint', id: 'customerNote' });
                var cid = form.querySelector('[name="customer_id"]');
                (cid ? cid.closest('.field') : inp.closest('.field')).appendChild(note);
              }
              note.textContent = '✓ Saved customer — details filled in. You can still change them.';
              lookupCustomer();
            });
            suggestBox.appendChild(b);
          });
          inp.closest('.field').appendChild(suggestBox);
        })
        .catch(function () {});
    }, 220);
  });
  document.addEventListener('focusout', function (e) {
    if (e.target.matches && e.target.matches('[data-customer-suggest]')) setTimeout(hideSuggest, 150);
  });

  // Customer name: filled in from the customer book; a name typed by hand is never overwritten.
  function fillCustomerName(v) {
    var n = form && form.querySelector('[name="customer_name"]');
    if (!n) return;
    if (n.value.trim() === '' || n.dataset.auto === '1') {
      n.value = v || '';
      n.dataset.auto = v ? '1' : '';
      syncNeckNames();
    }
  }
  // Neck labels that are on and still hold the customer name follow it when it changes.
  function syncNeckNames() {
    var n = form && form.querySelector('[name="customer_name"]');
    if (!n) return;
    $all(form, '[data-neck]').forEach(function (row) {
      var t = row.querySelector('[data-neck-toggle]');
      var inp = row.querySelector('.neck-text input');
      if (t && t.checked && inp && (inp.value.trim() === '' || inp.dataset.auto === '1')) {
        inp.value = n.value.trim();
        inp.dataset.auto = inp.value ? '1' : '';
      }
    });
  }
  document.addEventListener('input', function (e) {
    if (e.target.name === 'customer_name') { e.target.dataset.auto = ''; syncNeckNames(); }
    if (e.target.matches && e.target.matches('.neck-text input')) e.target.dataset.auto = '';
  });
  // Exact customer ID typed: fill the saved name and show the customer's order number for the day (C101-2).
  var lookupTimer = null;
  function lookupCustomer() {
    var cid = form && form.querySelector('[data-customer-lookup]');
    var box = document.getElementById('custOrderNo');
    if (!cid) return;
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(function () {
      var code = cid.value.trim();
      var note = document.getElementById('custNameNote');
      if (!code) { if (box) box.innerHTML = '<span class="muted">Fills in from the customer ID</span>'; return; }
      fetch(BASE + 'customer_search.php?code=' + encodeURIComponent(code) + '&order=' + (box ? box.dataset.order : 0), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (cid.value.trim() !== code) return;
          if (box) box.textContent = res.order_no || '';
          var n = form.querySelector('[name="customer_name"]');
          if (!n) return;
          if (res.found) {
            fillCustomerName(res.customer.customer_name);
            ['name', 'phone', 'address', 'pincode'].forEach(function (k) {
              var f = form.querySelector('[name="ship_' + k + '"]');
              if (f && !f.value.trim() && res.customer[k]) f.value = res.customer[k];
            });
          } else if (n.dataset.auto === '1') {
            n.value = ''; n.dataset.auto = '';
          }
          if (!note) {
            note = el('small', { class: 'hint', id: 'custNameNote' });
            n.closest('.field').appendChild(note);
          }
          note.textContent = res.found
            ? (n.value.trim() ? '' : 'Saved customer without a name — type it once and it is remembered.')
            : 'New customer — type the name; it is saved for next time.';
        })
        .catch(function () {});
    }, 350);
  }
  document.addEventListener('input', function (e) {
    if (e.target.matches && e.target.matches('[data-customer-lookup]')) lookupCustomer();
  });
  if (form && form.querySelector('[data-customer-lookup]') && form.querySelector('[data-customer-lookup]').value.trim()) lookupCustomer();

  // ---------------------------------------------------------------- one-tap ticks
  function post(data) {
    var body = new FormData();
    body.append('csrf', window.CSRF);
    for (var k in data) body.append(k, data[k]);
    return fetch(BASE + 'order_action.php', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { throw new Error('server'); }); })
      .then(function (res) {
        if (res.blocked) { var be = new Error(res.error); be.blocked = true; throw be; }
        if (!res.ok) throw new Error(res.error || 'Failed');
        return res;
      });
  }
  function fail(e) {
    if (e && e.blocked) { window.alert(e.message); return; }
    var msg = e && e.message === 'server' ? 'Could not update — the server had a problem. Please refresh the page and try again.'
      : e && e.message && e.message !== 'Failed' && !/fetch|network/i.test(e.message) ? 'Could not update: ' + e.message
      : 'Could not update. Check your internet and try again.';
    window.alert(msg);
  }

  function updateSummary(res) {
    var badge = document.getElementById('statusBadge');
    if (badge) { badge.className = 'badge ' + res.status.key; badge.textContent = res.status.label; }
    var sum = document.getElementById('printSummary');
    if (sum) {
      sum.classList.toggle('done', res.order_printed);
      sum.querySelector('.box').textContent = res.order_printed ? '✓' : '';
      var c = sum.querySelector('[data-printed-count]');
      if (c) c.textContent = res.printed_count;
    }
  }

  document.addEventListener('click', function (e) {
    var all = e.target.closest('[data-print-all]');
    if (all) {
      var on = all.dataset.on === '1';
      if (!window.confirm(on ? 'Mark ALL items in this order as printed?' : 'Remove the printed tick from all items?')) return;
      post({ id: all.dataset.id, stage: 'printed', on: on ? '1' : '' }).then(function () { location.reload(); }).catch(fail);
      return;
    }
    var btn = e.target.closest('button.tick');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    var holder = btn.closest('[data-id]');
    var turnOn = !btn.classList.contains('done');
    var stage = btn.dataset.stage;
    var label = ((btn.querySelector('b') || btn).textContent || stage).replace(/[✓–]/g, '').trim();
    if (!turnOn && !window.confirm('Remove the "' + label + '" tick?')) return;
    btn.classList.add('busy');
    var data = { id: holder.dataset.id, stage: stage, on: turnOn ? '1' : '' };
    if (btn.dataset.item) data.item = btn.dataset.item;
    post(data)
      .then(function (res) {
        // The same order can show the tick more than once (print list: one card per item).
        var same = btn.dataset.item ? [btn] : Array.prototype.slice.call(document.querySelectorAll('[data-id="' + holder.dataset.id + '"] button.tick[data-stage="' + stage + '"]'));
        same.forEach(function (b) {
          b.classList.toggle('done', res.on);
          b.querySelector('.box').textContent = res.on ? '✓' : '';
          b.title = res.by;
        });
        var by = btn.querySelector('.by');
        if (by) by.textContent = res.on ? res.by : 'Tap to mark';
        btn.title = res.by;
        var view = btn.closest('.item-view');
        if (view && stage === 'printed') view.classList.toggle('is-printed', res.on);
        updateSummary(res);
        // On the order page, show the "send shipping update on WhatsApp" banner right away.
        if (stage === 'shipped' && res.on && document.getElementById('statusBadge')) { location.reload(); return; }
        var card = btn.closest('.order-card');
        if (card) {
          var badge = card.querySelector('.badge:not(.delayed)');
          if (badge) { badge.className = 'badge ' + res.status.key; badge.textContent = res.status.label; }
        }
      })
      .catch(fail)
      .then(function () { btn.classList.remove('busy'); });
  });

  // Copy the shipping address (for courier booking sites / WhatsApp).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy-address]');
    if (!b) return;
    var text = $all(b.closest('.address'), '.val').map(function (v) { return v.innerText.trim(); }).filter(Boolean).join('\n');
    var done = function () { b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy the address:', text); });
    else window.prompt('Copy the address:', text);
  });

  // Copy a ready text, e.g. a bill's customer link.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy-text]');
    if (!b) return;
    var text = b.dataset.copyText, label = b.textContent;
    var done = function () { b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = label; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy:', text); });
    else window.prompt('Copy:', text);
  });

  // ---------------------------------------------------------------- image lightbox
  var lb = document.getElementById('lightbox');
  if (lb) {
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a.gal-item');
      if (!a) return;
      e.preventDefault();
      lb.querySelector('img').src = a.dataset.full || a.href;
      lb.hidden = false;
    });
    lb.addEventListener('click', function (e) {
      if (e.target.tagName === 'IMG' && !e.target.classList.contains('zoom')) { e.target.classList.add('zoom'); return; }
      lb.hidden = true;
      lb.querySelector('img').classList.remove('zoom');
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.hidden = true; });
  }

  // Color dots next to color names.
  if (window.COLOR_HEX) {
    $all(document, '.dot[data-color]').forEach(function (d) {
      var hex = window.COLOR_HEX[d.dataset.color];
      if (hex) d.style.background = hex; else d.remove();
    });
  }

  // Orders page: filters apply as soon as a date or delivery partner is picked (no need to tap Search).
  $all(document, 'form.filters').forEach(function (f) {
    $all(f, 'select, input[type=date]').forEach(function (el) {
      el.addEventListener('change', function () {
        if (el.type === 'date' && el.value && !/^\d{4}-\d{2}-\d{2}$/.test(el.value)) return;
        var pg = f.querySelector('input[name=page]'); if (pg) pg.remove();
        f.classList.add('busy');
        if (f.requestSubmit) f.requestSubmit(); else f.submit();
      });
    });
  });

  // Print list: search the items to print (order no, customer ID, sub-order, colour, print text…).
  var piSearch = document.getElementById('piSearch');
  if (piSearch) {
    var piCards = $all(document, '.print-item');
    var piNone = document.getElementById('piNone');
    var norm = function (s) { return s.toLowerCase().replace(/\s+/g, ' '); };
    piCards.forEach(function (c) { c.dataset.text = norm(c.textContent); });
    var piFilter = function () {
      var words = norm(piSearch.value).trim().split(' ').filter(Boolean);
      var shown = 0;
      piCards.forEach(function (c) {
        var hit = words.every(function (w) { return c.dataset.text.indexOf(w) !== -1; });
        c.hidden = !hit;
        if (hit) shown++;
      });
      if (piNone) piNone.hidden = shown > 0 || !piCards.length;
      $all(document, '.pi-hold input[name=q]').forEach(function (i) { i.value = piSearch.value.trim(); });
    };
    piSearch.addEventListener('input', piFilter);
    if (piSearch.value) piFilter();
  }

  // Print method per place: show the right questions and the catalog price for it.
  var pricing = form && form.dataset.pricing ? JSON.parse(form.dataset.pricing) : null;
  function tierRate(tiers, q) { var r = 0; (tiers || []).forEach(function (t) { if (q >= t[0]) r = t[1]; }); return r; }
  function printHint(place) {
    if (!pricing) return;
    var pm = place.querySelector('[data-pm]');
    if (!pm) return;
    var m = pm.value, card = place.closest('.item-card');
    var itemQty = parseInt(((card && card.querySelector('[name$="[quantity]"]')) || {}).value || '1', 10) || 1;
    var qty = Math.max(itemQty, printedPieces()); // 10+ printed pieces in the order get the lower prices
    var times = Math.max(1, parseInt((place.querySelector('[data-pn]') || {}).value || '1', 10) || 1);
    var sizeSel = place.querySelector('[data-ps]'), size = sizeSel ? sizeSel.value : '';
    var key = (size.split(/[\s(]/)[0] || '').toUpperCase(), table = {};
    Object.keys(pricing.print).forEach(function (k) { table[k.toUpperCase()] = pricing.print[k]; });
    var pr = (place.querySelector('[data-ppr]') || {}).value, hint = place.querySelector('[data-pp-hint]'), warn = false, txt = '';
    place.dataset.method = m;
    place.classList.toggle('custom-size', m === 'dtf' && size !== '' && !table[key]);
    var price = null;
    if (pr && m !== 'emb') {
      price = parseFloat(pr);
      txt = 'Your price ₹' + pr + ' per print' + (times > 1 ? ' × ' + times : '');
    } else if (m === 'dtf') {
      txt = !size ? 'Pick the print size — DTF price comes from the catalog.'
        : table[key] ? 'DTF ' + key + ': ₹' + table[key][0] + ' per print (₹' + table[key][1] + ' for 10+ pieces)'
        : 'Custom size: type the price per print.';
      if (table[key]) { price = qty >= 10 ? table[key][1] : table[key][0]; if (times > 1) txt += ' × ' + times + ' prints = ₹' + price * times; }
    } else if (m === 'emb') {
      var st = parseInt((place.querySelector('[data-pst]') || {}).value || '0', 10) || 0;
      var per = function (q) { return Math.round(st / 1000 * tierRate(pricing.emb, q) * 100) / 100; };
      if (st) price = per(qty);
      txt = !st ? 'Type the stitch count — ₹' + tierRate(pricing.emb, 1) + ' per 1000 stitches (less for 10+ / 50+ pieces).'
        : st.toLocaleString('en-IN') + ' stitches = ₹' + per(qty) + ' per piece for ' + qty + (qty === 1 ? ' pc' : ' pcs') + ' (₹' + per(10) + ' for 10+, ₹' + per(50) + ' for 50+). Digitizing extra.';
    } else {
      txt = 'Type the price per print — the catalog price depends on size and colours.';
      warn = !pr;
    }
    if (price !== null) price = Math.round(price * times * 100) / 100;
    var min = pricing.min[m];
    if (min && itemQty < min) { txt += ' Minimum ' + min + ' pieces per design.'; warn = true; }
    hint.textContent = txt;
    hint.classList.toggle('warn', warn);
    var used = (place.querySelector('textarea') || {}).value || size || (m === 'emb' && place.querySelector('[data-pst]').value) || pr;
    place._price = used ? price : 0; // null = price still missing
    place._label = (place.querySelector('.lbl') || {}).textContent || '';
    if (card) printTotal(card);
  }

  /** Printed pieces in the whole order (T-shirt + print and print-only items). */
  function printedPieces() {
    var t = 0;
    $all(form, '#items .item-card').forEach(function (c) {
      if (c.classList.contains('type-plain') || c.classList.contains('type-dtf_roll') || c.classList.contains('removing')) return;
      t += parseInt((c.querySelector('[name$="[quantity]"]') || {}).value || '0', 10) || 0;
    });
    return t;
  }

  // Printing ₹/pc of an item: the print places + chest logo + neck label (free with an A2/A3/A4 print).
  function printTotal(card) {
    var note = card.querySelector('[data-print-note]'), box = card.querySelector('[data-print-rate]');
    if (!pricing) return;
    var qty = Math.max(printedPieces(), 1);
    var logo = (pricing.print.Logo || pricing.print.LOGO || [0, 0])[qty >= 10 ? 1 : 0];
    var total = 0, parts = [], missing = false, big = false;
    $all(card, '.print-place').forEach(function (pl) {
      if (pl._price === undefined || pl._price === 0) return;
      var size = ((pl.querySelector('[data-ps]') || {}).value || '').split(/[\s(]/)[0].toUpperCase();
      if (pl.dataset.method !== 'emb' && ['A2', 'A3', 'A4'].indexOf(size) !== -1) big = true;
      var name = pl._label.replace(' print', '');
      if (pl._price === null) { missing = true; parts.push(name + ' ?'); return; }
      total += pl._price; parts.push(name + ' ₹' + pl._price);
    });
    var on = function (n) { var c = card.querySelector('input[type=checkbox][name$="[' + n + ']"]'); return c && c.checked; };
    if (on('chest_logo_on')) { total += logo; parts.push('Chest logo ₹' + logo); }
    if (on('neck_label_on')) { if (big) parts.push('Neck label free'); else { total += logo; parts.push('Neck label ₹' + logo); } }
    total = Math.round(total * 100) / 100;
    card._printPer = total;
    card._printWhat = parts.join(' + ');
    card._printMissing = missing;
    var dg = 0;
    $all(card, '.print-place[data-method=emb] input[name$="[dg]"]').forEach(function (i) { dg += parseFloat(i.value) || 0; });
    card._digit = dg;
    schedulePreview();
    if (!note) return;
    note.classList.toggle('warn-text', missing);
    note.innerHTML = !parts.length ? 'Printing: tap where this item is printed — the price comes from the catalog.'
      : 'Printing: <b>₹' + total + ' / pc</b> <span>(' + parts.join(' + ').replace(/[<>&]/g, '') + (missing ? ' — some prices missing' : '') + ')</span>';
  }
  if (form) {
    $all(form, '.print-place').forEach(printHint);
    form.addEventListener('change', function (e) {
      var pl = e.target.closest('.print-place');
      if (pl) printHint(pl);
      if (/\[(chest_logo_on|neck_label_on)\]$/.test(e.target.name || '')) printTotal(e.target.closest('.item-card'));
    });
    form.addEventListener('input', function (e) {
      var pl = e.target.closest('.print-place');
      if (pl) printHint(pl);
      if (e.target.matches('[name$="[quantity]"]')) $all(form, '#items .print-place').forEach(printHint);
      schedulePreview();
    });
  }

  // Prints: toggle the places this item is printed (front / back / chest / custom). Turning one off clears it.
  function setPlace(card, k, on) {
    var place = card.querySelector('.print-place[data-place="' + k + '"]');
    var btn = card.querySelector('[data-pp-toggle="' + k + '"]');
    if (!place || !btn) return;
    if (!on) {
      var txt = place.querySelector('textarea');
      if (txt && txt.value.trim() && !window.confirm('Remove this print and what is typed for it?')) return;
      $all(place, 'textarea, input').forEach(function (i) { i.value = i.matches('[data-pn]') ? 1 : ''; });
      var pm = place.querySelector('[data-pm]'); if (pm) pm.value = 'dtf';
      var ps = place.querySelector('[data-ps]'); if (ps) ps.value = '';
    }
    place.hidden = !on;
    btn.classList.toggle('on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    var empty = card.querySelector('.pp-empty');
    if (empty) empty.hidden = !!card.querySelector('.pp-toggle.on');
    printHint(place);
    if (on) { var first = place.querySelector('[data-pm]'); if (first) first.focus(); }
  }
  /** Show the print places that have something in them (after copying an item or picking a design). */
  function syncPlaces(card) {
    $all(card, '.print-place[data-place]').forEach(function (pl) {
      var v = function (sel) { var i = pl.querySelector(sel); return i ? i.value.trim() : ''; };
      var used = v('textarea') || v('[data-ps]') || v('[data-pm]') !== 'dtf' || v('[data-pst]') || v('[data-ppr]') || +v('[data-pn]') > 1;
      if (used) { pl.hidden = false; var b = card.querySelector('[data-pp-toggle="' + pl.dataset.place + '"]'); if (b) { b.classList.add('on'); b.setAttribute('aria-pressed', 'true'); } }
      printHint(pl);
    });
    var empty = card.querySelector('.pp-empty');
    if (empty) empty.hidden = !!card.querySelector('.pp-toggle.on');
  }
  if (form) form.addEventListener('click', function (e) {
    var t = e.target.closest('[data-pp-toggle]'), off = e.target.closest('[data-pp-off]');
    if (t) setPlace(t.closest('.item-card'), t.dataset.ppToggle, !t.classList.contains('on'));
    if (off) { var pl = off.closest('.print-place'); setPlace(pl.closest('.item-card'), pl.dataset.place, false); }
  });

  // ---------------------------------------------------------------- live bill preview (same rules as the bill)
  var previewTimer = null;
  function schedulePreview() { clearTimeout(previewTimer); previewTimer = setTimeout(billPreview, 120); }
  function billPreview() {
    var table = document.getElementById('billPreview');
    if (!table || !form) return;
    var panel = form.querySelector('.bill-panel'), gstDef = parseFloat(panel.dataset.gst) || 5;
    var rs = function (v) { return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var esc = function (v) { return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var cards = $all(form, '#items .item-card').filter(function (c) { return !c.classList.contains('removing'); });
    var perProduct = {};
    cards.forEach(function (c) {
      if (c.classList.contains('type-print_only') || c.classList.contains('type-dtf_roll') || !c._blank) return;
      var b = c._blank(), k = b.gsm + '|' + b.product;
      perProduct[k] = (perProduct[k] || 0) + (parseInt((c.querySelector('[name$="[quantity]"]') || {}).value || '0', 10) || 0);
    });
    var lines = [], missing = false;
    var typed = function (c, sel) { var i = c.querySelector(sel); return i && i.value.trim() !== '' ? parseFloat(i.value) : null; };
    cards.forEach(function (c) {
      var qty = parseInt((c.querySelector('[name$="[quantity]"]') || {}).value || '0', 10) || 0;
      var roll = c.classList.contains('type-dtf_roll'), only = c.classList.contains('type-print_only');
      if (roll) {
        var m = parseFloat((c.querySelector('[data-roll-len]') || {}).value) || 0, rr = typed(c, '[data-rate]');
        lines.push({ d: 'DTF print roll', q: m, u: 'm', r: rr !== null ? rr : ((pricing.print.Roll || [240])[0]), g: gstDef });
        return;
      }
      if (!qty) return;
      if (only) {
        var po = typed(c, '[data-rate]');
        lines.push({ d: 'Printing' + (c._printWhat ? ' – ' + c._printWhat : ''), q: qty, u: 'pcs', r: po !== null ? po : (c._printPer || 0), g: gstDef });
        if (po === null && c._printMissing) missing = true;
      } else {
        var b = c._blank ? c._blank() : {}, res = c._res || {}, own = typed(c, '[data-rate]'), price = res.price || 0;
        (res.tiers || []).forEach(function (t) { if ((perProduct[b.gsm + '|' + b.product] || qty) >= t[0]) price = t[1]; });
        if (own === null && !price) missing = true;
        lines.push({ d: 'T-shirt – ' + [b.gsm, b.product, b.color, b.size ? 'Size ' + b.size : ''].filter(Boolean).join(' · ') + (c.classList.contains('type-plain') ? ' (plain)' : ''),
          q: qty, u: 'pcs', r: own !== null ? own : price, g: res.gst || gstDef });
        if (c.classList.contains('type-print')) {
          if (c._printWhat) lines.push({ d: 'Printing – ' + c._printWhat, q: qty, u: 'pcs', r: c._printPer || 0, g: gstDef });
          if (c._printMissing) missing = true;
        }
      }
      if (c._digit) lines.push({ d: 'Embroidery digitizing (one time)', q: 1, u: 'pcs', r: c._digit, g: gstDef });
    });
    var sub = 0;
    lines.forEach(function (l) { l.a = Math.round(l.q * l.r * 100) / 100; sub += l.a; });
    var disc = Math.min(parseFloat((panel.querySelector('[data-bdisc]') || {}).value) || 0, sub);
    var ship = parseFloat((panel.querySelector('[data-bship]') || {}).value) || 0, tax = 0, taxable = 0;
    lines.forEach(function (l) { var t = sub ? Math.round((l.a - disc * l.a / sub) * 100) / 100 : 0; taxable += t; tax += Math.round(t * l.g) / 100; });
    var state = ((panel.querySelector('[data-bstate]') || {}).value || '').trim().toLowerCase();
    var inter = state && state !== (panel.dataset.sellerState || '').toLowerCase();
    var total = Math.round(taxable + tax + ship);
    var html = lines.map(function (l) {
      return '<tr><td class="wrap-cell">' + esc(l.d) + '</td><td class="num">' + (+l.q.toFixed(2)) + ' × ' + rs(l.r) + '</td><td class="num">' + rs(l.a) + '</td></tr>';
    }).join('') || '<tr><td class="muted" colspan="3">Choose a T-shirt and quantity above.</td></tr>';
    if (lines.length) {
      if (disc) html += '<tr><td colspan="2">Discount</td><td class="num">−' + rs(disc) + '</td></tr>';
      html += '<tr><td colspan="2">GST ' + (inter ? '(IGST)' : '(CGST + SGST)') + '</td><td class="num">' + rs(tax) + '</td></tr>';
      if (ship) html += '<tr><td colspan="2">Shipping</td><td class="num">' + rs(ship) + '</td></tr>';
      html += '<tr class="total"><td colspan="2"><b>Total</b></td><td class="num"><b>' + rs(total) + '</b></td></tr>';
      if (missing) html += '<tr><td colspan="3" class="warn-text">Some prices are missing — type them on the item (custom size, puff / HD / screen print).</td></tr>';
    }
    table.querySelector('tbody').innerHTML = html;
    form._billTotal = lines.length ? total : 0;
    var half = form.querySelector('[data-pay-half]'), full = form.querySelector('[data-pay-full]');
    if (half) half.textContent = '50% advance' + (total ? ' ' + rs(Math.round(total / 2)).replace('.00', '') : '');
    if (full) full.textContent = 'Full amount' + (total ? ' ' + rs(total).replace('.00', '') : '');
    var bar = document.getElementById('billTotalBar');
    var mkb = form.querySelector('[data-make-bill]'), none = mkb && !mkb.checked;
    if (bar) bar.textContent = lines.length && !none ? '· Bill ' + rs(total) : '';
  }
  if (form && document.getElementById('billPreview')) {
    form.addEventListener('input', schedulePreview);
    form.addEventListener('change', schedulePreview);
    form.addEventListener('click', function (e) { if (e.target.closest('.chip, .stepper button, [data-remove-item], [data-dup-item], #addItem')) schedulePreview(); });
    schedulePreview();
  }

  // New order: advance = 50% of the bill total (or the full amount).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-pay-half], [data-pay-full]');
    if (!b || !form || !form._billTotal) return;
    var box = b.closest('.field').querySelector('[data-pay-amount]');
    box.value = b.matches('[data-pay-half]') ? Math.round(form._billTotal / 2) : form._billTotal;
  });
  // Payment quick buttons: fill the amount (full balance / 50% advance).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-pay-fill]');
    if (!b) return;
    var box = b.closest('form').querySelector('[data-pay-amount]');
    if (box) { box.value = b.dataset.payFill; box.dispatchEvent(new Event('input', { bubbles: true })); }
  });

  // Order form bill: "No bill" hides the bill details.
  function syncBillType() {
    var mk = document.querySelector('[data-make-bill]'), none = mk && !mk.checked;
    $all(document, '.bill-fields, .bill-panel .bill-hint, .gst-switch').forEach(function (e) { e.hidden = !!none; });
  }
  $all(document, '[data-make-bill]').forEach(function (r) { r.addEventListener('change', syncBillType); });
  syncBillType();

  // Close the "More" sheet / account menu when tapping elsewhere.
  document.addEventListener('click', function (e) {
    $all(document, 'details.tab-more[open], details.nav-more[open], details.me[open], details.dropdown[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.open = false;
    });
  });
})();
