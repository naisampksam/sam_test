#!/usr/bin/env python3
"""Generate a month of Instagram/YouTube scripts for one page with the Claude API.

Runs the same pipeline as the /monthly-content skill, but standalone, using your
own Anthropic API key:

  1. trend report   -> 01-trend-report.md   (Claude searches the web)
  2. content plan   -> 02-content-calendar.md
  3. scripts        -> shorts/short-NN.md, long/long-NN.md
  4. tracking sheet -> performance.csv

Setup (once):
    pip install -r content-pipeline/tools/requirements.txt
    put ANTHROPIC_API_KEY=sk-ant-... in content-pipeline/.env   (never commit it)

Run:
    python3 content-pipeline/tools/generate.py --page my-page --month 2026-11
    python3 content-pipeline/tools/generate.py --all --month 2026-11    # every active page

Re-running resumes: finished files are skipped. Use --force to regenerate.
"""
import argparse
import csv
import json
import os
import sys
from datetime import date
from pathlib import Path

try:
    import anthropic
    import yaml
except ImportError:
    sys.exit("Missing packages: pip install -r content-pipeline/tools/requirements.txt")

ROOT = Path(__file__).resolve().parent.parent          # content-pipeline/
MODEL = "claude-opus-5-5"
BETAS = ["server-side-fallback-2026-07-01"]             # retry on another model if a request is declined
WEB_SEARCH = {"type": "web_search_20260209", "name": "web_search", "max_uses": 15}
BATCH = 1                                              # scripts per API call (one at a time)


def load_env():
    """Read KEY=VALUE lines from content-pipeline/.env into the environment."""
    env = ROOT / ".env"
    if env.exists():
        for line in env.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                os.environ.setdefault(k.strip(), v.strip().strip("\"'"))


def next_month():
    t = date.today()
    return f"{t.year + (t.month == 12)}-{t.month % 12 + 1:02d}"


def call(client, system, prompt, *, schema=None, search=False, max_tokens=64000):
    """One streamed request; resumes pause_turn; returns the final text."""
    messages = [{"role": "user", "content": prompt}]
    kwargs = dict(
        model=MODEL,
        max_tokens=max_tokens,
        betas=BETAS,
        fallbacks="default",
        thinking={"type": "adaptive"},
        output_config={"effort": "high"},
        # system prompt (page profile + templates) is identical for every call
        # of a page, so cache it.
        system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
    )
    if search:
        kwargs["tools"] = [WEB_SEARCH]
    if schema:
        kwargs["output_config"] = {**kwargs["output_config"],
                                   "format": {"type": "json_schema", "schema": schema}}
    for _ in range(6):
        with client.beta.messages.stream(messages=messages, **kwargs) as stream:
            resp = stream.get_final_message()
        if resp.stop_reason == "pause_turn":           # long web search: let it continue
            messages = [messages[0], {"role": "assistant", "content": resp.content}]
            continue
        break
    if resp.stop_reason == "refusal":
        raise RuntimeError(f"Request declined: {resp.stop_details}")
    if resp.stop_reason == "max_tokens":
        raise RuntimeError("Response hit max_tokens; try a smaller batch.")
    text = "".join(b.text for b in resp.content if b.type == "text").strip()
    u = resp.usage
    print(f"    tokens in={u.input_tokens} cached={u.cache_read_input_tokens or 0} out={u.output_tokens}")
    return json.loads(text) if schema else text


def system_prompt(page_yaml):
    t = ROOT / "templates"
    return f"""You are a senior short-form and YouTube scriptwriter and content strategist.
You write scripts that stop the scroll, hold retention, and get shared and saved,
for one specific page described below. Write natively in the page's language and tone.
Never invent statistics, quotes, or facts about the creator's business; mark anything
that needs checking with [VERIFY]. Follow platform policies (no misleading health or
finance claims; disclose paid promotion).

<page_profile>
{page_yaml}
</page_profile>

<short_form_template>
{(t / "short-form.md").read_text(encoding="utf-8")}
</short_form_template>

<long_form_template>
{(t / "long-form.md").read_text(encoding="utf-8")}
</long_form_template>

<hooks_library>
{(t / "hooks-library.md").read_text(encoding="utf-8")}
</hooks_library>"""


