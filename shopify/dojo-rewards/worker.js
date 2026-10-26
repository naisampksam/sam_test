/**
 * HAIKUFIT · Dojo Run rewards (Cloudflare Worker)
 *
 * Creates a unique, single-use, expiring Shopify discount code for each player
 * who really reaches a reward score in the Dojo Run game. The Shopify key lives
 * only here (never in the theme), so codes cannot be copied from the website.
 *
 *   POST /start  -> signed run ticket (when a battle starts)
 *   POST /claim  -> { code, value, label, expiresAt } (when a battle ends)
 *
 * Checks before a code is made:
 *   - the ticket is genuine (HMAC signed) and each ticket is used once
 *   - the score is physically possible for the time the run actually lasted
 *   - the reward % is decided here from the score, never trusted from the browser
 *   - limits per visitor (IP) per day and a total daily budget
 *   - requests only from your own store domains
 *
 * Settings (Worker → Settings → Variables and secrets), see README.md:
 *   SHOP              your-store.myshopify.com
 *   CLIENT_ID         Dev Dashboard app client ID          (secret)
 *   CLIENT_SECRET     Dev Dashboard app client secret      (secret)
 *   ADMIN_TOKEN       optional: a legacy shpat_ token instead of CLIENT_ID/SECRET (secret)
 *   SIGNING_SECRET    any long random text                 (secret)
 *   ALLOWED_ORIGINS   https://haikufit.in,https://www.haikufit.in
 *   TIERS             [{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]
 *                     (₹ off; or use "percent" instead of "amount" for % off)
 *   MAX_CREDIT_USE    100        most store credit (₹) a customer can use on one order
 *   MIN_SUBTOTAL      999        minimum order value for the code
 *   CODE_HOURS        24         hours before a game code expires
 *   MAX_PER_IP        2          codes per visitor per day
 *   DAILY_LIMIT       200        codes per day for the whole store
 *   API_VERSION       2026-07
 *   CREDIT_SECRET     optional: turns on store credit codes (same text as the theme's Store credit secret)
 *   ADMIN_KEY         private text that unlocks the /health setup page (open /health?key=...)
 *   CREDIT_DAYS       60         days new/returned store credit stays valid
 * KV namespace binding: REWARDS
 */

// Game physics (must match assets/dojo-run.js) used to cap believable scores.
const START_SPEED = 400;
const ACCEL = 8.5;
const MAX_SPEED = 1180;
const SLASH_POINTS = 15;
const TICKET_MAX_AGE = 30 * 60; // seconds

export default {
  // optional Cron Trigger (e.g. every 6 hours): give back credit from unused, expired credit codes
  async scheduled(event, env, ctx) {
    ctx.waitUntil(sweepCredit(env));
  },

  async fetch(request, env) {
    const origin = request.headers.get('Origin') || '';
    const allowed = (env.ALLOWED_ORIGINS || '').split(',').map((s) => s.trim()).filter(Boolean);
    const cors = {
      'Access-Control-Allow-Origin': allowed.includes(origin) ? origin : allowed[0] || '*',
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Access-Control-Max-Age': '86400',
      Vary: 'Origin'
    };
    const reply = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { ...cors, 'Content-Type': 'application/json' } });

    if (request.method === 'OPTIONS') return new Response(null, { status: 204, headers: cors });
    if (request.method === 'GET' && new URL(request.url).pathname.replace(/\/+$/, '').endsWith('/health')) {
      const q = new URL(request.url).searchParams;
      const json = (o, st = 200) => new Response(JSON.stringify(o, null, 2), { status: st, headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Robots-Tag': 'noindex' } });
      // the setup page shows your settings and recent activity, so it needs your private ADMIN_KEY
      if (!env.ADMIN_KEY) return json({ ok: true, running: true, next: 'Add a Secret named ADMIN_KEY in Cloudflare, then open /health?key=YOUR_ADMIN_KEY' });
      if (!(await sameText(q.get('key') || '', env.ADMIN_KEY))) return json({ ok: true, running: true, next: 'Add ?key=YOUR_ADMIN_KEY to see the details' }, 401);
      return json(await health(env, q.has('test')));
    }
    if (request.method !== 'POST') return reply({ error: 'not_found', tip: 'Open /health to check the setup' }, 404);
    // also accept a URL with extra text pasted after the address (e.g. …/health?test/start)
    const url = new URL(request.url);
    const path = (url.pathname + url.search).replace(/\/+$/, '');
    const action = path.endsWith('/dojo/redeem') ? 'dojo-redeem' : path.endsWith('/track') ? 'track' : path.endsWith('/credit/redeem') ? 'credit-redeem' : path.endsWith('/credit/cancel') ? 'credit-cancel'
      : path.endsWith('/start') ? 'start' : path.endsWith('/claim') ? 'claim' : path;
    let status = 200;
    let result;
    let body = {};
    try { body = await request.json(); } catch (e) { body = {}; }

    if (allowed.length && !allowed.includes(origin)) {
      status = 403;
      result = { error: 'origin', message: `"${origin || 'no origin'}" is not in ALLOWED_ORIGINS` };
    } else {
      try {
        if (action === 'track') result = await trackOrder(env, body, request.headers.get('CF-Connecting-IP') || 'unknown');
        else if (action === 'credit-redeem') result = await redeemCredit(env, body);
        else if (action === 'credit-cancel') result = await cancelCredit(env, body);
        else if (action === 'start') result = await startRun(env);
        else if (action === 'dojo-redeem') result = await redeemPending(env, body);
        else if (action === 'claim') {
          result = await claim(env, body, request.headers.get('CF-Connecting-IP') || 'unknown');
        } else { status = 404; result = { error: 'not_found' }; }
        if (result.error && status === 200) status = 400;
      } catch (e) {
        status = 500;
        result = { error: 'server', message: String(e && e.message || e) };
      }
    }
    // keep the last few requests so /health can show what happened (not the frequent start/track successes)
    if (!(action === 'start' && status === 200) && !(action === 'track' && status === 200)) await logEvent(env, { at: new Date().toISOString(), action, origin: origin || 'none', status, result: result.error ? `${result.error}${result.message ? `: ${result.message}` : ''}` : result.code ? `code ${maskCode(result.code)}` : result.pending ? 'won, waiting for sign-in' : result.nextAt ? 'used this month' : result.refunded ? `refunded ${result.refunded}` : result.order ? `tracked ${result.order}` : 'ticket issued', score: body && body.score });
    return reply(result, status);
  }
};

