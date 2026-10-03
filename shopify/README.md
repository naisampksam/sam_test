# Shopify T-Shirt Store

A ready-to-upload Shopify theme and product catalogue for a streetwear t-shirt brand: oversized tees, acid wash, regular fit, full sleeve and custom printing. The look takes its cues from Indian D2C fashion stores like bananaclub.co.in: clean black-and-white styling, bold uppercase headings, big product photos, sale badges, COD and free-shipping messaging.

```
shopify/
├── streetwear-tees-theme.zip   ← upload this in Shopify (Online Store → Themes)
├── products.csv                ← import this in Shopify (Products → Import)
├── theme/                      ← theme source (Liquid, CSS, JS)
├── product-images/             ← web-optimised product photos used by the CSV
├── tools/build_products_csv.py ← regenerates products.csv (edit prices/colors here)
└── preview/index.html          ← static design preview (open in a browser)
```

## What's included

**Homepage**
- Rotating announcement bar (free shipping / COD / returns)
- Sticky header with dropdown menus, search, account and cart count, plus a mobile slide-out menu
- Hero slideshow (ships with two banners made from your photos)
- Scrolling text strip
- "Shop by category" tiles: Oversized, Acid Wash, Regular Fit, Full Sleeve
- Bestsellers grid with sale % badges, a second image on hover, color swatches and a swipe slider on mobile
- Trust badges: free shipping, COD, easy returns, premium cotton
- Custom printing showcase: DTF, Screen, Puff, HD, Embroidery
- Bulk/corporate orders banner, newsletter signup and footer

**Product page**
- 2-column photo gallery on desktop and a swipe gallery on mobile
- Color swatches. Picking a color shows only that color's photos.
- Size buttons; unavailable or sold-out combinations are crossed out
- Size chart pop-up (editable; you can override it per product)
- Add to cart without a page reload, with a confirmation pop-up
- Buy-it-now button, trust lines, and description / fabric / shipping accordions
- **Custom print template**: customers choose the tee color and print position, upload their design file and add instructions. These details are attached to the order.

**Other pages**: collection (filters, sorting, pagination), cart (free-shipping progress bar, order notes), search, contact, blog, customer accounts, password page and 404.

**Extras**: floating WhatsApp chat button and social links. All text, colors and fonts can be edited in the Shopify theme editor.

## Setup: step by step

### 1. Store basics
1. Create your store at shopify.com (or use the one you have).
2. **Settings → General**: set the store name, address and **currency = Indian Rupee (INR)**.
3. **Settings → Taxes and duties**: turn on "Include tax in prices" (the theme shows "Inclusive of all taxes").

### 2. Upload the theme
1. Download `shopify/streetwear-tees-theme.zip` from this repo.
2. **Online Store → Themes → Add theme → Upload zip file**, then select the zip.
3. Click **Customize** to preview it, then **Publish**.

### 3. Import the products
The CSV loads its photos from this public GitHub repo. **Merge this branch into `master` first**, otherwise Shopify can't download the images.

1. **Products → Import → Add file** → `shopify/products.csv` → Upload and preview → Import.
2. This creates 5 products with 105 variants in total:

| Product | Colors | Price (placeholder) |
|---|---|---|
| Oversized T-Shirt, 240 GSM | Black, White, Beige, Bottle Green, Chocolate, Lavender, Navy, Royal Blue, Red | ₹699 (MRP ₹1,199) |
| Acid Wash Oversized T-Shirt, 250 GSM | Black, Royal Blue, Bottle Green | ₹899 (MRP ₹1,499) |
| Regular Fit T-Shirt, 180 GSM | Black, White, Red | ₹449 (MRP ₹799) |
| Full Sleeve Oversized T-Shirt, 240 GSM | Black | ₹849 (MRP ₹1,399) |
| Custom Printed T-Shirt | DTF / Screen / Puff / HD / Embroidery | ₹999 to ₹1,399 |

