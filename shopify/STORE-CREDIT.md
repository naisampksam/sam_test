# HAIKUFIT store credit: setup

Every order earns store credit (default **₹100 on orders of ₹499+, valid 90 days**). Customers spend it on their
next order. The theme already shows "Earn ₹100 store credit" on product pages and in the cart, and shows a
signed-in customer's balance. Shopify holds the balance; **Shopify Flow** adds the credit after each paid order.

## 1. Turn on customer accounts
Settings → **Customer accounts** → use **Customer accounts** (the new, passwordless ones) and turn on
**Show login link in the header and at checkout**. Customers spend store credit when they're signed in at checkout.

## 2. Install Shopify Flow
Shopify App Store → **Shopify Flow** (free, by Shopify) → Install.

## 3. Create the workflow: Flow → Create workflow
1. **Trigger:** `Order paid`
   (prepaid orders: right away; COD orders: when the order is marked paid after delivery, so returned COD parcels never earn credit)
2. **Condition:** add two checks (both must be true):
   - `Order / Customer` **is not empty**
   - `Order / Current subtotal price set / Shop money / Amount` **greater than or equal to** `499`
3. **Action (if true):** `Send Admin API request`
   - Mutation: **storeCreditAccountCredit**
   - Mutation inputs (paste):

```json
{
  "id": "{{ order.customer.id }}",
  "creditInput": {
    "creditAmount": { "amount": "100.00", "currencyCode": "INR" },
    "expiresAt": "{{ 'now' | date: '%s' | plus: 7776000 | date: '%Y-%m-%dT%H:%M:%SZ' }}"
  }
}
```

   `7776000` seconds = 90 days. To give a **percentage** instead (5%, max ₹200), replace `"100.00"` with:
   `"{{ order.currentSubtotalPriceSet.shopMoney.amount | times: 0.05 | at_most: 200 | round: 2 }}"`
4. **Turn on** the workflow.

**Match the theme:** if you change the amount, percentage, maximum or minimum here, change them too in
**Online Store → Themes → Customize → Theme settings → Store credit**, so the "Earn ₹X" messages are correct.

## 4. Test it
Place a test order while signed in with your own email, mark it paid, then open **Customers → you** and check
**Store credit**. Place a second order signed in: the credit appears at checkout.

## Good to know
- **Cashfree one-click checkout:** store credit is spent in **Shopify's checkout**. Ask Cashfree whether their
  popup applies Shopify store credit. If it doesn't, either let signed-in customers with credit use the normal
  checkout, or use the coupon method below.
- **Coupon method (works with any checkout):** instead of store credit, the Flow action can create a one-time
  ₹100 code for the customer (mutation `discountCodeBasicCreate`, usage limit 1, restricted to that customer)
  and send it with **Send email** or your WhatsApp app. Ask me and I'll write that workflow.
- **Store credit not showing on a customer?** Store credit depends on your plan and country. If Shopify doesn't
  offer it on your store yet, use the coupon method.
- Customers can see their balance on the cart page (when signed in) and in their account.
