# Roundcube Wave 1.5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the one-time prefetch warm from competing with the user's foreground clicks, keep Redis memory below the eviction ceiling, and instrument the app so "is it faster?" is answered by per-action percentiles instead of anecdote.

**Architecture:** Four changes — nginx timing instrumentation (measurement foundation, deploys first to capture a baseline), a one-line body-cache TTL reduction, a rewrite of `prefetch.js` into a single cancel-on-switch warmer that serializes batches and always warms the current folder, and a foreground-yield guard on top of it. Plus one staging investigation (PHP-FPM contention) that may or may not produce a config change. No new services, no new PHP logic.

**Tech Stack:** Browser JavaScript (Roundcube Elastic skin, no JS test harness), PHP 8.2, nginx (Alpine, config included inside `http{}`), Redis, awk for log aggregation.

**Spec:** `docs/superpowers/specs/2026-07-21-roundcube-prefetch-pacing-design.md`

## Global Constraints

- Never set `\Seen` — all body fetches use `BODY.PEEK`. No task here touches fetch logic; keep it that way.
- Do not add IMAP round trips or increase per-message bytes fetched from Zoho.
- Do NOT modify anything under `program/lib/Roundcube/` — core patches cost us on upstream rebases.
- Prod/staging RTT to Zoho is ~198ms. The worst observed cold prefetch batch was **69 seconds** — any timeout shorter than that will fire mid-request and double-send.
- The `seen` map in `prefetch.js` is the retry mechanism: a UID is marked `seen` only when its batch is actually sent, and unsent UIDs must stay retryable so an abandoned folder's batches re-queue on return. Do not break that invariant. (Task 3 removes the separate `inFlight` map — its role is taken over structurally by the single-run model.)
- There is no JavaScript test harness in this repo. JS changes are verified by `node --check` plus named staging behavioral observations — not unit tests. Do not invent a test framework.
- Commit messages must not mention Claude Code.

## Measurement is the point of this wave

The spec's acceptance criterion is: **foreground request latency must be independent of whether prefetch is running.** That is only checkable with per-action latency percentiles captured before and after, over comparable real usage. Task 1 builds that instrumentation and MUST be deployed and collecting a baseline before Tasks 3 and 4 change any behavior. Capturing the baseline after the fact would repeat exactly the mistake Wave 1 made.

---

### Task 1: nginx per-action latency instrumentation

**Files:**
- Modify: `docker/nginx.conf` (add `log_format` at file top, `access_log` inside `server`)
- Create: `scripts/perf-percentiles.awk`
- Create: `scripts/perf-report.sh`

**Interfaces:**
- Consumes: nothing.
- Produces: a `nginx-perf.log` on the running container in the format
  `<request_time> <upstream_response_time> <status> <method> <request_uri>`, and
  `scripts/perf-report.sh <container>` which prints p50/p95/p99 request_time per Roundcube `_action`.

- [ ] **Step 1: Add the log format and access log to nginx.conf**

`docker/nginx.conf` is included inside the main config's `http{}` block (Alpine's
`/etc/nginx/nginx.conf` has `include /etc/nginx/http.d/*.conf;` inside `http{}`, and the Dockerfile
copies this file to `/etc/nginx/http.d/default.conf`). A `log_format` directive is only valid in
`http` context, so it must sit at the **top of this file, before `server {`**. `access_log`
referencing it goes inside `server`.

At the very top of `docker/nginx.conf`, before the `server {` line, add:

```nginx
# Per-request timing for latency percentiles (Wave 1.5 measurement).
# request_time = full request incl. network; upstream_response_time = PHP-FPM only.
# request_uri carries _action even for POSTs, so the aggregation can bucket by action.
log_format perf '$request_time $upstream_response_time $status $request_method $request_uri';
```

Inside the `server {` block, immediately after the `index index.php;` line (line 5), add:

```nginx
    access_log /var/www/roundcube/logs/nginx-perf.log perf;
```

