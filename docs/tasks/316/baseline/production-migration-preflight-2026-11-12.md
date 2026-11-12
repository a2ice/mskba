# 316 — Production migration preflight (read-only baseline, 12.11.2026)

Status: **audit only**. No production DB writes or production deploy performed.

## Branch/schema inventory

- GitHub `main`: **169** migration files.
- GitHub `dev`: **265** migration files.
- Difference by filename: **96** files in dev not in main, none only in main.
- This is **not** the pending-migration count for production. Only `php artisan migrate:status --no-ansi` against the actual prod database can determine that. Never assume a migration was applied because its filename exists in main.
- Eight commits on `main` not in `dev` (as of audit) affect only `docs/Apelsinnine/tasks.md` and `docs/Apelsinnine/tasks/002/description.md`; no production application code differences were found in that branch-direction comparison.

## Sensitive migration review

1. `2026_10_17_114000_limit_page_builder_revisions_and_create_script_assets.php`: rewrites older `page_builder_revisions.snapshot` payloads to hash-only archive data, and its `down()` explicitly cannot restore history. **Requires recoverable full production backup before running; irreversible history truncation.**
2. `2026_11_11_230000_scope_page_builder_pages_by_design_theme.php`: drops old unique indexes, adds composite/partial unique indexes; on production verify there are no incompatible pre-existing duplicate draft/published paths. Rollback checks duplicates and may intentionally refuse to proceed.
3. `2026_11_01_232000_unify_event_owner_and_responsibility_contracts.php`: imports owner contracts for **all** existing events (including soft-deleted) and maps participant responsibilities. Review runtime/FK integrity and affected row counts.
4. `2026_11_01_234000_backfill_verified_venue_contracts.php`: modifies existing contracts and records contract-verification decisions based on existing approved/rejected ownership evidence. Must preserve already reviewed ownership provenance.
5. `2026_10_27_153000_merge_venue_subscriptions_into_play_intents.php`: idempotent-looking migration of existing subscriptions into play intents, changes notification activation flags, intentionally retains legacy rows. Check for duplicates and subscription state preservation.
6. `2026_10_15_200000_create_design_themes.php` and `2026_11_11_230100_seed_classic_home_draft.php`: require consistent `design_palettes` (including `arena_night`) and safe creation of non-published classic homepage draft.
7. `2026_10_17_001000_patch_dev_hero_slider_responsive_coordinates.php`, `2026_10_17_002000_convert_dev_legacy_hero_slider_slides_to_layers.php`, `2026_10_17_003000_enable_dev_hero_mobile_flow.php`, `2026_11_11_070000_restore_laravel_venues_catalog_on_dev.php`: **explicit staging + exact dev URL guards**; these should skip on prod. Verify guards remain unchanged.

This is a **targeted code review**, not a certification of all 96 migration files and not a substitute for a live migration-status report.

## Release sequence — use existing production database

1. **Before approval:** preserve `mskba.ru` SEO baseline using the read-only script:
   ```bash
   python3 scripts/seo/production_url_gate.py capture \
     --origin https://mskba.ru \
     --output pre-release-mskba-urls.csv \
     --extra-paths historical-indexed-paths.txt
   ```
   `--extra-paths` is optional but recommended for URLs from Search Console, Яндекс Вебмастер and logs.
2. On prod, **read-only**: obtain `php artisan migrate:status --no-ansi` and check its pending set against this inventory. Check PostgreSQL space/locks, current application revision, table constraints, media storage, existing `page_builder_pages` route overrides and ownership data.
3. With explicit production approval: put a maintenance/backup plan in place, create a **restorable** timestamped PostgreSQL backup + media backup, record code and environment revisions, and establish rollback.
4. Apply reviewed deploy and `php artisan migrate --force` **to existing production PostgreSQL**. Do not import/replace dev data; preserve existing IDs, content, users and statistics.
5. After release, run:
   ```bash
   python3 scripts/seo/production_url_gate.py check \
     --origin https://mskba.ru \
     --baseline pre-release-mskba-urls.csv \
     --output post-release-mskba-urls.csv
   ```
   Gate returns exit **2** when production URL status, canonical, indexability, placeholder, or existing entity identity is broken. Review any title/H1/description differences and redirected paths individually.
6. Verify dynamic sitemap, robots, Yandex Metrika, media paths, top landing pages, old external backlinks and Laravel/Nginx 404+500 logs. A 200 for the wrong object is not success.
7. Do not mark task 316 complete until post-release verification and acceptance.

## Known limits

- An independent dev DB is **not** a source of mappings for production IDs and is not required to be cloned from prod.
- The URL gate validates published HTTP/SEO output, not every application workflow or a full semantic comparison of article bodies. Important pages still need manual content and media review.
- Actual production migration state, data-volume runtime and collision risks remain unknown without read-only database access.
- The source sitemap is an incomplete inventory of indexed/search traffic pages; preserve external historical URL lists.
