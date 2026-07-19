# SQLite → Postgres Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the Roundcube database from per-container SQLite to an in-stack Postgres, eliminating the `cache_messages`/session `database is locked` storm, with a rehearsed, content-verified migration of real user data.

**Architecture:** Add a `postgres` service inside each roundcube stack (mirrors the existing in-stack `redis`). Migrate only the 7 user-data tables (users, identities, contacts, contactgroups, contactgroupmembers, collected_addresses, responses) with `pgloader` into a schema created from Roundcube's official `postgres.initial.sql`; skip all cache/session tables (disposable). All cluster steps run as one-shot containers driven through the Portainer API (no SSH). Prove the whole thing on a copy of prod data (rehearsal) before the live prod cutover.

**Tech Stack:** Postgres 16-alpine, `dimitri/pgloader`, Portainer Docker API, Roundcube 1.6.14 `SQL/postgres.initial.sql`, bash + jq + curl.

**Spec:** `docs/superpowers/specs/2026-07-19-sqlite-to-postgres-migration-design.md`

## Global Constraints

- Migrate ONLY these 7 tables: `users, identities, contacts, contactgroups, contactgroupmembers, collected_addresses, responses`. Never migrate `cache*`, `session`, `filestore`, `dictionary`, `searches`.
- Serial PKs / sequences to reset (exact names from `SQL/postgres.initial.sql`): `users.user_id`→`users_seq`, `identities.identity_id`→`identities_seq`, `contacts.contact_id`→`contacts_seq`, `contactgroups.contactgroup_id`→`contactgroups_seq`, `collected_addresses.address_id`→`collected_addresses_seq`, `responses.response_id`→`responses_seq`. `contactgroupmembers` has a composite PK (`contactgroup_id, contact_id`) — no sequence.
- SQLite is READ-ONLY throughout. Never write to `roundcube.db`.
- Postgres DSN form: `pgsql://roundcube:$PW@postgres/roundcube`. Password from new stack env `ROUNDCUBE_PG_PASSWORD` (not reused from any existing secret).
- Scope: staging `avuz-mail-roundcube-2` (endpoint 3) and prod `avuz-mail-roundcube` (endpoint 5). Endpoint 9 is OUT of scope.
- Portainer configs: staging `scripts/deploy.env`, prod `scripts/deploy.prod.env` (via `PORTAINER_ENV_FILE`). Both gitignored.
- Row parity baselines — staging: users 4, identities 4, collected_addresses 17, contacts 0, contactgroups 0, contactgroupmembers 0, responses 0. Prod: users 93, identities 94, contacts 11555, contactgroups 2, contactgroupmembers 6, collected_addresses 333, responses 4.
- Container/DB paths: SQLite at `/var/www/roundcube/temp/roundcube.db`; Roundcube schema at `/var/www/roundcube/SQL/postgres.initial.sql`; roundcube service name `roundcube`, postgres service name `postgres`, redis `redis`.
- **pgloader is PINNED** to `dimitri/pgloader:3.6.9` (never `:latest`). During rehearsal, record the resolved image digest and set `PGLOADER_IMAGE=dimitri/pgloader@sha256:<digest>` for the prod cutover so rehearsal and cutover run the provably identical build.

---

## File Structure

- `scripts/migrate/postgres.initial.sql` — committed copy of Roundcube 1.6.14 Postgres schema (pinned; source of the target schema).
- `scripts/migrate/roundcube.load` — pgloader command file (data-only, 7 tables).
- `scripts/migrate/reset-sequences.sql` — `setval` for the 6 serial PKs.
- `scripts/migrate/prescan.sh` — pre-migration checks against SQLite (invalid UTF-8, dangling FK rows).
- `scripts/migrate/verify.sh` — post-migration content-integrity gate (row parity, signature md5, vcard checksum, preferences deserialize, FK integrity, sequence sanity).
- `scripts/migrate/pg-oneshot.sh` — Portainer helper: create+start+wait+log+rm a one-shot container on an endpoint (image, network, optional volume mount, shell command).
- `scripts/migrate/migrate.sh` — orchestrator for one target (endpoint+stack+mode rehearsal|cutover).
- `deploy/stack.reference.yml` — add `postgres` service + `roundcube_pg` volume + env + `depends_on` (Modify).
- `config/config.inc.php` — set `messages_cache='db'` + fix the misleading comment (Modify).

