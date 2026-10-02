# Looma Apparels — WordPress Theme (v2)

A multi-page website theme for **Looma Apparels**, built from the 2026 catalog.

Download **`looma-apparels.zip`** and upload it to WordPress. The `looma-apparels/` folder holds the same theme as source code.

## Pages

When you activate the theme, it creates every page and menu for you.

| Page | Address | What's on it |
|---|---|---|
| Home | `/` | Hero, bestsellers, dropshipping vs bulk, printing, pricing preview, why Looma, FAQs |
| Shop | `/shop/` | All 6 t-shirts, with a fit filter and price sort |
| Product pages | `/shop/oversized-tee-250-gsm-french-terry/` and so on | Colour picker that recolours the shirt live, front/back/printed views, size and quantity pickers, a price that updates with quantity, **Add to quote list** and **Order on WhatsApp** |
| Dropshipping | `/dropshipping/` | Print on demand, the 5-step process, couriers, sign-up form |
| Bulk Orders | `/bulk-orders/` | Benefits, who it's for, a volume price table, quote form |
| Printing | `/printing/` | DTF, Puff, HD, Embroidery and Screen Print, plus DTF charges and the DTF roll |
| Price Estimator | `/price-estimator/` | The instant estimator, plus the 4 price-guide examples |
| Size Guide | `/size-guide/` | Oversized and Regular tables, a measuring diagram, fit comparison |
| About Us | `/about/` | Story, numbers, values |
| Contact | `/contact/` | WhatsApp, phone, email, address, enquiry form, map |
| Quote List | `/quote/` | Items the customer added. They send the list by email or WhatsApp |

## Install (Hostinger + WordPress)

1. Open `https://yourdomain.com/wp-admin`. On Hostinger you can also go to **hPanel → Websites → Manage → WordPress → Admin panel**.
2. Go to **Appearance → Themes → Add New Theme → Upload Theme**.
3. Choose `looma-apparels.zip`, click **Install Now**, then click **Activate**.
4. Open your domain. The pages, the menu and the home page are already set up.

If the upload says *"The link you followed has expired"*, your upload limit is too small. Instead:

1. Open **hPanel → File Manager** and go to `public_html/wp-content/themes/`.
2. Upload the zip there, then right-click it and choose **Extract**.
3. Activate the theme under **Appearance → Themes**.

### If you see "Page not found"

Go to **Appearance → Looma Setup** in WordPress. It lists every page with a ✔ or ✖. Click **Fix everything**. That recreates any missing pages, turns on pretty links, sets the home page and switches to the Looma menu; your old menus stay under Appearance → Menus. If you use LiteSpeed Cache on Hostinger, click **Purge All** afterwards.

### After activating

- **Appearance → Customize → Looma Business Details**: set the phone, **WhatsApp number**, email (enquiry-form messages go here), address, Instagram/Facebook links, and the announcement-bar text.
- **Appearance → Customize → Site Identity**: set the site title, logo (optional; a LOOMA APPARELS wordmark shows otherwise) and site icon (favicon).
- **Pages → About Us**: the story text on this page is general marketing copy I wrote. Edit it in `page-about.php` to tell your own story.
- **Enquiry emails**: install the free **WP Mail SMTP** plugin and connect it to a Hostinger mailbox (`smtp.hostinger.com`, port 465, SSL). Without it, form messages may land in spam or not arrive. The WhatsApp buttons work without any setup.

## Editing products and prices

Everything lives in one file: `inc/catalog.php`. Edit it in **Appearance → Theme File Editor → inc/catalog.php** or with Hostinger File Manager.

- **prices**: `'min'` is the smallest quantity for that price.
- **colours**: `array( 'Colour name', '#HEXCODE' )`. The shirt pictures recolour automatically.
- **display**: the colour shown on product cards.
- **shape**: `oversized`, `regular` or `fullsleeve` (which shirt drawing to use).
- **looma_dtf_prices()**: DTF print charges. `single` applies to 1–9 pieces and `bulk` to 10 or more.

The shop, product pages, estimator and quote list all update automatically.

