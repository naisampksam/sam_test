# Looma Apparels — Sales & P&L Dashboard

Monthly sales report and profit & loss dashboard. Runs on your own computer at **http://localhost:4000**.

## Run it

1. Install [Node.js](https://nodejs.org) (version 16 or newer). Nothing else to install.
2. Open a terminal in this folder and run:

   ```
   npm start
   ```

3. Open **http://localhost:4000** in your browser.

On Windows you can double-click `start-dashboard.bat` instead.

To use another port: `PORT=5000 npm start` (Windows PowerShell: `$env:PORT=5000; npm start`).

## Every month

1. Export the **GSTR-1 report** Excel from your billing software (same format as `GSTR1_Report_08_26_to_08_26.xlsx`).
2. Click **Import GSTR-1** and choose the file (or drag it onto the Import page). You can pick several months at once.
3. Check the preview and click **Save to dashboard**.
4. Go to **Expenses**, enter fabric, stitching, DTF sticker, shipping, salaries, rent and other costs, and click **Save expenses**.
5. **Overview** and **Profit & Loss** now show whether the month made a profit or a loss.

Re-importing a month replaces its sales and keeps the expenses you entered.

## What it reads from the Excel

- Company name, GSTIN and report period
- Every invoice line: customer, GSTIN, date, taxable value, CGST, SGST, IGST, cess
- Credit notes (subtracted from sales)
- The item / HSN summary (products and units sold)

Net sales are the taxable value. GST collected is shown separately because it is owed to the government.

## Where your data is saved

All data is saved in `data/looma-data.json` in this folder. A copy of the previous day's data is kept in `data/backups/`. Back up the `data` folder to keep your records safe. It is excluded from git.

## Files

| File | Purpose |
|---|---|
| `index.html` | The dashboard |
| `server.js` | Local server on port 4000 and the data API |
| `vendor/` | Excel reader (SheetJS 0.18.5) and Chart.js 4.4.1, so it works offline |
| `start-dashboard.bat` | Windows shortcut: starts the server and opens the browser |
