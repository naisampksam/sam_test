# Looma Apparels — WordPress Theme

A custom WordPress theme for **Looma Apparels** (www.loomaapparels.com), built from the 2026 catalog.

Download **`looma-apparels.zip`** from this repository and upload it to WordPress. The `looma-apparels/` folder holds the same theme as source code.

## What's on the website

One scrolling home page with every section of the catalog:

| Section | Contents |
|---|---|
| Hero | "Ideas into Apparel", quick facts, WhatsApp button |
| Top Products | 6 ready-stock t-shirts with fit filter. Clicking a product opens its specs, full price chart, ready-stock colours and a WhatsApp enquiry button |
| Dropshipping / POD & Bulk Order | Both services, the 5-step "How it works" process and "Ideal for" |
| Printing | DTF, Puff, HD, Embroidery and Screen Print with their details, the DTF charges table and the DTF roll |
| Price Guide | The 4 worked examples from the catalog, plus an **instant price estimator** (t-shirt + quantity + front/back DTF print → price per piece, GST and total) that sends the quote on WhatsApp |
| Size Chart | Oversized and Regular fit tables with a measuring diagram |
| Contact | Address, phone, email, Google Map, and an enquiry form that emails you or sends the enquiry on WhatsApp |

It also has a floating WhatsApp button, a mobile menu, SEO meta tags and Google business data (schema).

## How to install (Hostinger + WordPress)

1. Log in to your WordPress admin: `https://www.loomaapparels.com/wp-admin`
   (on Hostinger you can also go to **hPanel → Websites → Manage → WordPress → Dashboard / Admin panel**).
2. Go to **Appearance → Themes → Add New Theme → Upload Theme**.
3. Choose **`looma-apparels.zip`**, click **Install Now**, then click **Activate**.
4. Open your website. The home page shows the full catalog.

> If the upload fails with "The link you followed has expired", the file is too large for your upload limit. Instead, unzip it and upload the `looma-apparels` folder to `public_html/wp-content/themes/` using **hPanel → File Manager**. Then activate the theme under **Appearance → Themes**.

### Recommended settings after activating

- **Settings → Reading → Your homepage displays** can stay on either option. The theme always shows the catalog on the home page.
- **Settings → Permalinks**: choose **Post name**.
- **Settings → General**: set the Site Title to `Looma Apparels` and the Tagline to `Ideas into Apparel`.
- **Appearance → Customize → Site Identity**: upload your logo (optional; the theme shows a LOOMA APPARELS wordmark if you don't) and a **Site Icon** (the browser-tab favicon).

## Changing phone, WhatsApp, email and address

Go to **Appearance → Customize → Looma Business Details**. From there you can change:

- Phone number
- WhatsApp number (country code + number, digits only, e.g. `918089963691`)
- Email (enquiry-form messages are sent here)
- Address, working hours, map location
- Instagram and Facebook links (their icons appear in the footer once you add them)

## Changing products, prices and colours

Everything lives in one file: **`inc/catalog.php`**. Edit it in **Appearance → Theme File Editor → Looma Apparels → inc/catalog.php** (or with Hostinger File Manager).

- **Price tiers**: `'min'` is the smallest quantity for that price. For example, `array( 'min' => 10, 'label' => '10 – 25', 'price' => 265 )` means 265/- per piece from 10 pieces.
- **Colours**: `array( 'Colour name', '#HEXCODE' )`.
- **DTF print charges**: `looma_dtf_prices()`. `single` is the price for 1–10 pieces and `bulk` is the price for 10+ pieces.

The product cards, pop-ups and price estimator all update automatically.

## Replacing photos

The photos in `assets/img/` were cut from the catalog PDF, so they are low resolution. For a sharper site, replace them with your original photos. Keep the **same file names**, use JPG, and make them roughly 800–1200px wide. Upload them with Hostinger File Manager to `wp-content/themes/looma-apparels/assets/img/`.

| File | Used for |
|---|---|
| `hero-stack.jpg` | Main banner |
| `hanger-*.jpg` | Product cards |
| `folded-*.jpg` | Product pop-ups |
| `print-*.jpg` | Printing technique cards |
| `guide-*.jpg` | Price guide examples |
| `dropship-stack.jpg`, `bulk-hoodies.jpg` | Service cards |
| `size-diagram.jpg` | Size chart |

## Making the enquiry form email reliably

WordPress sends email through your hosting account, and those messages sometimes land in spam or aren't delivered at all. To make them reliable:

1. Create an email account such as `info@loomaapparels.com` in **Hostinger hPanel → Emails**.
2. Install the free **WP Mail SMTP** plugin (**Plugins → Add New**) and connect it to that Hostinger mailbox. Use SMTP host `smtp.hostinger.com`, port `465`, SSL.
3. Send a test email from the plugin.

The **Send via WhatsApp** button on the form works without any setup.

## Extra pages (optional)

Pages you create (for example a Privacy Policy, Terms, or Shipping & Returns page) automatically use the theme's design. To add them to the menu, go to **Appearance → Menus**, create a menu, and assign it to **Primary Menu**. If you create a menu, include links to the home page sections too, for example `/#products`, `/#printing`, `/#pricing`, `/#size` and `/#contact`.
