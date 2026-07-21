# Roundcube Search & Interaction Latency — Design

**Date**: 2026-07-20
**Branch**: avuz-customization
**Status**: DESIGN — awaiting approval
**Supersedes**: `2026-06-30-roundcube-latency-design.md` (that doc covered connection
setup only; imapproxy shipped and is deployed. This doc covers what remains.)

## Problem

Client reports, verbatim:

- Lentidão imensa na procura de e-mails
- Lentidão no envio e troca de pastas
- Lentidão para abrir e-mails

## Evidence

Measured 2026-07-20, prod (`avuz-mail-roundcube-*`, Portainer endpoint 5).

Network, from the Roundcube host to Zoho:

```
tcp=205ms  tls=210ms  greet=199ms  cmdRTT=198ms  TOTAL=812ms
```

RTT is **198ms** — the previous design doc assumed 130ms, so every round-trip
estimate in it understates by ~50%. Cold connect to first usable command: **820ms**.

Browser waterfall, real account, search term `PBL`:

| Search | Scope | Search in | Time | Response |
|---|---|---|---|---|
| A | this folder | subject | 2.20s | 80.7 kB |
| B | this folder | entire message | 2.94s | 80.7 kB |
| C | **all folders** | subject | **12.82s** | **2.3 kB** |

Zoho's advertised IMAP capabilities:

```
IMAP4rev1 UNSELECT CHILDREN XLIST NAMESPACE IDLE MOVE ID AUTH=PLAIN
SASL-IR AUTH=XOAUTH2 UIDPLUS ESEARCH LIST-EXTENDED LIST-STATUS WITHIN
LITERAL- ACL CONDSTORE
```

Present and useful: `CONDSTORE`, `IDLE`, `ESEARCH`, `LIST-STATUS`, `MOVE`, `UIDPLUS`,
`AUTH=XOAUTH2`. **Absent: `SORT`, `THREAD`, `QRESYNC`, `COMPRESS=DEFLATE`.**

User sort preferences, prod (97 users):

| `message_sort_col` | Users |
|---|---|
| (unset — index order) | 85 |
| `arrival` | 11 |
| `date` | 1 |

## Root cause per symptom

### Slow search — the dominant problem

`rcube_imap.php` `search()` routes a multi-folder query to
`rcube_imap_search->exec()`, which issues `SELECT` + `SEARCH` **per folder,
serially**. At 198ms per round trip and ~20 folders, that is the observed 12.82s.
The 2.3 kB response confirms almost none of that time was data transfer — it is
round-trip count, nothing else.

Two consequences:

- Cost scales linearly with folder count. Accounts with many folders degrade further.
- `$searcher->set_timelimit(60)` aborts at 60s and returns an **incomplete** result
  set. Large accounts can receive silently partial search results.

Single-folder search (A, 2.20s) is the same tax at N=1 plus connection warm-up.

**Body search is not implicated.** B ≈ A, so Zoho executes `SEARCH TEXT` server-side
at acceptable cost. No full-text-specific work is required.

### Slow folder switch

Two contributors:

1. `imapproxy.conf:8` sets `cache_expiration_time 60`. Idle backend connections are
   reaped after 60 seconds — shorter than a normal reading pause. The next action
   re-pays the full 820ms TCP+TLS+LOGIN. The proxy is warming far less than intended.
2. `imapproxy.conf:9` sets `enable_select_cache no`, so every folder switch pays a
   full `SELECT` round trip. This was a deliberate correctness choice (the cache
   serves stale counts and hides new mail) and is **not** revisited here.

### Slow open

Largely already solved by `plugins/avuz_prefetch`. Its effectiveness is undermined by
`deploy/stack.reference.yml:46` — Redis is capped at `128mb` with `allkeys-lru`,
shared across sessions, `imap_cache`, and every prefetched body. Bodies are the
largest entries and are evicted first under pressure, silently degrading the cache.

### Slow send

`config/config.inc.php:38` sends directly to `tls://smtp.zoho.com:587`. There is no
proxy or pooling on the SMTP path, so every send pays a fresh TLS handshake plus
`AUTH` before `DATA`. Nothing in the repo addresses this today.

### Aggravator: filter passes on every refresh

`plugins/avuz_filters/avuz_filters.php:22-24` hooks `login_after`, `new_messages`
**and** `refresh`. A bounded 1000-message INBOX pass therefore runs every 60 seconds
on the user's live IMAP session, competing for the same connections that interactive
actions need.

### Narrow issue: the missing SORT capability

Zoho advertises no `SORT`. When a sort column is set, `rcube_imap.php:1526` falls
through to `rcube_imap_generic::index()` → `fetchHeaderIndex()`, a FETCH of the sort
header across the **entire** message set, sorted in PHP. `set_sort_order()` does not
normalize `arrival` to index order, so `arrival` takes this path despite being
equivalent to it.

