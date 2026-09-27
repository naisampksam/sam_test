/**
 * LOOMA APPARELS – Print-on-demand order desk (Google Apps Script, bound to a Google Sheet).
 *
 * Every morning (8–9 AM, spreadsheet time zone) this script:
 *   1. Connects to the Shopify store of every client brand in the "Clients" tab.
 *   2. Pulls new / changed orders into that brand's own "<Brand> Orders" tab in Looma's order-sheet
 *      format (Product Details with GSM / Color / Size / Print, full shipping address block, COD, etc.).
 *   3. Uses the brand's "Product Map" to add the blank (GSM), print sizes and design / mockup
 *      links for the printing team.
 *
 * The "Dashboard" tab shows live counts per brand with links to each brand's orders tab and Shopify
 * admin. "Looma POD → Open dashboard" opens a window with a card + buttons per store and a form to
 * add a new Shopify store.
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
  DASHBOARD: 'Dashboard',
  CLIENTS: 'Clients',
  PRODUCT_MAP: 'Product Map',
  BLANKS: 'Blanks',
  TODAY: "Today's Orders",
  LOG: 'Sync Log',
};

// Blanks from the LOOMA catalog 2026.
const BLANK_HEADERS = ['Blank', 'GSM', 'Fabric', 'Fit', 'Ready-stock Colours'];
const BLANK_DEFAULTS = [
  ['Oversized 250 GSM French Terry', 250, 'French Terry / loopknit, 100% cotton, bio washed', 'Oversized',
    'Black, Royal Blue, Lavender, Red, Green, White, Beige, Brown, Navy Blue'],
  ['Oversized 250 GSM Acid Wash', 250, 'Acid wash French Terry, 100% cotton', 'Oversized',
    'Black, Green, Royal Blue'],
  ['Fullsleeve Oversized 250 GSM', 250, 'French Terry / loopknit, 100% cotton', 'Oversized', 'Black'],
  ['Oversized 230 GSM', 230, 'Single jersey, 100% cotton, bio washed', 'Oversized', 'Black, White'],
  ['Oversized 190 GSM', 190, 'Single jersey, 100% cotton, bio washed', 'Oversized', 'Black, White'],
  ['Regular 190 GSM', 190, 'Single jersey, 100% cotton, bio washed', 'Regular', 'Black, White, Red'],
];

const PRINT_POSITIONS = ['Front', 'Back', 'Side', 'Extra'];

const MAP_HEADERS = ['Client Code', 'Match Text', 'Blank', 'Front Print', 'Back Print', 'Side Print', 'Extra Print',
  'Neck Label', 'Design Drive Link', 'Mockup Folder (ALL)', 'Notes'];

const CLIENT_HEADERS = ['Client Code', 'Brand Name', 'Shopify Store', 'Active', 'Start Date', 'Connection', 'Last Sync',
  'Orders Tab ID'];

// --- "<Brand> Orders" tabs: same layout as Looma's "Customer Orders" sheet ---

const ORDER_COLS = [
  'Sl No', 'Date', 'Brand', 'Order ID', 'Customer Name', 'Country Code', 'Contact Number',
  'Product Details', 'Product Site Link', 'Product Name', 'Qty', 'Mockup Folder (ALL)',
  'Product Design Drive Link', 'COD Payment', 'Payment Method', 'Payment Status', 'Shipping Address',
  // Team columns – set once when the row is created, never overwritten afterwards.
  'Delivery Status', 'Printing Status', 'Delivery Partner', 'Tracking ID', 'Label Status',
  // System columns.
  'Map Status', 'Shopify Status', 'Synced On', 'Client Code', 'Line ID',
];
const C = ORDER_COLS.reduce((m, h, i) => { m[h] = i; return m; }, {});
const TEAM_COLS = ['Delivery Status', 'Printing Status', 'Delivery Partner', 'Tracking ID', 'Label Status'];
// Column ranges [firstIndex, count] the script rewrites when an order changes.
const ORDER_SCRIPT_BLOCKS = [[0, C['Delivery Status']], [C['Map Status'], ORDER_COLS.length - C['Map Status']]];
const ORDER_TEXT_COLS = ['Order ID', 'Country Code', 'Contact Number', 'Tracking ID', 'Line ID'];
const DROPDOWNS = {
  'Delivery Status': ['Select', 'Order Created', 'In Progress', 'Ready to Dispatch', 'Dispatched', 'Delivered', 'RTO', 'Cancelled By Customer'],
  'Printing Status': ['Select', 'Printing Started', 'Printing Done'],
  'Delivery Partner': ['DELHIVERY', 'DTDC', 'EKART', 'BLUE DART', 'INDIA POST', 'ECOM EXPRESS', 'SPEED & SAFE'],
  'Label Status': ['Select', 'Label Shared', 'Label Not Shared'],
};

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
      displayFinancialStatus
      displayFulfillmentStatus
      paymentGatewayNames
      totalOutstandingSet { ...Money }
      shippingAddress {
        name company address1 address2 city province zip country countryCodeV2 phone
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
          variant { selectedOptions { name value } }
          product { handle onlineStoreUrl }
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
    .addItem('Open dashboard (add / manage stores)', 'showDashboard')
    .addSeparator()
    .addItem('1. Set up sheets', 'setupSheets')
    .addItem('2. Connect a client Shopify store…', 'connectClientStore')
    .addItem('3. Test all connections', 'testAllConnections')
    .addItem('4. Enable daily 8 AM job', 'enableDailyJob')
    .addSeparator()
    .addItem('Sync orders now', 'menuSyncOrders')
    .addItem('Refresh Dashboard tab', 'menuRefreshDashboard')
    .addSeparator()
    .addItem('Disable daily job', 'disableDailyJob')
    .addToUi();
}

function setupSheets() {
  ensureSheets_();
  refreshViews_();
  SpreadsheetApp.getUi().alert('Sheets are ready',
    'Next: open Looma POD → Open dashboard and click "+ Add store" for each brand.\n\n' +
    '(You can also add brands by hand in the Clients tab and use "Connect a client Shopify store…".)',
    SpreadsheetApp.getUi().ButtonSet.OK);
}

function menuRefreshDashboard() {
  ensureSheets_();
  refreshViews_();
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
  ordersSheetFor_(client);
  refreshViews_();
  ui.alert(client.brand, result, ui.ButtonSet.OK);
}

function testAllConnections() {
  ensureSheets_();
  const lines = getClients_().filter((c) => c.active).map((c) => `${c.code}: ${testClient_(c)}`);
  SpreadsheetApp.getUi().alert('Connections', lines.join('\n\n') || 'No active clients.', SpreadsheetApp.getUi().ButtonSet.OK);
}

function menuSyncOrders() { runJobFromMenu_(); }

function enableDailyJob() {
  const tz = installDailyTrigger_();
  SpreadsheetApp.getUi().alert('Daily job enabled',
    `Every day between ${APP.DAILY_HOUR}:00 and ${APP.DAILY_HOUR + 1}:00 (${tz}) the script will sync all brand ` +
    'orders. Google picks the exact minute within that hour.\n\n' +
    'Results appear in the "Sync Log" tab.',
    SpreadsheetApp.getUi().ButtonSet.OK);
}

function installDailyTrigger_() {
  deleteTriggersFor_('dailyJob');
  const tz = SpreadsheetApp.getActive().getSpreadsheetTimeZone();
  ScriptApp.newTrigger('dailyJob').timeBased().everyDays(1).atHour(APP.DAILY_HOUR).inTimezone(tz).create();
  return tz;
}

function dailyJobEnabled_() {
  return ScriptApp.getProjectTriggers().some((t) => t.getHandlerFunction() === 'dailyJob');
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
  runJob_('daily');
}

/** One-off trigger that resumes a daily job that hit the time limit. */
function continueDailyJob() {
  deleteTriggersFor_('continueDailyJob');
  runJob_('continuation');
}

