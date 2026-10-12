---
name: monthly-content
description: Run the full monthly content pipeline for one Instagram/YouTube page — trend research, a 35-idea content calendar, then 30 short-form and 5 long-form scripts. Use when the user asks for a month of scripts, viral scripts, or content for a page. Args: <page-id> [YYYY-MM]. Run once per page for multiple pages.
---

# Monthly content for one page

Args: `<page-id>` (required; if missing, list `content-pipeline/pages/*.yaml` and
ask which — or offer `/new-page`) and `YYYY-MM` (default: next month).
Counts come from the page's `schedule` (defaults 30 shorts, 5 long).

Output dir: `content-pipeline/output/<page-id>/<YYYY-MM>/`. If it already has
files, ask before overwriting; otherwise continue where it left off.

Read first: the page YAML, `content-pipeline/templates/short-form.md`,
`long-form.md`, `hooks-library.md`. Use TaskCreate to track the stages below.

## Stage 1 — Trends
Follow `.claude/skills/trend-research/SKILL.md` to write `01-trend-report.md`
(skip if it exists for this month and is < 7 days old).

## Stage 2 — Calendar → `02-content-calendar.md`
1. Brainstorm ~60 ideas from the trend report, own winners, pillars, and dates.
2. Score each with the virality rubric in `hooks-library.md`. Keep shorts ≥ 7,
   long-form ≥ 8 with demand proof.
3. Pick 30 shorts + 5 long so that:
   - pillar mix matches `content_pillars` shares (±1 post),
   - no two consecutive shorts use the same hook formula or format,
   - time-sensitive ideas land before their date,
   - each long-form video has 2–3 shorts that tease or are cut from it,
   - ~20 % are remixes of proven own winners (if history exists).
4. Write a table: # · date · format (short/long) · pillar · title · hook formula ·
   trend source · score. Long-form on `posting_days` if set, otherwise spread
   ~weekly.
5. Show the user the calendar and pause for a quick OK / swaps before writing
   all scripts (skip the pause if the user asked for no check-ins).

## Stage 3 — Scripts
- `shorts/short-NN.md` (01–30) using `short-form.md`; `long/long-NN.md` (01–05)
  using `long-form.md`. Fill every field.
- Write in the page's `voice.language` and `tone`, natively (e.g. real Hinglish,
  not translated English). Use `words_to_use`; never use `words_to_avoid`.
- Respect `constraints` (e.g. no face → VO + b-roll / text-on-screen).
- Short scripts: hook within 3 s, one idea, a visual change every 2–3 s,
  payoff that matches the hook, one CTA. Word count ≈ 2.5 words per second.
- Long scripts: packaging first, open loop in the first 30 s, re-hooks between
  sections, ~150 words per minute of target length; write the full spoken
  script, not just bullet points.
- No fabricated stats or claims about the user's product; mark anything that
  needs fact-checking with `[VERIFY]`. Follow platform policies (no misleading
  health/finance claims, disclose paid promotion).
- Large batches: write files in parallel batches of ~5 to keep quality even;
  re-read the calendar before each batch to avoid repeats.

## Stage 4 — Wrap up
1. Create `performance.csv` with header
   `id,type,title,post_date,platform,url,views,likes,comments,shares,saves,avg_watch_pct,follows,notes`
   and one row per script (metrics blank) for the user to fill in.
2. Write `README.md` in the month folder: calendar link, filming batches
   (group scripts that share a location/setup), and top 5 "post these first" picks.
3. Report to the user: counts written, top picks, any `[VERIFY]` items, and
   remind them to fill `performance.csv` so next month learns from it.
4. If in a git repo on a working branch, commit the month folder.
