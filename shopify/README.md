# HAIKUFIT: Shopify T-Shirt Store

Store: **HAIKUFIT** · Domain: **haikufit.in**

A ready-to-upload Shopify theme and product catalogue for a streetwear t-shirt brand: oversized tees, acid wash, regular fit, full sleeve, graphic tees inspired by Japanese warriors. It's built for conversion: bundle offers ("Buy 2 get 10% off"), coupons, a slide-out cart with offer progress, quick add, delivery estimates, a sticky add-to-cart bar and more.

```
shopify/
├── haikufit-theme.zip   ← upload this in Shopify (Online Store → Themes)
├── products.csv                ← import this in Shopify (Products → Import)
├── theme/                      ← theme source (Liquid, CSS, JS)
├── brand/                      ← HAIKUFIT logos (black / white, transparent PNG) + favicon
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
`products.csv` creates the **10-product launch catalogue / 115 variants**. Prices are placeholders. Every product description starts with a **design story** (kanji, meaning and haiku). The theme shows it as a card on the product page and as a red kanji seal on product cards.

| Collection | Products | Price (sample) |
|---|---|---|
| **Warriors** (tag `warrior`) | Rōnin 浪人 · Shinobi 忍 · Oni 鬼 · Nana Korobi Ya Oki 七転び八起き · Fudōshin 不動心 (acid wash) | ₹899–₹999 |
| **Bushidō** (tag `bushido`) | 義 Gi · 勇 Yū · 誠 Makoto | ₹899 |
| Graphic | Fuji 富士 Puff Print | ₹1,099 |
| **Essentials** (tag `essentials`) | Essential Oversized (9 colours) | ₹699 |

To add a product back, add its handle to `LAUNCH` in `tools/build_products_csv.py` and rebuild the CSV.

> **The warrior product photos are mockups** that I generated from your blank tee photos (`tools/build_warrior_mockups.py`). Replace them with real photos once the designs are printed.
> **Have a native Japanese speaker check every design** before printing.

**If you already imported the earlier CSV:** delete those 9 old products first (Products → select all → Delete), then import the new `products.csv`. The handles have changed, so the new import doesn't overwrite them.

To change products, prices or haiku, edit `tools/build_products_csv.py` and run `python3 shopify/tools/build_products_csv.py --branch <branch>`. To edit one product's story in Shopify, open it, click **Show HTML** in the description editor and change the `design-story` block at the top. Alternatively, create product metafields `custom.kanji`, `custom.kanji_meaning` and `custom.haiku` (multi-line), which take priority over the description.

## Japanese warrior design system
- **Colours**: Sumi ink `#0E0E0E` · Washi paper `#F3EFE6` · Shu vermilion `#C8102E` · Kin gold `#B08D57`
- **Fonts**: Oswald for headings, Shippori Mincho for Japanese text and haiku (Google Fonts, can be switched off in Theme settings → Typography)
- **Motifs** (Theme settings → Layout): rice-paper texture, brush-stroke underline on headings, red hanko-seal badges, seigaiha wave pattern on dark sections, katana-sheen hover on buttons, vertical kanji on hero slides
- **Japanese subtitles**: most sections have a *Japanese subtitle (kanji)* setting, e.g. 新作 New arrivals · 人気 Bestsellers · 特価 Offer · 道場 Dojo · お客様の声 Reviews
- **New sections**: *Bushidō virtues* (7-virtue grid linked to products), *Haiku story*, *Dojo lookbook*, and a *Design story* block on the product page
- **Our Story page**: create a page called **Our Story** (handle `our-story`) and choose the template **page.story**

## Branding (HAIKUFIT)
- The theme already shows the **HAIKUFIT logo** in the header (black) and footer (white), plus an **"H" favicon**, with no upload needed.
- To use a different file, upload it in **Customize → Header → Logo** and **Theme settings → Layout → Favicon**. The originals are in `shopify/brand/`.
- Rename the store to **HAIKUFIT** in **Settings → Store details**. The name is used in page titles, emails and the checkout.

## Connect haikufit.in
1. **Settings → Domains → Connect existing domain** → `haikufit.in`.
2. At your domain registrar's DNS settings:
   - **A** record: host `@` → `23.227.38.65` (remove other `@` A records)
   - **CNAME**: host `www` → `shops.myshopify.com`
   - Don't touch the **MX** records (email).
3. **Verify connection** in Shopify, then set **haikufit.in as the primary domain**. SSL is automatic.
4. **Settings → Notifications → Sender email**: `support@haikufit.in`, then add the DNS records Shopify shows you to verify it.
5. In your **payment gateway dashboard**, make sure the approved website is `https://haikufit.in`.

## Setup: step by step

### 1. Store basics
1. **Settings → General**: store name, address, **currency = INR**.
2. **Settings → Taxes and duties**: turn on "Include tax in prices".

