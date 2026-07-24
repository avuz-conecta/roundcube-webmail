# IndexedDB Body Cache (Zimbra-parity instant open) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Open any listed message instantly by proactively prefetching visible+lookahead message bodies into the browser's IndexedDB, filled from Redis-warmed server renders.

**Architecture:** Three tiers — Zoho → Redis (`avuz_prefetch`, extended to multifolder) → IndexedDB (new `avuz_body_cache` plugin). A client controller prefetches the visible + lookahead window per row-folder: it warms Redis via `plugin.avuz_prefetch`, then `fetch()`es each message's real preview render (with a new `_preload` flag that suppresses mark-`\Seen`) and stores the sanitized HTML in IndexedDB. A click reads from IndexedDB (`srcdoc`, ~0ms) and fires the real mark-`\Seen`. Cache miss falls through to a normal open (prefetch-only; no reactive iframe capture).

**Tech Stack:** PHP 8.2 (Roundcube plugin API, `rcube_plugin`, `rcube_cache` redis), vanilla client JS (IndexedDB, `fetch`, rcmail events), PHPUnit for pure PHP logic, Docker overlay build, staging verification (no local Docker).

**Spec:** `docs/superpowers/specs/2026-07-24-indexeddb-body-cache-design.md` (§12 + §13 authoritative).

## Global Constraints

