# LOOMA APPARELS – POD Order Desk (Shopify → Google Sheet, daily at 8 AM)

A Google Apps Script that lives inside a Google Sheet. Every morning it pulls new orders from the Shopify store of every client brand. It writes them to the **Orders** tab in the same layout as Looma's "Customer Orders" sheet, with everything the team needs to print and ship.

## Tabs

| Tab | What it's for |
|---|---|
| **Orders** | One row per piece to print: Sl No, Date, Brand, Order ID, Customer Name, Country Code, Contact Number, Product Details, Product Site Link, Product Name, Qty, Mockup Folder (ALL), Product Design Drive Link, COD Payment, Payment Method, Payment Status, Shipping Address, and the team columns **Delivery Status, Printing Status, Delivery Partner, Tracking ID, Label Status** |
| **Today's Orders** | Only the orders pulled in today |
| **Clients** | One row per brand: code, name, Shopify store, and whether it's active |
| **Product Map** | Which blank and which print sizes each brand's products use |
| **Blanks** | T-shirt types from the 2026 catalog (GSM, fabric, colours) |
| **Sync Log** | What happened on each run |

- **Product Details** is written as `GSM / Color / Size / Print`. The **Shipping Address** is written as `Name / Address / Mobile / Email / Pincode`, just like the current sheet.
- **COD Payment** shows the amount to collect on COD orders.
- The yellow team columns are filled in only once, when a row is created (Delivery Status = "Order Created"). After that the script **never overwrites** them. Dispatch status and tracking numbers your team types are safe.
- If a customer cancels in Shopify, the row turns red with strikethrough, so the team knows not to print it.

## Product Map

Shopify orders don't say which blank or print sizes a design uses. The Product Map fills that in:

| Client Code | Match Text | Blank | Front | Back | Side | Extra | Neck Label | Design Drive Link | Mockup Folder |
|---|---|---|---|---|---|---|---|---|---|
| OUTFITCREW | tokyo | Oversized 230 GSM | A4 | | | | No | … | … |
| OUTFITCREW | *(blank = every other product)* | Oversized 250 GSM French Terry | A4 | A3 | | | Yes | | … |

- Rows are checked from the top down, and the first match wins. **Match Text** is looked for in the Shopify product title.
- Leave **Match Text** blank for a brand's default row, and put exceptions above it.
- Items that don't match any row are highlighted in the **Map Status** column, and the Sync Log mentions them.

---

## Setup

### 1. Shopify access for each brand

Each brand creates an app in its Shopify admin with these scopes: `read_orders`, `read_products`, and `read_customers`. You need either:

- **Option A:** a **Dev Dashboard** app, installed on the store. Copy its **Client ID** and **Client secret**.
- **Option B:** an older custom app's **Admin API access token** (`shpat_…`).

Also give the app access to **protected customer data** (name, address, phone, email). Without it, shipping addresses come through blank.

### 2. Add the script to a Google Sheet

1. Create a new Google Sheet. In **File → Settings**, set the time zone to **(GMT+05:30) India Standard Time**.
2. Go to **Extensions → Apps Script**, paste the contents of `Code.gs` into the editor, and click **Save**.
3. Reload the spreadsheet. A **Looma POD** menu appears.

### 3. Configure

1. **Looma POD → 1. Set up sheets.** This creates all the tabs. Google asks you to authorize the script: click **Allow**.
2. **Clients:** add one row per brand and set **Active = Yes**.
   - **Start Date** is the first order date to import. If you leave it blank, the script starts from yesterday.
3. **Product Map:** add each brand's default blank and prints, plus any exceptions.
4. **Looma POD → 2. Connect a client Shopify store…** Run this for each brand.
5. **Looma POD → Sync orders now** to try it once, then check the Orders tab.
6. **Looma POD → 4. Enable daily 8 AM job.**

The daily job runs **between 8:00 and 9:00 AM** (Google picks the exact minute). If a run fails, Google emails you, and the error also shows in **Sync Log**.
