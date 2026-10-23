# HAIKUFIT tests

| Suite | What it covers | Command |
|---|---|---|
| **Worker (unit)** | Dojo Run codes, anti-cheat, store credit, order tracking, `/health` lock, rate limits, KV limits (Shopify mocked) | `npm run test:worker` |
| **Theme (integration)** | The real theme rendered with liquidjs and driven in Chromium: every page at 4 widths, cart drawer, coupons, Cashfree note, motion & gestures, store credit, Dojo Run claim, order tracking, gift box, size measurements, XSS | `npm run test:theme` |
| **Live (integration)** | haikufit.in: every page and product, links, assets, catalogue, cart & discount APIs, search, SEO, policies, HTTPS | `npm run test:live` |

```bash
cd shopify/tests
npm install                      # liquidjs + playwright
npx playwright install chromium  # or set CHROMIUM_PATH to an existing Chromium
npm test                         # worker + theme
npm run test:live                # needs internet access to haikufit.in
```

The live suite only creates a throwaway cart (cleared at the end) and pauses between requests,
because Shopify answers bursts with `429 Too Many Requests`. Override the pause with `GAP_MS`.
