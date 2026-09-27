/**
 * Shopify → Google Sheets order sync (Google Apps Script, container-bound).
 *
 * - Pulls every order from the Shopify Admin GraphQL API into an "Orders" tab
 *   (one row per order) and a "Line Items" tab (one row per line item).
 * - First run imports full history; later runs are incremental (orders
 *   created OR updated since the last run), and existing rows are updated in
 *   place, so refunds / fulfilments / cancellations are reflected.
 * - A time-driven trigger runs the sync every day between 8:00 and 9:00 AM in
 *   the spreadsheet's time zone (Apps Script picks a slot inside that hour).
 * - Long imports are split across several executions to stay under the
 *   Apps Script 6-minute limit.
 *
 * Setup: see README.md, or use the "Shopify Sync" menu in the spreadsheet.
 */

const CONFIG = {
  DEFAULT_API_VERSION: '2026-07',
  ORDERS_SHEET: 'Orders',
  LINE_ITEMS_SHEET: 'Line Items',
  LOG_SHEET: 'Sync Log',
  DAILY_HOUR: 8,                      // 8 AM, spreadsheet time zone
  PAGE_SIZE: 20,                      // orders per API request (keeps query cost < 1000)
  LINE_ITEMS_PER_ORDER: 25,           // line items fetched per order
  MAX_RUNTIME_MS: 4.5 * 60 * 1000,    // stop and resume before the 6-min limit
  CONTINUATION_DELAY_MS: 60 * 1000,
  MAX_RETRIES: 6,
};

const PROP = {
  STORE: 'SHOPIFY_STORE',
  TOKEN: 'SHOPIFY_ACCESS_TOKEN',
  CLIENT_ID: 'SHOPIFY_CLIENT_ID',
  CLIENT_SECRET: 'SHOPIFY_CLIENT_SECRET',
  API_VERSION: 'SHOPIFY_API_VERSION',
  CHECKPOINT: 'SYNC_CHECKPOINT_UPDATED_AT',
};

const TOKEN_CACHE_KEY = 'shopify_client_credentials_token';

const ORDER_HEADERS = [
  'Order ID', 'Order Name', 'Created At', 'Updated At', 'Processed At',
  'Cancelled At', 'Cancel Reason', 'Closed At',
  'Financial Status', 'Fulfillment Status',
  'Currency', 'Presentment Currency',
  'Subtotal', 'Discounts', 'Shipping', 'Tax', 'Total', 'Total Refunded', 'Current Total',
  'Discount Codes', 'Payment Gateways', 'Shipping Method',
  'Customer ID', 'Customer First Name', 'Customer Last Name', 'Email', 'Phone',
  'Customer Order Count',
  'Shipping Name', 'Shipping Company', 'Shipping Address 1', 'Shipping Address 2',
  'Shipping City', 'Shipping Province', 'Shipping Zip', 'Shipping Country', 'Shipping Phone',
  'Billing Name', 'Billing City', 'Billing Country',
  'Tags', 'Note', 'Source', 'Test Order',
  'Total Quantity', 'Line Items Truncated', 'Admin URL', 'Last Synced',
];

const LINE_ITEM_HEADERS = [
  'Line Item ID', 'Order ID', 'Order Name', 'Order Created At',
  'Product ID', 'Variant ID', 'SKU', 'Product Title', 'Variant Title', 'Vendor',
  'Quantity', 'Current Quantity', 'Unit Price', 'Total Discount',
  'Line Total (after discounts)', 'Currency', 'Requires Shipping',
];

// Columns stored as plain text so long IDs are never reformatted.
const ORDER_TEXT_COLUMNS = ['Order ID', 'Customer ID'];
const LINE_ITEM_TEXT_COLUMNS = ['Line Item ID', 'Order ID', 'Product ID', 'Variant ID', 'SKU'];

