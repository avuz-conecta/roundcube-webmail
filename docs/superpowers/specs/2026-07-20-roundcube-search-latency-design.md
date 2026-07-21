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

**Product driver:** the client also asked for **all-folder search to be the default
scope**. On today's code that search costs 12.82s (see Evidence), so shipping the
default now would make the slowest operation in the product the default one. Two
aggravators: `set_timelimit(60)` aborts long multi-folder searches and returns
*silently partial* results, which reads as missing mail; and per-search load on Zoho
multiplies by the folder count across all users.

This makes Wave 2 a **prerequisite**, not an optimization. Sequencing rule:
**all-folder-default ships with Wave 2 and never before it.**

Note there is no configuration option for the default scope — it is session state set
per search at `program/actions/mail/search.php:120`. Making it the default requires a
small plugin or patch, scoped into Wave 2.

## Mailbox size distribution

Measured/estimated across the 97 prod users:

| Segment | Count | Messages |
|---|---|---|
| Typical | ~77 | hundreds |
| Large | ~20 | thousands |
| Worst observed | 1 | ~16,000 |

This sets engineering constraints, not architecture — see Wave 2.

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

### Slow open — and `avuz_prefetch` is making everything else slower

Measured on staging with the 16k-message account (`logs/imap.log`, 2026-07-21
02:01-02:05). A single `plugin.avuz_prefetch` request issues **50-90 sequential IMAP
commands**; `_action=list` requests reached **84 commands in 6 seconds**.

Cause is `plugins/avuz_prefetch/avuz_prefetch.php:90-112` — two nested loops, one
IMAP round trip per MIME part:

```php
foreach ($list as $rawUid) {                           // up to 10 messages
    $message = new rcube_message($uid, $mbox);         // → BODYSTRUCTURE fetch
    foreach ($message->mime_parts as $mimeId => $part) {
        $body = $message->get_part_body($mimeId, ...); // → one FETCH per part
```

Observed wire traffic for one message:

```
UID FETCH 2434 (BODY.PEEK[2.MIME])
UID FETCH 2434 (BODY.PEEK[3.MIME])
UID FETCH 2434 (BODY.PEEK[4.MIME])
...
```

A 7-part message costs 8+ round trips. A batch of 10 costs ~70. At 198ms that is
**~14 seconds per prefetch run**, executing continuously in the background
(`prefetch.js:63-67` hooks `init`, `afterlist`, `listupdate`).

This is the strongest available explanation for the 13.34s prod stall: a 4.8 kB
response on a warm connection cannot otherwise take 13 seconds. The plugin built to
make message opening faster is plausibly the largest single source of latency in the
product.

**Where the round trips actually come from.** Not the body loop — `is_text()` filters
non-text parts, so the many `BODY.PEEK[N.MIME]` commands are not ours. They come from
`new rcube_message()` building structure: `rcube_imap.php:2097-2103` batches MIME
header fetches, but only within a single nesting level, and `_structure_part()`
recurses. Its own `@TODO` at `:2098` acknowledges this. Nested multipart mail
therefore costs one FETCH per level.

**Fix (Wave 1): make prefetch idempotent.** The plugin rebuilds every message's
structure on every run even when its bodies are already cached, and `prefetch.js`
keeps its `seen{}` map in a plain object that resets on each page load — so the same
messages are re-warmed continuously. A per-message "done" sentinel in Redis lets
`prefetch()` skip a warmed message *before* constructing `rcube_message`, costing zero
round trips; persisting `seen{}` to `sessionStorage` stops the client re-queueing them.
Together these remove the repeat traffic, which is the bulk of it.

**Deferred: batching `BODY.PEEK` across parts.** IMAP permits multiple body sections
per `FETCH`, so a first warm could in principle cost ~11 round trips instead of ~70.
But the expensive commands are issued by core's structure walk, not by our loop, so
capturing that win requires patching `rcube_imap.php` — a cost on every upstream
rebase. Revisit only if Task 6's measurements show first-warm latency still dominates.

Secondary: `deploy/stack.reference.yml:46` caps Redis at `128mb` with `allkeys-lru`,
shared across sessions, `imap_cache`, and every prefetched body. Bodies are the
largest entries and are evicted first, silently degrading the cache.

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

