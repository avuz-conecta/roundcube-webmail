# All-Folder Search Latency — Design

**Date**: 2026-07-22
**Branch**: `avuz-customization`
**Status**: **Candidate C IMPLEMENTED on branch `claude/search-pipelining`, NOT deployed.**
107 folders against real Zoho: 55.2s → 6.4s, 200 matches, result sets identical to serial. Evidence and batch sizing:
`../spikes/2026-07-22-search-pipelining/RESULTS.md`. Plan:
`../plans/2026-07-22-search-pipelining.md`. Sections below marked ⟲ were superseded by measurement.
**Relates to**: `2026-07-20-roundcube-search-latency-design.md` (its "Wave 2" is one candidate
here, not the decision), `2026-07-22-open-latency-HANDOFF.md`, `2026-07-22-PROD-ROLLBACK-ANCHORS.md`

## Requirement

All-folder search must be fast enough to be the default search scope, without increasing
sustained load on Zoho IMAP.

## How this doc was produced

No user interview was possible. Everything below is either **verified in this repository**
(file and line cited) or **explicitly flagged as inferred/unverified**. Section
[Open questions](#open-questions-needing-a-decision) lists what genuinely needs the user rather
than a guess.

## What is actually true today (verified)

| Claim | Evidence |
|---|---|
| Multi-folder search runs one job per folder, **strictly serially**, in a single PHP request | `rcube_imap_search.php:86-96` — `foreach ($this->jobs as $job) { $job->run(); }` |
| The class was designed for threads that PHP no longer has | `rcube_imap_search.php:133` — `class rcube_imap_search_job /* extends Stackable */` (pthreads) |
| Each job does `SELECT` then `SEARCH` on the one shared connection | `rcube_imap_generic.php:1990-2025` — `search()` calls `select()` first |
| An empty folder costs 1 round trip, not 2 | `rcube_imap_generic.php:1999-2002` — returns early when `EXISTS` is 0 after SELECT |
| ⟲ ~~A 60s cap silently truncates results~~ **WRONG — corrected 2026-07-22.** The cap exists (`rcube_imap.php:1655`) but results are never shown truncated: `search.php:125` skips the listing when `incomplete` is set, `:155` keeps the UI locked, and `app.js:5550` re-issues the search with `_continue` **unboundedly**. Completed folders carry forward in `$_SESSION['search']`, so the final answer is complete and correct. The real failure mode is an invisible retry loop — one prod search spent **212.3s** behind a spinner across 4 requests | `rcube_imap.php:1655`, `program/actions/mail/search.php:125,155`, `program/js/app.js:5550`; prod `php-perf.log` session `03fe47ca` |
| A plugin can replace search entirely, no core patch | `rcube_imap.php:1629` `imap_search_before` hook; setting `result` bypasses all IMAP search |
| Scope is per-request session state, with no config option | `search.php:46`, `search.php:120` — `_scope` from the query string into `$_SESSION['search_scope']` |
| With no explicit `_headers`, search is **subject only** | `search.php:287-289` |
| Roundcube has **no** IMAP `MULTISEARCH` (RFC 7377) support anywhere | grep of `program/lib/Roundcube/` returns zero matches |
| `rcube_imap_generic` has **no** command pipelining | `execute()` at `:3945` writes one tag and blocks on `readReply()` |
| Postgres `cache_messages.data` is an opaque serialized blob, **not queryable columns** | `SQL/postgres.initial.sql:289-298`; written by `rcube_imap_cache::add_message()` at `:493` |
| Header data for *browsed* messages is already fetched and stored in Postgres | `rcube_imap_cache::get_messages():375-390` fetches misses from IMAP and inserts them |
| `messages_cache` is `db` (Postgres), TTL 10d | `config/config.inc.php:72,83` |
| Roundcube already tracks per-folder `UIDVALIDITY` + `HIGHESTMODSEQ` for its own cache | `rcube_imap_cache.php:781-793` (`add_index_row`), `:837` (`validate`), `:1038` |
| **The image has no working schema-migration path** | `docker/entrypoint.sh:29` runs `bin/initdb.sh --create-db` with **no `--dir`**, which `bin/initdb.sh:31-33` rejects outright; the failure is swallowed by `2>/dev/null \|\| true` |

⟲ **Re-measured 2026-07-22 on prod `1.0.3`, live traffic, 9.2h window.** All-folder search is
**median 15.5s, worst 212.3s** (7 logical searches, 11 requests, 4 sessions) — worse than the
12.82s it replaces, not better. Single-folder search median **0.92s** for the whole request, of
which ~0.40s is 2 IMAP round trips: Zoho's per-folder SEARCH compute is small and round-trip count
is the cost. Capability re-captured both through imapproxy and direct: unchanged, still **no**
`SORT`, `THREAD`, `MULTISEARCH`, `QRESYNC`, `COMPRESS=DEFLATE`; **`LITERAL-` is advertised**, which
makes non-ASCII search terms pipeline-safe without a continuation round trip. Full data:
`../spikes/2026-07-22-search-pipelining/RESULTS.md`.

Superseded, kept for the record — carried forward from 2026-07-20: 198ms RTT to Zoho; all-folder
subject search 12.82s returning 2.3 kB.

### The mechanism, stated plainly

`13s ≈ N folders × 2 round trips × 198ms`. The 2.3 kB response proves this is round-trip
*count*, not data volume, not CPU, not PHP-FPM workers (`PROD-ROLLBACK-ANCHORS.md` line 256:
"Do not re-raise `pm.max_children` as a latency fix").

There are therefore only three ways out:

1. **Remove the round trips** — answer from a local index.
2. **Stop paying for them serially** — overlap them (pipelining, or client fan-out).
3. **Do fewer of them** — search fewer folders.

Every candidate below is one of those three.

### ⟲ Assumption, now tested

The assumption was that part of the 12.82s was connection warm-up, which `1.0.3` attacks, so the
number might already be smaller. **It is not.** Re-measurement puts all-folder search at a median
of 15.5s. None of `1.0.3`'s changes touch the search path, so the premise was never half-fixed —
the cheapest candidate is not sufficient and the mechanism below stands unchanged.

## Candidates

### A — Local header index in Postgres (the existing Wave 2 spec)

Hook `imap_search_before`, answer `subject`/`from`/`to` searches from a Postgres table, return a
`rcube_result_multifolder`. Roundcube's existing paging then fetches only the visible page's
headers over IMAP (`rcube_imap.php:1125-1137`), so the listing path is unchanged.

**Reduces backend work to near zero at steady state** — the only design here that does. All-folder
search becomes one indexed SQL query, independent of folder count and of RTT.

Costs, all of them real:

- A backfill of every folder for every user: `SELECT` + batched `FETCH … ENVELOPE` ≈ 2 round trips
  per folder, plus the envelope bytes. Bounded by Zoho's **1 GB / 15 min** transfer cap, whose
  enforcement is account blocking, not throttling.
- A permanent consistency surface: new mail, moves, deletes, `UIDVALIDITY` resets — see
  [Consistency](#consistency-story-for-candidate-a).
- Deletes are the hard part: Zoho has **no `QRESYNC`**, so `CONDSTORE` reports flag changes but
  never vanished messages. Reconciliation needs a periodic full `UID SEARCH ALL` per folder.
- It cannot serve `body` or `TEXT` searches at all (indexing bodies is unsafe against the transfer
  cap), so those keep the 13s path. **What fraction of searches those are is unmeasured.**
- DDL on prod Postgres with no working migration path (see the verified table above).

### B — Progressive fan-out (no index)

Keep IMAP as the source of truth. Change *when* the user sees results, not where they come from.

The client issues one search request per folder (or per small group), at most **3 in flight**,
ordered INBOX → Sent → Archive → recently-visited → the rest, and appends results to the list as
each returns. Server side this is a thin action wrapping the existing single-folder search path;
no core patch, no new state, no consistency story — every result is live IMAP.

- First results in roughly one round trip pair (~0.5-1s). Completion at
  `N/3 × 400ms` ≈ 2.7s for 20 folders.
- Works identically for `body` and `TEXT` searches. Candidate A does not.
- Removes the `set_timelimit(60)` silent-truncation failure mode: each folder either returns or
  visibly fails, instead of the whole search quietly stopping at 60s.
- **Tension with the standing constraint.** This raises peak concurrency against Zoho — the one
  thing the handoff says makes matters worse. Mitigation: the cap is small (3), and unlike prefetch
  this fires only on an explicit, infrequent user action, not continuously in the background. Total
  work against Zoho is unchanged; only its distribution in time changes. This is a genuine
  trade-off, not a free win, and the concurrency cap is the knob.
- Requires the client to render a result set that grows. Whether the client accepts
  progressive results is a **product question**, not a technical one.

### C — Pipelined `SELECT`+`SEARCH` on the single connection

IMAP permits a client to send further commands without waiting for the previous reply. Writing all
`N × (SELECT, SEARCH)` command pairs, then reading `2N` tagged replies, collapses `2N` serialized
round trips into ~1 RTT plus Zoho's own processing time. 13s → plausibly 1-2s, with **no** change
in the work Zoho performs and **no** additional connections.

⟲ **SPIKED 2026-07-22 — it works. This is the recommended candidate.** Measured with the real
`rcube_imap_generic` through the production `up-imapproxy` build at a simulated 198ms RTT:

| Folders | Serial | Pipelined | Result sets |
|---|---|---|---|
| 26 | 11,056 ms | 422 ms | identical |
| 107 | 44,961 ms | 517 ms | identical |

Tags returned in strict send order in every run. The four doubts, resolved:

- ~~Whether Zoho processes commands in order is unverified.~~ Probed directly: three commands in
  one write, three replies in tag order, one 234ms round trip. **Still unproven for the
  authenticated selected-state path** — since closed: the gate ran against real Zoho 2026-07-22
  (107 folders, 200 matches, identical to serial).
- ~~Whether imapproxy relays pipelined commands unmangled is unverified.~~ **It does.** Same Debian
  `imapproxy 1.2.8~svn20171105-2+b2` package the sidecar uses; 214 commands in one write, relayed
  intact and in order.
- `rcube_imap_generic` has no pipelining, so this needs a core patch — but a small one.
  `execute():3945` already separates the write from the read-until-tag loop; the pipelined variant
  reuses both halves. The spike needed **no repository change at all**, only a subclass over the
  existing `protected` I/O.
- The desync failure mode is real and must be handled: any tag out of order ⇒ close the connection
  and fall back to the serial path. A failed `SELECT` mid-pipeline was tested and is safe — RFC
  3501 leaves no mailbox selected, so the dependent `SEARCH` returns `BAD`, not another folder's
  results.

Two details a production patch must get right: reset `$this->selected` and `$this->data[...]` after
a pipelined run, and accept that `search()`'s empty-folder short-circuit (`:1999-2002`) cannot
apply — the SEARCH is committed before the SELECT reply arrives. That costs no round trips.

**Implemented 2026-07-22** on branch `claude/search-pipelining`, not deployed. End-to-end through
the real `rcube_imap_search::exec()` against **real Zoho**, a 107-folder account:
**55.2s → 6.4s (8.6x)**, 3 batches, every tag in send order, 200 matched messages in exactly the same folders as a serial run. The local dovecot harness
showed 1.47s; Zoho is slower because pipelining removes round trips, not the server's own per-folder
SEARCH compute. **7s is the production expectation.**

| Piece | Where |
|---|---|
| `searchParams()`, `readPipelined()`, `searchMulti()`, `SEARCH_PIPELINE_CHUNK = 50` | `program/lib/Roundcube/rcube_imap_generic.php` |
| `run_pipelined()` + job accessors, serial fallback | `program/lib/Roundcube/rcube_imap_search.php` |
| 26 behaviour tests | `tests/Framework/ImapGenericPipelined.php`, `tests/Framework/ImapSearchPipelined.php` |
| Kill switch, no rebuild | `AVUZ_PIPELINED_SEARCH=0` |

Batch size is measured, not guessed: 455 reply bytes per folder, so 50 folders buffer ~23 kB.
The pipeline declines — leaving the serial path to answer — when threading or a sort field is
requested, when the jobs do not share one criteria string, or when any reply is not `OK`.

Evidence and reproduction: `../spikes/2026-07-22-search-pipelining/RESULTS.md`.

### Rejected without further work

**Server-side `MULTISEARCH` (RFC 7377).** The right answer in principle — one command, many
mailboxes, one round trip. Zoho does not advertise it (2026-07-20 capability capture) and Roundcube
implements none of it (verified). Two blockers, either fatal. Re-check Zoho's `CAPABILITY` when
convenient; the cost of checking is one command.

**`ESEARCH`.** Zoho has it and Roundcube already uses it (`rcube_imap_generic.php:2009-2015`). It
compacts the *response*, and the response is 2.3 kB. Irrelevant to this problem.

**Narrowing scope permanently** (search 4 folders and call it "all"). Answers a different question
than the user asked and silently loses mail. Excluding Trash and Junk is a defensible *product*
choice and is listed as an open question, but it is not a latency design.

**Raising `pm.max_children`, adding FPM workers, or more backend concurrency as a primary lever.**
Ruled out with evidence in `PROD-ROLLBACK-ANCHORS.md`.

## ⟲ Recommendation — RESOLVED 2026-07-22

Steps 1 and 2 below are **done**. The spike passed. **Build candidate C. Do not build B. Do not
build A.** B's only advantage was time-to-first-result, and a 0.52s completion erases it while its
3× concurrency cost against Zoho remains. A is unjustified on today's numbers: ~7 all-folder
searches per 9 hours, and 5 of the 11 observed `_scope=all` requests were `body`/`TEXT` searches
that A structurally cannot serve. Remaining sequencing is in
`../spikes/2026-07-22-search-pipelining/RESULTS.md`. No technical gate remains — the Zoho ordering
test passed 2026-07-22; what is left is a staging measurement before a prod deploy.

The original gating plan, kept for the record:

1. **Re-measure first (half a day).** Reproduce the A/B/C table from 2026-07-20 on staging against
   current `1.0.3` code. Also record each user's actual folder count and per-folder `MESSAGES` via
   a single `LIST … RETURN (STATUS …)`. Everything downstream is sized by these numbers, and the
   existing ones are stale.

2. **Spike candidate C (1-2 days, binary outcome).** On staging, against Zoho, *through* the
   imapproxy sidecar: write `SELECT`+`SEARCH` pairs for 5 folders without waiting, then read the
   replies. Confirm tags return in order and results match a serial run exactly. If it works, ship
   C — it is a bounded core patch, it adds no state, no storage, no sync, no consistency story, and
   it reduces nothing about Zoho's workload while removing almost all of the wall time. That is the
   best available fit to the constraint "reduce backend work, don't add concurrency".

3. **If the spike fails, ship B.** Progressive fan-out with a concurrency cap of 3. Slower than C
   in absolute completion time, far better in perceived time, and it fixes body/`TEXT` all-folder
   search, which candidate A structurally cannot.

4. **Build A only if C and B both land and measurement still says no** — or if search volume turns
   out high enough that eliminating the Zoho work matters more than the sync cost. Its consistency
   story is documented below so the option stays live, but it is the largest and riskiest of the
   three and should not be started on a stale 13s number.

**The key trade-off in one sentence:** C and B leave IMAP as the source of truth and can never
serve a wrong answer, but leave the work on Zoho's side; A removes the work permanently and is the
only durable fix, at the cost of a backfill against a hostile transfer cap and a correctness
surface (deletes without `QRESYNC`, `UIDVALIDITY` resets) that must be right every time.

**All-folder-default ships only after the chosen option is measured on staging**, and the
`set_timelimit(60)` silent truncation must be visible in the UI before then either way — a default
that quietly returns partial results is worse than a slow one.

## What gets indexed and when (candidate A, if built)

Only if A is chosen. Recorded here so the option is not lost.

**Indexed:** `subject`, `from`, `to`, `message-id`, `sent_at`, `flags`, keyed by
`(user_id, folder, uidvalidity, uid)`. **Not indexed:** bodies. Three independent reasons — body
search on Zoho already measured cheap per folder; bodies are GBs per large account; and the
backfill would trip Zoho's 1 GB/15 min cap, which blocks the account rather than throttling it.

**When:** inside the user's authenticated session, as `avuz_prefetch` already does — background
AJAX after `login_after`, never a daemon. A daemon would need stored credentials re-used outside a
session, widening the credential surface for a performance feature.

Two hard requirements that follow from the transfer cap:

- Batch envelope fetches (~1,000 UIDs per round trip) and throttle to stay well inside the window.
- Make backfill resumable (`indexed_upto`), so a closed tab resumes instead of restarting.
- Treat an IMAP block as stop-and-back-off. **Never retry into a block.**

**Text matching must be `pg_trgm`, not `tsvector`.** IMAP `SEARCH` does substring matching;
`tsvector` stems and tokenizes, so `relat` would not match `Relatório` and Portuguese stemming
would silently diverge from today's behaviour. Users perceive that as missing mail. Trigram indexes
need ≥3 characters; shorter terms fall back to IMAP. **Unverified: whether the `roundcube` DB role
can `CREATE EXTENSION pg_trgm` on the in-stack `postgres:16-alpine` — contrib is present in the
image, but the extension needs a superuser.**

## Consistency story (candidate A)

The rule that makes it safe: **fall back to IMAP for the whole query** whenever any folder in scope
is not `fully_indexed`, the search targets body/`TEXT`, or the term is under 3 characters. A fast
wrong answer is worse than a slow right one, and this makes the plugin safe to deploy cold —
behaviour is identical to today until a folder finishes indexing.

| Event | Detection | Response |
|---|---|---|
| New mail | `LIST "" * RETURN (STATUS (MESSAGES UIDNEXT UIDVALIDITY HIGHESTMODSEQ))` — one round trip for all folders | `SELECT` + `FETCH (CHANGEDSINCE)` only for folders whose modseq advanced |
| Flag change | same `HIGHESTMODSEQ` advance, `CONDSTORE` | update `flags` |
| Move / delete | **not** reported — Zoho has no `QRESYNC` | periodic `UID SEARCH ALL` per folder (1 round trip, full UID set); delete local rows not present. Background only, never on the interactive path |
| `UIDVALIDITY` change | compared on every `LIST-STATUS` | drop every row for `(user_id, folder)`, re-index, clear `fully_indexed` first so search falls back meanwhile |
| Index lags reality | — | a stale row surfaces a message that has moved; the subsequent header fetch for the visible page fails or returns the wrong folder. This is the failure mode that makes the reconciliation pass non-optional |

The same `LIST-STATUS` result should gate the `avuz_filters` pass, which today runs on every
refresh regardless of whether anything arrived.

## Failure modes to design against

| Failure | Applies to | Consequence | Mitigation |
|---|---|---|---|
| ⟲ `set_timelimit(60)` **retry loop** (not truncation) | today, B | UI locks behind a spinner and re-issues the search unboundedly via `_continue`; 212.3s observed on prod. Results stay correct | **C removes it by construction** — at ~0.5s the limit is never reached. No UI work needed; there is no truncation to surface |
| Pipeline desync | C | shared connection corrupted for the whole session | any tag mismatch ⇒ drop the connection, never recover in place |
| imapproxy mangles pipelined commands | C | wrong or hung results | settled by the spike, before any code lands |
| Fan-out raises Zoho concurrency | B | contention, the exact pathology from the open-latency work | hard cap of 3 in flight; explicit user action only, never background |
| Backfill trips 1 GB/15 min | A | Zoho **blocks the account** for IMAP | throttle; treat a block as stop-and-back-off |
| Index serves stale/wrong results | A | user sees mail that has moved or is gone | reconciliation pass + fall-back rule |
| Postgres/Redis growth | A | Redis is capped at 512mb and also holds sessions | index lives in **Postgres only**; nothing about A may touch Redis |
| DDL never applied to prod | A | plugin fatals on first search | the entrypoint's `initdb.sh` call is broken (verified); needs a deliberate migration step |

## Measurement

Before and after, on staging, with the same account and the same term. The tooling already exists
(`scripts/perf-report.sh`, `logs/php-perf.log`, `logs/nginx-perf.log`).

**Primary metric — wall time of `_action=search`, `_scope=all`,** p50/p95, bucketed in 5-minute
windows. Per `PROD-ROLLBACK-ANCHORS.md` lesson 1, never compare whole-window averages; per lesson
3, never read a bucket before it closes.

Secondary, all of which must be reported together or the primary is uninterpretable:

- **IMAP command count per search** (`ROUNDCUBE_DEBUG=1`, count by connection id — `imap.log`
  interleaves connections and this has caused two misreads already).
- **Time to first visible result** — the metric B optimises and wall time hides.
- **Peak concurrent backend connections during a search** — the regression B risks.
- **Folder count** for the account under test, so results normalise across users.
- **Result-set equality against a serial IMAP run** — for C and A, identical sets, not just similar
  counts. This is a correctness gate, not a performance metric.
- For A only: bytes transferred during backfill, against the 1 GB/15 min budget.

Acceptance for making all-folder the default: p95 all-folder subject search under an agreed target
(see open question 2) on a real account, result sets provably identical to a serial IMAP run, and
truncation surfaced in the UI.

## Could not verify

1. ✅ **RESOLVED** — Zoho's `CAPABILITY` re-captured 2026-07-22, unchanged.
2. ✅ **RESOLVED** — the ordering gate ran against real Zoho 2026-07-22 on a 107-folder account,
   authenticated and in selected state: tags in strict send order, and 200 matched messages
   attributed to exactly the same folders as a serial run.
3. ✅ **RESOLVED** — imapproxy relays pipelined commands unmangled and in order.
4. ✅ **RESOLVED** — no. It is median 15.5s on `1.0.3`, worst 212.3s.
5. Real folder counts and per-folder message counts. The brief's "8-26 folders, tens to low
   hundreds of messages" describes *cached* rows, not server truth, and the 2026-07-20 doc records
   a 16,000-message account. These are not the same measurement and A's sizing depends on the
   second one.
6. Effective Brazil↔Zoho bandwidth under contention (~1 Mbit/s was inferred, not measured).
7. Whether the `roundcube` Postgres role can create the `pg_trgm` extension.
8. How often users search at all, and what share of searches are body/`TEXT`. This is the single
   number that decides whether A's blind spot matters, and it is unmeasured.
9. How the `avuz_filters` tables reached prod Postgres, given the entrypoint's broken `initdb.sh`
   invocation. Whatever that path was is the path A's DDL would also need.

## Open questions needing a decision

1. **Does "all folders" include Trash and Junk?** Excluding them cuts N and changes the answer
   users get. Product call, not an engineering one.
2. **What does "fast enough" mean — first results under 2s, or complete results under 2s?** This
   single answer decides between B and C/A and should be settled before the spike.
3. **Is a progressively-filling result list acceptable to the client,** or must the list be
   complete on first paint? If it must be complete, candidate B is out.
4. **Is the default scope subject-only, or subject+from+to, or "entire message"?** With no explicit
   headers Roundcube searches subject only (`search.php:287-289`). If the default must include
   "entire message", candidate A cannot serve the default at all and the choice collapses to B or C.
5. **Appetite:** a 2-day spike with a binary outcome, versus the multi-week index. If the index is
   wanted regardless of the spike's result, say so now — it changes the sequencing.
6. **Who applies DDL to prod Postgres,** and through what reviewed process? Only relevant if A is
   chosen, but it has no answer today.

## Out of scope

- Relocating infrastructure closer to Zoho (rejected 2026-07-20: it would push every cache hit
  across the Atlantic; hits are the common case).
- The Zoho REST API as a mailbox backend (30 req/min, no changes-since token — closed 2026-07-20).
- Replacing Roundcube.
- `AUTH=XOAUTH2` migration — worth doing, but a security improvement, not a performance one.
- Open-latency / prefetch contention (`2026-07-22-open-latency-HANDOFF.md` owns that).
