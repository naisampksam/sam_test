---
name: monthly-content
description: Plan a month of content for one Instagram/YouTube page — trend research and a 35-idea calendar (30 short-form + 5 long-form) — then write the scripts one at a time. Use when the user asks for a month of scripts, viral scripts, or content for a page. Args: <page-id> [YYYY-MM]. Run once per page for multiple pages.
---

# Monthly content for one page

Args: `<page-id>` (required; if missing, list `content-pipeline/pages/*.yaml` and
ask which — or offer `/new-page`) and `YYYY-MM` (default: next month).
Counts come from the page's `schedule` (defaults 30 shorts, 5 long).
This skill plans the month; scripts are then written one at a time.

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
   target length · trend source · score. Target length must fit the page's
   `length` limits (shorts ≤ `short_max_seconds`; long within `long_minutes`;
   defaults 60 s and 8–15 min). Long-form on `posting_days` if set, otherwise spread
   ~weekly.
5. Show the user the calendar and pause for a quick OK / swaps before writing
   all scripts (skip the pause if the user asked for no check-ins).

## Stage 3 — Scripts (one at a time)
Scripts are written **one per turn** so each gets full attention and a single
turn stays small on the user's usage limits.
1. Create `performance.csv` now with header
   `id,type,title,post_date,platform,url,views,likes,comments,shares,saves,avg_watch_pct,follows,notes`
   and one row per calendar item (metrics blank).
2. Write only the first unwritten item (earliest date) by following
   `.claude/skills/write-script/SKILL.md`, then stop.
3. Tell the user to say **"next"** (or `/write-script <page-id>`) for the following
   one, or name a specific id (e.g. `long-02`) to jump to it.
4. Only if the user explicitly asks for several or all scripts in one go, loop
   the write-script steps item by item, still one file at a time.

## When the last script is done
1. Write `README.md` in the month folder: calendar link, filming batches
   (group scripts that share a location/setup), and top 5 "post these first" picks.
2. Report: counts written, top picks, any `[VERIFY]` items, and remind the user
   to fill `performance.csv` so next month learns from it.