Reuse: `scripts/portainer-exec.sh` (exec in an existing container), `scripts/logs.sh`.

---

### Task 1: Add Postgres to the stack reference

**Files:**
- Modify: `deploy/stack.reference.yml`

**Interfaces:**
- Produces: an in-stack `postgres` service reachable at host `postgres:5432`, DB `roundcube`, user `roundcube`, password from `ROUNDCUBE_PG_PASSWORD`; roundcube gets `ROUNDCUBE_DB_DSN` pointing at it.

- [ ] **Step 1: Read the current stack file**

Run: `sed -n '1,60p' deploy/stack.reference.yml`
Expected: see `roundcube`, `redis` services, `roundcube_temp`/`roundcube_logs`/`roundcube_redis` volumes.

- [ ] **Step 2: Add the postgres service + volume + env**

Add under `services:` (sibling of `redis`):

```yaml
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      - POSTGRES_USER=roundcube
      - POSTGRES_DB=roundcube
      - POSTGRES_PASSWORD=${ROUNDCUBE_PG_PASSWORD}
    volumes:
      - roundcube_pg:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U roundcube -d roundcube"]
      interval: 10s
      timeout: 5s
      retries: 5
```

Add to the `roundcube` service `environment:` (replacing the empty `ROUNDCUBE_DB_DSN=`):

```yaml
      - ROUNDCUBE_DB_DSN=pgsql://roundcube:${ROUNDCUBE_PG_PASSWORD}@postgres/roundcube
```

Add to the `roundcube` service `depends_on:` (create if absent): `postgres`.

Add to top-level `volumes:`: `roundcube_pg:`

- [ ] **Step 3: Validate YAML**

Run: `python3 -c "import yaml,sys; yaml.safe_load(open('deploy/stack.reference.yml')); print('yaml ok')"`
Expected: `yaml ok`

- [ ] **Step 4: Commit**

```bash
git add deploy/stack.reference.yml
git commit -m "feat(stack): add in-stack postgres service + DB DSN"
```

---

### Task 2: Commit the pinned Postgres schema

**Files:**
- Create: `scripts/migrate/postgres.initial.sql` (copy of `SQL/postgres.initial.sql`)

**Interfaces:**
- Produces: the exact DDL used to initialize the target Postgres (pinned to 1.6.14, independent of future upstream changes).

- [ ] **Step 1: Copy the schema into the migrate dir**

Run:
```bash
mkdir -p scripts/migrate
cp SQL/postgres.initial.sql scripts/migrate/postgres.initial.sql
```

- [ ] **Step 2: Sanity-check it defines the 7 tables + sequences**

Run: `grep -cE "CREATE TABLE (users|identities|contacts|contactgroups|contactgroupmembers|collected_addresses|responses) " scripts/migrate/postgres.initial.sql`
Expected: `7`

- [ ] **Step 3: Commit**

```bash
git add scripts/migrate/postgres.initial.sql
git commit -m "chore(migrate): pin roundcube 1.6.14 postgres schema"
```

---

### Task 3: pgloader command file + sequence-reset SQL

**Files:**
- Create: `scripts/migrate/roundcube.load`
- Create: `scripts/migrate/reset-sequences.sql`

**Interfaces:**
- Consumes: env-substituted paths — `SQLITE_PATH` (default `/data/roundcube.db`), `PG_DSN`.
- Produces: a pgloader spec that loads exactly the 7 tables, data-only; and a SQL script that fixes all 6 sequences.

- [ ] **Step 1: Write the pgloader command file**

`scripts/migrate/roundcube.load`:
```
LOAD DATABASE
     FROM sqlite:///data/roundcube.db
     INTO {{PG_DSN}}

 WITH data only,
      workers = 2, concurrency = 1,
      on error stop

 INCLUDING ONLY TABLE NAMES MATCHING
      'users', 'identities', 'contacts', 'contactgroups',
      'contactgroupmembers', 'collected_addresses', 'responses'

 SET work_mem to '64MB', maintenance_work_mem to '128MB';
```

