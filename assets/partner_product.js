// Partner product form (Designs): T-shirt + colour drive the Design Studio (assets/studio/studio.js), where the
// partner places their design / text on the real T-shirt photo. On saving, the studio's finished mock-ups go along
// as small pictures (made[]); the art itself never leaves the phone. Or ready photos are uploaded instead.
(function () {
  var form = document.getElementById('newDesign');
  if (!form) return;
  var CAT = JSON.parse(form.dataset.catalog || '[]'), byKey = {};
  CAT.forEach(function (g) { g.products.forEach(function (p) { byKey[g.gsm + '|' + p.name] = p; }); });
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var sel = document.getElementById('pdTshirt'), cols = document.getElementById('pdColors'), sizes = document.getElementById('pdSizes');
  var studio = document.querySelector('[data-studio]'), pick = document.getElementById('pdPick');
  var S = function () { return window.ptStudio; };

  function drawColors() {
    var p = byKey[sel.value], cur = cols.dataset.color;
    if (!p) { cols.innerHTML = '<p class="pt-hint">Choose the T-shirt to see its colours.</p>'; sizes.hidden = true; sync(); return; }
    if (!p.colors.some(function (c) { return c.name === cur; })) cur = p.colors.length === 1 ? p.colors[0].name : '';
    cols.innerHTML = p.colors.map(function (c) {
      return '<label class="pt-sw" title="' + esc(c.name) + '"><input type="radio" name="color" value="' + esc(c.name) + '"' + (cur === c.name ? ' checked' : '') + ' required>'
        + '<i style="--c:' + esc(c.hex || '#ccc') + '"></i><span>' + esc(c.name) + '</span></label>'; }).join('') || '<p class="pt-hint">No colours for this T-shirt.</p>';
    sizes.textContent = 'Sizes: ' + p.sizes.join(', ') + ' — you choose sizes in each order.'; sizes.hidden = !p.sizes.length;
    cols.dataset.color = cur;
    sync();
  }
  // The studio shows the chosen T-shirt in the chosen colour.
  function sync() {
    var ok = !!(S() && S().has(sel.value) && cols.dataset.color);
    if (studio) studio.hidden = !ok;
    pick.hidden = ok || !studio;
    if (ok) S().setProduct(sel.value, cols.dataset.color);
  }
  sel.addEventListener('change', drawColors);
  cols.addEventListener('change', function (e) { if (e.target.name === 'color') { cols.dataset.color = e.target.value; sync(); } });

  // Make it here / Upload photos
  var make = document.getElementById('pdMake'), drop = document.getElementById('ptDrop');
  function upload() { return form.querySelector('[name=mk_mode]:checked').value === 'upload'; }
  function mode() { make.hidden = upload(); drop.hidden = !upload(); }
  form.addEventListener('change', function (e) { if (e.target.name === 'mk_mode') mode(); });
  var inp = drop.querySelector('input'), prev = drop.querySelector('.pt-drop-prev');
  inp.addEventListener('change', function () {
    prev.innerHTML = ''; [].slice.call(inp.files, 0, 8).forEach(function (f) { var i = new Image(); i.src = URL.createObjectURL(f); i.alt = ''; prev.appendChild(i); });
    prev.hidden = !inp.files.length; drop.querySelector('.pt-drop-empty').hidden = !!inp.files.length;
  });
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('over'); }); });

  // Saving: the studio's mock-ups go along when there is a design on it — or a plain T-shirt picture for a new
  // product that has no picture at all yet.
  var sending = false;
  form.addEventListener('submit', function (e) {
    if (sending || !S() || !studio || studio.hidden || upload()) return;
    var saved = form.querySelectorAll('[name="remove_img[]"]').length, files = inp.files.length;
    if (!S().layers() && (saved || files)) return; // nothing designed: keep what is there (a plain picture only for a new product)
    e.preventDefault();
    var btn = form.querySelector('.pt-actions .pt-btn.filled'), label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Making the mock-up…';
    S().mockups().then(function (urls) {
      var out = document.getElementById('pdMade'); out.innerHTML = '';
      urls.forEach(function (u) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'made[]'; i.value = u; out.appendChild(i); });
      sending = true; btn.disabled = false; btn.textContent = label;
      form.submit();
    }, function () { btn.disabled = false; btn.textContent = label; sending = true; form.submit(); });
  });
  drawColors(); mode();
})();
