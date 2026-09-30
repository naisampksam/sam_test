# Looma Orders — internal order management & tracking

A small PHP + MySQL web app for **Looma Apparels**, built to run on Hostinger shared hosting under a subdomain
such as `orders.loomaapparels.com`. It works on phones and desktops.

## What it does

- **Logins for every staff member.** The admin adds staff, resets passwords and disables accounts.
- **Per-field permissions.** For every field (Customer ID, GSM, Product, Color, Size, Qty, Mock-ups, Front/Back/Chest
  print, Neck label, Custom print, Printed ✓, Packed ✓, Shipped ✓, Courier, Tracking…) the admin picks
  **Hidden / View / Edit** for each person. Quick presets are available: Order creator, Printer, Packer/shipping, View only.
- **Extra permissions:** create orders, delete orders, see the dashboard, export to Excel/CSV, and manage the catalog & options.
- **Order form:** GSM → Product → Color → Size dropdowns follow the catalog, so each GSM shows only its own products
  and each product shows only its own colors. Staff can pick **Other… (type)** for anything off-catalog.
  You can upload mock-up images (several, straight from the phone camera) or fill in the text print fields instead.
- **One-tap ticks:** Printed ✓ / Packed ✓ / Shipped ✓. The app records who ticked each one and when.
- **Dispatch deadline:** order date + 2 days, with Sundays skipped (you can change this in Settings). Orders that aren't shipped by then show up as **Delayed**.
- **Dashboard (admin):** pieces created / printed / packed / shipped on any day, and live counts of what's
  *To print / To pack / To ship / Due today / Delayed*. It also shows on-time %, a 14-day trend, per-staff work, courier split and top products.
- **Full change history** for every order (admin only), Excel/CSV export, search and date filters.
- **Catalog** comes pre-loaded from the *Looma Catalog 2026* and is editable in-app, including bulk import.

## Install on Hostinger

1. **Subdomain:** hPanel → *Domains → Subdomains* → create `orders` (e.g. `orders.loomaapparels.com`).
   Note its folder (usually `public_html/orders`).
2. **Database:** hPanel → *Databases → MySQL Databases* → create a database + user. Note the name, user and password.
3. **Upload:** hPanel → *File Manager* → open the subdomain folder → upload all files from this repo
   (or zip them, upload, then *Extract*).
4. **SSL:** hPanel → *Security → SSL* → install the free SSL on the subdomain.
5. Open `https://orders.loomaapparels.com/install.php`, enter the database details and create your admin login.
6. Delete `install.php` in File Manager (it locks itself anyway).
7. Log in → **Staff** → add each person with the right preset → fine-tune their fields.

PHP 8.0+ is needed (Hostinger default is fine). The `uploads/` and `sessions/` folders must be writable. They are by default.

## Updating

Upload the new files over the old ones (keep `config.php` and `uploads/`). Then open `install.php` once. It adds any
new tables and leaves your data alone.

## Backups

Mock-up images are in `uploads/`, and all other data is in the MySQL database. Hostinger's daily/weekly backups cover both.
