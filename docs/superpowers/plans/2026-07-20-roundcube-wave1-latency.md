# Roundcube Wave 1 Latency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut Roundcube's IMAP round trips against Zoho by making `avuz_prefetch` idempotent, removing a duplicated filter pass, enabling ESEARCH, and tuning the imapproxy/Redis/nginx layer — without changing search architecture.

**Architecture:** Four independent changes, three in our own plugins and config, one in infrastructure. Pure logic moves into `lib/` classes unit-tested with PHPUnit (following `plugins/avuz_filters/lib/` + `tests/`); IMAP-touching shells stay thin and are verified by wire-log measurement rather than unit tests.

**Tech Stack:** PHP 8.2 (container) / 8.5 (local CLI), PHPUnit 9.6 at `vendor/bin/phpunit`, Roundcube 1.6.14, Redis, Postgres 16, Debian imapproxy + stunnel sidecar, nginx.

**Spec:** `docs/superpowers/specs/2026-07-20-roundcube-search-latency-design.md`

## Global Constraints

- Never set `\Seen` — all body fetches must use `BODY.PEEK`.
- Zoho IMAP limits: 100 concurrent connections per mailbox; **1 GB / 15 min data transfer per account**, enforced by blocking the account. Do not increase per-message bytes fetched.
- Zoho advertises no `SORT`, no `THREAD`, no `QRESYNC`, no `COMPRESS=DEFLATE`. It does advertise `ESEARCH`, `CONDSTORE`, `IDLE`, `LIST-STATUS`, `MOVE`, `UIDPLUS`.
- Prod RTT to Zoho is **198ms**; every avoided round trip is worth ~198ms.
- Do not modify files under `program/lib/Roundcube/` — core patches are out of scope for Wave 1 and cost us on upstream rebases.
- Commit messages must not mention Claude Code.
- Tests assert behavior, not implementation. Test names use third-person verbs, no "should".

---

### Task 1: Make prefetch idempotent (server side)

Today `prefetch()` builds a full `rcube_message` for every UID in the batch on every run — which fetches BODYSTRUCTURE and, for nested multipart messages, one `BODY.PEEK[N.MIME]` command per nesting level. It does this even when every text body for that message is already in Redis. A "done" sentinel lets us skip the message entirely, costing zero IMAP round trips.

**Files:**
- Create: `plugins/avuz_prefetch/lib/prefetch_cache.php`
- Create: `plugins/avuz_prefetch/tests/prefetch_cache_test.php`
- Modify: `plugins/avuz_prefetch/avuz_prefetch.php:43-46` (key helper), `:80-119` (prefetch loop)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `avuz_prefetch_cache::body_key(string $folder, int $uid, string $mimeId): string`, `avuz_prefetch_cache::done_key(string $folder, int $uid): string`, `avuz_prefetch_cache::is_warm($cache, string $folder, int $uid): bool`, `avuz_prefetch_cache::mark_warm($cache, string $folder, int $uid): void`. Task 2 relies on none of these; Task 3 relies on none of these.

- [ ] **Step 1: Write the failing test**

Create `plugins/avuz_prefetch/tests/prefetch_cache_test.php`:

```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/prefetch_cache.php';

/** Minimal stand-in for rcube_cache: get/set only. */
class fake_cache
{
    public $data = [];
    public $writes = 0;
    function get($key) { return $this->data[$key] ?? null; }
    function set($key, $value) { $this->data[$key] = $value; $this->writes++; }
}

class prefetch_cache_test extends TestCase
{
    function testBodyKeyCombinesFolderUidAndMimeId() {
        $this->assertSame('INBOX:941:1.1', avuz_prefetch_cache::body_key('INBOX', 941, '1.1'));
    }

    function testDoneKeyIsDistinctFromAnyBodyKey() {
        $done = avuz_prefetch_cache::done_key('INBOX', 941);
        $this->assertNotSame(avuz_prefetch_cache::body_key('INBOX', 941, '1.1'), $done);
        $this->assertNotSame(avuz_prefetch_cache::body_key('INBOX', 941, ''), $done);
    }

    function testReportsColdWhenNothingCached() {
        $this->assertFalse(avuz_prefetch_cache::is_warm(new fake_cache(), 'INBOX', 941));
    }

    function testReportsWarmAfterMarking() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941);
        $this->assertTrue(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testWarmthIsScopedPerFolder() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'Enviadas', 941));
    }

    function testWarmthIsScopedPerUid() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 942));
    }

    function testTreatsMissingCacheAsCold() {
        $this->assertFalse(avuz_prefetch_cache::is_warm(false, 'INBOX', 941));
    }

    function testMarkingWithoutCacheDoesNotError() {
        avuz_prefetch_cache::mark_warm(false, 'INBOX', 941);
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --no-coverage plugins/avuz_prefetch/tests/prefetch_cache_test.php
```