/* ---------------- setup check: open https://<worker>/health ---------------- */
async function health(env, tryCreate) {
  const out = { ok: false, checks: {} };
  const c = out.checks;
  const shop = (env.SHOP || '').trim();
  c.SHOP = !shop ? '✗ missing' : /^[a-z0-9-]+\.myshopify\.com$/.test(shop) ? `✓ ${shop}` : `✗ "${shop}" must look like your-store.myshopify.com (no https://, not haikufit.in)`;
  c.SIGNING_SECRET = env.SIGNING_SECRET && env.SIGNING_SECRET.length >= 20 ? '✓ set' : '✗ missing or shorter than 20 characters';
  c.credentials = env.ADMIN_TOKEN ? '✓ ADMIN_TOKEN set' : env.CLIENT_ID && env.CLIENT_SECRET ? '✓ CLIENT_ID and CLIENT_SECRET set' : '✗ add CLIENT_ID and CLIENT_SECRET (as Secrets)';
  c.REWARDS_storage = env.REWARDS ? '✓ KV bound' : '✗ KV namespace not bound as REWARDS (limits will not work)';
  const origins = (env.ALLOWED_ORIGINS || '').split(',').map((x) => x.trim()).filter(Boolean);
  const badOrigins = origins.filter((o) => !/^https:\/\/[^/]+$/.test(o));
  c.ALLOWED_ORIGINS = !origins.length ? '✗ missing: add https://haikufit.in,https://www.haikufit.in' : badOrigins.length ? `✗ fix these (use https://domain with no / at the end): ${badOrigins.join(' ')}` : `✓ ${origins.join(' ')}`;
  let tiers = null;
  try { tiers = JSON.parse(env.TIERS || '[]'); } catch (e) { tiers = null; }
  c.TIERS = tiers === null ? '✗ not valid JSON, copy it again exactly' : `${percentTiersIgnored(env) ? '⚠ old % tiers ignored, using ₹ amounts: ' : '✓ '}${readTiers(env).map((t) => `${t.score} pts → ${t.label} off`).join(', ')}`;
  c.MAX_CREDIT_USE = `✓ store credit use capped at ₹${num(env.MAX_CREDIT_USE, 100)} per order`;
  if (c.SHOP.startsWith('✓') && c.credentials.startsWith('✓')) {
    try {
      const token = await accessToken(env);
      c.shopify_login = '✓ access token received';
      const res = await fetch(`https://${shop}/admin/api/${env.API_VERSION || '2026-07'}/graphql.json`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Shopify-Access-Token': token },
        body: JSON.stringify({ query: '{ shop { name } currentAppInstallation { accessScopes { handle } } }' })
      });
      const data = await res.json().catch(() => ({}));
      if (data.data && data.data.shop) {
        const scopes = data.data.currentAppInstallation.accessScopes.map((x) => x.handle);
        c.shopify_store = `✓ connected to "${data.data.shop.name}"`;
        c.discount_permission = scopes.includes('write_discounts') ? '✓ write_discounts granted' : `✗ app lacks write_discounts (has: ${scopes.join(', ') || 'none'}). Add it in Dev Dashboard → Versions, release, then update the app on the store`;
        c.order_tracking_permission = scopes.includes('read_orders') ? '✓ read_orders granted (order tracking works)' : '– order tracking off: add the read_orders scope (Dev Dashboard → Versions → release → update the app)';
        const creditScopes = ['read_customers', 'read_store_credit_accounts', 'write_store_credit_account_transactions'];
        const missing = creditScopes.filter((x) => !scopes.includes(x));
        c.store_credit_permission = !env.CREDIT_SECRET ? '– store credit codes off (add CREDIT_SECRET to turn on)' : missing.length ? `✗ for store credit add these scopes: ${missing.join(', ')} (Dev Dashboard → Versions → release → update the app)` : '✓ store credit scopes granted';
        c.monthly_rewards = !env.CREDIT_SECRET ? '– monthly rewards off: add CREDIT_SECRET (same as the theme) so players sign in and get one reward every 30 days; until then codes are limited per IP per day' : !scopes.includes('read_orders') || !scopes.includes('read_discounts') ? '✗ monthly rewards need read_orders and read_discounts (to see when a reward was used)' : '✓ unlimited play, one reward used per customer every 30 days';
        c.review_bonus = !env.CREDIT_SECRET ? '– review bonus off (needs monthly rewards)' : !env.JUDGEME_TOKEN ? '– review bonus off: add JUDGEME_TOKEN (Judge.me → Settings → Integrations → Private API token) as a Secret' : `✓ a new ${env.REVIEW_NEEDS_PHOTO === 'true' ? 'photo ' : ''}review unlocks one more reward in the same month`;
        if (env.CREDIT_SECRET) c.credit_with_dojo = scopes.includes('read_products') ? '✓ read_products granted (store credit stacks with a Dojo reward)' : '– add the read_products scope so store credit can be used together with a Dojo reward';
      } else {
        c.shopify_store = `✗ Shopify said: ${JSON.stringify(data.errors || data).slice(0, 300)} (HTTP ${res.status}). Check API_VERSION is 2026-07`;
      }
    } catch (e) {
      c.shopify_login = `✗ ${e.message}. Check CLIENT_ID/CLIENT_SECRET have no spaces and the app is installed on this store`;
    }
  }
  if (tryCreate && String(c.discount_permission || '').startsWith('✓')) {
    // real end-to-end test: create a code exactly like a winner gets, then delete it
    try {
      const made = await createDiscount(env, readTiers(env)[0]);
      c.create_test_code = `✓ created ${made.code}`;
      const del = await shopifyGraphql(env, 'mutation($id: ID!) { discountCodeDelete(id: $id) { deletedCodeDiscountId userErrors { message } } }', { id: made.id });
      const ok = del.data && del.data.discountCodeDelete && del.data.discountCodeDelete.deletedCodeDiscountId;
      c.delete_test_code = ok ? '✓ test code deleted again' : `✗ could not delete ${made.code}, delete it in Shopify → Discounts (${JSON.stringify(del).slice(0, 200)})`;
    } catch (e) {
      c.create_test_code = `✗ ${e.message.slice(0, 500)}`;
    }
  } else if (!tryCreate) {
    out.tip = 'Add ?test to the address (…/health?test) to also create and delete a real test code.';
  }
  out.ok = Object.values(c).every((v) => !String(v).startsWith('✗'));
  const log = env.REWARDS ? JSON.parse((await env.REWARDS.get('log')) || '[]') : [];
  out.recent_requests = log.length ? log : 'none yet: if you just played and this is empty, the game never reached this server (check the Rewards server URL in the theme, or the network)';
  out.next = out.ok ? 'All good. Play on your live store (not the theme editor preview) to test.' : 'Fix the ✗ items in Cloudflare → your worker → Settings → Variables and secrets, then Deploy and reload this page.';
  return out;
}

