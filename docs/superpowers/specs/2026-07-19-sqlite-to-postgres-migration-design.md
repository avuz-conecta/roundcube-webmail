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
- **SQLite is never modified.** The migration reads SQLite and writes Postgres. Rollback = point the DSN back.

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

## Verification (gate — must pass before declaring done)

- **Row parity**: each migrated table's Postgres count equals the pre-migration SQLite count (users 93, identities 94, contacts 11555, contactgroups 2, contactgroupmembers 6, collected_addresses 333, responses 4 on prod).
- **Login**: a real user logs in via SSO.
- **Signature renders**: a known user's HTML signature (with embedded image) displays — end-to-end proof identities migrated intact.
- **Autocomplete**: composing shows collected/contact addresses.
- **Sequences**: creating a new identity/contact assigns a fresh id with no primary-key collision.
- **Logs**: `errors.log` shows no `database is locked` and no DB errors after cutover.

## Rollback

SQLite volume is left intact and unmodified. If verification fails or issues appear:
1. Revert `ROUNDCUBE_DB_DSN` to the SQLite default and `messages_cache` to its prior value.
2. Redeploy the stack.
3. Roundcube is back on SQLite exactly as before. Postgres data is discarded.

Zero data loss because SQLite was read-only throughout.

## Rollout order

1. **Staging** (`avuz-mail-roundcube-2`) end-to-end: run the full procedure + verification, prove the pgloader mapping and sequence reset on real (small) data.
2. **Prod** (`avuz-mail-roundcube`, endpoint 5) in a scheduled window, same procedure, same verification.

## Out of scope

- Endpoint 9's `avuz-mail-roundcube` stack.
- Zero-downtime cutover (unjustified for ~12k rows of largely static data).
- Changing Redis usage (sessions, imap_cache, avuz_prefetch stay as-is).
- Ongoing Postgres backups/HA (worth a follow-up, not this migration).

## Open questions

None blocking. Postgres backup cadence and monitoring are a sensible follow-up task, tracked separately.