> The catalog's quantity ranges overlap ("1–10", "10–25"). The site follows the catalog's own price guide, where 10 pieces get the 10+ price, so the tiers read 1–9, 10–24, 25–49, and so on.

## Design Studio (Design a Product)

At `/design/` customers upload artwork and see a **live mockup on the real t-shirt photos**:

- **Positions:** Front, Back, Left sleeve and Right sleeve, each with its own tab and print-area guide. Back views are made from the real photos.
- **Designs:**
  - Upload PNG / JPG / WEBP / SVG (drag & drop works), or add text with fonts, colours and outline.
  - Several designs per position.
  - White backgrounds on JPGs are removed automatically, with a tick-box to undo.
- **Placing and sizing:**
  - Drag to move, corner handles to resize, top handle to rotate; sliders, arrow keys, and quick placements (Left chest, Centre chest, Full front A3, Max A2…).
  - Centre snapping, flip, duplicate, and "Copy to back / other sleeve".
  - Undo/redo; the design autosaves in the browser.
- **Print size and quality:** sizes are real inches (measured on a size M tee). A print-quality (DPI) warning appears for small images.
- **Live quote:** each position is priced as Logo / A4 / A3 / A2 DTF from its actual size, or embroidery by stitches. Quantity is entered per size, the neck label is free with A2/A3/A4, and GST is added. Shipping is noted as extra.
- **Send on WhatsApp:**
  - The mockups and original artwork are uploaded to `wp-content/uploads/looma-designs/`, and WhatsApp opens with the full order plus the file links.
  - You also get an email, and every design appears in **WordPress admin → Design Requests**, with thumbnails, details and a delete button.
- **Entry points:** "Design this tee" on every product page and "Design your own" on the home page.
- Print-area positions per t-shirt live in `inc/catalog.php` → `looma_mockup_zones()`.

## Price Estimator / order builder

The **Price Estimator** page is a full order builder, for customers and for your own quoting.

- Add as many items as you like. Each item has its own t-shirt, colour, size breakdown (XS–XXL), front print and back print. Items can be duplicated or removed.
- Prints: DTF Logo / A4 / A3 / A2, or Embroidery by stitch count.
- Pricing follows the catalog:
  - T-shirt tiers count all pieces of the same style together.
  - DTF uses the 10+ rate from 10 pieces per order.
  - Embroidery is ₹7, ₹6 or ₹4 per 1,000 stitches for 1–9, 10–49 and 50+ pieces.
  - The neck label is free with an A2/A3/A4 print.
- Shipping charges are shown as extra (staff can enter an exact shipping amount).
- Send the order on WhatsApp, by email (form below the builder) or copy it as text. **PDF** prints a proper quotation (save it as PDF from the print dialog).
- **Staff tools**: while logged in to WordPress, a yellow panel adds customer name/phone, discount (% or ₹), shipping and notes. These also appear on the PDF quotation. Visitors never see this panel.
- The order is saved in the browser, so it's still there after a refresh. **Start over** clears it.
- Embroidery and DTF rates live in `inc/catalog.php` (`looma_embroidery_rates()`, `looma_dtf_prices()`).

## About the images

All photos come from your **Looma catalog**. They were enhanced 4× with an AI photo upscaler (Real-ESRGAN) so they look sharp on the website.

- **Product photos**: `assets/img/products/{product-id}-{colour}.jpg`, for example `oversized-tee-250-gsm-french-terry-royal-blue.jpg`. Your catalog shows each tee in one colour, so the other colours were made by recolouring the real photo, which keeps its real folds and shading.
- **Fabric close-ups**: `{product-id}-detail.jpg` (the folded-tee photos from each catalog page).
- **Site photos**: `hero.jpg`, `dropship.jpg`, `bulk.jpg`, `storefront.jpg`, `print-*.jpg`, `guide-*.jpg` and `size-diagram.jpg`.

To use your own photos, replace a file with a JPG of the **same name**. Product photos look best at 600 × 680 px or larger, with the same proportions.

**Tip:** the catalog you sent is the compressed version. If you have the original, uncompressed catalog images or a photo shoot, send them; they will look even sharper.