This affects **12 of 97 users** — 11 on `arrival`, 1 on `date`. Notably `arrival` is
semantically identical to IMAP index order, so those 11 pay the full fetch-and-sort
cost for a result they would get for free by leaving the setting unset.

It is a real defect but not the general cause. **Deferred to Wave 2**, which removes
the cost for all users; see Wave 1 for the interim fixes considered and rejected.

Users set this in the message-list Options popup
(`skins/elastic/templates/mail.html:174-190`); it is persisted to
`users.preferences` by `program/actions/mail/list.php:45-46`.

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
| **Make prefetch idempotent** | `plugins/avuz_prefetch/avuz_prefetch.php:90-112`, `prefetch.js:11,43-58` | re-warms every page load → warms once |
| **Dedupe filter pass** | `plugins/avuz_filters/avuz_filters.php:23-24` | runs twice per refresh → once |
| ~~ESEARCH for index queries~~ | evaluated and **rejected** — see below | |
| Idle connection lifetime | `docker/imapproxy-sidecar/imapproxy.conf:8` | `60` → `1800` |
| Redis ceiling | `deploy/stack.reference.yml:46` | `128mb` → `512mb` (starting value, see below) |
| Response compression | `docker/nginx.conf` | add gzip for HTML/JS/CSS/JSON |

`512mb` is a starting value, not a measured one. The correct figure depends on
`evicted_keys` and body size under real load — measure with `redis-cli INFO stats`
before and after, and raise further if evictions persist. Splitting prefetched bodies
onto a second Redis instance is the alternative if sizing proves hard to bound.

`cache_expiration_time 1800` keeps a connection alive across normal reading pauses.
Bounded by `cache_size 200`; at ~2 connections per user against Zoho's per-mailbox
ceiling of 100 concurrent, this is safe by two orders of magnitude (see Zoho IMAP
limits).

**`avuz_filters`: keep the `refresh` hook, fix the duplication.** An earlier draft
proposed dropping `refresh` and keeping `login_after` + `new_messages`. That would
break filtering — `avuz_filters.php:18-21` documents that `new_messages` "only fires
when check_recent detects a status diff (not always)".

The pass itself is already cheap: `avuz_filters.log` shows
`crit='UID 942:*' found=0 rules=2` — it searches only UIDs above the last-seen one,
not a 1000-message scan.

The actual defect is that **both** `new_messages` and `refresh` fire in the same
request, so the pass runs twice. Confirmed on the wire — `A0007` and `A0009` are the
identical `UID SEARCH RETURN (ALL) UID 942:*` — and in the log, with runs at
`17:24:55` and `17:24:56`. Fix is a per-request guard, not a hook change.

Further gating on `LIST-STATUS` (skip entirely when nothing arrived) lands in Wave 2,
where that data is already being collected.

**`skip_deleted = true` was evaluated and rejected.** It would have enabled ESEARCH on
index queries: `rcube_imap_generic::search()` only requests ESEARCH when the criteria
string contains a non-digit, and with `skip_deleted = false` an unfiltered index query
has empty criteria — so Roundcube issues plain `UID SEARCH ALL` and Zoho returns ~16,000
individual UIDs per message-list request on the largest mailbox. `UNDELETED` would have
tripped the check and produced compact ranges.

Rejected for three reasons:

1. **Wrong risk class.** `skip_deleted` hides any message flagged `\Deleted` from the
   list, the counts, the badges **and search**, regardless of what set the flag. IMAP
   deletion is two steps — set `\Deleted`, then `EXPUNGE` — so a client that defers the
   expunge, or an interrupted COPY-flag-EXPUNGE move (how Roundcube itself deletes,
   `rcube_imap.php:2801-2848`), leaves a message flagged but present. It goes invisible in
   Roundcube while visible elsewhere, and the user cannot recover what they cannot see.

