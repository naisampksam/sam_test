# Looma Apparels — Sales & P&L dashboard

A single-page dashboard (`index.html`) for monthly sales reports and profit & loss.

- **Import GSTR-1 Excel**: reads invoices, customers, taxable value, CGST/SGST/IGST, credit notes and the item/HSN summary. GST is separated out of net sales.
- **Expenses**: fabric purchase, stitching, DTF sticker, shipping, salaries, rent and other expenses, plus any custom fields you add. Also optional GST input credit.
- **Profit & Loss**: monthly statement with gross profit, net profit/loss, margins and comparison with the previous month.
- **All months**: financial-year (April–March) summary with CSV export and a JSON backup.

Open `index.html` in a browser. Data is kept in the browser's local storage when opened as a file.
