# Dojo Run rewards server (unique discount codes)

Without this, the game gives everyone the same codes (DOJO5 / DOJO10 / DOJO15),
which anyone can share or find in the page source.

With this small free server, every winner gets **their own Shopify discount code**:

- unique, e.g. `HF50-K7Q2MX`
- works **once** (usage limit 1, once per customer)
- **expires** after 24 hours
- fixed ₹50 / ₹100 / ₹150 off, minimum order ₹999; stacks with bundle offers and store credit
- created live in Shopify, so you see each one in **Shopify admin → Discounts**

Before creating a code the server checks:

| Check | Stops |
| --- | --- |
| Signed run ticket from the start of the game, used once | copying a request or reusing a run |
| Score must be possible for the real time the run lasted (game physics) | typing a fake score |
| Server decides the reward from the score | asking for ₹150 with a 300 score |
| Max 2 codes per visitor (IP) per day | farming codes |
| Max 200 codes per day for the store | runaway cost if something goes wrong |
| Only your store domains may call it | other websites using it |

> Honest limit: a browser game can never be 100% cheat-proof. Someone technical could
> script a fake run that waits the real time. These checks make that slow and limited
> (2 codes a day per connection, each single-use and expiring), so the cost stays tiny.

It runs on **Cloudflare Workers (free plan: 100,000 requests a day)**. Setup takes about 20 minutes.
No coding needed, only copy and paste.

---

## Step 1: Create a Shopify app (gives the server permission to make discounts)

Since January 2026 store apps are made in Shopify's **Dev Dashboard**.

1. Open **https://dev.shopify.com/dashboard** and log in with your Shopify store owner account.
   (Or: Shopify admin → Settings → Apps → **Develop apps** → *Build apps in Dev Dashboard*.)
2. **Create app** → *Start from Dev Dashboard* → name it `Dojo Run Rewards` → Create.
3. Open **Versions** → **Create version**:
   - **Access scopes**: add `write_discounts` and `read_discounts`
   - Leave the app URL as it is. Click **Release**.
4. Go to the app's **Home** (Overview) → **Install app** → choose your HAIKUFIT store → **Install**.
5. Open **Settings** and copy the **Client ID** and **Client secret**. Keep them private.
6. Find your store's `.myshopify.com` address: Shopify admin → **Settings → Domains**
   (looks like `haikufit-xxxx.myshopify.com`).

## Step 2: Create the server on Cloudflare

1. Sign up free at **https://dash.cloudflare.com/sign-up**.
2. Create the small database the server uses for limits:
   **Storage & databases → Workers KV → Create** → name `dojo-rewards` → Create.
3. **Compute (Workers) → Workers & Pages → Create → Start with Hello World** →
   name it `dojo-rewards` → **Deploy**.
4. Click **Edit code**. Delete everything in `worker.js`, paste the whole contents of
   [`worker.js`](worker.js) from this folder, then **Deploy**.
5. Back on the worker page → **Settings → Bindings → Add → KV namespace**:
   - Variable name: `REWARDS`
   - KV namespace: `dojo-rewards` → **Add binding** (deploy if asked).
6. **Settings → Variables and secrets → Add**, one by one:

   | Type | Name | Value |
   | --- | --- | --- |
   | Text | `SHOP` | `haikufit-xxxx.myshopify.com` (from step 1.6) |
   | Text | `ALLOWED_ORIGINS` | `https://haikufit.in,https://www.haikufit.in,https://haikufit-xxxx.myshopify.com` |
   | Text | `TIERS` | `[{"score":300,"amount":50},{"score":600,"amount":100},{"score":1000,"amount":150}]` |
   | Text | `MIN_SUBTOTAL` | `999` |
   | Text | `MAX_CREDIT_USE` | `100` |
   | Text | `CODE_HOURS` | `24` |
   | Text | `MAX_PER_IP` | `2` |
   | Text | `DAILY_LIMIT` | `200` |
   | Text | `API_VERSION` | `2026-07` |
   | Secret | `CLIENT_ID` | Client ID from step 1.5 |
   | Secret | `CLIENT_SECRET` | Client secret from step 1.5 |
   | Secret | `SIGNING_SECRET` | any long random text, e.g. 40+ random letters and numbers |
   | Secret | `ADMIN_KEY` | another long random text, only for you: it unlocks the `/health` check page |

   Click **Deploy** after adding them.
7. Copy your worker's address from the top of the page, e.g.
   `https://dojo-rewards.your-name.workers.dev`.

> Keep `TIERS` and `MIN_SUBTOTAL` the same as the scores and rupee amounts in the theme's
> Dojo Run section and the terms text shown under the game.