### 2. Upload the theme
**Online Store → Themes → Add theme → Upload zip file** → `shopify/haikufit-theme.zip` → **Customize** → **Publish**.

### 3. Import the products
The CSV loads images from this public GitHub repo (currently from the `claude/gallant-mccarthy-c7q5hk` branch, so no merge is needed). Shopify copies the images when you import, so they keep working afterwards. If you regenerate the CSV after merging, run `python3 shopify/tools/build_products_csv.py --branch master`.

1. **Products → Import** → `shopify/products.csv` → Import.
2. Stock tracking is off by default. To use the "Only X left" alerts, turn on **Track quantity** and enter real stock.

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
| All Tees (`all-tees`) | Product tag is not equal to `combo` (used by the bundle discounts) |
| Bushidō (`bushido`) | Product tag = `bushido` |
| Warriors (`warriors`) | Product tag = `warrior` |
| Essentials (`essentials`) | Product tag = `essentials` |
| Acid Wash (`acid-wash`) | Product tag = `acid-wash` |
| Graphic Tees (`graphic-tees`) | Product type = `Graphic T-Shirt` |
| Combos (`combos`) | Product tag = `combo` |
| New Arrivals (`new-arrivals`) | Product tag = `new` |
| Bestsellers (`bestsellers`) | Product tag = `bestseller` |

### 6. Pages, menus, payments and shipping
- **Pages and menus**: follow `STORE-PAGES.md` (every page has a ready-made designed template).
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
Rebuild the zip: `cd shopify/theme && zip -r ../haikufit-theme.zip . -x '.*'`

## Dojo Run game (samurai runner)

- Shown on the home page, on the 404 page and on any page using the `page.dojo` template.
- Controls: Space/↑/tap = jump (hold for higher), ↓/swipe down = duck, X = slash (on phones: on-screen Jump / Duck / Slash buttons).
- Rewards (edit in the theme editor → Dojo Run game): 300 pts → 5% (`DOJO5`), 600 → 10% (`DOJO10`), 1000 → 15% (`DOJO15`).
- Unique codes (recommended): set up the free rewards server in `dojo-rewards/` (step-by-step in `dojo-rewards/README.md`) and paste its URL in Theme settings → Dojo Run rewards. Each winner then gets their own single-use Shopify code that expires in 7 days, checked against the real run time. Without it, the fixed codes above are used and can be shared.
- Sound effects (no background music): sword swish/clang, jump whoosh, a conch war horn at the start, a gong + koto phrase on each discount unlock. All generated in the browser (no audio files). Players switch it on/off with the Sound button on the start screen, the speaker icon in the corner during play, or the M key; the choice is remembered. Default is set by "Sound effects on by default".
  **Create these codes in Shopify admin → Discounts** (percentage, one use per customer, minimum order). Codes are visible to anyone who reaches the score.
- Personal best is saved on the player's device. The "All-time record" is set in the section settings: update it when a customer sends a screenshot of a higher score.

## Battle animations

Theme settings → Battle animations: katana-slash intro (once per visit), scroll reveals, brush-stroke headings, card slash on hover,
falling sakura petals, footer embers and a red scroll-progress line. All of it is switched off automatically for visitors who prefer reduced motion.

## Find your size (size quiz)

Product pages show **Find your size** under the size buttons: a 4-tap quiz with ink-sketch illustrations.
1. Male or female  2. Height & weight sliders (the sketch grows and fills out as you slide)
3. Body type (Lean / Athletic / Average / Broad, or Slim / Athletic / Curvy / Full)  4. How oversized (Classic / Relaxed / Oversized / Extra baggy)

The answer is matched to the product's **size chart** (Size chart block or `custom.size_chart` metafield; chest + length,
inches or cm) and revealed with a red seal stamp, plus "closer fit" / "even looser" / "tall: more length" alternatives,
**Select size** and **Add to cart**. Answers are remembered, so every product page shows "Your size: M".
Uses the LOOMA oversized chart (XS–XXL, inches) from the 2026 catalogue. Tune the maths in `assets/size-finder.js` (`chestInches`, the `ease` of each fit) after real orders and exchanges.

## Store credit

See `STORE-CREDIT.md`: the theme shows "Earn ₹100 store credit" on products and in the cart plus the customer's
balance; Shopify Flow adds the credit after each paid order.

## Real customer reviews

Install **Judge.me** (free plan) from the Shopify App Store. The theme already shows its star ratings on product cards and
next to product titles (standard `reviews.rating` metafields). Add the Judge.me *Review widget* app block on the product page,
and use the **Apps** section to place its reviews carousel on the home page. The sample testimonials hide automatically on
any product that has real reviews; remove the home-page sample section once you have a few.
