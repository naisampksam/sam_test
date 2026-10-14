# Dojo Run rewards server (unique discount codes)

Without this, the game gives everyone the same codes (DOJO5 / DOJO10 / DOJO15),
which anyone can share or find in the page source.

With this small free server, every winner gets **their own Shopify discount code**:

- unique, e.g. `HF10-K7Q2MX`
- works **once** (usage limit 1, once per customer)
- **expires** after 7 days
- fixed ₹50 / ₹100 / ₹150 off, minimum order ₹999; stacks with bundle offers and store credit
- created live in Shopify, so you see each one in **Shopify admin → Discounts**

Before creating a code the server checks:

| Check | Stops |
| --- | --- |
| Signed run ticket from the start of the game, used once | copying a request or reusing a run |
| Score must be possible for the real time the run lasted (game physics) | typing a fake score |
| Server decides the % from the score | asking for 15% with a 300 score |
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
   | Text | `CODE_DAYS` | `7` |
   | Text | `MAX_PER_IP` | `2` |
   | Text | `DAILY_LIMIT` | `200` |
   | Text | `API_VERSION` | `2026-07` |
   | Secret | `CLIENT_ID` | Client ID from step 1.5 |
   | Secret | `CLIENT_SECRET` | Client secret from step 1.5 |
   | Secret | `SIGNING_SECRET` | any long random text, e.g. 40+ random letters and numbers |

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
2. The result card shows "Forging your personal code…" and then a code like `HF5-AB12CD`
   with "Single-use code · valid till …".
3. In **Shopify admin → Discounts** you will see `Dojo Run 5% · HF5-AB12CD`.
4. Add a tee to the cart and check out: the discount is already applied.

**Setup check:** open `https://<your-worker>.workers.dev/health` in your browser (add `?test` to also create and delete a real test code). It lists every
setting with ✓ or ✗ and tells you exactly what to fix (wrong SHOP, missing secret, Shopify login,
missing discount permission…).

If the card says *"We couldn't create your code just now"*, open the worker in Cloudflare →
**Logs** (or *Observability*) to see the reason. Common causes:

- `SHOP` is your normal domain instead of the `.myshopify.com` one
- the Shopify app was not **installed** on the store, or the scopes were not released
- Client ID / secret pasted with extra spaces

## Housekeeping

- Codes expire after `CODE_DAYS`. Expired codes stay in your Discounts list. Filter by
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
- `/health` shows `store_credit_permission`.