Expected: FAIL — `Failed opening required '.../lib/prefetch_cache.php'`.

- [ ] **Step 3: Write minimal implementation**

Create `plugins/avuz_prefetch/lib/prefetch_cache.php`:

```php
<?php

/**
 * Cache key scheme + warmth check for avuz_prefetch.
 *
 * The "done" sentinel lets prefetch() skip a message whose text bodies are
 * already cached WITHOUT building an rcube_message first — building one costs a
 * BODYSTRUCTURE fetch plus, on nested multipart mail, one BODY.PEEK[N.MIME]
 * command per nesting level (rcube_imap.php:2097-2103 only batches within a level).
 * At 198ms RTT to Zoho those are the round trips worth removing.
 */
class avuz_prefetch_cache
{
    /** Sentinel mime_id. Not a legal IMAP part number, so it can never collide. */
    private const DONE_MIME_ID = '#done';

    public static function body_key($folder, $uid, $mimeId)
    {
        return $folder . ':' . $uid . ':' . $mimeId;
    }

    public static function done_key($folder, $uid)
    {
        return self::body_key($folder, $uid, self::DONE_MIME_ID);
    }

    /** True when this message was fully warmed on an earlier run. */
    public static function is_warm($cache, $folder, $uid)
    {
        if (!$cache) {
            return false;
        }
        return $cache->get(self::done_key($folder, $uid)) === '1';
    }

    public static function mark_warm($cache, $folder, $uid)
    {
        if (!$cache) {
            return;
        }
        $cache->set(self::done_key($folder, $uid), '1');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --no-coverage plugins/avuz_prefetch/tests/prefetch_cache_test.php
```

Expected: `OK (8 tests, 9 assertions)`.

- [ ] **Step 5: Wire the sentinel into the plugin**

In `plugins/avuz_prefetch/avuz_prefetch.php`, add the require at the top of the file, immediately after the opening `<?php` line and before the docblock:

```php
require_once __DIR__ . '/lib/prefetch_cache.php';
```

Replace the `key()` method (currently at `:43-46`) with a delegation, so there is one key scheme:

```php
    private function key($folder, $uid, $mimeId)
    {
        return avuz_prefetch_cache::body_key($folder, $uid, $mimeId);
    }
```

Replace the body of the `foreach ($list as $rawUid)` loop in `prefetch()` (currently `:90-116`) with:

```php
        foreach ($list as $rawUid) {
            $uid = (int) $rawUid;
            if ($uid <= 0) {
                continue;
            }

            // Already warmed on an earlier run: skip before building rcube_message,
            // which would cost a BODYSTRUCTURE fetch plus per-level MIME header fetches.
            if (avuz_prefetch_cache::is_warm($cache, (string) $mbox, $uid)) {
                continue;
            }

            try {
                $message = new rcube_message($uid, $mbox);
                if (empty($message->headers)) {
                    continue;
                }

                foreach ($message->mime_parts as $mimeId => $part) {
                    if (!$this->is_text($part)) {
                        continue;
                    }
                    // get_part_body fires our hook first (cache miss on first run) →
                    // then BODY.PEEK fetch → we store the result for next time.
                    $body = $message->get_part_body($mimeId, false, 0);
                    if ($cache && is_string($body) && $body !== '') {
                        $cache->set($this->key($message->folder, $uid, $mimeId), $body);
                    }
                }

                avuz_prefetch_cache::mark_warm($cache, $message->folder, $uid);
            } catch (Throwable $e) {
                rcube::raise_error("avuz_prefetch uid {$uid}: " . $e->getMessage(), true, false);
            }
        }
```

