#!/usr/bin/env python3
"""
Read-only production URL preservation gate for the MSKBA theme release.

Before the release (while the original production code and DB serve requests):
  python3 scripts/seo/production_url_gate.py capture \
    --origin https://mskba.ru --output pre-release.csv

After deploying the new code + running approved migrations ON THE SAME DB:
  python3 scripts/seo/production_url_gate.py check \
    --origin https://mskba.ru --baseline pre-release.csv --output post-release.csv

Optional --extra-paths file includes historical indexed URLs not in sitemap.
This tool never changes the database or fetches dev data. It does not make
claims about private user data or guarantee exact full article-body parity.
"""

import argparse
import csv
import json
import re
import sys
import time
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path

from compare_public_urls import get, normalize_path, parse_html, sitemap_paths, target_url


TRANSIENT_HTTP_STATUSES = {0, 429, 502, 503, 504}


def fetch_with_retry(url, timeout, retries=2):
    """Retry only network/transient failures; never obscure persistent HTTP errors."""
    for attempt in range(retries + 1):
        response = get(url, timeout)
        if response["status"] not in TRANSIENT_HTTP_STATUSES or attempt == retries:
            return response
        time.sleep(0.5 * (attempt + 1))


FIELDS = (
    "path", "source_status", "source_title", "source_description",
    "source_h1", "source_canonical", "source_error",
)


def capture_one(origin, path, timeout):
    response = fetch_with_retry(target_url(origin, path), timeout)
    html = parse_html(response)
    return {
        "path": path,
        "source_status": response["status"],
        "source_title": html["title"],
        "source_description": html["description"],
        "source_h1": html["h1"],
        "source_canonical": html["canonical"],
        "source_error": html["error"],
    }


def inspect_one(origin, row, sitemap, timeout):
    path = row["path"]
    response = get(target_url(origin, path), timeout)
    html = parse_html(response)
    issues = []

    if response["status"] != 200:
        issues.append("BLOCKER_HTTP_STATUS")
    elif html.get("is_placeholder"):
        issues.append("BLOCKER_PLACEHOLDER_200")

    if normalize_path(response["final_url"]) != path:
        issues.append("REVIEW_REDIRECT")

    if response["status"] == 200:
        if not html["canonical"]:
            issues.append("BLOCKER_MISSING_CANONICAL")
        elif row.get("source_canonical") and normalize_path(html["canonical"]) != normalize_path(row["source_canonical"]):
            issues.append("BLOCKER_CANONICAL_CHANGED")
        if "noindex" in (html["robots"] + " " + html["x_robots"]).lower():
            issues.append("BLOCKER_NOINDEX")
        if html["lang"].lower() != "ru":
            issues.append("REVIEW_HTML_LANGUAGE")
        if not html["description"]:
            issues.append("REVIEW_MISSING_DESCRIPTION")
        if not html["h1"]:
            issues.append("REVIEW_MISSING_H1")
        if html["title"] != row["source_title"]:
            issues.append("REVIEW_TITLE_CHANGED")
        if html["description"] != row["source_description"]:
            issues.append("REVIEW_DESCRIPTION_CHANGED")
        if html["h1"] != row["source_h1"]:
            # Same ID/alias must still identify the same entity.
            if re.match(r"^/(venues|events|teams|tournaments|sections)/[0-9]+(?:-|/)", path) and row["source_h1"]:
                issues.append("BLOCKER_ENTITY_H1_CHANGED")
            else:
                issues.append("REVIEW_H1_CHANGED")
        if path not in sitemap:
            issues.append("REVIEW_MISSING_FROM_SITEMAP")

    return {
        **row,
        "target_status": response["status"],
        "target_final_url": response["final_url"],
        "target_title": html["title"],
        "target_description": html["description"],
        "target_h1": html["h1"],
        "target_canonical": html["canonical"],
        "target_robots": html["robots"],
        "target_x_robots": html["x_robots"],
        "target_error": html["error"],
        "issues": ";".join(issues),
        "result": "BLOCKER" if any(x.startswith("BLOCKER_") for x in issues)
            else ("REVIEW" if issues else "OK"),
    }


def parallel(func, items, workers):
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(func, item) for item in items]
        return sorted([future.result() for future in as_completed(futures)], key=lambda row: row["path"])


def write_csv(path, rows, fieldnames):
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(stream, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("mode", choices=["capture", "check"])
    p.add_argument("--origin", required=True, help="Production origin; never dev")
    p.add_argument("--output", required=True, type=Path)
    p.add_argument("--baseline", type=Path, help="CSV created before deploying the new production code")
    p.add_argument("--extra-paths", type=Path, help="Optional historical paths from logs/Search Console")
    p.add_argument("--workers", type=int, default=3)
    p.add_argument("--timeout", type=float, default=15)
    args = p.parse_args()

    if args.workers < 1 or args.workers > 8 or args.timeout <= 0:
        p.error("workers must be in [1..8] and timeout must be positive")
    if args.mode == "check" and not args.baseline:
        p.error("check mode requires --baseline")
    if args.origin.rstrip("/").lower() not in ("https://mskba.ru", "https://www.mskba.ru"):
        p.error("Only the actual production host is allowed; do not compare independent dev databases")

    if args.mode == "capture":
        paths = sitemap_paths(target_url(args.origin, "/sitemap.xml"), args.timeout)
        if args.extra_paths:
            paths |= {
                normalize_path(line.strip())
                for line in args.extra_paths.read_text(encoding="utf-8").splitlines()
                if line.strip() and not line.lstrip().startswith("#")
            }
        rows = parallel(lambda path: capture_one(args.origin, path, args.timeout), sorted(paths), args.workers)
        write_csv(args.output, rows, FIELDS)
        summary = {"mode": "capture", "paths": len(rows), "statuses": dict(Counter(x["source_status"] for x in rows))}
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        return 2 if any(row["source_status"] != 200 for row in rows) else 0

    with args.baseline.open(newline="", encoding="utf-8") as source:
        baseline = list(csv.DictReader(source))
    if not baseline:
        p.error("Baseline CSV is empty")
    if not all(set(FIELDS).issubset(row) for row in baseline):
        p.error("Baseline CSV is missing required columns")

    sitemap = sitemap_paths(target_url(args.origin, "/sitemap.xml"), args.timeout)
    # Only URLs confirmed as 200 on the original production site are release
    # obligations. Retain other rows in baseline for manual review.
    relevant = [row for row in baseline if row["source_status"] == "200"]
    rows = parallel(lambda row: inspect_one(args.origin, row, sitemap, args.timeout), relevant, args.workers)
    columns = list(FIELDS) + [
        "target_status", "target_final_url", "target_title", "target_description",
        "target_h1", "target_canonical", "target_robots", "target_x_robots",
        "target_error", "issues", "result",
    ]
    write_csv(args.output, rows, columns)
    counts = dict(Counter(row["result"] for row in rows))
    print(json.dumps({
        "mode": "check", "checked": len(rows), "outcomes": counts,
        "missing_from_post_release_sitemap": sorted(set(row["path"] for row in rows) - sitemap),
        "report": str(args.output),
    }, ensure_ascii=False, indent=2))
    return 2 if counts.get("BLOCKER", 0) else 0


if __name__ == "__main__":
    sys.exit(main())
