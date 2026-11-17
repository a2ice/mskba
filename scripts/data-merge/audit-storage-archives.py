#!/usr/bin/env python3
"""Task 325. Read-only media safety census over verified protected backups.

Only count/name-free aggregates leave this process; archive member names,
content and digest paths never enter GitHub logs.
"""
import hashlib
import pathlib
import tarfile
import sys

BASE = pathlib.Path("/home/deploy/mskba-data-merge-backups/43974694391")
ARCHIVES = {
    "prod": BASE / "prod" / "storage-app.tar.gz",
    "dev": BASE / "dev" / "storage-app.tar.gz",
}

def scan(path):
    result = {}
    malformed = 0
    symlinks = 0
    total_bytes = 0
    with tarfile.open(path, "r:gz") as archive:
        for item in archive:
            name = item.name.rstrip("/")
            parts = pathlib.PurePosixPath(name).parts
            if not name.startswith("storage/app/") and name != "storage/app":
                malformed += 1
                continue
            if any(p in (".", "..") for p in parts) or item.name.startswith("/"):
                malformed += 1
                continue
            if item.issym() or item.islnk():
                symlinks += 1
                continue
            if not item.isfile():
                continue
            if name in result:
                malformed += 1
                continue
            stream = archive.extractfile(item)
            if stream is None:
                malformed += 1
                continue
            h = hashlib.sha256()
            n = 0
            while True:
                chunk = stream.read(1024 * 1024)
                if not chunk:
                    break
                n += len(chunk)
                h.update(chunk)
            total_bytes += n
            result[name] = (n, h.digest())
    return result, malformed, symlinks, total_bytes

def main():
    if len(sys.argv) != 1:
        raise SystemExit("No arguments supported")
    a, ba, sa, aa = scan(ARCHIVES["prod"])
    b, bb, sb, ab = scan(ARCHIVES["dev"])
    overlap = a.keys() & b.keys()
    identical = sum(a[n] == b[n] for n in overlap)
    different = len(overlap) - identical
    print("MEDIA_AUDIT_COMPLETE=true")
    for tag, data, blocked, links, bytes_count in (
        ("prod", a, ba, sa, aa), ("dev", b, bb, sb, ab)
    ):
        print(f"{tag}_regular_files={len(data)}")
        print(f"{tag}_member_bytes={bytes_count}")
        print(f"{tag}_unsafe_or_duplicate_members={blocked}")
        print(f"{tag}_links={links}")
    print(f"same_path_files={len(overlap)}")
    print(f"same_path_same_sha256={identical}")
    print(f"same_path_different_sha256={different}")
    print(f"dev_only_file_paths={len(b.keys()-a.keys())}")
    print(f"prod_only_file_paths={len(a.keys()-b.keys())}")
    print("ALL_MEDIA_CONTENT_SERVER_PRIVATE=true")
    # A conflicting file is a blocker, not safe to silently overwrite.
    return 0 if (ba == bb == 0 and different == 0) else 2

if __name__ == "__main__":
    raise SystemExit(main())