Note the sentinel is written with `$message->folder` (the resolved folder) while the skip check uses `$mbox` (the request parameter). When `$mbox` is null Roundcube resolves it to the current folder, so on the first run the sentinel may be stored under a different string than the check reads. Handle that by resolving the folder once before the loop — insert this immediately after the `$list = ...` line at `:88`:

```php
        $folder = $mbox !== null ? $mbox : $rcmail->storage->get_folder();
```

then use `$folder` in both the `is_warm` check and the `mark_warm` call, and leave `$this->key($message->folder, ...)` as-is for body keys so the read path in `serve_cached_body()` (which uses `$message->folder`) still matches.

- [ ] **Step 6: Verify the full plugin test file still passes**

```bash
vendor/bin/phpunit --no-coverage plugins/avuz_prefetch/tests/prefetch_cache_test.php
```

Expected: `OK (8 tests, 9 assertions)`.

- [ ] **Step 7: Lint the modified plugin**

```bash
php -l plugins/avuz_prefetch/avuz_prefetch.php
php -l plugins/avuz_prefetch/lib/prefetch_cache.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 8: Commit**

```bash
git add plugins/avuz_prefetch/lib/prefetch_cache.php \
        plugins/avuz_prefetch/tests/prefetch_cache_test.php \
        plugins/avuz_prefetch/avuz_prefetch.php
git commit -m "perf(prefetch): skip messages already warmed

prefetch() built a full rcube_message for every UID on every run, even when
all its text bodies were already cached. Building one costs a BODYSTRUCTURE
fetch plus one BODY.PEEK[N.MIME] command per nesting level on multipart mail,
since rcube_imap.php:2097 only batches within a single level. At 198ms RTT to
Zoho a 10-UID batch reached ~70 round trips.

A per-message sentinel key lets an already-warm message be skipped before any
IMAP work happens."
```

---

### Task 2: Stop re-warming on every page load (client side)

`prefetch.js` keeps its `seen{}` map in a plain object, so a page reload or task switch discards it and every message on the visible page is re-queued. Combined with Task 1 the server now answers those cheaply, but the requests still cost a PHP process, a session round trip, and an imapproxy connection each. Persisting the map to `sessionStorage` removes them.

**Files:**
- Modify: `plugins/avuz_prefetch/prefetch.js:10-29` (batching + seen map), `:63-67` (event hooks)

**Interfaces:**
- Consumes: nothing from Task 1 (independent; Task 1 is server-side).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Persist the `seen` map**

In `plugins/avuz_prefetch/prefetch.js`, replace line 11:

```javascript
  var seen = {};
```

with:

```javascript
  // Persisted for the tab's lifetime: a reload or task switch must not re-queue
  // UIDs we already warmed. Keys are already folder-scoped (mbox + ':' + uid).
  var SEEN_KEY = 'avuz_prefetch_seen';
  var seen = loadSeen();

  function loadSeen() {
    try { return JSON.parse(sessionStorage.getItem(SEEN_KEY)) || {}; }
    catch (e) { return {}; }
  }

  function saveSeen() {
    try { sessionStorage.setItem(SEEN_KEY, JSON.stringify(seen)); }
    catch (e) { /* quota or private mode: in-memory only, no behavior change */ }
  }
```

Function declarations hoist, so calling `loadSeen()` on the line above its definition is correct.

- [ ] **Step 2: Persist after queueing, and drop the debug line**

Replace the body of `prefetchPage()` (lines 43-58) with:

```javascript
  function prefetchPage() {
    if (rcmail.env.task !== 'mail') return;
    mbox = rcmail.env.mailbox;

    var all = pageUids();

    var uids = [];
    for (var i = 0; i < all.length; i++) {
      var key = mbox + ':' + all[i];
      if (seen[key]) continue;
      seen[key] = 1;
      uids.push(all[i]);
    }

    if (uids.length) {
      saveSeen();
      sendBatches(uids);
    }
  }
