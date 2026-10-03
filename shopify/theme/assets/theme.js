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
        const res = await fetch(`${window.theme.routes.cartAdd}.js`, {
          method: 'POST',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(form),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.description || data.message || strings.error);
        await refreshCartCount();
        showToast(data);
      } catch (err) {
        if (errorEl) {
          errorEl.textContent = err.message || strings.error;
          errorEl.hidden = false;
        }
      } finally {
        if (button) button.classList.remove('is-loading');
      }
    });
  }

  async function refreshCartCount() {
    try {
      const res = await fetch(`${window.theme.routes.cart}.js`, { headers: { Accept: 'application/json' } });
      const cart = await res.json();
      $$('[data-cart-count]').forEach((el) => { el.textContent = cart.item_count; });
      $$('[data-cart-count-bubble]').forEach((el) => { el.hidden = cart.item_count === 0; });
    } catch (_) { /* ignore */ }
  }

  const toast = $('#CartToast');
  let toastTimer;
  function showToast(item) {
    if (!toast) return;
    const holder = $('[data-cart-toast-item]', toast);
    const img = item.image ? `<img src="${item.image}${item.image.includes('?') ? '&' : '?'}width=160" alt="">` : '';
    const variant = item.variant_title ? `<br><small class="muted">${escapeHtml(item.variant_title)}</small>` : '';
    holder.innerHTML = `${img}<div><strong>${escapeHtml(item.product_title)}</strong>${variant}</div>`;
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.hidden = true; }, 6000);
  }
  if (toast) {
    $('[data-cart-toast-close]', toast).addEventListener('click', () => { toast.hidden = true; });
  }

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
})();