// ===========================================================================
// Job runner
// ===========================================================================

function runJobFromMenu_() {
  const ui = SpreadsheetApp.getUi();
  try {
    const res = runJob_('manual');
    ui.alert('Looma POD', res.message, ui.ButtonSet.OK);
  } catch (err) {
    ui.alert('Looma POD – failed', String(err.message || err), ui.ButtonSet.OK);
  }
}

function runJob_(source, onlyCode) {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30 * 1000)) {
    writeLog_(source, 'SKIPPED', 'Another run is in progress.');
    return { message: 'Another run is already in progress; skipped.' };
  }
  const started = Date.now();
  const messages = [];
  try {
    ensureSheets_();
    const s = syncAllClients_(started, onlyCode);
    messages.push(...s.messages);
    const syncComplete = s.complete;
    if (!syncComplete) {
      scheduleContinuation_();
      messages.push('Time limit reached – the job will continue automatically in about a minute.');
    }
    refreshViews_();
    const status = messages.some((m) => /^⚠/.test(m)) ? 'WARN' : (syncComplete ? 'OK' : 'PARTIAL');
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

function syncAllClients_(started, onlyCode) {
  const ss = SpreadsheetApp.getActive();
  const tz = ss.getSpreadsheetTimeZone();
  const catalog = loadCatalog_();
  const clients = getClients_().filter((c) => (onlyCode ? c.code === onlyCode : c.active));
  const messages = [];

  for (const client of clients) {
    if (Date.now() - started > APP.MAX_RUNTIME_MS) return { complete: false, messages: messages };
    try {
      const orders = new OrdersSheet_(ordersSheetFor_(client));
      const r = syncClient_(client, { started, orders, catalog, tz, syncedOn: new Date() });
      setClientCell_(client, 'Last Sync', new Date());
      setClientCell_(client, 'Connection', 'OK');
      messages.push(`${client.code}: ${r.orders} orders / ${r.lines} items synced` +
        (r.unmapped ? ` – ⚠ ${r.unmapped} item(s) not in Product Map` : '') +
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

  const shopInfo = shopifyGraphql_(cfg, '{ shop { primaryDomain { url } } }', {}).data.shop;
  const siteUrl = shopInfo && shopInfo.primaryDomain ? shopInfo.primaryDomain.url.replace(/\/$/, '') : '';

  const res = { orders: 0, lines: 0, unmapped: 0, truncated: 0, warnings: [], complete: true };
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
          const row = orderLineToRow_(client, o, li, idx === 0, ctx, siteUrl);
          // Skip items removed from the order unless we already have them on the sheet.
          if (Number(row[C['Qty']]) > 0 || ctx.orders.has(row[C['Line ID']])) rows.push(row);
        });
      });
      ctx.orders.upsert(rows);
      SpreadsheetApp.flush();
      props.setProperty(ckptKey, page.nodes[page.nodes.length - 1].updatedAt);
      res.orders += page.nodes.length;
      res.lines += rows.length;
      res.unmapped += rows.filter((r) => r[C['Map Status']] === 'NOT IN PRODUCT MAP').length;
    }
    after = page.pageInfo.hasNextPage ? page.pageInfo.endCursor : null;
    if (!after) break;
    if (Date.now() - ctx.started > APP.MAX_RUNTIME_MS) { res.complete = false; break; }
  }
  return res;
}