/* ---------------- tickets ---------------- */
async function startRun(env) {
  const payload = { id: randomId(16), t: Math.floor(Date.now() / 1000) };
  const run = b64url(JSON.stringify(payload));
  return { run, sig: await sign(env, run) };
}

async function claim(env, body, ip) {
  const { run, sig } = body || {};
  const score = Math.floor(Number(body && body.score));
  if (!run || !sig || !Number.isFinite(score) || score <= 0) return { error: 'bad_request' };
  if (!(await verify(env, run, sig))) return { error: 'bad_ticket' };

  let ticket;
  try { ticket = JSON.parse(atob(run.replace(/-/g, '+').replace(/_/g, '/'))); } catch (e) { return { error: 'bad_ticket' }; }
  const now = Math.floor(Date.now() / 1000);
  const elapsed = now - ticket.t;
  if (elapsed < 0 || elapsed > TICKET_MAX_AGE) return { error: 'expired' };
  if (score > maxScore(elapsed + 3)) return { error: 'implausible' };

  const tier = tierFor(env, score);
  if (!tier) return { error: 'no_reward' };

  // Monthly rewards (needs CREDIT_SECRET + storage): unlimited play, one reward used per customer
  // every 30 days, claimed by a signed-in customer. A guest's win is held while they sign in.
  if (env.CREDIT_SECRET && env.REWARDS) {
    // No storage writes per game: a customer can only ever hold one code, so replaying a run
    // gives nothing new, and a guest's win is a signed token (unlimited play stays free).
    const customerId = body.me ? await verifyCustomer(env, body.me) : null; // {customer, ts, sig} from the theme
    if (customerId) return issueMonthly(env, customerId, tier);
    const won = b64url(JSON.stringify({ s: tier.score, e: now + PENDING_HOURS * 3600 }));
    return { pending: `${won}.${await sign(env, won)}`, value: tier.value, label: tier.label, kind: tier.kind, needLogin: true };
  }

  const kv = env.REWARDS;
  const day = new Date().toISOString().slice(0, 10);
  const ipKey = `ip:${day}:${ip}`;
  const dayKey = `day:${day}`;
  let counts = null;
  if (kv) {
    if (await kv.get(`run:${ticket.id}`)) return { error: 'used' };
    const ipCount = parseInt(await kv.get(ipKey), 10) || 0;
    if (ipCount >= num(env.MAX_PER_IP, 2)) return { error: 'limit' };
    const dayCount = parseInt(await kv.get(dayKey), 10) || 0;
    if (dayCount >= num(env.DAILY_LIMIT, 200)) return { error: 'sold_out' };
    try {
      await kv.put(`run:${ticket.id}`, '1', { expirationTtl: 2 * 86400 });
      await kv.put(ipKey, String(ipCount + 1), { expirationTtl: 2 * 86400 });
      await kv.put(dayKey, String(dayCount + 1), { expirationTtl: 3 * 86400 });
    } catch (e) {
      return { error: 'busy' }; // storage limit reached: no code rather than an unchecked one
    }
    counts = { ipCount, dayCount };
  }

  let created;
  try {
    created = await createDiscount(env, tier);
  } catch (e) {
    // Shopify failed: give the run and the limits back so the player can retry
    if (kv && counts) {
      await kv.delete(`run:${ticket.id}`);
      await kv.put(ipKey, String(counts.ipCount), { expirationTtl: 2 * 86400 });
      await kv.put(dayKey, String(counts.dayCount), { expirationTtl: 3 * 86400 });
    }
    throw e;
  }
  return { code: created.code, value: tier.value, label: tier.label, kind: tier.kind, percent: tier.kind === 'percent' ? tier.value : undefined, expiresAt: created.endsAt };
}