const ORDERS_QUERY = `
fragment Money on MoneyBag { shopMoney { amount currencyCode } }
fragment Address on MailingAddress {
  name company address1 address2 city province provinceCode zip country countryCodeV2 phone
}
query Orders($first: Int!, $after: String, $query: String, $lineItems: Int!) {
  orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      name
      createdAt
      updatedAt
      processedAt
      cancelledAt
      cancelReason
      closedAt
      displayFinancialStatus
      displayFulfillmentStatus
      currencyCode
      presentmentCurrencyCode
      subtotalPriceSet { ...Money }
      totalDiscountsSet { ...Money }
      totalShippingPriceSet { ...Money }
      totalTaxSet { ...Money }
      totalPriceSet { ...Money }
      totalRefundedSet { ...Money }
      currentTotalPriceSet { ...Money }
      discountCodes
      paymentGatewayNames
      shippingLines(first: 3) { nodes { title } }
      email
      phone
      customer { id firstName lastName numberOfOrders }
      shippingAddress { ...Address }
      billingAddress { ...Address }
      tags
      note
      sourceName
      test
      subtotalLineItemsQuantity
      lineItems(first: $lineItems) {
        pageInfo { hasNextPage }
        nodes {
          id
          title
          variantTitle
          sku
          vendor
          quantity
          currentQuantity
          requiresShipping
          product { id }
          variant { id }
          originalUnitPriceSet { ...Money }
          totalDiscountSet { ...Money }
          discountedTotalSet { ...Money }
        }
      }
    }
  }
}`;

// ---------------------------------------------------------------------------
// Menu
// ---------------------------------------------------------------------------

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Shopify Sync')
    .addItem('1. Configure Shopify connection…', 'configureConnection')
    .addItem('2. Test connection', 'testConnection')
    .addItem('3. Enable daily 8 AM sync', 'enableDailySync')
    .addSeparator()
    .addItem('Sync now', 'syncNow')
    .addItem('Full re-import (clear & reload all orders)', 'fullResync')
    .addSeparator()
    .addItem('Disable daily sync', 'disableDailySync')
    .addToUi();
}

function configureConnection() {
  const ui = SpreadsheetApp.getUi();
  const props = PropertiesService.getScriptProperties();

  const store = promptOrCancel_(ui, 'Shopify store',
    'Enter your store domain, e.g. "my-store.myshopify.com" (or just "my-store").');
  if (store === null) return;
  props.setProperty(PROP.STORE, normalizeShop_(store));

  const token = promptOrCancel_(ui, 'Admin API access token',
    'Paste the Admin API access token (starts with "shpat_").\n\n' +
    'Using a Dev Dashboard app with a Client ID + Client secret instead? Leave this blank and press OK.');
  if (token === null) return;

  if (token) {
    props.setProperty(PROP.TOKEN, token);
    props.deleteProperty(PROP.CLIENT_ID);
    props.deleteProperty(PROP.CLIENT_SECRET);
  } else {
    const clientId = promptOrCancel_(ui, 'Client ID', 'Paste the app\'s Client ID.');
    if (!clientId) return;
    const clientSecret = promptOrCancel_(ui, 'Client secret', 'Paste the app\'s Client secret.');
    if (!clientSecret) return;
    props.setProperty(PROP.CLIENT_ID, clientId);
    props.setProperty(PROP.CLIENT_SECRET, clientSecret);
    props.deleteProperty(PROP.TOKEN);
  }
  CacheService.getScriptCache().remove(TOKEN_CACHE_KEY);

  testConnection();
}

function testConnection() {
  const ui = SpreadsheetApp.getUi();
  try {
    const cfg = getConfig_();
    const res = shopifyGraphql_(cfg, '{ shop { name myshopifyDomain currencyCode } ordersCount(limit: null) { count } }', {});
    const shop = res.data.shop;
    const count = res.data.ordersCount ? res.data.ordersCount.count : 'unknown';
    ui.alert('Connected ✔',
      `Store: ${shop.name} (${shop.myshopifyDomain})\nCurrency: ${shop.currencyCode}\n` +
      `Orders visible to this app: ${count}\n\n` +
      'Next: run "Sync now" for the first import, then "Enable daily 8 AM sync".',
      ui.ButtonSet.OK);
  } catch (err) {
    ui.alert('Connection failed', String(err.message || err), ui.ButtonSet.OK);
  }
}

function syncNow() {
  const ui = SpreadsheetApp.getUi();
  try {
    const result = runSync_('manual');
    ui.alert('Shopify sync', result.message, ui.ButtonSet.OK);
  } catch (err) {
    ui.alert('Shopify sync failed', String(err.message || err), ui.ButtonSet.OK);
  }
}

