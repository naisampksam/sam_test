// Looma Orders — small progressive enhancements. Everything still works as plain forms.
(function () {
  'use strict';

  var OTHER = '__other';

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    for (var k in attrs || {}) e.setAttribute(k, attrs[k]);
    if (text != null) e.textContent = text;
    return e;
  }

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
    var opt = el('option', { value: v }, v);
    s.insertBefore(opt, s.querySelector('option[value="' + OTHER + '"]'));
    s.value = v;
    s.dispatchEvent(new Event('change', { bubbles: true }));
  });
  document.addEventListener('focusin', function (e) {
    if (e.target.tagName === 'SELECT') e.target.dataset.prev = e.target.value;
  });

  // ---------------------------------------------------------------- catalog dropdowns
  var form = document.getElementById('orderForm');
  if (form && form.dataset.catalog) {
    var catalog = JSON.parse(form.dataset.catalog);
    var sel = {};
    ['gsm', 'product', 'color', 'size'].forEach(function (k) {
      sel[k] = form.querySelector('select[data-cat="' + k + '"]');
    });
    // Current values (from the select, or from the read-only text when the user can't edit that field).
    var cur = {};
    ['gsm', 'product', 'color', 'size'].forEach(function (k) {
      cur[k] = sel[k] ? sel[k].dataset.value : (window.ORDER_VALUES || {})[k] || '';
    });

    function fill(select, values, current, render) {
      if (!select) return;
      select.innerHTML = '';
      select.appendChild(el('option', { value: '' }, 'Select…'));
      var found = !current;
      values.forEach(function (v) {
        var val = typeof v === 'string' ? v : v.name;
        var o = el('option', { value: val }, render ? render(v) : val);
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

    function gsmEntry() { return catalog.find(function (g) { return g.gsm === value('gsm'); }); }
    function productEntry() {
      var g = gsmEntry();
      return g && g.products.find(function (p) { return p.name === value('product'); });
    }
    function value(k) { return sel[k] ? sel[k].value : cur[k]; }

    function refresh(level) {
      if (level <= 0) fill(sel.gsm, catalog.map(function (g) { return g.gsm; }), value('gsm'));
      var g = gsmEntry();
      if (level <= 1) fill(sel.product, g ? g.products : [], value('product'));
      var p = productEntry();
      if (level <= 2) {
        fill(sel.color, p ? p.colors : [], value('color'));
        fill(sel.size, p && p.sizes.length ? p.sizes : ['XS', 'S', 'M', 'L', 'XL', 'XXL'], value('size'));
      }
    }
    if (sel.gsm) sel.gsm.addEventListener('change', function () { if (sel.product) sel.product.value = ''; refresh(1); });
    if (sel.product) sel.product.addEventListener('change', function () { refresh(2); });
    refresh(0);

    form.querySelectorAll('select[data-other]').forEach(addOtherOption);

    // Show chosen mock-ups before upload.
    var input = document.getElementById('mockupInput');
    var preview = document.getElementById('mockupPreview');
    if (input && preview) {
      input.addEventListener('change', function () {
        preview.innerHTML = '';
        Array.prototype.forEach.call(input.files, function (f) {
          var img = el('img', { alt: f.name });
          img.src = URL.createObjectURL(f);
          preview.appendChild(img);
        });
      });
    }

    // Avoid double submits on slow mobile connections.
    form.addEventListener('submit', function () {
      setTimeout(function () {
        form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  }

  // ---------------------------------------------------------------- one-tap ticks
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('button.tick');
    if (!btn || btn.disabled) return;
    e.preventDefault();
    var holder = btn.closest('[data-id]');
    var on = !btn.classList.contains('done');
    var stage = btn.dataset.stage;
    if (!on && !window.confirm('Remove the "' + stage + '" tick?')) return;
    btn.classList.add('busy');
    var body = new FormData();
    body.append('csrf', window.CSRF);
    body.append('id', holder.dataset.id);
    body.append('stage', stage);
    if (on) body.append('on', '1');
    fetch(window.BASE + 'order_action.php', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Failed');
        btn.classList.toggle('done', res.on);
        btn.querySelector('.box').textContent = res.on ? '✓' : '';
        var by = btn.querySelector('.by');
        if (by) by.textContent = res.on ? res.by : 'Tap to mark';
        btn.title = res.by;
        var card = btn.closest('.order-card');
        if (card) {
          var badge = card.querySelector('.badge:not(.delayed)');
          if (badge) { badge.className = 'badge ' + res.status.key; badge.textContent = res.status.label; }
        }
      })
      .catch(function () { window.alert('Could not update. Check your internet and try again.'); })
      .then(function () { btn.classList.remove('busy'); });
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
    document.querySelectorAll('.dot[data-color]').forEach(function (d) {
      var hex = window.COLOR_HEX[d.dataset.color];
      if (hex) d.style.background = hex; else d.remove();
    });
  }
})();
