#!/usr/bin/env bash
# Task 325: Shop catalogue transfer into an isolated post-editorial candidate.
# All shop tables must be empty in target; source IDs remain unchanged because
# all shop foreign keys are internal to this isolated catalog.
set -euo pipefail
umask 077
[[ $# -eq 2 && "$1" =~ ^[0-9]+$ && "$2" =~ ^[0-9]+$ ]] || exit 2
RUN="$1"
EDITORIAL_RUN="$2"
BASE=/home/deploy/mskba-data-merge-candidates
SOURCE="$BASE/$EDITORIAL_RUN-editorial-import/editorial-candidate.pgdump"
DEV=/home/deploy/mskba-data-merge-backups/43974694391/dev/database.pgdump
DEST="$BASE/$RUN-shop-import"
NET="mskba-shop-net-$RUN"
CONTAINER="mskba-shop-db-$RUN"
test -s "$SOURCE"
test -s "$DEV"
test ! -e "$DEST"
( cd "$BASE/$EDITORIAL_RUN-editorial-import" && sha256sum --status -c SHA256SUMS )
mkdir -p "$DEST"
chmod 700 "$DEST"
PASSWORD="$(openssl rand -hex 32)"
cleanup() { docker stop "$CONTAINER" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create --internal "$NET" >/dev/null
docker run --rm -d --network "$NET" --name "$CONTAINER" \
  -e POSTGRES_DB=candidate -e POSTGRES_PASSWORD="$PASSWORD" postgres:17-alpine >/dev/null
ready=false
for _ in $(seq 1 60); do
  if docker exec "$CONTAINER" sh -c 'test "$(cat /proc/1/comm)" = postgres' && \
    docker exec "$CONTAINER" pg_isready -q -U postgres -d candidate; then ready=true; break; fi
  sleep 1
done
[[ "$ready" == true ]] || exit 3
if ! docker exec -i "$CONTAINER" pg_restore -U postgres -d candidate \
 --no-owner --no-acl --exit-on-error < "$SOURCE" > "$DEST/restore-candidate.log" 2>&1; then
 echo 'SHOP_BLOCKED candidate restore'; exit 4; fi
docker exec "$CONTAINER" createdb -U postgres dev_snapshot
if ! docker exec -i "$CONTAINER" pg_restore -U postgres -d dev_snapshot \
 --no-owner --no-acl --exit-on-error < "$DEV" > "$DEST/restore-dev.log" 2>&1; then
 echo 'SHOP_BLOCKED dev restore'; exit 5; fi

if ! docker exec -i "$CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate \
 > "$DEST/shop-copy.log" 2>&1 <<'SQL'; then
BEGIN;
DO $$
DECLARE n bigint;
BEGIN
 SELECT count(*) INTO n FROM shop_categories;
 IF n > 0 THEN RAISE EXCEPTION 'shop target is not empty'; END IF;
 SELECT count(*) INTO n FROM shop_products;
 IF n > 0 THEN RAISE EXCEPTION 'shop product target is not empty'; END IF;
END $$;
CREATE EXTENSION IF NOT EXISTS postgres_fdw;
CREATE SCHEMA ext_dev_shop;
CREATE SERVER dev_shop_snapshot FOREIGN DATA WRAPPER postgres_fdw
 OPTIONS (dbname 'dev_snapshot', host '/var/run/postgresql');
CREATE USER MAPPING FOR postgres SERVER dev_shop_snapshot OPTIONS(user 'postgres');
IMPORT FOREIGN SCHEMA public LIMIT TO (
 shop_categories,shop_products,shop_category_product,shop_tags,shop_taggables,
 shop_attributes,shop_attribute_category,shop_product_attribute_values,
 shop_product_types,shop_attribute_product_type
) FROM SERVER dev_shop_snapshot INTO ext_dev_shop;

INSERT INTO shop_product_types SELECT * FROM ext_dev_shop.shop_product_types;
INSERT INTO shop_attributes SELECT * FROM ext_dev_shop.shop_attributes;
INSERT INTO shop_tags SELECT * FROM ext_dev_shop.shop_tags;
INSERT INTO shop_products SELECT * FROM ext_dev_shop.shop_products;

DO $$
DECLARE count_before int; new_count int; target_count int;
BEGIN
 LOOP
  SELECT count(*) INTO count_before FROM shop_categories;
  INSERT INTO shop_categories
    SELECT c.* FROM ext_dev_shop.shop_categories c
    WHERE NOT EXISTS (SELECT 1 FROM shop_categories p WHERE p.id = c.id)
      AND (c.parent_id IS NULL OR EXISTS (SELECT 1 FROM shop_categories p2 WHERE p2.id=c.parent_id));
  SELECT count(*) INTO target_count FROM shop_categories;
  new_count := target_count - count_before;
  EXIT WHEN new_count = 0;
 END LOOP;
 IF (SELECT count(*) FROM shop_categories) <>
    (SELECT count(*) FROM ext_dev_shop.shop_categories) THEN
    RAISE EXCEPTION 'unresolved category parents';
 END IF;
END $$;

INSERT INTO shop_category_product SELECT * FROM ext_dev_shop.shop_category_product;
INSERT INTO shop_attribute_category SELECT * FROM ext_dev_shop.shop_attribute_category;
INSERT INTO shop_product_attribute_values SELECT * FROM ext_dev_shop.shop_product_attribute_values;
INSERT INTO shop_attribute_product_type SELECT * FROM ext_dev_shop.shop_attribute_product_type;
INSERT INTO shop_taggables SELECT * FROM ext_dev_shop.shop_taggables;

SELECT setval(pg_get_serial_sequence('shop_product_types','id'),GREATEST(1,(SELECT COALESCE(MAX(id),0) FROM shop_product_types)),true);
SELECT setval(pg_get_serial_sequence('shop_attributes','id'),GREATEST(1,(SELECT COALESCE(MAX(id),0) FROM shop_attributes)),true);
SELECT setval(pg_get_serial_sequence('shop_tags','id'),GREATEST(1,(SELECT COALESCE(MAX(id),0) FROM shop_tags)),true);
SELECT setval(pg_get_serial_sequence('shop_products','id'),GREATEST(1,(SELECT COALESCE(MAX(id),0) FROM shop_products)),true);
SELECT setval(pg_get_serial_sequence('shop_categories','id'),GREATEST(1,(SELECT COALESCE(MAX(id),0) FROM shop_categories)),true);
SELECT setval(pg_get_serial_sequence('shop_product_attribute_values','id'),GREATEST(1,(SELECT COALESCE(MAX(id),0) FROM shop_product_attribute_values)),true);
DO $$
DECLARE tbl text; source_count bigint; target_count bigint;
BEGIN
 FOREACH tbl IN ARRAY ARRAY[
  'shop_categories','shop_products','shop_category_product','shop_tags',
  'shop_taggables','shop_attributes','shop_attribute_category',
  'shop_product_attribute_values','shop_product_types',
  'shop_attribute_product_type'
 ] LOOP
  EXECUTE format('SELECT count(*) FROM public.%I',tbl) INTO target_count;
  EXECUTE format('SELECT count(*) FROM ext_dev_shop.%I',tbl) INTO source_count;
  IF target_count <> source_count THEN
   RAISE EXCEPTION 'shop table count mismatch for %',tbl;
  END IF;
 END LOOP;
END $$;
COMMIT;
SQL
 echo 'SHOP_BLOCKED SQL transfer; private log retained'; exit 6
fi
sql() { docker exec "$CONTAINER" psql -XAt -v ON_ERROR_STOP=1 -U postgres -d candidate -c "$1"; }
if [[ "$(sql "SELECT count(*) FROM pg_constraint WHERE contype='f' AND NOT convalidated")" != 0 ]]; then
 echo 'SHOP_BLOCKED invalid foreign key'; exit 7; fi
if ! docker exec "$CONTAINER" pg_dump -U postgres -d candidate --format=custom \
 --no-owner --no-acl > "$DEST/shop-candidate.pgdump.part"; then
 echo 'SHOP_BLOCKED checkpoint'; exit 8; fi
test -s "$DEST/shop-candidate.pgdump.part"
mv "$DEST/shop-candidate.pgdump.part" "$DEST/shop-candidate.pgdump"
sha256sum "$DEST/shop-candidate.pgdump" > "$DEST/SHA256SUMS"
find "$DEST" -maxdepth 1 -type f -exec chmod 600 {} +
echo 'SHOP_IMPORT_ISOLATED_PASS=true'
for table in shop_categories shop_products shop_tags shop_attributes shop_product_types; do
 echo "$table=$(sql "SELECT count(*) FROM $table")"
done
echo "candidate_checkpoint_bytes=$(stat -c %s "$DEST/shop-candidate.pgdump")"
echo 'LIVE_PROD_AND_DEV_DB_UNCHANGED=true'