function fullResync() {
  const ui = SpreadsheetApp.getUi();
  const answer = ui.alert('Full re-import',
    'This clears the Orders and Line Items tabs and downloads every order again. Continue?',
    ui.ButtonSet.YES_NO);
  if (answer !== ui.Button.YES) return;

  const ss = SpreadsheetApp.getActive();
  [CONFIG.ORDERS_SHEET, CONFIG.LINE_ITEMS_SHEET].forEach((name) => {
    const sheet = ss.getSheetByName(name);
    if (sheet) sheet.clearContents();
  });
  PropertiesService.getScriptProperties().deleteProperty(PROP.CHECKPOINT);
  syncNow();
}

function enableDailySync() {
  deleteTriggersFor_('dailySync');
  const tz = SpreadsheetApp.getActive().getSpreadsheetTimeZone();
  ScriptApp.newTrigger('dailySync')
    .timeBased()
    .everyDays(1)
    .atHour(CONFIG.DAILY_HOUR)
    .inTimezone(tz)
    .create();
  SpreadsheetApp.getUi().alert('Daily sync enabled',
    `Orders will sync every day between ${CONFIG.DAILY_HOUR}:00 and ${CONFIG.DAILY_HOUR + 1}:00 (${tz}).\n` +
    'Google picks the exact minute inside that hour. Results are written to the "Sync Log" tab.',
    SpreadsheetApp.getUi().ButtonSet.OK);
}

function disableDailySync() {
  deleteTriggersFor_('dailySync');
  deleteTriggersFor_('continueSync');
  SpreadsheetApp.getUi().alert('Daily sync disabled.');
}

// ---------------------------------------------------------------------------
// Trigger entry points
// ---------------------------------------------------------------------------

/** Called by the daily 8 AM trigger. */
function dailySync() {
  runSync_('daily');
}

/** Called by the one-off trigger that resumes a sync that hit the time limit. */
function continueSync() {
  deleteTriggersFor_('continueSync');
  runSync_('continuation');
}

// ---------------------------------------------------------------------------
// Sync
// ---------------------------------------------------------------------------

function runSync_(source) {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30 * 1000)) {
    const msg = 'Another sync is already running; skipped.';
    writeLog_(source, 'SKIPPED', 0, 0, 0, msg);
    return { message: msg };
  }

  const started = Date.now();
  let ordersSynced = 0;
  let lineItemsSynced = 0;
  const warnings = [];

  try {
    const cfg = getConfig_();
    const props = PropertiesService.getScriptProperties();
    const ss = SpreadsheetApp.getActive();
    const orderSheet = new SheetUpserter_(ss, CONFIG.ORDERS_SHEET, ORDER_HEADERS, ORDER_TEXT_COLUMNS);
    const lineSheet = new SheetUpserter_(ss, CONFIG.LINE_ITEMS_SHEET, LINE_ITEM_HEADERS, LINE_ITEM_TEXT_COLUMNS);

    const checkpoint = props.getProperty(PROP.CHECKPOINT);
    // ">=" re-reads the last order from the previous run; the upsert makes that harmless
    // and guarantees nothing updated in the same second is skipped.
    const search = checkpoint ? `updated_at:>="${checkpoint}"` : null;
    const syncedAt = new Date();

    let after = null;
    let timedOut = false;
    do {
      const res = shopifyGraphql_(cfg, ORDERS_QUERY, {
        first: CONFIG.PAGE_SIZE,
        after: after,
        query: search,
        lineItems: CONFIG.LINE_ITEMS_PER_ORDER,
      });
      res.warnings.forEach((w) => { if (warnings.indexOf(w) === -1) warnings.push(w); });

      const page = res.data.orders;
      const orders = page.nodes;
      if (orders.length) {
        orderSheet.upsert(orders.map((o) => orderToRow_(o, cfg, syncedAt)));
        const lineRows = [];
        orders.forEach((o) => o.lineItems.nodes.forEach((li) => lineRows.push(lineItemToRow_(li, o))));
        lineSheet.upsert(lineRows);

        // Persist progress only once the rows are safely written.
        SpreadsheetApp.flush();
        props.setProperty(PROP.CHECKPOINT, orders[orders.length - 1].updatedAt);
        ordersSynced += orders.length;
        lineItemsSynced += lineRows.length;
      }

      after = page.pageInfo.hasNextPage ? page.pageInfo.endCursor : null;
      if (after && Date.now() - started > CONFIG.MAX_RUNTIME_MS) {
        timedOut = true;
      }
    } while (after && !timedOut);

    let message;
    if (timedOut) {
      scheduleContinuation_();
      message = `Synced ${ordersSynced} orders so far. More remain — the sync will continue automatically in about a minute.`;
    } else {
      message = `Synced ${ordersSynced} orders (${lineItemsSynced} line items).`;
    }
    if (warnings.length) message += `\nWarnings: ${warnings.join(' | ')}`;

    writeLog_(source, timedOut ? 'PARTIAL' : 'OK', ordersSynced, lineItemsSynced, started, message);
    return { message: message };
  } catch (err) {
    writeLog_(source, 'ERROR', ordersSynced, lineItemsSynced, started, String(err.message || err));
    throw err; // re-throw so Apps Script emails a failure notice for trigger runs
  } finally {
    lock.releaseLock();
  }
}