function orderLineToRow_(client, o, li, firstLine, ctx, siteUrl) {
  const ship = o.shippingAddress || {};
  const opts = variantOptions_(li);
  const gateways = (o.paymentGatewayNames || []).join(', ');
  const isCod = /cash on delivery|\bcod\b/i.test(gateways);
  const phone = splitPhone_(ship.phone || o.phone || '', ship.countryCodeV2);
  const map = findProductMap_(ctx.catalog.maps, client.code, li.title);
  const blank = map && ctx.catalog.blanks[map.blank.toLowerCase()];
  const attrs = (li.customAttributes || []).filter((a) => a.value && !/^_/.test(a.key));
  const attrUrl = (li.customAttributes || []).map((a) => a.value).find((v) => /^https?:\/\//.test(v || ''));

  const details = [
    `GSM: ${blank ? blank.gsm : (map ? map.blank : '?')}`,
    `Color: ${opts.colour}`,
    `Size: ${opts.size}`,
    `Print: ${map ? printSummary_(map) : '?'}`,
  ].concat(attrs.filter((a) => !/^https?:\/\//.test(a.value)).map((a) => `${a.key}: ${a.value}`)).join('\n');

  const address = [
    `Name : ${ship.name || ''}`,
    `Address : ${[ship.company, ship.address1, ship.address2, ship.city, ship.province, ship.country].filter(Boolean).join(', ')}`,
    `Mobile : ${phone.number}`,
    `Email : ${o.email || ''}`,
    `Pincode : ${ship.zip || ''}`,
  ].join('\n');

  const product = li.product || {};
  const siteLink = product.onlineStoreUrl || (product.handle && siteUrl ? `${siteUrl}/products/${product.handle}` : '');

  const row = new Array(ORDER_COLS.length).fill('');
  row[C['Date']] = Utilities.formatDate(new Date(o.createdAt), ctx.tz, 'yyyy-MM-dd');
  row[C['Brand']] = client.brand;
  row[C['Order ID']] = o.name.replace(/^#/, '');
  row[C['Customer Name']] = ship.name || '';
  row[C['Country Code']] = phone.countryCode;
  row[C['Contact Number']] = phone.number;
  row[C['Product Details']] = details;
  row[C['Product Site Link']] = siteLink;
  row[C['Product Name']] = li.title || '';
  row[C['Qty']] = li.currentQuantity;
  row[C['Mockup Folder (ALL)']] = (map && map.mockup) || '';
  row[C['Product Design Drive Link']] = (map && map.design) || attrUrl || '';
  // COD amount only on the first line of each order so column totals are correct.
  row[C['COD Payment']] = firstLine && isCod ? money_(o.totalOutstandingSet) : '';
  row[C['Payment Method']] = isCod ? 'Cash on Delivery' : 'Online Payment';
  row[C['Payment Status']] = paymentStatus_(o.displayFinancialStatus);
  row[C['Shipping Address']] = address;
  row[C['Delivery Status']] = 'Order Created';
  row[C['Printing Status']] = 'Select';
  row[C['Label Status']] = 'Select';
  row[C['Map Status']] = !map ? 'NOT IN PRODUCT MAP' : (blank ? 'OK' : 'UNKNOWN BLANK');
  row[C['Shopify Status']] = o.cancelledAt ? 'Cancelled' : (o.test ? 'Test order' : (o.displayFulfillmentStatus || ''));
  row[C['Synced On']] = ctx.syncedOn;
  row[C['Client Code']] = client.code;
  row[C['Line ID']] = gidToId_(li.id);

  // Stop customer-supplied text (e.g. "+91…", "=…") being treated as a formula.
  return row.map(safeText_);
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
      if (!size && /^(xxs|xs|s|m|l|xl|xxl|xxxl|\dxl)$/i.test(part)) size = part;
      else if (!colour && opts.length === 0 && part && part !== size) colour = part;
    });
  }
  return { size: size, colour: colour };
}

function splitPhone_(raw, countryCode) {
  const digits = String(raw || '').replace(/\D/g, '');
  if (!digits) return { countryCode: '', number: '' };
  if (digits.length > 10) return { countryCode: digits.slice(0, digits.length - 10), number: digits.slice(-10) };
  return { countryCode: !countryCode || countryCode === 'IN' ? '91' : '', number: digits };
}

function paymentStatus_(s) {
  return ({
    PAID: 'Paid', PARTIALLY_PAID: 'Partially Paid', PENDING: 'Pending', AUTHORIZED: 'Pending',
    EXPIRED: 'Pending', REFUNDED: 'Refunded', PARTIALLY_REFUNDED: 'Partially Refunded', VOIDED: 'Voided',
  })[s] || (s || '');
}

// ===========================================================================
// Product Map
// ===========================================================================

function loadCatalog_() {
  const ss = SpreadsheetApp.getActive();
  const rows = (name, width) => {
    const sh = ss.getSheetByName(name);
    return sh.getLastRow() < 2 ? [] : sh.getRange(2, 1, sh.getLastRow() - 1, width).getValues();
  };
  const blanks = {};
  rows(SHEETS.BLANKS, BLANK_HEADERS.length).forEach((r) => {
    if (r[0]) blanks[String(r[0]).trim().toLowerCase()] = { name: String(r[0]).trim(), gsm: r[1] };
  });
  const maps = rows(SHEETS.PRODUCT_MAP, MAP_HEADERS.length)
    .filter((r) => r[0] !== '' || r[1] !== '' || r[2] !== '')
    .map((r) => ({
      client: normalizeCode_(r[0]),
      match: String(r[1]).toLowerCase().trim(),
      blank: String(r[2]).trim(),
      prints: PRINT_POSITIONS.map((pos, i) => ({ pos: pos, size: String(r[3 + i]).trim().toUpperCase() })).filter((p) => p.size),
      neckLabel: /^y/i.test(String(r[7])),
      design: String(r[8] || '').trim(),
      mockup: String(r[9] || '').trim(),
    }))
    .filter((m) => m.blank);
  return { blanks, maps };
}

/**
 * First Product Map row (top to bottom) for this client (or blank = every client) whose
 * Match Text appears in the Shopify product title. Blank Match Text = any product.
 */
function findProductMap_(maps, clientCode, title) {
  const text = String(title || '').toLowerCase();
  return maps.find((m) => (!m.client || m.client === clientCode) && (!m.match || text.indexOf(m.match) !== -1)) || null;
}

function printSummary_(map) {
  const parts = map.prints.map((p) => `${p.pos} (${p.size})`);
  if (map.neckLabel) parts.push('Neck Label');
  return parts.join(' & ') || 'Plain';
}

// ===========================================================================
// Sheets: setup & clients
// ===========================================================================

function ensureSheets_() {
  const ss = SpreadsheetApp.getActive();

  getOrCreateSheet_(ss, SHEETS.DASHBOARD, (sh) => {
    ss.setActiveSheet(sh);
    ss.moveActiveSheet(1);
  });

  const clientsSheet = headerSheet_(ss, SHEETS.CLIENTS, CLIENT_HEADERS, (sh) => {
    sh.getRange('E:E').setNumberFormat('dd-mmm-yyyy');
    sh.getRange('D2:D').setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(['Yes', 'No']).build());
    sh.getRange('G:G').setNumberFormat('dd-mmm-yyyy hh:mm');
  });
  // Sheets created by an earlier version lack the newer columns.
  if (clientsSheet.getRange(1, CLIENT_HEADERS.length).getValue() === '') {
    clientsSheet.getRange(1, 1, 1, CLIENT_HEADERS.length).setValues([CLIENT_HEADERS]).setFontWeight('bold').setBackground('#e8eaed');
  }

  headerSheet_(ss, SHEETS.BLANKS, BLANK_HEADERS, (sh) => {
    sh.getRange(2, 1, BLANK_DEFAULTS.length, BLANK_HEADERS.length).setValues(BLANK_DEFAULTS);
    sh.setColumnWidth(1, 230).setColumnWidth(3, 280).setColumnWidth(5, 330);
  });

  headerSheet_(ss, SHEETS.PRODUCT_MAP, MAP_HEADERS, (sh) => {
    const blanks = SpreadsheetApp.newDataValidation()
      .requireValueInRange(ss.getSheetByName(SHEETS.BLANKS).getRange('A2:A'), true).setAllowInvalid(false).build();
    const prints = SpreadsheetApp.newDataValidation()
      .requireValueInList(['A2', 'A3', 'A4', 'LOGO'], true).setAllowInvalid(true).build();
    sh.getRange('C2:C').setDataValidation(blanks);
    sh.getRange('D2:G').setDataValidation(prints);
    sh.getRange('H2:H').setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(['Yes', 'No']).build());
    sh.getRange(1, MAP_HEADERS.length + 2).setValue('How it works').setFontWeight('bold');
    sh.getRange(2, MAP_HEADERS.length + 2, 5, 1).setValues([
      ['Rows are checked top to bottom; the first match wins – put specific products above a brand\'s default row.'],
      ['Match Text is searched in the Shopify product title (not case-sensitive). Blank = every product of that brand.'],
      ['Blank must be a name from the Blanks tab (gives the GSM). Prints: A2, A3, A4 or LOGO.'],
      ['Product Details in Orders is built from this: GSM / Color / Size / Print.'],
      ['Design Drive Link / Mockup Folder are copied into the Orders tab for the printing team.'],
    ]);
    sh.setColumnWidth(2, 200).setColumnWidth(3, 230).setColumnWidth(11, 420);
  });

  getOrCreateSheet_(ss, SHEETS.TODAY, () => {});

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
      connection: String(r[5] || ''),
      lastSync: r[6] instanceof Date ? r[6] : null,
      tabId: r[7] === '' ? null : Number(r[7]),
    }))
    .filter((c) => c.code && c.store);
}

