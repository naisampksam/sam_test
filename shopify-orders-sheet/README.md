# LOOMA APPARELS – POD Order Desk (Shopify → Google Sheet, daily at 8 AM)

A Google Apps Script that lives inside a Google Sheet. Every morning it pulls new orders from the Shopify store of every brand you work with. Each brand's orders go into its **own tab**, in the same layout as Looma's "Customer Orders" sheet. A **Dashboard** shows every brand at a glance.

## The dashboard

**Dashboard tab.** This is the first tab in the sheet. It has live numbers for every brand: today's orders, to print, printing, ready to dispatch, dispatched, delivered, cancelled, and not in Product Map. Each brand's row has two links:
- **Open orders ↗** jumps to that brand's orders tab.
- **Shopify admin ↗** opens that brand's Shopify orders page.

The numbers update by themselves as your team changes statuses.

**Dashboard window.** Open it from **Looma POD → Open dashboard (add / manage stores)**. There's one card per store, each with its own buttons:

| Button | What it does |
|---|---|
| **Open orders** | Jumps to that brand's orders tab |
| **Sync now** | Pulls that brand's latest orders right away |
| **Shopify admin ↗** | Opens the brand's Shopify orders page |
| **Update access** | Enter a new Shopify token if the old one stops working |
| **Pause / Resume** | Stop or restart the daily sync for a brand you're no longer working with |
| **+ Add store** (top) | Connect a new brand's Shopify store |
| **Sync all now** (top) | Pull the latest orders for every brand |

**Adding a new store:**
1. Click **+ Add store** and fill in the brand name, the `.myshopify.com` address, and the Shopify access. Optionally, pick the brand's default T-shirt and print sizes too.
2. Click **Test & save**. The connection is **tested first**, and nothing is saved if it fails.
3. If it works, the brand is added to Clients, its **"<Brand> Orders"** tab is created, and it appears on the Dashboard.

## Tabs

| Tab | What it's for |
|---|---|
| **Dashboard** | Live overview of all brands, with links |
| **<Brand> Orders** | One tab per brand, one row per piece to print. Columns: Sl No, Date, Brand, Order ID, Customer Name, Country Code, Contact Number, Product Details, Product Site Link, Product Name, Qty, Mockup Folder (ALL), Product Design Drive Link, COD Payment, Payment Method, Payment Status, Shipping Address, and the team columns **Delivery Status, Printing Status, Delivery Partner, Tracking ID, Label Status** |
| **Today's Orders** | Every brand's orders pulled in today, in one list |
| **Clients** | The list of brands. Filled in by *+ Add store*, and editable by hand |
| **Product Map** | Which blank and which print sizes each brand's products use |
| **Blanks** | T-shirt types from the 2026 catalog (GSM, fabric, colours) |
| **Sync Log** | What happened on each run |

- **Product Details** is written as `GSM / Color / Size / Print`. The **Shipping Address** is written as `Name / Address / Mobile / Email / Pincode`.
- **COD Payment** shows the amount to collect on COD orders.
- The yellow team columns are filled in once, when a row is created (Delivery Status = "Order Created"). After that the script **never overwrites** them.
- If a customer cancels in Shopify, the row turns red with strikethrough.

## Product Map

Shopify orders don't say which blank or print sizes a design uses. The Product Map fills that in:

| Client Code | Match Text | Blank | Front | Back | Side | Extra | Neck Label | Design Drive Link | Mockup Folder |
|---|---|---|---|---|---|---|---|---|---|
| OUTFITCREW | tokyo | Oversized 230 GSM | A4 | | | | No | … | … |
| OUTFITCREW | *(blank = every other product)* | Oversized 250 GSM French Terry | A4 | A3 | | | Yes | | … |

- Rows are checked from the top down, and the first match wins. **Match Text** is looked for in the Shopify product title.
- The brand's default row (blank Match Text) is added by *+ Add store*. Put exceptions **above** it.

---

## Setup

### 1. Add the script to a Google Sheet (once)

1. Create a new Google Sheet. In **File → Settings**, set the time zone to **(GMT+05:30) India Standard Time**.
2. Go to **Extensions → Apps Script**, replace everything in the editor with `Code.gs`, and click **Save**.
3. Reload the spreadsheet. A **Looma POD** menu appears.
4. Click **Looma POD → 1. Set up sheets** and click **Allow** when Google asks for permission.
5. Click **Looma POD → 4. Enable daily 8 AM job**. You can also do this later with the *Turn it on* button in the dashboard window.

### 2. Shopify access for each brand

In the brand's Shopify admin, create an app with these scopes: `read_orders`, `read_products`, and `read_customers`. You need either:

- **Option A:** a **Dev Dashboard** app, installed on the store. Copy its **Client ID** and **Client secret**.
- **Option B:** an older custom app's **Admin API access token** (`shpat_…`).

Also give the app access to **protected customer data** (name, address, phone, email). Without it, shipping addresses come through blank.

### 3. Add the store

Go to **Looma POD → Open dashboard → + Add store**. Fill in the form, then click **Test & save**, then **Sync now**.

**Optional one-click button on the Dashboard tab:** go to **Insert → Drawing**, draw a button that says "Open dashboard", and click **Save and close**. Then click the drawing's **⋮ → Assign script**, and type `showDashboard`.

The daily job runs **between 8:00 and 9:00 AM** (Google picks the exact minute). If a run fails, Google emails you, and the error also shows in **Sync Log**.