function orderToRow_(o, cfg, syncedAt) {
  const ship = o.shippingAddress || {};
  const bill = o.billingAddress || {};
  const cust = o.customer || {};
  const orderId = gidToId_(o.id);
  return [
    orderId,
    o.name,
    toDate_(o.createdAt),
    toDate_(o.updatedAt),
    toDate_(o.processedAt),
    toDate_(o.cancelledAt),
    o.cancelReason || '',
    toDate_(o.closedAt),
    o.displayFinancialStatus || '',
    o.displayFulfillmentStatus || '',
    o.currencyCode || '',
    o.presentmentCurrencyCode || '',
    money_(o.subtotalPriceSet),
    money_(o.totalDiscountsSet),
    money_(o.totalShippingPriceSet),
    money_(o.totalTaxSet),
    money_(o.totalPriceSet),
    money_(o.totalRefundedSet),
    money_(o.currentTotalPriceSet),
    (o.discountCodes || []).join(', '),
    (o.paymentGatewayNames || []).join(', '),
    ((o.shippingLines && o.shippingLines.nodes) || []).map((s) => s.title).join(', '),
    cust.id ? gidToId_(cust.id) : '',
    cust.firstName || '',
    cust.lastName || '',
    o.email || '',
    o.phone || '',
    cust.numberOfOrders != null ? Number(cust.numberOfOrders) : '',
    ship.name || '',
    ship.company || '',
    ship.address1 || '',
    ship.address2 || '',
    ship.city || '',
    ship.province || '',
    ship.zip || '',
    ship.country || '',
    ship.phone || '',
    bill.name || '',
    bill.city || '',
    bill.country || '',
    (o.tags || []).join(', '),
    o.note || '',
    o.sourceName || '',
    o.test ? 'Yes' : 'No',
    o.subtotalLineItemsQuantity != null ? o.subtotalLineItemsQuantity : '',
    o.lineItems.pageInfo.hasNextPage ? 'Yes' : 'No',
    `https://admin.shopify.com/store/${cfg.shop.replace(/\.myshopify\.com$/, '')}/orders/${orderId}`,
    syncedAt,
  ];
}

function lineItemToRow_(li, order) {
  return [
    gidToId_(li.id),
    gidToId_(order.id),
    order.name,
    toDate_(order.createdAt),
    li.product ? gidToId_(li.product.id) : '',
    li.variant ? gidToId_(li.variant.id) : '',
    li.sku || '',
    li.title || '',
    li.variantTitle || '',
    li.vendor || '',
    li.quantity,
    li.currentQuantity,
    money_(li.originalUnitPriceSet),
    money_(li.totalDiscountSet),
    money_(li.discountedTotalSet),
    order.currencyCode || '',
    li.requiresShipping ? 'Yes' : 'No',
  ];
}

// ---------------------------------------------------------------------------
// Sheet upsert helper (rows keyed by the value in column A)
// ---------------------------------------------------------------------------

