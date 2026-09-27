/**
 * LOOMA APPARELS – Print-on-demand order desk (Google Apps Script, bound to a Google Sheet).
 *
 * Every morning (8–9 AM, spreadsheet time zone) this script:
 *   1. Connects to the Shopify store of every client brand listed in the "Clients" tab.
 *   2. Pulls new / changed orders into the "Orders" tab – one row per product to print,
 *      with size, colour, print/design details and the full ship-to address.
 *   3. Prices every item from the "Price List" tab (your catalog).
 *   4. Creates one invoice per client for all orders not yet billed, saves it as a PDF
 *      in Google Drive and (optionally) emails it to the client.
 *
 * Tabs created by "Looma POD → Set up sheets":
 *   Settings, Clients, Price List, Orders, Today's Orders, Invoices, Sync Log
 *
 * See README.md for the step-by-step setup.
 */

const APP = {
  DEFAULT_API_VERSION: '2026-07',
  DAILY_HOUR: 8,                      // daily job runs between 8:00 and 9:00
  PAGE_SIZE: 10,                      // orders per Shopify request (auto-reduced if too costly)
  LINE_ITEMS_PER_ORDER: 15,
  MAX_RUNTIME_MS: 4.5 * 60 * 1000,    // stop & resume before Apps Script's 6-minute limit
  CONTINUATION_DELAY_MS: 60 * 1000,
  MAX_RETRIES: 6,
};

const SHEETS = {
  SETTINGS: 'Settings',
  CLIENTS: 'Clients',
  PRICES: 'Price List',
  ORDERS: 'Orders',
  TODAY: "Today's Orders",
  INVOICES: 'Invoices',
  LOG: 'Sync Log',
};

const SETTINGS_DEFAULTS = [
  ['Company Name', 'LOOMA APPARELS', 'Printed at the top of every invoice'],
  ['Company Address', '', 'Full address (use Ctrl+Enter for new lines)'],
  ['Company Phone', '', ''],
  ['Company Email', '', 'Used as the reply-to address on invoice emails'],
  ['Company GSTIN', '', ''],
  ['Company State', '', 'Same state as client → CGST + SGST, otherwise IGST'],
  ['GST %', 5, 'Confirm the correct rate with your accountant'],
  ['Shipping Charge per Order', 0, 'Default per-order shipping billed to clients (can be overridden per client)'],
  ['Invoice Prefix', 'LA', 'Invoice numbers look like LA/2026-27/0001'],
  ['Invoice Folder', 'Looma Apparels Invoices', 'Google Drive folder where invoice PDFs are saved'],
  ['Invoice Email Mode', 'Draft', 'Draft = Gmail draft for you to check & send · Send = email automatically · Off = PDF only'],
  ['Bank Details', '', 'Printed on invoices (account name, number, IFSC, UPI…)'],
  ['Invoice Note', 'Thank you for your business!', 'Printed at the bottom of invoices'],
];

const CLIENT_HEADERS = [
  'Client Code', 'Brand Name', 'Shopify Store', 'Active', 'Start Date',
  'Billing Name', 'Billing Address', 'Billing State', 'GSTIN', 'Billing Email',
  'Shipping Charge per Order', 'Connection', 'Last Sync',
];

const PRICE_HEADERS = ['Client Code', 'Match Text', 'Size', 'Colour', 'Unit Price', 'Invoice Description'];

// Columns the script writes. Anything to the right (TEAM_COLS) is for your team and is never overwritten.
const ORDER_COLS = [
  'Line ID', 'Batch Date', 'Client Code', 'Brand', 'Order No', 'Order Date',
  'Product', 'Variant', 'SKU', 'Size', 'Colour', 'Qty',
  'Print / Design Details', 'Design Preview',
  'Unit Price', 'Line Amount', 'Price Status',
  'Customer Name', 'Phone', 'Email', 'Address 1', 'Address 2', 'City', 'State', 'Pincode', 'Country',
  'Payment Method', 'Payment Status', 'Order Value', 'COD Amount',
  'Shopify Fulfillment', 'Cancelled', 'Test Order', 'Order Note', 'Shopify Link', 'Invoice No',
];
const TEAM_COLS = ['Production Status', 'Courier', 'AWB / Tracking No', 'Remarks'];
const ORDER_TEXT_COLS = ['Line ID', 'Order No', 'SKU', 'Phone', 'Pincode', 'Invoice No'];
const C = ORDER_COLS.reduce((m, h, i) => { m[h] = i; return m; }, {});

const INVOICE_HEADERS = [
  'Invoice No', 'Invoice Date', 'Client Code', 'Brand', 'Orders', 'Items',
  'Subtotal', 'Shipping', 'GST', 'Total', 'PDF', 'Email',
];

const ORDERS_QUERY = `
fragment Money on MoneyBag { shopMoney { amount } }
query Orders($first: Int!, $after: String, $query: String, $lineItems: Int!) {
  orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      name
      createdAt
      updatedAt
      cancelledAt
      test
      email
      phone
      note
      displayFinancialStatus
      displayFulfillmentStatus
      paymentGatewayNames
      currentTotalPriceSet { ...Money }
      totalOutstandingSet { ...Money }
      shippingAddress {
        name company address1 address2 city province zip country phone
      }
      lineItems(first: $lineItems) {
        pageInfo { hasNextPage }
        nodes {
          id
          title
          variantTitle
          sku
          currentQuantity
          customAttributes { key value }
          image { url }
          variant { selectedOptions { name value } }
          product { productType }
        }
      }
    }
  }
}`;

// ===========================================================================
// Menu
// ===========================================================================

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Looma POD')
    .addItem('1. Set up sheets', 'setupSheets')
    .addItem('2. Connect a client Shopify store…', 'connectClientStore')
    .addItem('3. Test all connections', 'testAllConnections')
    .addItem('4. Enable daily 8 AM job', 'enableDailyJob')
    .addSeparator()
    .addItem('Sync orders now', 'menuSyncOrders')
    .addItem('Create invoices for unbilled orders', 'menuCreateInvoices')
    .addItem('Run full daily job now (sync + invoices)', 'menuRunDailyJob')
    .addSeparator()
    .addItem('Disable daily job', 'disableDailyJob')
    .addToUi();
}

function setupSheets() {
  ensureSheets_();
  SpreadsheetApp.getUi().alert('Sheets are ready',
    'Next:\n' +
    '• Fill in the Settings tab (company details, GST, bank details).\n' +
    '• Add each brand to the Clients tab.\n' +
    '• Enter your catalog prices in the Price List tab.\n' +
    '• Then use "Connect a client Shopify store…" for each brand.',
    SpreadsheetApp.getUi().ButtonSet.OK);
}

