# Roundcube Wave 1.5 — Prefetch Pacing & Cache Sizing

**Date**: 2026-07-21
**Branch**: avuz-customization
**Status**: DESIGN — awaiting approval
**Follows**: `2026-07-20-roundcube-search-latency-design.md` (Wave 1), measured in
`docs/superpowers/plans/2026-07-20-roundcube-wave1-results.md`

## Why this exists

Wave 1 worked. Measured on staging 2026-07-21:

| Measurement | Before | After — revisit |
|---|---|---|
| `_action=list` | 84 cmds / 6s | **7 cmds / 1s** |
| `plugin.avuz_prefetch` | 54 cmds / 3s | **10 cmds / 3s** |
| Worst list request | 13.34s | 1s |

But the client would not feel it, because **the first visit to a folder is unchanged**: a cold
batch of 8 messages cost **336 commands / 69 seconds**. Wave 1 removed repeat traffic; it did
not touch the one-time warm.

The one-time cost itself is not the problem — it is inherent, and the structure it builds is
persisted to Postgres so it is genuinely paid once (verified: revisits issue zero
`BODYSTRUCTURE` and zero `BODY.PEEK[N.MIME]`). **The problem is how that cost is spent.**
`sendBatches()` fires roughly four concurrent POSTs per page, each performing a heavy structure
walk, competing with the user's foreground clicks for imapproxy connections and PHP-FPM workers.
A 69-second background request contends with the folder the user just opened.

Wave 1.5 paces that cost and stops it from evicting sessions. It does not try to make it smaller
— see Out of scope.

## Change 1 — one prefetch batch in flight at a time

`prefetch.js` currently schedules the next batch on `requestIdleCallback` without waiting for the
previous POST to return. `rcmail.http_post` is asynchronous, and the idle callback typically
fires within milliseconds, so a 30-row page issues ~4 overlapping POSTs. Each is a separate
PHP-FPM request needing its own imapproxy backend connection — they cannot share one.

Serialize them: send one batch, wait for its response, then schedule the next.

Roundcube fires a `responseafter<action>` event once a response has been processed
(`program/js/app.js:9336`, `triggerEvent('responseafter' + response.action)`), so the completion
signal should be `responseafterplugin.avuz_prefetch`.

**VERIFY THIS EMPIRICALLY BEFORE IMPLEMENTING — it is the riskiest assumption in the change.**
The event name is built from `response.action`, a value the *server* puts in the JSON, and our
action's response has not been inspected. If the name differs by even a character the chain never
advances: batch 1 sends, nothing else ever does, and prefetch is silently dead for the tab — no
error, no log, and the symptom surfaces weeks later as "the cache stopped working".

Confirm in a browser console on staging before writing any chaining code:

```js
rcmail.addEventListener('responseafterplugin.avuz_prefetch', function(){ console.log('fired'); });
```

Trigger a prefetch and check it logs. If it does not, read the actual `action` value off the
response and use that.

**Failure handling.** Guard the chain with a fallback timer so a lost or errored response cannot
stall it permanently, and treat a stalled batch's UIDs as un-sent so they stay retryable — the
Wave 1 seen-map already supports this, since a UID is only marked seen once its batch is sent.

**The timer must exceed the worst observed batch.** A cold batch took **69 seconds**. A timeout
shorter than that would fire mid-flight and double-send, duplicating IMAP work — the opposite of
the goal. Pick a value comfortably above the slowest cold batch measured on staging, not a
convenient round number.

**Coverage must not regress.** Serializing makes warming a full page take longer in wall-clock,
so a user who navigates away mid-warm has fewer messages warmed *for that visit*. This is
acceptable only because unsent UIDs are never marked seen and are re-queued on the next visit —
coverage is deferred, not lost. **Verify this on staging:** count distinct warmed UIDs over a
browsing session before and after. If total coverage drops rather than shifts later, the change
is wrong as designed and should be reconsidered (e.g. two batches in flight rather than one).

**Expected effect:** peak backend connections per user per page load drops from ~5 to ~2. This
directly addresses the `cache_size 200` headroom problem: the Wave 1 plan's Step 6b estimated
97 users × 5 ≈ 485 against a ceiling of 200. Serializing brings that to ~194.

## Change 2 — yield to the foreground

Even serialized, a prefetch batch can be in flight while the user clicks a folder, and the two
compete. Roundcube exposes `rcmail.busy`, set while a locked (foreground) request is
outstanding.

Before dispatching a batch, check `rcmail.busy`; if set, reschedule rather than send. Combined
with Change 1 this means prefetch only ever occupies a connection when the user is idle.

**Do not use this as the only gate.** `rcmail.busy` is false between a user's requests, so
prefetch still progresses during normal reading — which is the intent. The goal is to lose races
against the user, not to stop prefetching.

**Risk:** a permanently-busy UI would starve prefetch entirely. Bound the number of consecutive
deferrals so prefetch eventually proceeds rather than never running.