function setClientCell_(client, header, value) {
  SpreadsheetApp.getActive().getSheetByName(SHEETS.CLIENTS)
    .getRange(client.rowNum, CLIENT_HEADERS.indexOf(header) + 1).setValue(value);
}

// ===========================================================================
// Per-brand orders tabs, Today's Orders and the Dashboard tab
// ===========================================================================

/** Returns the brand's "<Brand> Orders" tab, creating it the first time. */
function ordersSheetFor_(client) {
  const ss = SpreadsheetApp.getActive();
  if (client.tabId != null) {
    const existing = ss.getSheets().find((sh) => sh.getSheetId() === client.tabId);
    if (existing) return existing;
  }
  let name = `${client.brand} Orders`.replace(/[\[\]*?:\\/]/g, ' ').slice(0, 90);
  for (let n = 2; ss.getSheetByName(name); n++) name = `${client.brand} Orders (${n})`;
  const sh = ss.insertSheet(name);
  setupOrdersSheet_(sh);
  client.tabId = sh.getSheetId();
  setClientCell_(client, 'Orders Tab ID', client.tabId);
  return sh;
}

function setupOrdersSheet_(sh) {
  const rows = sh.getMaxRows();
  sh.getRange(1, 1, 1, ORDER_COLS.length).setValues([ORDER_COLS]).setFontWeight('bold').setBackground('#e8eaed');
  sh.setFrozenRows(1);
  ORDER_TEXT_COLS.forEach((h) => sh.getRange(1, C[h] + 1, rows, 1).setNumberFormat('@'));
  sh.getRange(1, C['Date'] + 1, rows, 1).setNumberFormat('dd-mm-yyyy');
  sh.getRange(1, C['Synced On'] + 1, rows, 1).setNumberFormat('dd-mm-yyyy hh:mm');
  sh.getRange(1, C['Delivery Status'] + 1, 1, TEAM_COLS.length).setBackground('#fff2cc');
  Object.keys(DROPDOWNS).forEach((h) => sh.getRange(2, C[h] + 1, rows - 1, 1).setDataValidation(
    SpreadsheetApp.newDataValidation().requireValueInList(DROPDOWNS[h], true).setAllowInvalid(true).build()));
  [C['Product Details'], C['Shipping Address']].forEach((i) => sh.getRange(1, i + 1, rows, 1).setWrap(true));
  sh.setColumnWidth(C['Product Details'] + 1, 190).setColumnWidth(C['Shipping Address'] + 1, 320);
  const lastCol = colLetter_(ORDER_COLS.length);
  const statusCol = colLetter_(C['Shopify Status'] + 1);
  const mapCol = colLetter_(C['Map Status'] + 1);
  sh.setConditionalFormatRules([
    SpreadsheetApp.newConditionalFormatRule().whenFormulaSatisfied(`=$${statusCol}2="Cancelled"`)
      .setBackground('#f4c7c3').setStrikethrough(true).setRanges([sh.getRange(`A2:${lastCol}`)]).build(),
    SpreadsheetApp.newConditionalFormatRule()
      .whenFormulaSatisfied(`=OR($${mapCol}2="NOT IN PRODUCT MAP",$${mapCol}2="UNKNOWN BLANK")`)
      .setBackground('#fce8b2').setRanges([sh.getRange(`${mapCol}2:${mapCol}`)]).build(),
  ]);
  sh.hideColumns(C['Line ID'] + 1);
}

/** Brands with their orders tab (creating missing tabs for brands added by hand in Clients). */
function brandTabs_() {
  return getClients_().map((c) => ({ client: c, sheet: ordersSheetFor_(c) }));
}

function quoteSheet_(name) {
  return `'${name.replace(/'/g, "''")}'`;
}

function refreshViews_() {
  const brands = brandTabs_();
  rebuildTodayView_(brands);
  rebuildDashboard_(brands);
}

/** "Today's Orders" = every brand's rows synced today, stacked into one list. */
function rebuildTodayView_(brands) {
  const sh = SpreadsheetApp.getActive().getSheetByName(SHEETS.TODAY);
  sh.clear();
  sh.getRange(1, 1, 1, ORDER_COLS.length).setValues([ORDER_COLS]).setFontWeight('bold').setBackground('#e8eaed');
  sh.setFrozenRows(1);
  if (!brands.length) return;
  const lastCol = colLetter_(ORDER_COLS.length);
  const stack = brands.map((b) => `${quoteSheet_(b.sheet.getName())}!A2:${lastCol}`).join(';');
  sh.getRange('A2').setFormula(
    `=IFERROR(QUERY({${stack}}, "select * where Col${C['Synced On'] + 1} >= datetime '"&TEXT(TODAY(),"yyyy-mm-dd")&" 00:00:00'", 0), "No new orders synced today")`);
  sh.getRange(2, C['Synced On'] + 1, sh.getMaxRows() - 1, 1).setNumberFormat('dd-mm-yyyy hh:mm');
  sh.getRange(2, C['Date'] + 1, sh.getMaxRows() - 1, 1).setNumberFormat('dd-mm-yyyy');
}

const DASH_METRICS = [
  // [label, formula builder(col => range string)]
  ["Today's orders", (r) => `IFERROR(COUNTUNIQUEIFS(${r('Order ID')},${r('Synced On')},">="&TODAY()),0)`],
  ['To print', (r) => `COUNTIFS(${r('Printing Status')},"Select",${r('Shopify Status')},"<>Cancelled")`],
  ['Printing', (r) => `COUNTIFS(${r('Printing Status')},"Printing Started")`],
  ['Ready to dispatch', (r) => `COUNTIFS(${r('Delivery Status')},"Ready to Dispatch")`],
  ['Dispatched', (r) => `COUNTIFS(${r('Delivery Status')},"Dispatched")`],
  ['Delivered', (r) => `COUNTIFS(${r('Delivery Status')},"Delivered")`],
  ['Cancelled', (r) => `COUNTIFS(${r('Shopify Status')},"Cancelled")`],
  ['Not in Product Map', (r) => `COUNTIF(${r('Map Status')},"NOT IN PRODUCT MAP")+COUNTIF(${r('Map Status')},"UNKNOWN BLANK")`],
];

