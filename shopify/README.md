# Shopify T-Shirt Store

A ready-to-upload Shopify theme and product catalogue for a streetwear t-shirt brand: oversized tees, acid wash, regular fit, full sleeve, graphic tees, combo packs and custom printing. It's built for conversion: bundle offers ("Buy 2 get 10% off"), coupons, a slide-out cart with offer progress, quick add, delivery estimates, a sticky add-to-cart bar and more.

```
shopify/
├── streetwear-tees-theme.zip   ← upload this in Shopify (Online Store → Themes)
├── products.csv                ← import this in Shopify (Products → Import)
├── theme/                      ← theme source (Liquid, CSS, JS)
├── product-images/             ← web-optimised product photos used by the CSV
├── tools/build_products_csv.py ← regenerates products.csv (edit prices/products here)
├── tools/build_preview.py      ← regenerates the clickable preview
└── preview/index.html          ← clickable design preview (open in a browser)
```

## Conversion features

### Offers (all editable, and all sample values)
All offers live in one place: **Online Store → Themes → Customize → Theme settings → Offers & conversion**. Change a value once and it updates everywhere.

| Offer | Default (sample) | Where it shows |
|---|---|---|
| Bundle tiers | Buy 2 → 10% off · Buy 3 → 15% off · Buy 5 → 20% off | Announcement bar, hero, offer banner, product cards ("Buy 2, get 10% OFF"), product page tier picker, cart progress bar |
| Coupon codes | `WELCOME10` 10% off first order · `PREPAID50` ₹50 off prepaid · `FREESHIP` | Product page and cart ("Available offers", with a copy button), offer banner |
| Free shipping | Above ₹999 | Announcement bar, cart progress bar, trust badges |
| Sale countdown | Ends 31 Oct 2026, 23:59 IST | Red bar above the header, offer banner, product page. Hides itself after the end time. |
| Sign-up popup | 10% off with code `WELCOME10` | Appears after 8 seconds; shown at most once a week per visitor |

> **Important:** the theme only *displays* offers. You must also create matching discounts in Shopify (step 4 below), or customers won't actually get them at checkout.

### Homepage, in conversion order
1. Sale countdown bar, plus a rotating offer bar (Buy 2 get 10% / free shipping / WELCOME10 / COD)
2. Hero banner with the offer in the headline
3. Scrolling offer strip
4. Shop by category
5. **Bestsellers**, with quick add on every card
6. **"Buy more, save more" offer banner**: tier cards, countdown and a tap-to-copy coupon
7. New arrivals
8. **Shop by price**: Under ₹499 / ₹699 / ₹999 / Combos
9. **Combo packs**
10. Trust badges: free shipping, COD, easy returns, premium cotton
11. Custom printing showcase
12. **"Why choose us"** comparison table
13. **Customer reviews**
14. **FAQ**: answers the questions that usually stop people buying (COD, sizing, delivery, returns)
15. Bulk orders banner, newsletter and footer

### Product cards
- Sale % badge and Bestseller / New / Combo badges
- Second photo on hover
- Color dots
- "Buy 2, get 10% OFF" line
- **Quick add** button: pick color and size in a pop-up and add without leaving the page
- "Only X left" (only when stock tracking is on and stock is genuinely low)
- Star rating (once a reviews app is installed)