CALENDAR_SCHEMA = {
    "type": "object",
    "properties": {
        "items": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "id": {"type": "string", "description": "short-01..short-NN or long-01..long-NN"},
                    "type": {"type": "string", "enum": ["short", "long"]},
                    "date": {"type": "string", "description": "YYYY-MM-DD"},
                    "pillar": {"type": "string"},
                    "title": {"type": "string"},
                    "angle": {"type": "string", "description": "the specific take / promise"},
                    "hook_formula": {"type": "string"},
                    "format": {"type": "string"},
                    "trend_source": {"type": "string"},
                    "score": {"type": "integer"},
                    "related_long": {"type": "string", "description": "long-NN this short supports, or empty"},
                },
                "required": ["id", "type", "date", "pillar", "title", "angle", "hook_formula",
                             "format", "trend_source", "score", "related_long"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["items"],
    "additionalProperties": False,
}

SCRIPTS_SCHEMA = {
    "type": "object",
    "properties": {
        "scripts": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {"id": {"type": "string"}, "markdown": {"type": "string"}},
                "required": ["id", "markdown"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["scripts"],
    "additionalProperties": False,
}


def run_page(client, page_file, month, force):
    page_yaml = page_file.read_text(encoding="utf-8")
    page = yaml.safe_load(page_yaml)
    pid = page.get("page_id") or page_file.stem
    sched = page.get("schedule") or {}
    n_short, n_long = int(sched.get("shorts_per_month", 30)), int(sched.get("long_per_month", 5))
    ln = page.get("length") or {}
    max_s = int(ln.get("short_max_seconds", 60))
    lo, hi = (ln.get("long_minutes") or [8, 15])[:2]
    limits = (f"Hard length limits: shorts at most {max_s} s (~{int(max_s * 2.5)} spoken words); "
              f"long videos {lo}-{hi} min (~{lo * 150}-{hi * 150} words). Count words; cut if over.")
    out = ROOT / "output" / pid / month
    (out / "shorts").mkdir(parents=True, exist_ok=True)
    (out / "long").mkdir(parents=True, exist_ok=True)
    system = system_prompt(page_yaml)
    print(f"\n=== {pid} · {month} · {n_short} shorts + {n_long} long ===")

    # extra evidence: YouTube data and last month's results, if present
    evidence = ""
    yt = out / "yt_trends.csv"
    if yt.exists():
        evidence += f"\n<youtube_trends_csv>\n{yt.read_text(encoding='utf-8')[:30000]}\n</youtube_trends_csv>\n"
    for prev in sorted((ROOT / "output" / pid).glob("*/performance.csv")):
        if prev.parent.name < month:
            evidence += f"\n<past_performance month='{prev.parent.name}'>\n{prev.read_text(encoding='utf-8')}\n</past_performance>\n"

    # 1. trends
    report = out / "01-trend-report.md"
    if force or not report.exists():
        print("  [1/3] researching trends (web search)…")
        report.write_text(call(client, system, f"""Today is {date.today()}. Research what is trending
right now for this page's niche and audience, to plan content for {month}.
Search the web (last ~30 days): niche news, trend round-ups, Reddit/forum questions,
trending Instagram Reels formats and audio, YouTube topics, and the listed competitors.
Also list festivals, holidays, and events in the audience's country during {month}.
{evidence}
Write a markdown report with: 1. Summary (5 bullets) 2. Trending topics table (topic ·
evidence with link and numbers · why it fits · best format · urgency), 15-25 rows
3. Winning formats & hooks 4. Trending audio / meme formats 5. Key dates
6. Proven from own history / drop list (if data given) 7. Sources with dates.
Only cite what you found; label unsourced ideas as hypotheses.""", search=True), encoding="utf-8")
    else:
        print("  [1/3] trend report exists, skipping")

    # 2. calendar
    cal_json = out / "02-content-calendar.json"
    if force or not cal_json.exists():
        print("  [2/3] building content calendar…")
        cal = call(client, system, f"""<trend_report>\n{report.read_text(encoding='utf-8')}\n</trend_report>
{evidence}
Plan {month}: brainstorm ~60 ideas, score each with the virality rubric, then choose exactly
{n_short} shorts (score >= 7, ids short-01..short-{n_short:02d}) and {n_long} long-form videos
(score >= 8 with demand proof, ids long-01..long-{n_long:02d}).
Rules: pillar mix matches content_pillars; no two consecutive shorts share a hook formula
or format; time-sensitive ideas land before their date; each long video has 2-3 related
shorts; spread long videos across the month (use posting_days if set).
Give every item a target length in its angle field. {limits}
Return all chosen items sorted by date.""", schema=CALENDAR_SCHEMA, max_tokens=32000)
        cal_json.write_text(json.dumps(cal, indent=2, ensure_ascii=False), encoding="utf-8")
        rows = ["| # | Date | Type | Pillar | Title | Hook | Score |", "|---|---|---|---|---|---|---|"]
        rows += [f"| {i['id']} | {i['date']} | {i['type']} | {i['pillar']} | {i['title']} | "
                 f"{i['hook_formula']} | {i['score']} |" for i in cal["items"]]
        (out / "02-content-calendar.md").write_text(
            f"# Content calendar — {pid} — {month}\n\n" + "\n".join(rows) + "\n", encoding="utf-8")
    items = json.loads(cal_json.read_text(encoding="utf-8"))["items"]

    # 3. scripts, in batches
    print("  [3/3] writing scripts…")
    path = lambda i: out / ("shorts" if i["type"] == "short" else "long") / f"{i['id']}.md"
    todo = [i for i in items if force or not path(i).exists()]
    calendar_text = (out / "02-content-calendar.md").read_text(encoding="utf-8")
    for kind, size in (("short", BATCH), ("long", 1)):   # long scripts one per call: they're big
        group = [i for i in todo if i["type"] == kind]
        for b in range(0, len(group), size):
            batch = group[b:b + size]
            print(f"    {', '.join(i['id'] for i in batch)}")
            res = call(client, system, f"""<content_calendar>\n{calendar_text}\n</content_calendar>
Write the full scripts for these calendar items, each filling every field of the
{'short_form' if kind == 'short' else 'long_form'}_template. {'Short: hook in first 3 s, one idea, visual change every 2-3 s, ~2.5 spoken words per second, one CTA.' if kind == 'short' else 'Long: packaging first, open loop in the first 30 s, re-hooks between sections, full spoken script at ~150 words per minute of target length (not bullet points).'}
Make each one clearly different from the others in the calendar. {limits}
<items>\n{json.dumps(batch, indent=2, ensure_ascii=False)}\n</items>
Return one entry per item with its id and the complete script as markdown.""",
                       schema=SCRIPTS_SCHEMA)
            by_id = {i["id"]: i for i in batch}
            for s in res["scripts"]:
                if s["id"] in by_id:
                    path(by_id[s["id"]]).write_text(s["markdown"].strip() + "\n", encoding="utf-8")

    # 4. tracking sheet
    perf = out / "performance.csv"
    if not perf.exists():
        with perf.open("w", newline="", encoding="utf-8") as f:
            w = csv.writer(f)
            w.writerow(["id", "type", "title", "post_date", "platform", "url", "views", "likes",
                        "comments", "shares", "saves", "avg_watch_pct", "follows", "notes"])
            for i in items:
                w.writerow([i["id"], i["type"], i["title"], i["date"]] + [""] * 10)
    missing = [i["id"] for i in items if not path(i).exists()]
    print(f"  done → {out.relative_to(ROOT.parent)}" + (f"  (missing: {missing} — re-run)" if missing else ""))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--page", help="page id (file name in pages/ without .yaml)")
    g.add_argument("--all", action="store_true", help="every page with active: true")
    ap.add_argument("--month", default=next_month(), help="YYYY-MM (default: next month)")
    ap.add_argument("--force", action="store_true", help="regenerate files that already exist")
    args = ap.parse_args()

    load_env()
    if not os.environ.get("ANTHROPIC_API_KEY"):
        sys.exit("No API key. Create content-pipeline/.env with:  ANTHROPIC_API_KEY=sk-ant-...\n"
                 "Get a key at https://console.anthropic.com → API Keys.")

    if args.all:
        pages = [p for p in sorted((ROOT / "pages").glob("*.yaml"))
                 if not p.name.startswith("_") and (yaml.safe_load(p.read_text(encoding="utf-8")) or {}).get("active")]
    else:
        pages = [ROOT / "pages" / f"{args.page}.yaml"]
    if not pages or not all(p.exists() for p in pages):
        sys.exit(f"Page profile not found / none active in {ROOT / 'pages'}")

    client = anthropic.Anthropic()
    for p in pages:
        try:
            run_page(client, p, args.month, args.force)
        except anthropic.AuthenticationError:
            sys.exit("Invalid ANTHROPIC_API_KEY.")
        except anthropic.RateLimitError:
            sys.exit("Rate limited — wait a minute and re-run (finished files are kept).")
        except (anthropic.APIStatusError, anthropic.APIConnectionError, RuntimeError) as e:
            print(f"  ERROR on {p.stem}: {e} — re-run to resume.", file=sys.stderr)


if __name__ == "__main__":
    main()