/** Formula-driven Dashboard tab: KPI tiles + one row per brand with links. Stays live as the team edits. */
function rebuildDashboard_(brands) {
  const ss = SpreadsheetApp.getActive();
  const sh = ss.getSheetByName(SHEETS.DASHBOARD);
  sh.getRange(1, 1, sh.getMaxRows(), sh.getMaxColumns()).breakApart();
  sh.clear();
  sh.setHiddenGridlines(true);
  const head = ['Brand', 'Active', 'Connection', 'Last Sync'].concat(DASH_METRICS.map((m) => m[0]), ['Orders', 'Shopify']);
  const W = head.length;

  sh.getRange(1, 1, 1, W).merge().setValue('LOOMA APPARELS · POD Dashboard')
    .setFontSize(18).setFontWeight('bold').setFontColor('#ffffff').setBackground('#0b1a33').setVerticalAlignment('middle');
  sh.setRowHeight(1, 44);
  sh.getRange(2, 1, 1, W).merge()
    .setValue(`Orders sync automatically every morning ${APP.DAILY_HOUR}–${APP.DAILY_HOUR + 1} AM` +
      (dailyJobEnabled_() ? '.' : ' – NOT ENABLED yet (Looma POD → 4. Enable daily 8 AM job).') +
      '   To add a store or sync one brand: Looma POD → Open dashboard.')
    .setFontColor('#5f6368');

  // Brand table
  const top = 8;
  sh.getRange(top, 1, 1, W).setValues([head]).setFontWeight('bold').setBackground('#e8eaed').setWrap(true);
  const clientsName = quoteSheet_(SHEETS.CLIENTS);
  const rows = brands.map((b) => {
    const tab = quoteSheet_(b.sheet.getName());
    const r = (h) => `${tab}!${colLetter_(C[h] + 1)}2:${colLetter_(C[h] + 1)}`;
    const cr = (h) => `=${clientsName}!${colLetter_(CLIENT_HEADERS.indexOf(h) + 1)}${b.client.rowNum}`;
    return [b.client.brand, cr('Active'), cr('Connection'), cr('Last Sync')]
      .concat(DASH_METRICS.map((m) => '=' + m[1](r)))
      .concat([`=HYPERLINK("#gid=${b.sheet.getSheetId()}","Open orders ↗")`,
        `=HYPERLINK("https://admin.shopify.com/store/${normalizeShop_(b.client.store).replace(/\.myshopify\.com$/, '')}/orders","Shopify admin ↗")`]);
  });
  if (rows.length) {
    sh.getRange(top + 1, 1, rows.length, W).setValues(rows).setVerticalAlignment('middle');
    sh.getRange(top + 1, 4, rows.length, 1).setNumberFormat('dd-mmm hh:mm');
    const totalRow = top + 1 + rows.length;
    const totals = ['TOTAL', '', '', ''].concat(DASH_METRICS.map((m, i) => {
      const col = colLetter_(5 + i);
      return `=SUM(${col}${top + 1}:${col}${totalRow - 1})`;
    }), ['', '']);
    sh.getRange(totalRow, 1, 1, W).setValues([totals]).setFontWeight('bold').setBackground('#f1f3f4');

    // KPI tiles (rows 4-5) pointing at the totals row
    DASH_METRICS.forEach((m, i) => {
      const col = 1 + i + 4;
      sh.getRange(4, col).setValue(m[0]).setFontColor('#5f6368').setWrap(true);
      sh.getRange(5, col).setFormula(`=${colLetter_(5 + i)}${totalRow}`).setFontSize(22).setFontWeight('bold');
    });
    sh.getRange(4, 5, 2, DASH_METRICS.length).setBackground('#f8f9fa').setHorizontalAlignment('center');
  } else {
    sh.getRange(top + 1, 1, 1, W).merge().setValue('No stores yet – open Looma POD → Open dashboard → + Add store.');
  }
  sh.getRange(4, 1, 2, 4).merge().setValue('All brands').setFontWeight('bold').setFontSize(14).setVerticalAlignment('middle');
  sh.setRowHeight(5, 40);
  sh.setColumnWidth(1, 170).setColumnWidth(2, 60).setColumnWidth(3, 190).setColumnWidth(4, 100);
  for (let i = 5; i <= W; i++) sh.setColumnWidth(i, 95);
  sh.setFrozenRows(top);
}

// ===========================================================================
// Dashboard window (Looma POD → Open dashboard): store cards, buttons, "+ Add store"
// Functions without a trailing underscore are called from the window via google.script.run.
// ===========================================================================

function showDashboard() {
  ensureSheets_();
  const html = HtmlService.createHtmlOutput(DASHBOARD_HTML).setWidth(1040).setHeight(700);
  SpreadsheetApp.getUi().showModalDialog(html, 'Looma POD – Stores');
}

function getDashboardData() {
  const ss = SpreadsheetApp.getActive();
  const tz = ss.getSpreadsheetTimeZone();
  const todayStart = new Date(Utilities.formatDate(new Date(), tz, "yyyy-MM-dd'T'00:00:00XXX"));
  const stores = brandTabs_().map(({ client, sheet }) => {
    const m = { today: 0, toPrint: 0, printing: 0, ready: 0, dispatched: 0, delivered: 0, cancelled: 0, unmapped: 0 };
    const last = sheet.getLastRow();
    if (last > 1) {
      const todayOrders = new Set();
      sheet.getRange(2, 1, last - 1, ORDER_COLS.length).getValues().forEach((r) => {
        if (!r[C['Line ID']]) return;
        const cancelled = r[C['Shopify Status']] === 'Cancelled';
        if (r[C['Synced On']] instanceof Date && r[C['Synced On']] >= todayStart) todayOrders.add(r[C['Order ID']]);
        if (r[C['Printing Status']] === 'Select' && !cancelled) m.toPrint++;
        if (r[C['Printing Status']] === 'Printing Started') m.printing++;
        if (r[C['Delivery Status']] === 'Ready to Dispatch') m.ready++;
        if (r[C['Delivery Status']] === 'Dispatched') m.dispatched++;
        if (r[C['Delivery Status']] === 'Delivered') m.delivered++;
        if (cancelled) m.cancelled++;
        if (/NOT IN PRODUCT MAP|UNKNOWN BLANK/.test(r[C['Map Status']])) m.unmapped++;
      });
      m.today = todayOrders.size;
    }
    const shop = normalizeShop_(client.store);
    return {
      code: client.code,
      brand: client.brand,
      store: shop,
      active: client.active,
      connection: client.connection,
      lastSync: client.lastSync ? Utilities.formatDate(client.lastSync, tz, 'dd MMM, hh:mm a') : 'Never',
      adminUrl: `https://admin.shopify.com/store/${shop.replace(/\.myshopify\.com$/, '')}/orders`,
      sheetUrl: `${ss.getUrl()}#gid=${sheet.getSheetId()}`,
      metrics: m,
    };
  });
  return {
    stores: stores,
    dailyEnabled: dailyJobEnabled_(),
    blanks: Object.values(loadCatalog_().blanks).map((b) => b.name),
    today: Utilities.formatDate(new Date(), tz, 'yyyy-MM-dd'),
  };
}

