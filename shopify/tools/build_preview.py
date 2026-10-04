#!/usr/bin/env python3
"""Build a static, clickable preview of the theme (shopify/preview/).

The preview uses the theme's real base.css and theme.js. Shopify endpoints
(/cart/add.js, /cart/change.js, /products/<handle>.js) are simulated in the
browser so quick add, the cart drawer and offer progress can be tried without
a Shopify store. Markup mirrors the Liquid sections; data comes from
build_products_csv.py. Run:  python3 shopify/tools/build_preview.py
"""

import html
import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
import build_products_csv as catalog  # noqa: E402

ROOT = os.path.normpath(os.path.join(os.path.dirname(__file__), ".."))
OUT = os.path.join(ROOT, "preview")
A = "../theme/assets/"
P = "../product-images/"

SWATCH = {
    "black": "#111111", "white": "#FFFFFF", "beige": "#E8DCC6", "bottle green": "#0F4D2C",
    "chocolate": "#5A3A26", "lavender": "#C3A6E8", "navy": "#1B2340", "royal blue": "#1E3FD0", "red": "#D7261E",
}
TIERS = [(2, 10), (3, 15), (5, 20)]
COUPONS = [("WELCOME10", "Extra 10% off your first order"), ("PREPAID50", "Flat ₹50 off on prepaid orders"),
           ("FREESHIP", "Free shipping on any order")]

ICONS = {
    "menu": '<path d="M3 6h18M3 12h18M3 18h18"/>',
    "search": '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    "account": '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
    "cart": '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
    "arrow": '<path d="M5 12h14M13 6l6 6-6 6"/>',
    "truck": '<path d="M2 6h12v10H2zM14 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    "cash": '<rect x="2" y="6" width="20" height="12" rx="1"/><circle cx="12" cy="12" r="3"/>',
    "return": '<path d="M4 9h11a5 5 0 0 1 0 10H8"/><path d="M8 5 4 9l4 4"/>',
    "shirt": '<path d="M8 3 3 6l2 5 3-1v11h8V10l3 1 2-5-5-3a4 4 0 0 1-8 0Z"/>',
    "ruler": '<rect x="2" y="7" width="20" height="10" rx="1"/><path d="M6 7v4M10 7v3M14 7v4M18 7v3"/>',
    "chevron": '<path d="m6 9 6 6 6-6"/>',
    "lock": '<rect x="4" y="10" width="16" height="11" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    "tag": '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Z"/><circle cx="8" cy="8" r="1.5"/>',
    "clock": '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    "pin": '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12Z"/><circle cx="12" cy="9" r="2.5"/>',
    "check": '<path d="m5 12 5 5 9-10"/>',
    "cross": '<path d="M7 7l10 10M17 7 7 17"/>',
    "close": '<path d="M6 6l12 12M18 6 6 18"/>',
}


def icon(name):
    cls = "icon icon-chevron" if name == "chevron" else "icon"
    return f'<svg class="{cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">{ICONS[name]}</svg>'


def money(cents):
    rupees = cents / 100
    return f"₹{rupees:,.2f}"


def e(text):
    return html.escape(str(text), quote=True)


# ---------------------------------------------------------------------------
# Catalogue -> product JSON (shape of Shopify's /products/<handle>.js)
# ---------------------------------------------------------------------------
def build_products():
    products, vid = [], 1000
    for p in catalog.PRODUCTS:
        opt = p.get("option_name", "Color")
        price = int(float(p["price"]) * 100)
        compare = int(float(p["compare_at"]) * 100)
        images = [f"{P}{img}.jpg" for imgs in p["colors"].values() for img in imgs]
        variants = []
        for color, imgs in p["colors"].items():
            for size in catalog.SIZES:
                vid += 1
                variants.append({
                    "id": vid, "title": f"{color} / {size}", "options": [color, size], "available": True,
                    "price": price, "compare_at_price": compare, "featured_image": {"src": f"{P}{imgs[0]}.jpg"},
                })
        products.append({
            "handle": p["handle"], "title": p["title"], "url": f"product.html?p={p['handle']}",
            "tags": [t.strip() for t in p["tags"].split(",")], "price": price, "compare_at_price": compare,
            "featured_image": images[0], "images": images,
            "options": [{"name": opt, "values": list(p["colors"].keys())}, {"name": "Size", "values": catalog.SIZES}],
            "variants": variants, "body": p["body"],
            "media": [(f"{P}{img}.jpg", color) for color, imgs in p["colors"].items() for img in imgs],
        })
    return products


