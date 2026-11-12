#!/usr/bin/env python3
"""
Read-only production-to-target URL/SEO parity audit.

Example:
  python3 scripts/seo/compare_public_urls.py \
    --source https://mskba.ru --target https://dev.mskba.ru \
    --output seo-parity.csv --json-output seo-parity.json

Use --strict ONLY after the target is running against a restored production
database snapshot. Independent development records are expected to have other
identifiers, slugs and text and must not fail the release preflight.

The source sitemap is only a starting point: pass --extra-paths with one
relative path per line from Yandex Webmaster, GSC, logs or an archival crawl.
Never use dev database IDs as production mapping evidence.
"""

import argparse
import csv
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, as_completed
from html.parser import HTMLParser
from pathlib import Path


USER_AGENT = "MSKBA-SEO-Migration-Audit/1.0 (+https://mskba.ru)"
PLACEHOLDERS = (
    "раздел в разработке",
    "новая тема находится в разработке",
    "эта возможность скоро появится",
)


class HeadAndHeadingParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.meta = {}
        self.title = ""
        self.h1 = ""
        self.canonical = ""
        self.lang = ""
        self._capture = None
        self._in_main = 0
        self.main_text = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "main":
            self._in_main += 1
        if tag == "html":
            self.lang = attrs.get("lang", "")
        elif tag == "title":
            self._capture = "title"
        elif tag == "h1" and not self.h1:
            self._capture = "h1"
        elif tag == "meta":
            key = attrs.get("name") or attrs.get("property")
            if key:
                self.meta[key.lower()] = attrs.get("content", "")
        elif tag == "link" and attrs.get("rel", "").lower() == "canonical":
            self.canonical = attrs.get("href", "")

    def handle_data(self, data):
        if self._in_main:
            self.main_text.append(data)
        if self._capture == "title":
            self.title += data
        elif self._capture == "h1":
            self.h1 += data

    def handle_endtag(self, tag):
        if tag == "main":
            self._in_main = max(0, self._in_main - 1)
        if tag in ("title", "h1"):
            self._capture = None


def normalize_path(url):
    parts = urllib.parse.urlsplit(url)
    path = parts.path or "/"
    # Query strings matter for historic pagination and filters.
    return path + (("?" + parts.query) if parts.query else "")


def target_url(base, path):
    return base.rstrip("/") + (path if path.startswith("/") else "/" + path)


