---
name: trend-research
description: Research what is trending right now in one page's niche on Instagram and YouTube and write a sourced trend report. Use for "what's trending in my niche", trend analysis, or as stage 1 of /monthly-content. Args: <page-id> [YYYY-MM].
---

# Trend research for one page

Inputs: `<page-id>` and target month (default: next calendar month).
Read `content-pipeline/pages/<page-id>.yaml` first. Everything you research must
match that page's niche, audience location, and language.

## Sources (use all that are available; note which you used)

1. **YouTube data** — if `YOUTUBE_API_KEY` is set, run:
   `python3 content-pipeline/tools/yt_trends.py --page content-pipeline/pages/<page-id>.yaml --days 30 --region <ISO country> --out content-pipeline/output/<page-id>/<YYYY-MM>/yt_trends.csv`
   Look at the top views-per-day videos for both shorts and long-form: what
   topics, title patterns, and angles repeat? If no key, say so and rely on the
   other sources.
2. **Web search (last 30 days)** — run several searches in one turn:
   niche news, "<niche> trend <month year>", Reddit/forum questions, Google
   Trends coverage, "trending reels <niche>", trending audio round-ups for
   Instagram. Prefer recent, dated sources; discard anything older than ~60 days
   unless it's evergreen demand.
3. **Competitors** — for each account in `competitors`, search for their recent
   high-performing posts/videos and extract the topic and hook pattern.
4. **Calendar** — festivals, holidays, shopping events, exams, sports, launches in
   the audience's country during the target month.
5. **Own history** — if a previous month's `performance.csv` exists for this page,
   rank its rows by views and saves; the top 20 % are proven formats to repeat,
   the bottom 20 % are what to drop.
6. **User-supplied data** — anything the user pasted (Reel links, Insights CSVs,
   trending sounds they've seen). Instagram has no public trend API, so this and
   web search are the Instagram signal; say so in the report.

## Output: `content-pipeline/output/<page-id>/<YYYY-MM>/01-trend-report.md`

Sections:
1. **Summary** — 5 bullets: the biggest opportunities this month.
2. **Trending topics** — table: topic · evidence (link + numbers) · why it fits
   this audience · best format (short/long) · urgency (now / this month / evergreen).
   Aim for 15–25 rows.
3. **Winning formats & hooks** — patterns repeated by top performers.
4. **Trending audio / meme formats** (Instagram) — with how to adapt to the niche.
5. **Key dates** for the month.
6. **Proven from own history** / **drop list**.
7. **Sources** — every link used, with date.

Never fabricate numbers or links. If a claim has no source, label it as a
hypothesis.