### Product page
- Star rating, linked to reviews
- Price, MRP, % OFF and **"You save ₹500"**
- Sale countdown (only while a real sale is running)
- Color swatches (the gallery switches to that color's photos), size buttons and a size chart
- **Bundle & Save picker**: Buy 1 / Buy 2 / Buy 3 (most popular) / Buy 5, with the per-tee price. Choosing one sets the quantity.
- Add to cart (opens the cart drawer) and Buy it now
- **Low-stock warning** from real inventory
- **Delivery estimate** ("Wed 8 Oct – Sat 11 Oct"), an "order within 3h 20m for same-day dispatch" timer and a pincode check
- **Available offers**: coupon codes with copy buttons
- Trust lines, secure-payment icons, highlights and accordions
- **Sticky add-to-cart bar** that appears when the main button scrolls out of view (mainly on mobile)
- You may also like (Shopify recommendations), reviews, FAQ and recently viewed

### Cart drawer and cart page
- Slides open after add to cart
- **Bundle progress**: "Add 1 more item to get 15% OFF", with a 10% → 15% → 20% step bar
- **Free-shipping progress bar**
- Change quantities or remove items without a page reload
- Shopify's real discounts shown per line and per order, plus a **"You're saving ₹X"** total
- **Upsell products** (pick them in Theme settings → Cart)
- Coupons, trust badges (secure checkout · COD · easy exchange) and a large "Check out · ₹1,258" button

### Kept from before
- Custom print product page (design upload, tee color and print position)
- Collection filters, sorting and pagination
- Search, blog, contact page and customer accounts
- WhatsApp button
- Mobile menu

## Sample products (replace later)
`products.csv` creates **9 products / 150 variants**. All prices are placeholders.

| Product | Options | Price (sample) |
|---|---|---|
| Oversized T-Shirt, 240 GSM | 9 colors × S–XXL | ₹699 (MRP ₹1,199) |
| Acid Wash Oversized T-Shirt, 250 GSM | 3 colors | ₹899 (MRP ₹1,499) |
| Regular Fit T-Shirt, 180 GSM | 3 colors | ₹449 (MRP ₹799) |
| Full Sleeve Oversized T-Shirt | Black | ₹849 (MRP ₹1,399) |
| Tokyo Puff Print Oversized T-Shirt *(sample graphic)* | Black | ₹1,099 (MRP ₹1,799) |
| Tokyo Screen Print Oversized T-Shirt *(sample graphic)* | Royal Blue | ₹999 (MRP ₹1,699) |
| Oversized Combo, Pack of 3 | 4 color combos | ₹1,799 (MRP ₹3,597) |
| Regular Fit Combo, Pack of 2 | 3 color combos | ₹799 (MRP ₹1,598) |
| Custom Printed T-Shirt | DTF / Screen / Puff / HD / Embroidery | ₹999 – ₹1,399 |

To change products or prices, edit `tools/build_products_csv.py` and run `python3 shopify/tools/build_products_csv.py`, or simply edit them in Shopify after importing.

Tags drive the theme:
- `bestseller` and `new` add badges and fill those collections
- `combo` adds the Combo badge
- `no-offer` hides the "Buy 2" line, which is used on combos since they're already discounted

## Setup: step by step

### 1. Store basics
1. **Settings → General**: store name, address, **currency = INR**.
2. **Settings → Taxes and duties**: turn on "Include tax in prices".

### 2. Upload the theme
**Online Store → Themes → Add theme → Upload zip file** → `shopify/streetwear-tees-theme.zip` → **Customize** → **Publish**.

### 3. Import the products
The CSV loads images from this public GitHub repo (currently from the `claude/gallant-mccarthy-c7q5hk` branch, so no merge is needed). Shopify copies the images when you import, so they keep working afterwards. If you regenerate the CSV after merging, run `python3 shopify/tools/build_products_csv.py --branch master`.

1. **Products → Import** → `shopify/products.csv` → Import.
2. Open **Custom Printed T-Shirt** → *Theme template* → **custom-print** → Save.
3. Stock tracking is off by default. To use the "Only X left" alerts, turn on **Track quantity** and enter real stock.

### 4. Create the discounts (this makes the offers real)
**Discounts → Create discount**:

| Name / code | Type | Settings |
|---|---|---|
| Buy 2 get 10% | **Automatic** · Amount off products | 10% · Applies to: *All Tees* collection · Minimum quantity of items: **2** |
| Buy 3 get 15% | **Automatic** · Amount off products | 15% · *All Tees* · Minimum quantity: **3** |
| Buy 5 get 20% | **Automatic** · Amount off products | 20% · *All Tees* · Minimum quantity: **5** |
| `WELCOME10` | Discount code · Amount off order | 10% · Limit to one use per customer |
| `PREPAID50` | Discount code · Amount off order | ₹50 fixed (optional: let it combine with product discounts) |
| `FREESHIP` | Discount code · Free shipping | (optional: minimum purchase) |

Shopify automatically applies whichever bundle discount saves the customer the most. Create an automated **All Tees** collection with the condition *Product tag is not equal to `combo`*, so combos and custom prints don't get a double discount. (Also exclude the custom print product if you want, e.g. tag it `combo` or use a product-type condition.)

If you change a tier in the theme settings, update the matching discount too, and the other way round.

### 5. Collections
**Products → Collections → Create collection** (Automated). Use these exact titles so the links on the homepage work:

| Title (handle) | Condition |
|---|---|
| All Tees (`all-tees`) | Product tag is not equal to `combo` |
| Oversized Tees (`oversized-tees`) | Product type = `Oversized T-Shirt` |
| Acid Wash (`acid-wash`) | Product tag = `acid-wash` |
| Regular Fit (`regular-fit`) | Product type = `Regular Fit T-Shirt` |
| Full Sleeve (`full-sleeve`) | Product type = `Full Sleeve T-Shirt` |
| Graphic Tees (`graphic-tees`) | Product type = `Graphic T-Shirt` |
| Combos (`combos`) | Product tag = `combo` |
| New Arrivals (`new-arrivals`) | Product tag = `new` |
| Bestsellers (`bestsellers`) | Product tag = `bestseller` |

### 6. Pages, menus, payments and shipping
- **Pages**: About Us, Contact (template **page.contact**), Shipping Policy, Returns & Exchange, Bulk Orders. **Settings → Policies**: generate them.
- **Main menu**: New Arrivals · Oversized · Acid Wash · Combos · Custom Print · Bulk Orders. **Footer menu**: Contact · Shipping · Returns · Track Order · Privacy.
- **Payments**: Razorpay / Cashfree / PhonePe, plus **Cash on Delivery** under manual payment methods.
- **Shipping**: e.g. ₹79 standard, and free above ₹999 (must match the theme's free-shipping threshold).

### 7. Customize
In **Theme settings**:
- **Offers & conversion**: tiers, coupons, sale end date, delivery days and dispatch cut-off time, low-stock threshold, sign-up popup (add an image)
- **Cart**: free-shipping threshold and upsell products
- **Social media**: WhatsApp number
- **Colors / Typography**: brand look

On the homepage and product page, edit the reviews, FAQ, banners, "Why choose us" rows and price tiles.

### 8. Recommended apps
- **Judge.me** or **Loox**: real product reviews with photos. Star ratings appear on cards and product pages automatically.
- **Shiprocket**: courier and tracking.
- **GoKwik / Shopflo**: one-click COD checkout and RTO protection.
- **Shopify Search & Discovery**: collection filters and "complementary products" for recommendations.

## ⚠️ Before you go live: keep it honest (and legal)
India's Consumer Protection rules, including the **Guidelines for Prevention and Regulation of Dark Patterns, 2023**, ban fake urgency, fake reviews and misleading prices. The theme is built to stay on the right side of these rules, but you have to keep it accurate:

- **Reviews**: the testimonials are labelled *"Sample review — replace with a real one"*. Replace them with real customer reviews, or remove the section until you have some.
- **Countdown**: set the sale end date to a real date. Don't keep resetting it.
- **MRP / compare-at price**: only show a struck-through price that is genuinely your MRP.
- **Low stock**: this uses real inventory only. Don't enter fake stock numbers.
- **Delivery estimate**: set the min/max days to what your courier actually achieves.
- **Print-method photos**: the sample "HD print" photo shows the Porsche name and the "DTF print" photo shows an anime character. Replace them with your own artwork so you don't risk a trademark or copyright claim.

## Preview the design locally
Open `shopify/preview/index.html` (homepage) or `shopify/preview/product.html` (product page) in a browser. It uses the theme's real CSS and JavaScript with sample data. Quick add, the cart drawer, bundle tiers, coupons and the countdown all work, with the cart simulated in your browser.

## Developing the theme
```bash
cd shopify/theme
shopify theme dev --store your-store.myshopify.com   # live local preview
shopify theme check                                  # lint (currently 0 offenses)
shopify theme push                                   # upload changes
```
Rebuild the zip: `cd shopify/theme && zip -r ../streetwear-tees-theme.zip . -x '.*'`
