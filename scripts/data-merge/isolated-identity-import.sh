#!/usr/bin/env bash
# Task 325: offline identity import into restored candidate; never the live DB.
set -euo pipefail
umask 077
[[ $# -eq 2 && "$1" =~ ^[0-9]+$ ]] || exit 2
RUN_ID="$1"
STAGED_PHP="$2"
[[ "$STAGED_PHP" == "/home/deploy/mskba-data-merge-tools/$RUN_ID/scripts/data-merge/import-dev-identities.php" ]] || exit 2
test -r "$STAGED_PHP"

ROOT=/home/deploy/mskba-data-merge-candidates
DEST="$ROOT/$RUN_ID-identity-import"
CANDIDATE="$ROOT/43980325816/candidate-migrated.pgdump"
SOURCE=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
NETWORK="mskba-identity-net-$RUN_ID"
DB="mskba-identity-db-$RUN_ID"
test ! -e "$DEST"
test -s "$CANDIDATE"
test -s "$SOURCE"
docker image inspect postgres:17-alpine >/dev/null
docker image inspect mskbanew-phpfpm:latest >/dev/null
mkdir -p "$DEST"
chmod 700 "$DEST"
PW="$(openssl rand -hex 32)"
cleanup() {
  docker stop "$DB" >/dev/null 2>&1 || true
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create --internal "$NETWORK" >/dev/null
docker run --rm -d --network "$NETWORK" --network-alias candidate-db --name "$DB" \
  -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PW" postgres:17-alpine >/dev/null

ready=false
for _ in $(seq 1 60); do
  if docker exec "$DB" sh -c 'test "$(cat /proc/1/comm)" = postgres' \
     && docker exec "$DB" pg_isready -q -U postgres -d candidate; then
    ready=true; break
  fi
  sleep 1
done
[[ "$ready" == true ]] || { echo 'IDENTITY_IMPORT_BLOCKED candidate start'; exit 3; }
if ! docker exec -i "$DB" pg_restore -U postgres -d candidate \
   --no-owner --no-acl --exit-on-error < "$CANDIDATE" > "$DEST/candidate-restore.log" 2>&1; then
  echo 'IDENTITY_IMPORT_BLOCKED candidate restore'; exit 4
fi
docker exec "$DB" createdb -U postgres dev_snapshot
if ! docker exec -i "$DB" pg_restore -U postgres -d dev_snapshot \
   --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/dev-restore.log" 2>&1; then
  echo 'IDENTITY_IMPORT_BLOCKED dev restore'; exit 5
fi

run_import() {
  docker run --rm --network "$NETWORK" \
    -v "$STAGED_PHP:/tmp/merge.php:ro" \
    -e MSKBA_MERGE_ISOLATED=YES -e MSKBA_MERGE_DB_HOST=candidate-db \
    -e MSKBA_MERGE_DB_USER=postgres -e MSKBA_MERGE_DB_PASSWORD="$PW" \
    --entrypoint php mskbanew-phpfpm:latest /tmp/merge.php "$@"
}
if ! run_import > "$DEST/dry-run.log" 2>&1; then
  echo 'IDENTITY_IMPORT_BLOCKED dry-run error; server-private log'; exit 6
fi
if ! run_import --apply > "$DEST/apply.log" 2>&1; then
  echo 'IDENTITY_IMPORT_BLOCKED apply error; server-private log'; exit 7
fi
count=$(docker exec "$DB" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c 'SELECT count(*) FROM users')
before=46
[[ "$count" -ge "$before" ]] || { echo 'IDENTITY_IMPORT_BLOCKED fewer production users'; exit 8; }
if ! docker exec "$DB" pg_dump -U postgres -d candidate --format=custom --no-owner --no-acl > "$DEST/identity-candidate.pgdump.part"; then
  echo 'IDENTITY_IMPORT_BLOCKED checkpoint failed'; exit 9
fi
test -s "$DEST/identity-candidate.pgdump.part"
mv "$DEST/identity-candidate.pgdump.part" "$DEST/identity-candidate.pgdump"
sha256sum "$DEST/identity-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +

echo 'IDENTITY_REHEARSAL_COMPLETE=true'
echo "candidate_user_count=$count"
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/identity-candidate.pgdump")"
cat "$DEST/apply.log"
echo "private_checkpoint_path=$DEST"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
