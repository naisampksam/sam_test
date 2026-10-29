// Tasks workspace: the Notion-style page editor (blocks, "/" menu, links / images / connections per line, comments)
// and dragging cards between board columns. Saves by itself a moment after each change.
(function () {
  'use strict';

  // ------------------------------------------------------------------ board: drag a card to another column
  var board = document.querySelector('.task-board[data-csrf]');
  if (board) {
    var dragId = null;
    board.addEventListener('dragstart', function (e) {
      var c = e.target.closest('.task-card'); if (!c) return;
      dragId = c.dataset.task; c.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', dragId);
    });
    board.addEventListener('dragend', function () { board.querySelectorAll('.dragging, .drop-on').forEach(function (x) { x.classList.remove('dragging', 'drop-on'); }); });
    board.addEventListener('dragover', function (e) {
      var col = e.target.closest('.task-col'); if (!col || !dragId) return;
      e.preventDefault(); board.querySelectorAll('.drop-on').forEach(function (x) { if (x !== col) x.classList.remove('drop-on'); }); col.classList.add('drop-on');
    });
    board.addEventListener('drop', function (e) {
      var col = e.target.closest('.task-col'); if (!col || !dragId) return;
      e.preventDefault();
      var fd = new FormData(); fd.append('csrf', board.dataset.csrf); fd.append('do', 'move'); fd.append('ajax', '1'); fd.append('task', dragId); fd.append('status', col.dataset.status);
      dragId = null;
      fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.status === 'review' && col.dataset.status === 'done') alert('Sent for approval — it moves to Done once it is approved.');
        location.reload();
      }).catch(function () { alert('Could not move the task. Check the connection and try again.'); });
    });
  }

  // ------------------------------------------------------------------ page editor
  var np = document.getElementById('np');
  var dataEl = document.getElementById('npData');
  if (!np || !dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var csrf = np.dataset.csrf;
  var ed = document.getElementById('npEditor');
  var saveEl = document.getElementById('npSave');
  var statsEl = document.getElementById('npStats');
  var blocks = D.blocks.map(function (b) { b.props = b.props && !Array.isArray(b.props) ? b.props : {}; return b; });
  var conns = D.conns || {};
  var ccount = D.comments && !Array.isArray(D.comments) ? D.comments : {};
  var rev = D.rev, dirty = {}, deleted = {}, orderDirty = false, saving = false, again = false, timer = null, failed = false;

  var TYPES = [
    ['p', 'Text', '¶', 'Plain text'], ['h1', 'Heading 1', 'H1', 'Big section heading'], ['h2', 'Heading 2', 'H2', 'Medium heading'], ['h3', 'Heading 3', 'H3', 'Small heading'],
    ['bullet', 'Bulleted list', '•', 'A simple list'], ['number', 'Numbered list', '1.', 'Steps in order'], ['todo', 'To-do', '☑', 'Tick it off when done'],
    ['toggle', 'Toggle', '▸', 'Hide the indented lines under it'], ['quote', 'Quote', '❝', 'A quote or voice-over line'], ['callout', 'Callout', '💡', 'Stand-out note'],
    ['divider', 'Divider', '—', 'A line between parts'], ['image', 'Image', '🖼', 'A picture on the page']
  ];
  var ACTIONS = [
    ['link', 'Add link', '🔗', 'Reference video, song, post…'], ['img', 'Reference image', '🖼', 'Attach pictures to this line'],
    ['conn', 'Connect order / page', '🔌', 'Link an order or another task'], ['timing', 'Timing & shot note', '⏱', 'Seconds and what to show'],
    ['comment', 'Comment', '💬', 'Feedback on this line']
  ];
  var COLORS = ['', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
  var EMOJI = '📄 🎬 🎥 📣 🎨 👕 🧵 📦 🚚 💡 ⭐ ✅ ⚠️ ❗ ❓ 📌 📎 🔗 🗓️ ⏱️ 🎵 🎤 📸 🖼️ ✂️ 🔥 💬 👀 🙌 💰 🛍️ 🏷️ 📝 🧠 🚀 🎯 ❤️ 😊 🎉'.split(' ');
  var PH = { p: "Type '/' for commands, or just write", h1: 'Heading 1', h2: 'Heading 2', h3: 'Heading 3', bullet: 'List', number: 'List', todo: 'To-do',
    toggle: 'Toggle', quote: 'Quote', callout: 'Write something to stand out', image: 'Caption (optional)' };
  var TEXTY = { p: 1, bullet: 1, number: 1, quote: 1, callout: 1, todo: 1, toggle: 1 };

  function uid() { return 'b' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36).slice(-4); }
  function byId(id) { for (var i = 0; i < blocks.length; i++) if (blocks[i].id === id) return i; return -1; }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function textOf(html) { var d = document.createElement('div'); d.innerHTML = (html || '').replace(/<br>/g, '\n'); return d.textContent; }
  function words(html) { var t = textOf(html).trim(); return t ? t.split(/\s+/).length : 0; }
  function imgUrl(f, thumb) { return 'image.php?f=' + encodeURIComponent(thumb ? f.replace(/(\.\w+)$/, '_t$1') : f); }
  function fmtSec(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2); }
  function post(fields) {
    var fd = fields instanceof FormData ? fields : new FormData();
    if (!(fields instanceof FormData)) Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    fd.append('csrf', csrf); fd.append('ajax', '1'); fd.append('task', D.task);
    return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { throw new Error('Server error (' + r.status + ')'); }).then(function (j) { if (!r.ok || j.error) throw new Error(j.error || 'Error ' + r.status); return j; });
    });
  }

  // ---------------------------------------------------------------- render
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }

  function renderBlock(b) {
    var p = b.props;
    var row = el('div', 'nb t-' + b.type + (p.color ? ' c-' + p.color : '') + (p.done ? ' is-done' : '') + (b.checked ? ' is-checked' : ''));
    row.dataset.id = b.id;
    row.style.setProperty('--ind', p.ind || 0);
    var gut = el('div', 'nb-gutter');
    gut.appendChild(el('button', 'nb-add', '+')).title = 'Add a line below';
    var hd = gut.appendChild(el('button', 'nb-handle', '⋮⋮')); hd.title = 'Drag to move · tap for options'; hd.draggable = true;
    gut.querySelectorAll('button').forEach(function (x) { x.type = 'button'; x.tabIndex = -1; });
    row.appendChild(gut);
    var main = el('div', 'nb-main');
    var line = el('div', 'nb-line');
    if (b.type === 'todo') { var cb = el('input', 'nb-check'); cb.type = 'checkbox'; cb.checked = !!b.checked; line.appendChild(cb); }
    if (b.type === 'bullet') line.appendChild(el('span', 'nb-bullet', '•'));
    if (b.type === 'number') line.appendChild(el('span', 'nb-num', '1.'));
    if (b.type === 'toggle') { var tg = el('button', 'nb-tog', '▸'); tg.type = 'button'; tg.setAttribute('aria-expanded', p.open ? 'true' : 'false'); line.appendChild(tg); }
    if (b.type === 'callout') { var em = el('button', 'nb-emoji', esc(p.emoji || '💡')); em.type = 'button'; line.appendChild(em); }
    if (b.type === 'divider') { line.appendChild(el('hr', 'nb-hr')); }
    else {
      var tx = el('div', 'nb-text');
      tx.contentEditable = 'true'; tx.spellcheck = true;
      tx.dataset.ph = PH[b.type] || '';
      tx.innerHTML = b.html || '';
      line.appendChild(tx);
    }
    if (b.type === 'image') {
      var box = el('div', 'nb-image');
      if (p.imgs && p.imgs.length) p.imgs.forEach(function (f) { var im = el('img'); im.src = imgUrl(f); im.loading = 'lazy'; im.dataset.full = f; box.appendChild(im); });
      else box.appendChild(el('button', 'btn small nb-pick', '🖼 Upload an image'));
      main.appendChild(box);
    }
    main.appendChild(line);
    main.appendChild(renderExtras(b));
    row.appendChild(main);
    var side = el('div', 'nb-side');
    if (TEXTY[b.type] && D.video) {
      var sec = p.sec || (words(b.html) / 2.5);
      if (sec > 0) side.appendChild(el('span', 'nb-sec' + (p.sec ? ' set' : ''), (p.sec ? '⏱ ' : '~') + Math.max(1, Math.round(sec)) + 's')).title = p.sec ? 'Timing set' : 'About how long to say it';
    }
    var cmt = side.appendChild(el('button', 'nb-cmt' + (ccount[b.id] ? ' has' : ''), ccount[b.id] ? '💬 ' + ccount[b.id] : '💬'));
    cmt.type = 'button'; cmt.title = 'Comments on this line';
    if (TEXTY[b.type] || b.type === 'image') {
      var dn = side.appendChild(el('button', 'nb-done', '✓')); dn.type = 'button'; dn.title = p.done ? 'Edited ✓ — tap to undo' : 'Mark this part as edited';
    }
    row.appendChild(side);
    return row;
  }

  function renderExtras(b) {
    var p = b.props, x = el('div', 'nb-extras');
    if (p.shot) x.appendChild(el('span', 'nb-shot', '🎥 ' + esc(p.shot))).dataset.act = 'timing';
    (p.links || []).forEach(function (l, i) {
      var a = el('a', 'nb-chip link', '🔗 ' + esc(l.t || l.u.replace(/^https?:\/\/(www\.)?/, '').slice(0, 40)));
      a.href = l.u; a.target = '_blank'; a.rel = 'noopener'; a.title = l.u;
      var rm = el('button', 'nb-x', '×'); rm.type = 'button'; rm.dataset.rm = 'links:' + i; rm.title = 'Remove link';
      var w = el('span', 'nb-chipw'); w.appendChild(a); w.appendChild(rm); x.appendChild(w);
    });
    (p.conn || []).forEach(function (c, i) {
      var info = conns[c.k + ':' + c.id] || { label: (c.k === 'order' ? 'Order #' : 'Task #') + c.id, url: '' };
      var a = el(info.url ? 'a' : 'span', 'nb-chip conn', (c.k === 'order' ? '📦 ' : '↗ ') + esc(info.label));
      if (info.url) a.href = info.url;
      var rm = el('button', 'nb-x', '×'); rm.type = 'button'; rm.dataset.rm = 'conn:' + i; rm.title = 'Remove';
      var w = el('span', 'nb-chipw'); w.appendChild(a); w.appendChild(rm); x.appendChild(w);
    });
    if (b.type !== 'image' && p.imgs && p.imgs.length) {
      var th = el('span', 'nb-thumbs');
      p.imgs.forEach(function (f, i) {
        var w = el('span', 'nb-thumb'); var im = el('img'); im.src = imgUrl(f, true); im.loading = 'lazy'; im.dataset.full = f; im.alt = 'Reference image';
        var rm = el('button', 'nb-x', '×'); rm.type = 'button'; rm.dataset.rm = 'imgs:' + i; rm.title = 'Remove image';
        w.appendChild(im); w.appendChild(rm); th.appendChild(w);
      });
      x.appendChild(th);
    }
    return x;
  }

  function renderAll() {
    ed.innerHTML = '';
    if (!blocks.length) blocks.push(newBlock('p'));
    blocks.forEach(function (b) { ed.appendChild(renderBlock(b)); });
    refresh();
  }

  function rowOf(id) { return ed.querySelector('.nb[data-id="' + id + '"]'); }

  function rerender(id, focusEnd) {
    var i = byId(id), old = rowOf(id); if (i < 0 || !old) return;
    var n = renderBlock(blocks[i]); old.replaceWith(n); refresh();
    if (focusEnd !== undefined) focusBlock(id, focusEnd);
  }

  /** Numbers, toggles (hide indented lines under a closed toggle) and the script stats. */
  function refresh() {
    var counters = [], hideBelow = null;
    blocks.forEach(function (b) {
      var row = rowOf(b.id); if (!row) return;
      var ind = b.props.ind || 0;
      if (hideBelow !== null && ind > hideBelow) row.hidden = true; else { row.hidden = false; hideBelow = null; }
      if (!row.hidden && b.type === 'toggle' && !b.props.open) hideBelow = ind;
      if (b.type === 'number') { counters.length = ind + 1; counters[ind] = (counters[ind] || 0) + 1; var n = row.querySelector('.nb-num'); if (n) n.textContent = counters[ind] + '.'; }
      else if (ind <= counters.length - 1) counters.length = ind;
    });
    stats();
  }

  function stats() {
    var w = 0, sec = 0, lines = 0, edited = 0, todo = 0, ticked = 0;
    blocks.forEach(function (b) {
      if (TEXTY[b.type] && b.type !== 'todo' && b.type !== 'toggle') {
        var n = words(b.html); w += n; sec += b.props.sec || n / 2.5;
        if (n) { lines++; if (b.props.done) edited++; }
      }
      if (b.type === 'todo') { todo++; if (b.checked) ticked++; }
    });
    var bits = [];
    if (w) bits.push('<span>' + w + ' words</span>');
    if (D.video && sec) bits.push('<span title="Read aloud at a normal pace, or the timings you set">⏱ ≈ ' + fmtSec(sec) + '</span>');
    if (lines && (D.video || edited)) bits.push('<span class="np-prog" title="Lines marked ✓ edited"><i style="width:' + Math.round(edited / lines * 100) + '%"></i></span><span>✎ ' + edited + '/' + lines + ' edited</span>');
    if (todo) bits.push('<span>☑ ' + ticked + '/' + todo + ' done</span>');
    statsEl.innerHTML = bits.join('');
  }

  // ---------------------------------------------------------------- changes & saving
  function newBlock(type, html) { return { id: uid(), type: type || 'p', html: html || '', checked: false, props: {} }; }
  function touch(id) { dirty[id] = true; schedule(); }
  function schedule(now) { setState('dirty'); clearTimeout(timer); timer = setTimeout(save, now ? 0 : 700); }
  function setState(s, msg) {
    saveEl.className = 'np-save ' + s;
    saveEl.textContent = msg || { dirty: 'Editing…', saving: 'Saving…', saved: 'Saved ✓', error: '⚠ Not saved — retrying' }[s] || '';
  }
  function pending() { return Object.keys(dirty).length || Object.keys(deleted).length || orderDirty; }

  function save() {
    if (saving) { again = true; return Promise.resolve(); }
    if (!pending()) { if (!failed) setState('saved'); return Promise.resolve(); }
    var changed = Object.keys(dirty).map(function (id) { return blocks[byId(id)]; }).filter(Boolean)
      .map(function (b) { return { id: b.id, type: b.type, html: b.html, checked: b.checked, props: b.props }; });
    var del = Object.keys(deleted), sentDirty = dirty;
    dirty = {}; deleted = {}; orderDirty = false; saving = true; setState('saving');
    return post({ do: 'blocks', rev: rev, data: JSON.stringify({ changed: changed, deleted: del, order: blocks.map(function (b) { return b.id; }) }) }).then(function (r) {
      saving = false; failed = false; rev = r.rev;
      if (r.others && r.blocks) merge(r.blocks, r.conns, 'Someone else changed this page too — their changes are shown.');
      if (again || pending()) { again = false; return save(); }
      setState('saved');
    }).catch(function (e) {
      saving = false; failed = true;
      Object.keys(sentDirty).forEach(function (k) { dirty[k] = true; }); del.forEach(function (k) { if (byId(k) < 0) deleted[k] = true; }); orderDirty = true;
      setState('error', '⚠ Not saved — ' + e.message + '. Retrying…');
      clearTimeout(timer); timer = setTimeout(save, 5000);
    });
  }

  /** Someone else's changes: take theirs for every line not being edited here. */
  function merge(server, newConns, msg) {
    Object.assign(conns, newConns || {});
    var active = document.activeElement && document.activeElement.closest && document.activeElement.closest('.nb');
    var activeId = active ? active.dataset.id : null;
    var mine = {}; blocks.forEach(function (b) { mine[b.id] = b; });
    var next = server.map(function (s) { s.props = s.props && !Array.isArray(s.props) ? s.props : {}; return (dirty[s.id] || s.id === activeId) && mine[s.id] ? mine[s.id] : s; });
    // keep my new lines not saved yet, after the line they follow
    blocks.forEach(function (b, i) {
      if (next.some(function (n) { return n.id === b.id; })) return;
      if (!dirty[b.id] && b.id !== activeId) return;
      var prev = i ? blocks[i - 1].id : null, at = prev ? next.findIndex(function (n) { return n.id === prev; }) + 1 : 0;
      next.splice(at, 0, b);
    });
    var sel = saveSel();
    blocks = next; renderAll(); restoreSel(sel);
    if (msg) flashMsg(msg);
  }

  function flashMsg(msg) { var f = el('div', 'np-toast', esc(msg)); document.body.appendChild(f); setTimeout(function () { f.remove(); }, 4000); }
  function saveSel() {
    var s = window.getSelection(); if (!s.rangeCount) return null;
    var row = s.anchorNode && (s.anchorNode.nodeType === 1 ? s.anchorNode : s.anchorNode.parentNode).closest('.nb'); if (!row) return null;
    return { id: row.dataset.id, off: caretOffset(row.querySelector('.nb-text')) };
  }
  function restoreSel(s) { if (s) { var r = rowOf(s.id); if (r) setCaret(r.querySelector('.nb-text'), s.off); } }

  window.addEventListener('beforeunload', function (e) { if (pending() || saving) { save(); e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); schedule(true); } });
  // A status / comment form: save the page first.
  np.addEventListener('submit', function (e) {
    var f = e.target;
    if (!pending() && !saving) return;
    e.preventDefault();
    clearTimeout(timer);
    (saving ? new Promise(function (r) { setTimeout(r, 400); }) : Promise.resolve()).then(save).then(function () { f.requestSubmit ? f.requestSubmit(e.submitter) : f.submit(); });
  }, true);

  // ---------------------------------------------------------------- caret helpers
  function caretOffset(tx) {
    var s = window.getSelection(); if (!tx || !s.rangeCount) return 0;
    var r = s.getRangeAt(0).cloneRange(), pre = document.createRange(); pre.selectNodeContents(tx); pre.setEnd(r.endContainer, r.endOffset);
    return pre.toString().length;
  }
  function setCaret(tx, off) {
    if (!tx) return; tx.focus();
    var s = window.getSelection(), r = document.createRange(), left = off, w = document.createTreeWalker(tx, NodeFilter.SHOW_TEXT), n, last = null;
    while ((n = w.nextNode())) { last = n; if (left <= n.length) { r.setStart(n, left); r.collapse(true); s.removeAllRanges(); s.addRange(r); return; } left -= n.length; }
    r.selectNodeContents(tx); r.collapse(off > 0 || last === null ? false : true); s.removeAllRanges(); s.addRange(r);
  }
  function pointAt(tx, off) {
    var w = document.createTreeWalker(tx, NodeFilter.SHOW_TEXT), n;
    while ((n = w.nextNode())) { if (off <= n.length) return [n, off]; off -= n.length; }
    return [tx, tx.childNodes.length];
  }
  function focusBlock(id, atEnd) {
    var row = rowOf(id); if (!row) return;
    var tx = row.querySelector('.nb-text'); if (!tx) { row.querySelector('.nb-handle').focus(); return; }
    setCaret(tx, atEnd ? 1e9 : 0);
  }
  function atStart(tx) { var s = window.getSelection(); return s.isCollapsed && caretOffset(tx) === 0; }
  function atEnd(tx) { var s = window.getSelection(); return s.isCollapsed && caretOffset(tx) >= tx.textContent.length; }

  // ---------------------------------------------------------------- block operations
  function insertAfter(id, b, focus) {
    var i = id ? byId(id) : blocks.length - 1;
    blocks.splice(i + 1, 0, b);
    var ref = id ? rowOf(id) : null, row = renderBlock(b);
    if (ref) ref.after(row); else ed.appendChild(row);
    touch(b.id); orderDirty = true; refresh();
    if (focus !== false) focusBlock(b.id, false);
    return b;
  }
  function removeBlock(id) {
    var i = byId(id); if (i < 0) return;
    blocks.splice(i, 1); delete dirty[id]; deleted[id] = true; orderDirty = true;
    var r = rowOf(id); if (r) r.remove();
    if (!blocks.length) insertAfter(null, newBlock('p'));
    refresh(); schedule();
  }
  function setType(id, type, caretAtStart) {
    var b = blocks[byId(id)]; if (!b) return;
    b.type = type; if (type !== 'todo') b.checked = false;
    if (type === 'divider') b.html = '';
    touch(id); rerender(id, !caretAtStart);
    if (type === 'divider') { var nx = blocks[byId(id) + 1]; if (nx) focusBlock(nx.id, false); else insertAfter(id, newBlock('p')); }
    if (type === 'image' && !(b.props.imgs || []).length) pickImages(id);
  }
  function move(id, dir) {
    var i = byId(id), j = i + dir; if (i < 0 || j < 0 || j >= blocks.length) return;
    var b = blocks.splice(i, 1)[0]; blocks.splice(j, 0, b); orderDirty = true;
    var r = rowOf(id), other = rowOf(blocks[dir < 0 ? j + 1 : j - 1].id);
    if (dir < 0) other.before(r); else other.after(r);
    refresh(); schedule();
  }
  function indent(id, d) {
    var b = blocks[byId(id)], cur = b.props.ind || 0, prev = blocks[byId(id) - 1];
    var max = prev ? (prev.props.ind || 0) + 1 : 0, v = Math.max(0, Math.min(4, Math.min(max, cur + d)));
    if (v === cur) return;
    if (v) b.props.ind = v; else delete b.props.ind;
    rowOf(id).style.setProperty('--ind', v); touch(id); refresh();
  }

  // ---------------------------------------------------------------- typing
  ed.addEventListener('input', function (e) {
    var tx = e.target.closest('.nb-text'); if (!tx) return;
    var id = tx.closest('.nb').dataset.id, b = blocks[byId(id)];
    if (tx.innerHTML === '<br>') tx.innerHTML = '';
    b.html = tx.innerHTML; touch(id);
    if (b.type === 'p' && markdown(b, tx)) return;
    slashCheck(tx, b, e);
    if (D.video || b.type === 'number') refresh(); else stats();
    var sec = tx.closest('.nb').querySelector('.nb-sec:not(.set)');
    if (sec) { var n = Math.max(1, Math.round(words(b.html) / 2.5)); sec.textContent = '~' + n + 's'; }
  });

  /** "# " heading, "- " list, "1. " numbered, "[] " to-do, "> " toggle, '" ' quote, "---" divider. */
  function markdown(b, tx) {
    var t = tx.textContent, rules = [[/^###[\s ]/, 'h3'], [/^##[\s ]/, 'h2'], [/^#[\s ]/, 'h1'], [/^[-*•][\s ]/, 'bullet'], [/^1[.)][\s ]/, 'number'],
      [/^\[ ?\][\s ]/, 'todo'], [/^>[\s ]/, 'toggle'], [/^["“][\s ]/, 'quote']];
    if (t === '---') { b.html = ''; setType(b.id, 'divider'); return true; }
    for (var i = 0; i < rules.length; i++) {
      var m = t.match(rules[i][0]);
      if (m && caretOffset(tx) === m[0].length) {
        var first = tx.firstChild;
        if (!first || first.nodeType !== 3 || first.nodeValue.length < m[0].length) return false;
        first.nodeValue = first.nodeValue.slice(m[0].length);
        b.html = tx.innerHTML === '<br>' ? '' : tx.innerHTML;
        setType(b.id, rules[i][1]);
        return true;
      }
    }
    return false;
  }

  ed.addEventListener('keydown', function (e) {
    var tx = e.target.closest('.nb-text'); if (!tx) return;
    var row = tx.closest('.nb'), id = row.dataset.id, i = byId(id), b = blocks[i];
    if (menu.open && menu.key(e)) return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); linkSelection(); return; }
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      if (!tx.textContent.length && ['bullet', 'number', 'todo', 'quote', 'callout', 'toggle', 'h1', 'h2', 'h3'].indexOf(b.type) >= 0) { setType(id, 'p'); return; }
      // caret at the start of a line with text: a new empty line goes above it
      if (atStart(tx) && tx.textContent.length) {
        var up = newBlock(b.type === 'toggle' ? 'p' : ({ bullet: 1, number: 1, todo: 1 }[b.type] ? b.type : 'p'));
        if (b.props.ind) up.props.ind = b.props.ind;
        blocks.splice(i, 0, up); row.before(renderBlock(up)); touch(up.id); orderDirty = true; refresh();
        return;
      }
      // split at the caret: the text after it moves to a new line
      var s = window.getSelection(); if (!s.rangeCount) return;
      var r = s.getRangeAt(0); r.deleteContents();
      var tail = document.createRange(); tail.selectNodeContents(tx); tail.setStart(r.endContainer, r.endOffset);
      var frag = tail.extractContents(), tmp = el('div'); tmp.appendChild(frag);
      var rest = tmp.innerHTML === '<br>' ? '' : tmp.innerHTML;
      if (tx.innerHTML === '<br>') tx.innerHTML = '';
      b.html = tx.innerHTML; touch(id);
      var keep = { bullet: 1, number: 1, todo: 1 }[b.type] ? b.type : 'p';
      if (b.type === 'toggle' && !rest) keep = 'p';
      var nb = newBlock(keep, rest);
      if (b.props.ind) nb.props.ind = b.props.ind;
      if (b.type === 'toggle') { nb.props.ind = (b.props.ind || 0) + 1; b.props.open = true; touch(id); rerender(id); }
      insertAfter(id, nb);
      return;
    }
    if (e.key === 'Backspace' && atStart(tx) && window.getSelection().isCollapsed) {
      if (b.type !== 'p') { e.preventDefault(); if (b.props.ind) { indent(id, -1); return; } setType(id, 'p', true); return; }
      if (b.props.ind) { e.preventDefault(); indent(id, -1); return; }
      var prev = blocks[i - 1]; if (!prev) return;
      e.preventDefault();
      if (prev.type === 'divider') { removeBlock(prev.id); return; }
      var ptx = rowOf(prev.id).querySelector('.nb-text');
      var off = ptx.textContent.length;
      prev.html = (prev.html || '') + (b.html || ''); ptx.innerHTML = prev.html; touch(prev.id);
      // move this line's extras to the one it joins
      ['links', 'imgs', 'conn'].forEach(function (k) { if (b.props[k]) { prev.props[k] = (prev.props[k] || []).concat(b.props[k]); } });
      removeBlock(id); rerender(prev.id); setCaret(rowOf(prev.id).querySelector('.nb-text'), off);
      return;
    }
    if (e.key === 'Delete' && atEnd(tx) && blocks[i + 1] && blocks[i + 1].type !== 'divider' && blocks[i + 1].type !== 'image') {
      e.preventDefault();
      var nx = blocks[i + 1], off2 = tx.textContent.length;
      b.html += nx.html || ''; tx.innerHTML = b.html; touch(id); removeBlock(nx.id); setCaret(tx, off2);
      return;
    }
    if (e.key === 'Tab') { e.preventDefault(); indent(id, e.shiftKey ? -1 : 1); return; }
    if (e.key === 'ArrowUp' && !e.shiftKey && caretLine(tx, -1)) { var pv = prevVisible(i); if (pv) { e.preventDefault(); focusBlock(pv.id, true); } return; }
    if (e.key === 'ArrowDown' && !e.shiftKey && caretLine(tx, 1)) { var nv = nextVisible(i); if (nv) { e.preventDefault(); focusBlock(nv.id, false); } return; }
    if ((e.altKey) && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); move(id, e.key === 'ArrowUp' ? -1 : 1); focusBlock(id, true); }
  });

  function visibleText(j) { var b = blocks[j]; var r = b && rowOf(b.id); return r && !r.hidden && r.querySelector('.nb-text') ? b : null; }
  function prevVisible(i) { for (var j = i - 1; j >= 0; j--) if (visibleText(j)) return blocks[j]; return null; }
  function nextVisible(i) { for (var j = i + 1; j < blocks.length; j++) if (visibleText(j)) return blocks[j]; return null; }
  /** Is the caret on the first (dir -1) / last (dir 1) line of the text? */
  function caretLine(tx, dir) {
    var s = window.getSelection(); if (!s.rangeCount || !s.isCollapsed) return false;
    var r = s.getRangeAt(0).getClientRects()[0], box = tx.getBoundingClientRect();
    if (!r) return dir < 0 ? atStart(tx) : atEnd(tx);
    var lh = parseFloat(getComputedStyle(tx).lineHeight) || 20;
    return dir < 0 ? r.top - box.top < lh * 0.8 : box.bottom - r.bottom < lh * 0.8;
  }

  // Paste: plain text; several lines become several lines (blocks); a picture is attached to the line.
  ed.addEventListener('paste', function (e) {
    var tx = e.target.closest('.nb-text'); if (!tx) return;
    var id = tx.closest('.nb').dataset.id, cd = e.clipboardData;
    var files = Array.prototype.filter.call(cd.files || [], function (f) { return /^image\//.test(f.type); });
    if (files.length) { e.preventDefault(); upload(id, files); return; }
    var text = cd.getData('text/plain'); if (!text) return;
    e.preventDefault();
    var lines = text.replace(/\r/g, '').split('\n');
    if (lines.length === 1 || lines.filter(function (l) { return l.trim(); }).length === 1) { document.execCommand('insertText', false, text.trim() === '' ? text : lines.join(' ')); return; }
    document.execCommand('insertText', false, lines[0]);
    var after = id;
    lines.slice(1).forEach(function (l) {
      if (!l.trim()) return;
      var t = 'p', m;
      if ((m = l.match(/^\s*[-*•]\s+/))) t = 'bullet'; else if ((m = l.match(/^\s*\d+[.)]\s+/))) t = 'number'; else if ((m = l.match(/^\s*#{1,3}\s+/))) t = 'h2'; else if ((m = l.match(/^\s*\[\s?\]\s*/))) t = 'todo';
      after = insertAfter(after, newBlock(t, esc(m ? l.slice(m[0].length) : l.trim())), false).id;
    });
    focusBlock(after, true);
  });

  // Drop pictures on a line.
  ed.addEventListener('dragover', function (e) { if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) { var r = e.target.closest('.nb'); if (r) { e.preventDefault(); markDrop(r, null); } } });
  ed.addEventListener('drop', function (e) {
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    var r = e.target.closest('.nb'); if (!r) return;
    e.preventDefault(); markDrop(null);
    upload(r.dataset.id, Array.prototype.filter.call(e.dataTransfer.files, function (f) { return /^image\//.test(f.type); }));
  });

  // ---------------------------------------------------------------- clicks in the editor
  ed.addEventListener('change', function (e) {
    if (!e.target.matches('.nb-check')) return;
    var row = e.target.closest('.nb'), b = blocks[byId(row.dataset.id)];
    b.checked = e.target.checked; row.classList.toggle('is-checked', b.checked); touch(b.id); stats();
  });
  ed.addEventListener('click', function (e) {
    var row = e.target.closest('.nb'); if (!row) return;
    var id = row.dataset.id, b = blocks[byId(id)], t = e.target;
    if (t.closest('.nb-add')) { var nb = insertAfter(id, newBlock('p')); menu.show(rowOf(nb.id).querySelector('.nb-text'), nb, 'slash', ''); return; }
    if (t.closest('.nb-handle')) { menu.show(t.closest('.nb-handle'), b, 'block'); return; }
    if (t.closest('.nb-tog')) { if (b.props.open) delete b.props.open; else b.props.open = true; t.closest('.nb-tog').setAttribute('aria-expanded', b.props.open ? 'true' : 'false'); touch(id); refresh(); return; }
    if (t.closest('.nb-done')) { if (b.props.done) delete b.props.done; else b.props.done = true; row.classList.toggle('is-done', !!b.props.done); t.title = b.props.done ? 'Edited ✓ — tap to undo' : 'Mark this part as edited'; touch(id); stats(); return; }
    if (t.closest('.nb-cmt')) { comments.open(id); return; }
    if (t.closest('.nb-emoji')) { emojiPick(t.closest('.nb-emoji'), function (em) { if (em) b.props.emoji = em; else delete b.props.emoji; touch(id); rerender(id); }); return; }
    if (t.closest('.nb-pick')) { pickImages(id); return; }
    if (t.closest('.nb-x')) { var rm = t.closest('.nb-x').dataset.rm.split(':'); b.props[rm[0]].splice(+rm[1], 1); if (!b.props[rm[0]].length) delete b.props[rm[0]]; touch(id); rerender(id); return; }
    if (t.closest('.nb-shot') || t.closest('.nb-sec')) { act('timing', b); return; }
    if (t.matches('.nb-image img, .nb-thumb img')) { lightbox(t.dataset.full); return; }
    if (t.closest('a.nb-chip') || t.closest('.nb-text a')) { var a = t.closest('a'); if (a && a.href && t.closest('.nb-text')) { e.preventDefault(); window.open(a.href, '_blank', 'noopener'); } }
  });

  document.getElementById('npAddEnd').addEventListener('click', function () {
    var last = blocks[blocks.length - 1];
    if (last && last.type === 'p' && !textOf(last.html).trim()) { focusBlock(last.id, false); return; }
    insertAfter(last ? last.id : null, newBlock('p'));
  });

  // ---------------------------------------------------------------- drag lines to reorder
  var dragging = null;
  ed.addEventListener('dragstart', function (e) {
    var h = e.target.closest && e.target.closest('.nb-handle'); if (!h) return;
    dragging = h.closest('.nb').dataset.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'block');
    var row = rowOf(dragging); row.classList.add('dragging'); try { e.dataTransfer.setDragImage(row, 10, 10); } catch (x) {}
  });
  ed.addEventListener('dragover', function (e) {
    if (!dragging) return; var r = e.target.closest('.nb'); if (!r) return;
    e.preventDefault(); var box = r.getBoundingClientRect(); markDrop(r, e.clientY > box.top + box.height / 2 ? 'after' : 'before');
  });
  ed.addEventListener('drop', function (e) {
    if (!dragging) return; e.preventDefault();
    var tgt = ed.querySelector('.drop-before, .drop-after');
    if (tgt && tgt.dataset.id !== dragging) {
      var after = tgt.classList.contains('drop-after'), b = blocks.splice(byId(dragging), 1)[0];
      blocks.splice(byId(tgt.dataset.id) + (after ? 1 : 0), 0, b);
      var row = rowOf(dragging); if (after) tgt.after(row); else tgt.before(row);
      orderDirty = true; refresh(); schedule();
    }
    markDrop(null); dragging = null;
  });
  ed.addEventListener('dragend', function () { markDrop(null); var r = dragging && rowOf(dragging); if (r) r.classList.remove('dragging'); dragging = null; });
  function markDrop(r, where) {
    ed.querySelectorAll('.drop-before, .drop-after, .drop-file').forEach(function (x) { x.classList.remove('drop-before', 'drop-after', 'drop-file'); });
    if (r) r.classList.add(where ? 'drop-' + where : 'drop-file');
  }

  // ---------------------------------------------------------------- "/" menu and the line menu
  var menu = (function () {
    var box = el('div', 'np-menu'); box.hidden = true; document.body.appendChild(box);
    var m = { open: false, items: [], sel: 0, block: null, mode: '', anchor: null, q: '' };
    function place(anchor) {
      var r = anchor.getBoundingClientRect();
      if (m.mode === 'slash') { var s = window.getSelection(); if (s.rangeCount) { var cr = s.getRangeAt(0).getClientRects()[0]; if (cr) r = cr; } }
      box.style.left = Math.max(8, Math.min(window.innerWidth - box.offsetWidth - 8, r.left + window.scrollX)) + 'px';
      var top = r.bottom + window.scrollY + 4;
      if (r.bottom + box.offsetHeight + 8 > window.innerHeight && r.top > box.offsetHeight + 8) top = r.top + window.scrollY - box.offsetHeight - 4;
      box.style.top = top + 'px';
    }
    function list() {
      var q = m.q.toLowerCase(), out = [];
      var add = function (kind, x) { if (!q || (x[1] + ' ' + x[0] + ' ' + x[3]).toLowerCase().indexOf(q) >= 0) out.push({ kind: kind, key: x[0], label: x[1], icon: x[2], hint: x[3] }); };
      if (m.mode === 'slash') { TYPES.forEach(function (x) { add('type', x); }); ACTIONS.forEach(function (x) { add('act', x); }); return out; }
      ACTIONS.forEach(function (x) { add('act', x); });
      [['dup', 'Duplicate', '⧉', ''], ['up', 'Move up', '↑', 'Alt + ↑'], ['down', 'Move down', '↓', 'Alt + ↓'], ['in', 'Indent', '→', 'Tab'], ['out', 'Outdent', '←', 'Shift + Tab'], ['del', 'Delete', '🗑', '']]
        .forEach(function (x) { add('op', x); });
      return out;
    }
    function draw() {
      m.items = list(); if (m.sel >= m.items.length) m.sel = 0;
      var h = '';
      if (m.mode === 'block') {
        h += '<div class="np-menu-h">Turn into</div><div class="np-menu-types">' + TYPES.map(function (x) {
          return '<button type="button" data-type="' + x[0] + '" title="' + esc(x[1]) + '" class="' + (m.block.type === x[0] ? 'on' : '') + '">' + esc(x[2]) + '</button>'; }).join('') + '</div>';
        h += '<div class="np-menu-h">Colour</div><div class="np-menu-colors">' + COLORS.map(function (c) {
          return '<button type="button" data-color="' + c + '" class="sw ' + (c ? 'c-' + c : 'none') + ((m.block.props.color || '') === c ? ' on' : '') + '" title="' + (c || 'Default') + '"></button>'; }).join('') + '</div>';
        h += '<div class="np-menu-h">Actions</div>';
      } else h += '<div class="np-menu-h">' + (m.q ? 'Results' : 'Basic blocks & actions') + '</div>';
      h += m.items.map(function (it, i) {
        return '<button type="button" class="np-mi' + (i === m.sel ? ' sel' : '') + '" data-i="' + i + '"><span class="np-mi-ic">' + esc(it.icon) + '</span><span><b>' + esc(it.label) + '</b><small>' + esc(it.hint) + '</small></span></button>';
      }).join('') || '<p class="muted small" style="padding:6px 10px">No match</p>';
      box.innerHTML = h;
      var s = box.querySelector('.np-mi.sel'); if (s && s.scrollIntoView) s.scrollIntoView({ block: 'nearest' });
    }
    m.show = function (anchor, b, mode, q) {
      m.open = true; m.block = b; m.mode = mode; m.anchor = anchor; m.q = q || ''; m.sel = 0; box.hidden = false; draw(); place(anchor);
    };
    m.hide = function () { m.open = false; box.hidden = true; };
    m.filter = function (q) { m.q = q; m.sel = 0; draw(); if (!m.items.length && q.length > 12) m.hide(); else place(m.anchor); };
    m.key = function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); m.sel = (m.sel + (e.key === 'ArrowDown' ? 1 : -1) + m.items.length) % Math.max(1, m.items.length); draw(); return true; }
      if (e.key === 'Enter' && m.items.length) { e.preventDefault(); choose(m.items[m.sel]); return true; }
      if (e.key === 'Escape') { e.preventDefault(); m.hide(); return true; }
      return false;
    };
    function choose(it) {
      var b = m.block, id = b.id, mode = m.mode; m.hide();
      if (mode === 'slash') {
        // take the "/query" out of the line
        var tx = rowOf(id).querySelector('.nb-text');
        if (tx) {
          var off = caretOffset(tx), txt = tx.textContent, at = txt.lastIndexOf('/', off - 1);
          if (at >= 0) { var r2 = document.createRange(); r2.setStart.apply(r2, pointAt(tx, at)); r2.setEnd.apply(r2, pointAt(tx, off)); r2.deleteContents(); setCaret(tx, at); }
          b.html = tx.innerHTML === '<br>' ? '' : tx.innerHTML; touch(id);
        }
        if (it.kind === 'type') {
          if (textOf(b.html).trim() && it.key !== b.type) { var nb = insertAfter(id, newBlock(it.key)); if (it.key === 'divider' || it.key === 'image') setType(nb.id, it.key); }
          else setType(id, it.key);
        } else act(it.key, b);
        return;
      }
      if (it.kind === 'act') act(it.key, b);
      else if (it.key === 'dup') { var c = JSON.parse(JSON.stringify(b)); c.id = uid(); delete c.props.done; insertAfter(id, c); }
      else if (it.key === 'up' || it.key === 'down') move(id, it.key === 'up' ? -1 : 1);
      else if (it.key === 'in' || it.key === 'out') indent(id, it.key === 'in' ? 1 : -1);
      else if (it.key === 'del') removeBlock(id);
    }
    box.addEventListener('mousedown', function (e) { e.preventDefault(); });
    box.addEventListener('click', function (e) {
      var b = m.block; if (!b) return;
      var ty = e.target.closest('[data-type]'), co = e.target.closest('[data-color]'), mi = e.target.closest('.np-mi');
      if (ty) { m.hide(); setType(b.id, ty.dataset.type); }
      else if (co) { if (co.dataset.color) b.props.color = co.dataset.color; else delete b.props.color; touch(b.id); m.hide(); rerender(b.id); }
      else if (mi) choose(m.items[+mi.dataset.i]);
    });
    document.addEventListener('mousedown', function (e) { if (m.open && !box.contains(e.target) && !(m.anchor && m.anchor.contains && m.anchor.contains(e.target))) m.hide(); });
    window.addEventListener('resize', function () { m.hide(); });
    return m;
  })();

  function slashCheck(tx, b, e) {
    var off = caretOffset(tx), txt = tx.textContent.slice(0, off);
    if (e.inputType === 'insertText' && e.data === '/' && (off === 1 || /\s/.test(txt.charAt(off - 2)))) { menu.show(tx, b, 'slash', ''); return; }
    if (menu.open && menu.mode === 'slash') {
      var at = txt.lastIndexOf('/');
      if (at < 0 || /\n/.test(txt.slice(at))) menu.hide(); else menu.filter(txt.slice(at + 1));
    }
  }

  // ---------------------------------------------------------------- per-line extras
  function act(key, b) {
    if (key === 'link') return dialog('🔗 Add a link', [['u', 'Link', 'url', 'https://'], ['t', 'Name (optional)', 'text', 'e.g. Reference reel']], function (v) {
      if (!/^https?:\/\//i.test(v.u)) v.u = 'https://' + v.u.replace(/^\/+/, '');
      (b.props.links = b.props.links || []).push({ u: v.u, t: v.t }); touch(b.id); rerender(b.id);
    });
    if (key === 'img') return pickImages(b.id);
    if (key === 'conn') return connect(b);
    if (key === 'timing') return dialog('⏱ Timing & shot note', [['sec', 'Seconds on screen', 'number', 'e.g. 4', b.props.sec || ''], ['shot', 'Shot / what to show', 'text', 'e.g. Close-up of the print, slow pan', b.props.shot || '']], function (v) {
      var s = parseFloat(v.sec); if (s > 0) b.props.sec = s; else delete b.props.sec;
      if (v.shot.trim()) b.props.shot = v.shot.trim(); else delete b.props.shot;
      touch(b.id); rerender(b.id);
    }, true);
    if (key === 'comment') return comments.open(b.id);
  }

  var fileIn = el('input'); fileIn.type = 'file'; fileIn.accept = 'image/*'; fileIn.multiple = true; fileIn.hidden = true; document.body.appendChild(fileIn);
  var pickFor = null;
  function pickImages(id) { pickFor = id; fileIn.value = ''; fileIn.click(); }
  fileIn.addEventListener('change', function () { if (pickFor && fileIn.files.length) upload(pickFor, Array.prototype.slice.call(fileIn.files)); });
  function upload(id, files) {
    var b = blocks[byId(id)]; if (!b || !files.length) return;
    setState('saving', 'Uploading ' + files.length + ' image' + (files.length > 1 ? 's' : '') + '…');
    files.reduce(function (p, f) {
      return p.then(function () {
        var fd = new FormData(); fd.append('do', 'upload'); fd.append('image', f);
        return post(fd).then(function (r) { (b.props.imgs = b.props.imgs || []).push(r.file); touch(id); rerender(id); })
          .catch(function (e) { alert('Could not upload ' + f.name + ': ' + e.message); });
      });
    }, Promise.resolve()).then(function () { schedule(true); });
  }

  function connect(b) {
    var d = modal('🔌 Connect an order or another task', '<input type="search" class="np-find" placeholder="Order number, customer or task name" autocomplete="off"><div class="np-found"></div>');
    var inp = d.querySelector('.np-find'), out = d.querySelector('.np-found'), tm = null;
    function find() {
      fetch(location.pathname + '?id=' + D.task + '&find=' + encodeURIComponent(inp.value), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        out.innerHTML = (j.results || []).map(function (x, i) { return '<button type="button" class="np-mi" data-i="' + i + '"><span class="np-mi-ic">' + (x.k === 'order' ? '📦' : '↗') + '</span><span><b>' + esc(x.label) + '</b><small>' + (x.k === 'order' ? 'Order' : 'Task page') + '</small></span></button>'; }).join('')
          || '<p class="muted small">Nothing found.</p>';
        out.onclick = function (e) {
          var bt = e.target.closest('[data-i]'); if (!bt) return; var x = j.results[+bt.dataset.i];
          conns[x.k + ':' + x.id] = { label: x.label, url: x.k === 'order' ? 'order.php?id=' + x.id : location.pathname + '?id=' + x.id };
          var list = b.props.conn = b.props.conn || [];
          if (!list.some(function (c) { return c.k === x.k && c.id === x.id; })) list.push({ k: x.k, id: x.id });
          touch(b.id); rerender(b.id); d.close();
        };
      });
    }
    inp.addEventListener('input', function () { clearTimeout(tm); tm = setTimeout(find, 250); });
    find(); setTimeout(function () { inp.focus(); }, 30);
  }

  // ---------------------------------------------------------------- comments on a line
  var comments = (function () {
    var side = el('aside', 'np-side'); side.hidden = true;
    side.innerHTML = '<div class="np-side-h"><b>💬 Comments on this line</b><button type="button" class="btn small" data-close>✕</button></div><blockquote class="np-side-q"></blockquote><ul class="history np-side-list"></ul>'
      + '<form class="np-side-form"><textarea rows="3" placeholder="Write a comment… (Ctrl+Enter to send)"></textarea><button class="btn primary">Comment</button></form>';
    document.body.appendChild(side);
    var cur = null, list = side.querySelector('.np-side-list'), ta = side.querySelector('textarea');
    function show(cs) {
      list.innerHTML = cs.map(function (c) { return '<li><span class="muted">' + esc(c.at) + '</span> · <b>' + esc(c.who) + '</b><div>' + esc(c.note).replace(/\n/g, '<br>') + '</div></li>'; }).join('') || '<li class="muted">No comments yet.</li>';
      ccount[cur] = cs.length; var r = rowOf(cur), bt = r && r.querySelector('.nb-cmt'); if (bt) { bt.textContent = cs.length ? '💬 ' + cs.length : '💬'; bt.classList.toggle('has', cs.length > 0); }
    }
    side.querySelector('[data-close]').onclick = function () { close(); };
    function close() {
      side.classList.remove('in'); cur = null;
      ed.querySelectorAll('.nb.commenting').forEach(function (x) { x.classList.remove('commenting'); });
      setTimeout(function () { if (!side.classList.contains('in')) side.hidden = true; }, 400);
    }
    side.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault(); var note = ta.value.trim(); if (!note || !cur) return;
      var bt = side.querySelector('form button'); bt.disabled = true;
      var send = function () { return post({ do: 'comment', block: cur, note: note }); };
      (pending() ? save().then(send) : send()).then(function (j) { ta.value = ''; show(j.comments); }).catch(function (er) { alert('Could not send: ' + er.message); }).then(function () { bt.disabled = false; });
    });
    ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) side.querySelector('form').requestSubmit(); });
    return {
      open: function (id) {
        cur = id; side.hidden = false; requestAnimationFrame(function () { requestAnimationFrame(function () { side.classList.add('in'); }); }); ed.querySelectorAll('.nb.commenting').forEach(function (x) { x.classList.remove('commenting'); });
        var r = rowOf(id); if (r) r.classList.add('commenting');
        var b = blocks[byId(id)]; side.querySelector('.np-side-q').textContent = textOf(b && b.html).slice(0, 200) || '(empty line)';
        list.innerHTML = '<li class="muted">Loading…</li>';
        fetch(location.pathname + '?id=' + D.task + '&comments=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (j) { show(j.comments || []); });
        setTimeout(function () { ta.focus(); }, 30);
      }
    };
  })();

  document.querySelectorAll('[data-goto-block]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault(); var r = rowOf(a.dataset.gotoBlock);
      if (!r) { alert('That line was deleted.'); return; }
      r.hidden = false; r.scrollIntoView({ behavior: 'smooth', block: 'center' }); r.classList.add('flash'); setTimeout(function () { r.classList.remove('flash'); }, 1600);
      comments.open(a.dataset.gotoBlock);
    });
  });

  // ---------------------------------------------------------------- formatting toolbar for selected text
  var bar = el('div', 'np-fmt'); bar.hidden = true;
  bar.innerHTML = [['bold', '<b>B</b>', 'Bold (Ctrl+B)'], ['italic', '<i>I</i>', 'Italic (Ctrl+I)'], ['underline', '<u>U</u>', 'Underline (Ctrl+U)'], ['strikeThrough', '<s>S</s>', 'Strike'],
    ['code', '&lt;/&gt;', 'Code'], ['mark', '<mark>H</mark>', 'Highlight'], ['link', '🔗', 'Link (Ctrl+K)']].map(function (x) { return '<button type="button" data-f="' + x[0] + '" title="' + x[2] + '">' + x[1] + '</button>'; }).join('');
  document.body.appendChild(bar);
  bar.addEventListener('mousedown', function (e) { e.preventDefault(); });
  bar.addEventListener('click', function (e) {
    var f = e.target.closest('[data-f]'); if (!f) return;
    var k = f.dataset.f;
    if (k === 'link') linkSelection();
    else if (k === 'code' || k === 'mark') wrapSel(k);
    else document.execCommand(k);
    syncSel();
  });
  function selText() { var s = window.getSelection(); if (!s.rangeCount || s.isCollapsed) return null; var n = s.anchorNode; var tx = n && (n.nodeType === 1 ? n : n.parentNode).closest('.nb-text'); return tx && ed.contains(tx) ? tx : null; }
  function syncSel() { var tx = selText(); if (!tx) return; var id = tx.closest('.nb').dataset.id; blocks[byId(id)].html = tx.innerHTML; touch(id); }
  function wrapSel(tag) {
    var s = window.getSelection(), r = s.getRangeAt(0), anc = r.commonAncestorContainer;
    var inside = (anc.nodeType === 1 ? anc : anc.parentNode).closest(tag);
    if (inside && selText()) { var p = inside.parentNode; while (inside.firstChild) p.insertBefore(inside.firstChild, inside); p.removeChild(inside); return; }
    var w = document.createElement(tag); w.appendChild(r.extractContents()); r.insertNode(w); s.removeAllRanges(); var nr = document.createRange(); nr.selectNodeContents(w); s.addRange(nr);
  }
  function linkSelection() {
    var tx = selText(); if (!tx) { flashMsg('Select some words first, then add the link.'); return; }
    var s = window.getSelection(), r = s.getRangeAt(0).cloneRange();
    dialog('🔗 Link the selected words', [['u', 'Link', 'url', 'https://']], function (v) {
      if (!/^(https?:\/\/|mailto:|tel:)/i.test(v.u)) v.u = 'https://' + v.u;
      tx.focus(); s.removeAllRanges(); s.addRange(r); document.execCommand('createLink', false, v.u); syncSel();
    });
  }
  document.addEventListener('selectionchange', function () {
    var tx = selText(); if (!tx || menu.open) { bar.hidden = true; return; }
    var rr = window.getSelection().getRangeAt(0).getBoundingClientRect(); if (!rr.width) { bar.hidden = true; return; }
    bar.hidden = false;
    bar.style.left = Math.max(8, Math.min(window.innerWidth - bar.offsetWidth - 8, rr.left + rr.width / 2 - bar.offsetWidth / 2)) + window.scrollX + 'px';
    bar.style.top = (rr.top + window.scrollY - bar.offsetHeight - 8 < window.scrollY ? rr.bottom + window.scrollY + 8 : rr.top + window.scrollY - bar.offsetHeight - 8) + 'px';
  });

  // ---------------------------------------------------------------- small dialogs
  function modal(title, body) {
    var d = el('dialog', 'np-dialog');
    d.innerHTML = '<form method="dialog"><div class="np-dialog-h"><b>' + esc(title) + '</b><button value="x" class="btn small" formnovalidate>✕</button></div><div class="np-dialog-b">' + body + '</div></form>';
    document.body.appendChild(d); d.addEventListener('close', function () { d.remove(); }); d.showModal(); return d;
  }
  function dialog(title, fields, done, allowEmpty) {
    var d = modal(title, fields.map(function (f) {
      return '<label class="field"><span class="lbl">' + esc(f[1]) + '</span><input name="' + f[0] + '" type="' + f[2] + '" placeholder="' + esc(f[3]) + '" value="' + esc(f[4] == null ? '' : f[4]) + '"' + (f[2] === 'number' ? ' step="0.5" min="0"' : '') + (!allowEmpty && f === fields[0] ? ' required' : '') + '></label>';
    }).join('') + '<div class="status-btns"><button class="btn primary" value="ok">Save</button></div>');
    var f = d.querySelector('form');
    f.addEventListener('submit', function (e) {
      if (e.submitter && e.submitter.value === 'x') return;
      var v = {}; fields.forEach(function (x) { v[x[0]] = f.elements[x[0]].value.trim(); });
      if (!allowEmpty && !v[fields[0][0]]) { e.preventDefault(); return; }
      done(v);
    });
    setTimeout(function () { f.elements[fields[0][0]].focus(); }, 30);
  }
  function lightbox(f) { var d = modal('Image', '<img class="np-big" src="' + imgUrl(f) + '" alt=""><p><a href="' + imgUrl(f) + '&dl=1">⬇ Download</a></p>'); d.classList.add('wide'); }
  function emojiPick(anchor, done) {
    var d = modal('Pick an icon', '<div class="np-emojis">' + EMOJI.map(function (e) { return '<button type="button">' + e + '</button>'; }).join('') + '</div><button type="button" class="btn small" data-none>Default icon</button>');
    d.addEventListener('click', function (e) { var bt = e.target.closest('.np-emojis button, [data-none]'); if (!bt) return; done(bt.hasAttribute('data-none') ? '' : bt.textContent); d.close(); });
  }

  // ---------------------------------------------------------------- page properties (title, icon, due, assignee …)
  function saveProp(key, value, input) {
    if (input) input.classList.add('saving');
    return post({ do: 'prop', key: key, value: value }).then(function () {
      if (input) { input.classList.remove('saving'); input.classList.add('saved'); setTimeout(function () { input.classList.remove('saved'); }, 1200); }
      if (['kind', 'due_date', 'assigned_to', 'needs_review'].indexOf(key) >= 0) { save().then(function () { location.reload(); }); }
    }).catch(function (e) { alert('Not saved: ' + e.message); });
  }
  np.querySelectorAll('[data-prop]').forEach(function (inp) {
    inp.addEventListener('change', function () { saveProp(inp.dataset.prop, inp.type === 'checkbox' ? (inp.checked ? '1' : '0') : inp.value, inp); });
  });
  var title = np.querySelector('[data-prop-title]');
  if (title) {
    var was = title.textContent;
    title.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); title.blur(); if (blocks[0]) focusBlock(blocks[0].id, false); } });
    title.addEventListener('blur', function () {
      var v = title.textContent.replace(/\s+/g, ' ').trim();
      if (!v) { title.textContent = was; return; }
      if (v !== was) { was = v; saveProp('title', v, title); document.title = v + ' · ' + document.title.split(' · ').slice(1).join(' · '); np.querySelector('.np-crumb').lastChild.nodeValue = ' ' + v; }
    });
  }
  np.addEventListener('click', function (e) {
    var b = e.target.closest('[data-np]'); if (!b) return;
    var k = b.dataset.np;
    if (k === 'icon') emojiPick(b, function (em) { saveProp('icon', em).then(function () { location.reload(); }); });
    if (k === 'copylink') { (navigator.clipboard ? navigator.clipboard.writeText(location.href) : Promise.reject()).then(function () { flashMsg('Link copied.'); }, function () { prompt('Copy this link:', location.href); }); }
    if (k === 'print') { save().then(function () { window.print(); }); }
  });

  // Someone else editing at the same time: check now and then (only when nothing is waiting to be saved here).
  setInterval(function () {
    if (document.hidden || saving || pending()) return;
    fetch(location.pathname + '?id=' + D.task + '&pull=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.rev && j.rev !== rev && !saving && !pending()) { rev = j.rev; ccount = j.comments && !Array.isArray(j.comments) ? j.comments : {}; merge(j.blocks, j.conns, 'Updated with the latest changes.'); }
    }).catch(function () {});
  }, 30000);

  renderAll();
  setState('saved', '');
})();