function SheetUpserter_(ss, name, headers, textColumns) {
  let sheet = ss.getSheetByName(name);
  if (!sheet) sheet = ss.insertSheet(name);
  this.sheet = sheet;
  this.width = headers.length;

  const firstRow = sheet.getLastRow() >= 1
    ? sheet.getRange(1, 1, 1, this.width).getValues()[0]
    : [];
  if (firstRow.join('\u0001') !== headers.join('\u0001')) {
    sheet.getRange(1, 1, 1, this.width).setValues([headers]).setFontWeight('bold');
    sheet.setFrozenRows(1);
  }
  textColumns.forEach((col) => {
    const idx = headers.indexOf(col) + 1;
    if (idx > 0) sheet.getRange(1, idx, sheet.getMaxRows(), 1).setNumberFormat('@');
  });

  this.index = new Map();
  const last = sheet.getLastRow();
  if (last > 1) {
    sheet.getRange(2, 1, last - 1, 1).getValues().forEach((r, i) => {
      if (r[0] !== '') this.index.set(String(r[0]), i + 2);
    });
  }
  this.nextRow = Math.max(last, 1) + 1;
}

SheetUpserter_.prototype.upsert = function (rows) {
  const appends = [];
  const pending = new Map(); // key -> position in appends
  rows.forEach((row) => {
    const key = String(row[0]);
    if (pending.has(key)) {
      appends[pending.get(key)] = row;
    } else if (this.index.has(key)) {
      this.sheet.getRange(this.index.get(key), 1, 1, this.width).setValues([row]);
    } else {
      pending.set(key, appends.length);
      appends.push(row);
    }
  });
  if (!appends.length) return;

  const needed = this.nextRow + appends.length - 1 - this.sheet.getMaxRows();
  if (needed > 0) this.sheet.insertRowsAfter(this.sheet.getMaxRows(), needed);
  this.sheet.getRange(this.nextRow, 1, appends.length, this.width).setValues(appends);
  pending.forEach((pos, key) => this.index.set(key, this.nextRow + pos));
  this.nextRow += appends.length;
};

// ---------------------------------------------------------------------------
// Shopify API
// ---------------------------------------------------------------------------

function getConfig_() {
  const props = PropertiesService.getScriptProperties();
  const shop = props.getProperty(PROP.STORE);
  const token = props.getProperty(PROP.TOKEN);
  const clientId = props.getProperty(PROP.CLIENT_ID);
  const clientSecret = props.getProperty(PROP.CLIENT_SECRET);
  if (!shop) throw new Error('Shopify store is not configured. Use Shopify Sync → Configure Shopify connection.');
  if (!token && !(clientId && clientSecret)) {
    throw new Error('Shopify credentials are missing. Use Shopify Sync → Configure Shopify connection.');
  }
  return {
    shop: normalizeShop_(shop),
    apiVersion: props.getProperty(PROP.API_VERSION) || CONFIG.DEFAULT_API_VERSION,
    token: token,
    clientId: clientId,
    clientSecret: clientSecret,
  };
}

/**
 * Returns an Admin API access token: the static one if configured, otherwise
 * one obtained via the client credentials grant (cached until shortly before it expires).
 */
function getAccessToken_(cfg, forceRefresh) {
  if (cfg.token) return cfg.token;

  const cache = CacheService.getScriptCache();
  if (!forceRefresh) {
    const cached = cache.get(TOKEN_CACHE_KEY);
    if (cached) return cached;
  }

  const res = UrlFetchApp.fetch(`https://${cfg.shop}/admin/oauth/access_token`, {
    method: 'post',
    payload: {
      grant_type: 'client_credentials',
      client_id: cfg.clientId,
      client_secret: cfg.clientSecret,
    },
    muteHttpExceptions: true,
  });
  if (res.getResponseCode() !== 200) {
    throw new Error(`Could not get a Shopify access token (HTTP ${res.getResponseCode()}): ${truncate_(res.getContentText())}`);
  }
  const body = JSON.parse(res.getContentText());
  const ttl = Math.min(Math.max((body.expires_in || 3600) - 300, 60), 21600);
  cache.put(TOKEN_CACHE_KEY, body.access_token, ttl);
  return body.access_token;
}

/**
 * POSTs a GraphQL query with retries for rate limits / transient errors.
 * Returns { data, warnings } where warnings are non-fatal GraphQL errors
 * (e.g. access denied to protected customer fields).
 */