- Base: Roundcube 1.6.14 fork, branch `avuz-customization`. Provider is Zoho.
- **No local Docker** — every runtime verification is: `./scripts/build-push.sh latest staging && ./scripts/deploy.sh -y avuz-mail-roundcube-2`, then check on staging (browser / logs via `./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 ...`).
- **Dockerfile overlays a specific file list** — any patched core/skin file (e.g. `show.php`) MUST get a `COPY` line in `Dockerfile`, and any patched `*.js` that has a `*.min.js` twin in the release needs the twin `rm`'d. Update `customizations.json` for every overlay.
- Feature flag `AVUZ_BODY_CACHE` (env, default off), mirroring `AVUZ_PIPELINED_SEARCH`. Nothing user-visible unless the flag is `1`.
- PHPUnit: run with `vendor/bin/phpunit -c tests/phpunit.xml <path>` (or the repo's configured config). Commit after every green step.
- Commit messages: Conventional Commits, no `Co-Authored-By` line (repo rule).
- Bodies are immutable per (folder, uid, uidvalidity) — the cache is a read cache; never treat it as truth for flags/existence.

---

## File Structure

- `program/actions/mail/show.php` — **modify**: gate the mark-`\Seen` block with `_preload`.
- `plugins/avuz_prefetch/lib/prefetch_cache.php` — **modify**: add a pure `parse_uid_folder_map()` helper.
- `plugins/avuz_prefetch/avuz_prefetch.php` — **modify**: `prefetch()` accepts per-UID folders (multifolder warm).
- `plugins/avuz_prefetch/tests/prefetch_cache_test.php` — **modify**: tests for the parser.
- `plugins/avuz_body_cache/avuz_body_cache.php` — **create**: server side (env emit, flag, include script).
- `plugins/avuz_body_cache/lib/body_cache.php` — **create**: pure helpers (HMAC user tag, sanitizer version, key string).
- `plugins/avuz_body_cache/tests/body_cache_test.php` — **create**: PHPUnit for the pure helpers.
- `plugins/avuz_body_cache/js/idb.js` — **create**: IndexedDB store (open/get/put/evict/dropIfUserChanged).
- `plugins/avuz_body_cache/js/controller.js` — **create**: prefetch controller (window, warm, fetch, store, defer).
- `plugins/avuz_body_cache/js/open.js` — **create**: open interception (srcdoc on hit + mark-seen; miss → normal).
- `plugins/avuz_body_cache/js/bodycache.js` — **create**: entry that wires idb+controller+open on `rcmail` init.
- `Dockerfile` — **modify**: overlay `show.php` + the new plugin; keep min-twin rule.
- `config/config.inc.php` — **modify**: load `avuz_body_cache` plugin (behind flag comment).
- `customizations.json` — **modify**: new entries.

Plugin JS is split into idb / controller / open / entry so each file has one responsibility and stays small enough to reason about; they are concatenated by the plugin's `include_script` calls.

---

## Task 1: `_preload` mark-seen gate in show.php

**Files:**
- Modify: `program/actions/mail/show.php` (the `if (empty($MESSAGE->headers->flags['SEEN']) && $MESSAGE->context === null)` block, ~line 128)
- Modify: `Dockerfile`
- Modify: `customizations.json`

**Interfaces:**
- Produces: preview/show renders with `?_preload=1` do not mark the message `\Seen` and do not arm the client read-timer. All other behavior identical.

- [ ] **Step 1: Add the `_preload` guard**

In `program/actions/mail/show.php`, change the mark-seen condition (currently):

```php
            if (empty($MESSAGE->headers->flags['SEEN']) && $MESSAGE->context === null) {
```

to:

```php
            // AVUZ: a background prefetch (avuz_body_cache) fetches this render with
            // _preload=1 only to warm the browser body cache — it is NOT a user open,
            // so it must not mark the message \Seen nor arm the client read-timer.
            $avuz_preload = rcube_utils::get_input_string('_preload', rcube_utils::INPUT_GET) === '1';
            if (!$avuz_preload && empty($MESSAGE->headers->flags['SEEN']) && $MESSAGE->context === null) {
```

- [ ] **Step 2: Add the Dockerfile overlay**

In `Dockerfile`, after the existing `program/actions/mail/index.php` COPY, add:

```dockerfile
# show.php: _preload flag suppresses mark-\Seen so avuz_body_cache can render a
# message body for the browser cache without marking it read. See customizations.json.
COPY program/actions/mail/show.php /var/www/roundcube/program/actions/mail/show.php
```

- [ ] **Step 3: Record the customization**

In `customizations.json` add an entry (in `entries`):

```json
{
  "id": "show-preload-no-mark-seen",
  "type": "core-patch",
  "paths": ["program/actions/mail/show.php", "Dockerfile"],
  "description": "A _preload=1 query flag on the show/preview action renders the body but suppresses marking the message \\Seen, so the avuz_body_cache background prefetch can warm the browser cache without marking mail read.",
  "risk": "low",
  "notes": "mail_read_time=0 makes show.php mark \\Seen immediately on render; the guard gates that single block. Re-check on rebase that the mark block still sits in show.php run() and that no other path marks \\Seen for a plain preview."
}
```

- [ ] **Step 4: Build, deploy, verify on staging**

Run:
```bash
./scripts/build-push.sh latest staging && ./scripts/deploy.sh -y avuz-mail-roundcube-2
```
Then, with an UNREAD message (uid U in folder F), from a logged-in staging session run in the browser devtools console:
```js
fetch(rcmail.url('preview', {_uid: U, _mbox: 'F', _framed: 1, _preload: 1}), {credentials:'same-origin'}).then(r=>r.text()).then(t=>console.log('len', t.length))
```
Expected: the body HTML returns (non-zero length) AND the message stays **unread** in the list (no `set_unread_message`). Compare: the same fetch without `_preload` marks it read.

- [ ] **Step 5: Commit**

```bash
git add program/actions/mail/show.php Dockerfile customizations.json
git commit -m "feat(show): _preload flag renders body without marking \\Seen"
```

---

## Task 2: Multifolder UID→folder parser in prefetch_cache

**Files:**
- Modify: `plugins/avuz_prefetch/lib/prefetch_cache.php`
- Test: `plugins/avuz_prefetch/tests/prefetch_cache_test.php`

**Interfaces:**
- Produces: `avuz_prefetch_cache::parse_uid_folder_map(string $uids, ?string $default_folder): array` → ordered list of `['uid' => int, 'folder' => string]`, at most `avuz_prefetch::MAX_UIDS` entries. Accepts either plain `"12,15,20"` (all in `$default_folder`) or folder-qualified `"12:INBOX,15:Sent"` tokens. Skips non-positive uids and entries with an empty resolved folder.

- [ ] **Step 1: Write the failing test**

Add to `plugins/avuz_prefetch/tests/prefetch_cache_test.php`:

```php
    public function test_parse_plain_uids_uses_default_folder()
    {
        $out = avuz_prefetch_cache::parse_uid_folder_map('12,15,20', 'INBOX');
        $this->assertSame(
            [['uid'=>12,'folder'=>'INBOX'],['uid'=>15,'folder'=>'INBOX'],['uid'=>20,'folder'=>'INBOX']],
            $out
        );
    }

    public function test_parse_folder_qualified_tokens()
    {
        $out = avuz_prefetch_cache::parse_uid_folder_map('12:INBOX,15:Sent', null);
        $this->assertSame(
            [['uid'=>12,'folder'=>'INBOX'],['uid'=>15,'folder'=>'Sent']],
            $out
        );
    }

    public function test_parse_skips_invalid_and_empty_folder()
    {
        $out = avuz_prefetch_cache::parse_uid_folder_map('0:INBOX,abc,15:,20:Sent', 'INBOX');
        $this->assertSame([['uid'=>20,'folder'=>'Sent']], $out);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit plugins/avuz_prefetch/tests/prefetch_cache_test.php`
Expected: FAIL — `Call to undefined method ...::parse_uid_folder_map()`.

- [ ] **Step 3: Implement the parser**

Add to `plugins/avuz_prefetch/lib/prefetch_cache.php` (inside the class):

```php
    /**
     * Parse a client uid list into an ordered [uid,folder] map.
     * Accepts "12,15" (default folder) or "12:INBOX,15:Sent" (folder-qualified).
     */
    public static function parse_uid_folder_map($uids, $default_folder)
    {
        $out = [];
        foreach (array_filter(explode(',', (string) $uids), 'strlen') as $token) {
            $pos    = strpos($token, ':');
            $rawUid = $pos === false ? $token : substr($token, 0, $pos);
            $folder = $pos === false ? (string) $default_folder : substr($token, $pos + 1);
            $uid    = (int) $rawUid;
            if ($uid <= 0 || $folder === '') {
                continue;
            }
            $out[] = ['uid' => $uid, 'folder' => $folder];
            if (count($out) >= 10) { // avuz_prefetch::MAX_UIDS
                break;
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit plugins/avuz_prefetch/tests/prefetch_cache_test.php`
Expected: PASS (all three new tests green).

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_prefetch/lib/prefetch_cache.php plugins/avuz_prefetch/tests/prefetch_cache_test.php
git commit -m "feat(prefetch): parse multifolder uid:folder maps"
```

---

## Task 3: Wire multifolder warming into avuz_prefetch::prefetch()

**Files:**
- Modify: `plugins/avuz_prefetch/avuz_prefetch.php` (the `prefetch()` method)

**Interfaces:**
- Consumes: `avuz_prefetch_cache::parse_uid_folder_map()` (Task 2).
- Produces: `plugin.avuz_prefetch` accepts either the legacy `_uids` + `_mbox` (single folder) or `_uids` with `uid:folder` tokens; each entry is warmed into Redis against its own folder via `new rcube_message($uid, $folder)`.

- [ ] **Step 1: Replace the uid/folder loop head**

In `plugins/avuz_prefetch/avuz_prefetch.php`, replace the current `$list`/`$folder` setup and `foreach` head in `prefetch()`:

```php
        $cache = $this->cache();
        $list  = array_slice(array_filter(explode(',', $uids), 'strlen'), 0, self::MAX_UIDS);
        $folder = $mbox !== null ? $mbox : $rcmail->storage->get_folder();

        foreach ($list as $rawUid) {
            $uid = (int) $rawUid;
            if ($uid <= 0) {
                continue;
            }
```

with:

```php
        $cache        = $this->cache();
        $defaultMbox  = $mbox !== null ? $mbox : $rcmail->storage->get_folder();
        $list         = avuz_prefetch_cache::parse_uid_folder_map($uids, $defaultMbox);

        foreach ($list as $entry) {
            $uid    = $entry['uid'];
            $folder = $entry['folder'];
```

- [ ] **Step 2: Use per-entry folder in the warm + is_warm calls**

In the same loop body, ensure the warm-check and message construction use `$folder` (per entry), not the old single `$folder`/`$mbox`:

```php
            if (avuz_prefetch_cache::is_warm($cache, (string) $folder, $uid)) {
                continue;
            }

            try {
                $message = new rcube_message($uid, $folder);
```

(Everything below — the `mime_parts` loop, `get_part_body`, storing — is unchanged; it already keys by `$message->folder`.)

- [ ] **Step 3: Verify existing unit tests still pass**

Run: `vendor/bin/phpunit plugins/avuz_prefetch/tests/prefetch_cache_test.php`
Expected: PASS (no regressions; the parser tests still green).

- [ ] **Step 4: Staging smoke — warm two folders in one call**

Build+deploy, then in the staging console:
```js
rcmail.http_post('plugin.avuz_prefetch', {_uids: 'U1:INBOX,U2:Sent'})
```
Then confirm both bodies are warm in Redis:
```bash
./scripts/portainer-exec.sh avuz-mail-roundcube-2-redis-1 sh -c 'redis-cli --scan --pattern "*avuz_body*" | head'
```
Expected: keys for both INBOX:U1 and Sent:U2 appear.

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_prefetch/avuz_prefetch.php
git commit -m "feat(prefetch): warm Redis per row-folder for multifolder search"
```

---

## Task 4: avuz_body_cache server — pure helpers + tests

**Files:**
- Create: `plugins/avuz_body_cache/lib/body_cache.php`
- Create: `plugins/avuz_body_cache/tests/body_cache_test.php`

**Interfaces:**
- Produces:
  - `avuz_body_cache_lib::user_tag(string $imap_user, string $des_key): string` → 16-hex-char stable opaque per-user tag (`substr(hash_hmac('sha256', user, key), 0, 16)`).
  - `avuz_body_cache_lib::SANITIZER_VERSION` (int const) — bump on any washtml/skin/CID render change.

- [ ] **Step 1: Write the failing test**

Create `plugins/avuz_body_cache/tests/body_cache_test.php`:

```php
<?php

class AvuzBodyCache_Lib extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        include_once __DIR__ . '/../lib/body_cache.php';
    }

    public function test_user_tag_is_stable_16_hex()
    {
        $a = avuz_body_cache_lib::user_tag('user@zoho.com', 'k' . str_repeat('x', 23));
        $b = avuz_body_cache_lib::user_tag('user@zoho.com', 'k' . str_repeat('x', 23));
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
    }

    public function test_user_tag_differs_per_user_and_key()
    {
        $key = 'k' . str_repeat('x', 23);
        $this->assertNotSame(
            avuz_body_cache_lib::user_tag('a@zoho.com', $key),
            avuz_body_cache_lib::user_tag('b@zoho.com', $key)
        );
        $this->assertNotSame(
            avuz_body_cache_lib::user_tag('a@zoho.com', $key),
            avuz_body_cache_lib::user_tag('a@zoho.com', 'other-key-000000000000000')
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit plugins/avuz_body_cache/tests/body_cache_test.php`
Expected: FAIL — file/class not found.

- [ ] **Step 3: Implement the lib**

Create `plugins/avuz_body_cache/lib/body_cache.php`:

```php
<?php

/**
 * Pure helpers for avuz_body_cache. No Roundcube runtime dependencies, so unit-testable.
 */
class avuz_body_cache_lib
{
    /** Bump on ANY change to how bodies are rendered/sanitized (washtml, skin, CID). */
    public const SANITIZER_VERSION = 1;

    /** Stable opaque per-user tag for namespacing the browser cache. */
    public static function user_tag($imap_user, $des_key)
    {
        return substr(hash_hmac('sha256', (string) $imap_user, (string) $des_key), 0, 16);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit plugins/avuz_body_cache/tests/body_cache_test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_body_cache/lib/body_cache.php plugins/avuz_body_cache/tests/body_cache_test.php
git commit -m "feat(body_cache): pure per-user tag + sanitizer version"
```

---

## Task 5: avuz_body_cache server — plugin (flag, env emit, scripts)

**Files:**
- Create: `plugins/avuz_body_cache/avuz_body_cache.php`

**Interfaces:**
- Consumes: `avuz_body_cache_lib` (Task 4).
- Produces: when `getenv('AVUZ_BODY_CACHE') === '1'`, emits to `rcmail.env`: `avuz_body_cache` (bool true), `avuz_cache_user` (tag), `avuz_sanitizer_version` (int), and includes `js/idb.js`, `js/controller.js`, `js/open.js`, `js/bodycache.js`. When the flag is not `1`, the plugin is inert (no env, no scripts).

- [ ] **Step 1: Implement the plugin**

Create `plugins/avuz_body_cache/avuz_body_cache.php`:

```php
<?php

require_once __DIR__ . '/lib/body_cache.php';

/**
 * avuz_body_cache — browser-side (IndexedDB) message-body cache with proactive
 * prefetch, for Zimbra-parity instant open. Server side is thin: emit the env the
 * client needs and include the client scripts. Gated by AVUZ_BODY_CACHE=1.
 */
class avuz_body_cache extends rcube_plugin
{
    public $task = 'mail';

    function init()
    {
        if (getenv('AVUZ_BODY_CACHE') !== '1') {
            return; // inert unless explicitly enabled
        }
        $this->add_hook('render_page', [$this, 'emit_env']);
        $this->include_script('js/idb.js');
        $this->include_script('js/controller.js');
        $this->include_script('js/open.js');
        $this->include_script('js/bodycache.js');
    }

    function emit_env($args)
    {
        $rcmail = rcmail::get_instance();
        $user   = (string) $rcmail->get_user_name();
        $deskey = (string) $rcmail->config->get('des_key');

        $rcmail->output->set_env('avuz_body_cache', true);
        $rcmail->output->set_env('avuz_cache_user', avuz_body_cache_lib::user_tag($user, $deskey));
        $rcmail->output->set_env('avuz_sanitizer_version', avuz_body_cache_lib::SANITIZER_VERSION);

        return $args;
    }
}
```

- [ ] **Step 2: Verify PHP lints**

Run: `php -l plugins/avuz_body_cache/avuz_body_cache.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_body_cache/avuz_body_cache.php
git commit -m "feat(body_cache): plugin flag + env emission"
```

---

## Task 6: Client — IndexedDB store (idb.js)

**Files:**
- Create: `plugins/avuz_body_cache/js/idb.js`

**Interfaces:**
- Produces global `window.avuzIdb` with:
  - `open(userTag): Promise<void>` — opens DB `avuz_body_cache` (store `bodies`, keyPath `k`, index `lastAccess`); if the stored `meta.user` differs from `userTag`, deletes and recreates the DB (drop-on-identity-change), then records `meta.user = userTag`.
  - `get(key): Promise<{html:string, lastAccess:number}|null>` — also bumps `lastAccess` on hit.
  - `put(key, html): Promise<void>` — stores `{k, html, bytes, lastAccess, createdAt}`.
  - `has(key): Promise<boolean>`.
  - `evictToBudget(maxEntries, maxBytes): Promise<void>` — LRU by `lastAccess`.
  - `clearAll(): Promise<void>`.

- [ ] **Step 1: Implement idb.js**

Create `plugins/avuz_body_cache/js/idb.js`:

```js
/* avuz_body_cache: IndexedDB store. One DB per origin, namespaced per user tag. */
(function () {
  var DB = 'avuz_body_cache', STORE = 'bodies', META = 'meta';
  var dbp = null, now = function () { return Date.now(); };

  function openRaw() {
    return new Promise(function (res, rej) {
      var r = indexedDB.open(DB, 1);
      r.onupgradeneeded = function () {
        var db = r.result;
        if (!db.objectStoreNames.contains(STORE)) {
          var s = db.createObjectStore(STORE, { keyPath: 'k' });
          s.createIndex('lastAccess', 'lastAccess');
        }
        if (!db.objectStoreNames.contains(META)) db.createObjectStore(META, { keyPath: 'id' });
      };
      r.onsuccess = function () { res(r.result); };
      r.onerror = function () { rej(r.error); };
    });
  }
  function del() { return new Promise(function (res) { var r = indexedDB.deleteDatabase(DB); r.onsuccess = r.onerror = function () { res(); }; }); }
  function tx(db, store, mode) { return db.transaction(store, mode).objectStore(store); }
  function pReq(req) { return new Promise(function (res, rej) { req.onsuccess = function () { res(req.result); }; req.onerror = function () { rej(req.error); }; }); }

  window.avuzIdb = {
    open: function (userTag) {
      dbp = openRaw().then(function (db) {
        return pReq(tx(db, META, 'readonly').get('user')).then(function (m) {
          if (m && m.value !== userTag) {
            db.close();
            return del().then(openRaw).then(function (db2) {
              return pReq(tx(db2, META, 'readwrite').put({ id: 'user', value: userTag })).then(function () { return db2; });
            });
          }
          if (!m) return pReq(tx(db, META, 'readwrite').put({ id: 'user', value: userTag })).then(function () { return db; });
          return db;
        });
      });
      return dbp.then(function () {});
    },
    has: function (k) { return dbp.then(function (db) { return pReq(tx(db, STORE, 'readonly').get(k)); }).then(function (v) { return !!v; }); },
    get: function (k) {
      return dbp.then(function (db) {
        return pReq(tx(db, STORE, 'readonly').get(k)).then(function (v) {
          if (!v) return null;
          v.lastAccess = now();
          tx(db, STORE, 'readwrite').put(v);
          return v;
        });
      });
    },
    put: function (k, html) {
      return dbp.then(function (db) {
        return pReq(tx(db, STORE, 'readwrite').put({ k: k, html: html, bytes: html.length, lastAccess: now(), createdAt: now() }));
      });
    },
    evictToBudget: function (maxEntries, maxBytes) {
      return dbp.then(function (db) {
        return pReq(tx(db, STORE, 'readonly').getAll()).then(function (all) {
          var bytes = all.reduce(function (s, e) { return s + (e.bytes || 0); }, 0);
          if (all.length <= maxEntries && bytes <= maxBytes) return;
          all.sort(function (a, b) { return a.lastAccess - b.lastAccess; }); // oldest first
          var s = tx(db, STORE, 'readwrite');
          for (var i = 0; i < all.length && (all.length - i > maxEntries || bytes > maxBytes); i++) {
            s.delete(all[i].k); bytes -= (all[i].bytes || 0);
          }
        });
      });
    },
    clearAll: function () { return dbp.then(function (db) { return pReq(tx(db, STORE, 'readwrite').clear()); }); }
  };
})();
```

- [ ] **Step 2: Node syntax check**

Run: `node -c plugins/avuz_body_cache/js/idb.js`
Expected: no output (valid).

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_body_cache/js/idb.js
git commit -m "feat(body_cache): IndexedDB store with per-user namespace + LRU"
```

---

## Task 7: Client — prefetch controller (controller.js)

**Files:**
- Create: `plugins/avuz_body_cache/js/controller.js`

**Interfaces:**
- Consumes: `window.avuzIdb` (Task 6), `rcmail`, `rcmail.env.{avuz_cache_user,avuz_sanitizer_version,mailbox}`, `rcmail.message_list.rows[*].{uid,folder}`.
- Produces global `window.avuzPrefetch` with:
  - `keyFor(folder, uid): string` — `userTag + '|' + folder + '|' + uid + '|' + sanitizerVersion` (uidvalidity added in Task 10).
  - `run(): void` — compute the visible+lookahead window from the current list, warm Redis per folder-batch, then fetch+store each uncached body. Throttled (3 concurrent), deferring while `rcmail.busy`, cancellable (a new `run()` supersedes the previous).

- [ ] **Step 1: Implement controller.js**

Create `plugins/avuz_body_cache/js/controller.js`:

```js
/* avuz_body_cache: prefetch controller. Fills IndexedDB for visible+lookahead rows. */
(function () {
  var CONCURRENCY = 3, token = 0;

  function userTag() { return rcmail.env.avuz_cache_user; }
  function sanitizerV() { return rcmail.env.avuz_sanitizer_version; }

  function windowRows() {
    // All rendered rows; each row carries uid and (for multifolder search) folder.
    var rows = (rcmail.message_list && rcmail.message_list.rows) || {}, out = [];
    for (var id in rows) {
      var r = rows[id];
      if (r && r.uid) out.push({ uid: String(r.uid), folder: r.folder || rcmail.env.mailbox });
    }
    return out; // rendered page already ~= visible + one page of lookahead in Elastic
  }

  function keyFor(folder, uid) { return userTag() + '|' + folder + '|' + uid + '|' + sanitizerV(); }

  function warmRedis(rows) {
    // Group into folder-qualified uid tokens, <=10 per POST (server caps at MAX_UIDS).
    var toks = rows.map(function (r) { return r.uid + ':' + r.folder; });
    for (var i = 0; i < toks.length; i += 10) {
      rcmail.http_post('plugin.avuz_prefetch', { _uids: toks.slice(i, i + 10).join(',') });
    }
  }

  function fetchBody(row) {
    var url = rcmail.url('preview', { _uid: row.uid, _mbox: row.folder, _framed: 1, _preload: 1, _safe: rcmail.env.show_images ? 1 : 0 });
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : null; });
  }

  function run() {
    if (!rcmail.env.avuz_body_cache) return;
    var myToken = ++token;
    var rows = windowRows();
    warmRedis(rows);

    var queue = rows.slice(), active = 0;
    function pump() {
      if (myToken !== token) return;                 // superseded
      if (rcmail.busy) { setTimeout(pump, 300); return; } // lose races to the user
      while (active < CONCURRENCY && queue.length) {
        (function (row) {
          active++;
          var key = keyFor(row.folder, row.uid);
          avuzIdb.has(key).then(function (hit) {
            if (hit || myToken !== token) return null;
            return fetchBody(row).then(function (html) {
              if (html && myToken === token) return avuzIdb.put(key, html);
            });
          }).then(function () {
            active--;
            avuzIdb.evictToBudget(500, 50 * 1024 * 1024);
            pump();
          }).catch(function () { active--; pump(); });
        })(queue.shift());
      }
    }
    pump();
  }

  window.avuzPrefetch = { keyFor: keyFor, run: run };
})();
```

- [ ] **Step 2: Node syntax check**

Run: `node -c plugins/avuz_body_cache/js/controller.js`
Expected: valid.

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_body_cache/js/controller.js
git commit -m "feat(body_cache): prefetch controller (window, warm, fetch, throttle)"
```

---

## Task 8: Client — open interception (open.js)

**Files:**
- Create: `plugins/avuz_body_cache/js/open.js`

**Interfaces:**
- Consumes: `window.avuzIdb`, `window.avuzPrefetch.keyFor`, `rcmail.show_message`, `rcmail.set_unread_message`.
- Produces: `window.avuzOpen.tryHit(uid, folder): Promise<boolean>` — if a cached body exists, writes it into the message content iframe via `srcdoc`, fires the real mark-`\Seen`, and resolves `true`; otherwise resolves `false` (caller does the normal open).

- [ ] **Step 1: Implement open.js**

Create `plugins/avuz_body_cache/js/open.js`:

```js
/* avuz_body_cache: instant open from cache. Miss -> caller falls back to normal open. */
(function () {
  function contentFrameWin() {
    var name = rcmail.env.contentframe, el = name && document.getElementById(name);
    return el ? el.contentWindow : null;
  }

  window.avuzOpen = {
    tryHit: function (uid, folder) {
      if (!rcmail.env.avuz_body_cache) return Promise.resolve(false);
      var key = avuzPrefetch.keyFor(folder, String(uid));
      return avuzIdb.get(key).then(function (rec) {
        if (!rec) return false;
        var win = contentFrameWin();
        if (!win) return false;                      // no preview frame -> let normal open run
        // Paint instantly from cache.
        var iframe = document.getElementById(rcmail.env.contentframe);
        iframe.removeAttribute('src');
        iframe.srcdoc = rec.html;
        // The fast path skipped the render that marks read: mark it now (real open).
        rcmail.set_unread_message(uid, folder);
        rcmail.http_post('mark', { _uid: uid, _mbox: folder, _flag: 'read' });
        return true;
      }).catch(function () { return false; });
    }
  };
})();
```

- [ ] **Step 2: Node syntax check**

Run: `node -c plugins/avuz_body_cache/js/open.js`
Expected: valid.

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_body_cache/js/open.js
git commit -m "feat(body_cache): instant open from cache + mark-seen"
```

---

## Task 9: Client — entry wiring (bodycache.js) + config + Dockerfile

**Files:**
- Create: `plugins/avuz_body_cache/js/bodycache.js`
- Modify: `config/config.inc.php` (register plugin)
- Modify: `Dockerfile` (overlay plugin)
- Modify: `customizations.json`

**Interfaces:**
- Consumes: `avuzIdb.open`, `avuzPrefetch.run`, `avuzOpen.tryHit`.
- Produces: on `rcmail` init, opens IndexedDB for the user tag, runs the controller on `afterlist`/`listupdate`, intercepts message open to try a cache hit first, and clears the cache on logout.

- [ ] **Step 1: Implement the entry**

Create `plugins/avuz_body_cache/js/bodycache.js`:

```js
/* avuz_body_cache: wire the store, controller and open-interception into rcmail. */
(function () {
  if (!window.rcmail) return;
  rcmail.addEventListener('init', function () {
    if (!rcmail.env.avuz_body_cache || !window.indexedDB) return;

    avuzIdb.open(rcmail.env.avuz_cache_user).then(function () {
      var schedule, t;
      schedule = function () { clearTimeout(t); t = setTimeout(function () { avuzPrefetch.run(); }, 300); };
      rcmail.addEventListener('afterlist', schedule);
      rcmail.addEventListener('listupdate', schedule);
      schedule();
    });

    // Try cache before a normal open. Intercept the row-select command path.
    var baseShow = rcmail.show_message;
    rcmail.show_message = function (id, safe, preview) {
      if (preview && id) {
        var folder = (rcmail.message_list && rcmail.message_list.rows[rcmail.message_list.get_row_uid ? id : id])
          ? (rcmail.message_list.rows[id].folder || rcmail.env.mailbox) : rcmail.env.mailbox;
        avuzOpen.tryHit(id, folder).then(function (hit) {
          if (!hit) baseShow.call(rcmail, id, safe, preview);
        });
        return;
      }
      return baseShow.call(rcmail, id, safe, preview);
    };

    rcmail.addEventListener('logout', function () { try { avuzIdb.clearAll(); } catch (e) {} });
  });
})();
```

> Note for implementer: `id` passed to `show_message` is the list row id; `rcmail.message_list.rows[id].uid` is the real uid and `.folder` the per-row folder (multifolder search). If the row lookup proves unreliable across Elastic versions, resolve uid/folder from `rcmail.env.uid`/`rcmail.env.mailbox` after selection instead — verify on staging in Step 4.

- [ ] **Step 2: Register the plugin**

In `config/config.inc.php`, add `avuz_body_cache` to the `plugins` array (find the existing `$config['plugins']` list and append):

```php
    'avuz_body_cache',   // AVUZ: browser IndexedDB body cache (inert unless AVUZ_BODY_CACHE=1)
```

- [ ] **Step 3: Overlay the plugin in the Dockerfile**

In `Dockerfile`, after the existing plugin COPYs, add:

```dockerfile
# avuz_body_cache: browser IndexedDB body-cache plugin (inert unless AVUZ_BODY_CACHE=1). See customizations.json.
COPY plugins/avuz_body_cache /var/www/roundcube/plugins/avuz_body_cache
```

- [ ] **Step 4: Record the customization**

Add to `customizations.json` `entries`:

```json
{
  "id": "avuz-body-cache-plugin",
  "type": "plugin",
  "paths": ["plugins/avuz_body_cache", "config/config.inc.php", "Dockerfile"],
  "description": "Browser-side IndexedDB message-body cache with proactive prefetch of visible+lookahead rows, for Zimbra-parity instant open. Warms Redis via avuz_prefetch, fetches the real preview render with _preload=1, stores sanitized HTML per user tag, opens via srcdoc. Gated by AVUZ_BODY_CACHE=1.",
  "risk": "medium",
  "notes": "Depends on the show.php _preload patch (show-preload-no-mark-seen) and the multifolder avuz_prefetch warm. Per-user namespaced by HMAC(imap_user, des_key); drops the DB on identity change; clears on logout. Immutable bodies => no cache coherency. Bump avuz_body_cache_lib::SANITIZER_VERSION on any washtml/skin render change. Client JS is plain (no *.min twin)."
}
```

- [ ] **Step 5: Node syntax check + build + deploy**

Run: `node -c plugins/avuz_body_cache/js/bodycache.js` (expect valid), then build+deploy staging.

- [ ] **Step 6: Commit**

```bash
git add plugins/avuz_body_cache/js/bodycache.js config/config.inc.php Dockerfile customizations.json
git commit -m "feat(body_cache): wire store+controller+open, register plugin"
```

---

## Task 10: UIDVALIDITY in the cache key

**Files:**
- Modify: `plugins/avuz_body_cache/avuz_body_cache.php` (emit per-folder uidvalidity)
- Modify: `plugins/avuz_body_cache/js/controller.js` (`keyFor` includes uidvalidity; purge folder on mismatch)

**Interfaces:**
- Consumes: `rcube_imap::folder_data()['UIDVALIDITY']`.
- Produces: `rcmail.env.avuz_uidvalidity` = `{ folder: uidvalidity }` for folders seen this page; `keyFor(folder, uid)` includes it; a folder whose uidvalidity changed has its entries ignored (treated as miss) and is re-prefetched.

- [ ] **Step 1: Emit uidvalidity for the current mailbox**

In `emit_env()` (Task 5 plugin), before `return $args;` add:

```php
        $map  = [];
        $mbox = $rcmail->output->get_env('mailbox') ?: $rcmail->storage->get_folder();
        if ($mbox) {
            $data = $rcmail->storage->folder_data($mbox);
            if (!empty($data['UIDVALIDITY'])) {
                $map[$mbox] = (string) $data['UIDVALIDITY'];
            }
        }
        $rcmail->output->set_env('avuz_uidvalidity', $map);
```

> For multifolder search, per-folder uidvalidity for the other folders is filled lazily client-side by treating an unknown-folder uidvalidity as `'0'` (still a stable key within a session); a follow-up may surface all folders' uidvalidity. Note this limitation in the commit.

- [ ] **Step 2: Include uidvalidity in the client key**

In `controller.js`, change `keyFor`:

```js
  function uidv(folder) { var m = rcmail.env.avuz_uidvalidity || {}; return m[folder] || '0'; }
  function keyFor(folder, uid) { return userTag() + '|' + folder + '|' + uidv(folder) + '|' + uid + '|' + sanitizerV(); }
```

- [ ] **Step 3: Node syntax check**

Run: `node -c plugins/avuz_body_cache/js/controller.js`
Expected: valid.

- [ ] **Step 4: Commit**

```bash
git add plugins/avuz_body_cache/avuz_body_cache.php plugins/avuz_body_cache/js/controller.js
git commit -m "feat(body_cache): include UIDVALIDITY in the cache key"
```

---

## Task 11: Enable on staging + full acceptance verification

**Files:** none (config/env + verification only)

- [ ] **Step 1: Turn the flag on for staging**

Set `AVUZ_BODY_CACHE=1` in the staging stack environment (same mechanism used for `AVUZ_PIPELINED_SEARCH`: edit the stack compose env in Portainer for `avuz-mail-roundcube-2`, then redeploy). Confirm:

```bash
./scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 sh -c 'echo BODYCACHE=$AVUZ_BODY_CACHE'
```
Expected: `BODYCACHE=1`.

- [ ] **Step 2: Prefetch fills IndexedDB (folder)**

Open a folder on staging. In devtools → Application → IndexedDB → `avuz_body_cache` → `bodies`: entries appear for the visible rows within ~1–2s. Network panel shows `?_action=preview...&_preload=1` requests, throttled ~3 concurrent.

- [ ] **Step 3: Instant open, no premature read**

Confirm the prefetched messages are still **unread** in the list after prefetch (Task 1 held). Click one: it paints from cache (no new `preview` request on the wire for the body) and only *then* does a `mark`/`set_unread_message` fire. Message becomes read.

- [ ] **Step 4: Search (multifolder) also instant**

Run an all-folders search. Confirm IndexedDB fills for the result rows (keys span multiple folders), Redis warms (`redis-cli --scan --pattern "*avuz_body*"` shows multiple folders), and opening a result is instant.

- [ ] **Step 5: Per-user isolation + logout clear**

Log out → IndexedDB `bodies` store is empty (logout clear). Log in as a different mailbox → no previous user's entries are visible (drop-on-identity-change). Verify the `meta.user` tag changed.

- [ ] **Step 6: Measure**

In the console, time a warm open: from click to the cached iframe painting should be well under ~100ms with zero body network. Record the number vs a cold (flag-off) open for the spec's success metric.

- [ ] **Step 7: Commit any config/doc changes and update the spec status**

Update the spec header status to `implemented (staging)` and commit:

```bash
git add docs/superpowers/specs/2026-07-24-indexeddb-body-cache-design.md
git commit -m "docs(cache): mark IndexedDB body cache implemented on staging"
```

---

## Self-Review Notes

- **Spec coverage:** §12 prefetch (Tasks 7,9), §13.1 prefetch-only/no reactive capture (open.js miss → normal open; no iframe-capture code), §13.2 `_preload` (Task 1), §13.3 multifolder warm (Tasks 2,3) + per-folder fetch (Task 7), §13.4 one controller + throttle/defer (Task 7) + supersede prefetch.js client loop (note below), §13.5 mark-seen on hit (Task 8), §5 namespacing/sanitizerVersion (Tasks 4,5,10), §7 eviction (Task 6), §10 flag/rollout (Tasks 5,11).
- **prefetch.js retirement:** the spec says the new controller supersedes `prefetch.js`'s *client* loop. To avoid double-warming while the flag is on, once Task 11 passes, either gate `prefetch.js`'s `startRun` behind `!rcmail.env.avuz_body_cache` or remove its `afterlist`/`listupdate` binding — do this as a small follow-up commit after acceptance, not before (keep folder warming working if the flag is off). Left as a deliberate post-acceptance step, not a hidden cap.
- **Open items carried (non-blocking):** all-folders UIDVALIDITY for multifolder search (Task 10 uses `'0'` fallback), inline images re-fetch (accepted v1), bulk endpoint B (scale hatch, unbuilt), at-rest encryption (none, per §5).