This affects **12 of 97 users**. It is a real defect but not the general cause, and
the design treats it accordingly.

## Rejected alternatives

**Relocating the container to a US region near Zoho.** Rejected by product decision.
Cutting the Zoho RTT would push every *cached* read — Redis bodies, Postgres
`messages_cache`, sessions — across the Atlantic to Brazilian users. Cache hits are
the common case; misses are the exception. Net loss.

**Zoho Mail REST API as a mailbox backend.** Published limit is 30 requests/minute,
with the scope (per mailbox? per OAuth client? per org?) undocumented. No
changes-since token or modseq equivalent on the list endpoint. Webhooks exist but are
configured per user through the Zoho settings UI with no provisioning API. IMAP with
CONDSTORE is a strictly better incremental-sync primitive. Closed.

**Migrating to another webmail.** Grommunio, Stalwart, and Zimbra/Carbonio are mail
*stores* — they own the mailbox and cannot front an external Zoho account.
Disqualified by constraint. SOGo is a viable IMAP client but caches no mail at all.
Nextcloud Mail is the only structural improvement (Postgres envelope cache, cron
background sync, native embedding) but caches **headers only, no bodies, no
full-text index**, so body search still round-trips to Zoho — it does not fix the
worst symptom, and migrating discards `avuz_filters`, `avuz_prefetch`, the washtml
fix, and the imapproxy work.

**Adopting an existing local-sync webmail.** The category is empty. Mailpile is
frozen, Nylas sync-engine dead since 2017 (its repo carries a misleading recent
tombstone commit), Cypht / Alps / SnappyMail / SOGo are all live-IMAP proxies. No
maintained, multi-user, server-hosted webmail syncs to local storage with a local
index.

**Dovecot `imapc` or `mbsync` mirroring into local Dovecot.** `imapc` caches indexes
and metadata but **not bodies**, so it would not fix message open. `doveadm backup`
against `imapc:` is documented as one-time migration, not continuous replication, and
has no supported write-back path. `mbsync` and Dovecot both claim ownership of
Maildir UID state, a documented duplicate-Sent failure mode. Rejected as off-label
with high blast radius against real customer mailboxes.

**nginx mail proxy for connection pooling.** nginx does 1:1 TCP proxying and does not
pool. Upstream rejected pooling as infeasible for a stateful protocol.

## Design

Three waves, sequenced by cost and by the client's stated priority (search first).

### Wave 1 — configuration

Reversible, no new services, no schema.

| Change | Where | From → To |
|---|---|---|
| Idle connection lifetime | `docker/imapproxy-sidecar/imapproxy.conf:8` | `60` → `1800` |
| Redis ceiling | `deploy/stack.reference.yml:46` | `128mb` → `512mb` (starting value, see below) |
| Filter pass off refresh | `plugins/avuz_filters/avuz_filters.php:22-24` | drop `refresh` hook; keep `login_after` + `new_messages` |
| Response compression | `docker/nginx.conf` | add gzip for HTML/JS/CSS/JSON |
| Sort normalization | one-off SQL migration over `users.preferences` | unset `message_sort_col` where it is `arrival` |

`512mb` is a starting value, not a measured one. The correct figure depends on
`evicted_keys` and body size under real load — measure with `redis-cli INFO stats`
before and after, and raise further if evictions persist. Splitting prefetched bodies
onto a second Redis instance is the alternative if sizing proves hard to bound.

`cache_expiration_time 1800` keeps a connection alive across normal reading pauses.
The ceiling is `cache_size 200` concurrent cached connections; with 97 users this is
within budget, but connection count against Zoho must be watched after rollout (see
Open questions).

Sort normalization: `arrival` is semantically identical to IMAP index order, so
unsetting it avoids the `fetchHeaderIndex` path at zero behavioral cost. This is a
one-off `UPDATE` over `users.preferences`, not a code change, and it fixes **11 of
the 12** affected users. The single `date` user is left alone — remapping `date`
would visibly change their sort order, and Wave 2 removes the cost anyway. Users can
re-select `arrival` in the UI afterwards; if that proves common, revisit as a code
change that normalizes on read.

Expected effect: improves A, B, folder switch, and open. **Does not fix C.**

### Wave 2 — local search index

The listing path is already efficient: for multi-folder results Roundcube slices the
result set first and fetches only the visible page's headers
(`rcube_imap.php:1125-1137`). **Only the search itself needs replacing.**

`rcube_imap.php` exposes an `imap_search_before` hook that lets a plugin populate
`result` and skip IMAP search entirely. No core patch is required.

#### Components

**`avuz_search` plugin.** Hooks `imap_search_before`. Translates Roundcube's search
criteria into SQL against the index, returns a `rcube_result_multifolder` of
`uid-folder` identifiers. Roundcube's existing paging code then fetches the visible
headers over IMAP as it does today.