## Change 3 — keep memory below the ceiling so nothing evicts a session

Measured on staging:

| | Keys | Avg size | Total |
|---|---|---|---|
| Prefetched bodies | 1,156 | 4.9 kB | ~5.7 MB |
| PHP sessions | 8,403 | 720 B | ~6.1 MB |

Bodies are ~4.9 kB each — not the ~180 kB assumed during review — and each warmed message costs
roughly two body keys (~10 kB). At a 10-day TTL that projects to ~485 MB across 97 users plus
~160 MB for the 16k mailbox, against a 512 MB ceiling.

**The failure mode, not the capacity, is what makes this worth addressing.** Bodies and sessions
share one `allkeys-lru` keyspace. Redis evicts by recency and cannot tell a cached email from a
login, so an evicted session is a silent logout — potentially mid-compose. The goal is to keep
eviction from ever triggering.

First, separate two mechanisms that are easy to conflate:

- **TTL is per-key expiry.** A body's 5-day TTL only ever removes that body. It can never remove
  a session — they are independent clocks. Sessions expire on their own `session_lifetime`
  (7 days), refreshed on every use, so an active user never hits it.
- **Eviction is memory pressure.** When Redis reaches `maxmemory`, `allkeys-lru` discards the
  least-recently-used key *regardless of remaining TTL*, and it cannot tell a session from a
  body. This — not any TTL — is the silent-logout path.

So the fix is to keep `used_memory` below `maxmemory`, which makes `allkeys-lru` never fire.

**This wave — one config change:**

- **body TTL 10 days → 5 days.** Halves the accumulated working set. Projection drops from
  ~485 MB + ~160 MB ≈ 645 MB (over the 512 MB ceiling) to ~240 MB + ~80 MB ≈ 320 MB —
  comfortably under 512 MB. Cost: a message untouched for 5 days re-warms once on next open.

Sessions stay on Redis. With the working set at ~320 MB against 512 MB, memory never approaches
the ceiling, so eviction never triggers, so sessions are never at risk. If growth ever pushes
memory up, `maxmemory` can be raised — the host has RAM headroom, and this is the chosen lever.

**Explicitly deferred: moving sessions to Postgres (`session_storage = 'db'`).** This would make
session loss *structurally* impossible rather than merely improbable — bodies could grow without
bound and still never evict a session. It is deferred, not rejected, because keeping memory below
the ceiling is a cheaper mitigation for the current scale and the host has RAM to raise the
ceiling if needed.

The distinction that matters for a future reader: **raising `maxmemory` is an operational
commitment, not a structural guarantee.** It holds only while someone keeps the ceiling ahead of
the working set. The day that assumption breaks — user count climbs, the body TTL creeps back up,
or the ceiling is trimmed — `allkeys-lru` resumes evicting sessions and the failure is a silent
mid-compose logout with no error. **Trigger to revisit the Postgres move:** Redis `used_memory`
sustained above ~70% of `maxmemory`, or any observed session eviction (`evicted_keys > 0` on the
session keyspace). At that point the structural fix is worth its few-ms-per-request cost.

(Postgres session writes are plain MVCC inserts — `session/db.php` `write()` has no `FOR UPDATE`
and no explicit transaction — so the old blank-signature bug cannot recur. That bug was SQLite's
whole-file lock, `config.inc.php:64-68`, a mechanism Postgres does not have. Noted so the deferred
option is not mistaken for reverting a bugfix.)

**Also rejected: a second Redis instance for bodies.** Roundcube cannot express a per-cache host
— `cache/redis.php:74` reads the global `redis_hosts` into a static singleton (`:118`) and
`session/redis.php:45` reuses it — so the split would need a hand-rolled Redis client inside
`avuz_prefetch`, reimplementing TTL, serialization, failure degradation, and the per-user key
prefix (`7:avuz_body:ENGENHARIA:2427:1.1.1`). Getting that prefix wrong would let one user read
another's message bodies. Not worth it when a TTL change solves the same problem.

## Change 4 — check whether PHP-FPM is the real bottleneck

Changes 1 and 2 coordinate one tab's prefetch against that tab's own foreground requests. The
contention that actually matters is shared: imapproxy (`cache_size 200`) and PHP-FPM
(`pm.max_children = 20`, `Dockerfile.base:18-30`) serve all 97 users. `rcmail.busy` knows nothing
about the other 96 people, so no amount of per-tab politeness prevents twenty users' cold warms
colliding.

A prefetch request that occupies an FPM worker for 69 seconds is the concerning case: a handful
of concurrent cold warms could exhaust 20 workers and stall **foreground** requests for everyone.

This change is an investigation, not a predetermined fix. Measure under load:

- FPM active workers and listen-queue depth during a multi-user cold-warm burst
  (`pm.status_path`, or `SCRIPT_NAME=/status` via the FPM socket).