def get(url, timeout):
    req = urllib.request.Request(
        url,
        headers={"User-Agent": USER_AGENT, "Accept": "text/html,application/xml;q=0.9,*/*;q=0.1"},
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            return {
                "status": response.status,
                "final_url": response.geturl(),
                "headers": dict(response.headers.items()),
                "body": response.read(2_000_000),
            }
    except urllib.error.HTTPError as exc:
        return {
            "status": exc.code,
            "final_url": exc.geturl(),
            "headers": dict(exc.headers.items()),
            "body": exc.read(2_000_000),
        }
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        return {"status": 0, "final_url": url, "headers": {}, "body": b"", "error": str(exc)}


def header(headers, name):
    return next((v for k, v in headers.items() if k.lower() == name.lower()), "")


def parse_html(response):
    result = {
        "title": "", "description": "", "keywords": "", "h1": "",
        "canonical": "", "lang": "", "robots": "", "x_robots": "",
        "og_title": "", "og_description": "", "error": response.get("error", ""),
    }
    result["x_robots"] = header(response["headers"], "X-Robots-Tag")
    if not response["body"]:
        return result
    encoding = "utf-8"
    header_charset = re.search(r"charset=([\w-]+)", header(response["headers"], "Content-Type"), re.I)
    if header_charset:
        encoding = header_charset.group(1)
    body = response["body"].decode(encoding, errors="replace")
    parser = HeadAndHeadingParser()
    parser.feed(body)
    result.update({
        "title": parser.title.strip(),
        "description": parser.meta.get("description", ""),
        "keywords": parser.meta.get("keywords", ""),
        "h1": parser.h1.strip(),
        "canonical": parser.canonical,
        "lang": parser.lang,
        "robots": parser.meta.get("robots", ""),
        "og_title": parser.meta.get("og:title", ""),
        "og_description": parser.meta.get("og:description", ""),
        # A shared support popup can contain a future-feature notice within
        # <main>; only the actual page heading/title proves a placeholder.
        "is_placeholder": (
            "раздел в разработке" in parser.title.lower()
            or parser.h1.strip().lower() == "mskba app theme"
        ),
    })
    return result


def sitemap_paths(url, timeout):
    response = get(url, timeout)
    if response["status"] != 200:
        raise RuntimeError(f"Cannot load sitemap {url}: HTTP {response['status']} {response.get('error', '')}")
    try:
        root = ET.fromstring(response["body"])
    except ET.ParseError as exc:
        raise RuntimeError(f"Malformed sitemap XML {url}: {exc}") from exc
    # Support XML sitemap indexes too, without crawling any unrelated URL.
    if root.tag.rsplit("}", 1)[-1] == "sitemapindex":
        all_paths = set()
        for node in root.iter():
            if node.tag.rsplit("}", 1)[-1] == "loc" and node.text:
                nested = node.text.strip()
                all_paths.update(sitemap_paths(nested, timeout))
        return all_paths
    return {
        normalize_path(node.text.strip())
        for node in root.iter()
        if node.tag.rsplit("}", 1)[-1] == "loc" and node.text
    }


def audit_one(path, source, target, target_sitemap, timeout, strict):
    src = get(target_url(source, path), timeout)
    dst = get(target_url(target, path), timeout)
    a = parse_html(src)
    b = parse_html(dst)
    issues = []

    if src["status"] == 200 and dst["status"] in (0, 404, 410):
        issues.append("BLOCKER_MISSING_URL")
    elif dst["status"] >= 500 or dst["status"] == 0:
        issues.append("BLOCKER_TARGET_ERROR")
    elif dst["status"] == 200 and b.get("is_placeholder"):
        issues.append("BLOCKER_SOFT_404_PLACEHOLDER")
    elif src["status"] == 200 and dst["status"] != 200:
        issues.append("REVIEW_REDIRECT_OR_STATUS")

    if dst["status"] == 200:
        if not b["canonical"]:
            issues.append("BLOCKER_MISSING_CANONICAL")
        elif normalize_path(b["canonical"]) != path:
            issues.append("REVIEW_CANONICAL_PATH")
        if not b["description"]:
            issues.append("REVIEW_MISSING_DESCRIPTION")
        if not b["h1"]:
            issues.append("REVIEW_MISSING_H1")
        if b["lang"] != "ru":
            issues.append("REVIEW_LANGUAGE")
        if "noindex" not in (b["robots"] + " " + b["x_robots"]).lower():
            issues.append("REVIEW_STAGING_INDEXABLE")
        if a["title"] != b["title"]:
            issues.append("REVIEW_TITLE_DIFF")
        if a["description"] != b["description"]:
            issues.append("REVIEW_DESCRIPTION_DIFF")
        if a["h1"] != b["h1"]:
            issues.append("REVIEW_H1_DIFF")
            # Same numeric ID/slug URL, different published entity: this is
            # more dangerous than a missing SEO description. The staging DB
            # cannot be used as a mapping source for production IDs.
            if re.match(r"^/(venues|events|teams|tournaments|sections)/[0-9]+[-/]", path) and a["h1"] and b["h1"]:
                issues.append("BLOCKER_ENTITY_CONTENT_MISMATCH")
        if path not in target_sitemap and src["status"] == 200:
            issues.append("REVIEW_TARGET_SITEMAP_OMISSION")

    if not strict:
        # Different databases make ID/alias/content differences expected.
        # Keep the signal in the CSV without declaring a release failure.
        issues = [issue.replace("BLOCKER_", "CANDIDATE_") for issue in issues]

    return {
        "path": path,
        "source_status": src["status"],
        "target_status": dst["status"],
        "target_final_url": dst["final_url"],
        "source_title": a["title"], "target_title": b["title"],
        "source_description": a["description"], "target_description": b["description"],
        "source_h1": a["h1"], "target_h1": b["h1"],
        "source_canonical": a["canonical"], "target_canonical": b["canonical"],
        "target_robots": b["robots"], "target_x_robots": b["x_robots"],
        "source_error": a["error"], "target_error": b["error"],
        "issues": ";".join(issues),
        "result": "BLOCKER" if any(x.startswith("BLOCKER") for x in issues)
                  else ("REVIEW" if issues else "OK"),
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--source", required=True, help="Current production origin, e.g. https://mskba.ru")
    p.add_argument("--target", required=True, help="New deployment/staging origin")
    p.add_argument("--source-sitemap", help="Override source sitemap XML URL")
    p.add_argument("--target-sitemap", help="Override target sitemap XML URL")
    p.add_argument("--extra-paths", type=Path, help="Text file of additional paths from search tools/logs")
    p.add_argument("--output", type=Path, default=Path("seo-parity.csv"))
    p.add_argument("--json-output", type=Path)
    p.add_argument("--strict", action="store_true", help="Only for a target restored from a production DB snapshot")
    p.add_argument("--workers", type=int, default=3)
    p.add_argument("--timeout", type=float, default=12.0)
    args = p.parse_args()

    if args.workers < 1 or args.workers > 8:
        p.error("--workers must be in [1..8] to avoid overloading production")
    source_paths = sitemap_paths(args.source_sitemap or target_url(args.source, "/sitemap.xml"), args.timeout)
    target_paths = sitemap_paths(args.target_sitemap or target_url(args.target, "/sitemap.xml"), args.timeout)
    extra = set()
    if args.extra_paths:
        extra = {
            normalize_path(line.strip())
            for line in args.extra_paths.read_text(encoding="utf-8").splitlines()
            if line.strip() and not line.lstrip().startswith("#")
        }
    paths = sorted(source_paths | extra)
    results = []
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [
            pool.submit(audit_one, path, args.source, args.target, target_paths, args.timeout, args.strict)
            for path in paths
        ]
        for future in as_completed(futures):
            results.append(future.result())
    results.sort(key=lambda row: row["path"])

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as f:
        writer = csv.DictWriter(f, fieldnames=list(results[0]) if results else ["path", "result"])
        writer.writeheader()
        writer.writerows(results)
    summary = {
        "source_sitemap_count": len(source_paths),
        "target_sitemap_count": len(target_paths),
        "same_paths": len(source_paths & target_paths),
        "missing_from_target_sitemap": sorted(source_paths - target_paths),
        "only_target_sitemap": sorted(target_paths - source_paths),
        "extra_checked_paths": len(extra),
        "mode": "production_copy_gate" if args.strict else "independent_dev_baseline",
        "outcomes": dict(Counter(x["result"] for x in results)),
        "csv": str(args.output),
    }
    if args.json_output:
        args.json_output.parent.mkdir(parents=True, exist_ok=True)
        args.json_output.write_text(
            json.dumps({"summary": summary, "pages": results}, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 2 if args.strict and summary["outcomes"].get("BLOCKER", 0) else 0


if __name__ == "__main__":
    sys.exit(main())
