# MSKBA RBAC staging readiness and rollback

Scope: [PR #367](https://github.com/a2ice/mskba/pull/367), development only.
Never execute staging commands on production/main. The PR is draft until
backups, staging permissions acceptance, and release owner sign-off.

## A. Disposable PostgreSQL compatibility

The RBAC PostgreSQL Migration workflow runs on ephemeral GitHub-hosted
PostgreSQL 16 and PostgreSQL 17 (the dev Compose image is postgres:17-alpine).
This workflow does not have staging secrets. It creates a pre-RBAC schema,
reproduces the historic users_system_role_check, seeds moderator/editor/admin/
user fixtures and individual grants, applies the migration, and verifies role
mapping, preserved snapshots, ceilings and idempotence.

The workflow is NOT a substitute for checking the existing staging database.

## B. Real dev preflight before any merge

On the authorized staging host, first verify that the deployed project is
/var/www/mskba-dev-next and its APP_ENV is staging. Confirm the Compose
stack uses the dev-only compose.dev.yaml and compose.dev.local.yaml. Do not
read out or paste secret values into ChatGPT or GitHub logs.

Using a shell on the dev host:

    cd /var/www/mskba-dev-next
    git status --short --branch
    git rev-parse HEAD
    docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml config --quiet
    docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml exec -T phpfpm php artisan migrate:status

Record pre-migration aggregate role counts (no names, emails or secrets):

    docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "SELECT COALESCE(system_role, '\''NULL'\''), count(*) FROM users GROUP BY system_role ORDER BY system_role"'

Review total moderator/editor canonical accounts, aliases, existing
user_operational_permissions including individual denials, and existing
operator/support conversations. Check that no background staff-role writes
are active during migration.

## C. Required encrypted backup and restore rehearsal

A trusted operator with permission to handle staging data must create a
private timestamped directory outside the Git checkout with umask 077,
generate a compressed PostgreSQL custom-format dump using the dev-only DB
service, check nonzero output and pg_restore -l, and store it in a
protected/encrypted backup location. Retain backup path/timestamp internally
without exposing credentials or customer records in PR comments.

Suggested read-only export (staging host only):

    umask 077
    BACKUP_DIR="$HOME/mskba-rbac-backup-$(date +%Y%m%d-%H%M%S)"
    mkdir -m 700 "$BACKUP_DIR"
    docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner --no-acl' > "$BACKUP_DIR/dev-before-rbac.dump"
    test -s "$BACKUP_DIR/dev-before-rbac.dump"
    docker compose --env-file .env -f compose.dev.yaml -f compose.dev.local.yaml exec -T db pg_restore -l < "$BACKUP_DIR/dev-before-rbac.dump" > /dev/null

A successful pg_restore -l only proves the archive is readable, NOT that
it can be restored. Rehearse an actual restore into a separate isolated
database, verify table counts, and protect or destroy the temporary copy.

## D. Release and staging acceptance

Do not merge PR #367 until:
- PR is up-to-date with dev and full CI plus both PostgreSQL variants pass;
- real dev backup AND isolated restore rehearsal are verified;
- owners approve an explicit staging deployment window;
- the team confirms who will review changes and can restore safely.

A merge into dev may trigger automatic staging deployment: do not merge
before completing the backup.

After supervised dev migration, verify:
1. Moderator/editor accounts become manager with corresponding preset;
   admins, users, aliases and personal grants are preserved.
2. Admin can delegate ONLY subordinate permissions; user cannot enter
   Filament even with a forged old positive grant.
3. Manager with zero, one and several specializations sees exactly its
   assigned UI and rights; display title and renamed specialization are
   not authorization gates.
4. Manager/Editor cannot publish executable Page Builder code by default.
5. Operator presence, conversations and marketing remain separate;
   revocations redact previous previews, and marketing respects consent.
6. Demotion clears profiles and incompatible grants; repromotion cannot
   silently reactivate old privileges.
7. Confirm contextual team/venue/event/tournament rights are unaffected.

## E. Rollback conditions

Stop rollout for failed migrations, mismatched counts, broken backup,
unexpected admin/Filament access, history leaks, or unintended advertising.
The migration deliberately refuses destructive down() while manager
accounts exist: a verified restore of the PRE-MIGRATION database, paired
with restoration of the previous deployment SHA, is required for full
rollback. Do NOT run migrate:rollback expecting automatic staff reversal.

No production/main operations are part of this release.
