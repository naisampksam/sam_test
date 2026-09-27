# LOOMA APPARELS – POD Order Desk (Shopify → Google Sheet, daily at 8 AM)

A Google Apps Script that lives inside a Google Sheet. Every morning it:

1. **Pulls new orders** from the Shopify store of every client brand.
2. **Writes them to the Orders tab** in the same layout as Looma's current "Customer Orders" sheet. That covers product details (GSM, colour, size, print), design and mockup links, COD, and the full shipping address.
3. **Prices each piece** from the 2026 catalog: blank T-shirt price plus DTF print charges.
4. **Creates one invoice per brand** as a PDF in Google Drive, and prepares an email to the brand.

## Tabs

| Tab | What it's for |
|---|---|
| **Orders** | One row per piece to print. The columns match the Looma order sheet: Sl No, Date, Brand, Order ID, Customer Name, Country Code, Contact Number, Product Details, Product Site Link, Product Name, Qty, Mockup Folder (ALL), Product Design Drive Link, COD Payment, Payment Method, Payment Status, Shipping Address, **Delivery Status, Printing Status, Delivery Partner, Tracking ID, Label Status** (yellow columns, for your team), then billing columns (Unit Price, Line Amount, Invoice No…) |
| **Today's Orders** | Only the orders pulled in today |
| **Clients** | One row per brand: Shopify store, billing name, address, state, GSTIN, email |
| **Product Map** | Which blank and which print sizes each brand's products use (see below) |
| **Blanks** | T-shirt prices by quantity tier. Pre-filled from the 2026 catalog |
| **Print Charges** | DTF A2 / A3 / A4 / Logo / Neck label prices. Pre-filled from the 2026 catalog |
| **Invoices** | Every invoice created, with its PDF link |
| **Settings** | Company details, GST %, shipping charge, invoice email mode, bank details |
| **Sync Log** | What happened on each run |

The yellow team columns are filled in only once, when a row is created (for example, Delivery Status = "Order Created"). After that the script **never overwrites** them. Dispatch status and tracking numbers your team types are safe.

Cancelled orders are shown in red with strikethrough, so the team knows not to print them.

## How pricing works

Unit price for one piece = **blank price** + **each print** + **neck label**.

- The neck label is free when the piece has an A2, A3 or A4 print, as the catalog says.
- For example, Oversized 250 GSM French Terry with an A3 back print and an A4 front print costs **₹290 + ₹135 + ₹95 = ₹520** for a single piece. At 10+ pieces it's **₹265 + ₹100 + ₹70 = ₹435**. These match the catalog's price guide.

The **Product Map** tells the script which blank and prints each product uses. Shopify orders don't carry this information.

| Client Code | Match Text | Blank | Front | Back | Side | Extra | Neck Label |
|---|---|---|---|---|---|---|---|
| OUTFITCREW | tokyo | Oversized 230 GSM | A4 | | | | No |
| OUTFITCREW | *(blank = every other product)* | Oversized 250 GSM French Terry | A4 | A3 | | | Yes |

- Rows are checked from the top down, and the first match wins. **Match Text** is looked for in the Shopify product title.
- Leave **Match Text** blank for a brand's default row, and put exceptions above it.
- **Design Drive Link** and **Mockup Folder** columns are copied into each order row for the printing team.

**Quantity tiers.** In Settings, **Quantity Discount = Daily total** means the catalog tier is based on the total pieces in that day's bill for the brand. For example, 12 pieces in a day are priced at the 10–24 tier. Set it to **None** to always charge the 1–9 piece price.

**When something can't be priced.** If an item has no Product Map row, or no price, that brand's invoice is **not created**. The row is highlighted and the Sync Log says what's missing. So a wrong bill is never sent. Fix the tab, then run *Create invoices for unbilled orders*.

## Invoices

- One invoice per brand per run covers every order not yet billed. Cancelled and test orders are skipped.
- Numbering is sequential per financial year: `LA/2026-27/0001`, `0002`, …
- GST is 5% by default. A brand in Kerala is charged CGST + SGST; a brand in another state is charged IGST. The rule uses the brand's **Billing State**.
- A shipping charge per order is optional. It's set in Settings and can be overridden per brand.
- The PDF is saved in the Google Drive folder **Looma Apparels Invoices / YYYY-MM**.
- **Invoice Email Mode** in Settings:
  - `Draft` (default) creates a Gmail draft to the brand's Billing Email, for you to check and send.
  - `Send` emails it automatically.
  - `Off` only saves the PDF.
- An order is billed once. If it's cancelled after being billed, it's marked Cancelled but stays on that invoice.

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

1. **Looma POD → 1. Set up sheets.** This creates all the tabs, with the catalog prices already filled in. Google asks you to authorize the script: click **Allow**.
2. **Settings:** add your **GSTIN** and **bank details**. Check the shipping charge.
3. **Clients:** one row per brand. Set **Active = Yes**.
   - **Start Date** is the first order date to import. If you leave it blank, the script starts from yesterday, so old orders aren't billed.
4. **Product Map:** add each brand's default blank and prints, plus any exceptions.
5. **Looma POD → 2. Connect a client Shopify store…** Run this for each brand.
6. **Looma POD → Run full daily job now** to try it once. Check the Orders tab, the invoice PDF and the Gmail draft.
7. **Looma POD → 4. Enable daily 8 AM job.**

The daily job runs **between 8:00 and 9:00 AM** (Google picks the exact minute). If a run fails, Google emails you, and the error also shows in **Sync Log**.