That path is the existing logs volume, readable via `scripts/portainer-exec.sh` the same way
`imap.log` is.

- [ ] **Step 2: Write the percentile aggregator**

Create `scripts/perf-percentiles.awk`:

```awk
#!/usr/bin/awk -f
# Reads nginx "perf" log lines on stdin:
#   <request_time> <upstream_response_time> <status> <method> <request_uri>
# Buckets request_time by the Roundcube _action in the URI and prints
# count / p50 / p95 / p99 / max per action, slowest p95 first.
{
    rt = $1 + 0
    uri = $5
    act = "other"
    if (match(uri, /_action=[^&]+/)) {
        act = substr(uri, RSTART + 8, RLENGTH - 8)
    } else if (match(uri, /_task=[^&]+/)) {
        act = "task:" substr(uri, RSTART + 6, RLENGTH - 6)
    }
    n[act]++
    times[act, n[act]] = rt
}
function pct(a, cnt, p,   idx) {
    idx = int((p / 100.0) * cnt + 0.5)
    if (idx < 1) idx = 1
    if (idx > cnt) idx = cnt
    return a[idx]
}
END {
    printf "%-28s %7s %8s %8s %8s %8s\n", "action", "count", "p50", "p95", "p99", "max"
    for (act in n) {
        cnt = n[act]
        for (i = 1; i <= cnt; i++) col[i] = times[act, i]
        # insertion sort (log volumes are small; keeps the script dependency-free)
        for (i = 2; i <= cnt; i++) {
            v = col[i]; j = i - 1
            while (j >= 1 && col[j] > v) { col[j+1] = col[j]; j-- }
            col[j+1] = v
        }
        rows[act] = sprintf("%-28s %7d %8.3f %8.3f %8.3f %8.3f", act, cnt, pct(col,cnt,50), pct(col,cnt,95), pct(col,cnt,99), col[cnt])
        p95sort[act] = pct(col, cnt, 95)
        delete col
    }
    # print slowest p95 first
    for (act in p95sort) order[++m] = act
    for (i = 2; i <= m; i++) { v = order[i]; j = i-1; while (j>=1 && p95sort[order[j]] < p95sort[v]) { order[j+1]=order[j]; j-- } order[j+1]=v }
    for (i = 1; i <= m; i++) print rows[order[i]]
}
```

- [ ] **Step 3: Write the report wrapper**

Create `scripts/perf-report.sh`:

```bash
#!/bin/bash
# Print per-action latency percentiles from a container's nginx-perf.log.
#   ./scripts/perf-report.sh <container> [tail-lines]
# For staging:  PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/perf-report.sh avuz-mail-roundcube-2-roundcube-1
# For prod:     PORTAINER_ENV_FILE=scripts/deploy.prod.env PORTAINER_ENDPOINT=5 ./scripts/perf-report.sh <prod-container>
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="${1:?usage: perf-report.sh <container> [tail-lines]}"
LINES="${2:-100000}"
AWK_BODY="$(cat "$SCRIPT_DIR/perf-percentiles.awk")"
PORTAINER_ENV_FILE="${PORTAINER_ENV_FILE:-$SCRIPT_DIR/deploy.env}" \
PORTAINER_ENDPOINT="${PORTAINER_ENDPOINT:-}" \
  "$SCRIPT_DIR/portainer-exec.sh" -u www-data "$CONTAINER" \
  sh -c "tail -n $LINES /var/www/roundcube/logs/nginx-perf.log | awk '$AWK_BODY'"
```

- [ ] **Step 4: Validate locally**

```bash
chmod +x scripts/perf-report.sh
printf '%s\n' \
  '0.512 0.480 200 POST /?_task=mail&_action=list&_mbox=INBOX' \
  '2.104 2.090 200 POST /?_task=mail&_action=list&_mbox=INBOX' \
  '0.031 0.028 200 GET /?_task=mail&_action=getunread' \
  '69.2 69.1 200 POST /?_task=mail&_action=plugin.avuz_prefetch' \
  | awk -f scripts/perf-percentiles.awk
```

