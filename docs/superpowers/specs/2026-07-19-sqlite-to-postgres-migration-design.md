# SQLite → Postgres Migration — Design

**Date:** 2026-07-19
**Status:** Approved (design), pending implementation plan
**Scope:** staging `avuz-mail-roundcube-2` (endpoint 3), then prod `avuz-mail-roundcube` (endpoint 5). Endpoint 9 explicitly out of scope.

## Problem

Roundcube's per-message cache (`messages_cache`, table `cache_messages`) is **DB-only** — `rcube_imap_cache` is hardwired to the SQL handle (`rcube_imap.php:4375`); the config value `'redis'` is ignored (only its truthiness matters, `rcube.php:397`). With the DB on SQLite, every message view/flag-change writes `cache_messages`, and SQLite's whole-file write lock serializes those writes under multiple PHP-FPM workers + `avuz_prefetch` (up to 30 bodies/page). Result: a storm of `database is locked` errors (617+ in staging logs; prod similar), dropped writes, and degraded UX.

SQLite is fundamentally unsuited to a multi-worker FPM app. Moving the database to Postgres (concurrent writers, row-level locking) removes the lock storm at the source and lets `messages_cache` work as intended.

### What this is NOT
- Not the blank-signature fix (that was `rcube_washtml.php` base64 padding, already shipped).
- Not a Redis change. `session_storage=redis`, `imap_cache=redis`, and the `avuz_prefetch` body cache stay in Redis. `avuz_prefetch` is complementary, not redundant: `messages_cache` caches headers + MIME bodystructure and **strips the body**; `avuz_prefetch` caches the body. Both remain.

## Current state (live inventory, 2026-07-19)

`ROUNDCUBE_DB_DSN` is empty on both environments → default SQLite at `/var/www/roundcube/temp/roundcube.db`.

| Table | Staging rows | Prod rows | Migrate? |
|-------|-------------:|----------:|----------|
| users | 4 | 93 | **yes** |
| identities | 4 | 94 | **yes** |
| contacts | 0 | 11555 | **yes** |
| contactgroups | 0 | 2 | **yes** |
| contactgroupmembers | 0 | 6 | **yes** |
| collected_addresses | 17 | 333 | **yes** |
| responses | 0 | 4 | **yes** |
| cache, cache_index, cache_messages, cache_shared, cache_thread | — | — | no (disposable, refills from IMAP) |
| session | 28987 | 29482 | no (now in Redis) |
| filestore, dictionary, searches | 0 | 0 | no |

Real prod payload ≈ **12,000 rows across 7 tables**. The 321 MB prod DB is ~99% disposable session/cache bloat.

### Why this is low-risk
- **No passwords in the DB.** Roundcube keeps the IMAP password in the (Redis) session, encrypted with `des_key` — never in `users`. Nothing password-shaped to break; users re-auth via SSO.
- **Cache is discarded, not migrated.** Postgres starts with empty cache tables; Roundcube refills from IMAP on first use. No stale-cache risk.
- **SQLite is never modified.** The migration only reads SQLite. A clean SQLite rollback exists in the pre-write window right after cutover; past that, recovery is forward-on-Postgres + `pg_dump` (see Rollback). Confidence comes from the prod-data rehearsal, not from an indefinite fallback.

## Architecture

Add an **in-stack `postgres` service**, mirroring the existing in-stack `redis`:

- Image: `postgres:16-alpine`
- Volume: `roundcube_pg:/var/lib/postgresql/data`
- Network: the stack's `..._default` (roundcube reaches it at host `postgres`)
- Credentials from stack env: `POSTGRES_USER=roundcube`, `POSTGRES_DB=roundcube`, `POSTGRES_PASSWORD=$ROUNDCUBE_PG_PASSWORD` (new secret, not reused)
- Roundcube: `ROUNDCUBE_DB_DSN=pgsql://roundcube:$ROUNDCUBE_PG_PASSWORD@postgres/roundcube`

`config/config.inc.php` already reads `ROUNDCUBE_DB_DSN` from env, so no code change there. `deploy/stack.reference.yml` is updated to add the service, volume, env, and `depends_on`.