# ---------------------------------------------------------------------------
# Shared pieces
# ---------------------------------------------------------------------------
def card(p):
    pct = round((p["compare_at_price"] - p["price"]) * 100 / p["compare_at_price"])
    second = p["images"][1] if len(p["images"]) > 1 else None
    opt = p["options"][0]
    swatches = ""
    if opt["name"] == "Color" and len(opt["values"]) > 1:
        dots = "".join(f'<li><span class="swatch swatch--sm" style="--swatch:{SWATCH.get(v.lower(), "#ccc")}" title="{e(v)}"></span></li>' for v in opt["values"][:6])
        more = f'<li class="card__swatches-more">+{len(opt["values"]) - 6}</li>' if len(opt["values"]) > 6 else ""
        swatches = f'<ul class="card__swatches">{dots}{more}</ul>'
    badges = f'<span class="badge badge--sale">{pct}% OFF</span>'
    if "bestseller" in p["tags"]:
        badges += '<span class="badge badge--accent">Bestseller</span>'
    elif "new" in p["tags"]:
        badges += '<span class="badge">New</span>'
    if "combo" in p["tags"]:
        badges += '<span class="badge badge--offer">Combo</span>'
    offer = "" if "no-offer" in p["tags"] else f'<p class="card__offer">Buy {TIERS[0][0]}, get {TIERS[0][1]}% OFF</p>'
    hover = f'<img class="card__image card__image--hover" src="{second}" alt="" loading="lazy">' if second else ""
    return f'''<li class="product-grid__item"><div class="card">
  <div class="card__media-wrap">
    <a href="{p["url"]}" class="card__media ratio ratio--portrait" aria-label="{e(p["title"])}">
      <img class="card__image" src="{p["featured_image"]}" alt="{e(p["title"])}" loading="lazy">{hover}
      <div class="card__badges">{badges}</div>
    </a>
    <button type="button" class="card__quick-add" data-quick-add="/products/{p["handle"]}" aria-label="Quick add {e(p["title"])}">
      <span class="card__quick-add-plus" aria-hidden="true">+</span><span class="card__quick-add-text">Quick add</span></button>
  </div>
  <div class="card__info">
    <h3 class="card__title"><a href="{p["url"]}">{e(p["title"])}</a></h3>
    <div class="price price--on-sale"><span class="price__current">{money(p["price"])}</span><s class="price__compare">{money(p["compare_at_price"])}</s></div>
    {offer}{swatches}
  </div>
</div></li>'''


def grid(heading, products, link="#"):
    cards = "".join(card(p) for p in products)
    return f'''<section class="section page-width" style="--columns:4;--columns-mobile:2">
  <div class="section__header section__header--split"><h2 class="section__heading">{heading}</h2>
  <a href="{link}" class="link-arrow">View all {icon("arrow")}</a></div>
  <ul class="product-grid product-grid--slider">{cards}</ul></section>'''


def countdown(label, style="inline"):
    units = "".join(f'<span class="countdown__unit"><b data-cd-{k}>00</b><small>{t}</small></span>'
                    for k, t in [("days", "Days"), ("hours", "Hrs"), ("mins", "Min"), ("secs", "Sec")])
    return f'<div class="countdown countdown--{style}" data-countdown="2026-10-31T23:59:00+05:30" hidden><span class="countdown__label">{label}</span><span class="countdown__time">{units}</span></div>'


def coupons():
    items = "".join(f'<li class="coupon"><span class="coupon__info"><span class="coupon__code">{c}</span><span class="coupon__text">{t}</span></span><button type="button" class="coupon__copy" data-copy="{c}">Copy</button></li>' for c, t in COUPONS)
    return f'<div class="coupons"><p class="coupons__heading">Available offers</p><ul class="coupons__list">{items}</ul></div>'


