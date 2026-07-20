# Avuz Filters (server-side mail filters for Zoho) — Design

**Date:** 2026-07-19
**Status:** Approved (design), pending implementation plan
**Depends on:** the SQLite→Postgres migration (rules/creds/state live in Postgres). See `2026-07-19-sqlite-to-postgres-migration-design.md`.

## Problem

Clients migrating to Avuz (Zoho backend) expect the email **Filters** they had on their previous provider — auto-sorting incoming mail into folders, marking read, deleting/spam. Roundcube's built-in Filters plugin speaks **ManageSieve**, and **Zoho offers neither a ManageSieve server nor a filter API** (verified against Zoho's full Mail API index — 16 modules, none for filters/rules/sieve; the community "sieve support" thread is an unanswered feature request). Zoho *has* filters, but only in its own web UI — not manageable from our product or any protocol.

A client has this as a hard requirement. So we build filtering ourselves: manage rules in Roundcube, apply them to the user's Zoho mailbox over IMAP.

### Non-goals (v1)
- Forwarding/redirect actions (needs SMTP send + loop protection) — excluded.
- Outgoing filters, vacation/auto-reply, regex conditions — later.
- Replacing Zoho's own delivery-time filtering — we run our own, post-delivery.

## Decisions (from brainstorming)

- **Execution: background daemon** (server-side-like) — applies rules even when the user is offline, matching the old provider. Not a Roundcube-only in-session applier.
- **Credentials: capture-on-login.** When a user logs into Roundcube (SSO already carries their IMAP password), store it AES-encrypted in Postgres with the existing `ROUNDCUBE_CREDENTIAL_KEY`. The daemon decrypts to connect. Only users who've logged in at least once are filtered (acceptable).
- **Trigger: hybrid.** Poll (~90s) sweeps all users when offline; when a user is active (login / inbox refresh), the plugin **pokes the daemon to process that user immediately, before the list renders**, so a watching user never sees mail linger in the wrong folder. Manual refresh button also triggers it. Single rule engine (in the daemon) — the plugin never re-implements rule logic.
- **Actions v1: move-to-folder, mark read, flag, delete/spam.** All pure IMAP (no SMTP).
- **Conditions v1:** `from | to | cc | subject` × `contains | is`, combined `match all (AND)` or `match any (OR)`.
- **Deployment: separate image tag.** Built and deployed on a distinct tag (e.g. `:filters`), never touching prod `:latest` until proven on staging.

## Architecture

Three pieces, mirroring the existing password-broker pattern.

### 1. Postgres storage (in the roundcube DB)

- **`avuz_filters`** — one row per rule: `id, user_id, name, enabled, match ('all'|'any'), priority, conditions (jsonb), actions (jsonb), created_at, updated_at`.
  - `conditions`: `[{field:'from'|'to'|'cc'|'subject', op:'contains'|'is', value:text}]`
  - `actions`: `[{type:'move', folder:text} | {type:'mark_read'} | {type:'flag'} | {type:'delete'}]`
- **`avuz_filter_creds`** — `user_id (pk), imap_user, enc_password (bytea, AES-256 via ROUNDCUBE_CREDENTIAL_KEY), updated_at`.
- **`avuz_filter_state`** — `user_id, folder, last_uid, last_run_at` (pk `user_id, folder`). Ensures each message is processed once; the daemon only fetches `UID > last_uid`.

These are Avuz-only tables (not Roundcube core), created by an idempotent migration the plugin/daemon runs on boot.

### 2. Roundcube plugin `avuz_filters`

- **Filters UI** — a Settings page ("Filtros") to CRUD rules → `avuz_filters`. Modeled on the managesieve UI shape (rule list, conditions, actions) but backed by our tables. Replaces the managesieve menu; **`managesieve` is removed from `$config['plugins']`**.
- **Capture-on-login** — on the `login_after` hook, encrypt the session IMAP password with `ROUNDCUBE_CREDENTIAL_KEY` and upsert into `avuz_filter_creds`.
- **On-demand trigger** — on `new_messages` / `refresh` (and the manual refresh action), fire an async `POST {daemon}/process/{user_id}` (shared-secret header) *before* the message list is returned, so the active user's inbox is sorted first. Best-effort + short timeout — never block the UI if the daemon is slow/down.
- Config: `avuz_filter_worker_url`, `avuz_filter_worker_secret` (env, like the broker pair).

### 3. Daemon `avuz-filter-worker` (Node/TypeScript, its own image)

- **Poll loop** (interval ~90s): for each user in `avuz_filter_creds` who has ≥1 enabled rule → connect to Zoho IMAP (decrypt password) → `SELECT` new messages `UID > last_uid` in INBOX → for each, evaluate rules (headers only: from/to/cc/subject) → apply the first matching rule's actions → advance `last_uid`.
- **On-demand endpoint** `POST /process/:user_id` (shared-secret) → run one user's pass immediately; returns fast.
- **Rule engine** (the single source of truth): match conditions (AND/OR), then actions in order. Stops at first matching rule (Sieve-like) unless we later add "continue".
- **IMAP actions:** move = `UID MOVE` (or `COPY`+`\Deleted`+`EXPUNGE` fallback), mark read = `+FLAGS \Seen`, flag = `+FLAGS \Flagged`, delete = move to Trash (safer than hard-expunge).
- **Auth to Zoho:** plaintext IMAP LOGIN over TLS (or via the imapproxy sidecar). Creds decrypted per run, never logged.
- **Concurrency:** bounded worker pool; respects Zoho per-account connection limits (short-lived connections, not persistent IDLE).

### Data flow

```
Login → plugin stores enc IMAP password (avuz_filter_creds)
User edits rules → avuz_filters (Postgres)

Active user refreshes inbox
  → plugin POST /process/{user} → daemon runs that user now → inbox sorted → list renders

Offline users
  → daemon poll (~90s) → per user: fetch UID>last_uid → apply rules → advance last_uid
```

## Security

- Daemon holds **encrypted** mail passwords for logged-in users only; decrypt in-memory per run, never persist plaintext, never log creds.
- On-demand endpoint behind a shared secret (`avuz_filter_worker_secret`), no public ports (internal stack network only, like `broker`).
- Encryption reuses `ROUNDCUBE_CREDENTIAL_KEY` (already shared with the Nextcloud app).
- Adds a persistent DB + IMAP connection footprint — factored into the Postgres connection budget (see migration spec's ops section).

## Failure modes

- **Daemon down:** rules simply aren't applied; mail stays in INBOX. Plugin's on-demand poke is best-effort (short timeout) so the UI is unaffected. No data loss.
- **Bad/stale creds (user changed password):** IMAP login fails → skip that user, log a warning, surface a "re-login needed" state; capture-on-login refreshes creds next login.
- **last_uid drift / mailbox reset:** if UIDVALIDITY changes, reset `last_uid` for that folder (reprocess from current) rather than mis-skip.
- **Rule loops / repeated processing:** `last_uid` guarantees once-only; actions are idempotent (moving an already-moved message is a no-op since it's no longer in INBOX).

## Rollout

1. Build on a **separate tag** (`:filters`); add `avuz-filter-worker` to the stack alongside `broker`.
2. Validate end-to-end on **staging** (Postgres already live there).
3. Ship to prod **after** the prod Postgres cutover (the feature depends on Postgres).

## Open questions

- Poll interval default (90s proposed) — tune against Zoho connection limits during staging.
- Do we also process folders other than INBOX in v1? (Proposed: INBOX only.)
- "Stop after first match" vs "apply all matching rules" — proposed first-match (Sieve-like); confirm during planning.
