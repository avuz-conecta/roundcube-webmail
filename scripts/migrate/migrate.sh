#!/bin/bash
# Initialize the in-stack Postgres schema and load the 7 user tables from a
# SQLite file in the roundcube_temp volume, then reset sequences and re-validate
# foreign keys.
#
# Usage: migrate.sh <endpoint_id> <stack_prefix> <sqlite_rel_path_in_volume>
#   e.g. migrate.sh 3 avuz-mail-roundcube-2 roundcube.db
# PORTAINER_ENV_FILE selects staging (default) vs prod.
# FORCE_WIPE=1 permits wiping a populated (non-production) target.
set -euo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
die(){ echo "error: $*" >&2; exit 1; }
[ "$#" -eq 3 ] || die "usage: $0 <endpoint_id> <stack_prefix> <sqlite_rel_path>"
EID="$1"; STACK="$2"; SQLITE="$3"
NET="${STACK}_default"
VOL="${STACK}_roundcube_temp"     # named volume backing /var/www/roundcube/temp
PG_DSN="pgsql://roundcube:${ROUNDCUBE_PG_PASSWORD:?set ROUNDCUBE_PG_PASSWORD}@postgres/roundcube"
PGURI="postgresql://roundcube:${ROUNDCUBE_PG_PASSWORD}@postgres:5432/roundcube"
# Pinned pgloader — NEVER :latest (non-reproducible + abandoned build). Override
# with a @sha256:... digest for the cutover, recorded during rehearsal.
PGLOADER_IMAGE="${PGLOADER_IMAGE:-dimitri/pgloader:3.6.9}"

echo "== 0. SAFETY GUARD (this script's first act is DROP SCHEMA) =="
Q(){ "$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
     "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -tAc \"$1\" 2>/dev/null" \
     | grep -v '^\[oneshot exit=' | tr -d '[:space:]'; }
# Guard A: a completed cutover stamps migration_complete. NEVER wipe such a DB —
# not even with FORCE_WIPE. It is live production.
if [ "$(Q "SELECT to_regclass('public.migration_complete') IS NOT NULL;")" = "t" ]; then
  die "REFUSING: target Postgres carries a migration_complete marker = LIVE PRODUCTION. Will not wipe."
fi
# Guard B: a populated target (rehearsal leftovers) requires an explicit opt-in.
if [ "$(Q "SELECT to_regclass('public.users') IS NOT NULL;")" = "t" ]; then
  n="$(Q "SELECT count(*) FROM users;")"; n="${n:-0}"
  if [ "$n" -gt 0 ] && [ "${FORCE_WIPE:-0}" != "1" ]; then
    die "REFUSING: target already has $n users. Set FORCE_WIPE=1 to intentionally wipe (rehearsal re-run ONLY, never live prod)."
  fi
fi

echo "== 1. reset schema (drop+recreate public) =="
"$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
  "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -v ON_ERROR_STOP=1 -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'"

echo "== 2. load roundcube schema =="
SCHEMA_B64="$(base64 < "$D/postgres.initial.sql" | tr -d '\n')"
"$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
  "echo '$SCHEMA_B64' | base64 -d > /tmp/s.sql && PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -v ON_ERROR_STOP=1 -f /tmp/s.sql >/dev/null && echo schema-loaded"

echo "== 3. capture + drop FK constraints (so pgloader load order is irrelevant) =="
# Persist FK defs in a real table (temp tables don't survive across one-shot psql
# containers). Re-added with validation in step 6 — that IS the FK integrity gate.
DROP_FK="CREATE TABLE IF NOT EXISTS _fk_backup AS
  SELECT conrelid::regclass::text AS tbl, conname::text AS name, pg_get_constraintdef(oid) AS def
  FROM pg_constraint WHERE contype='f';
DO \$\$ DECLARE r record; BEGIN
  FOR r IN SELECT * FROM _fk_backup LOOP
    EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I', r.tbl, r.name);
  END LOOP; END \$\$;"
"$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
  "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -v ON_ERROR_STOP=1 -c \"$DROP_FK\" && echo fks-dropped"

echo "== 4. pgloader (data only, 7 tables) =="
LOAD_B64="$(sed "s|{{PG_DSN}}|$PG_DSN|" "$D/roundcube.load" | sed "s|/data/roundcube.db|/data/$SQLITE|" | base64 | tr -d '\n')"
"$D/pg-oneshot.sh" "$EID" "$NET" "$PGLOADER_IMAGE" "$VOL" \
  "echo '$LOAD_B64' | base64 -d > /tmp/m.load && pgloader /tmp/m.load"

echo "== 5. reset sequences =="
SEQ_B64="$(base64 < "$D/reset-sequences.sql" | tr -d '\n')"
"$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
  "echo '$SEQ_B64' | base64 -d > /tmp/seq.sql && PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -v ON_ERROR_STOP=1 -f /tmp/seq.sql && echo sequences-reset"

echo "== 6. re-add FK constraints (VALIDATES every referencing row = integrity gate) =="
READD_FK="DO \$\$ DECLARE r record; BEGIN
  FOR r IN SELECT * FROM _fk_backup LOOP
    EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s', r.tbl, r.name, r.def);
  END LOOP; END \$\$;
DROP TABLE _fk_backup;"
"$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
  "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -v ON_ERROR_STOP=1 -c \"$READD_FK\" && echo fks-revalidated"

echo "== migrate.sh done =="
