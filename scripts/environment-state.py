#!/usr/bin/env python3
"""Read-only Git tree comparison and verified deploy checkpoints (stdlib only)."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile

SCHEMA = 1
HISTORY = Path("storage/app/private/environment-checkpoints")
GROUPS = ("functional", "workflows", "documentation", "generated")


def git(*args):
    return subprocess.check_output(["git", *args])


def group(path):
    if path.startswith("public/build/"):
        return "generated"
    if path.startswith(".github/workflows/"):
        return "workflows"
    if path.startswith("docs/") or path == "README.md":
        return "documentation"
    return "functional"


def tree(ref):
    sha = git("rev-parse", "--verify", ref + "^{commit}").decode().strip()
    entries = {k: {} for k in GROUPS}
    for record in git("ls-tree", "-r", "-z", sha).split(b"\0"):
        if not record:
            continue
        meta, name = record.split(b"\t", 1)
        mode, kind, oid = meta.decode().split()
        path = os.fsdecode(name)
        entries[group(path)][path] = [mode, kind, oid]
    return sha, entries


def fingerprint(entries):
    # Sorted, length-delimited by JSON escaping; includes path, object type and mode.
    data = json.dumps(entries, sort_keys=True, ensure_ascii=True, separators=(",", ":"))
    return hashlib.sha256(data.encode("ascii")).hexdigest()


def snapshot(ref):
    sha, entries = tree(ref)
    hashes = {k: fingerprint(v) for k, v in entries.items()}
    return {"schema": SCHEMA, "git_sha": sha, "functional_sha256": hashes["functional"],
            "functional_version": "f1-" + hashes["functional"][:16],
            "group_sha256": hashes, "file_counts": {k: len(v) for k, v in entries.items()}}, entries


def compare(left, right):
    a, aa = snapshot(left)
    b, bb = snapshot(right)
    differences = {}
    for k in GROUPS:
        differences[k] = [
            {"path": p, "status": "added" if p not in aa[k] else
             "removed" if p not in bb[k] else "modified"}
            for p in sorted(aa[k].keys() | bb[k].keys())
            if aa[k].get(p) != bb[k].get(p)
        ]
    return {"schema": SCHEMA, "left_ref": left, "right_ref": right, "left": a,
            "right": b, "functional_equal": not differences["functional"],
            "all_tracked_equal": not any(differences.values()), "differences": differences}


def worktree_drift(entries):
    # Verify tracked bytes, symlinks and executable bit directly; don't trust mtime/index.
    algo = git("rev-parse", "--show-object-format").decode().strip()
    drift = []
    for k in GROUPS:
        if k == "generated":
            continue
        for path, (mode, kind, expected) in entries[k].items():
            try:
                p = Path(path)
                info = p.lstat()
                if kind != "blob":
                    drift.append(path)
                    continue
                if stat.S_ISLNK(info.st_mode):
                    data = os.fsencode(os.readlink(p))
                    actual_mode = "120000"
                    h = hashlib.new(algo, b"blob " + str(len(data)).encode() + b"\0")
                    h.update(data)
                elif stat.S_ISREG(info.st_mode):
                    actual_mode = "100755" if info.st_mode & stat.S_IXUSR else "100644"
                    h = hashlib.new(algo, b"blob " + str(info.st_size).encode() + b"\0")
                    with p.open("rb") as f:
                        for block in iter(lambda: f.read(1024 * 1024), b""):
                            h.update(block)
                else:
                    drift.append(path)
                    continue
                if actual_mode != mode or h.hexdigest() != expected:
                    drift.append(path)
            except OSError:
                drift.append(path)
    # Untracked, non-ignored source files must not silently count as clean.
    untracked = [os.fsdecode(x) for x in git("ls-files", "--others", "--exclude-standard", "-z").split(b"\0") if x]
    # Restrict this check to application/dependency source roots; never inspect server secrets.
    roots = ("app/", "bootstrap/", "config/", "database/", "resources/", "routes/",
             "tests/", "scripts/", "public/")
    for p in untracked:
        if p.startswith(roots) and group(p) != "generated" and not p.startswith("bootstrap/cache/"):
            drift.append(p)
    return sorted(set(drift))


def inspect():
    info, entries = snapshot("HEAD")
    info["tracked_and_unignored_source_drift"] = worktree_drift(entries)
    info["source_clean"] = not info["tracked_and_unignored_source_drift"]
    try:
        latest = json.loads((HISTORY / "latest.json").read_text())
    except (OSError, ValueError):
        latest = None
    info["last_verified_deploy"] = latest
    info["checkpoint_matches_checkout"] = bool(
        isinstance(latest, dict) and latest.get("schema") == SCHEMA
        and latest.get("git_sha") == info["git_sha"]
        and latest.get("functional_sha256") == info["functional_sha256"]
        and info["source_clean"]
    )
    return info


def atomic_write(path, data):
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent, delete=False) as f:
        tmp = Path(f.name)
        try:
            json.dump(data, f, ensure_ascii=True, indent=2)
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
            os.fchmod(f.fileno(), 0o640)
            os.replace(tmp, path)
        finally:
            tmp.unlink(missing_ok=True)


def record(environment, expected_sha, run_id):
    if not re.fullmatch(r"[0-9]+", run_id):
        raise ValueError("Invalid workflow run ID")
    info, entries = snapshot("HEAD")
    if info["git_sha"] != expected_sha:
        raise RuntimeError("Checkout does not match the verified deployment SHA")
    drift = worktree_drift(entries)
    if drift:
        raise RuntimeError("Unexpected source drift: " + json.dumps(drift))
    # Run only after existing deployment health gates have succeeded.
    info.update(environment=environment, verified_at=dt.datetime.now(dt.timezone.utc).isoformat(),
                workflow_run_id=run_id, source_clean=True)
    HISTORY.mkdir(parents=True, exist_ok=True)
    atomic_write(HISTORY / (run_id + "-" + expected_sha + ".json"), info)
    atomic_write(HISTORY / "latest.json", info)
    return info


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    c = commands.add_parser("compare")
    c.add_argument("left")
    c.add_argument("right")
    commands.add_parser("inspect")
    r = commands.add_parser("record")
    r.add_argument("environment", choices=("production", "development"))
    r.add_argument("expected_sha")
    r.add_argument("run_id")
    args = parser.parse_args()
    if args.command == "compare":
        result = compare(args.left, args.right)
    elif args.command == "inspect":
        result = inspect()
    else:
        result = record(args.environment, args.expected_sha, args.run_id)
    print(json.dumps(result, ensure_ascii=True, indent=2))


if __name__ == "__main__":
    main()
