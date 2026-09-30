// Looma Orders — small progressive enhancements on top of plain forms.
(function () {
  'use strict';

  var OTHER = '__other';

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    for (var k in attrs || {}) e.setAttribute(k, attrs[k]);
    if (text != null) e.textContent = text;
    return e;
  }
  function $all(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

  function addOtherOption(select) {
    if (select.querySelector('option[value="' + OTHER + '"]')) return;
    select.appendChild(el('option', { value: OTHER }, 'Other… (type)'));
  }

  // "Other…" on any dropdown lets staff type a value that isn't in the list.
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (s.tagName !== 'SELECT' || s.value !== OTHER) return;
    var v = (window.prompt('Type the value') || '').trim();
    if (!v) { s.value = s.dataset.prev || ''; return; }
    s.insertBefore(el('option', { value: v }, v), s.querySelector('option[value="' + OTHER + '"]'));
    s.value = v;
    s.dispatchEvent(new Event('change', { bubbles: true }));
  });
  document.addEventListener('focusin', function (e) {
    if (e.target.tagName === 'SELECT') e.target.dataset.prev = e.target.value;
  });

  // ---------------------------------------------------------------- order form
  var form = document.getElementById('orderForm');
  if (form && form.dataset.catalog) {
    var catalog = JSON.parse(form.dataset.catalog);
    var itemsBox = document.getElementById('items');
    var tpl = document.getElementById('itemTemplate');
    var newCounter = 1000;

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

    // GSM -> product -> color / size, per item card.
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
        if (level <= 0) fill(sel.gsm, catalog.map(function (g) { return g.gsm; }), sel.gsm ? sel.gsm.dataset.value : '');
        var g = gsmEntry();
        if (level <= 1) fill(sel.product, g ? g.products : [], level === 0 && sel.product ? sel.product.dataset.value : value('product'));
        var p = productEntry();
        if (level <= 2) {
          fill(sel.color, p ? p.colors : [], level === 0 && sel.color ? sel.color.dataset.value : value('color'));
          fill(sel.size, p && p.sizes.length ? p.sizes : ['XS', 'S', 'M', 'L', 'XL', 'XXL'], level === 0 && sel.size ? sel.size.dataset.value : value('size'));
        }
      }
      if (sel.gsm) sel.gsm.addEventListener('change', function () { if (sel.product) sel.product.value = ''; refresh(1); });
      if (sel.product) sel.product.addEventListener('change', function () { refresh(2); });
      refresh(0);
      $all(card, 'select[data-other]').forEach(addOtherOption);
    }

    function renumber() {
      var cards = $all(itemsBox, '.item-card');
      cards.forEach(function (c, i) { c.querySelector('.item-num').textContent = i + 1; });
      var total = 0;
      cards.forEach(function (c) {
        var del = c.querySelector('input[name$="[delete]"]');
        var q = c.querySelector('input[data-qty]');
        if (!(del && del.checked) && q) total += parseInt(q.value, 10) || 0;
      });
      var t = document.getElementById('totalPcs');
      if (t) t.textContent = cards.length + ' item' + (cards.length === 1 ? '' : 's') + (total ? ' · ' + total + ' pcs' : '');
    }

    function newCard(values) {
      var key = 'n' + (newCounter++);
      var wrap = document.createElement('div');
      wrap.innerHTML = tpl.innerHTML.split('__KEY__').join(key);
      var card = wrap.firstElementChild;
      if (values) card.dataset.values = JSON.stringify(values);
      return card;
    }

    $all(itemsBox, '.item-card').forEach(initCard);
    renumber();

    var addBtn = document.getElementById('addItem');
    if (addBtn) {
      addBtn.addEventListener('click', function () {
        var card = newCard();
        itemsBox.appendChild(card);
        initCard(card);
        renumber();
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    itemsBox.addEventListener('click', function (e) {
      var dup = e.target.closest('[data-dup-item]');
      var rem = e.target.closest('[data-remove-item]');
      if (dup) {
        var src = dup.closest('.item-card');
        var vals = {};
        ['gsm', 'product', 'color', 'size'].forEach(function (k) {
          var s = src.querySelector('select[data-cat="' + k + '"]');
          vals[k] = s ? s.value : (JSON.parse(src.dataset.values || '{}')[k] || '');
        });
        var card = newCard(vals);
        // Pre-select catalog values, then copy every other field by its name suffix.
        ['gsm', 'product', 'color', 'size'].forEach(function (k) {
          var s = card.querySelector('select[data-cat="' + k + '"]');
          if (s) s.dataset.value = vals[k];
        });
        src.after(card);
        initCard(card);
        $all(src, 'input:not([type=file]):not([type=hidden]):not([type=checkbox]), textarea, select:not([data-cat])').forEach(function (inp) {
          var suffix = inp.name.replace(/^items\[[^\]]+\]/, '');
          var target = card.querySelector('[name$="' + suffix + '"]');
          if (!target) return;
          if (target.tagName === 'SELECT' && !Array.prototype.some.call(target.options, function (o) { return o.value === inp.value; })) {
            target.insertBefore(el('option', { value: inp.value }, inp.value), target.querySelector('option[value="' + OTHER + '"]'));
          }
          target.value = inp.value;
        });
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
      if (e.target.matches('input[data-preview]')) {
        var preview = e.target.nextElementSibling;
        preview.innerHTML = '';
        Array.prototype.forEach.call(e.target.files, function (f) {
          var img = el('img', { alt: f.name });
          img.src = URL.createObjectURL(f);
          preview.appendChild(img);
        });
      }
    });
    itemsBox.addEventListener('input', function (e) { if (e.target.matches('input[data-qty]')) renumber(); });

    // Avoid double submits on slow mobile connections.
    form.addEventListener('submit', function () {
      setTimeout(function () { $all(form, 'button').forEach(function (b) { b.disabled = true; }); }, 0);
    });
  }

  // ---------------------------------------------------------------- one-tap ticks
  function post(data) {
    var body = new FormData();
    body.append('csrf', window.CSRF);
    for (var k in data) body.append(k, data[k]);
    return fetch(window.BASE + 'order_action.php', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (!res.ok) throw new Error(res.error || 'Failed'); return res; });
  }
  function fail() { window.alert('Could not update. Check your internet and try again.'); }

  function updateSummary(res) {
    var badge = document.getElementById('statusBadge');
    if (badge) { badge.className = 'badge ' + res.status.key; badge.textContent = res.status.label; }
    var sum = document.getElementById('printSummary');
    if (sum) {
      sum.classList.toggle('done', res.order_printed);
      sum.querySelector('.box').textContent = res.order_printed ? '✓' : '';
      sum.querySelector('[data-printed-count]').textContent = res.printed_count;
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
    if (!turnOn && !window.confirm('Remove the "' + stage + '" tick?')) return;
    btn.classList.add('busy');
    var data = { id: holder.dataset.id, stage: stage, on: turnOn ? '1' : '' };
    if (btn.dataset.item) data.item = btn.dataset.item;
    post(data)
      .then(function (res) {
        btn.classList.toggle('done', res.on);
        btn.querySelector('.box').textContent = res.on ? '✓' : '';
        var by = btn.querySelector('.by');
        if (by) by.textContent = res.on ? res.by : 'Tap to mark';
        btn.title = res.by;
        var view = btn.closest('.item-view');
        if (view) view.classList.toggle('is-printed', res.on);
        updateSummary(res);
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
})();