function connectClientStore() {
  const ui = SpreadsheetApp.getUi();
  ensureSheets_();
  const clients = getClients_();
  if (!clients.length) {
    ui.alert('Add the brand to the Clients tab first (Client Code, Brand Name, Shopify Store), then try again.');
    return;
  }
  const code = promptOrCancel_(ui, 'Client code',
    'Which client? Enter the Client Code from the Clients tab:\n' + clients.map((c) => c.code).join(', '));
  if (!code) return;
  const client = clients.find((c) => c.code === normalizeCode_(code));
  if (!client) { ui.alert(`No client with code "${code}" in the Clients tab.`); return; }

  const token = promptOrCancel_(ui, `${client.brand}: Admin API access token`,
    'Paste the Admin API access token (starts with "shpat_").\n\n' +
    'Using a Dev Dashboard app with Client ID + Client secret instead? Leave blank and press OK.');
  if (token === null) return;

  const props = PropertiesService.getScriptProperties();
  const p = credKeys_(client.code);
  if (token) {
    props.setProperty(p.token, token);
    props.deleteProperty(p.id);
    props.deleteProperty(p.secret);
  } else {
    const id = promptOrCancel_(ui, `${client.brand}: Client ID`, 'Paste the app\'s Client ID.');
    if (!id) return;
    const secret = promptOrCancel_(ui, `${client.brand}: Client secret`, 'Paste the app\'s Client secret.');
    if (!secret) return;
    props.setProperty(p.id, id);
    props.setProperty(p.secret, secret);
    props.deleteProperty(p.token);
  }
  CacheService.getScriptCache().remove(p.cache);

  const result = testClient_(client);
  ui.alert(`${client.brand}`, result, ui.ButtonSet.OK);
}

function testAllConnections() {
  ensureSheets_();
  const lines = getClients_().filter((c) => c.active).map((c) => `${c.code}: ${testClient_(c)}`);
  SpreadsheetApp.getUi().alert('Connections', lines.join('\n\n') || 'No active clients.', SpreadsheetApp.getUi().ButtonSet.OK);
}

function menuSyncOrders() { runJobFromMenu_({ sync: true, invoice: false }); }
function menuCreateInvoices() { runJobFromMenu_({ sync: false, invoice: true }); }
function menuRunDailyJob() { runJobFromMenu_({ sync: true, invoice: true }); }

function enableDailyJob() {
  deleteTriggersFor_('dailyJob');
  const tz = SpreadsheetApp.getActive().getSpreadsheetTimeZone();
  ScriptApp.newTrigger('dailyJob').timeBased().everyDays(1).atHour(APP.DAILY_HOUR).inTimezone(tz).create();
  SpreadsheetApp.getUi().alert('Daily job enabled',
    `Every day between ${APP.DAILY_HOUR}:00 and ${APP.DAILY_HOUR + 1}:00 (${tz}) the script will sync all client ` +
    'orders and create invoices. Google picks the exact minute within that hour.\n\n' +
    'Results appear in the "Sync Log" tab.',
    SpreadsheetApp.getUi().ButtonSet.OK);
}

function disableDailyJob() {
  deleteTriggersFor_('dailyJob');
  deleteTriggersFor_('continueDailyJob');
  SpreadsheetApp.getUi().alert('Daily job disabled.');
}

// ===========================================================================
// Trigger entry points
// ===========================================================================

/** Daily 8 AM trigger. */
function dailyJob() {
  runJob_('daily', { sync: true, invoice: true });
}

/** One-off trigger that resumes a daily job that hit the time limit. */
function continueDailyJob() {
  deleteTriggersFor_('continueDailyJob');
  runJob_('continuation', { sync: true, invoice: true });
}

// ===========================================================================
// Job runner
// ===========================================================================

function runJobFromMenu_(what) {
  const ui = SpreadsheetApp.getUi();
  try {
    const res = runJob_('manual', what);
    ui.alert('Looma POD', res.message, ui.ButtonSet.OK);
  } catch (err) {
    ui.alert('Looma POD – failed', String(err.message || err), ui.ButtonSet.OK);
  }
}

function runJob_(source, what) {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30 * 1000)) {
    writeLog_(source, 'SKIPPED', 'Another run is in progress.');
    return { message: 'Another run is already in progress; skipped.' };
  }
  const started = Date.now();
  const messages = [];
  try {
    ensureSheets_();
    let syncComplete = true;
    if (what.sync) {
      const s = syncAllClients_(started);
      messages.push(...s.messages);
      syncComplete = s.complete;
      if (!syncComplete) {
        scheduleContinuation_();
        messages.push('Time limit reached – the job will continue automatically in about a minute.');
      }
    }
    if (what.invoice && syncComplete) {
      messages.push(...createInvoices_());
    }
    const status = messages.some((m) => /^⚠|error/i.test(m)) ? 'WARN' : (syncComplete ? 'OK' : 'PARTIAL');
    const message = messages.join('\n') || 'Nothing to do.';
    writeLog_(source, status, message, started);
    return { message: message };
  } catch (err) {
    writeLog_(source, 'ERROR', [...messages, String(err.message || err)].join('\n'), started);
    throw err; // Apps Script emails the owner when a trigger run fails
  } finally {
    lock.releaseLock();
  }
}

// ===========================================================================
// Order sync
// ===========================================================================

function syncAllClients_(started) {
  const ss = SpreadsheetApp.getActive();
  const tz = ss.getSpreadsheetTimeZone();
  const batchDate = Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd');
  const prices = loadPriceList_();
  const orders = new SheetUpserter_(ss.getSheetByName(SHEETS.ORDERS), ORDER_COLS.length);
  const clients = getClients_().filter((c) => c.active);
  const messages = [];

  for (const client of clients) {
    if (Date.now() - started > APP.MAX_RUNTIME_MS) return { complete: false, messages: messages };
    try {
      const r = syncClient_(client, { started, orders, prices, batchDate, tz });
      setClientCell_(client, 'Last Sync', new Date());
      setClientCell_(client, 'Connection', 'OK');
      messages.push(`${client.code}: ${r.orders} orders / ${r.lines} items synced` +
        (r.unpriced ? ` – ⚠ ${r.unpriced} item(s) have no price in Price List` : '') +
        (r.truncated ? ` – ⚠ ${r.truncated} order(s) have more than ${APP.LINE_ITEMS_PER_ORDER} items; extra items not imported` : '') +
        (r.warnings.length ? ` – ⚠ ${r.warnings.join(' | ')}` : ''));
      if (!r.complete) return { complete: false, messages: messages };
    } catch (err) {
      setClientCell_(client, 'Connection', 'Error: ' + String(err.message || err).slice(0, 200));
      messages.push(`⚠ ${client.code}: sync error – ${err.message || err}`);
    }
  }
  return { complete: true, messages: messages };
}