Note: `data only` requires the target tables to already exist (Task 5 creates them from the pinned schema). `on error stop` makes any type/encoding failure abort loudly rather than silently skip rows.

- [ ] **Step 2: Write the sequence-reset SQL**

`scripts/migrate/reset-sequences.sql`:
```sql
SELECT setval('users_seq',               COALESCE((SELECT MAX(user_id)      FROM users), 0) + 1, false);
SELECT setval('identities_seq',          COALESCE((SELECT MAX(identity_id)   FROM identities), 0) + 1, false);
SELECT setval('contacts_seq',            COALESCE((SELECT MAX(contact_id)    FROM contacts), 0) + 1, false);
SELECT setval('contactgroups_seq',       COALESCE((SELECT MAX(contactgroup_id) FROM contactgroups), 0) + 1, false);
SELECT setval('collected_addresses_seq', COALESCE((SELECT MAX(address_id)    FROM collected_addresses), 0) + 1, false);
SELECT setval('responses_seq',           COALESCE((SELECT MAX(response_id)   FROM responses), 0) + 1, false);
```

- [ ] **Step 3: Commit**

```bash
git add scripts/migrate/roundcube.load scripts/migrate/reset-sequences.sql
git commit -m "chore(migrate): pgloader load spec + sequence reset"
```

---

### Task 4: Portainer one-shot container helper

**Files:**
- Create: `scripts/migrate/pg-oneshot.sh`

**Interfaces:**
- Consumes: `PORTAINER_ENV_FILE` (or default `scripts/deploy.env`) for `PORTAINER_URL`/`PORTAINER_TOKEN`/`PORTAINER_INSECURE`.
- Produces: `pg-oneshot.sh <endpoint_id> <network> <image> <volume_or_dash> <sh_command>` — runs `sh -lc "<sh_command>"` in a throwaway container attached to `<network>` (and `<volume>:/data:ro` unless `-`), streams its logs, returns its exit code, removes it.

- [ ] **Step 1: Write the helper**

`scripts/migrate/pg-oneshot.sh`:
```bash
#!/bin/bash
# Run a one-shot container on a Portainer endpoint: create -> start -> wait ->
# print logs -> remove. Used to run pgloader / psql against the in-stack postgres
# and the roundcube_temp volume without SSH.
#
# Usage: pg-oneshot.sh <endpoint_id> <network> <image> <volume|-> <sh_command>
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CFG="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/../deploy.env}"
die(){ echo "error: $*" >&2; exit 1; }
[ -f "$CFG" ] || die "no $CFG"
# shellcheck disable=SC1090
set -a; . "$CFG"; set +a
PORTAINER_URL="${PORTAINER_URL%/}"
OPTS=(-fsS); [ "${PORTAINER_INSECURE:-0}" = "1" ] && OPTS+=(-k)
api(){ curl "${OPTS[@]}" -H "X-API-Key: $PORTAINER_TOKEN" "$@"; }

[ "$#" -eq 5 ] || die "usage: $0 <endpoint_id> <network> <image> <volume|-> <sh_command>"
EID="$1"; NET="$2"; IMAGE="$3"; VOL="$4"; CMD="$5"
BASE="$PORTAINER_URL/api/endpoints/$EID/docker"

# Pull the image (idempotent).
api -X POST "$BASE/images/create?fromImage=$IMAGE" >/dev/null || true

BINDS='[]'; [ "$VOL" != "-" ] && BINDS="$(jq -nc --arg v "$VOL" '[$v + ":/data:ro"]')"
# Tty:true => the logs endpoint returns raw bytes (no 8-byte multiplex frame
# headers), so psql/pgloader output comes back clean and parseable.
BODY="$(jq -n --arg img "$IMAGE" --arg net "$NET" --arg cmd "$CMD" --argjson binds "$BINDS" \
  '{Image:$img, Tty:true, Cmd:["sh","-lc",$cmd], HostConfig:{Binds:$binds, NetworkMode:$net, AutoRemove:false}}')"
CID="$(api -X POST -H 'Content-Type: application/json' -d "$BODY" "$BASE/containers/create?name=migrate-oneshot-$$" | jq -r '.Id')"
[ -n "$CID" ] && [ "$CID" != null ] || die "create failed"

cleanup(){ api -X DELETE "$BASE/containers/$CID?force=1" >/dev/null 2>&1 || true; }
trap cleanup EXIT

api -X POST "$BASE/containers/$CID/start" >/dev/null
# Wait for exit, capture status code.
CODE="$(api -X POST "$BASE/containers/$CID/wait" | jq -r '.StatusCode')"
# Raw logs (Tty:true => no frame headers). stderr is merged into stdout.
api "$BASE/containers/$CID/logs?stdout=1&stderr=1" || true
echo "[oneshot exit=$CODE]"
exit "${CODE:-1}"
```