function shopifyGraphql_(cfg, query, variables) {
  const url = `https://${cfg.shop}/admin/api/${cfg.apiVersion}/graphql.json`;
  let refreshedToken = false;

  for (let attempt = 1; attempt <= CONFIG.MAX_RETRIES; attempt++) {
    const res = UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json',
      headers: { 'X-Shopify-Access-Token': getAccessToken_(cfg, false) },
      payload: JSON.stringify({ query: query, variables: variables }),
      muteHttpExceptions: true,
    });
    const code = res.getResponseCode();
    const text = res.getContentText();

    if (code === 401 && !cfg.token && !refreshedToken) {
      getAccessToken_(cfg, true); // cached client-credentials token expired early
      refreshedToken = true;
      continue;
    }
    if (code === 429 || code >= 500) {
      Utilities.sleep(Math.min(1000 * Math.pow(2, attempt), 30000));
      continue;
    }
    if (code !== 200) {
      throw new Error(`Shopify API error (HTTP ${code}): ${truncate_(text)}`);
    }

    const json = JSON.parse(text);
    const errors = Array.isArray(json.errors) ? json.errors : [];
    if (errors.some((e) => e.extensions && e.extensions.code === 'THROTTLED')) {
      waitForThrottle_(json.extensions, true);
      continue;
    }
    if (errors.length && !json.data) {
      throw new Error(`Shopify GraphQL error: ${errors.map((e) => e.message).join('; ')}`);
    }
    waitForThrottle_(json.extensions, false);
    return { data: json.data, warnings: errors.map((e) => e.message) };
  }
  throw new Error('Shopify API: gave up after repeated rate-limit / server errors.');
}

/** Sleeps if the GraphQL cost bucket is too low for another similar request. */
function waitForThrottle_(extensions, throttled) {
  const cost = extensions && extensions.cost;
  const status = cost && cost.throttleStatus;
  if (!status) {
    if (throttled) Utilities.sleep(2000);
    return;
  }
  const needed = cost.requestedQueryCost || 0;
  if (throttled || status.currentlyAvailable < needed) {
    const deficit = Math.max(needed - status.currentlyAvailable, 0);
    const seconds = deficit / (status.restoreRate || 50);
    Utilities.sleep(Math.min(Math.ceil(seconds * 1000) + 500, 30000));
  }
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

function scheduleContinuation_() {
  deleteTriggersFor_('continueSync');
  ScriptApp.newTrigger('continueSync').timeBased().after(CONFIG.CONTINUATION_DELAY_MS).create();
}

function deleteTriggersFor_(handler) {
  ScriptApp.getProjectTriggers()
    .filter((t) => t.getHandlerFunction() === handler)
    .forEach((t) => ScriptApp.deleteTrigger(t));
}

function writeLog_(source, status, orders, lineItems, started, message) {
  try {
    const ss = SpreadsheetApp.getActive();
    let sheet = ss.getSheetByName(CONFIG.LOG_SHEET);
    if (!sheet) {
      sheet = ss.insertSheet(CONFIG.LOG_SHEET);
      sheet.appendRow(['Time', 'Run Type', 'Status', 'Orders', 'Line Items', 'Duration (s)', 'Message']);
      sheet.getRange(1, 1, 1, 7).setFontWeight('bold');
      sheet.setFrozenRows(1);
    }
    const duration = started ? Math.round((Date.now() - started) / 1000) : 0;
    sheet.appendRow([new Date(), source, status, orders, lineItems, duration, message]);
  } catch (e) {
    console.error('Could not write sync log: ' + e);
  }
}

function normalizeShop_(input) {
  let s = String(input || '').trim().toLowerCase();
  const adminMatch = s.match(/admin\.shopify\.com\/store\/([^/?#]+)/);
  if (adminMatch) return `${adminMatch[1]}.myshopify.com`;
  s = s.replace(/^https?:\/\//, '').replace(/[/?#].*$/, '');
  if (s && s.indexOf('.') === -1) s += '.myshopify.com';
  return s;
}

function promptOrCancel_(ui, title, text) {
  const res = ui.prompt(title, text, ui.ButtonSet.OK_CANCEL);
  if (res.getSelectedButton() !== ui.Button.OK) return null;
  return res.getResponseText().trim();
}

function gidToId_(gid) {
  return gid ? String(gid).split('/').pop() : '';
}

function toDate_(iso) {
  return iso ? new Date(iso) : '';
}

function money_(bag) {
  return bag && bag.shopMoney ? Number(bag.shopMoney.amount) : '';
}

function truncate_(text) {
  text = String(text || '');
  return text.length > 500 ? text.slice(0, 500) + '…' : text;
}
