# Open-Latency Investigation — Handoff for Fresh-Context Brainstorm

**Date**: 2026-07-22
**Purpose**: Start a brainstorming session on fixing slow message opens, from a clean context.
Read this whole file first — it captures the confirmed root cause, the wrong turns already ruled
out (don't repeat them), what's shipped, the tooling, and the leading fix direction.

---

## The confirmed root cause (definitive, measured)

Opening an image-heavy email **while prefetch is running** is slow (16s–90s observed) because of
**connection + bandwidth contention**, not structure parsing.

Measured on staging, a cold open of message uid 166 (an HTML invoice with ~8 inline images) during
active prefetch:

- The open fires **~8 parallel IMAP connections**, one per inline image (`UID FETCH 166
  (BODY.PEEK[1.2])`, `[1.3]`, `[1.5]` … each on its own connection).
- Prefetch is warming concurrently (worse with 2 browser tabs — see below).
- Result in a 10s window: **13 concurrent backend connections, 27 opened total, 119 part fetches
  (~12 MB of image/body data)** moving over the constrained Brazil↔Zoho link (~198ms RTT).
- Some image fetches **queued 20–95 seconds** waiting for bandwidth/connection slots.
- **No Zoho hard throttle** (no BYE/blocked/limit in the log) — pure bandwidth + connection
  contention.

**Prefetch, meant to help, actively hurts the open**: without it the 8 image fetches would have
full bandwidth; with it they starve.

Second open of the same message is instant (structure cached in Postgres `messages_cache`; images
cached by the browser). The cost is entirely the **first, cold** open of a not-yet-warmed message.

---

## Wrong turns already ruled out — DO NOT re-propose without new evidence

1. **"It's the images loading."** Partly — but the durable cost is the *contention* between the
   open's parallel image fetches and prefetch, not images alone. (User corrected this early;
   evidence later confirmed the contention framing.)

2. **"It's the MIME structure walk"** (the whole `2026-07-22-roundcube-mime-structure-batching-design.md`
   spec). **FALSIFIED by a spike.** `BODY.PEEK[N.MIME]` is built only in `fetchMIMEHeaders`
   (`rcube_imap_generic.php:2839`), which has 2 callers, both in `structure_part`.
   `is_attachment_part` returns **false** for cid-inline images (content-id makes `$part[3]`
   non-empty), so `structure_part` never MIME-fetches them. Instrumented open of msg 162 logged
   **zero** `fetchMIMEHeaders` calls. The "12 structure fetches" that motivated that spec were
   **misattributed** interleaved log lines. That spec is **BLOCKED/dead** for this client's mail
   (cid-image invoices). It would only help attachment/rfc822-heavy mail — a class not confirmed to
   be what the client opens.

3. **Caching inline images.** Rejected by the user with a correct argument: it does **not** solve
   the race. If prefetch hasn't reached a message yet and the user opens it, they still pay the
   image fetches cold. Caching only helps *after* warming. (Also: images are 30–250 KB vs 4.9 KB
   text bodies, so caching all of them bloats Redis — this is why image warming was dropped
   2026-07-07, commit `88f849a62`.)

---

## The leading fix direction (from the user, for the brainstorm to develop)

**Incremental prefetch batches + cross-tab (server-side) prefetch pause.** They compound:

- **Incremental batches**: warm the first ~3 messages, then 5, then 8 (ramp). Rationale:
  - Top-of-list messages (most likely to be opened) warm **fastest** → user hits warm more often.
  - Early batches are **short** → brief in-flight windows → a pause can actually take effect.
  - Keeps serialization (one request at a time), so it does **not** raise concurrent-connection
    count against Zoho's per-mailbox limit (batch *size* ≠ concurrent connections; each prefetch
    request uses ~1 connection regardless of how many UIDs it warms).
