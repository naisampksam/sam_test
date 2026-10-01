// Code 128 barcode as SVG (for AWB / tracking numbers on shipping labels). No external dependencies.
// Uses code set C for long digit runs (more compact) and code set B for everything else.
(function (global) {
  'use strict';

  // Bar/space widths for symbols 0..106 (106 = stop, which has an extra final bar).
  var P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
  var START_B = 104, START_C = 105, CODE_B = 100, CODE_C = 99;

  function encode(text) {
    var codes = [];
    var i = 0;
    var digitsAt = function (pos) { var n = 0; while (pos + n < text.length && /\d/.test(text[pos + n])) n++; return n; };
    var set = digitsAt(0) >= 4 ? 'C' : 'B';
    codes.push(set === 'C' ? START_C : START_B);
    while (i < text.length) {
      if (set === 'C') {
        if (digitsAt(i) >= 2) { codes.push(parseInt(text.substr(i, 2), 10)); i += 2; continue; }
        codes.push(CODE_B); set = 'B';
      }
      var run = digitsAt(i);
      if (run >= 6 || (run >= 4 && i + run === text.length)) {
        if (run % 2) { codes.push(text.charCodeAt(i) - 32); i++; }
        codes.push(CODE_C); set = 'C';
        continue;
      }
      var c = text.charCodeAt(i);
      codes.push(c >= 32 && c <= 127 ? c - 32 : 0);
      i++;
    }
    var sum = codes[0];
    for (var k = 1; k < codes.length; k++) sum += codes[k] * k;
    codes.push(sum % 103);
    codes.push(106);
    return codes;
  }

  // Draw into an <svg>: quiet zone of 10 modules on each side; stretches to the svg's CSS width.
  global.code128Svg = function (svg, text) {
    text = String(text).replace(/[^\x20-\x7e]/g, '');
    var widths = encode(text).map(function (c) { return P[c]; }).join('');
    var x = 10, rects = '';
    for (var j = 0; j < widths.length; j++) {
      var w = +widths[j];
      if (j % 2 === 0) rects += '<rect x="' + x + '" y="0" width="' + w + '" height="40"/>';
      x += w;
    }
    svg.setAttribute('viewBox', '0 0 ' + (x + 10) + ' 40');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.innerHTML = rects;
  };
})(window);