## Step 3: Connect the theme

1. Shopify admin → **Online Store → Themes → Customize**.
2. Click the **gear (Theme settings) → Dojo Run rewards**.
3. Paste the worker address into **Rewards server URL** → **Save**.

From now on the fixed codes are no longer printed in the page, and winners get unique codes.

## Step 4: Turn off the old fixed codes

If you created `DOJO5`, `DOJO10`, `DOJO15` in **Shopify admin → Discounts**, open each one
and **Deactivate** (or delete) it, because those codes may already have been shared.

## Step 5: Test it

1. Open your store's Dojo Run page and play until you pass 300 points, then finish the run.
2. The result card shows "Forging your personal code…" and then a code like `HF50-AB12CD`
   with "Single-use code · valid till …".
3. In **Shopify admin → Discounts** you will see `Dojo Run ₹50 · HF50-AB12CD`.
4. Add a tee to the cart and check out: the discount is already applied.

**Setup check:** open `https://<your-worker>.workers.dev/health?key=YOUR_ADMIN_KEY` in your browser
(add `&test` to also create and delete a real test code). Without the right key the page only says
"running", so nobody else can see your settings or recent codes. It lists every
setting with ✓ or ✗ and tells you exactly what to fix (wrong SHOP, missing secret, Shopify login,
missing discount permission…).

If the card says *"We couldn't create your code just now"*, open the worker in Cloudflare →
**Logs** (or *Observability*) to see the reason. Common causes:

- `SHOP` is your normal domain instead of the `.myshopify.com` one
- the Shopify app was not **installed** on the store, or the scopes were not released
- Client ID / secret pasted with extra spaces

## Housekeeping

- Codes expire after `CODE_HOURS`. Expired codes stay in your Discounts list. Filter by
  "Expired" and bulk delete them once a month.
- To change the limits, edit `MAX_PER_IP` or `DAILY_LIMIT` in Cloudflare (no theme change needed).
- To change the reward scores or amounts, change them **both** in the theme section and in `TIERS`.

## Store credit "Use my credit" (optional, same worker)

The worker also turns a signed-in customer's Shopify store credit into a one-time code in the cart,
so store credit works with any checkout (including Cashfree one-click). Full steps: **STORE-CREDIT.md**
(Step 4). In short:

- Shopify app scopes: add `read_customers`, `read_store_credit_accounts`,
  `write_store_credit_account_transactions` (Dev Dashboard → Versions → Release → update the app).
- Worker variables: Secret `CREDIT_SECRET` (same text as Theme settings → Store credit → Store credit
  secret), Text `CREDIT_DAYS` = `60`.
- Cron trigger `0 */6 * * *` so unused, expired credit codes give the credit back.
- `/health?key=…` shows `store_credit_permission`.

## Monthly rewards (turns on with CREDIT_SECRET)

