#!/usr/bin/env python3
"""Task 325: construct a private lossless union of prod/dev storage/app backups.

Runs on server; no extraction and no writes into live web roots.
Same-path/different-content fails closed. All filenames remain private.
"""
from __future__ import annotations
import hashlib
import json
import os
import pathlib
import tarfile
import sys

SRC = pathlib.Path("/home/deploy/mskba-data-merge-backups/43974694391")
ROOT = pathlib.Path("/home/deploy/mskba-data-merge-candidates")

def digest(stream):
    h = hashlib.sha256()
    while True:
        chunk = stream.read(1024 * 1024)
        if not chunk:
            break
        h.update(chunk)
    return h.hexdigest()

def entries(path):
    with tarfile.open(path, "r:gz") as tf:
        for m in tf:
            name = m.name.rstrip("/")
            parts = pathlib.PurePosixPath(name).parts
            if not (name == "storage/app" or name.startswith("storage/app/")):
                raise RuntimeError("Unexpected archive prefix")
            if m.name.startswith("/") or ".." in parts or not name:
                raise RuntimeError("Unsafe tar path")
            if m.issym() or m.islnk():
                raise RuntimeError("Linked archive members disallowed")
            if m.isfile():
                f = tf.extractfile(m)
                if f is None:
                    raise RuntimeError("Unreadable archive member")
                yield name, m.size, digest(f)

def main():
    if len(sys.argv) != 2 or not sys.argv[1].isdigit():
        raise SystemExit("Usage: union-storage.py <run-id>")
    dest = ROOT / (sys.argv[1] + "-media-union")
    if dest.exists():
        raise RuntimeError("Refusing to overwrite prior union")
    os.umask(0o077)
    dest.mkdir(mode=0o700)
    sources = {
        "prod": SRC / "prod" / "storage-app.tar.gz",
        "dev": SRC / "dev" / "storage-app.tar.gz",
    }
    seen = {}
    for scope, path in sources.items():
        for name, size, sha in entries(path):
            if name in seen:
                if seen[name] != (size,sha):
                    raise RuntimeError("MEDIA_UNION_BLOCKED filename collision")
            else:
                seen[name] = (size,sha)
    files_written = set()
    archive_part = dest / "storage-app-combined.tar.gz.part"
    with tarfile.open(archive_part, "w:gz") as out:
        for scope,path in sources.items():
            with tarfile.open(path, "r:gz") as source:
                for item in source:
                    name = item.name.rstrip("/")
                    if not item.isfile() or name in files_written:
                        continue
                    stream=source.extractfile(item)
                    if stream is None:
                        raise RuntimeError("Missing source file stream")
                    info=tarfile.TarInfo(name=name)
                    info.size=item.size
                    info.mode=item.mode & 0o664
                    info.mtime=item.mtime
                    info.uid=0
                    info.gid=0
                    out.addfile(info, stream)
                    files_written.add(name)
    if files_written != set(seen):
        raise RuntimeError("Union did not contain every expected file")
    archive = dest / "storage-app-combined.tar.gz"
    archive_part.rename(archive)
    archive.chmod(0o600)
    with tarfile.open(archive, "r:gz") as tf:
        count=sum(item.isfile() for item in tf)
    if count != len(seen):
        raise RuntimeError("Union archive integrity mismatch")
    sha = hashlib.sha256()
    with archive.open("rb") as stream:
        while chunk := stream.read(1024 * 1024):
            sha.update(chunk)
    (dest / "SHA256SUMS").write_text(
        sha.hexdigest() + "  storage-app-combined.tar.gz\n",
        encoding="ascii")
    (dest / "SHA256SUMS").chmod(0o600)
    (dest / "manifest-private.json").write_text(
        json.dumps({k:{"size":n,"sha256":h} for k,(n,h) in seen.items()},
                   ensure_ascii=False,sort_keys=True), encoding="utf-8")
    (dest / "manifest-private.json").chmod(0o600)
    print("MEDIA_UNION_PASS=true")
    print("unique_file_paths="+str(count))
    print("union_archive_bytes="+str(archive.stat().st_size))
    print("media_bundle_server_private="+str(dest))
    print("LIVE_PROD_AND_DEV_MEDIA_UNCHANGED=true")

if __name__ == "__main__":
    main()