/** Adds a new store (or updates an existing one's store address / access) after testing the connection. */
function addStore(f) {
  const brand = String(f.brand || '').trim();
  const shop = normalizeShop_(f.store);
  if (!brand) throw new Error('Enter the brand name.');
  if (!/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/.test(shop)) {
    throw new Error('Enter the store\'s .myshopify.com address (e.g. outfitcrew.myshopify.com), not the website address.');
  }
  const token = String(f.token || '').trim();
  const clientId = String(f.clientId || '').trim();
  const clientSecret = String(f.clientSecret || '').trim();
  if (!token && !(clientId && clientSecret)) throw new Error('Enter the Admin API access token, or the Client ID and Client secret.');

  const clients = getClients_();
  let client = f.code ? clients.find((c) => c.code === normalizeCode_(f.code)) : null;
  const code = client ? client.code : uniqueCode_(normalizeCode_(brand).replace(/^_+|_+$/g, '') || 'BRAND', clients);

  // Test before saving anything.
  const cfg = { code, shop, apiVersion: PropertiesService.getScriptProperties().getProperty('SHOPIFY_API_VERSION') || APP.DEFAULT_API_VERSION,
    token: token || null, clientId: clientId || null, clientSecret: clientSecret || null, cacheKey: credKeys_(code).cache };
  CacheService.getScriptCache().remove(cfg.cacheKey);
  let shopName;
  try {
    shopName = shopifyGraphql_(cfg, '{ shop { name } }', {}).data.shop.name;
  } catch (err) {
    throw new Error('Could not connect to Shopify: ' + (err.message || err));
  }

  const props = PropertiesService.getScriptProperties();
  const k = credKeys_(code);
  if (token) {
    props.setProperty(k.token, token); props.deleteProperty(k.id); props.deleteProperty(k.secret);
  } else {
    props.setProperty(k.id, clientId); props.setProperty(k.secret, clientSecret); props.deleteProperty(k.token);
  }

  const sh = SpreadsheetApp.getActive().getSheetByName(SHEETS.CLIENTS);
  if (client) {
    sh.getRange(client.rowNum, 2, 1, 3).setValues([[brand, shop, 'Yes']]);
    sh.getRange(client.rowNum, 6).setValue('OK – ' + shopName);
  } else {
    const row = sh.getLastRow() + 1;
    sh.getRange(row, 1, 1, 6).setValues([[code, brand, shop, 'Yes', f.startDate || '', 'OK – ' + shopName]]);
    if (f.blank) {
      const map = SpreadsheetApp.getActive().getSheetByName(SHEETS.PRODUCT_MAP);
      map.getRange(lastRowInColumnA_(map) + 1, 1, 1, MAP_HEADERS.length).setValues([[
        code, '', f.blank, f.front || '', f.back || '', f.side || '', '', f.neckLabel ? 'Yes' : 'No',
        f.design || '', f.mockup || '', `Default for every ${brand} product – add rows ABOVE for products that differ`]]);
    }
    client = getClients_().find((c) => c.code === code);
  }
  ordersSheetFor_(client);
  refreshViews_();
  return `${brand} connected ✔ (${shopName}). Its orders tab is ready – click "Sync now" to pull orders.`;
}

/** Last used row in column A (the Product Map has help notes further right). */
function lastRowInColumnA_(sh) {
  const vals = sh.getRange(1, 1, Math.max(sh.getLastRow(), 1), 1).getValues();
  for (let i = vals.length - 1; i >= 0; i--) if (vals[i][0] !== '') return i + 1;
  return 1;
}

function uniqueCode_(base, clients) {
  let code = base.slice(0, 20);
  for (let n = 2; clients.some((c) => c.code === code); n++) code = `${base.slice(0, 18)}_${n}`;
  return code;
}

function syncStore(code) {
  return runJob_('manual', normalizeCode_(code)).message;
}

function syncAllStores() {
  return runJob_('manual').message;
}

function setStoreActive(code, active) {
  const client = getClients_().find((c) => c.code === normalizeCode_(code));
  if (!client) throw new Error('Store not found.');
  setClientCell_(client, 'Active', active ? 'Yes' : 'No');
  return active ? `${client.brand} resumed – it will sync every morning.` : `${client.brand} paused – it won't sync until resumed.`;
}

function openStoreTab(code) {
  const client = getClients_().find((c) => c.code === normalizeCode_(code));
  if (client) SpreadsheetApp.getActive().setActiveSheet(ordersSheetFor_(client));
}

function enableDailyFromDashboard() {
  installDailyTrigger_();
  refreshViews_();
  return `Daily sync enabled – every morning between ${APP.DAILY_HOUR}:00 and ${APP.DAILY_HOUR + 1}:00.`;
}

const PRINT_OPTIONS_HTML = ['', 'A2', 'A3', 'A4', 'LOGO'].map((p) => `<option value="${p}">${p || 'None'}</option>`).join('');