Expected: a table with a `list` row (count 2), a `getunread` row, and a `plugin.avuz_prefetch`
row, sorted slowest-p95 first. Confirm the numbers are plausible (list p95 ≈ 2.104).

- [ ] **Step 5: Validate the nginx config parses**

```bash
docker run --rm -v "$PWD/docker/nginx.conf:/etc/nginx/http.d/default.conf:ro" nginx:alpine nginx -t
```

Expected: `syntax is ok` / `test is successful`. This mounts the file at its real include path so
the `log_format`-at-top placement is validated in the correct context. If `docker run` is denied
in this environment, skip and rely on the staging check in Task 5 Step 1.

- [ ] **Step 6: Commit**

```bash
git add docker/nginx.conf scripts/perf-percentiles.awk scripts/perf-report.sh
git commit -m "perf(obs): per-action nginx latency instrumentation

Adds a 'perf' log_format capturing request_time and upstream_response_time,
written to the roundcube logs volume, plus perf-report.sh to aggregate
p50/p95/p99 per Roundcube _action. This is the measurement foundation for
Wave 1.5: the acceptance criterion is that foreground latency is independent
of whether prefetch is running, which is only checkable with before/after
percentiles over real usage."
```

**After this task is deployed (Task 5 Step 1), a baseline must accumulate over real staging usage
before the behavior changes land. Implement Tasks 2-4 while it accrues.**

---

### Task 2: reduce the prefetched-body TTL to 5 days

**Files:**
- Modify: `plugins/avuz_prefetch/avuz_prefetch.php` (the `TTL` constant)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Confirm the right knob**

```bash
grep -n "const TTL\|messages_cache_ttl" plugins/avuz_prefetch/avuz_prefetch.php config/config.inc.php
```

Expected: `avuz_prefetch.php:24: private const TTL = '10d';` and
`config/config.inc.php:83: $config['messages_cache_ttl'] = '10d';`.

Change ONLY the plugin constant. `messages_cache_ttl` governs Roundcube's message cache, which is
on **Postgres** (`messages_cache = 'db'`), not Redis — it does not contribute to Redis memory
pressure, so it stays at 10 days. The Redis body cache is the `avuz_prefetch` `TTL` constant.

- [ ] **Step 2: Make the change**

In `plugins/avuz_prefetch/avuz_prefetch.php`, change line 24 from:

```php
    private const TTL      = '10d';
```

to:

```php
    // 5 days, not 10: halves the accumulated body working set so Redis stays well
    // under maxmemory and allkeys-lru never evicts a session. A message untouched
    // for 5 days simply re-warms once on next open. See the Wave 1.5 design doc.
    private const TTL      = '5d';
```

- [ ] **Step 3: Lint**

```bash
php -l plugins/avuz_prefetch/avuz_prefetch.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add plugins/avuz_prefetch/avuz_prefetch.php
git commit -m "perf(prefetch): cut body cache TTL from 10 days to 5

The prefetched-body working set projects to ~485MB across 97 users at a 10-day
TTL, over the 512MB Redis ceiling shared with sessions under allkeys-lru — where
an eviction is a silent logout. 5 days halves the working set to ~320MB, keeping
memory clear of the ceiling so eviction never fires. Cost is one re-warm for a
message untouched for 5 days. messages_cache_ttl (Postgres) is unaffected."
```

---

### Task 3: one cancel-on-switch prefetch warmer for the current folder

The current `sendBatches` fires ~4 batches concurrently (fire-and-forget), and `prefetchPage` starts
a fresh chain on every `afterlist`/`listupdate`. Two problems compound: concurrent batches overrun
the imapproxy pool and contend with foreground clicks, and — if we naively serialized per-call —
multiple chains would register listeners on the same `responseafterplugin.avuz_prefetch` event and
advance each other's batches (cross-triggering).

