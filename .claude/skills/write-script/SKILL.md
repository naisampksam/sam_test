---
name: write-script
description: Write ONE Instagram Reel / YouTube Short or long-form script for a page — the next unwritten one from the month's calendar, a specific calendar id, or a one-off topic. Use when the user says "next", "next script", "write script short-07", or asks for a single script. Args: <page-id> [next | short-NN | long-NN | "topic"] [YYYY-MM].
---

# Write one script

Exactly one script per run. Never write more than one unless the user
explicitly asked for several in this message.

## 1. Work out what to write
- **Page:** from the args; if missing and only one active profile exists in
  `content-pipeline/pages/`, use it; otherwise ask. No profile at all → ask for
  the basics (niche, audience, language, tone, goal/CTA) in one message, save
  them with `/new-page`'s format, then continue.
- **Month:** from the args, else the latest month folder in
  `content-pipeline/output/<page-id>/`, else next month.
- **Item:**
  - `next` or nothing → the earliest-dated calendar item in
    `02-content-calendar.md` whose file (`shorts/short-NN.md` or `long/long-NN.md`)
    doesn't exist yet. All written → say the month is complete and suggest
    `/monthly-content <page-id> <next month>`.
  - `short-NN` / `long-NN` → that item (if its file exists, ask before overwriting).
  - A free topic in quotes → a one-off script: save it as
    `content-pipeline/output/<page-id>/extras/<YYYY-MM-DD>-<slug>.md`.
  - No calendar for the month and no topic → offer to plan the month first
    (`/monthly-content`), or to write a one-off script on a trending topic now
    after a quick web search.

## 2. Gather context (keep it light)
Read the page YAML, the matching template (`content-pipeline/templates/short-form.md`
or `long-form.md`), `hooks-library.md`, the calendar row, and the relevant rows
of `01-trend-report.md`. Skim the hooks used by the last 2–3 written scripts so
this one uses a different hook formula and opening.

## 3. Write it
- Fill every field of the template.
- Write natively in the page's `voice.language` and `tone` (real Hinglish, not
  translated English). Use `words_to_use`; never use `words_to_avoid`.
- Respect `constraints` (e.g. no face → VO + b-roll / text-on-screen).
- **Length is a hard limit.** Read `length` from the page YAML (defaults:
  `short_max_seconds: 60`, `long_minutes: [8, 15]`). Spoken words: ≈ 2.5 per
  second for shorts (60 s → max ~150 words), ≈ 150 per minute for long
  (8–15 min → ~1,200–2,250 words). Count the words before saving; if over,
  cut, never squeeze. Put the target duration and word count in the script's
  Length / Target length field.
- **Short:** hook within 3 s plus 2 alternative hooks, one idea, a visual change
  every 2–3 s, payoff that matches the hook, one CTA; the beat table's last
  timestamp must not pass `short_max_seconds`.
- **Long:** packaging first (3 titles + thumbnail), open loop in the first 30 s,
  re-hooks between sections, full spoken script (not bullet points) sized to the
  calendar's target minutes within `long_minutes`, 3–5 shorts to cut from it
  (each within `short_max_seconds`).
- No invented stats or claims about the user's product; mark facts to check with
  `[VERIFY]`. Follow platform policies (no misleading health/finance claims,
  disclose paid promotion).

## 4. Finish
1. Save the file. If in a git repo on a working branch, commit it (and push if
   the session's instructions say to).
2. Show the user the full script in the reply, so they can read it without
   opening the file.
3. End with one line: progress (e.g. "7 of 35 written") and "Say **next** for
   <next id>: <its title>."