/* ---------------- monthly Dojo rewards (one used per customer every 30 days) ---------------- */
const PENDING_HOURS = 2;          // a guest's win waits this long for them to sign in
const REWARD_COOLDOWN_DAYS = 30;  // after a reward is used

// A signed-in customer turns a held win into their code
async function redeemPending(env, body) {
  const customerId = await verifyCustomer(env, body);
  if (!customerId) return { error: 'bad_customer' };
  if (!env.REWARDS) return { error: 'busy' };
  const [won, sig] = String((body && body.pending) || '').split('.');
  if (!won || !sig || !(await verify(env, won, sig))) return { error: 'bad_ticket' };
  let held;
  try { held = JSON.parse(atob(won.replace(/-/g, '+').replace(/_/g, '/'))); } catch (e) { return { error: 'bad_ticket' }; }
  if (!(held.e > Math.floor(Date.now() / 1000))) return { error: 'expired' };
  const tier = readTiers(env).find((t) => t.score === held.s);
  if (!tier) return { error: 'expired' };
  return issueMonthly(env, customerId, tier);
}

// When was this reward used? (order date if Shopify has it)
async function usedAt(env, discountId, code) {
  const orders = await shopifyGraphql(env, 'query($q: String!) { orders(first: 1, query: $q, sortKey: CREATED_AT) { nodes { createdAt } } }', { q: `discount_code:${code}` });
  const node = orders.data && orders.data.orders && orders.data.orders.nodes[0];
  if (node) return node.createdAt;
  return (await codeUsed(env, discountId)) ? new Date().toISOString() : null;
}

/* Review bonus: a customer who used this month's reward and then leaves a review on Judge.me
 * (published, written after that order) gets one more reward in the same 30 days.
 * REVIEW_NEEDS_PHOTO=true counts photo reviews only. Needs JUDGEME_TOKEN + SHOP. */
function reviewsOn(env) {
  return !!(env.JUDGEME_TOKEN && env.SHOP);
}

async function customerEmail(env, customerId) {
  const data = await shopifyGraphql(env, 'query($id: ID!) { customer(id: $id) { email } }', { id: `gid://shopify/Customer/${customerId}` });
  return (data.data && data.data.customer && data.data.customer.email) || null;
}

async function bonusReview(env, customerId, since, usedIds) {
  if (!reviewsOn(env)) return null;
  const email = await customerEmail(env, customerId);
  if (!email) return null;
  const url = new URL('https://api.judge.me/api/v1/reviews');
  url.searchParams.set('shop_domain', env.SHOP);
  url.searchParams.set('api_token', env.JUDGEME_TOKEN);
  url.searchParams.set('reviewer_email', email);
  url.searchParams.set('per_page', '20');
  const res = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error(`Judge.me ${res.status}`);
  const data = await res.json();
  const photoOnly = env.REVIEW_NEEDS_PHOTO === 'true';
  const sinceMs = new Date(since).getTime();
  const found = ((data && data.reviews) || []).find((r) => r && r.reviewer
    && String(r.reviewer.email || '').toLowerCase() === email.toLowerCase() // never trust the filter alone
    && r.published !== false && !r.hidden
    && new Date(r.created_at).getTime() > sinceMs
    && !usedIds.includes(String(r.id))
    && (!photoOnly || (r.pictures || []).some((x) => x && !x.hidden)));
  return found ? String(found.id) : null;
}

const MONTH_TTL = (REWARD_COOLDOWN_DAYS + 5) * 86400;

async function deleteDiscount(env, id) {
  try { await shopifyGraphql(env, 'mutation($id: ID!) { discountCodeDelete(id: $id) { deletedCodeDiscountId } }', { id }); } catch (e) { /* expires on its own */ }
}

/* One record per customer, `dojo:cust:<id>`:
 *   the current code {code, discountId, value, label, kind, expiresAt, usedAt}
 *   the 30-day window {windowStart, uses, reviews: [review ids that gave a bonus]}
 * The window starts when a reward is first used. Inside it, one reward may be used,
 * plus one more after a new review. An unused code is returned again (or upgraded). */