Both are solved by the same restructure: **one module-level warmer, always warming the folder the
user is currently on.** Batches go out one at a time (serialized). Switching folders supersedes the
previous run — its un-sent batches are simply never sent — so the new folder's foreground request
never competes with the old folder's leftover prefetch. At most the single batch already POSTed for
the old folder finishes server-side (an in-flight request cannot be recalled); the other ~3 are
abandoned.

This replaces `sendBatches`, `prefetchPage`, and the module-level `mbox`/`inFlight` state. The
`inFlight` map is removed: its only job was preventing a mid-dribble `listupdate` from re-collecting
not-yet-sent UIDs, and that is now handled structurally — a same-folder trigger while a run is active
is ignored, so no re-collection happens.

**Files:**
- Modify: `plugins/avuz_prefetch/prefetch.js` (replace `mbox`/`inFlight` declarations, `sendBatches`, `prefetchPage`; remove the now-unused `idle` helper)

**Interfaces:**
- Consumes: `pageUids()`, `isSeen()`, `seen`, `saveSeen()`, `BATCH` — all unchanged, defined above the replaced region.
- Produces: module-level `startRun(folder)` and the run-state variables. Task 4 adds a busy check inside `startRun`'s `sendNext`.

- [ ] **Step 1: Verify the completion event name empirically — DO THIS FIRST, on the deployed staging**

This is the riskiest assumption in the wave. `program/js/app.js:9336` fires
`triggerEvent('responseafter' + response.action)`, so the expected event is
`responseafterplugin.avuz_prefetch` — but `response.action` is server-supplied and has not been
inspected. If the name is wrong, the warmer stalls after batch 1 and prefetch dies silently.

In a browser console on staging (port 8091), logged in:

```js
rcmail.addEventListener('responseafterplugin.avuz_prefetch', function(){ console.log('PREFETCH RESPONSE FIRED'); });
```

Then open an unwarmed folder. If `PREFETCH RESPONSE FIRED` logs, the name is correct. If it does
NOT log, find the real action string:

```js
rcmail.addEventListener('responseafter', function(e){ console.log('action=', e.response ? e.response.action : rcmail.env.last_action); });
```

Record the confirmed event name in the commit message and use exactly that string in Step 2.

- [ ] **Step 2: Replace the warmer**

In `plugins/avuz_prefetch/prefetch.js`, replace everything from the `var mbox = '';` declaration
(around line 39, with its `inFlight` block) down through the end of the `prefetchPage` function
(the closing `}` before `var t;`) with the block below. Also delete the now-unused `idle` helper
function. Leave `pageUids`, the `seen`/`isSeen`/`saveSeen` block, `BATCH`, and the `schedule`/`init`
wiring at the bottom untouched.