- Whether foreground request latency degrades while prefetch is running for other users.

If the queue backs up, raising `pm.max_children`, capping prefetch request duration, or bounding
concurrent prefetch server-side would each be more direct than client-side pacing. Decide on the
evidence rather than assuming.

## How we measure success

Wave 1's numbers came from clicking around with DevTools open and reading command counts out of
`imap.log`. That worked for a one-off comparison but it is anecdotal, unrepeatable, and it cannot
answer "is it better for the client this week than last week".

**Instrument first, then change anything.** nginx already sits in front of every request and
knows exactly how long each took. Add a timing log format capturing `$request_time` and
`$upstream_response_time` alongside the request URI (which carries `_action`), then aggregate.

That yields, continuously and without anyone opening DevTools:

- p50 / p95 / p99 per action — `list`, `show`, `search`, `plugin.avuz_prefetch`, `refresh`
- the distribution, not a single anecdotal click
- before/after over the same real usage, not a staged test

**Capture a baseline before implementing Wave 1.5.** Without it there is nothing to compare to,
and we would be repeating Wave 1's mistake of measuring only after the fact.

### The acceptance criterion

Changes 1, 2 and 4 all aim at one thing, so state it directly:

> **Foreground request latency must be independent of whether prefetch is running.**

Concretely: p95 of `_action=list` and `_action=show` during a cold-warm burst should be
statistically indistinguishable from p95 when prefetch is idle. Today it is not — a 69-second
prefetch request competes for the same imapproxy connections and FPM workers.

This is measurable, it is the actual user experience ("the app is slow while it's doing something
in the background"), and it does not depend on anyone's subjective sense of speed.

Secondary criteria:

- **Coverage:** distinct warmed UIDs per browsing session must not drop (see Change 1).
- **Sessions:** zero session losses. With the working set at ~320 MB against a 512 MB ceiling,
  `used_memory` should stay well clear and `allkeys-lru` should never fire; verify no unexpected
  logouts and that `evicted_keys` stays 0.
- **Redis:** `evicted_keys` stays at 0 with the 5-day TTL; `used_memory` stays under ~70% of
  `maxmemory` (the trigger for revisiting the Postgres session move).

### What this does not measure

Perceived speed on a **first** visit to a folder is still bounded by the one-time warm, which
Wave 1.5 deliberately does not shrink. If the client's remaining complaint turns out to be first
visits specifically, the answer is reduced prefetch coverage or the deferred `BODYSTRUCTURE`
work — not more pacing. Distinguishing the two is exactly what the per-action percentiles above
let us do.

## Testing

Behavior, not implementation:

- A batch is not dispatched while an earlier batch is outstanding.
- A batch whose response never arrives does not stall the chain permanently, and its UIDs remain
  eligible for a later pass.
- No batch is dispatched while `rcmail.busy` is set; prefetch resumes once it clears.
- Prefetch is not starved indefinitely by a persistently busy UI.
- Body keys written by the split client are readable by `serve_cached_body`, and keys for one
  user are never visible to another.
- With the body Redis unreachable, opening a message still works (falls back to IMAP).

Staging verification, against the Wave 1 baseline in the results doc:

- Peak concurrent imapproxy backend connections during a page-load burst (expect ~2/user, was ~5).
- First-visit wall-clock for a cold folder — expected to stay similar in total IMAP work but stop
  blocking foreground clicks.
- `evicted_keys` on the session Redis: expect 0, with bodies no longer sharing the pool.

## Out of scope

- **Making the first warm cheaper.** Parsing `BODYSTRUCTURE` ourselves to avoid
  `get_structure()`'s recursive `BODY.PEEK[N.MIME]` walk was evaluated and dropped: the structure
  is persisted to Postgres, so the walk is genuinely one-time, and revisits already issue none of
  those commands. It would buy nothing on the path users repeat.
- **Reducing prefetch coverage** (warming the top N of a page instead of all 30). A config knob
  worth having, but pacing addresses the contention without giving up coverage. Revisit if
  first-visit contention persists after Changes 1 and 2.
- **Refresh cost.** 14 `refresh` requests were observed in one short window, each paying a full
  four-round-trip handshake. Real, but a separate concern from prefetch.
- **Wave 2** (local search index) is unaffected — search was never touched by Wave 1, and
  all-folder search remains ~13s.

## Sequencing decision

Wave 1.5 ships **before** Wave 2, despite search being the client's most-cited complaint.

The reasoning is frequency over severity: navigating between folders is far more common than
searching, so a smaller improvement on the common path is worth more than waiting for a larger
improvement on the rarer one. Search stays at ~13s in the meantime, and the all-folder-default
the client asked for stays blocked until Wave 2 — that is the accepted cost.

Recorded because it is a product judgement, not a technical one, and a future reader will
otherwise wonder why the loudest complaint was not addressed first.