def head(title):
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{title}</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Assistant:wght@400;700;800&display=swap" rel="stylesheet">
<style>:root{{--font-body:Assistant,sans-serif;--font-heading:Assistant,sans-serif;--font-heading-weight:800;--color-bg:#fff;--color-text:#111;--color-muted:#737373;--color-border:#dbdbdb;--color-surface:#f3f3f3;--color-accent:#111;--color-sale:#D7261E;--color-button:#111;--color-button-text:#fff;--page-width:1400px;--radius:0px}}
.preview-note{{background:#FFE14D;color:#111;text-align:center;font-size:12px;padding:6px 16px}}</style>
<link rel="icon" href="{A}favicon.png"><link rel="stylesheet" href="{A}base.css"></head>'''


def header(template):
    links = "".join(f'<li class="header__menu-item"><a href="index.html">{t}</a></li>' for t in ["New Arrivals", "Oversized", "Acid Wash", "Combos", "Custom Print", "Bulk Orders"])
    return f'''<body class="template-{template}">
<p class="preview-note">Static design preview with sample data. Cart actions are simulated in your browser.</p>
<div class="countdown-bar" data-countdown-container hidden style="--cb-bg:#D7261E;--cb-text:#fff"><div class="page-width countdown-bar__inner">{countdown("Festive Sale: up to 20% off ends in")}<a href="index.html" class="countdown-bar__link">Shop now →</a></div></div>
<div class="announcement-bar" data-announcement-rotator data-speed="4"><div class="page-width announcement-bar__inner">
<p class="announcement-bar__message is-active">Buy 2 get 10% off · Buy 3 get 15% off</p>
<p class="announcement-bar__message">Free shipping on orders above ₹999</p>
<p class="announcement-bar__message">Extra 10% off your first order: WELCOME10</p></div></div>
<div class="section-header"><header class="header header--sticky" data-header><div class="page-width header__inner">
<details class="header__drawer" data-drawer><summary class="header__icon">{icon("menu")}</summary></details>
<a href="index.html" class="header__logo"><img src="{A}logo.png" alt="HAIKUFIT" class="header__logo-img" style="width:170px"></a>
<nav class="header__nav"><ul class="header__menu">{links}</ul></nav>
<div class="header__icons"><span class="header__icon">{icon("search")}</span><a class="header__icon header__icon--account">{icon("account")}</a>
<a href="#" class="header__icon header__icon--cart" aria-label="Cart">{icon("cart")}<span class="cart-count" data-cart-count-bubble hidden><span data-cart-count>0</span></span></a></div>
</div></header></div><main>'''


def footer(products):
    data = {p["handle"]: {k: p[k] for k in ("handle", "title", "url", "featured_image", "options", "variants")} for p in products}
    return f'''</main>
<section class="newsletter"><div class="page-width newsletter__inner"><h2 class="section__heading">Join the club</h2><div class="rte"><p>Get 10% off your first order, early access to drops and members-only offers.</p></div><div class="newsletter__field"><input type="email" placeholder="Your email address"><button class="button">Subscribe</button></div></div></section>
<footer class="footer"><div class="page-width footer__grid"><div class="footer__about"><a href="index.html" class="footer__logo"><img src="{A}logo-white.png" alt="HAIKUFIT"></a><div class="rte"><p>Premium oversized, acid wash &amp; custom printed t-shirts. Designed for everyday comfort.</p></div></div>
<div class="footer__block"><p class="footer__heading">Shop</p><ul class="footer__links"><li><a href="#">Oversized</a></li><li><a href="#">Acid Wash</a></li><li><a href="#">Combos</a></li><li><a href="#">Custom Print</a></li></ul></div>
<div class="footer__block"><p class="footer__heading">Help</p><ul class="footer__links"><li><a href="#">Track order</a></li><li><a href="#">Returns &amp; exchange</a></li><li><a href="#">Shipping policy</a></li><li><a href="#">Contact us</a></li></ul></div>
<div class="footer__block"><p class="footer__heading">Contact us</p><div class="rte"><p>Mon–Sat, 10am–7pm<br>support@haikufit.in</p></div></div></div>
<div class="page-width footer__bottom"><p>© 2026 HAIKUFIT. All rights reserved.</p></div></footer>
<a class="whatsapp-float" href="#"><svg class="icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Z"/></svg></a>
<div id="shopify-section-cart-drawer"><div class="cart-drawer" data-cart-drawer aria-hidden="true"></div></div>
<dialog class="quick-add" id="QuickAdd" data-quick-add-modal><button type="button" class="popup__close" data-dialog-close aria-label="Close">{icon("close")}</button><div class="quick-add__body" data-quick-add-body></div></dialog>
<script>window.PREVIEW_PRODUCTS = {json.dumps(data)};</script>
<script src="preview-shim.js"></script>
<script src="{A}theme.js"></script>
</body></html>'''


# ---------------------------------------------------------------------------
# Pages
# ---------------------------------------------------------------------------
def homepage(products):
    by = {p["handle"]: p for p in products}
    best = [p for p in products if "bestseller" in p["tags"]][:8]
    new = [p for p in products if "new" in p["tags"]][:4]
    combos = [p for p in products if "combo" in p["tags"]]
    tiles = "".join(f'<a href="#" class="tile"><div class="tile__media ratio ratio--tall"><img src="{A}{f}" alt="" loading="lazy"></div><span class="tile__title">{t} {icon("arrow")}</span></a>' for t, f in [("Oversized Tees", "tile-oversized.jpg"), ("Acid Wash", "tile-acid-wash.jpg"), ("Regular Fit", "tile-regular.jpg"), ("Full Sleeve", "tile-full-sleeve.jpg")])
    marq = "".join(f'<span class="marquee__item">{t}</span><span class="marquee__sep">✦</span>' for t in ["Buy 2 get 10% off", "Buy 3 get 15% off", "Buy 5 get 20% off", "Free shipping above ₹999", "COD available", "Easy 7-day exchange"])
    tiers = "".join(f'<div class="offer-card{" offer-card--featured" if i == 1 else ""}"><p class="offer-card__buy">Buy {q}</p><p class="offer-card__off">{d}%<span>OFF</span></p></div>' for i, (q, d) in enumerate(TIERS))
    prices = "".join(f'<a href="#" class="price-tile"><span class="price-tile__label">{l}</span><span class="price-tile__price">{v}</span><span class="price-tile__cta">Shop now {icon("arrow")}</span></a>' for l, v in [("Under", "₹499"), ("Under", "₹699"), ("Under", "₹999"), ("Combos from", "₹799")])
    trust = "".join(f'<div class="trust__item"><span class="trust__icon">{icon(i)}</span><div><p class="trust__title">{t}</p><p class="trust__text">{d}</p></div></div>' for i, t, d in [("truck", "Free shipping", "On orders above ₹999"), ("cash", "Cash on delivery", "Pay when it arrives"), ("return", "Easy returns", "7-day hassle-free exchange"), ("shirt", "Premium cotton", "100% cotton, 240+ GSM")])
    prints = "".join(f'<div class="print-method"><div class="print-method__media ratio ratio--square"><img src="{A}print-{k}.jpg" alt="" loading="lazy"></div><h3 class="print-method__title">{t}</h3><p class="print-method__text">{d}</p></div>' for k, t, d in [("dtf", "DTF Print", "Vibrant, full-color prints with fine detail."), ("screen", "Screen Print", "Bold, long-lasting solid colors."), ("puff", "Puff Print", "Raised 3D texture that stands out."), ("hd", "HD Print", "High-density, tonal raised print."), ("embroidery", "Embroidery", "Stitched logos & patches built to last.")])
    rows = "".join(f'<tr><th scope="row">{f}</th><td class="compare__us">{icon("check")}</td><td>{icon("check" if t else "cross")}</td></tr>' for f, t in [("240+ GSM heavyweight cotton", False), ("Bio-washed, pre-shrunk fabric", False), ("Bundle discounts up to 20%", False), ("Cash on delivery", True), ("7-day easy exchange", False), ("Custom printing, no minimum", False)])
    reviews = "".join(f'<figure class="testimonial"><div class="testimonial__stars">★★★★★</div><blockquote class="testimonial__text">{t}</blockquote><figcaption class="testimonial__author"><span><strong>Customer name</strong><small class="muted">{m}</small></span></figcaption></figure>' for t, m in [("Sample review — replace with a real one. The 240 GSM fabric feels premium and the oversized fit is spot on.", "City · Oversized Tee"), ("Sample review — replace with a real one. Ordered 3 tees with the bundle offer, colors are exactly like the photos.", "City · Bundle of 3"), ("Sample review — replace with a real one. Got my custom puff print for our team event, quality was great.", "City · Custom Print")])
    faqs = "".join(f'<details class="accordion"><summary>{q} {icon("chevron")}</summary><div class="accordion__content rte"><p>{a}</p></div></details>' for q, a in [("How does the Buy 2 / Buy 3 offer work?", "Add any 2 tees to get 10% off, any 3 to get 15% off, or 5+ to get 20% off. Mix and match. Applied automatically at checkout."), ("Is Cash on Delivery available?", "Yes, COD is available across India."), ("What size should I buy?", "Take your regular size for a relaxed fit, or one size down for a closer fit."), ("How long does delivery take?", "Dispatched within 24–48 hours, delivered in 3–6 business days.")])
    body = f'''
<section class="slideshow slideshow--large"><div class="slideshow__track"><div class="slideshow__slide">
<div class="slideshow__media"><img class="slideshow__image" src="{A}hero-oversized.jpg" alt=""></div>
<div class="slideshow__content slideshow__content--left slideshow__content--dark"><p class="slideshow__subheading">Buy 2 get 10% off · Buy 3 get 15% off</p><h2 class="slideshow__heading">Oversized. Heavyweight. Everyday.</h2><a href="#" class="button">Shop bestsellers</a></div>
</div></div><div class="slideshow__dots"><button class="slideshow__dot is-active"></button><button class="slideshow__dot"></button></div></section>
<div class="marquee marquee--dark"><div class="marquee__track"><div class="marquee__group">{marq}</div><div class="marquee__group">{marq}</div></div></div>
<section class="section page-width" style="--columns:4;--columns-mobile:2"><div class="section__header"><h2 class="section__heading">Shop by category</h2></div><div class="tiles">{tiles}</div></section>
{grid("Bestsellers", best)}
<section class="offer-banner offer-banner--dark"><div class="page-width offer-banner__inner">
<div class="offer-banner__intro"><p class="eyebrow">Limited time offer</p><h2 class="offer-banner__heading">Buy more, save more</h2>{countdown("Sale ends in", "boxes")}</div>
<div class="offer-banner__tiers">{tiers}</div>
<div class="offer-banner__cta"><button type="button" class="offer-banner__coupon" data-copy="WELCOME10">{icon("tag")}<span>Use code <b>WELCOME10</b></span><small>Tap to copy</small></button><a href="#" class="button button--light">Shop the offer</a><p class="offer-banner__note">Mix &amp; match any tees. Discount applied automatically at checkout.</p></div>
</div></section>
{grid("New arrivals", new)}
<section class="section page-width"><div class="section__header"><h2 class="section__heading">Shop by price</h2></div><div class="price-tiles">{prices}</div></section>
{grid("Combo packs · Save more", combos)}
<section class="trust trust--boxed"><div class="page-width trust__grid">{trust}</div></section>
<section class="section page-width print-methods" style="--columns:5"><div class="section__header section__header--center"><p class="eyebrow">Custom printing</p><h2 class="section__heading">Your design. Our tees.</h2><div class="section__text rte"><p>Upload your artwork and pick a print style.</p></div></div><div class="print-methods__grid">{prints}</div><div class="section__footer"><a href="product.html?p=custom" class="button">Design your tee</a></div></section>
<section class="section page-width why-us"><div class="section__header section__header--center"><p class="eyebrow">The difference</p><h2 class="section__heading">Why choose us</h2></div><div class="table-wrap"><table class="compare"><thead><tr><th></th><th class="compare__us">HAIKUFIT</th><th>Regular brands</th></tr></thead><tbody>{rows}</tbody></table></div></section>
<section class="section page-width testimonials"><div class="section__header section__header--center"><p class="eyebrow">Reviews</p><h2 class="section__heading">What our customers say</h2></div><div class="testimonials__track">{reviews}</div></section>
<section class="section page-width page-width--narrow faq"><div class="section__header section__header--center"><h2 class="section__heading">Frequently asked questions</h2></div>{faqs}</section>'''
    return head("Homepage preview") + header("index") + body + footer(products)


def product_page(products):
    p = products[0]
    variants = p["variants"]
    first = variants[1]  # Black / M
    media = "".join(f'<li class="product__media-item" data-media-id="{i}" data-media-alt="{e(alt.lower())}"><div class="ratio ratio--portrait"><img src="{src}" alt="{e(alt)}"></div></li>' for i, (src, alt) in enumerate(p["media"]))
    sw = "".join(f'<input type="radio" class="visually-hidden" id="c{i}" name="o0" value="{e(v)}" {"checked" if i == 0 else ""}><label for="c{i}" class="swatch-label" title="{e(v)}"><span class="swatch" style="--swatch:{SWATCH.get(v.lower(), "#ccc")}"></span></label>' for i, v in enumerate(p["options"][0]["values"]))
    sz = "".join(f'<input type="radio" class="visually-hidden" id="s{i}" name="o1" value="{v}" {"checked" if v == "M" else ""}><label for="s{i}" class="pill">{v}</label>' for i, v in enumerate(catalog.SIZES))
    tiers = f'<label class="offer-tier"><input type="radio" name="offer-tier" value="1" data-discount="0" checked><span class="offer-tier__body"><span class="offer-tier__title">Buy 1</span><span class="offer-tier__sub">Standard price</span></span><span class="offer-tier__price" data-tier-price>{money(first["price"])}</span></label>'
    for i, (q, d) in enumerate(TIERS):
        unit = first["price"] * (100 - d) // 100
        flag = '<span class="offer-tier__flag">Most popular</span>' if i == 1 else ""
        tiers += f'<label class="offer-tier{" offer-tier--popular" if i == 1 else ""}"><input type="radio" name="offer-tier" value="{q}" data-discount="{d}">{flag}<span class="offer-tier__body"><span class="offer-tier__title">Buy {q} <em>Get {d}% OFF</em></span><span class="offer-tier__sub" data-tier-unit>{money(unit)} per tee</span></span><span class="offer-tier__price" data-tier-total>{money(unit * q)}</span></label>'
    saving = first["compare_at_price"] - first["price"]
    pct = round(saving * 100 / first["compare_at_price"])
    rows = "".join(f"<tr>{''.join(f'<td>{c}</td>' for c in r.split(','))}</tr>" for r in ["S,42,27.5,21,8.5", "M,44,28.5,22,9", "L,46,29.5,23,9.5", "XL,48,30.5,24,10", "XXL,50,31.5,25,10.5"])
    others = [x for x in products[1:] if x["handle"] != p["handle"]][:4]
    body = f'''
<section class="product page-width" data-product data-url="product.html" data-money-format="₹{{{{amount}}}}" data-color-index="0" data-filter-media="true">
  <div class="product__gallery" data-gallery><ul class="product__media-list">{media}</ul>
  <p class="product__gallery-count"><span data-gallery-index>1</span> / <span data-gallery-total>{len(p["media"])}</span></p></div>
  <div class="product__info"><form class="product__form" data-product-form novalidate>
    <input type="hidden" name="id" value="{first["id"]}" data-variant-id>
    <h1 class="product__title">{e(p["title"])}</h1>
    <div class="product__price" data-price-wrapper><div class="price price--on-sale" data-price><span class="price__current">{money(first["price"])}</span><s class="price__compare">{money(first["compare_at_price"])}</s><span class="price__discount">{pct}% OFF</span></div>
      <p class="product__saving" data-saving>You save {money(saving)}</p><p class="product__tax">Inclusive of all taxes</p></div>
    <div class="product__countdown">{countdown("Sale ends in")}</div>
    <div class="product__options">
      <fieldset class="option" data-option-index="0"><legend class="option__label">Color: <span data-option-value>Black</span></legend><div class="option__values option__values--swatch">{sw}</div></fieldset>
      <fieldset class="option" data-option-index="1"><legend class="option__label">Size: <span data-option-value>M</span></legend><div class="option__values">{sz}</div></fieldset>
    </div>
    <script type="application/json" data-variants-json>{json.dumps(variants)}</script>
    <div class="size-chart"><button type="button" class="link-underline size-chart__open" data-dialog-open="SizeChart">{icon("ruler")} Size chart</button>
      <dialog class="dialog" id="SizeChart"><div class="dialog__header"><p class="dialog__title">Size chart (inches)</p><button type="button" class="dialog__close" data-dialog-close>{icon("close")}</button></div>
      <div class="table-wrap"><table class="size-table"><tr><th>Size</th><th>Chest</th><th>Length</th><th>Shoulder</th><th>Sleeve</th></tr>{rows}</table></div></dialog></div>
    <div class="offer-tiers" data-offer-tiers><p class="offer-tiers__heading"><span>Bundle &amp; Save</span><span class="offer-tiers__note">Mix &amp; match any styles</span></p><div class="offer-tiers__list">{tiers}</div><p class="offer-tiers__footnote">Mix &amp; match any tees. Discount applied automatically at checkout.</p></div>
    <div class="product__buy"><div class="quantity" data-quantity><button type="button" class="quantity__button" data-qty-minus>−</button><input type="number" name="quantity" value="1" min="1" class="quantity__input"><button type="button" class="quantity__button" data-qty-plus>+</button></div>
      <button type="submit" name="add" class="button button--full" data-add-to-cart><span data-add-to-cart-text>Add to cart</span></button>
      <p class="form-message form-message--error" data-form-error hidden></p></div>
    <div class="delivery" data-delivery data-min="3" data-max="6" data-cutoff="14">
      <p class="delivery__line">{icon("truck")}<span>Estimated delivery: <strong data-delivery-range>3–6 business days</strong></span></p>
      <p class="delivery__line delivery__cutoff" data-delivery-cutoff hidden>{icon("clock")}<span>Order within <strong data-delivery-countdown></strong> for same-day dispatch</span></p>
      <form class="pincode" data-pincode>{icon("pin")}<input type="text" inputmode="numeric" maxlength="6" placeholder="Enter pincode"><button type="submit" class="link-underline">Check</button></form>
      <p class="delivery__result muted" data-pincode-result hidden></p></div>
    {coupons()}
    <ul class="product__trust"><li>{icon("truck")} Free shipping above ₹999</li><li>{icon("cash")} Cash on delivery available</li><li>{icon("return")} Easy 7-day exchange</li></ul>
    <ul class="product__highlights"><li>240 GSM, 100% cotton, bio-washed</li><li>Pre-shrunk &amp; colorfast</li><li>Drop shoulder, relaxed oversized fit</li></ul>
    <details class="accordion" open><summary>Description {icon("chevron")}</summary><div class="accordion__content rte">{p["body"]}</div></details>
    <details class="accordion"><summary>Fabric &amp; care {icon("chevron")}</summary><div class="accordion__content rte"><p>Machine wash cold, inside out.</p></div></details>
    <details class="accordion"><summary>Shipping &amp; returns {icon("chevron")}</summary><div class="accordion__content rte"><p>Dispatched in 24–48 hours.</p></div></details>
  </form></div>
</section>
<div class="sticky-atc" data-sticky-atc hidden><div class="page-width sticky-atc__inner"><div class="sticky-atc__product"><img src="{p["featured_image"]}" alt=""><div><p class="sticky-atc__title">{e(p["title"])}</p><p class="sticky-atc__variant muted" data-sticky-variant>Black / M</p></div></div><div class="sticky-atc__price" data-sticky-price>{money(first["price"])}</div><button type="button" class="button" data-sticky-add>Add to cart</button></div></div>
{grid("You may also like", others)}'''
    return head("Product page preview") + header("product") + body + footer(products)


SHIM = r"""/* Preview-only: simulates Shopify cart endpoints in the browser. */
(() => {
  const tiers = [[2, 10], [3, 15], [5, 20]];
  const threshold = 99900;
  const fmt = (c) => '₹' + (c / 100).toLocaleString('en-IN', { minimumFractionDigits: 2 });
  const byId = {};
  Object.values(window.PREVIEW_PRODUCTS).forEach((p) => p.variants.forEach((v) => { byId[v.id] = { p, v }; }));
  const cart = { items: [] };
  const count = () => cart.items.reduce((n, i) => n + i.quantity, 0);
  const total = () => cart.items.reduce((n, i) => n + i.quantity * i.v.price, 0);

  window.theme = {
    routes: { cart: '/cart', cartAdd: '/cart/add', cartChange: '/cart/change' },
    strings: { addToCart: 'Add to cart', soldOut: 'Sold out', unavailable: 'Unavailable', error: 'Something went wrong.',
      discount: '__PERCENT__% OFF', youSave: 'You save __AMOUNT__', lowStock: 'Hurry, only __COUNT__ left in stock', copied: 'Copied!',
      perTee: '__PRICE__ per tee', viewDetails: 'View full details', deliveryBy: '__START__ – __END__',
      deliveryTo: 'Estimated delivery to __PIN__: __DATES__', invalidPincode: 'Please enter a valid 6-digit pincode.' },
    cartType: 'drawer', moneyFormat: '₹{{amount}}',
    swatches: { black: '#111111', white: '#FFFFFF', beige: '#E8DCC6', 'bottle green': '#0F4D2C', chocolate: '#5A3A26', lavender: '#C3A6E8', navy: '#1B2340', 'royal blue': '#1E3FD0', red: '#D7261E' },
  };

  const icon = (d) => `<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">${d}</svg>`;
  function drawer() {
    const n = count();
    let body;
    if (!n) {
      body = `<div class="cart-drawer__empty"><p>Your cart is empty.</p><p class="cart-drawer__empty-offer">Buy 2, get 10% OFF</p><a href="index.html" class="button">Continue shopping</a></div>`;
    } else {
      const next = tiers.find(([q]) => n < q);
      const best = tiers.filter(([q]) => n >= q).pop();
      const max = tiers[tiers.length - 1][0];
      const msg = next ? `Add ${next[0] - n} more item${next[0] - n > 1 ? 's' : ''} to get ${next[1]}% OFF` : `🎉 You've unlocked ${best[1]}% OFF — applied at checkout`;
      const steps = tiers.map(([q, d]) => `<span class="offer-progress__step${n >= q ? ' is-done' : ''}"><b>${d}%</b><small>${q} items</small></span>`).join('');
      const t = total();
      const ship = t >= threshold ? "🎉 You've unlocked free shipping!" : `You're ${fmt(threshold - t)} away from free shipping!`;
      const save = cart.items.reduce((s, i) => s + (i.v.compare_at_price - i.v.price) * i.quantity, 0) + (best ? Math.round(t * best[1] / 100) : 0);
      const items = cart.items.map((i, idx) => `<li class="cart-drawer__item" data-line="${idx + 1}"><a href="${i.p.url}" class="cart-drawer__media"><img src="${i.v.featured_image.src}" alt=""></a>
        <div class="cart-drawer__details"><a href="${i.p.url}" class="cart-drawer__name">${i.p.title}</a><p class="muted">${i.v.title}</p>
        <div class="cart-drawer__row"><div class="quantity quantity--small"><button type="button" class="quantity__button" data-cart-change="${i.quantity - 1}">−</button><span class="quantity__input">${i.quantity}</span><button type="button" class="quantity__button" data-cart-change="${i.quantity + 1}">+</button></div>
        <div class="cart-drawer__price"><s class="muted">${fmt(i.v.compare_at_price * i.quantity)}</s><strong>${fmt(i.v.price * i.quantity)}</strong></div></div>
        <button type="button" class="cart-drawer__remove link-underline muted" data-cart-change="0">Remove</button></div></li>`).join('');
      const disc = best ? `<p class="cart-drawer__line"><span>${icon('<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9-9-9Z"/>')} Bundle ${best[1]}% OFF</span><span>−${fmt(Math.round(t * best[1] / 100))}</span></p>` : '';
      const final = best ? t - Math.round(t * best[1] / 100) : t;
      body = `<div class="cart-drawer__body"><div class="offer-progress"><div class="offer-progress__row"><p>${msg}</p><div class="offer-progress__steps">${steps}</div><div class="progress"><span style="width:${Math.min(100, n * 100 / max)}%"></span></div></div>
        <div class="offer-progress__row"><p>${ship}</p><div class="progress"><span style="width:${Math.min(100, t * 100 / threshold)}%"></span></div></div></div>
        <ul class="cart-drawer__items">${items}</ul></div>
        <div class="cart-drawer__footer">${disc}<p class="cart-drawer__savings">You're saving ${fmt(save)} on this order 🎉</p>
        <p class="cart-drawer__line cart-drawer__subtotal"><span>Subtotal</span><strong>${fmt(final)}</strong></p>
        <p class="muted cart-drawer__taxes">Taxes included. Coupon codes &amp; shipping are applied at checkout.</p>
        <button type="button" class="button button--full button--checkout">${icon('<rect x="4" y="10" width="16" height="11" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>')} Check out · ${fmt(final)}</button>
        <ul class="trust-mini"><li>100% secure checkout</li><li>Cash on delivery</li><li>Easy 7-day exchange</li></ul></div>`;
    }
    return `<div id="shopify-section-cart-drawer"><div class="cart-drawer" data-cart-drawer aria-hidden="true"><div class="cart-drawer__overlay" data-cart-drawer-close></div>
      <div class="cart-drawer__panel" role="dialog" tabindex="-1"><div class="cart-drawer__header"><p class="cart-drawer__title">Your cart <span>(${n})</span></p>
      <button type="button" class="cart-drawer__close" data-cart-drawer-close aria-label="Close">${icon('<path d="M6 6l12 12M18 6 6 18"/>')}</button></div>${body}</div></div></div>`;
  }
  const json = (data) => new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } });

  window.fetch = async (url, opts = {}) => {
    const path = String(url);
    if (path.startsWith('/products/')) return json(window.PREVIEW_PRODUCTS[path.split('/')[2].replace('.js', '')]);
    if (path === '/cart.js') return json({ item_count: count() });
    if (path === '/cart/add.js') {
      const fd = opts.body;
      const id = parseInt(fd.get('id'), 10);
      const qty = parseInt(fd.get('quantity'), 10) || 1;
      const hit = byId[id];
      const line = cart.items.find((i) => i.v.id === id);
      if (line) line.quantity += qty; else cart.items.push({ ...hit, quantity: qty });
      return json({ id, sections: { 'cart-drawer': drawer() } });
    }
    if (path === '/cart/change.js') {
      const { line, quantity } = JSON.parse(opts.body);
      if (quantity <= 0) cart.items.splice(line - 1, 1); else cart.items[line - 1].quantity = quantity;
      return json({ item_count: count(), sections: { 'cart-drawer': drawer() } });
    }
    return new Response('', { status: 404 });
  };
  document.addEventListener('DOMContentLoaded', () => {
    const holder = document.getElementById('shopify-section-cart-drawer');
    holder.outerHTML = drawer();
  });
})();
"""


def main():
    os.makedirs(OUT, exist_ok=True)
    products = build_products()
    with open(os.path.join(OUT, "index.html"), "w", encoding="utf-8") as f:
        f.write(homepage(products))
    with open(os.path.join(OUT, "product.html"), "w", encoding="utf-8") as f:
        f.write(product_page(products))
    with open(os.path.join(OUT, "preview-shim.js"), "w", encoding="utf-8") as f:
        f.write(SHIM)
    print(f"Wrote preview to {OUT}")


if __name__ == "__main__":
    main()
