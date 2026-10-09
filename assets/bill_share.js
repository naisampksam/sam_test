// Bill as a PDF or picture, made in the browser, then shared to WhatsApp.
// Phones: the share sheet opens with the file attached (pick WhatsApp → contact).
// Computers: the file is downloaded and WhatsApp opens with the message — attach the file there.
(function () {
  'use strict';
  var body = document.body;
  var base = body.dataset.assets || '';
  var fileBase = (body.dataset.file || 'bill').replace(/[^\w-]+/g, '-');
  var text = body.dataset.text || '';
  var wa = body.dataset.wa || '';

  function load(src) {
    return new Promise(function (ok, bad) {
      if (document.querySelector('script[data-src="' + src + '"]')) return ok();
      var s = document.createElement('script');
      s.src = base + src; s.dataset.src = src; s.onload = ok; s.onerror = bad;
      document.head.appendChild(s);
    });
  }

  /** Picture of the bill at A4 width, whatever the screen size. */
  function capture() {
    return load('vendor/html2canvas.min.js').then(function () {
      body.classList.add('capturing');
      var sheet = document.querySelector('.sheet');
      return window.html2canvas(sheet, { scale: 2, backgroundColor: '#ffffff', windowWidth: 900, useCORS: true })
        .then(function (c) { body.classList.remove('capturing'); return c; },
              function (e) { body.classList.remove('capturing'); throw e; });
    });
  }

  function toBlob(canvas, type) {
    return new Promise(function (ok) { canvas.toBlob(ok, type, 0.92); });
  }

  function makePng() {
    return capture().then(function (c) { return toBlob(c, 'image/png'); })
      .then(function (blob) { return new File([blob], fileBase + '.png', { type: 'image/png' }); });
  }

  function makePdf() {
    return Promise.all([capture(), load('vendor/jspdf.umd.min.js')]).then(function (r) {
      var c = r[0], JsPDF = window.jspdf.jsPDF;
      var pdf = new JsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      var margin = 8, w = 210 - margin * 2, pageH = 297 - margin * 2;
      var pxPerMm = c.width / w, sliceH = Math.floor(pageH * pxPerMm);
      // Long bills continue on more pages.
      for (var y = 0, page = 0; y < c.height; y += sliceH, page++) {
        var part = document.createElement('canvas');
        part.width = c.width; part.height = Math.min(sliceH, c.height - y);
        part.getContext('2d').drawImage(c, 0, y, c.width, part.height, 0, 0, c.width, part.height);
        if (page) pdf.addPage();
        pdf.addImage(part.toDataURL('image/jpeg', 0.92), 'JPEG', margin, margin, w, part.height / pxPerMm);
      }
      return new File([pdf.output('blob')], fileBase + '.pdf', { type: 'application/pdf' });
    });
  }

  function download(file) {
    var a = document.createElement('a');
    a.href = URL.createObjectURL(file); a.download = file.name;
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
  }

  function busy(btn, on) {
    if (on) { btn.dataset.label = btn.innerHTML; btn.innerHTML = '⏳ Making…'; btn.disabled = true; }
    else { btn.innerHTML = btn.dataset.label; btn.disabled = false; }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-share]');
    if (!btn) return;
    var kind = btn.dataset.share; // pdf | png | download
    busy(btn, true);
    (kind === 'png' ? makePng() : makePdf()).then(function (file) {
      busy(btn, false);
      if (kind === 'download') { download(file); return; }
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        return navigator.share({ files: [file], text: text, title: fileBase }).catch(function (err) {
          if (err && err.name === 'AbortError') return; // closed the share sheet
          download(file); if (text) window.open(waLink(), '_blank');
        });
      }
      // Computer: save the file, open WhatsApp with the message; attach the saved file in the chat.
      download(file);
      if (!text) return; // customer link: just the download
      window.open(waLink(), '_blank');
      var note = document.getElementById('shareNote');
      if (note) { note.hidden = false; note.textContent = '✓ ' + file.name + ' saved to your downloads. In WhatsApp, tap 📎 and attach it to the message.'; }
    }).catch(function () {
      busy(btn, false);
      window.alert('Could not make the file. Please use Print → Save as PDF instead.');
    });
  });

  function waLink() { return 'https://wa.me/' + wa + '?text=' + encodeURIComponent(text); }

  // Opened from the bill page with ?send=pdf|png: point at the right button.
  var want = new URLSearchParams(location.search).get('send');
  var target = want && document.querySelector('[data-share="' + want + '"]');
  if (target) target.classList.add('pulse');
})();
