# HAIKUFIT store credit: full setup

**How it works for customers**

1. Every order of **₹799+** earns **10% back as store credit (max ₹250)**. Product pages and the cart
   show "Earn ₹X store credit with this order".
2. The credit is added **10 days after the order is fulfilled**, after the return window, so returned
   or refused COD parcels never earn credit. It is **valid for 60 days**.
3. A signed-in customer sees their balance:
   - next to the account icon in the header (e.g. **₹250**)
   - in the mobile menu
   - in the cart: **"You have ₹250 store credit [USE ₹250]"**
4. Tapping **USE ₹250** takes the credit off the order straight away, in the cart. This works with
   **any checkout**, including Cashfree one-click. If they remove it, or don't check out within 48 hours,
   the credit goes back to their account automatically.

Shopify keeps the balance (Customers → a customer → Store credit). Shopify Flow adds the credit. Your
Dojo Run rewards worker (Cloudflare) turns credit into a one-time code when the customer taps USE.

---

## Step 1: Customer accounts (5 min)
Shopify admin → **Settings → Customer accounts**:
- Choose **Customer accounts** (the new, passwordless ones: customers log in with an email code).
- Turn on **Show login links in online store header and checkout**.

## Step 2: Check store credit is available on your store (2 min)
Open **Customers** → any customer (or create yourself as a customer) → look for a **Store credit** card →
**Issue credit** → ₹10 → Save.
- You see it: great, continue. Delete or keep the ₹10 test credit.
- No Store credit card: your plan or region doesn't have store credit yet. Tell me and I'll switch you
  to the coupon version, which doesn't need store credit.

## Step 3: Earn credit automatically with Shopify Flow (10 min)
1. Shopify App Store → **Shopify Flow** (free, by Shopify) → Install.
2. Flow → **Create workflow** → **Select a trigger** → **Order fulfilled**.
3. Add **Wait** → **10 days**.
4. Add **Condition** with **all** of these (click "Add criteria" for each):
   - `Order / Customer` **is not empty**
   - `Order / Display financial status` **is equal to** `PAID`
   - `Order / Cancelled at` **is empty**
   - `Order / Total refunded set / Shop money / Amount` **is equal to** `0`
   - `Order / Current subtotal price set / Shop money / Amount` **greater than or equal to** `799`
5. Under **Then** → **Add action** → **Send Admin API request**:
   - Mutation: **storeCreditAccountCredit**
   - Mutation inputs (paste exactly):

```json
{
  "id": "{{ order.customer.id }}",
  "creditInput": {
    "creditAmount": {
      "amount": "{{ order.currentSubtotalPriceSet.shopMoney.amount | times: 0.10 | at_most: 250 | floor }}",
      "currencyCode": "INR"
    },
    "expiresAt": "{{ 'now' | date: '%s' | plus: 5184000 | date: '%Y-%m-%dT%H:%M:%SZ' }}"
  }
}
```

   `0.10` = 10%, `250` = maximum, `floor` = whole rupees, `5184000` seconds = 60 days.
6. (Optional) Add **Send customer email / WhatsApp** saying "₹X store credit added, valid 60 days".
7. Name it **Store credit 10%** → **Turn on workflow**.

> COD orders: the condition only passes once the order is **Paid**. Make sure COD orders are marked paid
> after delivery (Shiprocket / your courier app usually does this, or mark them paid by hand).
> If you change 10% / ₹250 / ₹799, change them too in **Customize → Theme settings → Store credit**.

## Step 4: "Use my credit" button in the cart (10 min)
This uses the same Cloudflare worker as Dojo Run rewards.

**4a. Give the Shopify app store-credit permission.** Go to **dev.shopify.com/dashboard → Dojo Run Rewards →
Versions → Create version**. Keep `write_discounts` and `read_discounts`, and **add**:
- `read_customers`
- `read_store_credit_accounts`
- `write_store_credit_account_transactions`

Click **Release**. Then in Shopify admin → **Apps → Dojo Run Rewards**, accept or **Update** the new permissions if asked.

**4b. Update the worker.** In Cloudflare → **dojo-rewards → Edit code**, paste the new `worker.js` → **Deploy**.
Then go to **Settings → Variables and secrets → Add**:

| Type | Name | Value |
|---|---|---|
| Secret | `CREDIT_SECRET` | 40+ random letters and numbers (make it up, keep it private) |
| Text | `CREDIT_DAYS` | `60` |

Then **Deploy**.

**4c. Automatic refunds of unused credit codes.** Go to **Settings → Trigger events** (or *Triggers*) →
**Add → Cron trigger** → `0 */6 * * *` (every 6 hours) → Add.

**4d. Connect the theme.** **Customize → gear (Theme settings) → Store credit**:
- **Credit per order**: Percentage · **10** % · max **250** · minimum **799**
- **Store credit secret**: paste **exactly the same text** as `CREDIT_SECRET` → **Save**.
  The Rewards server URL in *Dojo Run rewards* must already be set (`https://dojo-rewards.haikufitclothing.workers.dev`).

**4e. Check it.** Open `https://dojo-rewards.haikufitclothing.workers.dev/health` →
`store_credit_permission` must show **✓ store credit scopes granted**.

## Step 5: Test the whole thing (5 min)
1. Give yourself credit: **Customers → you → Store credit → Issue credit → ₹50**.
2. On haikufit.in, **sign in** (header account icon → your email → code).
3. The header shows **₹50** on the account icon. Add a tee to the cart and open the cart: you see
   **"You have ₹50 store credit [USE ₹50]"**.
4. Tap **USE ₹50**. The cart shows a code chip `CR-XXXXXXXX −₹50` and the total drops by ₹50.
   Your balance in Shopify is now ₹0.
5. Tap **×** on the chip. The ₹50 comes back (check the header or Shopify).
6. Apply it again and complete a test order. The discount carries into checkout (including Cashfree).
   Check the order shows the CR- discount.

If anything fails, open `/health` on the worker: `recent_requests` shows the exact reason.

## Good to know
- **Shopify checkout also works on its own:** in Shopify's own checkout, signed-in customers can also
  apply store credit there as a payment. The cart button is what makes it work with Cashfree.
- **Credit codes are safe:** each code works once, only for that customer, and expires in 48 hours.
  A customer can only ever spend their own credit (the cart signs their customer ID with your secret).
- **Combining:** credit codes combine with your bundle offers and other codes (it's money, not a promo).
  Shopify only puts two codes on one order if **both** allow it. For any code you want customers to use
  together with store credit (e.g. WELCOME10), open it in **Discounts → Combinations** and tick
  **Order discounts**. Dojo Run codes made by the worker already allow it.
- **Giving credit by hand** (apology, influencer, return as credit): Customers → customer → Store credit →
  Issue credit.