async function issueMonthly(env, customerId, tier) {
  const kv = env.REWARDS;
  const key = `dojo:cust:${customerId}`;
  const rec = JSON.parse((await kv.get(key)) || 'null') || { uses: 0, reviews: [] };
  rec.reviews = rec.reviews || [];
  const now = Date.now();
  const cooldown = REWARD_COOLDOWN_DAYS * 86400000;

  // has the current code been used since we last looked?
  if (rec.code && !rec.usedAt && rec.discountId) {
    const when = await usedAt(env, rec.discountId, rec.code);
    if (when) {
      rec.usedAt = when;
      if (!rec.windowStart || new Date(rec.windowStart).getTime() + cooldown <= new Date(when).getTime()) {
        rec.windowStart = when; rec.uses = 0; rec.reviews = [];
      }
      rec.uses = (rec.uses || 0) + 1;
      await safePut(kv, key, JSON.stringify(rec), { expirationTtl: MONTH_TTL });
    }
  }

  const windowEnd = rec.windowStart ? new Date(rec.windowStart).getTime() + cooldown : 0;
  if (windowEnd > now) {
    if (rec.code && !rec.usedAt && new Date(rec.expiresAt).getTime() > now) {
      // a bonus code is waiting to be used: same or upgraded below
    } else {
      const allowed = 1 + rec.reviews.length;
      let review = null;
      if (rec.uses >= allowed && rec.reviews.length === 0) {
        try { review = await bonusReview(env, customerId, rec.usedAt || rec.windowStart, rec.reviews); } catch (e) { review = null; } // Judge.me down: no bonus right now
      }
      if (review) {
        rec.reviews.push(review);
      } else if (rec.uses >= allowed) {
        return { error: 'used_this_month', nextAt: new Date(windowEnd).toISOString(), canReview: reviewsOn(env) && rec.reviews.length === 0 };
      }
    }
  }

  // an unused, unexpired code: give it back, or upgrade it to a better tier
  if (rec.code && !rec.usedAt && new Date(rec.expiresAt).getTime() > now) {
    if (tier.value <= rec.value) return { code: rec.code, value: rec.value, label: rec.label, kind: rec.kind, expiresAt: rec.expiresAt, reused: true };
    await deleteDiscount(env, rec.discountId);
  }

  const day = new Date().toISOString().slice(0, 10);
  const dayKey = `day:${day}`;
  const dayCount = parseInt(await kv.get(dayKey), 10) || 0;
  if (dayCount >= num(env.DAILY_LIMIT, 200)) return { error: 'sold_out' };
  // LOCK_TO_CUSTOMER=false: code works for any buyer (if a checkout can't match the customer); the 30-day rule still holds here
  const created = await createDiscount(env, tier, env.LOCK_TO_CUSTOMER === 'false' ? null : customerId);
  Object.assign(rec, { code: created.code, discountId: created.id, value: tier.value, label: tier.label, kind: tier.kind, expiresAt: created.endsAt, issuedAt: new Date().toISOString(), usedAt: null });
  if (!(await safePut(kv, key, JSON.stringify(rec), { expirationTtl: MONTH_TTL }))) {
    await deleteDiscount(env, created.id);
    return { error: 'busy' };
  }
  await safePut(kv, dayKey, String(dayCount + 1), { expirationTtl: 3 * 86400 });
  return { code: rec.code, value: rec.value, label: rec.label, kind: rec.kind, expiresAt: rec.expiresAt, bonus: rec.reviews.length > 0 && rec.uses > 0 };
}

// Highest score possible in `t` seconds: run points (speed/40 per second,
// speed ramps up to its cap) plus generous room for sword-slash bonuses.
function maxScore(t) {
  const tCap = (MAX_SPEED - START_SPEED) / ACCEL;
  const run = t <= tCap
    ? (START_SPEED * t + (ACCEL * t * t) / 2) / 40
    : (START_SPEED * tCap + (ACCEL * tCap * tCap) / 2) / 40 + (MAX_SPEED / 40) * (t - tCap);
  return run + SLASH_POINTS * (t / 3) + 20;
}

// TIERS entries: {"score":300,"amount":50} (₹ off, recommended) or {"score":300,"percent":5}
const DEFAULT_TIERS = [{ score: 300, amount: 50 }, { score: 600, amount: 100 }, { score: 1000, amount: 150 }];
// Percentage tiers are the old format: a % stacks badly with the bundle offer, so they are ignored
// (fixed ₹ rewards are used) unless ALLOW_PERCENT is "true".
function percentTiersIgnored(env) {
  let tiers = [];
  try { tiers = JSON.parse(env.TIERS || '[]'); } catch (e) { return false; }
  return env.ALLOW_PERCENT !== 'true' && Array.isArray(tiers) && tiers.some((t) => !t.amount && t.percent);
}

function readTiers(env) {
  let tiers = [];
  try { tiers = JSON.parse(env.TIERS || '[]'); } catch (e) { tiers = []; }
  if (!Array.isArray(tiers) || !tiers.length || percentTiersIgnored(env)) tiers = DEFAULT_TIERS;
  return tiers.map((t) => (t.amount
    ? { score: t.score, kind: 'amount', value: t.amount, label: `₹${t.amount}` }
    : { score: t.score, kind: 'percent', value: t.percent, label: `${t.percent}%` }));
}

function tierFor(env, score) {
  return readTiers(env).filter((t) => score >= t.score).sort((a, b) => b.value - a.value)[0] || null;
}

/* ---------------- store credit → one-time code ----------------
 * Shopify's own store credit can only be spent in Shopify checkout. To make it
 * work with any checkout (e.g. Cashfree one-click), the cart asks for a one-time
 * code worth the credit: the credit is taken off the customer's balance and a
 * single-use code, locked to that customer, is created. If the code is removed
 * from the cart or expires unused, the credit is given back.
 * The theme signs "customerId:timestamp" with CREDIT_SECRET (Liquid hmac_sha256),
 * so a shopper can only ever use their own credit. */
const CREDIT_CODE_HOURS = 48;