- [ ] **Step 2: chmod + syntax check**

Run: `chmod +x scripts/migrate/pg-oneshot.sh && bash -n scripts/migrate/pg-oneshot.sh && echo ok`
Expected: `ok`

- [ ] **Step 3: Smoke test against staging (endpoint 3, stack network)**

Run:
```bash
scripts/migrate/pg-oneshot.sh 3 avuz-mail-roundcube-2_default postgres:16-alpine - 'echo hello-from-oneshot'
```
Expected: output contains `hello-from-oneshot` and `[oneshot exit=0]`.

- [ ] **Step 4: Commit**

```bash
git add scripts/migrate/pg-oneshot.sh
git commit -m "chore(migrate): portainer one-shot container helper"
```

---

### Task 5: Orchestrator — schema init + load + sequences

**Files:**
- Create: `scripts/migrate/migrate.sh`

**Interfaces:**
- Consumes: `pg-oneshot.sh`, `postgres.initial.sql`, `roundcube.load`, `reset-sequences.sql`, `scripts/portainer-exec.sh`.
- Produces: `migrate.sh <endpoint_id> <stack_prefix> <sqlite_path>` — guards against wiping a live DB, then initializes the in-stack Postgres schema, drops FKs, runs pgloader from `<sqlite_path>` (a path inside the roundcube_temp volume), resets sequences, re-adds+validates FKs. Refuses if the target has a `migration_complete` marker (live prod) or, when the target already holds users, unless `FORCE_WIPE=1` is set (intentional rehearsal re-run). Schema init drops+recreates the public schema, so a permitted run is idempotent.

- [ ] **Step 1: Write the orchestrator**

`scripts/migrate/migrate.sh`:
```bash
#!/bin/bash
# Initialize the in-stack Postgres schema and load the 7 user tables from a
# SQLite file in the roundcube_temp volume, then reset sequences.
#
# Usage: migrate.sh <endpoint_id> <stack_prefix> <sqlite_rel_path_in_volume>
#   e.g. migrate.sh 3 avuz-mail-roundcube-2 roundcube.db
# PORTAINER_ENV_FILE selects staging (default) vs prod.
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
```

- [ ] **Step 2: chmod + syntax check**