function syncClient_(client, ctx) {
  const props = PropertiesService.getScriptProperties();
  const cfg = clientConfig_(client);
  const ckptKey = 'CKPT_' + client.code;

  // Never pull a brand's full history on its first sync: default to orders from yesterday onwards.
  let startDate = client.startDate;
  if (!startDate) {
    startDate = new Date(Date.now() - 24 * 3600 * 1000);
    startDate.setHours(0, 0, 0, 0);
    setClientCell_(client, 'Start Date', startDate);
  }
  const terms = [`created_at:>="${startDate.toISOString()}"`];
  const ckpt = props.getProperty(ckptKey);
  if (ckpt) terms.push(`updated_at:>="${ckpt}"`);

  const res = { orders: 0, lines: 0, unpriced: 0, truncated: 0, warnings: [], complete: true };
  let pageSize = APP.PAGE_SIZE;
  let after = null;
  for (;;) {
    let data;
    try {
      data = shopifyGraphql_(cfg, ORDERS_QUERY, {
        first: pageSize, after: after, query: terms.join(' AND '), lineItems: APP.LINE_ITEMS_PER_ORDER,
      });
    } catch (err) {
      if (err.code === 'MAX_COST_EXCEEDED' && pageSize > 1) { pageSize = Math.max(1, Math.floor(pageSize / 2)); continue; }
      throw err;
    }
    data.warnings.forEach((w) => { if (res.warnings.indexOf(w) === -1) res.warnings.push(w); });

    const page = data.data.orders;
    if (page.nodes.length) {
      const rows = [];
      page.nodes.forEach((o) => {
        if (o.lineItems.pageInfo.hasNextPage) res.truncated++;
        o.lineItems.nodes.forEach((li, idx) => {
          const row = orderLineToRow_(client, cfg, o, li, idx === 0, ctx.batchDate);
          if (row) rows.push(row);
        });
      });
      rows.forEach((row) => applyPrice_(row, client, ctx.prices));
      ctx.orders.upsert(rows, mergeOrderRow_);
      SpreadsheetApp.flush();
      props.setProperty(ckptKey, page.nodes[page.nodes.length - 1].updatedAt);
      res.orders += page.nodes.length;
      res.lines += rows.length;
      res.unpriced += rows.filter((r) => r[C['Price Status']] === 'NOT FOUND' && !r[C['Invoice No']]).length;
    }
    after = page.pageInfo.hasNextPage ? page.pageInfo.endCursor : null;
    if (!after) break;
    if (Date.now() - ctx.started > APP.MAX_RUNTIME_MS) { res.complete = false; break; }
  }
  return res;
}

function orderLineToRow_(client, cfg, o, li, firstLine, batchDate) {
  const ship = o.shippingAddress || {};
  const opts = variantOptions_(li);
  const gateways = (o.paymentGatewayNames || []).join(', ');
  const isCod = /cash on delivery|\bcod\b/i.test(gateways);
  const orderId = gidToId_(o.id);
  const design = (li.customAttributes || []).map((a) => `${a.key}: ${a.value}`).join('\n');
  const imageUrl = li.image && li.image.url;

  const row = new Array(ORDER_COLS.length).fill('');
  row[C['Line ID']] = gidToId_(li.id);
  row[C['Batch Date']] = batchDate;
  row[C['Client Code']] = client.code;
  row[C['Brand']] = client.brand;
  row[C['Order No']] = o.name;
  row[C['Order Date']] = new Date(o.createdAt);
  row[C['Product']] = li.title || '';
  row[C['Variant']] = li.variantTitle || '';
  row[C['SKU']] = li.sku || '';
  row[C['Size']] = opts.size;
  row[C['Colour']] = opts.colour;
  row[C['Qty']] = li.currentQuantity;
  row[C['Print / Design Details']] = design;
  row[C['Design Preview']] = imageUrl ? `=IMAGE("${imageUrl.replace(/"/g, '%22')}")` : '';
  row[C['Customer Name']] = ship.name || '';
  row[C['Phone']] = ship.phone || o.phone || '';
  row[C['Email']] = o.email || '';
  row[C['Address 1']] = [ship.company, ship.address1].filter(Boolean).join(', ');
  row[C['Address 2']] = ship.address2 || '';
  row[C['City']] = ship.city || '';
  row[C['State']] = ship.province || '';
  row[C['Pincode']] = ship.zip || '';
  row[C['Country']] = ship.country || '';
  row[C['Payment Method']] = isCod ? 'COD' : (gateways || '');
  row[C['Payment Status']] = o.displayFinancialStatus || '';
  // Order-level money only on the first line of each order so column totals are correct.
  row[C['Order Value']] = firstLine ? money_(o.currentTotalPriceSet) : '';
  row[C['COD Amount']] = firstLine && isCod ? money_(o.totalOutstandingSet) : '';
  row[C['Shopify Fulfillment']] = o.displayFulfillmentStatus || '';
  row[C['Cancelled']] = o.cancelledAt ? 'Yes' : 'No';
  row[C['Test Order']] = o.test ? 'Yes' : 'No';
  row[C['Order Note']] = o.note || '';
  row[C['Shopify Link']] = `https://admin.shopify.com/store/${cfg.shop.replace(/\.myshopify\.com$/, '')}/orders/${orderId}`;

  // Stop user-supplied text (e.g. "+91…" phone numbers, notes) being treated as formulas.
  return row.map((v, i) => (i === C['Design Preview'] ? v : safeText_(v)));
}

/** Keeps billing data stable once a line has been invoiced. */
function mergeOrderRow_(oldRow, newRow) {
  const out = newRow.slice();
  out[C['Batch Date']] = oldRow[C['Batch Date']] || newRow[C['Batch Date']];
  out[C['Invoice No']] = oldRow[C['Invoice No']];
  if (oldRow[C['Invoice No']]) {
    ['Qty', 'Unit Price', 'Line Amount', 'Price Status'].forEach((h) => { out[C[h]] = oldRow[C[h]]; });
  }
  return out;
}

