/**
 * LOOMA APPARELS – Print-on-demand order desk (Google Apps Script, bound to a Google Sheet).
 *
 * Every morning (8–9 AM, spreadsheet time zone) this script:
 *   1. Connects to the Shopify store of every client brand in the "Clients" tab.
 *   2. Pulls new / changed orders into the "Orders" tab in Looma's order-sheet format
 *      (Product Details with GSM / Color / Size / Print, full shipping address block, COD, etc.).
 *   3. Uses the brand's "Product Map" to add the blank (GSM), print sizes and design / mockup
 *      links for the printing team.
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
  CLIENTS: 'Clients',
  PRODUCT_MAP: 'Product Map',
  BLANKS: 'Blanks',
  ORDERS: 'Orders',
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

const CLIENT_HEADERS = ['Client Code', 'Brand Name', 'Shopify Store', 'Active', 'Start Date', 'Connection', 'Last Sync'];

// --- Orders tab: same layout as Looma's "Customer Orders" sheet -------------

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
    .addItem('1. Set up sheets', 'setupSheets')
    .addItem('2. Connect a client Shopify store…', 'connectClientStore')
    .addItem('3. Test all connections', 'testAllConnections')
    .addItem('4. Enable daily 8 AM job', 'enableDailyJob')
    .addSeparator()
    .addItem('Sync orders now', 'menuSyncOrders')
    .addSeparator()
    .addItem('Disable daily job', 'disableDailyJob')
    .addToUi();
}

function setupSheets() {
  ensureSheets_();
  SpreadsheetApp.getUi().alert('Sheets are ready',
    'Next:\n' +
    '• Clients – add each brand (code, name, Shopify store).\n' +
    '• Product Map – tell the script which blank and print sizes each brand\'s products use.\n' +
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
  ui.alert(client.brand, testClient_(client), ui.ButtonSet.OK);
}

function testAllConnections() {
  ensureSheets_();
  const lines = getClients_().filter((c) => c.active).map((c) => `${c.code}: ${testClient_(c)}`);
  SpreadsheetApp.getUi().alert('Connections', lines.join('\n\n') || 'No active clients.', SpreadsheetApp.getUi().ButtonSet.OK);
}

function menuSyncOrders() { runJobFromMenu_(); }

function enableDailyJob() {
  deleteTriggersFor_('dailyJob');
  const tz = SpreadsheetApp.getActive().getSpreadsheetTimeZone();
  ScriptApp.newTrigger('dailyJob').timeBased().everyDays(1).atHour(APP.DAILY_HOUR).inTimezone(tz).create();
  SpreadsheetApp.getUi().alert('Daily job enabled',
    `Every day between ${APP.DAILY_HOUR}:00 and ${APP.DAILY_HOUR + 1}:00 (${tz}) the script will sync all brand ` +
    'orders. Google picks the exact minute within that hour.\n\n' +
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

function runJob_(source) {
  const lock = LockService.getScriptLock();
  if (!lock.tryLock(30 * 1000)) {
    writeLog_(source, 'SKIPPED', 'Another run is in progress.');
    return { message: 'Another run is already in progress; skipped.' };
  }
  const started = Date.now();
  const messages = [];
  try {
    ensureSheets_();
    const s = syncAllClients_(started);
    messages.push(...s.messages);
    const syncComplete = s.complete;
    if (!syncComplete) {
      scheduleContinuation_();
      messages.push('Time limit reached – the job will continue automatically in about a minute.');
    }
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

function syncAllClients_(started) {
  const ss = SpreadsheetApp.getActive();
  const tz = ss.getSpreadsheetTimeZone();
  const catalog = loadCatalog_();
  const orders = new OrdersSheet_(ss.getSheetByName(SHEETS.ORDERS));
  const clients = getClients_().filter((c) => c.active);
  const messages = [];

  for (const client of clients) {
    if (Date.now() - started > APP.MAX_RUNTIME_MS) return { complete: false, messages: messages };
    try {
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

  headerSheet_(ss, SHEETS.CLIENTS, CLIENT_HEADERS, (sh) => {
    sh.getRange('E:E').setNumberFormat('dd-mmm-yyyy');
    sh.getRange('D2:D').setDataValidation(SpreadsheetApp.newDataValidation().requireValueInList(['Yes', 'No']).build());
    sh.getRange('G:G').setNumberFormat('dd-mmm-yyyy hh:mm');
    sh.getRange(2, 1, 1, 4).setValues([['OUTFITCREW', 'Outfitcrew', 'your-store.myshopify.com', 'No']]);
  });

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
    sh.getRange(2, 1, 1, MAP_HEADERS.length).setValues([[
      'OUTFITCREW', '', 'Oversized 250 GSM French Terry', 'A4', 'A3', '', '', 'Yes', '', '',
      'EXAMPLE – default for every Outfitcrew product. Add rows ABOVE it for products that differ.']]);
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

  headerSheet_(ss, SHEETS.ORDERS, ORDER_COLS, (sh) => {
    const rows = sh.getMaxRows();
    ORDER_TEXT_COLS.forEach((h) => sh.getRange(1, C[h] + 1, rows, 1).setNumberFormat('@'));
    sh.getRange(1, C['Date'] + 1, rows, 1).setNumberFormat('dd-mm-yyyy');
    sh.getRange(1, C['Synced On'] + 1, rows, 1).setNumberFormat('dd-mm-yyyy hh:mm');
    sh.getRange(1, C['Delivery Status'] + 1, 1, TEAM_COLS.length).setBackground('#fff2cc');
    Object.keys(DROPDOWNS).forEach((h) => sh.getRange(2, C[h] + 1, rows - 1, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(DROPDOWNS[h], true).setAllowInvalid(true).build()));
    [C['Product Details'], C['Shipping Address']].forEach((i) => sh.getRange(1, i + 1, rows, 1).setWrap(true));
    sh.setColumnWidth(C['Product Details'] + 1, 190).setColumnWidth(C['Shipping Address'] + 1, 320);
    const lastCol = colLetter_(ORDER_COLS.length);
    const all = sh.getRange(`A2:${lastCol}`);
    const statusCol = colLetter_(C['Shopify Status'] + 1);
    const mapCol = colLetter_(C['Map Status'] + 1);
    sh.setConditionalFormatRules([
      SpreadsheetApp.newConditionalFormatRule().whenFormulaSatisfied(`=$${statusCol}2="Cancelled"`)
        .setBackground('#f4c7c3').setStrikethrough(true).setRanges([all]).build(),
      SpreadsheetApp.newConditionalFormatRule()
        .whenFormulaSatisfied(`=OR($${mapCol}2="NOT IN PRODUCT MAP",$${mapCol}2="UNKNOWN BLANK")`)
        .setBackground('#fce8b2').setRanges([sh.getRange(`${mapCol}2:${mapCol}`)]).build(),
    ]);
    sh.hideColumns(C['Line ID'] + 1);
  });

  getOrCreateSheet_(ss, SHEETS.TODAY, (sh) => {
    const lastCol = colLetter_(ORDER_COLS.length);
    const synced = colLetter_(C['Synced On'] + 1);
    sh.getRange('A1').setFormula(
      `=QUERY(Orders!A:${lastCol}, "select * where ${synced} >= datetime '"&TEXT(TODAY(),"yyyy-mm-dd")&" 00:00:00'", 1)`);
    sh.setFrozenRows(1);
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
    }))
    .filter((c) => c.code && c.store);
}

function setClientCell_(client, header, value) {
  SpreadsheetApp.getActive().getSheetByName(SHEETS.CLIENTS)
    .getRange(client.rowNum, CLIENT_HEADERS.indexOf(header) + 1).setValue(value);
}

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