```

This drops the `console.log` at line 48, writes `sessionStorage` once per page rather than once per UID, and skips the `sendBatches` call entirely when there is nothing new.

- [ ] **Step 3: Verify syntax**

```bash
node --check plugins/avuz_prefetch/prefetch.js
```

Expected: no output (exit 0). If `node` is unavailable, skip this step and rely on Task 6's staging check — a syntax error would stop the message list from loading, which is immediately visible.

- [ ] **Step 4: Commit**

```bash
git add plugins/avuz_prefetch/prefetch.js
git commit -m "perf(prefetch): persist the seen-UID map per tab

The map lived in a plain object, so any reload or task switch discarded it and
re-queued every visible UID. sessionStorage keeps it for the tab's lifetime,
keyed per folder. Also drops a stray console.log."
```

---

### Task 3: Run the filter pass once per request

`avuz_filters` hooks both `new_messages` and `refresh`. Both fire during a single refresh request, so the pass runs twice — visible on the wire as two identical `UID SEARCH RETURN (ALL) UID <n>:*` commands (`A0007` and `A0009` in the 2026-07-20 staging capture) and as duplicate lines in `avuz_filters.log`. Removing either hook is wrong: `avuz_filters.php:18-21` documents that `new_messages` fires only when `check_recent` sees a status diff, so `refresh` is the reliable trigger. The fix is a per-request guard.

**Files:**
- Modify: `plugins/avuz_filters/avuz_filters.php:22-24` (hooks), `:42-47` (`on_new_messages`)
- Create: `plugins/avuz_filters/tests/run_guard_test.php`
- Modify: `tests/phpunit.xml:29-67` (register both avuz plugin test files)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `avuz_run_guard::claim(string $token): bool` — returns true exactly once per token per PHP process.

- [ ] **Step 1: Write the failing test**

Create `plugins/avuz_filters/tests/run_guard_test.php`:

```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/run_guard.php';

class run_guard_test extends TestCase
{
    protected function setUp(): void { avuz_run_guard::reset(); }

    function testGrantsFirstClaim() {
        $this->assertTrue(avuz_run_guard::claim('filters'));
    }

    function testRefusesSecondClaimOfSameToken() {
        avuz_run_guard::claim('filters');
        $this->assertFalse(avuz_run_guard::claim('filters'));
    }

    function testTracksTokensIndependently() {
        avuz_run_guard::claim('filters');
        $this->assertTrue(avuz_run_guard::claim('prefetch'));
    }

