#!/usr/bin/env python3
"""Compare aggregate-only MSKBA DB inventories. Never joins users or writes a DB."""

import argparse
import json
from pathlib import Path


def load(path: str, expected: str) -> dict:
    report = json.loads(Path(path).read_text(encoding="utf-8"))
    if report.get("report_version") != 1 or report.get("db_engine") != "pgsql":
        raise ValueError(f"{path}: unsupported inventory format")
    if report.get("source") != expected:
        raise ValueError(f"{path}: expected source={expected}, got {report.get('source')!r}")
    required = ("tables", "row_counts", "migrations", "columns_digest_sha256")
    if any(key not in report for key in required):
        raise ValueError(f"{path}: incomplete inventory")
    return report


def compare(prod: dict, dev: dict) -> dict:
    p_tables, d_tables = set(prod["tables"]), set(dev["tables"])
    p_migrations, d_migrations = set(prod["migrations"]), set(dev["migrations"])
    shared_counts = {
        table: {"prod": prod["row_counts"][table], "dev": dev["row_counts"][table]}
        for table in sorted(set(prod["row_counts"]) & set(dev["row_counts"]))
    }
    return {
        "comparison_version": 1,
        "prod_generated_at": prod["generated_at_utc"],
        "dev_generated_at": dev["generated_at_utc"],
        "prod_table_count": prod["table_count"],
        "dev_table_count": dev["table_count"],
        "prod_migration_count": prod["migration_count"],
        "dev_migration_count": dev["migration_count"],
        "prod_only_tables": sorted(p_tables - d_tables),
        "dev_only_tables": sorted(d_tables - p_tables),
        "prod_migrations_missing_from_dev": sorted(p_migrations - d_migrations),
        "dev_migrations_missing_from_prod": sorted(d_migrations - p_migrations),
        "shared_business_counts": shared_counts,
        "schema_digests_identical": (
            prod["columns_digest_sha256"] == dev["columns_digest_sha256"]
        ),
        "warnings": [
            "This is an aggregate inventory, not a duplicate user/venue match.",
            "Different numeric IDs across databases MUST NOT be treated as the same entity.",
            "Check source media, encryption keys, external identities and polymorphic links separately.",
            "No migration, import, backup, or production release is performed by this script.",
        ],
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("prod", help="JSON produced with --label=prod")
    parser.add_argument("dev", help="JSON produced with --label=dev")
    args = parser.parse_args()
    result = compare(load(args.prod, "prod"), load(args.dev, "dev"))
    print(json.dumps(result, ensure_ascii=False, indent=2))
    if result["prod_migrations_missing_from_dev"]:
        raise SystemExit("BLOCKED: Production migrations absent from dev codebase")


if __name__ == "__main__":
    main()
