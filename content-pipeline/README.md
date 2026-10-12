# Content Pipeline: Instagram + YouTube scripts

A repeatable, multi-page pipeline that researches what's trending in each niche
and produces, **per page, per month**:

- **30 short-form scripts** (Instagram Reels / YouTube Shorts, 15–60 s)
- **5 long-form scripts** (YouTube, 8–15 min)

Every page (Instagram account / YouTube channel) has its own profile, so
different niches, languages, and tones never get mixed.

```
content-pipeline/
├── pages/                     one profile per page (niche, audience, voice, rules)
│   ├── _TEMPLATE.yaml         copy this for every new page
│   └── <page-id>.yaml
├── templates/                 the exact shape every script follows
│   ├── short-form.md
│   ├── long-form.md
│   └── hooks-library.md       proven hook formulas the writer pulls from
├── tools/
│   └── yt_trends.py           pulls top-performing recent YouTube videos for a niche
└── output/<page-id>/<YYYY-MM>/
    ├── 01-trend-report.md     what's trending + why (with sources)
    ├── 02-content-calendar.md 35 ideas mapped to dates, pillars, formats
    ├── shorts/short-01.md … short-30.md
    ├── long/long-01.md … long-05.md
    └── performance.csv        you fill in views/saves after posting → feeds next month
```

## How the pipeline runs

| Stage | What happens | Command |
|---|---|---|
| 0. Onboard a page | Claude interviews you and writes `pages/<page-id>.yaml` | `/new-page` |
| 1. Trend research | Web search (last 30 days), YouTube Data API top videos, competitor accounts, seasonal/festival dates, last month's winners | `/trend-research <page-id>` |
| 2. Calendar | 35 ideas scored for virality, balanced across content pillars, mapped to posting dates | part of `/monthly-content` |
| 3. Scripts | 30 shorts + 5 long-form written in the page's voice using the templates | part of `/monthly-content` |
| 4. Feedback loop | You log results in `performance.csv`; next month doubles down on what worked | automatic next run |

Full month for one page: **`/monthly-content <page-id> <YYYY-MM>`** (runs stages 1–3).
For all pages: run it once per page id — each page is independent.

## Option B: run it yourself with your own Claude API key

`tools/generate.py` runs the same pipeline (trend research with live web search,
calendar, 30 + 5 scripts, performance sheet) directly on the Claude API, so you
don't need Claude Code to generate scripts.

1. Get a key: <https://console.anthropic.com> → **API Keys** → *Create key*
   (API usage is billed separately from a Claude.ai subscription; add credits under Billing).
2. Install (Python 3.10+):
   `pip install -r content-pipeline/tools/requirements.txt`
3. Copy `content-pipeline/.env.example` to `content-pipeline/.env` and paste your key.
   `.env` is git-ignored. **Never commit a key or paste it into a chat or a GitHub issue.**
4. Run:
   ```bash
   python3 content-pipeline/tools/generate.py --page <page-id> --month 2026-11
   python3 content-pipeline/tools/generate.py --all --month 2026-11   # every active page
   ```
   If it stops midway (network, rate limit), run the same command again; finished
   files are kept. `--force` regenerates everything.

Rough cost: one page-month is about 13 API calls. With current Claude Opus 5.5 pricing, expect
around **$3 to $8 per page per month**, depending on how long the scripts are and how much
web search runs. Your Console's Usage page shows the real number.

## What information to give (the more, the better the scripts)

**Must have (per page)**
1. Page handle + platform(s) (Instagram, YouTube, or both)
2. Niche and sub-niche (e.g. "fitness → home workouts for busy dads")
3. Target audience: age, gender, country/city, what they struggle with, what they want
4. Language & style (English / Hindi / Hinglish / Tamil…, formal vs. casual, face-cam vs. voice-over vs. text-on-screen)
5. Goal of the page: followers, sales, leads, brand awareness, affiliate income
6. What you sell / promote (if anything) and the CTA you want

**Very useful**
7. 3–10 competitor / inspiration accounts you like
8. Your top 5 and bottom 5 posts (links + views) — teaches what your audience reacts to
9. Content pillars you want (e.g. 40 % education, 30 % entertainment, 20 % story, 10 % sales)
10. Topics / words to avoid, legal or brand restrictions
11. Recording constraints (solo, no face, phone only, budget, location)
12. Posting frequency and preferred days

**Optional power-ups**
13. Instagram Insights / YouTube Studio exports (CSV) — audience active hours, retention graphs
14. A `YOUTUBE_API_KEY` (free from Google Cloud Console) so `tools/yt_trends.py` can pull real view data
15. Upcoming launches, events, festivals, collabs you want covered

## Honest limits

- **Instagram has no public "trending" API.** Instagram trends come from web
  search (news, Reddit, Google Trends coverage, trend round-ups), the
  competitor accounts you list, and data you paste in (Insights exports, links to
  Reels that are blowing up, trending audio names you see in the app).
- **YouTube** trends are measurable: `tools/yt_trends.py` ranks recent videos for
  your niche keywords by views-per-day.
- Nobody can guarantee a video goes viral. The pipeline maximises the odds:
  proven hook formulas, retention structure, topics with demonstrated demand,
  and a monthly feedback loop that learns from your actual numbers.