2. **Benefit unproven, possibly negative.** `countmessages()` (`rcube_imap.php:758-778`)
   swaps a cheap `STATUS` for a live `SEARCH` on unread counts — core's own comment reads
   *"not very performant but more precise"*. And when the count comes from cache,
   `icache['undeleted_idx']` is never populated, so `rcube_imap_cache::validate()` issues
   `ALL UNDELETED NOT UID <compressed-set>` — uploading a large UID set on every warm list
   request, against the 1 GB / 15 min budget.

3. **It confounds the measurement.** Wave 1's staging numbers decide whether Wave 2 gets
   built. Two candidate causes for a disappointing result makes those numbers useless.

If revisited, ship it alone with its own before/after and a live `\Deleted`-flag test.

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

**Headers only — no bodies.** Justified by two measurements. First, body search on
Zoho is cheap: test B (entire message) cost only ~740ms more than test A (subject).
The 12.82s in test C was round-trip count, not body scanning. Second, the cost scales
badly: for the 16k-message account, headers are ~5 MB while bodies would be several GB
and hours of transfer, per user. Indexing bodies buys little and costs a lot.

Consequence: `subject`/`from`/`to` searches are served locally. Body and "entire
message" searches fall back to IMAP, where they already perform acceptably per-folder.
All-folder *body* search remains slow; measure how often it is used before treating
that as a gap.

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
  primary key (user_id, folder, uidvalidity, uid)

avuz_search_state
  user_id        int
  folder         text
  uidvalidity    bigint
  highest_modseq bigint
  fully_indexed  bool
  indexed_upto   bigint      -- resume point for interrupted backfill
  last_synced_at timestamptz
  primary key (user_id, folder)
