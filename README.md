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
- **Item types:** T-shirt + print, Plain T-shirt, **Print only** (no T-shirt) and **DTF roll** (by the metre).
  Each item can have its own **Sub-order ID**; each order an **ORD- reference**.
- **Admin can add / edit / delete** customers, saved designs, products, colours, couriers, custom fields and staff;
  every area has its own permission (Customers, Saved designs, Catalog, Shipping labels, …).
- **Shipping label (7.5 × 12.5 cm)** like the Google-Sheet label: carrier, AWB (with barcode), ORD-, customer
  name / phone / address / PIN and seller (return) name / phone / address / PIN. Every field can be changed at
  print time with a live preview; "Save & print" stores it on the order.
- **Packing slip (7.5 × 12.5 cm)** with ship-to address, big PIN, phone, contents, courier/tracking and a
  **QR code** that opens the order on a staff phone. The brand name on the slip can be changed per order
  (for dropshipping / white-label). Print one slip from the order, or all slips of a tab from the order list.
  Printer settings: paper 75 × 125 mm (label 3×5"), margins none, scale 100%.
- **WhatsApp:** one tap opens WhatsApp to the customer with a ready message (order confirmation or
  shipped + tracking). After ticking Shipped, a "Send shipping update" banner appears. Messages are editable in Settings.
- **Print list:** everything waiting to print. "Blanks to pick" totals per GSM/product/color by size, then each item
  with its mock-ups, print details and a Printed tick. Filters for due today and delayed. Printable.
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
- **Orders make bills.** A new order gets its tax invoice (or proforma) when it is saved, and the bill follows later edits. Optional bill details on the order form: price per item (the stock price when left empty), printing charge, GSTIN, state, discount, shipping, branding and payment received. Deleting the order cancels its bill. Orders made before this feature get no bill unless one is picked on the order.
- **Products & stock (one page):** every T-shirt product with its selling price, colours and sizes, and the stock of each colour × size. Add new products, change prices or colours, and add or count stock from the same page. Bills use the product price for T-shirts. Print options, couriers and the GSM list are under *More → Order form options*.
- **Vyapar import:** T-shirts are added only when they match one of your products (same GSM — 240 counts as 250, editable — product, colour and size), at your product price. The rest are listed to add as new products in one tick. Printing and shipping items are added as in the file.
- **Bills make orders.** Saving a tax invoice also creates the production order: T-shirt lines become order items, printing lines go onto them, and shipping is not an item.
  Item and party names are suggested while you type, and the current stock shows on both the bill and the order form.
- **Import from Vyapar** (More → Import from Vyapar, admin): upload *Export_Items.xlsx* and *PartyReport.xlsx*.
  T-shirts go into Stock with their quantity and drop when they are billed. Printing (DTF, HD, embroidery…) becomes a service that never touches stock.
  Shipping items become shipping charges, and parties become customers. Running it again updates existing records instead of adding duplicates.

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
