# Avuz Filters (in-session mail filters for Zoho) — Design

**Date:** 2026-07-19
**Status:** Approved (design), pending implementation plan
**Depends on:** the SQLite→Postgres migration (rules + state live in Postgres). See `2026-07-19-sqlite-to-postgres-migration-design.md`.

## Problem

Clients migrating to Avuz (Zoho backend) expect the email **Filters** they had on their previous provider — auto-sorting incoming mail into folders, marking read, deleting/spam. Roundcube's built-in Filters plugin speaks **ManageSieve**, and **Zoho offers neither a ManageSieve server nor a filter API** (verified against Zoho's full Mail API index — 16 modules, none for filters/rules/sieve; the community "sieve support" thread is an unanswered feature request). Zoho *has* filters, but only in its own web UI — not manageable from our product or any protocol, and we cannot push rules into Zoho's engine (no API).

So we build filtering ourselves, inside Roundcube.

## Decision log (from brainstorming + grilling)

The design was pushed hard on two axes and landed here deliberately:

1. **No credential vault.** A background daemon would need to store every user's mailbox password (decryptable, always-on, network-reachable) — a severe security downgrade (compromise = full IMAP access to all mailboxes). **Rejected.** Filters run **in-session**, using the user's *own* live IMAP connection — **no passwords are ever stored.**
2. **In-session, Roundcube-only, accepted for V1.** Only Zoho's own server-side filters are truly universal (delivery-time, all clients). Anything we build is post-delivery and Roundcube-scoped. In-session filtering **only runs while the user is active in Roundcube** — so users who live in Outlook/phone are NOT filtered in V1. Client's mix is ~80% Roundcube-primary / ~20% Outlook; V1 ships for the majority, non-Roundcube coverage is a future iteration.

### Added after initial design (client requirement)
- **Redirect action** ("Redirecionar mensagem para <address>"): resend the original message untouched to another address (Sieve `redirect` semantics — recipient sees the original sender). Sends via Roundcube's native resend/bounce machinery using the user's in-session SMTP (no stored creds). **Loop guard:** stamps `X-Avuz-Forwarded` and skips any message already carrying it. Not terminal — coexists with move/mark. Caveat: for external senders, redirect can fail SPF/DMARC at the target (deliverability risk); fine for internal same-domain. A "forward as new message" mode may be added later if SPF issues surface.

### Non-goals (V1)
- No background daemon, no stored credentials, no offline/other-client filtering.
- No outgoing filters, vacation/auto-reply, regex conditions.
- Not a replacement for Zoho's delivery-time filtering (users should pick one, not both — see Conflicts).

## How it works

A Roundcube plugin (`avuz_filters`) applies user-defined rules to the user's **INBOX** using the **user's already-authenticated IMAP session**, triggered while they use webmail. Rules and per-folder progress live in Postgres. Nothing runs when the user is away.

### Trigger points (all in-request, using the live session)
- **On login** (`login_after`) — apply pending rules so the inbox is sorted on first view.
- **On refresh** — Roundcube's periodic check-recent (~60s) and the manual refresh button both fire the filter pass **before** the message list is returned, so a watching user sees mail already sorted (no visible wrong-folder window).

### Execution (one pass)
1. Read the user's enabled rules from `avuz_filters` (ordered).
2. From `avuz_filter_state`, get `last_uid` for INBOX; fetch INBOX messages with `UID > last_uid` (headers only: from/to/cc/subject).
3. For each new message, evaluate rules in order; apply the **first matching** rule's actions (Sieve-like first-match).
4. Advance `last_uid` to the highest processed UID.
5. **Bounded work:** cap **1,000 messages per pass**; a larger backlog drains across subsequent refreshes. Also wrap the pass in a short time budget — whichever limit (1,000 msgs or the time budget) hits first commits progress (`last_uid`) and continues on the next trigger, so page loads never stall.

### Actions (V1, pure IMAP on the live session)
- **Move to folder** — `UID MOVE` (or `COPY` + `\Deleted` + `EXPUNGE` fallback).
- **Mark read** — `+FLAGS \Seen`.
- **Flag** — `+FLAGS \Flagged`.
- **Delete/spam** — move to Trash/Junk (never hard-expunge — safer, recoverable).

### Conditions (V1)
`from | to | cc | subject` × `contains | is`, combined per rule as **match all (AND)** or **match any (OR)**.

## Storage (Postgres, in the roundcube DB)

- **`avuz_filters`** — `id, user_id, name, enabled, match ('all'|'any'), priority, conditions (jsonb), actions (jsonb), created_at, updated_at`.
  - `conditions`: `[{field:'from'|'to'|'cc'|'subject', op:'contains'|'is', value:text}]`
  - `actions`: `[{type:'move', folder:text} | {type:'mark_read'} | {type:'flag'} | {type:'delete'}]`
- **`avuz_filter_state`** — `user_id, folder, last_uid, uidvalidity, last_run_at` (pk `user_id, folder`). Guarantees once-only processing; new mail = `UID > last_uid`.

Avuz-only tables, created by an idempotent migration on plugin boot. **No credential table.**

## UI

- A Settings page **"Filtros"** — CRUD rules (list, conditions, actions, enable/disable, reorder). Modeled on the managesieve UI shape but backed by our tables.
- **"Apply to existing inbox now"** button — runs the ruleset against current INBOX once (ignores `last_uid` for that pass), so a newly created rule can sort mail already sitting there. Default behavior is forward-only (new mail).
- **Conflict notice** — a visible note: "Manage filters here OR in Zoho's web UI, not both, to avoid double-filtering." (We cannot detect Zoho-side rules.)
- `managesieve` is **removed** from `$config['plugins']` (its Filters menu + connection error disappear).

## Security

Dramatically smaller than the rejected daemon: **no stored passwords, no new always-on service, no new network-reachable secret store.** The plugin only ever uses the connection the user already authenticated. Compromise surface is unchanged from today's Roundcube.

## Conflicts & correctness

- **Double-filtering:** if a user also sets Zoho web filters, both act on the mailbox. We can't see Zoho's rules; mitigated by the UI notice (pick one).
- **Post-delivery reality:** mail lands in INBOX first (notification fires), then moves on the next in-session pass. Inherent to any non-delivery-time approach; unavoidable without a Zoho filter API.
- **UIDVALIDITY change / mailbox reset:** if INBOX `uidvalidity` differs from stored, reset `last_uid` (process forward from current) rather than mis-skip or reprocess everything.
- **Idempotency:** actions are naturally idempotent — a moved message leaves INBOX, so it won't be reprocessed; `last_uid` prevents re-scan.
- **Never block the UI:** the pass is bounded + time-budgeted; if it errors, the message list still renders (filtering degrades, mail is never lost).

## Rollout

1. Build on a **separate image tag** (e.g. `:filters`), never touching prod `:latest` until proven.
2. Validate end-to-end on **staging** (Postgres already live there).
3. Ship to prod **after** the prod Postgres cutover (feature depends on Postgres).

## Future (post-V1, out of scope now)

- Coverage for Outlook/phone-primary users (would require the delivery-time/daemon approach we rejected on security grounds — revisit with a safer credential model, e.g. per-user OAuth XOAUTH2 if Zoho ever supports it).
- Forwarding, more conditions/operators, additional folders beyond INBOX.

## Resolved decisions

- **Per-pass cap = 1,000 messages** (plus a time budget as a secondary guard).
- **First-match** — a message gets the first matching rule's actions only (Sieve-like).
- **INBOX-only** for V1.

## Open questions

None blocking. Time-budget seconds value to tune on staging.
