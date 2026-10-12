#!/usr/bin/env python3
"""Find what's over-performing on YouTube for a niche right now.

For each keyword, searches videos published in the last N days, fetches their
stats, and ranks them by views per day (a better virality signal than raw views).

Usage:
    export YOUTUBE_API_KEY=...   # free key: Google Cloud Console -> YouTube Data API v3
    python3 yt_trends.py --keywords "puff print" "oversized tee" --days 30 \
        --region IN --out ../output/my-page/2026-11/yt_trends.csv
    python3 yt_trends.py --page ../pages/my-page.yaml --days 30   # uses niche.keywords

Shorts vs long-form is guessed from duration (<= 3 min -> short).
Quota: ~101 units per keyword (search=100, videos=1); the free daily quota is 10,000.
"""
import argparse
import csv
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone

API = "https://www.googleapis.com/youtube/v3/"


def get(endpoint, params):
    url = API + endpoint + "?" + urllib.parse.urlencode(params)
    with urllib.request.urlopen(url, timeout=30) as resp:
        return json.load(resp)


def parse_duration(iso):
    """ISO-8601 duration (PT#H#M#S) -> seconds."""
    m = re.fullmatch(r"P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?", iso or "")
    if not m:
        return 0
    d, h, mi, s = (int(x or 0) for x in m.groups())
    return d * 86400 + h * 3600 + mi * 60 + s


def keywords_from_page(path):
    """Read niche.keywords from a page YAML without requiring PyYAML."""
    text = open(path, encoding="utf-8").read()
    m = re.search(r"^\s*keywords:\s*\[(.*?)\]", text, re.M | re.S)
    if not m:
        return []
    return [k.strip().strip("\"'") for k in m.group(1).split(",") if k.strip()]


def search(key, keyword, since, region, language, max_results):
    params = {
        "key": key, "part": "id", "type": "video", "q": keyword,
        "order": "viewCount", "publishedAfter": since, "maxResults": max_results,
    }
    if region:
        params["regionCode"] = region
    if language:
        params["relevanceLanguage"] = language
    return [i["id"]["videoId"] for i in get("search", params).get("items", [])]


def video_stats(key, ids):
    rows = []
    for i in range(0, len(ids), 50):
        data = get("videos", {"key": key, "part": "snippet,statistics,contentDetails",
                              "id": ",".join(ids[i:i + 50])})
        rows.extend(data.get("items", []))
    return rows


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--keywords", nargs="*", default=[])
    ap.add_argument("--page", help="page YAML; its niche.keywords are added")
    ap.add_argument("--days", type=int, default=30)
    ap.add_argument("--region", default="", help="ISO country code, e.g. IN, US")
    ap.add_argument("--language", default="", help="e.g. hi, en")
    ap.add_argument("--per-keyword", type=int, default=25)
    ap.add_argument("--top", type=int, default=40, help="rows to print")
    ap.add_argument("--out", help="CSV path")
    args = ap.parse_args()

    key = os.environ.get("YOUTUBE_API_KEY")
    if not key:
        sys.exit("Set YOUTUBE_API_KEY first (Google Cloud Console -> YouTube Data API v3).")

    keywords = list(args.keywords)
    if args.page:
        keywords += keywords_from_page(args.page)
    keywords = list(dict.fromkeys(k for k in keywords if k))
    if not keywords:
        sys.exit("No keywords: pass --keywords or a --page with niche.keywords.")

    now = datetime.now(timezone.utc)
    since = (now - timedelta(days=args.days)).strftime("%Y-%m-%dT%H:%M:%SZ")

    found = {}
    for kw in keywords:
        try:
            for vid in search(key, kw, since, args.region, args.language, args.per_keyword):
                found.setdefault(vid, kw)
        except urllib.error.HTTPError as e:
            print(f"[warn] search failed for {kw!r}: {e.code} {e.read()[:200]!r}", file=sys.stderr)

    rows = []
    for v in video_stats(key, list(found)):
        sn, st = v["snippet"], v.get("statistics", {})
        published = datetime.fromisoformat(sn["publishedAt"].replace("Z", "+00:00"))
        age_days = max((now - published).total_seconds() / 86400, 0.5)
        views = int(st.get("viewCount", 0))
        likes = int(st.get("likeCount", 0))
        comments = int(st.get("commentCount", 0))
        secs = parse_duration(v["contentDetails"].get("duration"))
        rows.append({
            "keyword": found[v["id"]],
            "format": "short" if secs <= 180 else "long",
            "title": sn["title"],
            "channel": sn["channelTitle"],
            "published": published.date().isoformat(),
            "duration_s": secs,
            "views": views,
            "views_per_day": round(views / age_days),
            "engagement_pct": round(100 * (likes + comments) / views, 2) if views else 0,
            "url": f"https://youtu.be/{v['id']}",
        })
    rows.sort(key=lambda r: r["views_per_day"], reverse=True)

    if args.out:
        os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
        with open(args.out, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=list(rows[0]) if rows else ["keyword"])
            w.writeheader()
            w.writerows(rows)
        print(f"Wrote {len(rows)} videos to {args.out}", file=sys.stderr)

    for r in rows[: args.top]:
        print(f"{r['views_per_day']:>9,}/day  {r['format']:<5} {r['views']:>11,}  "
              f"{r['title'][:70]}  [{r['channel']}]  {r['url']}")


if __name__ == "__main__":
    main()