Run: `chmod +x scripts/migrate/migrate.sh && bash -n scripts/migrate/migrate.sh && echo ok`
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
git add scripts/migrate/migrate.sh
git commit -m "chore(migrate): schema-init + pgloader + sequence orchestrator"
```

---

### Task 6: Pre-scan (invalid UTF-8 + dangling FK rows)

**Files:**
- Create: `scripts/migrate/prescan.sh`

**Interfaces:**
- Consumes: `scripts/portainer-exec.sh` (runs PHP + sqlite3 inside the roundcube container which holds the SQLite file).
- Produces: `prescan.sh <container>` — prints counts of invalid-UTF-8 text values and dangling contactgroupmembers; exit 0 if clean, 1 if issues found.

- [ ] **Step 1: Write the pre-scan**

`scripts/migrate/prescan.sh`:
```bash
#!/bin/bash
# Pre-migration data checks against the live SQLite (read-only): invalid UTF-8 in
# text columns, and contactgroupmembers referencing missing contacts/groups
# (Postgres FKs will reject those; SQLite allowed them).
# Usage: prescan.sh <roundcube_container>
set -euo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
C="${1:?usage: prescan.sh <container>}"
PHP='
$db=new PDO("sqlite:/var/www/roundcube/temp/roundcube.db");
$bad=0;
$cols=[["identities","signature"],["identities","name"],["contacts","name"],["contacts","vcard"],["users","preferences"],["collected_addresses","name"],["collected_addresses","email"]];
foreach($cols as [$t,$c]){
  foreach($db->query("SELECT $c AS v FROM $t") as $r){
    $v=$r["v"]; if($v!==null && $v!=="" && !mb_check_encoding($v,"UTF-8")){ $bad++; }
  }
}
$dangC=$db->query("SELECT count(*) FROM contactgroupmembers m LEFT JOIN contacts c ON c.contact_id=m.contact_id WHERE c.contact_id IS NULL")->fetchColumn();
$dangG=$db->query("SELECT count(*) FROM contactgroupmembers m LEFT JOIN contactgroups g ON g.contactgroup_id=m.contactgroup_id WHERE g.contactgroup_id IS NULL")->fetchColumn();
echo "invalid_utf8=$bad dangling_contact=$dangC dangling_group=$dangG\n";
exit(($bad+$dangC+$dangG)>0 ? 3 : 0);
'
out="$("$D/../portainer-exec.sh" -u www-data "$C" php -r "$PHP" 2>&1)"
echo "$out"
echo "$out" | grep -q "invalid_utf8=0 dangling_contact=0 dangling_group=0" || { echo "PRESCAN: issues found — resolve before migrating"; exit 1; }
echo "PRESCAN: clean"
```

- [ ] **Step 2: chmod + syntax check**

Run: `chmod +x scripts/migrate/prescan.sh && bash -n scripts/migrate/prescan.sh && echo ok`
Expected: `ok`

- [ ] **Step 3: Run against staging (expect clean — 4 users, no contacts)**

Run: `scripts/migrate/prescan.sh avuz-mail-roundcube-2-roundcube-1`
Expected: `invalid_utf8=0 dangling_contact=0 dangling_group=0` then `PRESCAN: clean`.

- [ ] **Step 4: Commit**

```bash
git add scripts/migrate/prescan.sh
git commit -m "chore(migrate): pre-scan for invalid utf8 + dangling fk rows"
```

---

### Task 7: Content-integrity verification gate

**Files:**
- Create: `scripts/migrate/verify.sh`

**Interfaces:**
- Consumes: `pg-oneshot.sh` (psql against postgres), `scripts/portainer-exec.sh` (sqlite3/php against roundcube), the row-parity baselines from Global Constraints.
- Produces: `verify.sh <endpoint_id> <stack_prefix> <roundcube_container> [sqlite_filename]` — runs the full gate; exits 0 only if every check passes. `sqlite_filename` (default `roundcube.db`) MUST be the same snapshot the migration loaded from, so the comparison is exact with zero drift (rehearsal passes `rehearsal.db`).

- [ ] **Step 1: Write the verifier**

`scripts/migrate/verify.sh`:
```bash
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
SQ(){ "$D/../portainer-exec.sh" -u www-data "$C" sh -c "sqlite3 \"$DB\" \"$1\"" | tr -d '[:space:]'; }
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
```

- [ ] **Step 2: chmod + syntax check**

Run: `chmod +x scripts/migrate/verify.sh && bash -n scripts/migrate/verify.sh && echo ok`
Expected: `ok`

- [ ] **Step 3: Commit**

```bash
git add scripts/migrate/verify.sh
git commit -m "chore(migrate): content-integrity verification gate"
```

---

### Task 8: Re-enable message caching cleanly on the DB

**Files:**
- Modify: `config/config.inc.php`

**Interfaces:**
- Produces: `messages_cache='db'` (explicit) with an accurate comment; behavior unchanged until DSN is Postgres, at which point message caching lands in Postgres (no SQLite lock).

- [ ] **Step 1: Update the value + comment**

In the `if ($redisHost)` block, change:
```php
    $config['messages_cache'] = 'redis'; // backend TYPE (was `true` = broken → no message caching)
