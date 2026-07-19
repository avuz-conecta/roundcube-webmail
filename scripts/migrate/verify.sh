#!/bin/bash
# Post-migration content-integrity gate. Compares SQLite (source, read-only) to
# the in-stack Postgres (target). Exits 0 only if all checks pass.
#
# Approach: no md5 (SQLite ships none). For each sensitive text column we compare
# (non-null count, total char length) between engines — this catches dropped rows,
# truncation, and encoding expansion/corruption. Row parity + FK integrity +
# sequence sanity round it out. Byte-perfect signature rendering is confirmed by
# the live smoke test (login loads+unserializes users.preferences; signature
# displays) in Tasks 9/11.
#
# Usage: verify.sh <endpoint_id> <stack_prefix> <roundcube_container> [sqlite_filename]
# sqlite_filename (default roundcube.db) MUST be the exact snapshot the migration
# loaded from — rehearsal passes rehearsal.db so PG and SQLite are compared with
# zero drift (exact parity required; no "acceptable mismatch" escape hatch).
set -euo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EID="${1:?}"; STACK="${2:?}"; C="${3:?}"; SQLITE_FILE="${4:-roundcube.db}"
NET="${STACK}_default"
PGURI="postgresql://roundcube:${ROUNDCUBE_PG_PASSWORD:?set ROUNDCUBE_PG_PASSWORD}@postgres:5432/roundcube"
DB="/var/www/roundcube/temp/$SQLITE_FILE"
# scalar helpers: run a single query, return the lone value with whitespace stripped
SQ(){ "$D/../portainer-exec.sh" -u www-data "$C" sh -c "sqlite3 -noheader -list \"$DB\" \"$1\"" | tr -d '[:space:]'; }
PG(){ "$D/pg-oneshot.sh" "$EID" "$NET" postgres:16-alpine - \
      "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql '$PGURI' -tAc \"$1\" 2>/dev/null" \
      | grep -v '^\[oneshot exit=' | tr -d '[:space:]'; }
fail=0
chk(){ # chk <label> <sqlite-value> <pg-value>
  if [ "$2" = "$3" ]; then echo "OK   $1: $2"; else echo "FAIL $1: sqlite=$2 pg=$3"; fail=1; fi; }

# 1. Row parity for all 7 tables
for t in users identities contacts contactgroups contactgroupmembers collected_addresses responses; do
  chk "rows $t" "$(SQ "SELECT count(*) FROM $t;")" "$(PG "SELECT count(*) FROM $t;")"
done

# 2. Content parity: (non-null count, total char length) per sensitive column.
#    SQLite length() and Postgres char_length() both count characters.
chk "identities.signature nn"  "$(SQ "SELECT count(signature) FROM identities;")"          "$(PG "SELECT count(signature) FROM identities;")"
chk "identities.signature len" "$(SQ "SELECT COALESCE(sum(length(signature)),0) FROM identities;")" "$(PG "SELECT COALESCE(sum(char_length(signature)),0) FROM identities;")"
chk "contacts.vcard nn"        "$(SQ "SELECT count(vcard) FROM contacts;")"                "$(PG "SELECT count(vcard) FROM contacts;")"
chk "contacts.vcard len"       "$(SQ "SELECT COALESCE(sum(length(vcard)),0) FROM contacts;")"       "$(PG "SELECT COALESCE(sum(char_length(vcard)),0) FROM contacts;")"
chk "users.preferences nn"     "$(SQ "SELECT count(preferences) FROM users;")"             "$(PG "SELECT count(preferences) FROM users;")"
chk "users.preferences len"    "$(SQ "SELECT COALESCE(sum(length(preferences)),0) FROM users;")"    "$(PG "SELECT COALESCE(sum(char_length(preferences)),0) FROM users;")"

# 3. FK integrity on Postgres: zero dangling contactgroupmembers.
chk "pg dangling members" "0" "$(PG "SELECT count(*) FROM contactgroupmembers m LEFT JOIN contacts c USING(contact_id) WHERE c.contact_id IS NULL;")"

# 4. Sequence sanity: users_seq strictly ahead of max(user_id). (nextval consumes
#    one id — harmless; Roundcube tolerates gaps.)
chk "users_seq ahead" "t" "$(PG "SELECT nextval('users_seq') > (SELECT COALESCE(MAX(user_id),0) FROM users);")"

[ "$fail" = 0 ] && { echo "VERIFY: PASS"; exit 0; } || { echo "VERIFY: FAIL"; exit 1; }
