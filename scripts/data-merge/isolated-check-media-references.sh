#!/usr/bin/env bash
# Read-only audit: restored final candidate media metadata vs private file union.
set -euo pipefail
umask 077
[[ $# -eq 3 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
RUN="$1"; CANDIDATE_RUN="$2"; CHECKER="$3"
[[ "$CHECKER" == "/home/deploy/mskba-data-merge-tools/$RUN/scripts/data-merge/check-imported-media.py" ]] || exit 2
BASE=/home/deploy/mskba-data-merge-candidates
SOURCE="$BASE/$CANDIDATE_RUN-finalized/finalized-candidate.pgdump"
UNION="$BASE/44032038961-media-union/manifest-private.json"
DEST="$BASE/$RUN-media-reference-audit"
DB="mskba-media-references-$RUN"
test -s "$SOURCE"
test -r "$UNION"
test -r "$CHECKER"
test ! -e "$DEST"
( cd "$BASE/$CANDIDATE_RUN-finalized" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST"
chmod 700 "$DEST"
cleanup(){ docker stop "$DB" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker run --rm -d --network none --name "$DB" \
 -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$(openssl rand -hex 32)" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
 if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' && \
 docker exec "$DB" pg_isready -q -U postgres -d candidate; then ready=true;break;fi
 sleep 1
done
[[ "$ready" == true ]] || exit 3
if ! docker exec -i "$DB" pg_restore -U postgres -d candidate \
 --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/restore.log" 2>&1; then
 echo 'MEDIA_REF_BLOCKED restore';exit 4
fi
docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate \
 -c "SELECT json_build_object('disk',disk,'path',path,'original_disk',original_disk,'original_path',original_path)::text FROM media WHERE deleted_at IS NULL ORDER BY id" \
 > "$DEST/media-paths-private.jsonl"
chmod 600 "$DEST"/*
python3 "$CHECKER" "$DEST/media-paths-private.jsonl" "$UNION"
echo 'LIVE_PROD_AND_DEV_MEDIA_UNCHANGED=true'
