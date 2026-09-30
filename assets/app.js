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
  }

  if (form) $all(form, '.item-card, .design-card').forEach(initCard);

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

  // Plain / printed, neck label: show only what applies.
  function syncCardState(card) {
    var plain = card.querySelector('[data-plain-toggle]:checked');
    if (plain) card.classList.toggle('is-plain', plain.value === '1');
    var neck = card.querySelector('[data-neck-toggle]');
    if (neck) card.querySelector('.neck-text').hidden = !neck.checked;
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-plain-toggle]')) syncCardState(e.target.closest('.item-card'));
    if (e.target.matches('[data-neck-toggle]')) {
      var row = e.target.closest('[data-neck]');
      var txt = row.querySelector('.neck-text');
      txt.hidden = !e.target.checked;
      if (e.target.checked) txt.focus();
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
      var plainPrint = card.querySelector('[data-plain-toggle][value="0"]');
      if (plainPrint) { plainPrint.checked = true; }
      var blank = card._blank();
      card._setBlank({ gsm: d.gsm || blank.gsm, product: d.gsm ? d.product : (d.product || blank.product), color: d.color || blank.color, size: blank.size });
      ['front_print', 'back_print', 'chest_print', 'custom_print'].forEach(function (k) {
        var f = fieldBySuffix(card, '[' + k + ']');
        if (f) f.value = d[k] || '';
      });
      Object.keys(d.extra || {}).forEach(function (k) {
        var f = fieldBySuffix(card, '[' + k + ']');
        if (!f) return;
        if (f.type === 'checkbox') { f.checked = !!d.extra[k]; return; }
        if (f.tagName === 'SELECT' && d.extra[k]) addOption(f, d.extra[k]);
        f.value = d.extra[k] || '';
      });
      var neck = card.querySelector('[data-neck-toggle]');
      if (neck) {
        neck.checked = !!d.neck_label_on;
        card.querySelector('.neck-text').value = d.neck_label || '';
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
      if (det && ['front_print', 'back_print', 'chest_print', 'custom_print'].some(function (k) { return d[k]; })) det.open = true;
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
            b.appendChild(el('span', {}, [c.name, c.phone, c.pincode].filter(Boolean).join(' · ')));
            b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            b.addEventListener('click', function () {
              setField('customer_id', c.code);
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
              note.textContent = '✓ Saved customer — address filled in. You can still change it.';
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

  // ---------------------------------------------------------------- one-tap ticks
  function post(data) {
    var body = new FormData();
    body.append('csrf', window.CSRF);
    for (var k in data) body.append(k, data[k]);
    return fetch(BASE + 'order_action.php', { method: 'POST', body: body, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
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

  // Close the "More" sheet / account menu when tapping elsewhere.
  document.addEventListener('click', function (e) {
    $all(document, 'details.tab-more[open], details.me[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.open = false;
    });
  });
})();