All products come in sizes S to XXL. **Change the prices** to your own, either in Shopify or by editing `tools/build_products_csv.py` and running `python3 shopify/tools/build_products_csv.py`.

3. Open **Custom Printed T-Shirt** → in the right sidebar under *Theme template* choose **custom-print** → Save. This turns on the design-upload form.
4. Stock tracking is off by default, so everything can be sold. To track stock, turn on "Track quantity" per product and enter quantities.

> Tip: each image's *alt text* is set to its color name (e.g. "Black"). This is how the product page shows only the selected color's photos. Keep that pattern when you add new photos.

### 4. Create collections
**Products → Collections → Create collection**. Choose *Automated* and use these exact titles, so the handles match the homepage tiles:

| Title (handle) | Condition |
|---|---|
| Oversized Tees (`oversized-tees`) | Product type is equal to `Oversized T-Shirt` |
| Acid Wash (`acid-wash`) | Product type is equal to `Acid Wash T-Shirt` |
| Regular Fit (`regular-fit`) | Product type is equal to `Regular Fit T-Shirt` |
| Full Sleeve (`full-sleeve`) | Product type is equal to `Full Sleeve T-Shirt` |
| New Arrivals (`new-arrivals`) | Product tag is equal to `new` |
| Bestsellers (`bestsellers`) | Product tag is equal to `bestseller` |

Products tagged `new` or `bestseller` get a matching badge on their product card.

### 5. Pages and menus
1. **Online Store → Pages**: create *About Us*, *Contact* (template: **page.contact**), *Shipping Policy*, *Returns & Exchange* and *Bulk Orders*.
2. **Settings → Policies**: generate the refund, privacy, terms and shipping policies.
3. **Online Store → Navigation → Main menu**: New Arrivals, Oversized, Acid Wash, Regular Fit, Full Sleeve, Custom Print (link to the product) and Bulk Orders. You can nest items to create dropdowns.
4. **Footer menu**: Contact, Shipping Policy, Returns & Exchange, Track Order, Privacy Policy.

### 6. Payments and shipping (India)
- **Settings → Payments**: add Razorpay, Cashfree or PhonePe (UPI, cards, netbanking). Under *Manual payment methods*, add **Cash on Delivery (COD)**.
- **Settings → Shipping and delivery**: add an India rate, e.g. ₹79 standard, plus a free rate with condition "order price ≥ ₹999".
- Optional apps: **Shiprocket** (courier and tracking), **Judge.me** (reviews), **GoKwik / Shopflo** (COD checkout and RTO protection).

### 7. Customize the theme
**Online Store → Themes → Customize**:
- **Header**: upload your logo.
- **Theme settings → Colors / Typography**: brand colors and fonts (for a bolder streetwear look, try *Archivo* or *Space Grotesk* for headings).
- **Theme settings → Social media**: Instagram and other links, plus your **WhatsApp number** (e.g. `919876543210`) to show the chat button.
- **Theme settings → Cart**: free-shipping threshold (₹999 by default).
- **Theme settings → Swatches**: color name → hex code mapping for any new colors you add.
- **Homepage**: swap in your own banner photos (2400×1200 px recommended) and point each category tile at its collection.
- **Product page → Size chart block**: edit the measurements. To give one product its own chart, create a product metafield `custom.size_chart` (multi-line text) in the same comma-separated format.

### 8. Go live
Remove the store password under **Online Store → Preferences**, place a test order (including one COD order), then share your link.

## Preview the design locally
Open `shopify/preview/index.html` in a browser. It's a static mock-up using the theme's real CSS and your product photos, so you can see the look before uploading.

## Developing the theme
With the [Shopify CLI](https://shopify.dev/docs/storefronts/themes/tools/cli):

```bash
cd shopify/theme
shopify theme dev --store your-store.myshopify.com   # live local preview
shopify theme check                                  # lint (currently 0 offenses)
shopify theme push                                   # upload changes
```

To rebuild the zip after changes: `cd shopify/theme && zip -r ../streetwear-tees-theme.zip . -x '.*'`