Rationale for in-stack (vs one shared Postgres): matches the established per-stack self-contained pattern (each stack already owns its `redis`), keeps blast radius to one stack, no new shared dependency or cross-stack network wiring.

## Migration procedure (per stack, in a maintenance window)

1. **Backup** — copy `/var/www/roundcube/temp/roundcube.db` out of the volume to a safe host path (timestamped). This is the rollback artifact.
2. **Bring up `postgres`** — deploy the stack with the new service; wait healthy.
3. **Init schema** — load Roundcube's official `SQL/postgres.initial.sql` into the `roundcube` database (correct types, sequences, indexes, matching what Roundcube expects). Run once against the empty DB.
4. **Stop `roundcube`** — writes stop; users briefly offline.
5. **Copy data** — run `pgloader` (throwaway container) as a **data-only** load of exactly the 7 user tables SQLite→Postgres. pgloader handles vcard blobs, serialized-PHP preference text, and bool/timestamp/NULL conversion.
6. **Reset sequences (explicit)** — for every table with a serial PK, run `SELECT setval(pg_get_serial_sequence('<table>','<idcol>'), COALESCE(MAX(<idcol>),0)+1, false) FROM <table>;`. Do not rely on pgloader to do this in data-only mode. Covers `users.user_id`, `identities.identity_id`, `contacts.contact_id`, `contactgroups.contactgroup_id`, `collected_addresses.collected_address_id`, `responses.response_id`.
7. **Flip config** — set `ROUNDCUBE_DB_DSN` to the Postgres DSN; set `messages_cache='db'` (now safe on a concurrent DB — regains header/bodystructure caching without the lock storm). Update the config comment that currently says `'redis'`.
8. **Restart `roundcube`** — comes up on Postgres.
9. **Run the verification gate** (below) immediately, before announcing availability — this is the last clean SQLite-rollback point.
10. **Baseline `pg_dump`** — once verified, capture a `pg_dump` as the day-2+ recovery artifact.

## Rehearsal (prod-data dry run) — the primary risk control

Staging has only 4 clean users; it cannot surface what prod's ~12k rows will (odd encodings, malformed vcards, dangling group members, oversized signatures). So before any live prod cutover, rehearse the **exact** migration against a **copy of real prod data**, with zero user impact:

1. **Copy prod `roundcube.db`** out to a scratch host (the file copies safely while prod runs; this doubles as the pre-cutover backup).
2. **Run the full procedure** (schema init → pgloader → sequence reset) against that copy into a **throwaway** Postgres. Repeat until clean.
3. **Pre-scan for encoding gremlins**: check every text column of the prod SQLite copy for invalid UTF-8 and list offending rows *before* migrating; decide fix vs drop per row.
4. Run the full **content-integrity verification** (below) against the rehearsal Postgres.

Only when a rehearsal passes cleanly does the real prod cutover proceed — it is then a re-run of the identical, already-proven procedure inside the window.

## Verification (gate — must pass before declaring done)

Row counts alone do NOT prove correctness; verify content:

- **Row parity**: each migrated table's Postgres count equals the source SQLite count (prod: users 93, identities 94, contacts 11555, contactgroups 2, contactgroupmembers 6, collected_addresses 333, responses 4).
- **Signature fidelity**: per-row md5 of `identities.signature` matches SQLite vs Postgres (byte-for-byte proof signatures survived, incl. embedded base64 images).
- **Contact fidelity**: `contacts.vcard` checksums match; non-null vcard count matches.
- **Preferences deserialize**: every `users.preferences` value still `unserialize()`s in PHP (no truncation/encoding damage to the serialized blob).
- **FK integrity**: zero `contactgroupmembers` referencing a missing contact or group (Postgres FKs reject these; SQLite silently allowed them — pre-scan and resolve).
- **pgloader summary**: rows-read == rows-imported for all 7 tables; zero rejected rows in the pgloader log.
- **Sequences**: creating a new identity/contact assigns a fresh id with no primary-key collision.
- **Live smoke**: a real user logs in via SSO, their HTML signature (with image) renders, autocomplete shows addresses.
- **Logs**: `errors.log` shows no `database is locked` and no DB errors after cutover.