```
to:
```php
    // messages_cache is DB-only in Roundcube (rcube_imap_cache uses the SQL handle);
    // the value is only tested for truthiness. 'db' = cache messages in the main DB.
    // Safe now that the DB is Postgres (concurrent writers, no whole-file lock).
    $config['messages_cache'] = 'db';
```
Apply the same `'db'` in the `else` branch if it differs (it already sets `'db'`).

- [ ] **Step 2: Lint**

Run: `php -l config/config.inc.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add config/config.inc.php
git commit -m "fix(cache): messages_cache='db' (honest value; safe on postgres)"
```

---

### Task 9: Execute on STAGING end-to-end

**Files:** none (execution task; uses Tasks 1–8 artifacts).

**Interfaces:**
- Consumes: all migrate scripts; `PORTAINER_ENV_FILE=scripts/deploy.env` (staging), endpoint 3, stack `avuz-mail-roundcube-2`, container `avuz-mail-roundcube-2-roundcube-1`.

- [ ] **Step 1: Set the PG password + deploy the stack with postgres**

Set `ROUNDCUBE_PG_PASSWORD` on the staging stack env in Portainer, apply `deploy/stack.reference.yml` changes to the live `avuz-mail-roundcube-2` stack, redeploy. Confirm postgres healthy:
Run: `scripts/portainer-exec.sh avuz-mail-roundcube-2-postgres-1 pg_isready -U roundcube -d roundcube`
Expected: `accepting connections`

- [ ] **Step 2: Pre-scan (read-only)**

Run: `export ROUNDCUBE_PG_PASSWORD=<staging-pw>; scripts/migrate/prescan.sh avuz-mail-roundcube-2-roundcube-1`
Expected: `PRESCAN: clean`

- [ ] **Step 3: Stop roundcube (writes stop)**

Run: `scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 sh -c 'echo will-stop'` then stop the container via Portainer (UI or API `POST /containers/<id>/stop`).
Expected: roundcube not running.

- [ ] **Step 4: Run the migration**

Run: `scripts/migrate/migrate.sh 3 avuz-mail-roundcube-2 roundcube.db`
Expected: ends with `== migrate.sh done ==`; pgloader step reports 7 tables loaded, `[oneshot exit=0]` at each phase.

- [ ] **Step 5: Verify (gate)**

Run: `scripts/migrate/verify.sh 3 avuz-mail-roundcube-2 avuz-mail-roundcube-2-roundcube-1`
Expected: all `OK`, final `VERIFY: PASS` (staging baselines: users 4, identities 4, collected_addresses 17, others 0).

- [ ] **Step 6: Start roundcube + live smoke**

Start the roundcube container. Then log in via SSO on staging, confirm signature renders, compose shows an autocomplete address. Check logs:
Run: `scripts/logs.sh avuz-mail-roundcube-2-roundcube-1 errors grep "database is locked"`
Expected: no new post-cutover lock lines; login + signature work.

- [ ] **Step 7: Rollback rehearsal (prove the escape hatch)**

Temporarily set the staging `ROUNDCUBE_DB_DSN` back to empty (SQLite), redeploy, confirm login still works on SQLite, then set it back to Postgres and redeploy. This proves the forward-only rollback path before prod.
Expected: both flips work; end state = Postgres.

---

### Task 10: PROD rehearsal against a copy of prod data

**Files:** none (execution task).

**Interfaces:**
- Consumes: prod Portainer (`PORTAINER_ENV_FILE=scripts/deploy.prod.env`), endpoint 5, stack `avuz-mail-roundcube`, container `avuz-mail-roundcube-roundcube-1`. Uses a SCRATCH copy + scratch DB — prod roundcube keeps running.

- [ ] **Step 1: Make an in-volume copy of prod SQLite (read-only source stays intact)**

Run: `scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-roundcube-1 sh -c 'cp /var/www/roundcube/temp/roundcube.db /var/www/roundcube/temp/rehearsal.db && ls -la /var/www/roundcube/temp/rehearsal.db'`
Expected: `rehearsal.db` present.

- [ ] **Step 2: Deploy the postgres service to the prod stack (empty, unused by roundcube yet)**

Set `ROUNDCUBE_PG_PASSWORD` on the prod stack, apply the stack change **without** yet pointing `ROUNDCUBE_DB_DSN` at Postgres (keep roundcube on SQLite). Redeploy. Confirm postgres healthy.
Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/portainer-exec.sh avuz-mail-roundcube-postgres-1 pg_isready -U roundcube -d roundcube`
Expected: `accepting connections`

