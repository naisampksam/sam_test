# Looma Orders — internal order management & tracking

A small PHP + MySQL web app for **Looma Apparels**, built to run on Hostinger shared hosting under a subdomain
such as `orders.loomaapparels.com`. It works on phones and desktops.

## What it does

- **Logins for every staff member.** The admin adds staff, resets passwords and disables accounts.
- **Orders with many items.** One order is one parcel for one customer. It has a Customer ID, a **shipping address**
  (name, phone, address, pincode), a dispatch date, Packed ✓, Shipped ✓, courier and tracking.
  Inside it are any number of **items**. Each item has its own GSM → Product → Color → Size (from the catalog),
  quantity, **its own mock-up images**, print details (front / back / chest / neck / custom) and print method/size.
  - Example: 10 pieces = 250 GSM Black L × 4 (mock-up A) + 190 GSM White XL × 5 (mock-up B) + 190 GSM Red XL × 1 (mock-up C).
  - "⧉ Copy" duplicates an item so staff only change what is different.
- **With print / Plain T-shirt** toggle per item. Plain items need no printing and no mock-up, so an order
  with only plain T-shirts goes straight to *To pack*.
- **Neck label** on/off switch per item (printed or plain). When it's on, you can type the label text.
- **Saved designs** (Designs page): save a product once with its mock-up images, print details and neck label.
  When creating an order, tap **Pick a saved design** on an item and everything fills in. Images are linked, not copied.
- **Customer book:** customers are saved automatically from orders. When creating an order, type a customer ID,
  phone or name, tap the suggestion, and the ID and shipping address fill in. **Customers** page (admin only):
  every customer with their full order history, plus "New order for this customer".
- **Shipping address is required** on every order (name, phone, address, 6-digit pincode).
- **Easy on phones:** tap-to-choose chips for GSM / product / color (with color dots) / size, − / + quantity buttons,
  and a bottom tab bar (Orders, + New, Dashboard, More).
- **Per-item Printed ✓.** The printer ticks each item as it's done, and the order shows *Printing 2/3* until all items are printed.
  Packed ✓ and Shipped ✓ are ticked once per order. Every tick records who did it and when.
- **Per-field permissions.** For every field the admin picks **Hidden / View / Edit** for each person.
  Quick presets are available: Order creator, Printer, Packer/shipping, View only.
  Extra permissions: create orders (and add/remove items), delete orders, dashboard, Excel export,
  saved designs, manage catalog & options, and **free up space**.
- **Dispatch deadline:** order date + 2 days, with Sundays skipped (you can change this in Settings). Unshipped orders past that date show up as **Delayed**.
- **Dashboard:** pieces created / printed / packed / shipped on any day, and live counts of *To print / To pack / To ship / Due today / Delayed*.
  It also shows on-time %, a 14-day trend, per-staff pieces, courier split and top products.
- **Free up space (Storage page):** deletes the mock-up images of shipped orders (all, or those shipped more than N days ago),
  or of one shipped order from its page. Only the image files are removed. Every other order detail stays.
- **Full change history** per order (admin), Excel/CSV export (one row per item), search by customer ID / name / phone / pincode / order no.
- **Catalog** comes pre-loaded from the *Looma Catalog 2026* and is editable in-app, including bulk import.

## Install on Hostinger

1. **Subdomain:** hPanel → *Domains → Subdomains* → create `orders` (e.g. `orders.loomaapparels.com`).
   Note its folder (usually `public_html/orders`).
2. **Database:** hPanel → *Databases → MySQL Databases* → create a database + user. Note the name, user and password.
3. **Upload:** hPanel → *File Manager* → open the subdomain folder → upload `looma-orders.zip` → right-click → *Extract*
   (make sure `index.php` ends up directly in the subdomain folder, not in a sub-folder).
   This is **not a WordPress plugin**: it runs on its own subdomain next to your WordPress site and does not touch it.
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
