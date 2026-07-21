# Roundcube Wave 1.5 — Prefetch Pacing & Cache Isolation

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

Roundcube fires a `responseafter<action>` event once a response has been processed, so the
completion signal for our action is `responseafterplugin.avuz_prefetch`. Chain on that rather
than on a timer.

**Failure handling matters.** If the request errors, the event may not fire and the chain would
stall for the life of the tab, silently disabling prefetch. Guard with a fallback timer that
advances the chain if no response arrives within a bounded window, and treat a stalled batch's
UIDs as un-sent so they remain retryable (the seen-map semantics from Wave 1 already support
this — a UID is only marked seen once its batch is actually sent).

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

## Change 3 — give prefetched bodies their own Redis

Measured on staging:

| | Keys | Avg size | Total |
|---|---|---|---|
| Prefetched bodies | 1,156 | 4.9 kB | ~5.7 MB |
| PHP sessions | 8,403 | 720 B | ~6.1 MB |

Bodies are ~4.9 kB each, not the ~180 kB assumed during review, and each warmed message costs
roughly two body keys (~10 kB). Projecting:

```
97 users × ~500 warmed messages × 10 kB   ≈ 485 MB
the 16k mailbox, browsed thoroughly       ≈ 160 MB   (one user)
```

Against the current 512 MB ceiling, with a **10-day TTL** so bodies accumulate rather than turn
over. Marginal today, worse as usage grows.

**The failure mode is what forces this change.** Bodies and sessions share one `allkeys-lru`
keyspace. Redis evicts by recency and cannot distinguish a cached email from a login. An evicted
session is a silent logout, potentially mid-compose. Raising `maxmemory` moves the cliff; it does
not remove it.

Bodies are disposable — losing one costs a slow message open. Sessions are not. They must not
share a memory pool.

**A separate Redis database is not sufficient:** `maxmemory` is per-instance, so databases on the
same instance still compete. This needs a second container.

**Roundcube cannot express this in config.** `cache/redis.php:74` reads the global `redis_hosts`
into a **static** singleton (`:118`), and `session/redis.php:45` uses that same shared instance,
so every cache and the session store necessarily share one connection. The split therefore has to
happen inside `avuz_prefetch`, which opens its own connection instead of calling
`rcmail::get_cache()`. The `redis` PHP extension is already present (`Dockerfile.base:12`).

Design notes for that connection:

- Gate on an env var (e.g. `AVUZ_BODY_REDIS_HOST`). When unset, fall back to the current
  `get_cache('avuz_body', …)` path so local development and any non-split deployment keep
  working unchanged.
- **Replicate the per-user key prefix.** `rcube_cache` prefixes keys with the numeric user id —
  observed live as `7:avuz_body:ENGENHARIA:2427:1.1.1`. A hand-rolled client must include the
  user id or users would read each other's cached message bodies. This is the single most
  important detail in this change.
- Reproduce the existing value handling: `setex` with the 10-day TTL, `serialize`/`unserialize`
  for the mime-id list sentinel, plain strings for bodies.
- Fail soft. If the body Redis is unreachable, prefetch and `serve_cached_body` must degrade to
  live IMAP fetches, never error the request.
- Size the new instance for bodies alone and keep `allkeys-lru` there — eviction of a body is
  harmless, which is the entire point of separating it.

Sessions and `imap_cache` stay on the existing Redis, which then needs far less headroom.

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