```

**Trigram indexes, not `tsvector`.** This matters for correctness, not performance:

```sql
CREATE EXTENSION pg_trgm;
CREATE INDEX ON avuz_search_message USING gin (lower(subject) gin_trgm_ops);
-- likewise from_addr, to_addr
```

IMAP `SEARCH` does **substring** matching. `tsvector` does lexeme matching — it
tokenizes and stems, so `relat` would not match `Relatório` and Portuguese stemming
would silently diverge from current behavior. Users would perceive missing mail. With
`pg_trgm` the plugin issues `lower(subject) LIKE '%' || lower(:term) || '%'`, which is
the same operation IMAP performs today. Parity is the requirement; speed is the
constraint.

**Trigram indexes require ≥3 characters.** Queries shorter than that cannot use the
index and fall back to IMAP — covered by the correctness rule below.

Estimated size across all 97 users: low hundreds of MB, ~1 GB with trigram GIN.
Negligible for Postgres.

**Sync strategy — in-session, not a daemon.** The syncer runs inside the user's
authenticated session, as `avuz_prefetch` does: background AJAX calls, indexing on
`login_after`.

The backfill is small enough to make a daemon unnecessary. Envelope fetches batch, so
a typical mailbox (~20 folders, hundreds of messages) costs roughly
`1 + 2×20 = 41` round trips ≈ **8 seconds**. The 16k worst case is bandwidth-bound
rather than RTT-bound: ~5 MB, so tens of seconds. Both complete well inside a single
session, so there is nothing to converge toward.

This also settles the credential question. A daemon would need to re-authenticate as
each user without a session; credentials are recoverable server-side today (the
`avuz-password-broker` holds Zoho tenant OAuth credentials and
`ROUNDCUBE_CREDENTIAL_KEY` decrypts stored passwords), so it is *feasible* — but it
would widen the credential surface for a performance feature that does not need it.
Were a daemon ever required, the correct mitigation is `AUTH=XOAUTH2` (which Zoho
advertises), eliminating recoverable passwords entirely — not more careful password
handling. See Out of scope.

Two constraints from the 16k case:

- **Batch envelope fetches** (~1,000 UIDs per round trip). 16k envelopes in one PHP
  array is a memory problem and a single ~5 MB response blocks the request.
- **Make backfill resumable** via `avuz_search_state.indexed_upto`. A user who closes
  the tab mid-index resumes on next login rather than restarting.

**Incremental updates.** Zoho advertises `LIST-STATUS`, so a single round trip returns
every folder's state:

```
LIST "" * RETURN (STATUS (MESSAGES UIDNEXT UIDVALIDITY HIGHESTMODSEQ))
```

Only folders whose `HIGHESTMODSEQ` advanced need a `SELECT`; those use CONDSTORE
(`FETCH ... (CHANGEDSINCE <modseq>)`) to retrieve just the changes. Staying current
therefore costs ~198ms plus work proportional to actual change.

**Vanished messages.** Zoho has no `QRESYNC`, so CONDSTORE reports flag changes but
not deletions. Reconciliation: periodically issue `UID SEARCH ALL` per folder — one
round trip, returns the complete UID set — and delete local rows not present. Runs in
background, not on the interactive path.

**UIDVALIDITY change** invalidates a folder wholesale: drop all rows for that
`(user_id, folder)` and re-index.

**Gate the `avuz_filters` pass on the same `LIST-STATUS` result.** It currently runs a
1000-message INBOX scan every 60s regardless of whether mail arrived. Once the sync
layer knows INBOX's `HIGHESTMODSEQ`/`UIDNEXT`, the filter pass runs only when
something actually changed — free on most refreshes, and without removing the
`refresh` hook that it depends on for reliable triggering.

**Rollout sequencing.** Waves 1 and 2 ship together; Wave 3 follows separately, since
it is the only one introducing a new failure mode. Deploy to staging incrementally
(Wave 1, measure A/B/C, then Wave 2, measure again) so regressions and gains can be
attributed to a specific change — the single client-facing release is a separate
decision from how staging is exercised.

#### Correctness rule

Fall back to IMAP search for the **whole query** when any of these hold:

1. Any folder in scope is not `fully_indexed`.
2. The search targets body or "entire message" (not indexed by design).
3. The search term is shorter than 3 characters (trigram index unusable).

A fast wrong answer is worse than a slow right one. The index only serves searches it
can answer completely and with the same semantics as IMAP.

This makes the plugin safe to deploy cold: behavior is identical to today until a
folder finishes indexing, then transparently faster.

#### Expected effect

All-folder subject/from/to search: ~13s → a single indexed Postgres query, decoupled
from folder count and RTT. This is what unblocks making all-folder search the default.

Single-folder search: improves by the removed `SELECT` + `SEARCH` round trips, but the
2.20s baseline has **not been decomposed** into connection setup vs. search vs.
rendering. Wave 1 may already absorb much of it. Re-measure on staging after Wave 1
before assuming Wave 2's contribution here.

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

1. **Whether Zoho auto-files SMTP-sent mail into Sent.** Affects Wave 3 dedup, and
   whether the index would double-count sent messages.
2. **Folder count distribution across the 97 users.** Determines who currently suffers
   worst on multi-folder search and who benefits most from Wave 2.

## Zoho IMAP limits (verified)

From [Zoho's rates and limits](https://www.zoho.com/mail/help/adminconsole/rates-and-limits.html):

| Limit | Value | Scope |
|---|---|---|
| IMAP connections | 100 concurrent | per mailbox |
| IMAP data transfer | 1 GB / 15 min (org), 250 MB / 15 min (personal) | per account |
| POP connections | 5 concurrent | per mailbox |

**Connections are not a constraint.** imapproxy holds roughly 2 per user against a
per-mailbox ceiling of 100, so `cache_expiration_time 1800` is well within budget.

**Data transfer is the real constraint, and enforcement is harsh** — Zoho blocks the
account ("temporarily blocked for IMAP use") rather than degrading gracefully.

This is a third, independent argument for headers-only indexing: the 16k-message
backfill is ~5 MB, about 0.5% of the 15-minute window, whereas body indexing for the
same account would be several GB and would reliably trip the cap and lock the user out
of IMAP. Body indexing is not a storage tradeoff — it is unsafe.

**Syncer requirements that follow:** throttle backfill to stay well inside the window,
and treat an IMAP block as a stop-and-back-off condition. Never retry into a block.

## Out of scope

- Relocating infrastructure (rejected above).
- `enable_select_cache` (deliberate correctness tradeoff, unchanged).
- IMAP IDLE push for new-mail detection.
- Replacing Roundcube.
- `AUTH=XOAUTH2` migration — Zoho supports it and it would remove recoverable
  password storage, but it is a security improvement, not a performance one. Worth
  its own spec.