function variantOptions_(li) {
  let size = '';
  let colour = '';
  const opts = (li.variant && li.variant.selectedOptions) || [];
  opts.forEach((o) => {
    if (/size/i.test(o.name)) size = o.value;
    else if (/colou?r/i.test(o.name)) colour = o.value;
  });
  if ((!size || !colour) && li.variantTitle) {
    li.variantTitle.split('/').map((s) => s.trim()).forEach((part) => {
      if (!size && /^(xxs|xs|s|m|l|xl|xxl|xxxl|\d?xl|\d{2})$/i.test(part)) size = part;
      else if (!colour && opts.length === 0 && part && part !== size) colour = part;
    });
  }
  return { size: size, colour: colour };
}

// ===========================================================================
// Pricing
// ===========================================================================

function loadPriceList_() {
  const sheet = SpreadsheetApp.getActive().getSheetByName(SHEETS.PRICES);
  const last = sheet.getLastRow();
  if (last < 2) return [];
  return sheet.getRange(2, 1, last - 1, PRICE_HEADERS.length).getValues()
    .filter((r) => r[1] !== '' && r[4] !== '' && !isNaN(Number(r[4])))
    .map((r) => ({
      client: normalizeCode_(r[0]),
      match: String(r[1]).toLowerCase().trim(),
      size: String(r[2]).toLowerCase().trim(),
      colour: String(r[3]).toLowerCase().trim(),
      price: Number(r[4]),
      description: String(r[5] || '').trim(),
    }));
}

/**
 * Finds the first Price List row (top to bottom) that matches the line:
 * same client (or blank = all clients), Match Text found in product title / variant / SKU / product type,
 * and Size / Colour equal (or blank = any). Put specific rows above general ones.
 */
function findPrice_(prices, clientCode, product, variant, sku, size, colour) {
  const text = [product, variant, sku].join(' ').toLowerCase();
  const s = String(size || '').toLowerCase().trim();
  const c = String(colour || '').toLowerCase().trim();
  return prices.find((p) =>
    (!p.client || p.client === clientCode) &&
    text.indexOf(p.match) !== -1 &&
    (!p.size || p.size === s) &&
    (!p.colour || p.colour === c)) || null;
}

function applyPrice_(row, client, prices) {
  const p = findPrice_(prices, client.code, row[C['Product']], row[C['Variant']], row[C['SKU']], row[C['Size']], row[C['Colour']]);
  if (p) {
    row[C['Unit Price']] = p.price;
    row[C['Line Amount']] = round2_(p.price * Number(row[C['Qty']] || 0));
    row[C['Price Status']] = 'OK';
  } else {
    row[C['Unit Price']] = '';
    row[C['Line Amount']] = '';
    row[C['Price Status']] = 'NOT FOUND';
  }
  return p;
}

// ===========================================================================
// Invoicing
// ===========================================================================

function createInvoices_() {
  const ss = SpreadsheetApp.getActive();
  const tz = ss.getSpreadsheetTimeZone();
  const settings = getSettings_();
  const clients = getClients_();
  const prices = loadPriceList_();
  const sheet = ss.getSheetByName(SHEETS.ORDERS);
  const last = sheet.getLastRow();
  if (last < 2) return ['Invoices: no orders.'];

  const data = sheet.getRange(2, 1, last - 1, ORDER_COLS.length).getValues();
  const byClient = new Map();
  data.forEach((row, i) => {
    if (row[C['Invoice No']] || row[C['Cancelled']] === 'Yes' || row[C['Test Order']] === 'Yes') return;
    if (!(Number(row[C['Qty']]) > 0)) return;
    const code = String(row[C['Client Code']]);
    if (!byClient.has(code)) byClient.set(code, []);
    byClient.get(code).push(i);
  });
  if (!byClient.size) return ['Invoices: nothing new to bill.'];

  const messages = [];
  const now = new Date();
  byClient.forEach((idxs, code) => {
    const client = clients.find((c) => c.code === code) || { code: code, brand: code };

    // Re-price with the current Price List so fixes to prices are picked up.
    const missing = [];
    const lines = idxs.map((i) => {
      const row = data[i];
      const p = applyPrice_(row, client, prices);
      if (!p) missing.push(`${row[C['Order No']]} ${row[C['Product']]} ${row[C['Size']]}`.trim());
      return { i: i, row: row, description: (p && p.description) || row[C['Product']] };
    });
    if (missing.length) {
      messages.push(`⚠ ${code}: invoice NOT created – no price for ${missing.length} item(s): ` +
        missing.slice(0, 5).join('; ') + (missing.length > 5 ? '…' : '') +
        '. Add them to the Price List and run "Create invoices".');
      return;
    }

    const inv = buildInvoice_(client, lines, settings, now, tz);
    const pdf = renderInvoicePdf_(ss, inv, settings);
    const file = invoiceFolder_(settings, now, tz).createFile(pdf);
    const emailStatus = emailInvoice_(inv, client, settings, pdf);

    lines.forEach((l) => { data[l.i][C['Invoice No']] = inv.number; });
    ss.getSheetByName(SHEETS.INVOICES).appendRow([
      inv.number, inv.date, code, client.brand, inv.orderCount, inv.itemCount,
      inv.subtotal, inv.shipping, inv.cgst + inv.sgst + inv.igst, inv.total, file.getUrl(), emailStatus,
    ]);
    messages.push(`${code}: invoice ${inv.number} – ₹${inv.total.toFixed(2)} (${inv.orderCount} orders) – ${emailStatus}`);
  });

  // Write back prices and invoice numbers in one go.
  ['Unit Price', 'Line Amount', 'Price Status', 'Invoice No'].forEach((h) => {
    sheet.getRange(2, C[h] + 1, data.length, 1).setValues(data.map((r) => [r[C[h]]]));
  });
  return messages;
}