const DASHBOARD_HTML = `<!DOCTYPE html>
<html><head><base target="_top"><meta charset="utf-8">
<style>
  :root { --ink:#0b1a33; --muted:#5f6368; --line:#e3e6ea; --bg:#f6f7f9; --ok:#1e8e3e; --warn:#b06000; --bad:#c5221f; }
  * { box-sizing:border-box; }
  body { margin:0; font:14px/1.4 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color:var(--ink); background:var(--bg); }
  header { display:flex; align-items:center; gap:12px; padding:14px 18px; background:var(--ink); color:#fff; }
  header h1 { font-size:17px; margin:0; flex:1; letter-spacing:.3px; }
  button { font:inherit; border:1px solid var(--line); background:#fff; color:var(--ink); border-radius:6px; padding:6px 11px; cursor:pointer; }
  button:hover { background:#eef1f5; }
  button.primary { background:#1a73e8; border-color:#1a73e8; color:#fff; }
  button.primary:hover { background:#1666cc; }
  button:disabled { opacity:.55; cursor:default; }
  header button { border-color:rgba(255,255,255,.35); background:transparent; color:#fff; }
  header button:hover { background:rgba(255,255,255,.12); }
  header button.primary { background:#fff; color:var(--ink); border-color:#fff; }
  main { padding:14px 18px 24px; }
  .banner { padding:9px 12px; border-radius:6px; margin-bottom:12px; background:#fef7e0; color:#7a4f01; display:flex; gap:10px; align-items:center; }
  .banner.info { background:#e8f0fe; color:#174ea6; }
  .banner.err { background:#fce8e6; color:var(--bad); }
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(310px, 1fr)); gap:12px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px; display:flex; flex-direction:column; gap:10px; }
  .card.paused { opacity:.7; }
  .top { display:flex; align-items:flex-start; gap:8px; }
  .top h2 { font-size:16px; margin:0; flex:1; }
  .store { color:var(--muted); font-size:12px; }
  .pill { font-size:11px; padding:2px 8px; border-radius:99px; white-space:nowrap; }
  .pill.ok { background:#e6f4ea; color:var(--ok); } .pill.bad { background:#fce8e6; color:var(--bad); } .pill.off { background:#eee; color:var(--muted); }
  .stats { display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; }
  .stat { background:var(--bg); border-radius:6px; padding:6px; text-align:center; }
  .stat b { display:block; font-size:18px; }
  .stat span { font-size:11px; color:var(--muted); }
  .stat.alert b { color:var(--warn); }
  .meta { font-size:12px; color:var(--muted); }
  .actions { display:flex; flex-wrap:wrap; gap:6px; }
  .empty { text-align:center; padding:50px 20px; color:var(--muted); background:#fff; border:1px dashed var(--line); border-radius:10px; }
  form { background:#fff; border:1px solid var(--line); border-radius:10px; padding:16px; max-width:760px; }
  form h2 { margin:0 0 4px; font-size:17px; }
  fieldset { border:0; padding:0; margin:14px 0 0; }
  legend { font-weight:600; margin-bottom:6px; }
  .row { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:8px; }
  .row.four { grid-template-columns:repeat(4, 1fr); }
  label { display:flex; flex-direction:column; gap:3px; font-size:12px; color:var(--muted); }
  input, select { font:inherit; color:var(--ink); padding:7px 8px; border:1px solid #cfd4da; border-radius:6px; }
  .hint { font-size:12px; color:var(--muted); margin:2px 0 8px; }
  .or { text-align:center; font-size:12px; color:var(--muted); margin:4px 0 8px; }
  .formbar { display:flex; gap:8px; justify-content:flex-end; margin-top:14px; }
  .hidden { display:none !important; }
</style></head>
<body>
<header>
  <h1>LOOMA APPARELS · POD Stores</h1>
  <button id="btnSyncAll">Sync all now</button>
  <button id="btnAdd" class="primary">+ Add store</button>
</header>
<main>
  <div id="msg" class="banner info hidden"></div>
  <div id="dailyBanner" class="banner hidden">Daily 8 AM sync is not turned on yet. <button id="btnDaily">Turn it on</button></div>
  <div id="list"><div class="empty">Loading stores…</div></div>

  <form id="form" class="hidden" autocomplete="off">
    <h2 id="formTitle">Add a Shopify store</h2>
    <div class="hint">The connection is tested before anything is saved.</div>
    <input type="hidden" name="code">
    <fieldset><legend>Brand</legend>
      <div class="row">
        <label>Brand name<input name="brand" required placeholder="Outfitcrew"></label>
        <label>Shopify store address<input name="store" required placeholder="outfitcrew.myshopify.com"></label>
      </div>
      <div class="row" id="startRow">
        <label>Import orders placed from<input type="date" name="startDate"></label>
        <div></div>
      </div>
    </fieldset>
    <fieldset><legend>Shopify access</legend>
      <label>Admin API access token (starts with shpat_)<input name="token" placeholder="shpat_…"></label>
      <div class="or">— or, for a Dev Dashboard app —</div>
      <div class="row">
        <label>Client ID<input name="clientId"></label>
        <label>Client secret<input name="clientSecret" type="password"></label>
      </div>
    </fieldset>
    <fieldset id="mapSet"><legend>Default product (can be changed later in the Product Map tab)</legend>
      <div class="row">
        <label>T-shirt (blank)<select name="blank"><option value="">– decide later –</option></select></label>
        <label>Neck label<select name="neckLabel"><option value="1">Yes</option><option value="">No</option></select></label>
      </div>
      <div class="row four">
        <label>Front print<select name="front">${PRINT_OPTIONS_HTML}</select></label>
        <label>Back print<select name="back">${PRINT_OPTIONS_HTML}</select></label>
        <label>Side print<select name="side">${PRINT_OPTIONS_HTML}</select></label>
        <div></div>
      </div>
      <div class="row">
        <label>Design Drive link (optional)<input name="design" placeholder="https://drive.google.com/…"></label>
        <label>Mockup folder link (optional)<input name="mockup" placeholder="https://drive.google.com/…"></label>
      </div>
    </fieldset>
    <div class="formbar">
      <button type="button" id="btnCancel">Cancel</button>
      <button type="submit" class="primary" id="btnSave">Test & save</button>
    </div>
  </form>
</main>
<script>
  var data = null;
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
  function show(text, kind) { var m = $('msg'); m.textContent = text; m.className = 'banner ' + (kind || 'info'); }
  function busy(on) { document.querySelectorAll('button').forEach(function (b) { b.disabled = on; }); }
  function call(fn, args, done) {
    busy(true);
    google.script.run
      .withSuccessHandler(function (r) { busy(false); done && done(r); })
      .withFailureHandler(function (e) { busy(false); show(e.message || String(e), 'err'); })[fn].apply(null, args || []);
  }
  function load() { call('getDashboardData', [], render); }

  function render(d) {
    data = d;
    $('dailyBanner').classList.toggle('hidden', d.dailyEnabled);
    var sel = document.querySelector('select[name=blank]');
    sel.innerHTML = '<option value="">– decide later –</option>' + d.blanks.map(function (b) { return '<option>' + esc(b) + '</option>'; }).join('');
    if (!d.stores.length) {
      $('list').innerHTML = '<div class="empty"><p><b>No stores yet.</b></p><p>Click <b>+ Add store</b> to connect the first brand\\'s Shopify store.</p></div>';
      return;
    }
    $('list').innerHTML = '<div class="grid">' + d.stores.map(card).join('') + '</div>';
  }

  function stat(v, label, alert) { return '<div class="stat' + (alert && v ? ' alert' : '') + '"><b>' + v + '</b><span>' + label + '</span></div>'; }

  function card(s) {
    var m = s.metrics;
    var ok = /^OK/.test(s.connection);
    var pill = !s.active ? '<span class="pill off">Paused</span>' :
      (ok ? '<span class="pill ok">Connected</span>' : (s.connection ? '<span class="pill bad">Error</span>' : '<span class="pill off">Not synced</span>'));
    return '<div class="card' + (s.active ? '' : ' paused') + '">' +
      '<div class="top"><div style="flex:1"><h2>' + esc(s.brand) + '</h2><div class="store">' + esc(s.store) + '</div></div>' + pill + '</div>' +
      '<div class="stats">' + stat(m.today, "Today's orders") + stat(m.toPrint, 'To print') + stat(m.ready, 'Ready to ship') + stat(m.dispatched, 'Dispatched') + '</div>' +
      '<div class="meta">Last sync: ' + esc(s.lastSync) +
        (m.cancelled ? ' · ' + m.cancelled + ' cancelled' : '') +
        (m.unmapped ? ' · <b style="color:#b06000">' + m.unmapped + ' not in Product Map</b>' : '') +
        (s.connection && !ok ? '<br><span style="color:#c5221f">' + esc(s.connection) + '</span>' : '') + '</div>' +
      '<div class="actions">' +
        '<button class="primary" data-act="open" data-code="' + esc(s.code) + '">Open orders</button>' +
        '<button data-act="sync" data-code="' + esc(s.code) + '">Sync now</button>' +
        '<button data-act="admin" data-url="' + esc(s.adminUrl) + '">Shopify admin ↗</button>' +
        '<button data-act="edit" data-code="' + esc(s.code) + '">Update access</button>' +
        '<button data-act="toggle" data-code="' + esc(s.code) + '" data-active="' + (s.active ? '1' : '') + '">' + (s.active ? 'Pause' : 'Resume') + '</button>' +
      '</div></div>';
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]');
    if (!b) return;
    var code = b.getAttribute('data-code');
    var act = b.getAttribute('data-act');
    if (act === 'open') call('openStoreTab', [code], function () { google.script.host.close(); });
    if (act === 'admin') window.open(b.getAttribute('data-url'), '_blank');
    if (act === 'sync') { show('Syncing… this can take a minute.'); call('syncStore', [code], function (r) { show(r); load(); }); }
    if (act === 'toggle') call('setStoreActive', [code, !b.getAttribute('data-active')], function (r) { show(r); load(); });
    if (act === 'edit') openForm(data.stores.filter(function (s) { return s.code === code; })[0]);
  });

  function openForm(store) {
    var f = $('form');
    f.reset();
    f.code.value = store ? store.code : '';
    f.brand.value = store ? store.brand : '';
    f.store.value = store ? store.store : '';
    f.startDate.value = data.today;
    $('formTitle').textContent = store ? 'Update Shopify access – ' + store.brand : 'Add a Shopify store';
    $('startRow').classList.toggle('hidden', !!store);
    $('mapSet').classList.toggle('hidden', !!store);
    $('list').classList.add('hidden');
    f.classList.remove('hidden');
    $('msg').classList.add('hidden');
    f.brand.focus();
  }
  function closeForm() { $('form').classList.add('hidden'); $('list').classList.remove('hidden'); }

  $('btnAdd').onclick = function () { openForm(null); };
  $('btnCancel').onclick = closeForm;
  $('btnDaily').onclick = function () { call('enableDailyFromDashboard', [], function (r) { show(r); load(); }); };
  $('btnSyncAll').onclick = function () { show('Syncing all stores… this can take a few minutes.'); call('syncAllStores', [], function (r) { show(r); load(); }); };
  $('form').onsubmit = function (e) {
    e.preventDefault();
    var f = e.target, v = {};
    ['code','brand','store','startDate','token','clientId','clientSecret','blank','neckLabel','front','back','side','design','mockup']
      .forEach(function (k) { v[k] = f[k].value; });
    show('Testing the Shopify connection…');
    call('addStore', [v], function (r) { show(r); closeForm(); load(); });
  };
  load();
</script>
</body></html>`;

