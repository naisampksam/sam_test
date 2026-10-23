const { chromium } = require('playwright');
const OUT = __dirname + '/out/';
const pages = ['home', 'product', 'collection', 'cart', 'story', 'notfound', 'search', 'page-faq', 'page-size-guide', 'page-shipping', 'page-returns', 'page-track-order', 'page-contact', 'page-dojo'];
const widths = [360, 390, 414, 768, 1024, 1280, 1440];
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
  const report = {};
  for (const pg of pages) for (const w of widths) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    const errs = [];
    p.on('pageerror', (e) => errs.push('JS: ' + e.message));
    await p.goto('file://' + OUT + pg + '.html');
    await p.evaluate(() => document.querySelectorAll('img[loading=lazy]').forEach((i) => (i.loading = 'eager')));
    await p.waitForTimeout(500);
    const r = await p.evaluate((w) => {
      const out = [];
      const doc = document.documentElement;
      if (doc.scrollWidth > w + 1) out.push(`page scrolls sideways: ${doc.scrollWidth}px wide`);
      const desc = (el) => el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
      const scrollers = (el) => { for (let a = el.parentElement; a; a = a.parentElement) { const cs = getComputedStyle(a); if (/(auto|scroll|hidden)/.test(cs.overflowX) && a !== document.body && a !== doc) return true; } return false; };
      for (const el of document.body.querySelectorAll('*')) {
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden' || el.closest('[hidden],dialog:not([open]),.cart-drawer:not(.is-open),.visually-hidden,.marquee') || (el.closest('details:not([open])') && !el.closest('summary') && el.closest('details:not([open])') !== el)) continue;
        const rc = el.getBoundingClientRect();
        if (rc.width === 0 || rc.height === 0) continue;
        if ((rc.right > w + 1 || rc.left < -1) && !scrollers(el) && cs.position !== 'fixed') out.push(`overflows viewport: ${desc(el)} (${Math.round(rc.left)}..${Math.round(rc.right)})`);
        if (el.scrollWidth > el.clientWidth + 2 && cs.overflowX === 'visible' && el.children.length === 0 && /\S/.test(el.textContent) && cs.whiteSpace === 'nowrap') out.push(`text cut off: ${desc(el)} "${el.textContent.trim().slice(0, 30)}"`);
        if (w <= 414 && el.matches('a, button, input, select, summary, label.pill, label.swatch-label') && !el.closest('.rte, .footer__links, .card__title, .breadcrumbs, p, .header__dropdown')) {
          if ((rc.height < 24 || rc.width < 24) && !el.matches('.skip-link, input[type=hidden], input[type=radio], .slideshow__dot') && !el.closest('details:not([open]) > :not(summary)')) out.push(`small tap target: ${desc(el)} ${Math.round(rc.width)}x${Math.round(rc.height)}`);
        }
      }
      for (const img of document.images) if (img.complete && img.naturalWidth === 0 && img.getBoundingClientRect().width > 0) out.push('broken image: ' + img.src.split('/').pop());
      return [...new Set(out)];
    }, w);
    report[`${pg}@${w}`] = [...errs, ...r];
    await p.close();
  }
  for (const [k, v] of Object.entries(report)) if (v.length) console.log(k + '\n  ' + v.slice(0, 12).join('\n  '));
  console.log('checked', Object.keys(report).length, 'page/width combinations;', Object.values(report).filter((v) => v.length).length, 'with issues');
  await b.close();
})();