async function hmacHex(secret, data) {
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data));
  return [...new Uint8Array(mac)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function verifyCustomer(env, body) {
  if (!env.CREDIT_SECRET) throw new Error('CREDIT_SECRET is not set');
  const id = String((body && body.customer) || '');
  const ts = parseInt(body && body.ts, 10);
  if (!/^\d+$/.test(id) || !ts || !body.sig) return null;
  const age = Math.floor(Date.now() / 1000) - ts;
  if (age < -300 || age > 2 * 86400) return null;
  const expected = await hmacHex(env.CREDIT_SECRET, `${id}:${ts}`);
  const sig = String(body.sig).toLowerCase();
  if (expected.length !== sig.length) return null;
  let diff = 0;
  for (let i = 0; i < expected.length; i++) diff |= expected.charCodeAt(i) ^ sig.charCodeAt(i);
  return diff === 0 ? id : null;
}

async function creditAccount(env, customerId) {
  const data = await shopifyGraphql(env, 'query($id: ID!) { customer(id: $id) { storeCreditAccounts(first: 10) { nodes { id balance { amount currencyCode } } } } }', { id: `gid://shopify/Customer/${customerId}` });
  if (data.errors) throw new Error(`Shopify: ${JSON.stringify(data.errors).slice(0, 300)}`);
  const nodes = (data.data && data.data.customer && data.data.customer.storeCreditAccounts.nodes) || [];
  return nodes.filter((n) => parseFloat(n.balance.amount) > 0).sort((a, b) => parseFloat(b.balance.amount) - parseFloat(a.balance.amount))[0] || null;
}

// used = Shopify counted a use, or any order carries the code. The order check also covers
// orders created by one-click checkouts (Cashfree) and the delay before the count updates.
async function codeUsed(env, discountId, code) {
  const data = await shopifyGraphql(env, 'query($id: ID!) { codeDiscountNode(id: $id) { codeDiscount { ... on DiscountCodeBasic { asyncUsageCount } } } }', { id: discountId });
  const node = data.data && data.data.codeDiscountNode;
  if (node && (node.codeDiscount.asyncUsageCount || 0) > 0) return true;
  if (!code) return false;
  const orders = await shopifyGraphql(env, 'query($q: String!) { orders(first: 1, query: $q) { nodes { id } } }', { q: `discount_code:${code}` });
  if (orders.errors) throw new Error(`Shopify: ${JSON.stringify(orders.errors).slice(0, 300)}`); // unsure: keep the code, retry next run
  return ((orders.data && orders.data.orders.nodes) || []).length > 0;
}

// Every active product, cached for 6 hours (one storage write). null -> order discount fallback.
async function creditItems(env) {
  const kv = env.REWARDS;
  try {
    let ids = kv && JSON.parse((await kv.get('product_ids')) || 'null');
    if (!ids) {
      const data = await shopifyGraphql(env, '{ products(first: 250, query: "status:active") { nodes { id } } }', {});
      ids = data.errors || !data.data ? null : data.data.products.nodes.map((n) => n.id);
      if (kv && ids && ids.length) await safePut(kv, 'product_ids', JSON.stringify(ids), { expirationTtl: 6 * 3600 });
    }
    if (ids && ids.length) return { products: { productsToAdd: ids } };
  } catch (e) { /* fall back below */ }
  return { all: true };
}

async function addCredit(env, accountId, amount, currency) {
  const expiresAt = new Date(Date.now() + num(env.CREDIT_DAYS, 60) * 86400000).toISOString();
  const res = await shopifyGraphql(env, `mutation($id: ID!, $input: StoreCreditAccountCreditInput!) {
    storeCreditAccountCredit(id: $id, creditInput: $input) { storeCreditAccountTransaction { amount { amount } } userErrors { message } } }`,
  { id: accountId, input: { creditAmount: { amount, currencyCode: currency || 'INR' }, expiresAt } });
  const r = res.data && res.data.storeCreditAccountCredit;
  if (!r || (r.userErrors && r.userErrors.length)) throw new Error(`Could not give credit back: ${JSON.stringify(res.errors || (r && r.userErrors)).slice(0, 300)}`);
}

// settle an earlier code: used -> forget it; unused and (expired or cancelling) -> give the credit back
async function settle(env, customerId, cancelling) {
  const kv = env.REWARDS;
  const raw = kv && (await kv.get(`credit:${customerId}`));
  if (!raw) return { pending: null };
  const pending = JSON.parse(raw);
  if (await codeUsed(env, pending.discountId, pending.code)) { await kv.delete(`credit:${customerId}`); return { pending: null }; }
  if (cancelling || Date.now() > pending.expires) {
    await shopifyGraphql(env, 'mutation($id: ID!) { discountCodeDelete(id: $id) { deletedCodeDiscountId } }', { id: pending.discountId });
    await addCredit(env, pending.accountId, pending.amount, pending.currency);
    await kv.delete(`credit:${customerId}`);
    return { pending: null, refunded: pending.amount };
  }
  return { pending };
}

async function redeemCredit(env, body) {
  const customerId = await verifyCustomer(env, body);
  if (!customerId) return { error: 'bad_customer' };
  if (!env.REWARDS) throw new Error('KV namespace REWARDS is not bound');
  const { pending } = await settle(env, customerId, false);
  if (pending) return { code: pending.code, amount: pending.amount, reused: true };

  const account = await creditAccount(env, customerId);
  if (!account) return { error: 'no_credit' };
  const balance = parseFloat(account.balance.amount);
  const wanted = parseFloat(body.amount) > 0 ? parseFloat(body.amount) : balance;
  const cap = num(env.MAX_CREDIT_USE, 100); // ₹ per order, enforced here even if the page is tampered with
  const amount = Math.floor(Math.min(balance, wanted, cap > 0 ? cap : Infinity) * 100) / 100;
  if (amount < 1) return { error: 'no_credit' };
  const money = amount.toFixed(2);
  const currency = account.balance.currencyCode;

  const debit = await shopifyGraphql(env, `mutation($id: ID!, $input: StoreCreditAccountDebitInput!) {
    storeCreditAccountDebit(id: $id, debitInput: $input) { storeCreditAccountTransaction { amount { amount } } userErrors { message } } }`,
  { id: account.id, input: { debitAmount: { amount: money, currencyCode: currency } } });
  const d = debit.data && debit.data.storeCreditAccountDebit;
  if (!d || (d.userErrors && d.userErrors.length)) throw new Error(`Could not use credit: ${JSON.stringify(debit.errors || (d && d.userErrors)).slice(0, 300)}`);

  const code = `CR-${randomCode(8)}`;
  const now = new Date();
  const input = {
    title: `Store credit ₹${money} · ${code}`,
    code,
    startsAt: now.toISOString(),
    endsAt: new Date(now.getTime() + CREDIT_CODE_HOURS * 3600000).toISOString(),
    context: { customers: { add: [`gid://shopify/Customer/${customerId}`] } },
    // a product discount on every active product (needs read_products), so it can stack with a
    // Dojo reward, which refuses other order discounts. Without the scope it falls back to an order discount.
    customerGets: { value: { discountAmount: { amount: money, appliesOnEachItem: false } }, items: (await creditItems(env)) },
    usageLimit: 1,
    appliesOncePerCustomer: true,
    combinesWith: { orderDiscounts: true, productDiscounts: true, shippingDiscounts: true }
  };
  const made = await shopifyGraphql(env, `mutation($input: DiscountCodeBasicInput!) {
    discountCodeBasicCreate(basicCodeDiscount: $input) { codeDiscountNode { id } userErrors { field message } } }`, { input });
  const m = made.data && made.data.discountCodeBasicCreate;
  if (!m || !m.codeDiscountNode) {
    await addCredit(env, account.id, money, currency); // undo, so the customer never loses credit
    throw new Error(`Could not create credit code: ${JSON.stringify(made.errors || (m && m.userErrors)).slice(0, 300)}`);
  }
  const record = { code, discountId: m.codeDiscountNode.id, amount: money, currency, accountId: account.id, expires: now.getTime() + CREDIT_CODE_HOURS * 3600000 };
  await env.REWARDS.put(`credit:${customerId}`, JSON.stringify(record), { expirationTtl: 30 * 86400 });
  return { code, amount: money };
}

async function cancelCredit(env, body) {
  const customerId = await verifyCustomer(env, body);
  if (!customerId) return { error: 'bad_customer' };
  const raw = env.REWARDS && (await env.REWARDS.get(`credit:${customerId}`));
  if (!raw || JSON.parse(raw).code !== String(body.code || '').toUpperCase()) return { error: 'no_pending' };
  const { refunded } = await settle(env, customerId, true);
  return refunded ? { refunded } : { error: 'already_used' };
}

async function sweepCredit(env) {
  if (!env.REWARDS) return;
  const list = await env.REWARDS.list({ prefix: 'credit:' });
  for (const k of list.keys) {
    try { await settle(env, k.name.slice(7), false); } catch (e) { /* try again next run */ }
  }
}

/* ---------------- order tracking ----------------
 * The customer gives their order number + the email or phone on the order.
 * We look the order up in Shopify and return each shipment's courier, AWB,
 * status and tracking link (Shopify builds the link when you fulfil with a
 * known courier such as Delhivery or DTDC). Nothing else about the order is sent. */
const TRACK_FAILS_PER_HOUR = 10; // wrong order number / contact attempts per visitor per hour

async function trackOrder(env, body, ip) {
  const number = String((body && body.order) || '').replace(/[^0-9a-z-]/gi, '');
  const contact = String((body && body.contact) || '').trim().toLowerCase();
  if (!number || !contact) return { error: 'bad_request' };

  const kv = env.REWARDS;
  let failKey = null;
  let fails = 0;
  if (kv) {
    const key = `track:${new Date().toISOString().slice(0, 13)}:${ip}`;
    const n = parseInt(await kv.get(key), 10) || 0;
    if (n >= TRACK_FAILS_PER_HOUR) return { error: 'too_many' };
    failKey = key;
    fails = n;
  }

  const data = await shopifyGraphql(env, `query($q: String!) {
    orders(first: 3, query: $q, sortKey: CREATED_AT, reverse: true) { nodes {
      name createdAt cancelledAt displayFulfillmentStatus email phone
      customer { email phone } shippingAddress { phone }
      fulfillments(first: 10) { status displayStatus createdAt deliveredAt estimatedDeliveryAt inTransitAt
        trackingInfo(first: 5) { company number url } }
    } } }`, { q: `name:#${number} OR name:${number}` });
  if (data.errors) throw new Error(`Shopify: ${JSON.stringify(data.errors).slice(0, 300)}`);
  const orders = (data.data && data.data.orders.nodes) || [];
  const digits = (v) => String(v || '').replace(/\D/g, '').slice(-10);
  const wantDigits = digits(contact);
  const match = orders.find((o) => {
    const emails = [o.email, o.customer && o.customer.email].filter(Boolean).map((e) => e.toLowerCase());
    const phones = [o.phone, o.customer && o.customer.phone, o.shippingAddress && o.shippingAddress.phone].map(digits).filter((d) => d.length === 10);
    return (contact.includes('@') && emails.includes(contact)) || (wantDigits.length === 10 && phones.includes(wantDigits));
  });
  // same answer for "no such order" and "wrong email/phone", so order numbers can't be probed;
  // only failed lookups count toward the hourly limit
  if (!match) {
    if (failKey) await safePut(kv, failKey, String(fails + 1), { expirationTtl: 2 * 3600 });
    return { error: 'not_found' };
  }

  const shipments = [];
  for (const f of match.fulfillments || []) {
    const base = { status: f.displayStatus || f.status || '', shippedAt: f.createdAt, deliveredAt: f.deliveredAt || null, eta: f.estimatedDeliveryAt || null };
    const infos = f.trackingInfo || [];
    if (!infos.length) shipments.push(Object.assign({ courier: '', awb: '', url: '' }, base));
    for (const t of infos) shipments.push(Object.assign({ courier: t.company || '', awb: t.number || '', url: t.url || '' }, base));
  }
  return { order: match.name, placedAt: match.createdAt, cancelled: !!match.cancelledAt, fulfillment: match.displayFulfillmentStatus, shipments };
}

/* ---------------- Shopify ---------------- */
async function accessToken(env) {
  if (env.ADMIN_TOKEN) return env.ADMIN_TOKEN;
  const kv = env.REWARDS;
  if (kv) {
    const cached = await kv.get('shopify_token');
    if (cached) return cached;
  }
  const res = await fetch(`https://${env.SHOP}/admin/oauth/access_token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'client_credentials', client_id: env.CLIENT_ID, client_secret: env.CLIENT_SECRET })
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.access_token) throw new Error(`Shopify token request failed (${res.status}${data.error ? `: ${data.error_description || data.error}` : ''})`);
  if (kv) await safePut(kv, 'shopify_token', data.access_token, { expirationTtl: Math.max(60, (data.expires_in || 86400) - 600) });
  return data.access_token;
}

async function createDiscount(env, tier, customerId) {
  const token = await accessToken(env);
  const hours = num(env.CODE_HOURS, 24);
  const startsAt = new Date();
  const endsAt = new Date(startsAt.getTime() + hours * 3600000);
  const query = `mutation dojoReward($input: DiscountCodeBasicInput!) {
    discountCodeBasicCreate(basicCodeDiscount: $input) {
      codeDiscountNode { id }
      userErrors { field message }
    }
  }`;
  for (let attempt = 0; attempt < 3; attempt++) {
    const code = `HF${tier.value}-${randomCode(6)}`;
    const input = {
      title: `Dojo Run ${tier.label} off · ${code}`,
      code,
      startsAt: startsAt.toISOString(),
      endsAt: endsAt.toISOString(),
      context: customerId ? { customers: { add: [`gid://shopify/Customer/${customerId}`] } } : { all: 'ALL' },
      customerGets: {
        value: tier.kind === 'amount' ? { discountAmount: { amount: Number(tier.value).toFixed(2), appliesOnEachItem: false } } : { percentage: tier.value / 100 },
        items: { all: true }
      },
      usageLimit: 1,
      appliesOncePerCustomer: true,
      // one Dojo reward per order: it is an order discount that refuses other order discounts
      // (so two HF- codes or HF + WELCOME10 never stack), but it does stack with the bundle
      // offer and with store credit codes, which are product discounts.
      combinesWith: { orderDiscounts: false, productDiscounts: true, shippingDiscounts: true }
    };
    const min = parseFloat(env.MIN_SUBTOTAL);
    if (min > 0) input.minimumRequirement = { subtotal: { greaterThanOrEqualToSubtotal: min.toFixed(2) } };

    const res = await fetch(`https://${env.SHOP}/admin/api/${env.API_VERSION || '2026-07'}/graphql.json`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Shopify-Access-Token': token },
      body: JSON.stringify({ query, variables: { input } })
    });
    const data = await res.json().catch(() => ({}));
    if (res.status === 401 && env.REWARDS) await env.REWARDS.delete('shopify_token');
    const result = data && data.data && data.data.discountCodeBasicCreate;
    if (result && result.codeDiscountNode) return { code, endsAt: input.endsAt, id: result.codeDiscountNode.id };
    const errors = (result && result.userErrors) || data.errors || [{ message: `HTTP ${res.status}` }];
    // retry only if the random code happened to clash with an existing one
    if (!JSON.stringify(errors).toLowerCase().includes('unique')) throw new Error(`Shopify: ${JSON.stringify(errors)}`);
  }
  throw new Error('Could not create a unique code');
}

