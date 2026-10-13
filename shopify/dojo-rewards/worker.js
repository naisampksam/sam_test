/**
 * HAIKUFIT · Dojo Run rewards (Cloudflare Worker)
 *
 * Creates a unique, single-use, expiring Shopify discount code for each player
 * who really reaches a reward score in the Dojo Run game. The Shopify key lives
 * only here (never in the theme), so codes cannot be copied from the website.
 *
 *   POST /start  -> signed run ticket (when a battle starts)
 *   POST /claim  -> { code, percent, expiresAt } (when a battle ends)
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
 *   TIERS             [{"score":300,"percent":5},{"score":600,"percent":10},{"score":1000,"percent":15}]
 *   MIN_SUBTOTAL      799        minimum order value for the code
 *   CODE_DAYS         7          days before the code expires
 *   MAX_PER_IP        2          codes per visitor per day
 *   DAILY_LIMIT       200        codes per day for the whole store
 *   API_VERSION       2026-07
 * KV namespace binding: REWARDS
 */

// Game physics (must match assets/dojo-run.js) used to cap believable scores.
const START_SPEED = 400;
const ACCEL = 8.5;
const MAX_SPEED = 1180;
const SLASH_POINTS = 15;
const TICKET_MAX_AGE = 30 * 60; // seconds

export default {
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
      const tryCreate = new URL(request.url).searchParams.has('test');
      return new Response(JSON.stringify(await health(env, tryCreate), null, 2), { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
    }
    if (request.method !== 'POST') return reply({ error: 'not_found', tip: 'Open /health to check the setup' }, 404);
    if (allowed.length && !allowed.includes(origin)) return reply({ error: 'origin' }, 403);

    const path = new URL(request.url).pathname.replace(/\/+$/, '');
    let body = {};
    try { body = await request.json(); } catch (e) { body = {}; }

    try {
      if (path.endsWith('/start')) return reply(await startRun(env));
      if (path.endsWith('/claim')) {
        const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
        const result = await claim(env, body, ip);
        return reply(result, result.error ? 400 : 200);
      }
      return reply({ error: 'not_found' }, 404);
    } catch (e) {
      return reply({ error: 'server', message: String(e && e.message || e) }, 500);
    }
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
  c.TIERS = tiers === null ? '✗ not valid JSON, copy it again exactly' : tiers.length ? `✓ ${tiers.map((t) => `${t.score}→${t.percent}%`).join(', ')}` : '✓ default 300→5%, 600→10%, 1000→15%';
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
      const made = await createDiscount(env, 5);
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
  out.ok = Object.values(c).every((v) => String(v).startsWith('✓'));
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
    await kv.put(`run:${ticket.id}`, '1', { expirationTtl: 2 * 86400 });
    await kv.put(ipKey, String(ipCount + 1), { expirationTtl: 2 * 86400 });
    await kv.put(dayKey, String(dayCount + 1), { expirationTtl: 3 * 86400 });
    counts = { ipCount, dayCount };
  }

  let created;
  try {
    created = await createDiscount(env, tier.percent);
  } catch (e) {
    // Shopify failed: give the run and the limits back so the player can retry
    if (kv && counts) {
      await kv.delete(`run:${ticket.id}`);
      await kv.put(ipKey, String(counts.ipCount), { expirationTtl: 2 * 86400 });
      await kv.put(dayKey, String(counts.dayCount), { expirationTtl: 3 * 86400 });
    }
    throw e;
  }
  return { code: created.code, percent: tier.percent, expiresAt: created.endsAt };
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

function tierFor(env, score) {
  let tiers = [];
  try { tiers = JSON.parse(env.TIERS || '[]'); } catch (e) { tiers = []; }
  if (!tiers.length) tiers = [{ score: 300, percent: 5 }, { score: 600, percent: 10 }, { score: 1000, percent: 15 }];
  return tiers.filter((t) => score >= t.score).sort((a, b) => b.percent - a.percent)[0] || null;
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
  if (kv) await kv.put('shopify_token', data.access_token, { expirationTtl: Math.max(60, (data.expires_in || 86400) - 600) });
  return data.access_token;
}

async function createDiscount(env, percent) {
  const token = await accessToken(env);
  const days = num(env.CODE_DAYS, 7);
  const startsAt = new Date();
  const endsAt = new Date(startsAt.getTime() + days * 86400000);
  const query = `mutation dojoReward($input: DiscountCodeBasicInput!) {
    discountCodeBasicCreate(basicCodeDiscount: $input) {
      codeDiscountNode { id }
      userErrors { field message }
    }
  }`;
  for (let attempt = 0; attempt < 3; attempt++) {
    const code = `HF${percent}-${randomCode(6)}`;
    const input = {
      title: `Dojo Run ${percent}% · ${code}`,
      code,
      startsAt: startsAt.toISOString(),
      endsAt: endsAt.toISOString(),
      context: { all: 'ALL' },
      customerGets: { value: { percentage: percent / 100 }, items: { all: true } },
      usageLimit: 1,
      appliesOncePerCustomer: true,
      combinesWith: { orderDiscounts: false, productDiscounts: false, shippingDiscounts: true }
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

