# Shopify Orders → Google Sheet (daily at 8 AM)

A Google Apps Script that lives inside a Google Sheet. It connects to your Shopify store, pulls **all order data**, and refreshes it **every morning at 8 AM**.

## What you get

| Tab | Contents |
|---|---|
| **Orders** | One row per order: dates, financial and fulfillment status, subtotal, discounts, shipping, tax, total, refunds, discount codes, payment gateway, customer, shipping and billing address, tags, note, source, a link to the order in Shopify admin, and more |
| **Line Items** | One row per product in each order: SKU, product and variant, vendor, quantity, unit price, discount, line total |
| **Sync Log** | One row per run: time, status, how many orders were synced, and any error |

- **First run** imports your whole order history.
- **Each daily run** after that fetches only the orders that were **created or changed** since the last run. Rows for existing orders are updated in place, so refunds, fulfillments, cancellations and edits show up without creating duplicates.
- Large stores are handled automatically. If a run gets close to Google's 6-minute limit, the script saves its progress and schedules itself to continue a minute later.
- Shopify rate limits are respected. The script waits and retries when needed.

---

## Setup (about 10 minutes)

### Step 1 – Create a Shopify app and get credentials

The app needs these Admin API scopes:

- `read_orders` (required)
- `read_all_orders` (needed for orders **older than 60 days**; without it Shopify only returns the last 60 days)
- `read_customers` (for customer name and order count)

Pick **one** of the options below.

**Option A – Dev Dashboard app (current Shopify method)**
1. In Shopify admin go to **Settings → Apps → Develop apps**. This opens the Shopify **Dev Dashboard**. Create an app there.
2. Configure the Admin API scopes listed above, then release a version and **install the app on your store**.
3. Copy the app's **Client ID** and **Client secret**. The script uses these to get a fresh access token automatically (client-credentials grant).

**Option B – Existing custom app with an access token**
If your store already has a custom app from before Shopify's change, and it has an Admin API access token that starts with `shpat_`, you can use that token directly. Make sure the app has the scopes listed above.

> If customer names or emails come back empty, give the app access to **protected customer data** (name, email, phone, address) in its configuration. Orders still sync without it. The Sync Log will show a warning.

### Step 2 – Add the script to a Google Sheet

1. Create a new Google Sheet, or open an existing one.
2. Go to **Extensions → Apps Script**.
3. Replace the contents of `Code.gs` with the contents of [`Code.gs`](./Code.gs) from this folder, then click **Save**.
4. (Optional) Click **Project Settings** (gear icon) → tick **Show "appsscript.json" manifest file**, then replace it with [`appsscript.json`](./appsscript.json).
5. Close the Apps Script tab and **reload the spreadsheet**. A new **Shopify Sync** menu appears.

### Step 3 – Connect, import and schedule

In the spreadsheet use the **Shopify Sync** menu:

1. **Configure Shopify connection…** Enter your store (e.g. `my-store.myshopify.com`), then either paste the `shpat_` token, or leave that blank and enter the Client ID and Client secret. Google asks you to authorize the script the first time; click **Allow**.
2. **Test connection.** This shows your store name and how many orders it can see.
3. **Sync now.** This runs the first full import. For big stores it continues automatically in the background; watch the **Sync Log** tab.
4. **Enable daily 8 AM sync.** This creates the daily trigger.

That's it. The sheet now refreshes every morning.

---

## About the 8 AM schedule

- The trigger uses the **spreadsheet's time zone** (**File → Settings → Time zone**). Set this correctly **before** you click *Enable daily 8 AM sync*. If you change it later, click *Enable daily 8 AM sync* again.
- Google Apps Script daily triggers run **within the hour you choose**, so the sync starts between **8:00 and 9:00 AM**. Google picks the exact minute.
- To use a different hour, change `DAILY_HOUR` at the top of `Code.gs` and click *Enable daily 8 AM sync* again.
- If a scheduled run fails, Google emails the script owner, and the error also appears in the **Sync Log** tab.

## Menu reference

| Menu item | What it does |
|---|---|
| Configure Shopify connection… | Saves your store and credentials in Script Properties. They are not visible in the sheet. |
| Test connection | Checks the credentials and shows the store name and order count |
| Enable daily 8 AM sync | Creates or replaces the daily trigger |
| Sync now | Runs an incremental sync immediately |
| Full re-import | Clears the Orders and Line Items tabs and downloads everything again |
| Disable daily sync | Removes the daily trigger |

## Settings (top of `Code.gs`)

| Setting | Default | Notes |
|---|---|---|
| `DEFAULT_API_VERSION` | `2026-07` | Shopify Admin API version. You can also set the Script Property `SHOPIFY_API_VERSION`. Shopify supports each version for about 12 months, so bump it about once a year. |
| `DAILY_HOUR` | `8` | Hour of the daily run (0–23) |
| `PAGE_SIZE` | `20` | Orders per API request |
| `LINE_ITEMS_PER_ORDER` | `25` | If an order has more line items than this, the Orders tab marks it with **Line Items Truncated = Yes** |

You can also enter the credentials yourself in **Apps Script → Project Settings → Script Properties**: `SHOPIFY_STORE`, and either `SHOPIFY_ACCESS_TOKEN` or `SHOPIFY_CLIENT_ID` + `SHOPIFY_CLIENT_SECRET`.

## Notes

- Amounts are in your **store currency**. The Orders tab also has a *Presentment Currency* column (the currency the customer paid in).
- Orders deleted in Shopify are not removed from the sheet by the daily sync. Use **Full re-import** to rebuild from scratch.
- Use your `*.myshopify.com` domain, not a custom domain such as `mystore.com`.