async function shopifyGraphql(env, query, variables) {
  const token = await accessToken(env);
  const res = await fetch(`https://${env.SHOP}/admin/api/${env.API_VERSION || '2026-07'}/graphql.json`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Shopify-Access-Token': token },
    body: JSON.stringify({ query, variables })
  });
  return res.json().catch(() => ({ errors: [{ message: `HTTP ${res.status}` }] }));
}

async function safePut(kv, key, value, opts) {
  try { await kv.put(key, value, opts); return true; } catch (e) { return false; }
}

function maskCode(code) {
  const c = String(code || '');
  return c.length > 6 ? `${c.slice(0, c.indexOf('-') + 3)}${'•'.repeat(Math.max(0, c.length - c.indexOf('-') - 3))}` : c;
}

async function sameText(a, b) {
  const enc = new TextEncoder();
  const [x, y] = await Promise.all([crypto.subtle.digest('SHA-256', enc.encode(a)), crypto.subtle.digest('SHA-256', enc.encode(b))]);
  const u = new Uint8Array(x); const v = new Uint8Array(y);
  let diff = 0;
  for (let i = 0; i < u.length; i++) diff |= u[i] ^ v[i];
  return diff === 0;
}

async function logEvent(env, entry) {
  if (!env.REWARDS) return;
  try {
    const log = JSON.parse((await env.REWARDS.get('log')) || '[]');
    log.unshift(entry);
    await env.REWARDS.put('log', JSON.stringify(log.slice(0, 12)), { expirationTtl: 7 * 86400 });
  } catch (e) { /* logging must never break a request */ }
}

/* ---------------- helpers ---------------- */
const num = (v, d) => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : d);

function randomCode(n) {
  const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
  const bytes = crypto.getRandomValues(new Uint8Array(n));
  return [...bytes].map((b) => abc[b % abc.length]).join('');
}

function randomId(n) {
  return [...crypto.getRandomValues(new Uint8Array(n))].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function b64url(str) {
  return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function hmacKey(env) {
  if (!env.SIGNING_SECRET) throw new Error('SIGNING_SECRET is not set');
  return crypto.subtle.importKey('raw', new TextEncoder().encode(env.SIGNING_SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign', 'verify']);
}

async function sign(env, data) {
  const mac = await crypto.subtle.sign('HMAC', await hmacKey(env), new TextEncoder().encode(data));
  return b64url(String.fromCharCode(...new Uint8Array(mac)));
}

async function verify(env, data, sig) {
  const expected = await sign(env, data);
  if (expected.length !== sig.length) return false;
  let diff = 0;
  for (let i = 0; i < expected.length; i++) diff |= expected.charCodeAt(i) ^ sig.charCodeAt(i);
  return diff === 0;
}