// ===========================================================================
// Orders sheet – rows keyed by Line ID; team columns are never overwritten
// ===========================================================================

function OrdersSheet_(sheet) {
  this.sheet = sheet;
  this.width = ORDER_COLS.length;
  this.index = new Map();
  this.rows = new Map();
  this.maxSl = 0;
  const last = sheet.getLastRow();
  if (last > 1) {
    sheet.getRange(2, 1, last - 1, this.width).getValues().forEach((r, i) => {
      const key = String(r[C['Line ID']]);
      if (key) { this.index.set(key, i + 2); this.rows.set(key, r); }
      this.maxSl = Math.max(this.maxSl, Number(r[C['Sl No']]) || 0);
    });
  }
  this.nextRow = Math.max(last, 1) + 1;
}

OrdersSheet_.prototype.has = function (key) {
  return this.index.has(String(key));
};

OrdersSheet_.prototype.upsert = function (rows) {
  const appends = [];
  const pending = new Map();
  rows.forEach((row) => {
    const key = String(row[C['Line ID']]);
    if (pending.has(key)) {
      const prev = appends[pending.get(key)];
      row[C['Sl No']] = prev[C['Sl No']];
      appends[pending.get(key)] = row;
    } else if (this.index.has(key)) {
      const merged = mergeOrderRow_(this.rows.get(key), row);
      const rowNum = this.index.get(key);
      ORDER_SCRIPT_BLOCKS.forEach(([start, count]) => {
        this.sheet.getRange(rowNum, start + 1, 1, count).setValues([merged.slice(start, start + count)]);
      });
      this.rows.set(key, merged);
    } else {
      row[C['Sl No']] = ++this.maxSl;
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

/** Keeps Sl No, first-synced time and the team's columns when an order is re-synced. */
function mergeOrderRow_(oldRow, newRow) {
  const out = newRow.slice();
  ['Sl No', 'Synced On'].concat(TEAM_COLS).forEach((h) => { out[C[h]] = oldRow[C[h]]; });
  return out;
}

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

function gidToId_(gid) {
  return gid ? String(gid).split('/').pop() : '';
}

function money_(bag) {
  return bag && bag.shopMoney ? Number(bag.shopMoney.amount) : '';
}

function truncate_(text) {
  text = String(text || '');
  return text.length > 500 ? text.slice(0, 500) + '…' : text;
}
