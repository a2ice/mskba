#!/usr/bin/env python3
"""Task 325: verify every active Laravel media path against private union tar.

The DB's path values and missing filenames remain on the server. Only aggregate
counts are emitted. Does not extract or modify any source media.
"""
import json
import pathlib
import sys

def target_path(disk, raw):
    if not isinstance(raw, str) or not raw.strip():
        return None
    raw = raw.strip().replace("\\", "/")
    if raw.startswith("http://") or raw.startswith("https://"):
        return None
    path = raw.lstrip("/")
    if path.startswith("storage/app/"):
        full = path
    else:
        if disk == "public":
            if path.startswith("storage/"):
                path = path[8:]
            full = "storage/app/public/" + path
        elif disk == "local":
            full = "storage/app/private/" + path
        else:
            return None
    p = pathlib.PurePosixPath(full)
    if ".." in p.parts or "." in p.parts or not full.startswith("storage/app/"):
        return None
    return full

def main():
    if len(sys.argv) != 3:
        raise SystemExit("Usage: check-imported-media.py <db-private-jsonl> <union-private-manifest>")
    rows = pathlib.Path(sys.argv[1])
    manifest = json.loads(pathlib.Path(sys.argv[2]).read_text(encoding="utf-8"))
    active = 0
    references = 0
    missing = 0
    invalid = 0
    originals = 0
    for raw in rows.read_text(encoding="utf-8").splitlines():
        if not raw.strip():
            continue
        row = json.loads(raw)
        active += 1
        for disk_key, path_key in (("disk", "path"), ("original_disk", "original_path")):
            if row.get(path_key) is None:
                continue
            disk = row.get(disk_key) or row.get("disk")
            path = target_path(disk, row.get(path_key))
            references += 1
            if path_key == "original_path":
                originals += 1
            if path is None:
                invalid += 1
            elif path not in manifest:
                missing += 1
    print("MEDIA_REFERENCES_AUDITED=true")
    print(f"active_media_rows={active}")
    print(f"storage_file_references={references}")
    print(f"original_file_references={originals}")
    print(f"missing_media_files={missing}")
    print(f"invalid_or_external_media_paths={invalid}")
    print("NO_PATH_VALUES_UPLOADED=true")
    return 0 if missing == invalid == 0 else 2

if __name__ == "__main__":
    raise SystemExit(main())