- [ ] **Step 3: Pre-scan the prod copy**

Run: `export ROUNDCUBE_PG_PASSWORD=<prod-pw>; PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/prescan.sh avuz-mail-roundcube-roundcube-1`
Expected: prints counts. If NOT clean, record offending rows and decide fix/drop before proceeding — do NOT cut over until clean.

- [ ] **Step 4: Rehearse the migration against the copy (time it + lock the pgloader digest)**

Run: `time PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/migrate.sh 5 avuz-mail-roundcube rehearsal.db`
Expected: `== migrate.sh done ==`; pgloader loads all 7 tables with rows-read == rows-imported (inspect its summary in the output).
- Record the wall-clock from `time` — this is the only prod-scale measurement of the load and sizes the cutover maintenance window (staging has 0 contacts, so it tells you nothing about 11.5k).
- Capture the resolved pgloader digest for the cutover:
  Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/pg-oneshot.sh 5 avuz-mail-roundcube_default postgres:16-alpine - 'echo skip' ; ` then read the image digest from Portainer (Images → dimitri/pgloader) and set `PGLOADER_IMAGE=dimitri/pgloader@sha256:<digest>` for Task 11.

- [ ] **Step 5: Verify content integrity on the rehearsal (exact, against the snapshot)**

Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/verify.sh 5 avuz-mail-roundcube avuz-mail-roundcube-roundcube-1 rehearsal.db`
Expected: `VERIFY: PASS` — EXACT parity, because both sides derive from the same `rehearsal.db` snapshot (the 4th arg makes the SQLite side read `rehearsal.db`, not live). Baselines at snapshot time: users 93, identities 94, contacts 11555, contactgroups 2, contactgroupmembers 6, collected_addresses ~333. Any `FAIL` is a real migration defect — no drift exemption. Fix and re-run 10.3–10.6 until clean.

- [ ] **Step 6: Tear down the rehearsal (keep prod pristine)**

Drop the rehearsal data so cutover starts clean:
Run:
```bash
export ROUNDCUBE_PG_PASSWORD=<prod-pw>
PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/pg-oneshot.sh 5 avuz-mail-roundcube_default postgres:16-alpine - \
  "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql 'postgresql://roundcube:$ROUNDCUBE_PG_PASSWORD@postgres:5432/roundcube' -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'"
scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-roundcube-1 rm -f /var/www/roundcube/temp/rehearsal.db
```
Expected: schema dropped, rehearsal.db removed. Fix any issues found and RE-RUN Tasks 10.3–10.6 until a fully clean pass.

---

### Task 11: PROD cutover (scheduled window) + baseline backup

**Files:** none (execution task).

**Interfaces:**
- Consumes: a clean rehearsal (Task 10). Same prod target. This is the live switch.

- [ ] **Step 1: Announce the window / confirm timing**

Confirm the maintenance window is agreed. Users will be briefly offline and re-auth via SSO.

- [ ] **Step 2: Backup the live SQLite (rollback artifact)**

Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-roundcube-1 sh -c 'cp /var/www/roundcube/temp/roundcube.db /var/www/roundcube/temp/roundcube.pre-pg.$(date +%Y%m%d%H%M).db && ls -la /var/www/roundcube/temp/*.pre-pg.*'`
Expected: timestamped backup present.

- [ ] **Step 3: Pre-scan the LIVE db (final)**

Run: `export ROUNDCUBE_PG_PASSWORD=<prod-pw>; PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/prescan.sh avuz-mail-roundcube-roundcube-1`
Expected: `PRESCAN: clean` (issues found in rehearsal must already be resolved).

- [ ] **Step 4: Stop roundcube (writes stop)**

Stop `avuz-mail-roundcube-roundcube-1` via Portainer.
Expected: not running.

- [ ] **Step 5: Migrate the live db**

Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/migrate.sh 5 avuz-mail-roundcube roundcube.db`
Expected: `== migrate.sh done ==`, pgloader rows-read == rows-imported for all 7 tables.

