# Plan: make progressive all-folders search safe to enable (fix D1 pool poisoning)

**Date**: 2026-08-13
**Status**: in progress
**Spec**: `docs/superpowers/specs/2026-07-23-search-pipelining-hardening-design.md`
**Goal**: keep iPhone-style progressive all-folders search (scope=all, pipeline ON), but make it
safe under concurrent load — no imapproxy pool poisoning, no wrong/empty-complete results, no error
toast. Then enable `AVUZ_PIPELINED_SEARCH=1` on prod.

## Confirmed current state (2026-08-13)

- `AVUZ_PIPELINED_SEARCH=0` on prod; scope default = `all` → serial per-folder search (6–61 folders
  × ~250ms RTT) = the slowness.
- **D1 UNFIXED**: `readPipelined()` (rcube_imap_generic.php:2101) calls `closeSocket()` on tag
  mismatch — closes Roundcube's side, leaves the desynced upstream Zoho connection in imapproxy's
  pool → next user poisoned. This is what broke everyone at load.
- **searchMulti all-or-nothing** (rcube_imap_generic.php:2207–2221): one folder `NO` discards all.
- **D3** literal fix (getCapability preflight + guard, :2160/:2168) is theory-based, never proven
  inside a real batch; guard fallback is safe.
- **D4** already logs instead of `setError` (no toast) — verify still true after D1/D2 changes.

## Phase A — Reproduce off-prod (GATE — answers the linchpin question)

Harness: `docs/superpowers/spikes/2026-07-22-search-pipelining/` (Dovecot + real up-imapproxy +
198ms RTT shim). Extend it to:

1. Force a **reused** `XPROXYREUSE` connection (second login after a cached logout), then a desync
   (mixed batch / accented literal), then have a SECOND client take the pooled connection and assert
   whether it gets clean or poisoned replies. Reproduces D1.
2. **Open question O1**: after Roundcube closes abruptly mid-desync, does up-imapproxy DISCARD the
   upstream connection or CACHE it? Test both an abrupt TCP close and a resync+LOGOUT, observe what
   the next client gets. This decides D1's destroy path.

Exit criteria: D1 reproduced deterministically in the harness; O1 answered with wire evidence.

## Phase B — Fix

1. **D1 resync at the connection layer** (whenever a tag mismatch is seen, not only in search):
   - On mismatch: send uniquely-tagged `NOOP`, read-and-discard until that tag, bounded by a byte
     cap AND a time cap.
   - Tag reached → stream aligned → `LOGOUT` so imapproxy pools a clean connection.
   - Budget exceeded → destroy in the way O1 proved actually evicts it from the pool.
2. **searchMulti per-folder tolerance**: skip a failed folder (leave it for serial), return the
   folders that succeeded, instead of returning false for the whole batch.
3. **D2**: failed/unanswered jobs come back `incomplete=true`, never complete-and-empty; serial path
   / continuation answers them.
4. **D3**: instrument a real pipelined batch to confirm `{N}`→`{N+}` rewrite; keep the guard.
5. **D4**: confirm fallback stays log-only.

## Phase C — Prove + roll out

- Harness regression tests: (a) pool stays clean after a deliberate desync — the D1 test; (b) batch
  failure → jobs incomplete not empty; (c) accented all-folders search correct; (d) no user-facing
  error on fallback.
- Staging: accented all-folders search + deliberate desync, watched. Keep flag on, observe pool.
- Prod: watched window, enable flag (code fix needs a rebuild+deploy; then the flag).
- Runbook: if pool ever poisoned, restart imapproxy sidecar (clears it).

## Non-goals

- Body/TEXT search speed (Zoho compute-bound; progressive display + search_notice already set
  expectations).
- Reverting scope to `base` — explicitly rejected; user wants all-folders progressive.