function buildInvoice_(client, lines, settings, now, tz) {
  lines.sort((a, b) => String(a.row[C['Order No']]).localeCompare(String(b.row[C['Order No']]), undefined, { numeric: true }));
  const orderNos = new Set(lines.map((l) => l.row[C['Order No']]));
  const subtotal = round2_(lines.reduce((s, l) => s + Number(l.row[C['Line Amount']]), 0));
  const shipRate = client.shipping !== '' && client.shipping != null ? Number(client.shipping) : Number(settings['Shipping Charge per Order'] || 0);
  const shipping = round2_(shipRate * orderNos.size);
  const taxable = round2_(subtotal + shipping);
  const gstPct = Number(settings['GST %'] || 0);
  const sameState = settings['Company State'] && client.billingState &&
    String(settings['Company State']).trim().toLowerCase() === String(client.billingState).trim().toLowerCase();
  const gst = round2_(taxable * gstPct / 100);
  const cgst = sameState ? round2_(gst / 2) : 0;
  const sgst = sameState ? round2_(gst - cgst) : 0;
  const igst = sameState ? 0 : gst;
  const exact = round2_(taxable + gst);
  const total = Math.round(exact);

  return {
    number: nextInvoiceNumber_(settings, now, tz),
    date: now,
    dateText: Utilities.formatDate(now, tz, 'dd MMM yyyy'),
    lines: lines,
    orderCount: orderNos.size,
    itemCount: lines.reduce((s, l) => s + Number(l.row[C['Qty']]), 0),
    subtotal, shipRate, shipping, taxable, gstPct, cgst, sgst, igst,
    roundOff: round2_(total - exact),
    total,
    client,
  };
}

function nextInvoiceNumber_(settings, now, tz) {
  const y = Number(Utilities.formatDate(now, tz, 'yyyy'));
  const m = Number(Utilities.formatDate(now, tz, 'M'));
  const fyStart = m >= 4 ? y : y - 1;
  const fy = `${fyStart}-${String((fyStart + 1) % 100).padStart(2, '0')}`;
  const props = PropertiesService.getScriptProperties();
  const key = 'INVOICE_SEQ_' + fy;
  const seq = Number(props.getProperty(key) || 0) + 1;
  props.setProperty(key, String(seq));
  return `${settings['Invoice Prefix'] || 'INV'}/${fy}/${String(seq).padStart(4, '0')}`;
}

/** Lays the invoice out on a temporary tab, exports it as an A4 PDF, then deletes the tab. */
function renderInvoicePdf_(ss, inv, s) {
  const sh = ss.insertSheet('_invoice_' + Date.now());
  try {
    sh.setHiddenGridlines(true);
    [32, 90, 230, 50, 70, 40, 75, 95].forEach((w, i) => sh.setColumnWidth(i + 1, w));
    const W = 8;
    const money = '"₹"#,##0.00';
    let r = 1;

    sh.getRange(r, 1, 1, 5).merge().setValue(s['Company Name']).setFontSize(18).setFontWeight('bold');
    sh.getRange(r, 6, 1, 3).merge().setValue('TAX INVOICE').setFontSize(14).setFontWeight('bold').setHorizontalAlignment('right');
    sh.setRowHeight(r, 30);
    r++;

    const from = [s['Company Address'], [s['Company Phone'], s['Company Email']].filter(Boolean).join(' | '),
      s['Company GSTIN'] ? 'GSTIN: ' + s['Company GSTIN'] : ''].filter(Boolean).join('\n');
    sh.getRange(r, 1, 1, 5).merge().setValue(from).setWrap(true).setVerticalAlignment('top');
    sh.getRange(r, 6, 1, 3).merge().setValue(`Invoice No: ${inv.number}\nDate: ${inv.dateText}`)
      .setHorizontalAlignment('right').setVerticalAlignment('top').setWrap(true);
    sh.setRowHeight(r, 17 * Math.max(lineCount_(from), 2) + 6);
    r += 2;

    const c = inv.client;
    const to = [c.billingName || c.brand, c.billingAddress, c.billingState ? 'State: ' + c.billingState : '',
      c.gstin ? 'GSTIN: ' + c.gstin : ''].filter(Boolean).join('\n');
    sh.getRange(r, 1, 1, W).merge().setValue('BILL TO').setFontWeight('bold').setBackground('#f1f3f4');
    r++;
    sh.getRange(r, 1, 1, W).merge().setValue(to).setWrap(true).setVerticalAlignment('top');
    sh.setRowHeight(r, 17 * lineCount_(to) + 6);
    r += 2;

    const head = ['#', 'Order No', 'Description', 'Size', 'Colour', 'Qty', 'Rate', 'Amount'];
    sh.getRange(r, 1, 1, W).setValues([head]).setFontWeight('bold').setBackground('#202124').setFontColor('#ffffff');
    r++;
    const body = inv.lines.map((l, i) => [i + 1, l.row[C['Order No']], l.description, l.row[C['Size']],
      l.row[C['Colour']], l.row[C['Qty']], l.row[C['Unit Price']], l.row[C['Line Amount']]]);
    const bodyRange = sh.getRange(r, 1, body.length, W);
    bodyRange.setValues(body).setBorder(null, null, true, null, null, true, '#dadce0', SpreadsheetApp.BorderStyle.SOLID);
    sh.getRange(r, 3, body.length, 1).setWrap(true);
    sh.getRange(r, 7, body.length, 2).setNumberFormat(money);
    r += body.length + 1;

    const totals = [['Subtotal', inv.subtotal]];
    if (inv.shipping) totals.push([`Shipping (${inv.orderCount} orders × ₹${inv.shipRate})`, inv.shipping]);
    totals.push(['Taxable value', inv.taxable]);
    if (inv.igst) totals.push([`IGST @ ${inv.gstPct}%`, inv.igst]);
    if (inv.cgst || inv.sgst) {
      totals.push([`CGST @ ${inv.gstPct / 2}%`, inv.cgst]);
      totals.push([`SGST @ ${inv.gstPct / 2}%`, inv.sgst]);
    }
    if (inv.roundOff) totals.push(['Round off', inv.roundOff]);
    totals.forEach(([label, value]) => {
      sh.getRange(r, 1, 1, 7).merge().setValue(label).setHorizontalAlignment('right');
      sh.getRange(r, 8).setValue(value).setNumberFormat(money);
      r++;
    });
    sh.getRange(r, 1, 1, 7).merge().setValue('TOTAL').setHorizontalAlignment('right').setFontWeight('bold');
    sh.getRange(r, 8).setValue(inv.total).setNumberFormat(money).setFontWeight('bold');
    sh.getRange(r, 1, 1, W).setBackground('#f1f3f4');
    r += 2;

    sh.getRange(r, 1, 1, W).merge().setValue(`${inv.orderCount} orders · ${inv.itemCount} items`).setFontColor('#5f6368');
    r += 2;
    if (s['Bank Details']) {
      sh.getRange(r, 1, 1, W).merge().setValue('Bank details').setFontWeight('bold');
      r++;
      sh.getRange(r, 1, 1, W).merge().setValue(s['Bank Details']).setWrap(true).setVerticalAlignment('top');
      sh.setRowHeight(r, 17 * lineCount_(s['Bank Details']) + 6);
      r += 2;
    }
    if (s['Invoice Note']) { sh.getRange(r, 1, 1, W).merge().setValue(s['Invoice Note']); r++; }
    sh.getRange(r, 1, 1, W).merge().setValue('This is a computer-generated invoice.').setFontColor('#5f6368').setFontSize(8);

    SpreadsheetApp.flush();
    const url = `https://docs.google.com/spreadsheets/d/${ss.getId()}/export?format=pdf&gid=${sh.getSheetId()}` +
      '&size=A4&portrait=true&fitw=true&gridlines=false&printtitle=false&sheetnames=false&pagenum=UNDEFINED' +
      '&top_margin=0.5&bottom_margin=0.5&left_margin=0.5&right_margin=0.5';
    const res = UrlFetchApp.fetch(url, { headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken() }, muteHttpExceptions: true });
    if (res.getResponseCode() !== 200) throw new Error(`Could not export invoice PDF (HTTP ${res.getResponseCode()})`);
    const name = `${inv.number.replace(/\//g, '-')} ${inv.client.brand || inv.client.code} ${inv.dateText}.pdf`;
    return res.getBlob().setName(name);
  } finally {
    ss.deleteSheet(sh);
  }
}