When the worker has `CREDIT_SECRET` (the same secret as the theme's store credit secret):

- **Play as often as you like.** No daily limit (keep "Battles per day" at 0 in the theme).
- **Sign in to claim.** A guest who wins sees "Sign in to claim ₹100 OFF". The win is held for 2 hours
  (signed by the server, so it can't be edited). After signing in, the code is created.
- **The code is only for that customer, single use, valid 30 days** (`MONTHLY_CODE_DAYS`, default 30).
  Until it's used, playing again gives the same code back, or upgrades it if they score higher.
- **One chance a month.** The month starts the day they order with a Dojo code and lasts 30 days. After that
  order they can still play, but win no code until the month ends.
- **Bonus battle.** Each Dojo order whose tee they review on Judge.me (published, written after that order,
  a tee from that order) gives one more chance in the same month. So: order with code → parcel with the
  thank-you card → review → bonus code → order → review → another bonus… No review = no new code until the
  month ends. Bonus chances don't extend the month.
- No storage is used per game, so unlimited play stays inside Cloudflare's free plan.

App scopes needed: `read_orders`, `read_discounts`, `read_customers`, `read_products` (for the tee pictures),
and `write_customers` (for the reminder tags). Dev Dashboard → Versions → Release → update the app.
`/health?key=…` shows `monthly_rewards`, `review_bonus` and `reminder_tags`.

**Cashfree check:** place one test order through Cashfree one-click with a won code while signed in. If Cashfree
says the code is not valid for this customer, add Text `LOCK_TO_CUSTOMER` = `false`: codes then work for any buyer,
and the one-a-month rule is still kept by the worker (the code just isn't tied to the account at checkout).

### Judge.me (for the bonus battle)

1. Judge.me → **Settings → Integrations → Judge.me API** → copy the **Private API token**.
2. Cloudflare → worker → Settings → Variables and secrets → add **Secret** `JUDGEME_TOKEN` = that token.
3. Optional: Text `REVIEW_NEEDS_PHOTO` = `true` to count **photo reviews only**.
4. Keep review moderation on: only **published** reviews unlock a battle. Auto-publishing 4–5★ reviews makes
   the unlock instant.

### Thank-you card QR → `haikufit.in/pages/dojo-unlock`

Point the QR on your card to **`https://haikufit.in/pages/dojo-unlock`**. Create the page once:
Shopify → Online Store → Pages → Add page, title **Unlock your bonus battle**, handle `dojo-unlock`,
template **page.dojo-unlock**.

The customer signs in and sees their Dojo month: the date their next battle unlocks, and the tees from their
Dojo order with **Write a review** buttons. Once the review is published they get **Play Dojo Run**.
Scanning alone unlocks nothing, so it doesn't matter if the card is shared. Customers who never used a code
just see "Your Dojo Run is ready".

### Reminder emails (Shopify Flow + Shopify Email, free)

Every 6 hours (the cron) the worker tags customers:

- `dojo-ready` when their 30-day month ends (once per month),
- `dojo-bonus` as soon as a review unlocks a bonus battle (also tagged right away if they check on the page).

Make two workflows in **Shopify Flow** (free app):

1. Trigger **Customer tags added** → Condition: added tags include `dojo-ready` → Action **Send marketing email**
   (Shopify Email template): "Your Dojo Run has recharged, warrior. Play again and win up to ₹150 off:
   haikufit.in/pages/dojo-run".
2. Same with `dojo-bonus`: "Arigatō for your review! Your bonus battle is unlocked this month:
   haikufit.in/pages/dojo-run".

Shopify Email is free up to 10,000 emails a month, but only reaches customers who accepted email marketing,
so keep the "email me offers" box ticked by default at sign-up/checkout. (WhatsApp is not free: Meta charges
per message; any WhatsApp app with a Flow action can use the same tags later.)

## Order tracking (same worker)

The Track Order page lets customers enter their **order number + phone or email**. The worker looks the
order up in Shopify and returns each shipment's courier (Delhivery, DTDC…), AWB, status and tracking link.
Only that is returned: no address, no email, and a wrong number or wrong contact get the same
"not found" answer. After 10 wrong lookups in an hour a visitor is paused for that hour (correct lookups are never limited).

- Shopify app scope: add **`read_orders`** (Dev Dashboard → Versions → Release → update the app).
- When you fulfil an order in Shopify, choose the **courier** (Delhivery, DTDC, Blue Dart, Xpressbees, Ecom Express,
  Ekart, Shadowfax, India Post…) and paste the **AWB**. Shopify then makes the tracking link and the page shows it.
  Courier not in Shopify's list? Pick **Other**, type its name and paste its **tracking URL**.
  No link at all? The page falls back to a universal tracker that recognises most Indian couriers.
- `/health?key=…` shows `order_tracking_permission`.

## Security notes

- `/health` needs `?key=YOUR_ADMIN_KEY`, and the key must be at least 16 characters (a short one could be
  guessed by trying many). Codes in its recent-requests list are masked (`HF-7K••••••`). Server error details
  are shown only there, never to visitors.
- **One code per chance, even under attack.** Each Dojo chance and each store credit use has a code name
  fixed by the customer and a slot number (`HF-…` / `CR-…`, unguessable: made with SIGNING_SECRET).
  Requests sent at the same moment all try the same name and Shopify keeps one, so a script can't collect
  several codes at once. A better Dojo score raises the same code's amount instead of making another.
  Store credit is only taken after its code exists.
- Game tickets, held wins and customer signatures can't be swapped for one another.
- The game runs in the visitor's browser, so a script can fake a top score (it must still wait about a
  minute of "play"). That's why rewards are tied to a signed-in customer: one a month, single use, only for
  that customer, minimum order. Someone making many accounts can get one code per account; each still
  needs its own paid order of ₹999+, and `DAILY_LIMIT` caps codes per day.
- All secrets (`CLIENT_SECRET`, `SIGNING_SECRET`, `CREDIT_SECRET`, `ADMIN_KEY`) live only in Cloudflare.
  `CREDIT_SECRET` is also in Theme settings, which Shopify never shows to visitors. Don't share theme
  export zips: they contain it.
- Free Cloudflare KV allows 1,000 writes a day. The worker only writes what it must; if the limit is ever
  hit, rewards say "busy, try again" instead of giving codes away unchecked. With heavy traffic, move to
  Workers Paid ($5/month).
