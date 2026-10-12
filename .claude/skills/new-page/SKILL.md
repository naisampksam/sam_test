---
name: new-page
description: Onboard a new Instagram/YouTube page into the content pipeline by interviewing the user and writing content-pipeline/pages/<page-id>.yaml. Use when the user wants to add a page, account, channel, or niche.
---

# Onboard a page

1. Read `content-pipeline/pages/_TEMPLATE.yaml` and list existing profiles in
   `content-pipeline/pages/` so you don't create a duplicate.
2. If the user already pasted details, extract everything you can from them first.
3. Ask only for what's still missing, in at most two rounds, grouped:
   - **Basics:** handles, platforms, niche + sub-niche, language, goal, offer, CTA.
   - **Audience:** age, gender, location, pain points, desires, beginner/expert.
   - **Voice & production:** tone, format (face-cam / VO / text), constraints.
   - **Evidence:** competitors, top 5 / bottom 5 posts with views, Insights exports.
   Unknown answers are fine — leave them blank, never invent facts about the
   user's business. If the user names competitors, you may web-search them to
   suggest pillars and keywords, and say that you did.
4. Propose 10–15 `niche.keywords` (the phrases the audience actually searches)
   and 3–5 content pillars whose shares sum to 100. Confirm with the user.
5. Write `content-pipeline/pages/<page-id>.yaml` (lowercase-dashed id) and set
   `active: true`.
6. Tell the user the next step: `/monthly-content <page-id> <YYYY-MM>`.