function invoiceFolder_(settings, now, tz) {
  const rootName = settings['Invoice Folder'] || 'Looma Apparels Invoices';
  const root = getOrCreateFolder_(DriveApp.getRootFolder(), rootName);
  return getOrCreateFolder_(root, Utilities.formatDate(now, tz, 'yyyy-MM'));
}

function getOrCreateFolder_(parent, name) {
  const it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : parent.createFolder(name);
}

function emailInvoice_(inv, client, settings, pdf) {
  const mode = String(settings['Invoice Email Mode'] || 'Draft').trim().toLowerCase();
  if (mode === 'off') return 'PDF saved (email off)';
  if (!client.billingEmail) return 'PDF saved (no Billing Email for client)';

  const company = settings['Company Name'] || 'LOOMA APPARELS';
  const subject = `Invoice ${inv.number} – ${company} – ${inv.dateText}`;
  const body =
    `Dear ${client.billingName || client.brand},\n\n` +
    `Please find attached our invoice ${inv.number} dated ${inv.dateText} for ` +
    `${inv.orderCount} order(s) / ${inv.itemCount} item(s) printed and shipped for ${client.brand}.\n\n` +
    `Amount due: ₹${inv.total.toFixed(2)}\n\n` +
    (settings['Bank Details'] ? `Bank details:\n${settings['Bank Details']}\n\n` : '') +
    `Regards,\n${company}`;
  const options = { attachments: [pdf], name: company };
  if (settings['Company Email']) options.replyTo = settings['Company Email'];

  if (mode === 'send') {
    GmailApp.sendEmail(client.billingEmail, subject, body, options);
    return 'Emailed to ' + client.billingEmail;
  }
  GmailApp.createDraft(client.billingEmail, subject, body, options);
  return 'Gmail draft created for ' + client.billingEmail;
}

// ===========================================================================
// Sheets: setup, settings, clients
// ===========================================================================

function ensureSheets_() {
  const ss = SpreadsheetApp.getActive();

  const settings = getOrCreateSheet_(ss, SHEETS.SETTINGS, () => {});
  if (settings.getLastRow() === 0) {
    settings.getRange(1, 1, 1, 3).setValues([['Setting', 'Value', 'Notes']]).setFontWeight('bold');
    settings.getRange(2, 1, SETTINGS_DEFAULTS.length, 3).setValues(SETTINGS_DEFAULTS);
    settings.setFrozenRows(1);
    settings.setColumnWidth(1, 200).setColumnWidth(2, 320).setColumnWidth(3, 480);
    settings.getRange('B:B').setWrap(true);
  }

  headerSheet_(ss, SHEETS.CLIENTS, CLIENT_HEADERS, (sh) => {
    sh.getRange('E:E').setNumberFormat('dd-mmm-yyyy');
    sh.getRange('M:M').setNumberFormat('dd-mmm-yyyy hh:mm');
    sh.getRange('D2:D').setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(['Yes', 'No']).build());
    sh.getRange(2, 1, 1, 11).setValues([['BRAND1', 'Example Brand', 'example-brand.myshopify.com', 'No', '',
      'Example Brand Pvt Ltd', 'Street, City – PIN', 'Tamil Nadu', '', 'accounts@example.com', '']]);
  });

  headerSheet_(ss, SHEETS.PRICES, PRICE_HEADERS, (sh) => {
    sh.getRange('E:E').setNumberFormat('"₹"#,##0.00');
    sh.getRange(2, 1, 4, PRICE_HEADERS.length).setValues([
      ['', 'Oversized T-Shirt', '', '', '', 'Oversized T-Shirt – printed'],
      ['', 'Hoodie', '', '', '', 'Hoodie – printed'],
      ['', 'Sweatshirt', '', '', '', 'Sweatshirt – printed'],
      ['', 'T-Shirt', '', '', '', 'Regular T-Shirt – printed'],
    ]);
    sh.getRange(1, 8).setValue('How it works').setFontWeight('bold');
    sh.getRange(2, 8, 5, 1).setValues([
      ['Rows are checked top to bottom; the first match wins, so put specific rows above general ones.'],
      ['Match Text is searched in the Shopify product title, variant and SKU (not case-sensitive).'],
      ['Leave Client Code / Size / Colour blank to match any.'],
      ['Items with no matching price are marked NOT FOUND in Orders and block that client\'s invoice.'],
      ['Unit Price = what Looma charges the brand per piece (blank + print + packing).'],
    ]);
  });

  headerSheet_(ss, SHEETS.ORDERS, ORDER_COLS.concat(TEAM_COLS), (sh) => {
    ORDER_TEXT_COLS.forEach((h) => sh.getRange(1, C[h] + 1, sh.getMaxRows(), 1).setNumberFormat('@'));
    sh.getRange(1, C['Batch Date'] + 1, sh.getMaxRows(), 1).setNumberFormat('dd-mmm-yyyy');
    sh.getRange(1, C['Order Date'] + 1, sh.getMaxRows(), 1).setNumberFormat('dd-mmm-yyyy hh:mm');
    sh.getRange(1, ORDER_COLS.length + 1, 1, TEAM_COLS.length).setBackground('#fff2cc');
    sh.getRange(2, ORDER_COLS.length + 1, sh.getMaxRows() - 1, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(['Pending', 'Printing', 'Packed', 'Shipped', 'Delivered', 'RTO'], true)
        .setAllowInvalid(true).build());
    const status = sh.getRange(2, C['Price Status'] + 1, sh.getMaxRows() - 1, 1);
    sh.setConditionalFormatRules([
      SpreadsheetApp.newConditionalFormatRule().whenTextEqualTo('NOT FOUND').setBackground('#f4c7c3').setRanges([status]).build(),
    ]);
    sh.setColumnWidth(C['Design Preview'] + 1, 80);
    sh.hideColumns(C['Line ID'] + 1);
  });

  getOrCreateSheet_(ss, SHEETS.TODAY, (sh) => {
    const lastCol = colLetter_(ORDER_COLS.length + TEAM_COLS.length);
    sh.getRange('A1').setFormula(
      `=QUERY(Orders!A:${lastCol}, "select * where B = date '"&TEXT(TODAY(),"yyyy-mm-dd")&"'", 1)`);
    sh.setFrozenRows(1);
  });

  headerSheet_(ss, SHEETS.INVOICES, INVOICE_HEADERS, (sh) => {
    sh.getRange('B:B').setNumberFormat('dd-mmm-yyyy');
    sh.getRange('G:J').setNumberFormat('"₹"#,##0.00');
  });
  headerSheet_(ss, SHEETS.LOG, ['Time', 'Run Type', 'Status', 'Duration (s)', 'Details'], (sh) => {
    sh.getRange('A:A').setNumberFormat('dd-mmm-yyyy hh:mm');
    sh.setColumnWidth(5, 700);
    sh.getRange('E:E').setWrap(true);
  });
}