**Index schema** (Postgres, alongside the existing Roundcube tables):

```
avuz_search_message
  user_id       int      references users(user_id) on delete cascade
  folder        text
  uidvalidity   bigint
  uid           bigint
  modseq        bigint
  message_id    text
  subject       text
  from_addr     text
  to_addr       text
  sent_at       timestamptz
  flags         text[]
  body_text     text
  tsv           tsvector generated from subject/from/to/body_text
  primary key (user_id, folder, uidvalidity, uid)

avuz_search_state
  user_id       int
  folder        text
  uidvalidity   bigint
  highest_modseq bigint
  fully_indexed bool
  last_synced_at timestamptz
  primary key (user_id, folder)
```

GIN index on `tsv`. Text extraction reuses `avuz_prefetch`'s existing body-fetch code
(`avuz_prefetch.php:80-119`), which already walks `mime_parts` and calls
`get_part_body($mimeId, false, 0)` with BODY.PEEK so it never sets `\Seen`.

**Sync strategy — in-session, not a daemon.** The syncer runs inside the user's
authenticated session, as `avuz_prefetch` does: background AJAX calls during idle
time, walking folders and filling the index incrementally. This is deliberate.

A background daemon would need to re-authenticate to Zoho as each user without a
session. Credentials are recoverable server-side today (the `avuz-password-broker`
holds Zoho tenant OAuth credentials, and `ROUNDCUBE_CREDENTIAL_KEY` decrypts stored
passwords), so a daemon is *feasible* — but it would widen the credential surface for
a performance feature. In-session sync avoids that entirely and ships sooner. If cold-index
latency proves unacceptable in practice, a broker-backed daemon is the documented
follow-up, decided on evidence rather than up front.

**Incremental updates** use CONDSTORE: `SELECT` returns `HIGHESTMODSEQ`, and
`FETCH ... (CHANGEDSINCE <modseq>)` returns only messages changed since the last
sync. Cheap and bounded.

**Vanished messages.** Zoho has no `QRESYNC`, so CONDSTORE reports flag changes but
not deletions. Reconciliation: periodically issue `UID SEARCH ALL` per folder — one
round trip, returns the complete UID set — and delete local rows not present. Runs in
background, not on the interactive path.

**UIDVALIDITY change** invalidates a folder wholesale: drop all rows for that
`(user_id, folder)` and re-index.

#### Correctness rule

**If any folder in the query's scope is not `fully_indexed`, fall back to IMAP search
for the whole query.** A fast wrong answer is worse than a slow right one. The index
only serves searches it can answer completely.

This makes the plugin safe to deploy before the index is warm: behavior is identical
to today until a folder finishes indexing, then transparently faster.

#### Expected effect

All-folder search: ~13s → a single indexed Postgres query. Single-folder search:
~2.2s → the same. Search cost decouples from folder count and from RTT.

### Wave 3 — async send

Compose accepts the message, persists it, and returns immediately; a worker delivers
to Zoho in the background and reports failures back to the user. Removes the SMTP
handshake from the interactive path.

Deferred to last: send is the least-cited symptom, and it is the only wave that
introduces a durable queue and a user-visible failure mode (a send that appears to
succeed and later fails). It deserves its own design pass.

## Testing

Wave 1 is config; verify by re-running the A/B/C measurements above on staging and
comparing. Specifically: connection count against Zoho after raising
`cache_expiration_time`, and that `avuz_filters` still applies filters on new mail
after losing the `refresh` hook.

Wave 2 needs behavioral tests, per repo convention (test behavior, not
implementation):

- Search returns the same result set from the index as from IMAP, for each supported
  criterion (subject, from, to, body, entire message).
- A folder that is not fully indexed falls back to IMAP.
- A UIDVALIDITY change drops and re-indexes the folder.
- A message deleted on the server disappears from results after reconciliation.
- Flag changes propagate via CONDSTORE.
- Indexing never sets `\Seen` (BODY.PEEK).

## Open questions

These are cheap to test and are resolved during Wave 1/2 rather than assumed:

1. **Zoho's per-account IMAP connection limit.** Gates how aggressively the syncer may
   run, and interacts with raising `cache_expiration_time`. Documented as 100
   concurrent connections per account; verify against real behavior.
2. **Whether Zoho auto-files SMTP-sent mail into Sent.** Affects Wave 3 dedup, and
   whether the index would double-count sent messages.
3. **Folder count distribution across the 97 users.** Determines who currently suffers
   worst on multi-folder search and who benefits most from Wave 2.

## Out of scope

- Relocating infrastructure (rejected above).
- `enable_select_cache` (deliberate correctness tradeoff, unchanged).
- IMAP IDLE push for new-mail detection.
- Replacing Roundcube.
- `AUTH=XOAUTH2` migration — Zoho supports it and it would remove recoverable
  password storage, but it is a security improvement, not a performance one. Worth
  its own spec.