- [ ] **Step 6: Verify (gate) BEFORE reopening**

Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/verify.sh 5 avuz-mail-roundcube avuz-mail-roundcube-roundcube-1`
Expected: `VERIFY: PASS`, exact prod baselines. If FAIL → do Step 7-rollback (still the clean window, no user writes yet).

- [ ] **Step 7 (only on failure): Clean rollback**

Revert prod `ROUNDCUBE_DB_DSN` to empty (SQLite) + `messages_cache` behavior, redeploy, start roundcube. Prod is back on the untouched SQLite. Investigate, re-rehearse, reschedule.

- [ ] **Step 8: Flip config + start**

Confirm the prod stack `ROUNDCUBE_DB_DSN` points at Postgres (from Task 1) and `messages_cache='db'` is in the image (Task 8). Start `avuz-mail-roundcube-roundcube-1`.
Expected: container healthy on Postgres.

- [ ] **Step 9: Live smoke + lock check**

Log in via SSO as a real user, confirm signature renders + autocomplete. Then:
Run: `PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/logs.sh avuz-mail-roundcube-roundcube-1 errors grep "database is locked"`
Expected: no NEW lock lines after cutover timestamp.

- [ ] **Step 10: Stamp `migration_complete` (arms the wipe-guard forever)**

Once the live smoke passes, mark this Postgres as production so `migrate.sh` can never wipe it again (Guard A), even with `FORCE_WIPE=1`.
Run:
```bash
export ROUNDCUBE_PG_PASSWORD=<prod-pw>
PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/pg-oneshot.sh 5 avuz-mail-roundcube_default postgres:16-alpine - \
  "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql 'postgresql://roundcube:$ROUNDCUBE_PG_PASSWORD@postgres:5432/roundcube' -v ON_ERROR_STOP=1 -c \"CREATE TABLE IF NOT EXISTS migration_complete (completed_at timestamptz NOT NULL DEFAULT now(), note text); INSERT INTO migration_complete(note) VALUES ('sqlite->pg cutover');\" && echo stamped"
```
Expected: `stamped`. (Harmless extra table; Roundcube ignores it.)

- [ ] **Step 11: Baseline pg_dump (day-2 recovery artifact)**

Run:
```bash
export ROUNDCUBE_PG_PASSWORD=<prod-pw>
PORTAINER_ENV_FILE=scripts/deploy.prod.env scripts/migrate/pg-oneshot.sh 5 avuz-mail-roundcube_default postgres:16-alpine - \
  "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' pg_dump 'postgresql://roundcube:$ROUNDCUBE_PG_PASSWORD@postgres:5432/roundcube'" > prod-roundcube-postcutover.sql
ls -la prod-roundcube-postcutover.sql
```
Expected: a non-empty SQL dump saved locally as the recovery baseline.

---

## Notes for the executor

- **`stack_prefix` vs container names:** Docker Compose/Portainer name the network `<stack>_default`, volumes `<stack>_<volname>`, containers `<stack>-<service>-1`. Verify the exact volume name once with `docker volume ls` via a one-shot (`pg-oneshot.sh <eid> <net> postgres:16-alpine - 'echo'` then inspect) if `migrate.sh`'s pgloader step can't find `/data/roundcube.db`.
- **pgloader `on error stop`** means a single bad row aborts the load — that's intentional; fix the row (pre-scan) and re-run.
- **`migrate.sh` wipe-guard:** it drops+recreates the schema, so it refuses to run against a populated target unless `FORCE_WIPE=1`, and refuses ALWAYS (even with `FORCE_WIPE`) once `migration_complete` exists. During rehearsal iterations, either run Task 10 Step 6 teardown between attempts (leaves an empty target — no flag needed) or re-run with `FORCE_WIPE=1 scripts/migrate/migrate.sh ...`. The prod cutover load (Task 11 Step 5) runs against an empty target (rehearsal torn down), so it needs no flag; Step 10 then stamps `migration_complete` and the DB can never be wiped again.
- **Rollback is forward-only after users write** (see spec). The pre-reopen verify gate (Step 6) is the last clean SQLite rollback point.