function getOrCreateSheet_(ss, name, onCreate) {
  let sh = ss.getSheetByName(name);
  if (!sh) { sh = ss.insertSheet(name); onCreate(sh); }
  return sh;
}

function headerSheet_(ss, name, headers, onCreate) {
  return getOrCreateSheet_(ss, name, (sh) => {
    sh.getRange(1, 1, 1, headers.length).setValues([headers]).setFontWeight('bold').setBackground('#e8eaed');
    sh.setFrozenRows(1);
    onCreate(sh);
  });
}

function getSettings_() {
  const sh = SpreadsheetApp.getActive().getSheetByName(SHEETS.SETTINGS);
  const out = {};
  if (sh.getLastRow() > 1) {
    sh.getRange(2, 1, sh.getLastRow() - 1, 2).getValues().forEach((r) => { if (r[0]) out[String(r[0]).trim()] = r[1]; });
  }
  return out;
}

function getClients_() {
  const sh = SpreadsheetApp.getActive().getSheetByName(SHEETS.CLIENTS);
  const last = sh.getLastRow();
  if (last < 2) return [];
  return sh.getRange(2, 1, last - 1, CLIENT_HEADERS.length).getValues()
    .map((r, i) => ({
      rowNum: i + 2,
      code: normalizeCode_(r[0]),
      brand: String(r[1] || r[0]).trim(),
      store: String(r[2] || '').trim(),
      active: String(r[3]).trim().toLowerCase() !== 'no',
      startDate: r[4] instanceof Date ? r[4] : null,
      billingName: String(r[5] || '').trim(),
      billingAddress: String(r[6] || '').trim(),
      billingState: String(r[7] || '').trim(),
      gstin: String(r[8] || '').trim(),
      billingEmail: String(r[9] || '').trim(),
      shipping: r[10],
    }))
    .filter((c) => c.code && c.store);
}

function setClientCell_(client, header, value) {
  SpreadsheetApp.getActive().getSheetByName(SHEETS.CLIENTS)
    .getRange(client.rowNum, CLIENT_HEADERS.indexOf(header) + 1).setValue(value);
}

// ===========================================================================
// Sheet upsert helper – rows are keyed by column A
// ===========================================================================

function SheetUpserter_(sheet, width) {
  this.sheet = sheet;
  this.width = width;
  this.index = new Map();
  this.rows = new Map();
  const last = sheet.getLastRow();
  if (last > 1) {
    sheet.getRange(2, 1, last - 1, width).getValues().forEach((r, i) => {
      if (r[0] !== '') { this.index.set(String(r[0]), i + 2); this.rows.set(String(r[0]), r); }
    });
  }
  this.nextRow = Math.max(last, 1) + 1;
}

SheetUpserter_.prototype.upsert = function (rows, merge) {
  const appends = [];
  const pending = new Map();
  rows.forEach((row) => {
    const key = String(row[0]);
    if (pending.has(key)) {
      appends[pending.get(key)] = row;
    } else if (this.index.has(key)) {
      const merged = merge ? merge(this.rows.get(key), row) : row;
      this.sheet.getRange(this.index.get(key), 1, 1, this.width).setValues([merged]);
      this.rows.set(key, merged);
    } else {
      pending.set(key, appends.length);
      appends.push(row);
    }
  });
  if (!appends.length) return;
  const needed = this.nextRow + appends.length - 1 - this.sheet.getMaxRows();
  if (needed > 0) this.sheet.insertRowsAfter(this.sheet.getMaxRows(), needed);
  this.sheet.getRange(this.nextRow, 1, appends.length, this.width).setValues(appends);
  pending.forEach((pos, key) => { this.index.set(key, this.nextRow + pos); this.rows.set(key, appends[pos]); });
  this.nextRow += appends.length;
};

// ===========================================================================
// Shopify API
// ===========================================================================

function credKeys_(code) {
  return {
    token: `CLIENT_${code}_TOKEN`,
    id: `CLIENT_${code}_CLIENT_ID`,
    secret: `CLIENT_${code}_CLIENT_SECRET`,
    cache: `CLIENT_${code}_ACCESS_TOKEN`,
  };
}

function clientConfig_(client) {
  const props = PropertiesService.getScriptProperties();
  const k = credKeys_(client.code);
  const cfg = {
    code: client.code,
    shop: normalizeShop_(client.store),
    apiVersion: props.getProperty('SHOPIFY_API_VERSION') || APP.DEFAULT_API_VERSION,
    token: props.getProperty(k.token),
    clientId: props.getProperty(k.id),
    clientSecret: props.getProperty(k.secret),
    cacheKey: k.cache,
  };
  if (!cfg.token && !(cfg.clientId && cfg.clientSecret)) {
    throw new Error(`No Shopify credentials for ${client.code}. Use Looma POD → Connect a client Shopify store.`);
  }
  return cfg;
}