## Rollback (forward-only, short window) + disaster recovery

**Rollback to SQLite is a first-minutes-only option.** The moment Postgres takes live writes (a saved signature, a collected address), those writes exist *only* in Postgres; reverting the DSN to the untouched SQLite would silently lose them. There is no reverse-migration (rejected as YAGNI — it trusts a second lossy conversion and hedges a scenario that `pg_dump` covers better).

Therefore:
1. **Point of no return**: immediately after cutover, run the verification gate. If it fails, revert `ROUNDCUBE_DB_DSN` to SQLite + restore `messages_cache`, redeploy — no data lost because no user has written yet. This is the only clean SQLite rollback.
2. **After users are writing**: do not roll back to SQLite. Fix forward on Postgres.
3. **Disaster recovery** (day-2+ safety net): a `pg_dump` taken right after cutover and on a schedule. Any later "restore" means restoring Postgres from a `pg_dump`, never round-tripping to SQLite.

The rehearsal is what makes the short rollback window acceptable: prod-shaped data is proven to migrate cleanly *before* the window opens.

## Rollout order

1. **Staging** (`avuz-mail-roundcube-2`) end-to-end: prove the procedure + verification tooling on real (small) data.
2. **Prod rehearsal**: run the full migration against a copy of prod's SQLite offline; pass the content-integrity gate. Iterate until clean.
3. **Prod cutover** (`avuz-mail-roundcube`, endpoint 5) in a scheduled window: re-run the proven procedure, pass the gate live.

## Postgres operations (running it in a container)

Postgres-in-a-container is fine at this scale (~100 users, small DB); container overhead is negligible and bare metal would add a machine to manage without removing any of the real risks below. Keep it in the stack. The risks are operational, not the container itself:

- **Backups — VM snapshots are not sufficient alone.** A filesystem/VM snapshot of a *running* Postgres is only safe if crash-consistent, and can never restore a single table/user. Add a **scheduled logical `pg_dump`** (consistent, portable, granular) — the actual restore artifact. VM snapshot = whole-machine DR; `pg_dump` = the safety net you restore from. Baseline `pg_dump` is taken at cutover (migration procedure step 10); automate a daily one as a follow-up.
- **Accidental volume deletion.** "Remove stack" (with volumes) or `docker volume prune` wipes `roundcube_pg` instantly; the `migration_complete` marker guards re-migration, not the volume. Operational rule: never prune volumes on this host; recovery is `pg_dump` + snapshot.
- **Disk full = hard outage.** A full host disk stops Postgres writes → roundcube login/identities/contacts fail. Monitor the volume's disk space + alert.
- **Major-version upgrades are not a tag bump.** `postgres:16` → `17` needs `pg_upgrade` or dump/restore (data dir is version-specific). Image is pinned to `16-alpine`; never let it drift to another major without a planned migration.
- **SPOF / HA.** One container, one host — same failure profile as the SQLite it replaces (and roundcube is single-instance too), so no regression. Real HA (replication/failover) is a separate later project; recovery here is snapshot + `pg_dump`.
- **Resource/connection tuning.** Default alpine config is modest (`max_connections=100`, small `shared_buffers`). Fine now; watch connection count once the planned filter daemon adds persistent connections. Light tuning (`shared_buffers`, `work_mem`) and host-RAM headroom suffice; add pgbouncer only if connections grow.

**Minimum ops to add:** scheduled `pg_dump`, disk-space monitoring/alert, pinned major version (done), and a documented "don't delete the volume" rule.

## Out of scope

- Endpoint 9's `avuz-mail-roundcube` stack.
- Zero-downtime cutover (unjustified for ~12k rows of largely static data).
- Reverse migration Postgres→SQLite (rejected: trusts a second lossy conversion; `pg_dump` is the correct day-2 recovery artifact).
- Changing Redis usage (sessions, imap_cache, avuz_prefetch stay as-is).
- Postgres HA/replication and a recurring backup schedule (a one-off post-cutover `pg_dump` IS in scope as the DR baseline; automating cadence is a follow-up).

## Open questions

None blocking. Automating `pg_dump` cadence + monitoring is a sensible follow-up task, tracked separately.