- **Cross-tab pause**: when any foreground request for the user is active, prefetch backs off.
  - Viability nuance the user raised: an **in-flight** prefetch request cannot be recalled (server
    keeps processing it), so a pause can't stop the batch already running — same limitation as the
    client-side busy-yield. **But** (a) it works across tabs (client busy-yield doesn't), and
    (b) if batches are short (incremental), the in-flight one you can't stop is small, so the
    residual contention is small. This is why the two ideas depend on each other.

**Open questions for the brainstorm:**
- Where does the pause live? A server-side flag keyed per user (Redis) set on foreground
  open/list and checked before dispatching a prefetch batch? How is "foreground active" detected
  and expired?
- Does the *open itself* need to fire 8 parallel image fetches, or can that parallelism be capped
  (fewer concurrent connections per open)? That's core/Elastic display behavior — investigate
  feasibility.
- Multi-tab: two tabs each run their own prefetch warmer (Wave 1.5 serialization is per-tab). A
  server-side pause/coordination is the only thing that tames cross-tab concurrency. Confirm this
  is worth solving or whether one-tab is the realistic usage.
- What's the actual bandwidth ceiling Brazil↔Zoho? 12 MB took ~90s across 13 connections ≈ ~1
  Mbit/s effective under contention — measure it, because it bounds every option.

---

## What is shipped, and where

| Work | State |
|---|---|
| **Wave 1** (prefetch idempotency, run-guard, imapproxy 1800s, Redis 512mb, gzip) | On **staging** (`avuz-mail-roundcube-2`, port 8091), validated (list path 84→7 commands). **NOT on prod.** |
| **Wave 1.5** (cancel-on-switch warmer, serialize batches, busy-yield, TTL 5d) | On **staging**, serialization + event-name verified live. **NOT on prod.** Cancel-on-switch not user-confirmed via waterfall. |
| **MIME structure batching** spec | **BLOCKED/dead** (see wrong-turn #2). |
| **Wave 2** (local search index) | Specced (`2026-07-20-roundcube-search-latency-design.md`), untouched. All-folder search still ~13s. Client asked for all-folder-default — gated on Wave 2. |
| **Prod** | Nothing from this whole effort deployed. User decision: no rollout until more is solved. |

Relevant specs/plans in `docs/superpowers/`:
- `specs/2026-07-20-roundcube-search-latency-design.md` — Wave 1 root causes + Wave 2 (search)
- `plans/2026-07-20-roundcube-wave15-prefetch-pacing.md` — Wave 1.5 (implemented)
- `plans/2026-07-20-roundcube-wave1-results.md` — Wave 1 staging measurements
- `specs/2026-07-22-roundcube-mime-structure-batching-design.md` — the falsified spec (keep as record)

---

## Measurement tooling (built this session, on staging)

- **nginx per-request timing**: `docker/nginx.conf` logs `$msec $request_time $upstream_response_time
  $status $method $request_uri` to `/var/www/roundcube/logs/nginx-perf.log`.
- **`scripts/perf-report.sh <container> [actions|concurrency]`** — per-action p50/p95/p99, or splits
  `list`/`show` latency by whether prefetch overlapped (the contention metric).
- **`scripts/perf-percentiles.awk`, `scripts/perf-concurrency.awk`** — the aggregators.
- Staging access: `PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3
  ./scripts/portainer-exec.sh [-u www-data] avuz-mail-roundcube-2-<svc>-1 <cmd>`.
  Services: `roundcube`, `imapproxy`, `redis`, `postgres`, `broker`. **Prod is endpoint 5,
  `scripts/deploy.prod.env` — do not touch for this work.**

### Controlled cold-open reproduction (how the root cause was measured)
1. Flush the user's cache: `DELETE FROM cache_messages WHERE user_id=<id>` (Postgres);
   `redis-cli --scan --pattern "<id>:*" | while IFS= read -r k; do redis-cli DEL "$k">/dev/null; done`
   plus the `*avuz_body*` pattern. **Use a `while read` loop, not `xargs` — folder names have
   spaces and xargs splits keys.**
2. Client `seen` map persists in sessionStorage across reloads → prefetch skips. To force cold
   prefetch: browser console `sessionStorage.clear(); location.reload();`.
3. The heavy test user is **user_id 10** (admin@grupovidalar.com.br). Message uid 166 / 162 in
   folder "A - PBL" are the image-heavy invoices used.
4. Truncate `nginx-perf.log` and note the `imap.log` line count as a marker before each test.

### Gotchas learned (don't relearn)
- **JS is immutable-cached** (`Cache-Control: immutable`, `?s=<mtime>`). A soft `location.reload()`
  may serve old `prefetch.js`. Verify deployed vs loaded: container `stat -c %Y prefetch.js` must
  equal the browser's `?s=`.
- **`imap.log` interleaves all connections/requests** — never attribute a command block to one
  request without checking connection IDs. This caused two misreads this session.
- **`build-push.sh` hardcodes `:staging`** for the staging env regardless of version arg.
- **`deploy/stack.reference.yml` does NOT deploy** — `scripts/deploy.sh:100` re-sends Portainer's
  current stack file. Config changes to the running stack need a Portainer edit.

---

## Suggested first moves for the brainstorm session

1. Confirm the requirement in one sentence: *first cold open of a not-yet-warmed message stays
   responsive even while prefetch runs, within Redis size and Zoho bandwidth limits.*
2. Measure the Brazil↔Zoho effective bandwidth under contention (bounds every option).
3. Develop the incremental-batch + cross-tab-pause design; decide where the pause state lives and
   how "foreground active" is detected/expired.
4. Separately decide the **priority question**: keep pushing open-latency, or ship Wave 1 + 1.5 to
   prod (validated, help the common path) and/or pivot to Wave 2 (search — the client's
   first-named symptom, still ~13s). Nothing is on prod yet.

---

## PROD ROLLOUT of Wave 1 + 1.5 (for tomorrow morning)

**Users need NO manual steps.** The console cache/sessionStorage clearing we did was test-only
(forcing cold state to measure). For a real deploy:
- New `prefetch.js` reaches users automatically via Roundcube's `?s=<filemtime>` cache-bust
  (`rcmail_output_html.php:1073`) on their next page load — a fresh URL the browser hasn't cached,
  so `Cache-Control: immutable` doesn't block it.
- Old Redis/Postgres caches self-heal: `avuz_prefetch_cache::is_warm` treats a legacy `'1'` sentinel
  as not-warm and rewrites it. No flush.
- Mixed version is safe: new server PHP + old client JS works (old JS POSTs UIDs the same way).
- Residual: a user who keeps the tab open overnight runs old JS until they reload — harmless
  (today's behavior). Users reaching mail fresh via Nextcloud each morning get new JS on load.

**Deploy overnight** so first load tomorrow is clean.

### Rollout steps (execute carefully — gated commands will prompt)
1. **Pick a VERSION tag** (prod uses `:${VERSION}` + `:latest`, e.g. `1.0.0`). CONFIRM with user.
2. `./scripts/build-push.sh <VERSION> prod` — builds + pushes app + imapproxy sidecar to the prod
   registry.
3. **Per prod tenant stack** (prod = Portainer endpoint 5, `scripts/deploy.prod.env`): update the
   stack to pull the new image, AND make two config changes that don't come from the image:
   - **Redis `--maxmemory` → 512mb** (or higher): a MANUAL Portainer stack edit — `scripts/deploy.sh`
     re-sends Portainer's *current* file, so a git/reference change does NOT reach the running stack.
   - imapproxy `cache_expiration_time 1800` is baked into the sidecar image → arrives via image pull,
     no manual edit.
4. Redeploy each stack with **pull image** enabled.
5. Verify per stack: container image tag updated; `prefetch.js` in container has `startRun`/
   `BATCH_TIMEOUT_MS` (4 markers); `redis-cli CONFIG GET maxmemory` = 536870912;
   `grep cache_expiration_time /etc/imapproxy.conf` = 1800.

### UNKNOWNS to resolve with the user before deploying (do NOT guess)
- **Which prod tenant stacks get the deploy?** Prod endpoint 5 has multiple roundcube stacks
  (e.g. `avuz-mail-roundcube`, `grupo-vidalar-roundcube`, …). List them, confirm scope. Each needs
  its own Redis Portainer edit + redeploy.
- **Version tag** to use.
- **Redis prod sizing**: prod mailboxes/users may differ from staging; size `maxmemory` with
  headroom (staging used 512mb; measure `used_memory`/`evicted_keys` after).
- **Rollback plan**: prod images are versioned, so rollback = redeploy previous `:${VERSION}`. Note
  the current prod version before deploying.

### Post-deploy sanity (prod)
- `scripts/perf-report.sh <prod-container> actions` (perf instrumentation ships in the image) to
  confirm list-path command counts dropped and nothing regressed.
- Watch `evicted_keys` (should stay 0) and errors.log for any Zoho block messages.
