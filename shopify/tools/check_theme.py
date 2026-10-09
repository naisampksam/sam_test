#!/usr/bin/env python3
"""Check the theme against Shopify upload rules that `shopify theme check` misses.

Shopify silently drops a section file on zip upload if its schema breaks one of
these rules, and then drops every JSON template that uses that section (the page
shows 404). Run before building the zip:

    python3 shopify/tools/check_theme.py
"""
import glob
import json
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "theme")


def schema(path):
    text = open(path, encoding="utf-8").read()
    if "{% schema %}" not in text:
        return None
    return json.loads(text[text.index("{% schema %}") + 12:text.index("{% endschema %}")])


def check_setting_defs(where, settings, issues):
    ids = [s["id"] for s in settings if "id" in s]
    issues += [f"{where}: duplicate setting id {d}" for d in sorted({i for i in ids if ids.count(i) > 1})]
    for s in settings:
        t, sid = s.get("type"), s.get("id", "")
        if t == "range":
            steps = (s["max"] - s["min"]) / s.get("step", 1) + 1
            if steps < 3 or steps > 101:
                issues.append(f"{where}: range {sid} must have 3-101 steps (has {steps:g})")
            d = s.get("default")
            if d is not None and (d < s["min"] or d > s["max"] or ((d - s["min"]) / s.get("step", 1)) % 1):
                issues.append(f"{where}: range {sid} default not on a step")
        if t == "select" and "default" in s and s["default"] not in [o["value"] for o in s["options"]]:
            issues.append(f"{where}: select {sid} default is not an option")
        if t == "url" and "default" in s and s["default"] not in ("/collections", "/collections/all"):
            issues.append(f"{where}: url {sid} default must be /collections or /collections/all")
        if t == "richtext" and s.get("default") and not re.match(r"^<(p|ul|ol|h[1-6])", s["default"]):
            issues.append(f"{where}: richtext {sid} default must start with <p>")


def check_values(where, values, defs, issues):
    by_id = {d["id"]: d for d in defs if "id" in d}
    for k, v in values.items():
        d = by_id.get(k)
        if not d:
            issues.append(f"{where}: unknown setting {k}")
            continue
        t = d["type"]
        if t == "range" and (not isinstance(v, (int, float)) or v < d["min"] or v > d["max"]):
            issues.append(f"{where}: {k}={v} outside range")
        if t == "select" and v not in [o["value"] for o in d["options"]]:
            issues.append(f"{where}: {k}={v!r} is not an option")
        if t in ("url", "collection", "product", "page", "blog") and v:
            issues.append(f"{where}: {k} stores a {t} reference; use the *_path / *_handle text setting "
                          "instead (Shopify rejects templates whose references don't exist yet)")


def main():
    issues, schemas = [], {}
    for path in sorted(glob.glob(os.path.join(ROOT, "sections", "*.liquid"))):
        name = os.path.basename(path)[:-7]
        sch = schema(path)
        if sch is None:
            continue
        schemas[name] = sch
        where = f"sections/{name}.liquid"
        if len(sch.get("name", "")) > 25:
            issues.append(f"{where}: section name longer than 25 characters")
        check_setting_defs(where, sch.get("settings", []), issues)
        for b in sch.get("blocks", []):
            if b["type"].startswith("@"):
                continue
            if len(b.get("name", "")) > 25:
                issues.append(f"{where}: block name {b['name']!r} longer than 25 characters")
            check_setting_defs(f"{where} block {b['type']}", b.get("settings", []), issues)
    for path in sorted(glob.glob(os.path.join(ROOT, "config", "settings_schema.json"))):
        for group in json.load(open(path, encoding="utf-8")):
            check_setting_defs(f"settings_schema/{group.get('name')}", group.get("settings", []), issues)
    for path in sorted(glob.glob(os.path.join(ROOT, "templates", "*.json")) + glob.glob(os.path.join(ROOT, "sections", "*.json"))):
        rel = os.path.relpath(path, ROOT)
        data = json.load(open(path, encoding="utf-8"))
        for sid, sec in data.get("sections", {}).items():
            sch = schemas.get(sec["type"])
            if sch is None:
                issues.append(f"{rel}:{sid}: section {sec['type']} does not exist")
                continue
            check_values(f"{rel}:{sid}", sec.get("settings", {}), sch.get("settings", []), issues)
            block_defs = {b["type"]: b for b in sch.get("blocks", [])}
            for bid, blk in sec.get("blocks", {}).items():
                if blk["type"] not in block_defs:
                    issues.append(f"{rel}:{sid}.{bid}: block type {blk['type']} not in schema")
                    continue
                check_values(f"{rel}:{sid}.{bid}", blk.get("settings", {}), block_defs[blk["type"]].get("settings", []), issues)
    print("\n".join(issues) if issues else "OK: no upload-blocking problems found")
    sys.exit(1 if issues else 0)


if __name__ == "__main__":
    main()