```javascript
  var PREFETCH_RESPONSE_EVENT = 'responseafterplugin.avuz_prefetch'; // VERIFIED in Step 1
  var BATCH_TIMEOUT_MS = 90000; // fallback if a response is lost; MUST exceed the
                                // worst cold batch (~69s) or it fires mid-request
                                // and double-sends. See the Wave 1.5 design doc.

  // One warmer for the whole tab, always warming the CURRENT folder. runToken
  // identifies the live run; starting a new run bumps it, so any pending
  // response/timeout for the old run becomes a no-op. runTimer/runListener are
  // the single outstanding batch's fallback timer and response listener.
  var runToken = 0;
  var runFolder = null;
  var runActive = false;
  var runTimer = null;
  var runListener = null;

  function detachRun() {
    if (runTimer) { window.clearTimeout(runTimer); runTimer = null; }
    if (runListener) { rcmail.removeEventListener(PREFETCH_RESPONSE_EVENT, runListener); runListener = null; }
  }

  // Warm `folder`, one batch at a time. Supersedes any previous run: detachRun
  // drops the old listener/timer and the ++runToken makes the old run's next
  // check fail, so its un-sent batches are abandoned. The one batch already
  // POSTed for the old folder still completes on the server — it cannot be
  // recalled — but the rest are never sent, so the new folder's foreground
  // request does not compete with the old folder's leftover prefetch.
  function startRun(folder) {
    detachRun();
    var myToken = ++runToken;
    runFolder = folder;

    // Collect this folder's not-recently-warmed UIDs. isSeen has a 1h TTL, so a
    // batch already sent this hour is skipped; un-sent batches of an earlier
    // abandoned visit to this folder are NOT seen, so they re-queue here —
    // coverage is deferred by a folder switch, never lost.
    var all = pageUids();
    var uids = [];
    for (var k = 0; k < all.length; k++) {
      if (!isSeen(folder + ':' + all[k])) uids.push(all[k]);
    }
    if (!uids.length) { runActive = false; return; }
    runActive = true;

    var i = 0;
    (function sendNext() {
      if (myToken !== runToken) return;              // superseded by a newer folder
      if (i >= uids.length) { runActive = false; detachRun(); return; }

      var batch = uids.slice(i, i + BATCH);
      i += BATCH;

      var advanced = false;
      function advance() {
        // First of (response, timeout) wins, and only if this run is still
        // current. sendNext is scheduled OUT of the event-dispatch loop:
        // rcube's triggerEvent iterates its handler array live (common.js:389),
        // so re-registering synchronously here would fire the new listener in
        // the same loop and cascade every batch at once.
        if (advanced || myToken !== runToken) return;
        advanced = true;
        detachRun();
        window.setTimeout(sendNext, 0);
      }
      runListener = advance;
      rcmail.addEventListener(PREFETCH_RESPONSE_EVENT, advance);
      runTimer = window.setTimeout(advance, BATCH_TIMEOUT_MS);

      rcmail.http_post('plugin.avuz_prefetch', { _uids: batch.join(','), _mbox: folder });

      // Mark seen at dispatch: a UID is seen iff its batch was sent. Un-sent
      // batches (abandoned by a folder switch) are never marked, so they retry.
      for (var j = 0; j < batch.length; j++) seen[folder + ':' + batch[j]] = Date.now();
      saveSeen();
    })();
  }

  function prefetchPage() {
    if (rcmail.env.task !== 'mail') return;
    var folder = rcmail.env.mailbox;

    // Folder changed → supersede and warm the new folder. Same folder but the
    // previous run finished → pick up anything new (pagination, new mail). Same
    // folder with a run still draining → leave it; new UIDs are collected on the
    // next trigger after it finishes.
    if (folder !== runFolder || !runActive) startRun(folder);
  }
```

- [ ] **Step 3: Syntax check**

```bash
node --check plugins/avuz_prefetch/prefetch.js
```

Expected: exit 0, no output. If `node` is unavailable, skip and rely on Task 5's staging load — a
syntax error stops the message list from rendering, which is immediately visible.

- [ ] **Step 4: Confirm nothing else references the removed symbols**

```bash
grep -nE "sendBatches|inFlight|[^a-z]idle\(|var mbox" plugins/avuz_prefetch/prefetch.js
```

Expected: no matches. If any appear, a reference to a removed symbol was left behind — fix it before
committing.

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_prefetch/prefetch.js
git commit -m "perf(prefetch): one cancel-on-switch warmer for the current folder

Replaces the fire-and-forget dribble (which fired ~4 concurrent batches per
page) and the per-click chain model with a single module-level warmer that
serializes batches and always warms the folder the user is on. Switching folders
supersedes the previous run via a run token, so the old folder's un-sent batches
are abandoned and the new folder's foreground request no longer competes with
leftover prefetch; at most the one already-POSTed batch finishes server-side.

