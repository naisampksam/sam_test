# HAIKUFIT: pages, menus and collections to create

The theme already has a **designed template** for every page. In Shopify you only create the page,
give it the exact **handle** below and pick the **template**. Leave the page content empty
(anything you type there shows under the designed sections).

## 1. Pages: Online Store → Pages → Add page

| Title | Handle (URL) | Template | Linked from |
|---|---|---|---|
| Our Story | `our-story` | `page.story` | "Read our story" buttons, menu |
| Contact | `contact` | `page.contact` | FAQ, footer, help links |
| FAQ | `faq` | `page.faq` | footer |
| Size Guide | `size-guide` | `page.size-guide` | FAQ, footer |
| Shipping & Delivery | `shipping` | `page.shipping` | footer |
| Returns & Exchanges | `returns-exchanges` | `page.returns` | FAQ, footer |
| Track Your Order | `track-order` | `page.track-order` | FAQ, shipping page, footer |
| Dojo Run | `dojo-run` | `page.dojo` | menu (optional) |

**How:** type the title → scroll to **Search engine listing** → **Edit** → set **URL handle** exactly as above →
right side **Theme template** → choose the template → **Save**.

Links to a page that doesn't exist yet go to the home page, so nothing breaks while you set this up.

## 2. Collections: Products → Collections → Create collection (automated)

| Title | Handle | Condition |
|---|---|---|
| Bushidō | `bushido` | Product tag is equal to `bushido` |
| Warriors | `warriors` | Product tag is equal to `warrior` |
| New Arrivals | `new-arrivals` | Product tag is equal to `new` |
| Bestsellers | `bestsellers` | Product tag is equal to `bestseller` |
| Acid Wash | `acid-wash` | Product tag is equal to `acid-wash` |

"Shop All" is Shopify's built-in `/collections/all`; no need to create it.

## 3. Menus: Online Store → Navigation

**Main menu**
- Shop All → `/collections/all` (if your menu says "Catalog", the theme shows it as "Shop All"; you can also rename it)
- New Drops → collection New Arrivals
- Bushidō → collection Bushidō
- Warriors → collection Warriors
- Our Story → page Our Story

**Footer menu**
- FAQ · Size Guide · Shipping & Delivery · Returns & Exchanges · Track Your Order · Contact
- Privacy Policy · Terms of Service · Refund Policy (Shopify policies, see below)

## 4. Policies: Settings → Policies

Click **Create from template** for Refund, Privacy and Terms of Service, then edit:
- Business name: **LOOMA APPARELS LLP** (brand HAIKUFIT), registered address, email support@haikufit.in, phone.
- Refund policy: 7-day size exchange on unworn tees with tags; damaged/wrong items replaced or refunded if reported within 48 hours with an unboxing video; refunds in 5–7 working days.
- Shipping policy: dispatch in 24–48 hours; delivery 3–7 working days; free above ₹999, ₹79 below; COD available (fee shown before payment).
- Add a **Grievance Officer** (name, email, phone) to the Contact information policy; Indian e-commerce rules require it.

## 5. After setting up
Open each page on your phone and tap every footer link once to check nothing goes to the home page by mistake.