    function testResetAllowsClaimingAgain() {
        avuz_run_guard::claim('filters');
        avuz_run_guard::reset();
        $this->assertTrue(avuz_run_guard::claim('filters'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/phpunit --no-coverage plugins/avuz_filters/tests/run_guard_test.php
```

Expected: FAIL — `Failed opening required '.../lib/run_guard.php'`.

- [ ] **Step 3: Write minimal implementation**

Create `plugins/avuz_filters/lib/run_guard.php`:

```php
<?php

/**
 * Once-per-request guard.
 *
 * avuz_filters hooks both 'new_messages' and 'refresh'; both fire in a single
 * refresh request, so the filter pass ran twice, issuing duplicate
 * UID SEARCH commands against Zoho. Neither hook can be dropped —
 * 'new_messages' fires only when check_recent detects a status diff, so
 * 'refresh' is the reliable trigger and 'new_messages' the timely one.
 *
 * State is per PHP process, which for PHP-FPM means per HTTP request.
 */
class avuz_run_guard
{
    private static $claimed = [];

    /** True the first time this token is claimed in this request, false after. */
    public static function claim($token)
    {
        if (isset(self::$claimed[$token])) {
            return false;
        }
        self::$claimed[$token] = true;
        return true;
    }

    /** Test seam. */
    public static function reset()
    {
        self::$claimed = [];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --no-coverage plugins/avuz_filters/tests/run_guard_test.php
```

Expected: `OK (4 tests, 4 assertions)`.

- [ ] **Step 5: Apply the guard in the plugin**

In `plugins/avuz_filters/avuz_filters.php`, add after the existing requires near the top of the file:

```php
require_once __DIR__ . '/lib/run_guard.php';
```

Replace `on_new_messages()` (currently `:42-47`) with:

```php
    function on_new_messages($args)
    {
        // Both 'new_messages' and 'refresh' fire in the same request; run once.
        if (!avuz_run_guard::claim('filters')) {
            return $args;
        }

        // Fires on check-recent when the server reports new mail. Sort before render.
        avuz_filter_runner::run(rcmail::get_instance(), false);
        return $args;
    }
```

Leave the three `add_hook` calls at `:22-24` unchanged — `login_after` calls `on_login`, which is a separate entry point and must still run.

- [ ] **Step 6: Register both avuz plugin test files in the suite**

`plugins/avuz_filters/tests/rule_engine_test.php` exists but is not listed in `tests/phpunit.xml`, so it has never run in CI. Add all three files. In `tests/phpunit.xml`, inside the `<testsuite name="Plugins">` block, immediately after the `nextcloud_sso` line at `:52`, insert:

```xml
      <file>./../plugins/avuz_filters/tests/rule_engine_test.php</file>
      <file>./../plugins/avuz_filters/tests/run_guard_test.php</file>
      <file>./../plugins/avuz_prefetch/tests/prefetch_cache_test.php</file>
```

- [ ] **Step 7: Run the registered suite**

```bash
vendor/bin/phpunit --no-coverage -c tests/phpunit.xml --testsuite Plugins --filter 'rule_engine|run_guard|prefetch_cache'
```

Expected: PASS, 17 tests total (5 rule_engine + 4 run_guard + 8 prefetch_cache).

- [ ] **Step 8: Lint and commit**

```bash
php -l plugins/avuz_filters/avuz_filters.php
php -l plugins/avuz_filters/lib/run_guard.php
git add plugins/avuz_filters/lib/run_guard.php \
        plugins/avuz_filters/tests/run_guard_test.php \
        plugins/avuz_filters/avuz_filters.php \
        tests/phpunit.xml
git commit -m "fix(filters): run the filter pass once per request

new_messages and refresh both fire during a single refresh, so the pass ran
twice and issued duplicate UID SEARCH commands to Zoho — visible as A0007 and
A0009 in the staging wire capture. Neither hook can be dropped: new_messages
fires only when check_recent sees a status diff, refresh is the reliable one.

Also registers the avuz plugin tests in phpunit.xml; rule_engine_test.php had
been present but never wired into the suite."
```

---

### Task 4: Enable ESEARCH for index queries

`rcube_imap_generic::search()` only requests ESEARCH when the criteria string contains a non-digit. With `skip_deleted` at its default `false` and no search term, the criteria is empty, so Roundcube issues plain `UID SEARCH ALL` and Zoho returns every UID individually — roughly 16,000 numbers (~100 kB) per message-list request on the largest account. Setting `skip_deleted = true` makes the criteria `UNDELETED`, which enables `UID SEARCH RETURN (ALL) UNDELETED` and compact ranges.

**Files:**
- Modify: `config/config.inc.php` (add after the `imap_timeout` line at `:35`)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Confirm the mechanism in core before changing config**

```bash
sed -n '/If ESEARCH is supported always use ALL/,/^        \$esearch/p' program/lib/Roundcube/rcube_imap_generic.php
```

Expected output includes:

```php
        if (empty($items) && preg_match('/[^0-9]/', $criteria)) {
            $items = ['ALL'];
        }
```

This is the gate: an empty `$criteria` fails `preg_match`, so `$items` stays empty and ESEARCH is never requested.

- [ ] **Step 2: Add the config**

In `config/config.inc.php`, immediately after `$config['imap_timeout'] = 15;` at `:35`, insert:

```php
// Zoho advertises ESEARCH but rcube_imap_generic::search() only requests it when
// the criteria string contains a non-digit. With skip_deleted=false the criteria
// for an unfiltered index query is empty, so Roundcube falls back to plain
// UID SEARCH ALL — ~16,000 individual UIDs per message-list request on our
// largest mailbox. skip_deleted=true makes the criteria 'UNDELETED', which
// enables UID SEARCH RETURN (ALL) and compact ranges.
// Safe on Zoho: deletions move to Lixeira rather than being flagged \Deleted
// in place. Verified in Task 6.
$config['skip_deleted'] = true;
```

- [ ] **Step 3: Verify the file parses**

```bash
php -l config/config.inc.php
```

Expected: `No syntax errors detected in config/config.inc.php`.

- [ ] **Step 4: Commit**

```bash
git add config/config.inc.php
git commit -m "perf(imap): enable ESEARCH via skip_deleted

rcube_imap_generic::search() requests ESEARCH only when the criteria contains a
non-digit. With skip_deleted=false an unfiltered index query has empty criteria,
so Roundcube issued plain UID SEARCH ALL and Zoho returned every UID
individually — ~16,000 numbers per message-list request on the largest account.

skip_deleted=true makes the criteria UNDELETED, enabling
UID SEARCH RETURN (ALL) and compact ranges. Zoho moves deletions to Lixeira
rather than flagging in place, so no mail is hidden."
```

---

### Task 5: Tune imapproxy, Redis, and nginx

Three infrastructure values. `cache_expiration_time 60` reaps idle backend connections after a minute — shorter than a normal reading pause — so the next action re-pays the ~820ms TCP+TLS+LOGIN. The staging capture measured a cold request at 2.95s against 1.21s warm for identical payload. Redis at 128mb is shared across sessions, `imap_cache`, and every prefetched body, so bodies are evicted first. nginx compresses nothing.

**Files:**
- Modify: `docker/imapproxy-sidecar/imapproxy.conf:8`
- Modify: `deploy/stack.reference.yml` (redis `command` line)
- Modify: `docker/nginx.conf`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Raise the idle connection lifetime**

In `docker/imapproxy-sidecar/imapproxy.conf`, replace line 8:

```
cache_expiration_time 1800
```

Leave `cache_size 200` and `enable_select_cache no` unchanged. `cache_size 200` is the concurrent-connection ceiling; at roughly 2 connections per user against Zoho's per-mailbox limit of 100, this is safe. `enable_select_cache` stays off deliberately — it serves stale counts and hides new mail.

- [ ] **Step 2: Raise the Redis ceiling**

In `deploy/stack.reference.yml`, replace the redis `command` line:

```yaml
    command: redis-server --maxmemory 512mb --maxmemory-policy allkeys-lru
```

512mb is a starting value, not a measured one — Step 6 of Task 6 measures evictions and decides whether it needs to go higher.

- [ ] **Step 3: Enable compression in nginx**

In `docker/nginx.conf`, inside the `server` block and before the first `location` block, insert:

```nginx
    # Zoho is 198ms away but the browser is not — compressing HTML/JS/CSS/JSON
    # cuts transfer on every AJAX response. Roundcube's list and search responses
    # are 80 kB of JSON uncompressed.
    gzip              on;
    gzip_vary         on;
    gzip_min_length   1024;
    gzip_proxied      any;
    gzip_comp_level   5;
    gzip_types        text/plain text/css text/javascript
                      application/javascript application/json application/xml
                      image/svg+xml;
```

- [ ] **Step 4: Verify the nginx config parses**

```bash
docker run --rm -v "$PWD/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro" nginx:alpine nginx -t
```

Expected: `syntax is ok` and `test is successful`. If the config uses directives only valid inside the project's full `nginx.conf` context, this standalone check may fail on unrelated lines — in that case skip it and rely on the container healthcheck in Task 6.

- [ ] **Step 5: Verify the compose file still parses**

```bash
docker compose -f deploy/stack.reference.yml config >/dev/null && echo VALID
```

Expected: `VALID`.

- [ ] **Step 6: Commit**

```bash
git add docker/imapproxy-sidecar/imapproxy.conf deploy/stack.reference.yml docker/nginx.conf
git commit -m "perf(infra): keep imapproxy connections warm, raise Redis, enable gzip

cache_expiration_time was 60s — shorter than any normal reading pause, so idle
backend connections were reaped and the next action re-paid the ~820ms
TCP+TLS+LOGIN. Staging measured 2.95s cold vs 1.21s warm for an identical
payload. 1800s keeps a connection across a pause; cache_size 200 still bounds
us well under Zoho's 100-per-mailbox limit at ~2 connections per user.

Redis 128mb was shared by sessions, imap_cache and every prefetched body, so
bodies were evicted first under allkeys-lru. 512mb is a starting point pending
eviction measurement.

nginx compressed nothing; list and search responses are ~80 kB of JSON."
```

---

### Task 6: Deploy to staging and measure

Wave 1's value is a claim until it is measured. This task re-runs the same measurements taken on 2026-07-20 so the before/after is directly comparable, and verifies the two behavioral risks (`skip_deleted` hiding mail, prefetch skipping messages it should warm).

**Files:**
- Create: `docs/superpowers/plans/2026-07-20-roundcube-wave1-results.md`

**Interfaces:**
- Consumes: all of Tasks 1-5.
- Produces: the measured numbers that decide Wave 2's scope.

- [ ] **Step 1: Build and push the image to staging**

```bash
./scripts/build-push.sh latest staging
```

Expected: build completes and pushes both the app image and the imapproxy sidecar image.

- [ ] **Step 2: Redeploy the staging stack**

The staging stack is `avuz-mail-roundcube-2` on Portainer endpoint 3, published on port 8091. Redeploy it through Portainer so it pulls the new images. Confirm the containers came back:

```bash
set -a; . scripts/deploy.env; set +a
curl -sk -H "X-API-Key: $PORTAINER_TOKEN" \
  "$PORTAINER_URL/api/endpoints/3/docker/containers/json" \
  | jq -r '.[] | select(.Names[]|test("roundcube-2")) | "\(.Names[0]|ltrimstr("/"))  \(.State)  \(.Status)"'
```

Expected: `roundcube`, `imapproxy`, `redis`, `postgres`, `broker` all `running`.

- [ ] **Step 3: Record a log marker**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 \
  sh -c "date -u '+%H:%M:%S UTC'; wc -l < /var/www/roundcube/logs/imap.log"
```

Record both values — Step 5 reads only lines after this point.

- [ ] **Step 4: Re-run the measurement set**

Log in to staging (port 8091) as the ~16,000-message account. DevTools → Network → filter XHR → Preserve log. Record the `Time` column for each:

Folder switch: idle 90s then open an unopened folder (D1); immediately open a second (D2); return to the first (D3); idle 90s and open a third (D4).

Message open: idle 90s then open a message from page 2 or later (E1); immediately open another (E2); re-open the first (E3).

Search: common term, this folder, subject (A); same term, this folder, entire message (B); same term, all folders, subject (C).

Baseline for comparison, measured 2026-07-20 on prod:

| Measurement | Before |
|---|---|
| A — search, this folder, subject | 2.20s |
| B — search, this folder, entire message | 2.94s |
| C — search, all folders, subject | 12.82s |
| Cold list (~80 kB) | 2.95s |
| Warm list (~80 kB) | 1.21s / 1.18s |
| Worst observed list request | 13.34s |

- [ ] **Step 5: Count IMAP commands per request**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 \
  sh -c "tail -n +<MARKER_LINE> /var/www/roundcube/logs/imap.log > /tmp/after.log; awk '
  /^\[/ {
    t=substr(\$0,14,8); split(t,a,\":\"); s=a[1]*3600+a[2]*60+a[3];
    if (\$0 ~ /Connecting/) {
      if (n>0) printf \"dur=%3ds cmds=%3d %s\n\", prev-start, n, cmd1;
      start=s; n=0; cmd1=\"\";
    }
    if (\$0 ~ / C: /) { n++; if (cmd1==\"\" && \$0 ~ /command/) { i=index(\$0,\"command\"); cmd1=substr(\$0,i,60) } }
    prev=s;
  }' /tmp/after.log"
```

Replace `<MARKER_LINE>` with the line count from Step 3 plus one.

Before: `_action=list` requests reached **84 commands / 6s**; `plugin.avuz_prefetch` reached **54 commands / 3s**. Expect both to drop sharply on the second and later visits to a folder, since Task 1 skips warmed messages.

- [ ] **Step 6: Check Redis evictions**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-redis-1 \
  redis-cli INFO stats | grep evicted_keys
```

If `evicted_keys` is climbing, 512mb is still too low — raise it and note the new value in the results doc.

- [ ] **Step 7: Verify `skip_deleted` hides no mail**

Two checks, not one. The second is the important one.

**7a — before/after comparison.** In the staging UI, confirm total message counts per folder match what they were before the change, and that no message visible before deployment has disappeared.

**7b — stray `\Deleted` flag.** The premise behind `skip_deleted` is that Zoho moves deletions to Lixeira rather than flagging in place. That premise covers Zoho's *own* UI, not other clients. `skip_deleted` hides any message carrying the flag, whatever set it — and a phone or desktop client that flags then defers the expunge (standard Apple Mail behavior) would make a message vanish from Roundcube's list, counts, badges **and search** while staying visible everywhere else.

Test it directly. Against the staging mailbox, flag a message `\Deleted` without expunging:

```
A1 LOGIN <user> <pass>
A2 SELECT INBOX
A3 UID STORE <uid> +FLAGS (\Deleted)
```

Then, in Roundcube: reload the folder and confirm whether that message is still listed, still counted in the folder total, still in the unread badge if it was unread, and still findable by searching its subject. Clear the flag afterwards with `UID STORE <uid> -FLAGS (\Deleted)`.

**If the message disappears from any of those, revert `skip_deleted` immediately** — it is a one-line config change and correctness outranks the ESEARCH win. Record the outcome in the results doc either way; this is the finding that decides whether the setting ships to prod.

- [ ] **Step 8: Verify prefetch still warms correctly**

Open a message that was never opened before. Confirm it displays with full body content. Then check the sentinel is not suppressing genuine work:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-2-redis-1 \
  redis-cli --scan --pattern '*#done*' | head -5
```

Expected: sentinel keys exist for messages already warmed. If a message renders with an empty body, the sentinel is being written before the bodies are cached — check that `mark_warm` runs after the inner loop, not before.

- [ ] **Step 9: Write up the results**

Create `docs/superpowers/plans/2026-07-20-roundcube-wave1-results.md` with the before/after table for all ten measurements, the command counts from Step 5, the eviction figure from Step 6, and a one-line verdict on whether Wave 2 is still needed at its currently specced scope.

- [ ] **Step 10: Commit**

```bash
git add docs/superpowers/plans/2026-07-20-roundcube-wave1-results.md
git commit -m "docs(perf): Wave 1 staging measurements

Before/after for the ten measurements taken on 2026-07-20, IMAP command counts
per request, and Redis eviction figures. Sets the scope for Wave 2."
```

---

## Not in this plan

Investigated and deliberately deferred:

- **Batching `BODY.PEEK` across MIME parts.** `rcube_imap.php:2097-2103` batches MIME header fetches only within one nesting level, and `_structure_part` recurses — its own `@TODO` at `:2098` acknowledges this. Fixing it means patching core, which costs us on every upstream rebase. Task 1 removes most of the traffic without touching it.
- **The `arrival` sort-column fallback.** Affects 12 of 97 users; Wave 2 removes the cost for everyone. Three interim fixes were considered and rejected — see the spec.
- **Gating the filter pass on `LIST-STATUS`.** Needs the sync layer from Wave 2.
- **Async send queue.** Wave 3, separate plan — it introduces a durable queue and a new failure mode (a send that appears to succeed and fails later).