Each batch waits for the <VERIFIED-EVENT-NAME> event with a 90s fallback timer
(above the 69s worst cold batch, so it cannot fire mid-request and double-send).
sendNext is scheduled via setTimeout(0) so it runs outside rcube's live event
dispatch loop, preventing a synchronous cascade of all batches. A single
module-level listener eliminates the cross-chain triggering that per-call chains
would have on the shared response event.

Removes the inFlight map: its role (blocking mid-dribble re-collection) is now
structural — a same-folder trigger while a run is active is ignored. seen is
marked at dispatch, preserving the Wave 1 retry invariant."
```

(Replace `<VERIFIED-EVENT-NAME>` with the string confirmed in Step 1.)

---

### Task 4: yield to the foreground

Even serialized, a batch can be dispatched while the user is mid-click. `rcmail.busy` is true while
a locked foreground request is outstanding. Defer a batch when the UI is busy, bounded so a
persistently busy UI cannot starve prefetch forever.

**Files:**
- Modify: `plugins/avuz_prefetch/prefetch.js` (`startRun`'s `sendNext`, from Task 3)

**Interfaces:**
- Consumes: the `startRun` / `sendNext` structure from Task 3.
- Produces: nothing.

- [ ] **Step 1: Add the busy check at the top of `sendNext`**

In the `sendNext` function inside `startRun`, add a busy-defer guard. The declaration
`var deferrals = 0;` goes just inside `startRun`, next to `var i = 0;`. Then change the top of
`sendNext` from:

```javascript
    var i = 0;
    (function sendNext() {
      if (myToken !== runToken) return;              // superseded by a newer folder
      if (i >= uids.length) { runActive = false; detachRun(); return; }

      var batch = uids.slice(i, i + BATCH);
```

to:

```javascript
    var i = 0;
    var deferrals = 0;
    var MAX_DEFERRALS = 20; // bounded starvation guard (~6s), then proceed anyway
    (function sendNext() {
      if (myToken !== runToken) return;              // superseded by a newer folder
      if (i >= uids.length) { runActive = false; detachRun(); return; }

      // Lose races against the user: if a locked foreground request is in flight,
      // wait and retry rather than compete for a backend connection. Bounded so a
      // permanently busy UI eventually gets prefetched rather than never. The
      // myToken check above still fires first, so a folder switch during a defer
      // still supersedes correctly.
      if (rcmail.busy && deferrals < MAX_DEFERRALS) {
        deferrals++;
        window.setTimeout(sendNext, 300);
        return;
      }
      deferrals = 0;

      var batch = uids.slice(i, i + BATCH);
```

Leave the rest of `sendNext` (from `i += BATCH;` onward) unchanged.

- [ ] **Step 2: Syntax check**

```bash
node --check plugins/avuz_prefetch/prefetch.js
```

Expected: exit 0.

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_prefetch/prefetch.js
git commit -m "perf(prefetch): defer a batch while the UI is busy

Before dispatching a batch, check rcmail.busy (true during a locked foreground
request) and reschedule if set, so prefetch loses races against the user's clicks
instead of competing for a backend connection. Bounded to 20 deferrals (~6s) so a
persistently busy UI still gets prefetched eventually rather than never."
```

---

### Task 5: deploy, baseline, measure, and investigate FPM contention

This task carries the whole point of the wave: prove the change against the acceptance criterion,
and run the Change 4 investigation. It has two staging deploys — instrumentation first for a clean
baseline, then the behavior changes.

**Files:**
- Create: `docs/superpowers/plans/2026-07-21-roundcube-wave15-results.md`

**Interfaces:**
- Consumes: all prior tasks.
- Produces: the measured before/after and the go/no-go on the acceptance criterion.

- [ ] **Step 1: Deploy the instrumentation build (Task 1 only) and confirm it logs**

After Task 1 is committed and reviewed, build and deploy so the baseline can start. The staging
stack `avuz-mail-roundcube-2` already runs `:staging` with Redis at 512mb, so this is a plain
build-push + redeploy (no manual Portainer edit):

```bash
./scripts/build-push.sh latest staging
```

Redeploy the stack through Portainer (or the wrapper) with image re-pull, then confirm the perf log
is being written:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 \
  sh -c 'sleep 2; wc -l /var/www/roundcube/logs/nginx-perf.log; tail -3 /var/www/roundcube/logs/nginx-perf.log'
```

Expected: the file exists and has lines in the `<request_time> <upstream_response_time> <status>
<method> <uri>` format. If it is empty or permission-denied, nginx cannot write that path — check
the nginx worker user; fall back to `/var/log/nginx/nginx-perf.log` and read it as root via
portainer-exec. Do not proceed until it logs.

> NOTE: at this point Tasks 2-4 are committed on the branch but NOT deployed — only Task 1's
> instrumentation is live. That is deliberate: the baseline must reflect current behavior.

- [ ] **Step 2: Let a baseline accumulate**

Use staging normally, or have the client's usage flow through it, for a representative window
(hours to a day — enough that `plugin.avuz_prefetch`, `list`, `show` and `search` each have a few
hundred samples). Then capture the baseline:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/perf-report.sh avuz-mail-roundcube-2-roundcube-1
```

Record the full table in the results doc under "Baseline (instrumentation only)". This is the
"before" — foreground `list`/`show` p95 while the OLD concurrent prefetch is running.

- [ ] **Step 3: Rotate the log, deploy the full build**

Truncate the perf log so the "after" window is clean, then deploy the behavior changes:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 \
  sh -c ': > /var/www/roundcube/logs/nginx-perf.log'
./scripts/build-push.sh latest staging
```

Redeploy with image re-pull. Confirm the new `prefetch.js` is live (it should contain the
serialized `sendBatches`):

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 \
  grep -c "BATCH_TIMEOUT_MS" /var/www/roundcube/plugins/avuz_prefetch/prefetch.js
```

Expected: `1` (or more). If `0`, the image did not repull — redeploy.

- [ ] **Step 4: Confirm the serialized chain actually runs (not silently stalled)**

The Step 1 event-name verification was on the OLD code; confirm the deployed serialized version
warms a whole page, not just its first batch. Open an unwarmed folder with >8 messages, wait ~30s,
then:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-redis-1 \
  sh -c 'redis-cli --scan --pattern "*avuz_body*#done*" | wc -l'
```

Run it just after opening the folder and again 60s later. The sentinel count must keep climbing
past the first batch (8 UIDs). If it stalls at ~8, the chain is not advancing — the event name is
wrong; revisit Task 3 Step 1 against the deployed code before trusting any latency numbers.

**Also check the opposite failure — a synchronous cascade.** A re-entrancy bug in the chain would
fire every batch at once, warming everything (so the sentinel check above still passes) while
restoring the concurrency this task exists to remove. Serialized batches must NOT overlap in time.
Open a cold folder, then:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 \
  sh -c 'grep -a "plugin.avuz_prefetch" /var/www/roundcube/logs/imap.log | grep -a "Connecting" | tail -20 | cut -c2-21 | uniq -c'
```

Each prefetch request should connect at a distinct time. If multiple `Connecting` lines cluster in
the same 1-2 seconds, batches are overlapping — the serialization is broken (likely the
`setTimeout(sendNext, 0)` deferral in `advance()` was dropped) and must be fixed before proceeding.

**Also verify cancel-on-switch.** Open a cold folder with many messages, wait ~5s (so a batch or
two go out), then immediately switch to a different folder. The abandoned folder's remaining batches
must stop. Watch which folder prefetch is fetching after the switch:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 \
  sh -c 'grep -a "plugin.avuz_prefetch" /var/www/roundcube/logs/imap.log | grep -a "SELECT" | tail -8'
```

After the switch, the `SELECT` lines in prefetch requests should be the NEW folder, not the
abandoned one. At most one straggler request for the old folder (the batch already in flight at the
moment of the switch) may complete — more than one means the old run is not being superseded.

- [ ] **Step 5: Let the "after" window accumulate, then compare**

Same usage pattern and duration as Step 2. Then:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/perf-report.sh avuz-mail-roundcube-2-roundcube-1
```

Record as "After (serialized + busy-yield)". **Acceptance criterion:** p95 of `list` and `show`
should be materially lower and closer to their idle values than in the baseline — foreground
latency no longer dragged up by concurrent prefetch. Prefetch's own p95 may rise (serialized is
slower wall-clock); that is expected and acceptable.

- [ ] **Step 6: Verify coverage did not regress**

Serializing defers warming; it must not reduce it. Compare distinct warmed UIDs for a fixed
browsing pattern. After browsing the same set of folders as a baseline run:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-redis-1 \
  sh -c 'redis-cli --scan --pattern "*avuz_body*#done*" | wc -l'
```

Expected: comparable to or higher than before the change for the same folders visited. A large drop
means coverage was lost, not deferred — reconsider (e.g. allow two batches in flight). Record the
numbers.

- [ ] **Step 7: Change 4 — investigate PHP-FPM contention under load**

`pm.max_children = 20`. A 69s prefetch request occupies a worker the whole time; enough concurrent
cold warms could exhaust the pool and stall foreground requests for everyone. `pm.status_path` is
not enabled, but the FPM listen-queue backlog is observable on the socket. During a burst (several
folders opened cold in quick succession, ideally from more than one session):

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 \
  sh -c 'for i in 1 2 3 4 5; do ss -tln 2>/dev/null | grep ":9000" || netstat -an 2>/dev/null | grep ":9000.*LISTEN"; ps -eo comm | grep -c "php-fpm"; sleep 2; done'
```

`Recv-Q` on the listening `:9000` socket is the backlog of connections FPM has not yet accepted —
a sustained non-zero value means workers are exhausted and foreground requests are queuing behind
prefetch. Record what you observe. If the backlog is real, the direct fix is server-side (raise
`pm.max_children`, or bound prefetch request duration / concurrency in `avuz_prefetch.php`) — note
the recommendation; that becomes its own task, not part of this one.

- [ ] **Step 8: Safety re-checks**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-redis-1 \
  sh -c 'redis-cli INFO stats | grep evicted_keys; redis-cli INFO memory | grep -E "used_memory_human|maxmemory_human"'
```

Expected: `evicted_keys:0`, `used_memory` well under `maxmemory` — confirming the 5-day TTL keeps
memory clear and sessions are not at eviction risk.

- [ ] **Step 9: Write up results**

Create `docs/superpowers/plans/2026-07-21-roundcube-wave15-results.md` with: the baseline and after
percentile tables, the acceptance-criterion verdict (is foreground latency now independent of
prefetch?), the coverage comparison, the FPM backlog observation and any follow-up recommendation,
and the Redis safety figures.

- [ ] **Step 10: Commit**

```bash
git add docs/superpowers/plans/2026-07-21-roundcube-wave15-results.md
git commit -m "docs(perf): Wave 1.5 staging measurements

Before/after per-action latency percentiles, acceptance-criterion verdict,
prefetch coverage comparison, FPM listen-queue observation, and Redis safety
figures under the 5-day TTL."
```

---

## Not in this plan

- **Making the first warm cheaper** (parsing BODYSTRUCTURE to avoid the `.MIME` walk). The
  structure is persisted to Postgres, so the walk is one-time; revisits already issue none of it.
  Out of scope per the spec.
- **Moving sessions to Postgres.** Deferred with a trigger (Redis `used_memory` sustained above
  ~70% of `maxmemory`, or any session eviction). The 5-day TTL keeps memory clear at current scale.
- **A server-side prefetch concurrency/duration bound.** Only becomes a task if Task 5 Step 7 shows
  a real FPM backlog.
- **Wave 2** (local search index). Unaffected; all-folder search stays ~13s until then.
