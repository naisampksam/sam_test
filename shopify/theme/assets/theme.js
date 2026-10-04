(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const strings = (window.theme && window.theme.strings) || {};

  /* ------------------------------------------------------------------------
   * Money formatting (mirrors Shopify's money filters)
   * ---------------------------------------------------------------------- */
  function formatMoney(cents, format) {
    if (typeof cents === 'string') cents = cents.replace('.', '');
    const placeholder = /\{\{\s*(\w+)\s*\}\}/;
    const fmt = format || '₹{{amount}}';

    const withDelimiters = (number, precision, thousands = ',', decimal = '.') => {
      if (isNaN(number) || number == null) return '0';
      const fixed = (number / 100).toFixed(precision);
      const [whole, frac] = fixed.split('.');
      const wholeFmt = whole.replace(/(\d)(?=(\d\d\d)+(?!\d))/g, `$1${thousands}`);
      return frac ? wholeFmt + decimal + frac : wholeFmt;
    };

    const match = fmt.match(placeholder);
    let value = '';
    switch (match ? match[1] : 'amount') {
      case 'amount_no_decimals': value = withDelimiters(cents, 0); break;
      case 'amount_with_comma_separator': value = withDelimiters(cents, 2, '.', ','); break;
      case 'amount_no_decimals_with_comma_separator': value = withDelimiters(cents, 0, '.', ','); break;
      case 'amount_with_space_separator': value = withDelimiters(cents, 2, ' ', ','); break;
      default: value = withDelimiters(cents, 2);
    }
    return fmt.replace(placeholder, value);
  }

  /* ------------------------------------------------------------------------
   * Announcement bar rotator
   * ---------------------------------------------------------------------- */
  $$('[data-announcement-rotator]').forEach((bar) => {
    const messages = $$('.announcement-bar__message', bar);
    if (messages.length < 2) return;
    let index = 0;
    const speed = (parseInt(bar.dataset.speed, 10) || 4) * 1000;
    setInterval(() => {
      messages[index].classList.remove('is-active');
      index = (index + 1) % messages.length;
      messages[index].classList.add('is-active');
    }, speed);
  });

  /* ------------------------------------------------------------------------
   * Header: drawer / search panels
   * ---------------------------------------------------------------------- */
  const setHeaderBottom = () => {
    const header = $('[data-header]');
    if (!header) return;
    const bottom = Math.max(header.getBoundingClientRect().bottom, 0);
    document.documentElement.style.setProperty('--header-bottom', `${bottom}px`);
  };
  setHeaderBottom();
  window.addEventListener('resize', setHeaderBottom);
  window.addEventListener('scroll', setHeaderBottom, { passive: true });

  const headerPanels = $$('[data-drawer], [data-search]');
  headerPanels.forEach((panel) => {
    panel.addEventListener('toggle', () => {
      if (!panel.open) {
        if (panel.matches('[data-drawer]')) document.body.style.overflow = '';
        return;
      }
      setHeaderBottom();
      headerPanels.forEach((other) => { if (other !== panel) other.open = false; });
      if (panel.matches('[data-drawer]')) document.body.style.overflow = 'hidden';
      if (panel.matches('[data-search]')) {
        const input = $('input[type="search"]', panel);
        if (input) setTimeout(() => input.focus(), 50);
      }
    });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') headerPanels.forEach((p) => { p.open = false; });
  });

  /* ------------------------------------------------------------------------
   * Slideshow
   * ---------------------------------------------------------------------- */
  $$('[data-slideshow]').forEach((slideshow) => {
    const track = $('[data-slideshow-track]', slideshow);
    const slides = $$('.slideshow__slide', slideshow);
    const dots = $$('[data-slide-to]', slideshow);
    if (slides.length < 2) return;

    let current = 0;
    const goTo = (i) => {
      current = (i + slides.length) % slides.length;
      track.scrollTo({ left: track.clientWidth * current, behavior: 'smooth' });
    };

    track.addEventListener('scroll', () => {
      const i = Math.round(track.scrollLeft / track.clientWidth);
      if (i !== current) current = i;
      dots.forEach((d, n) => d.classList.toggle('is-active', n === i));
    }, { passive: true });

    dots.forEach((dot) => dot.addEventListener('click', () => goTo(parseInt(dot.dataset.slideTo, 10))));

    if (slideshow.dataset.autoplay === 'true' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      const speed = (parseInt(slideshow.dataset.speed, 10) || 5) * 1000;
      let timer = setInterval(() => goTo(current + 1), speed);
      const pause = () => clearInterval(timer);
      const resume = () => { clearInterval(timer); timer = setInterval(() => goTo(current + 1), speed); };
      slideshow.addEventListener('mouseenter', pause);
      slideshow.addEventListener('mouseleave', resume);
      slideshow.addEventListener('touchstart', pause, { passive: true });
      slideshow.addEventListener('focusin', pause);
    }
  });

  /* ------------------------------------------------------------------------
   * Quantity buttons (product page + cart)
   * ---------------------------------------------------------------------- */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-qty-minus], [data-qty-plus]');
    if (!btn) return;
    const input = $('input', btn.closest('[data-quantity]'));
    const min = parseInt(input.min, 10) || 0;
    const value = parseInt(input.value, 10) || 0;
    input.value = btn.hasAttribute('data-qty-plus') ? value + 1 : Math.max(min, value - 1);
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  /* Cart page: submit the form when a quantity changes */
  const cartForm = $('#CartForm');
  if (cartForm) {
    let timer;
    cartForm.addEventListener('change', (e) => {
      if (!e.target.matches('[data-cart-quantity]')) return;
      clearTimeout(timer);
      timer = setTimeout(() => cartForm.submit(), 500);
    });
  }

  /* ------------------------------------------------------------------------
   * Dialogs (size chart)
   * ---------------------------------------------------------------------- */
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-dialog-open]');
    if (opener) {
      const dialog = document.getElementById(opener.dataset.dialogOpen);
      if (dialog && dialog.showModal) dialog.showModal();
      return;
    }
    const closer = e.target.closest('[data-dialog-close]');
    if (closer) closer.closest('dialog').close();
    if (e.target.tagName === 'DIALOG') e.target.close();
  });

  /* ------------------------------------------------------------------------
   * Product: variant picker, gallery and media filtering
   * ---------------------------------------------------------------------- */
  $$('[data-product]').forEach((section) => {
    const variantsJson = $('[data-variants-json]', section);
    const form = $('[data-product-form]', section);
    const gallery = $('[data-gallery]', section);
    const mediaItems = gallery ? $$('[data-media-id]', gallery) : [];
    const mediaList = gallery ? $('.product__media-list', gallery) : null;
    const colorIndex = section.dataset.colorIndex === '' ? -1 : parseInt(section.dataset.colorIndex, 10);
    const filterMedia = section.dataset.filterMedia === 'true';
    const moneyFormat = section.dataset.moneyFormat;

    /* Gallery counter on mobile */
    const indexEl = gallery && $('[data-gallery-index]', gallery);
    const totalEl = gallery && $('[data-gallery-total]', gallery);
    const visibleMedia = () => mediaItems.filter((m) => !m.hidden);
    if (mediaList && indexEl) {
      mediaList.addEventListener('scroll', () => {
        const i = Math.round(mediaList.scrollLeft / mediaList.clientWidth) + 1;
        indexEl.textContent = Math.min(i, visibleMedia().length);
      }, { passive: true });
    }

    const filterMediaByColor = (color) => {
      if (!filterMedia || !color || !mediaItems.length) return;
      const key = color.toLowerCase().trim();
      const matches = mediaItems.filter((m) => m.dataset.mediaAlt === key);
      if (!matches.length) {
        mediaItems.forEach((m) => { m.hidden = false; });
      } else {
        mediaItems.forEach((m) => { m.hidden = !matches.includes(m); });
      }
      if (totalEl) totalEl.textContent = visibleMedia().length;
      if (indexEl) indexEl.textContent = 1;
      if (mediaList) mediaList.scrollLeft = 0;
    };

    if (!variantsJson || !form) {
      initProductForm(form);
      return;
    }

    const variants = JSON.parse(variantsJson.textContent);
    const fieldsets = $$('[data-option-index]', section);
    const idInput = $('[data-variant-id]', form);
    const addButton = $('[data-add-to-cart]', section);
    const addText = $('[data-add-to-cart-text]', section);
    const priceWrapper = $('[data-price-wrapper]', section);

    const selectedOptions = () => fieldsets.map((fs) => {
      const checked = $('input:checked', fs);
      return checked ? checked.value : null;
    });

    const updateAvailability = (selected) => {
      fieldsets.forEach((fs, optIndex) => {
        $$('input', fs).forEach((input) => {
          const available = variants.some((v) =>
            v.available &&
            v.options[optIndex] === input.value &&
            v.options.every((val, i) => i === optIndex || val === selected[i])
          );
          input.classList.toggle('is-unavailable', !available);
        });
      });
    };

    const renderPrice = (variant) => {
      if (!priceWrapper) return;
      const price = $('[data-price]', priceWrapper);
      if (!price) return;
      const onSale = variant.compare_at_price && variant.compare_at_price > variant.price;
      let html = `<span class="price__current">${formatMoney(variant.price, moneyFormat)}</span>`;
      if (onSale) {
        const pct = Math.round(((variant.compare_at_price - variant.price) * 100) / variant.compare_at_price);
        html += `<s class="price__compare">${formatMoney(variant.compare_at_price, moneyFormat)}</s>`;
        html += `<span class="price__discount">${(strings.discount || '__PERCENT__% OFF').replace('__PERCENT__', pct)}</span>`;
      }
      price.innerHTML = html;
      price.classList.toggle('price--on-sale', !!onSale);
    };

    const onChange = () => {
      const selected = selectedOptions();
      fieldsets.forEach((fs, i) => {
        const label = $('[data-option-value]', fs);
        if (label) label.textContent = selected[i] || '';
      });
      updateAvailability(selected);

      const variant = variants.find((v) => v.options.every((val, i) => val === selected[i]));

      if (colorIndex >= 0) filterMediaByColor(selected[colorIndex]);

      if (!variant) {
        if (addButton) addButton.disabled = true;
        if (addText) addText.textContent = strings.unavailable;
        return;
      }

      idInput.value = variant.id;
      renderPrice(variant);
      updateExtras(variant);
      if (addButton) addButton.disabled = !variant.available;
      if (addText) addText.textContent = variant.available ? strings.addToCart : strings.soldOut;

      if (variant.featured_media && !(filterMedia && colorIndex >= 0)) {
        const target = mediaItems.find((m) => m.dataset.mediaId === String(variant.featured_media.id));
        if (target && mediaList) {
          if (window.matchMedia('(min-width: 990px)').matches) {
            mediaList.prepend(target);
          } else {
            mediaList.scrollTo({ left: target.offsetLeft, behavior: 'smooth' });
          }
        }
      }

      if (section.dataset.url) {
        const url = new URL(window.location.href);
        url.searchParams.set('variant', variant.id);
        window.history.replaceState({}, '', url.toString());
      }
    };

    /* Savings line, low stock, bundle tier prices and sticky bar */
    const stockJson = $('[data-stock-json]', section);
    const stock = stockJson ? JSON.parse(stockJson.textContent) : {};
    const updateExtras = (variant) => {
      const saving = $('[data-saving]', section);
      if (saving) {
        const amount = variant.compare_at_price > variant.price ? variant.compare_at_price - variant.price : 0;
        saving.hidden = amount === 0;
        saving.textContent = (strings.youSave || '').replace('__AMOUNT__', formatMoney(amount, moneyFormat));
      }
      const low = $('[data-low-stock]', section);
      if (low) {
        const qty = stock[variant.id];
        low.hidden = !(qty > 0);
        if (qty > 0) $('[data-low-stock-text]', low).textContent = (strings.lowStock || '').replace('__COUNT__', qty);
      }
      $$('.offer-tier input', section).forEach((input) => {
        const qty = parseInt(input.value, 10);
        const disc = parseInt(input.dataset.discount, 10) || 0;
        const unit = Math.floor((variant.price * (100 - disc)) / 100);
        const tier = input.closest('.offer-tier');
        const total = $('[data-tier-total]', tier) || $('[data-tier-price]', tier);
        if (total) total.textContent = formatMoney(unit * qty, moneyFormat);
        const unitEl = $('[data-tier-unit]', tier);
        if (unitEl) unitEl.textContent = (strings.perTee || '__PRICE__').replace('__PRICE__', formatMoney(unit, moneyFormat));
      });
      const sticky = document.querySelector('[data-sticky-atc]');
      if (sticky) {
        $('[data-sticky-price]', sticky).textContent = formatMoney(variant.price, moneyFormat);
        $('[data-sticky-variant]', sticky).textContent = variant.title === 'Default Title' ? '' : variant.title;
        $('[data-sticky-add]', sticky).disabled = !variant.available;
      }
    };

    fieldsets.forEach((fs) => fs.addEventListener('change', onChange));

    /* Initial state */
    const initial = selectedOptions();
    updateAvailability(initial);
    if (colorIndex >= 0) filterMediaByColor(initial[colorIndex]);

    initProductForm(form);
  });

  /* ------------------------------------------------------------------------
   * Add to cart (AJAX) + toast
   * ---------------------------------------------------------------------- */
  function initProductForm(form) {
    if (!form) return;

    const upload = $('[data-design-upload]', form);
    if (upload) {
      upload.addEventListener('change', () => {
        const name = upload.files && upload.files[0] ? upload.files[0].name : '';
        const drop = upload.closest('.file-drop');
        const label = $('[data-file-name]', drop);
        if (name) label.textContent = name;
        drop.classList.toggle('has-file', !!name);
      });
    }

    form.addEventListener('submit', async (e) => {
      const errorEl = $('[data-form-error]', form);
      if (errorEl) errorEl.hidden = true;

      /* Validate required fields manually (form uses novalidate) */
      const invalid = $$('[required]', form).find((el) => (el.type === 'file' ? !el.files.length : !el.value));
      if (invalid) {
        e.preventDefault();
        if (errorEl) {
          errorEl.textContent = invalid.closest('.field') ? `${$('.field__label', invalid.closest('.field')).textContent.replace('*', '').trim()} is required.` : strings.error;
          errorEl.hidden = false;
        }
        invalid.focus();
        return;
      }

      /* Files are uploaded with a normal form post for maximum reliability */
      const hasFile = upload && upload.files && upload.files.length;
      if (window.theme.cartType === 'page' || hasFile) return;

      e.preventDefault();
      const button = $('[data-add-to-cart]', form);
      if (button) button.classList.add('is-loading');

      try {
        await addToCart(new FormData(form));
      } catch (err) {
        if (errorEl) {
          errorEl.textContent = err.message || strings.error;
          errorEl.hidden = false;
        }
      } finally {
        if (button) button.classList.remove('is-loading');
      }
    });

    /* Bundle tiers set the quantity */
    const qtyInput = $('input[name="quantity"]', form);
    $$('.offer-tier input', form).forEach((radio) => {
      radio.addEventListener('change', () => {
        if (qtyInput) qtyInput.value = radio.value;
      });
    });
    if (qtyInput) {
      qtyInput.addEventListener('change', () => {
        const match = $$('.offer-tier input', form).find((r) => r.value === qtyInput.value);
        $$('.offer-tier input', form).forEach((r) => { r.checked = r === match; });
      });
    }
  }

  /* ------------------------------------------------------------------------
   * Cart drawer (Section Rendering API)
   * ---------------------------------------------------------------------- */
  const DRAWER_SECTION = 'cart-drawer';

  function drawerEl() { return document.querySelector('[data-cart-drawer]'); }

  function openDrawer() {
    const drawer = drawerEl();
    if (!drawer) { window.location.href = window.theme.routes.cart; return; }
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('drawer-open');
    const panel = $('.cart-drawer__panel', drawer);
    if (panel) panel.focus();
  }

  function closeDrawer() {
    const drawer = drawerEl();
    if (!drawer) return;
    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('drawer-open');
  }

  function renderSections(sections, itemCount) {
    if (sections && sections[DRAWER_SECTION]) {
      const wasOpen = drawerEl() && drawerEl().classList.contains('is-open');
      const doc = new DOMParser().parseFromString(sections[DRAWER_SECTION], 'text/html');
      const fresh = doc.querySelector('[data-cart-drawer]');
      const current = drawerEl();
      if (fresh && current) {
        current.innerHTML = fresh.innerHTML;
        if (wasOpen) current.classList.add('is-open');
      }
    }
    if (typeof itemCount === 'number') {
      $$('[data-cart-count]').forEach((el) => { el.textContent = itemCount; });
      $$('[data-cart-count-bubble]').forEach((el) => { el.hidden = itemCount === 0; });
    }
  }

  async function addToCart(formData) {
    formData.append('sections', DRAWER_SECTION);
    formData.append('sections_url', window.location.pathname);
    const res = await fetch(`${window.theme.routes.cartAdd}.js`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: formData,
    });
    const data = await res.json();
    if (!res.ok || data.status) throw new Error(data.description || data.message || strings.error);
    const cart = await (await fetch(`${window.theme.routes.cart}.js`, { headers: { Accept: 'application/json' } })).json();
    renderSections(data.sections, cart.item_count);
    if (window.theme.cartType === 'page') {
      window.location.href = window.theme.routes.cart;
    } else {
      openDrawer();
    }
    return data;
  }

  async function changeLine(line, quantity) {
    const drawer = drawerEl();
    if (drawer) drawer.classList.add('is-loading');
    try {
      const res = await fetch(`${window.theme.routes.cartChange}.js`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ line, quantity, sections: [DRAWER_SECTION], sections_url: window.location.pathname }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.description || strings.error);
      renderSections(data.sections, data.item_count);
    } catch (err) {
      window.alert(err.message || strings.error);
    } finally {
      const d = drawerEl();
      if (d) d.classList.remove('is-loading');
    }
  }

  document.addEventListener('click', (e) => {
    const change = e.target.closest('[data-cart-change]');
    if (change && change.closest('[data-cart-drawer]')) {
      const line = parseInt(change.closest('[data-line]').dataset.line, 10);
      changeLine(line, Math.max(0, parseInt(change.dataset.cartChange, 10)));
      return;
    }
    if (e.target.closest('[data-cart-drawer-close]')) { closeDrawer(); return; }
    const cartLink = e.target.closest('.header__icon--cart');
    if (cartLink && drawerEl() && window.theme.cartType !== 'page' && !document.body.classList.contains('template-cart')) {
      e.preventDefault();
      openDrawer();
    }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeDrawer(); });

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }

  /* ------------------------------------------------------------------------
   * Collection sort auto-submit
   * ---------------------------------------------------------------------- */
  $$('[data-auto-submit]').forEach((select) => {
    select.addEventListener('change', () => select.form.submit());
  });

  /* ------------------------------------------------------------------------
   * Customer pages
   * ---------------------------------------------------------------------- */
  $$('[data-toggle-recover]').forEach((link) => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      const recover = $('#RecoverPassword');
      const login = $('#CustomerLogin');
      const showRecover = recover.hidden;
      recover.hidden = !showRecover;
      login.hidden = showRecover;
    });
  });
  if (window.location.hash === '#recover' && $('#RecoverPassword')) {
    $('#RecoverPassword').hidden = false;
    $('#CustomerLogin').hidden = true;
  }

  $$('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    });
  });

  $$('select[data-default]').forEach((select) => {
    if (select.dataset.default) select.value = select.dataset.default;
  });

  /* ------------------------------------------------------------------------
   * Quick add (product cards & upsells)
   * ---------------------------------------------------------------------- */
  const quickModal = $('[data-quick-add-modal]');

  function swatchColor(name) {
    const map = window.theme.swatches || {};
    return map[String(name).toLowerCase()] || String(name).toLowerCase().replace(/\s+/g, '');
  }

  function renderQuickAdd(product) {
    const body = $('[data-quick-add-body]', quickModal);
    const fmt = window.theme.moneyFormat;
    const first = product.variants.find((v) => v.available) || product.variants[0];
    const optionsHtml = product.options.map((opt, i) => {
      const isColor = /^colou?r$/i.test(opt.name);
      const values = opt.values.map((val) => {
        const id = `qa-${i}-${val.replace(/\W+/g, '-')}`;
        const checked = first.options[i] === val ? 'checked' : '';
        const label = isColor
          ? `<label for="${id}" class="swatch-label" title="${escapeHtml(val)}"><span class="swatch" style="--swatch:${escapeHtml(swatchColor(val))}"></span><span class="visually-hidden">${escapeHtml(val)}</span></label>`
          : `<label for="${id}" class="pill">${escapeHtml(val)}</label>`;
        return `<input type="radio" class="visually-hidden" id="${id}" name="qa-${i}" value="${escapeHtml(val)}" ${checked}>${label}`;
      }).join('');
      return `<fieldset class="option" data-qa-option="${i}"><legend class="option__label">${escapeHtml(opt.name)}: <span data-qa-value>${escapeHtml(first.options[i])}</span></legend><div class="option__values${isColor ? ' option__values--swatch' : ''}">${values}</div></fieldset>`;
    }).join('');
    const hasOptions = !(product.variants.length === 1 && product.variants[0].title === 'Default Title');

    body.innerHTML = `
      <div class="quick-add__grid">
        <div class="quick-add__media ratio ratio--portrait"><img data-qa-image src="${product.featured_image ? product.featured_image + (product.featured_image.includes('?') ? '&' : '?') + 'width=600' : ''}" alt=""></div>
        <div class="quick-add__info">
          <p class="quick-add__title">${escapeHtml(product.title)}</p>
          <div class="price" data-qa-price></div>
          ${hasOptions ? optionsHtml : ''}
          <button type="button" class="button button--full" data-qa-add>${escapeHtml(strings.addToCart)}</button>
          <p class="form-message form-message--error" data-qa-error hidden></p>
          <a href="${product.url}" class="link-underline">${escapeHtml(strings.viewDetails)}</a>
        </div>
      </div>`;

    const selected = () => $$('[data-qa-option]', body).map((fs) => ($('input:checked', fs) || {}).value);
    const update = () => {
      const sel = selected();
      $$('[data-qa-option]', body).forEach((fs, i) => { $('[data-qa-value]', fs).textContent = sel[i] || ''; });
      const v = product.variants.find((x) => x.options.every((o, i) => o === sel[i]));
      const btn = $('[data-qa-add]', body);
      const price = $('[data-qa-price]', body);
      if (!v) { btn.disabled = true; btn.textContent = strings.unavailable; price.innerHTML = ''; return null; }
      btn.disabled = !v.available;
      btn.textContent = v.available ? strings.addToCart : strings.soldOut;
      const sale = v.compare_at_price > v.price;
      price.className = `price${sale ? ' price--on-sale' : ''}`;
      price.innerHTML = `<span class="price__current">${formatMoney(v.price, fmt)}</span>${sale ? `<s class="price__compare">${formatMoney(v.compare_at_price, fmt)}</s>` : ''}`;
      if (v.featured_image && v.featured_image.src) {
        const src = v.featured_image.src;
        $('[data-qa-image]', body).src = src + (src.includes('?') ? '&' : '?') + 'width=600';
      }
      return v;
    };
    body.addEventListener('change', update);
    update();

    $('[data-qa-add]', body).addEventListener('click', async (e) => {
      const v = update();
      if (!v) return;
      const btn = e.currentTarget;
      btn.classList.add('is-loading');
      try {
        const fd = new FormData();
        fd.append('id', v.id);
        fd.append('quantity', 1);
        quickModal.close();
        await addToCart(fd);
      } catch (err) {
        if (!quickModal.open) quickModal.showModal();
        const errEl = $('[data-qa-error]', body);
        errEl.textContent = err.message || strings.error;
        errEl.hidden = false;
      } finally {
        btn.classList.remove('is-loading');
      }
    });
  }

  document.addEventListener('click', async (e) => {
    const trigger = e.target.closest('[data-quick-add]');
    if (!trigger) return;
    e.preventDefault();
    if (trigger.dataset.variantId) {
      trigger.classList.add('is-loading');
      try {
        const fd = new FormData();
        fd.append('id', trigger.dataset.variantId);
        fd.append('quantity', 1);
        await addToCart(fd);
      } catch (err) {
        window.alert(err.message || strings.error);
      } finally {
        trigger.classList.remove('is-loading');
      }
      return;
    }
    if (!quickModal || !quickModal.showModal) { window.location.href = trigger.dataset.quickAdd; return; }
    $('[data-quick-add-body]', quickModal).innerHTML = '<div class="quick-add__loading"></div>';
    quickModal.showModal();
    try {
      const res = await fetch(`${trigger.dataset.quickAdd.split('?')[0]}.js`);
      renderQuickAdd(await res.json());
    } catch (_) {
      window.location.href = trigger.dataset.quickAdd;
    }
  });

  /* ------------------------------------------------------------------------
   * Sticky add to cart
   * ---------------------------------------------------------------------- */
  const sticky = $('[data-sticky-atc]');
  const mainAdd = $('[data-product] [data-add-to-cart]');
  if (sticky && mainAdd && 'IntersectionObserver' in window) {
    const footer = $('.footer');
    let pastButton = false;
    let atFooter = false;
    const toggle = () => {
      const show = pastButton && !atFooter;
      sticky.hidden = !show;
      document.body.classList.toggle('has-sticky-atc', show);
    };
    new IntersectionObserver(([entry]) => {
      pastButton = !entry.isIntersecting && entry.boundingClientRect.top < 0;
      toggle();
    }).observe(mainAdd);
    if (footer) new IntersectionObserver(([entry]) => { atFooter = entry.isIntersecting; toggle(); }).observe(footer);
    $('[data-sticky-add]', sticky).addEventListener('click', () => {
      const form = mainAdd.closest('form');
      if (form.requestSubmit) form.requestSubmit(mainAdd); else mainAdd.click();
    });
  }

  /* ------------------------------------------------------------------------
   * Countdown timers (only to the real sale end date set in theme settings)
   * ---------------------------------------------------------------------- */
  $$('[data-countdown]').forEach((el) => {
    const end = Date.parse(el.dataset.countdown);
    if (isNaN(end)) return;
    const container = el.closest('[data-countdown-container]');
    const pad = (n) => String(n).padStart(2, '0');
    const tick = () => {
      const diff = end - Date.now();
      if (diff <= 0) {
        el.hidden = true;
        if (container) container.hidden = true;
        return false;
      }
      const s = Math.floor(diff / 1000);
      $('[data-cd-days]', el).textContent = pad(Math.floor(s / 86400));
      $('[data-cd-hours]', el).textContent = pad(Math.floor((s % 86400) / 3600));
      $('[data-cd-mins]', el).textContent = pad(Math.floor((s % 3600) / 60));
      $('[data-cd-secs]', el).textContent = pad(s % 60);
      el.hidden = false;
      if (container) container.hidden = false;
      return true;
    };
    if (tick()) {
      const timer = setInterval(() => { if (!tick()) clearInterval(timer); }, 1000);
    }
  });

  /* ------------------------------------------------------------------------
   * Copy coupon codes
   * ---------------------------------------------------------------------- */
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;
    const code = btn.dataset.copy;
    try {
      await navigator.clipboard.writeText(code);
    } catch (_) {
      const tmp = document.createElement('textarea');
      tmp.value = code;
      document.body.appendChild(tmp);
      tmp.select();
      document.execCommand('copy');
      tmp.remove();
    }
    btn.classList.add('is-copied');
    const label = btn.matches('.coupon__copy') ? btn : null;
    const original = label ? label.textContent : '';
    if (label) label.textContent = strings.copied;
    setTimeout(() => {
      btn.classList.remove('is-copied');
      if (label) label.textContent = original;
    }, 2000);
  });

  /* ------------------------------------------------------------------------
   * Sign-up popup (shown once per 7 days, or after a successful sign-up)
   * ---------------------------------------------------------------------- */
  const popup = $('[data-popup]');
  if (popup && popup.showModal) {
    const KEY = 'theme:popup-dismissed';
    const store = {
      get() { try { return parseInt(localStorage.getItem(KEY), 10) || 0; } catch (_) { return 0; } },
      set() { try { localStorage.setItem(KEY, String(Date.now())); } catch (_) { /* ignore */ } },
    };
    const posted = window.location.search.includes('customer_posted=true') && $('[data-popup-success]', popup);
    if (posted) {
      popup.showModal();
      store.set();
    } else if (Date.now() - store.get() > 7 * 24 * 3600 * 1000) {
      setTimeout(() => {
        if (!document.querySelector('dialog[open]') && !document.body.classList.contains('drawer-open')) popup.showModal();
      }, (parseInt(popup.dataset.delay, 10) || 8) * 1000);
    }
    popup.addEventListener('close', () => store.set());
    $$('[data-popup-close]', popup).forEach((b) => b.addEventListener('click', () => popup.close()));
  }

  /* ------------------------------------------------------------------------
   * Delivery estimate + pincode check
   * ---------------------------------------------------------------------- */
  $$('[data-delivery]').forEach((el) => {
    const minDays = parseInt(el.dataset.min, 10) || 3;
    const maxDays = parseInt(el.dataset.max, 10) || 7;
    const cutoff = parseInt(el.dataset.cutoff, 10) || 14;
    const addBusinessDays = (date, days) => {
      const d = new Date(date);
      let added = 0;
      while (added < days) {
        d.setDate(d.getDate() + 1);
        if (d.getDay() !== 0) added += 1; // skip Sundays
      }
      return d;
    };
    const fmt = (d) => d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
    const now = new Date();
    const start = now.getHours() >= cutoff || now.getDay() === 0 ? addBusinessDays(now, 1) : now;
    const range = (strings.deliveryBy || '__START__ – __END__')
      .replace('__START__', fmt(addBusinessDays(start, minDays)))
      .replace('__END__', fmt(addBusinessDays(start, maxDays)));
    $('[data-delivery-range]', el).textContent = range;

    const cutoffEl = $('[data-delivery-cutoff]', el);
    const countdownEl = $('[data-delivery-countdown]', el);
    const updateCutoff = () => {
      const n = new Date();
      const end = new Date(n);
      end.setHours(cutoff, 0, 0, 0);
      const diff = end - n;
      if (diff <= 0 || n.getDay() === 0) { cutoffEl.hidden = true; return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      countdownEl.textContent = `${h}h ${m}m`;
      cutoffEl.hidden = false;
    };
    if (cutoffEl) { updateCutoff(); setInterval(updateCutoff, 30000); }

    const pinForm = $('[data-pincode]', el);
    const result = $('[data-pincode-result]', el);
    if (pinForm) {
      const input = $('input', pinForm);
      try { input.value = localStorage.getItem('theme:pincode') || ''; } catch (_) { /* ignore */ }
      const check = () => {
        const pin = input.value.trim();
        result.hidden = false;
        if (!/^[1-9][0-9]{5}$/.test(pin)) {
          result.textContent = strings.invalidPincode;
          return;
        }
        try { localStorage.setItem('theme:pincode', pin); } catch (_) { /* ignore */ }
        result.textContent = (strings.deliveryTo || '').replace('__PIN__', pin).replace('__DATES__', range);
      };
      $('[data-pincode-check]', pinForm).addEventListener('click', check);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); check(); }
      });
    }
  });

  /* ------------------------------------------------------------------------
   * Product recommendations
   * ---------------------------------------------------------------------- */
  $$('[data-recommendations]').forEach(async (el) => {
    if (!el.dataset.url || el.children.length) return;
    try {
      const html = await (await fetch(el.dataset.url)).text();
      const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-recommendations]');
      if (fresh && fresh.innerHTML.trim()) el.innerHTML = fresh.innerHTML;
    } catch (_) { /* ignore */ }
  });

  /* ------------------------------------------------------------------------
   * Recently viewed (stored in this browser only)
   * ---------------------------------------------------------------------- */
  const RV_KEY = 'theme:recently-viewed';
  const rvRead = () => { try { return JSON.parse(localStorage.getItem(RV_KEY)) || []; } catch (_) { return []; } };
  const current = $('[data-recent-product]');
  if (current) {
    try {
      const item = JSON.parse(current.textContent);
      const list = rvRead().filter((p) => p.handle !== item.handle);
      list.unshift(item);
      localStorage.setItem(RV_KEY, JSON.stringify(list.slice(0, 12)));
    } catch (_) { /* ignore */ }
  }
  $$('[data-recently-viewed]').forEach((section) => {
    const limit = parseInt(section.dataset.limit, 10) || 4;
    const items = rvRead().filter((p) => p.handle !== section.dataset.current).slice(0, limit);
    if (!items.length) return;
    const fmt = window.theme.moneyFormat;
    $('[data-recently-viewed-list]', section).innerHTML = items.map((p) => `
      <li class="product-grid__item"><div class="card">
        <a href="${escapeHtml(p.url)}" class="card__media ratio ratio--portrait">${p.image ? `<img class="card__image" src="${escapeHtml(p.image)}" alt="" loading="lazy">` : ''}</a>
        <div class="card__info">
          <h3 class="card__title"><a href="${escapeHtml(p.url)}">${escapeHtml(p.title)}</a></h3>
          <div class="price${p.compare > p.price ? ' price--on-sale' : ''}"><span class="price__current">${formatMoney(p.price, fmt)}</span>${p.compare > p.price ? `<s class="price__compare">${formatMoney(p.compare, fmt)}</s>` : ''}</div>
        </div>
      </div></li>`).join('');
    section.hidden = false;
  });
})();