function testClient_(client) {
  try {
    const cfg = clientConfig_(client);
    const res = shopifyGraphql_(cfg, '{ shop { name myshopifyDomain } }', {});
    setClientCell_(client, 'Connection', 'OK – ' + res.data.shop.name);
    return `Connected ✔ ${res.data.shop.name} (${res.data.shop.myshopifyDomain})`;
  } catch (err) {
    setClientCell_(client, 'Connection', 'Error: ' + String(err.message || err).slice(0, 200));
    return 'Failed: ' + (err.message || err);
  }
}

function getAccessToken_(cfg, forceRefresh) {
  if (cfg.token) return cfg.token;
  const cache = CacheService.getScriptCache();
  if (!forceRefresh) {
    const cached = cache.get(cfg.cacheKey);
    if (cached) return cached;
  }
  const res = UrlFetchApp.fetch(`https://${cfg.shop}/admin/oauth/access_token`, {
    method: 'post',
    payload: { grant_type: 'client_credentials', client_id: cfg.clientId, client_secret: cfg.clientSecret },
    muteHttpExceptions: true,
  });
  if (res.getResponseCode() !== 200) {
    throw new Error(`Could not get a Shopify access token (HTTP ${res.getResponseCode()}): ${truncate_(res.getContentText())}`);
  }
  const body = JSON.parse(res.getContentText());
  cache.put(cfg.cacheKey, body.access_token, Math.min(Math.max((body.expires_in || 3600) - 300, 60), 21600));
  return body.access_token;
}

/** POSTs a GraphQL query with retries. Returns { data, warnings }. */
function shopifyGraphql_(cfg, query, variables) {
  const url = `https://${cfg.shop}/admin/api/${cfg.apiVersion}/graphql.json`;
  let refreshed = false;
  for (let attempt = 1; attempt <= APP.MAX_RETRIES; attempt++) {
    const res = UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json',
      headers: { 'X-Shopify-Access-Token': getAccessToken_(cfg, false) },
      payload: JSON.stringify({ query: query, variables: variables }),
      muteHttpExceptions: true,
    });
    const code = res.getResponseCode();
    const text = res.getContentText();
    if (code === 401 && !cfg.token && !refreshed) { getAccessToken_(cfg, true); refreshed = true; continue; }
    if (code === 429 || code >= 500) { Utilities.sleep(Math.min(1000 * Math.pow(2, attempt), 30000)); continue; }
    if (code !== 200) throw new Error(`Shopify API error (HTTP ${code}): ${truncate_(text)}`);

    const json = JSON.parse(text);
    const errors = Array.isArray(json.errors) ? json.errors : [];
    const codes = errors.map((e) => e.extensions && e.extensions.code);
    if (codes.indexOf('THROTTLED') !== -1) { waitForThrottle_(json.extensions, true); continue; }
    if (codes.indexOf('MAX_COST_EXCEEDED') !== -1) {
      const e = new Error('Shopify query too expensive'); e.code = 'MAX_COST_EXCEEDED'; throw e;
    }
    if (errors.length && !json.data) throw new Error(`Shopify GraphQL error: ${errors.map((e) => e.message).join('; ')}`);
    waitForThrottle_(json.extensions, false);
    return { data: json.data, warnings: errors.map((e) => e.message) };
  }
  throw new Error('Shopify API: gave up after repeated rate-limit / server errors.');
}

function waitForThrottle_(extensions, throttled) {
  const cost = extensions && extensions.cost;
  const status = cost && cost.throttleStatus;
  if (!status) { if (throttled) Utilities.sleep(2000); return; }
  const needed = cost.requestedQueryCost || 0;
  if (throttled || status.currentlyAvailable < needed) {
    const deficit = Math.max(needed - status.currentlyAvailable, 0);
    Utilities.sleep(Math.min(Math.ceil(deficit / (status.restoreRate || 50) * 1000) + 500, 30000));
  }
}

// ===========================================================================
// Utilities
// ===========================================================================

function scheduleContinuation_() {
  deleteTriggersFor_('continueDailyJob');
  ScriptApp.newTrigger('continueDailyJob').timeBased().after(APP.CONTINUATION_DELAY_MS).create();
}

function deleteTriggersFor_(handler) {
  ScriptApp.getProjectTriggers().filter((t) => t.getHandlerFunction() === handler).forEach((t) => ScriptApp.deleteTrigger(t));
}

function writeLog_(source, status, message, started) {
  try {
    const sh = SpreadsheetApp.getActive().getSheetByName(SHEETS.LOG);
    if (!sh) return;
    sh.appendRow([new Date(), source, status, started ? Math.round((Date.now() - started) / 1000) : 0, message]);
  } catch (e) {
    console.error('Could not write log: ' + e);
  }
}

function normalizeShop_(input) {
  let s = String(input || '').trim().toLowerCase();
  const m = s.match(/admin\.shopify\.com\/store\/([^/?#]+)/);
  if (m) return `${m[1]}.myshopify.com`;
  s = s.replace(/^https?:\/\//, '').replace(/[/?#].*$/, '');
  if (s && s.indexOf('.') === -1) s += '.myshopify.com';
  return s;
}

function normalizeCode_(code) {
  return String(code || '').trim().toUpperCase().replace(/[^A-Z0-9_]/g, '_');
}

function promptOrCancel_(ui, title, text) {
  const res = ui.prompt(title, text, ui.ButtonSet.OK_CANCEL);
  if (res.getSelectedButton() !== ui.Button.OK) return null;
  return res.getResponseText().trim();
}

function safeText_(v) {
  return typeof v === 'string' && /^[=+\-@]/.test(v) ? "'" + v : v;
}

function colLetter_(n) {
  let s = '';
  for (; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
  return s;
}

function lineCount_(text) {
  return String(text || '').split('\n').length;
}

function gidToId_(gid) {
  return gid ? String(gid).split('/').pop() : '';
}

function money_(bag) {
  return bag && bag.shopMoney ? Number(bag.shopMoney.amount) : '';
}

function round2_(n) {
  return Math.round(Number(n) * 100) / 100;
}

function truncate_(text) {
  text = String(text || '');
  return text.length > 500 ? text.slice(0, 500) + '…' : text;
}
