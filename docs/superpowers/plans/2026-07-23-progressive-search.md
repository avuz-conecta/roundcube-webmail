# Progressive Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show cross-folder search results as each folder completes, so a search that takes 200 seconds stops looking like a hang.

**Architecture:** Roundcube already searches incrementally, already caches completed folder results in the session, and already re-issues the request via `continue_search`. It just refuses to render anything until every folder is done (`search.php` fetches headers only when `!incomplete`, and forces `$count = 0` "to keep UI locked"). This plan removes that lock, makes the per-round time limit configurable and short so rounds paint often, bounds the total so a hopeless search ends honestly, and warns on whole-message search. No change to how results are gathered.

**Tech Stack:** PHP 8.2, Roundcube 1.6.14, PHPUnit 9.6 (`vendor/bin/phpunit`, config `tests/phpunit.xml`).

**Spec:** `docs/superpowers/specs/2026-07-23-progressive-search-design.md`

## Global Constraints

- Run tests from the `tests/` directory: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter <name>`.
- **Baselines. A run is clean only if it matches these exactly.** `Framework`: 7 pre-existing errors, 0 failures. `Plugins`: 1 pre-existing error and 1 pre-existing failure (Enigma crypto-key config, Password bcrypt-cost env). `Actions`: run it once before starting and record its baseline — do not assume zero. Never "fix" a pre-existing failure.
- **Every core patch needs a `COPY` line in `Dockerfile` AND an entry in `customizations.json`.** A core file without its COPY is rebuilt from the release tarball and the patch is silently inert. This has already shipped dead code twice on this project. Verify by grepping the *running container*, never the repo.
- **Do not patch `program/js/app.js`.** Client-side work goes in plugin JS, following `plugins/nextcloud_sso/avuz-overrides.js`.
- New config keys: `imap_search_timelimit` (int, seconds, default 60) and `imap_search_total_timelimit` (int, seconds, default 120).
- `php -l <file>` is the syntax check. There is no linter or formatter.
- Commit messages must not mention Claude or Claude Code.

---

## File Structure

| File | Responsibility |
|---|---|
| `program/actions/mail/search.php` (modify) | Render partial results; emit `continue_search` independently of whether rows rendered; enforce the total bound |
| `program/lib/Roundcube/rcube_imap.php` (modify) | Read the per-round time limit from config instead of the hardcoded 60 |
| `config/config.inc.php` (modify) | Set both new limits for this deployment |
| `tests/Actions/Mail/Search.php` (modify) | Tests for the incomplete-result behaviour |
| `plugins/avuz_search_notice/` (create) | Plugin: warn when the user searches whole-message |
| `Dockerfile` (modify) | COPY both patched core files and the new plugin |
| `customizations.json` (modify) | Register both core patches and the plugin |

---

### Task 1: Render partial results while a search is still running

The core change. Today `search.php` fetches headers only when the result is complete, so a
cross-folder search shows nothing until every folder is done.

**Files:**
- Modify: `program/actions/mail/search.php` (the block currently at lines 123-158)
- Modify: `tests/Actions/Mail/Search.php`
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: no new API. Behavioural contract later tasks rely on: when `$result->incomplete` is
  truthy, the action renders the rows found so far **and** emits `continue_search`.

- [ ] **Step 1: Record the Actions suite baseline**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Actions`

Write the exact tests/errors/failures line into your report. Every later step compares against it.

- [ ] **Step 2: Write the failing test**

Add to `tests/Actions/Mail/Search.php`, inside the `Actions_Mail_Search` class:

```php
    /**
     * An unfinished cross-folder search must still render the rows found so far,
     * and must still ask the client to continue.
     */
    function test_search_incomplete_renders_partial_results()
    {
        $action = new rcmail_action_mail_search;
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $_GET = [
            '_q'     => 'test',
            '_mbox'  => 'INBOX',
            '_scope' => 'all',
        ];

        $index = new rcube_result_index('INBOX', 'SEARCH 10');

        $partial = new rcube_result_multifolder(['INBOX', 'Archive']);
        $partial->add($index);
        $partial->incomplete = true;

        self::initStorage()
            ->registerFunction('set_page')
            ->registerFunction('set_search_set')
            ->registerFunction('search', $partial)
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('list_messages', [
                10 => rcube_message_header::from_array([
                    'id'           => 42,
                    'uid'          => 10,
                    'subject'      => 'partial hit',
                    'from'         => 'test1@test.com',
                    'to'           => 'Test <test2@test.com>',
                    'date'         => 'Sun, 13 Mar 2022 17:08:18 +0100',
                    'size'         => 889,
                    'content-type' => 'text/plain',
                ]),
            ])
            ->registerFunction('get_error_code', null)
            ->registerFunction('count', 1)
            ->registerFunction('count', 1)
            ->registerFunction('folder_data', [])
            ->registerFunction('get_quota', false);

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        // the row the completed folder found must be on screen
        $this->assertTrue(strpos($result['exec'], 'partial hit') !== false,
            'partial results must be rendered while the search is still running');

        // and the client must still be told to continue
        $this->assertTrue(strpos($result['exec'], 'this.continue_search(') !== false,
            'an incomplete search must still ask the client to continue');

        // the UI must not be told there are zero messages
        $this->assertNotSame(0, $result['env']['messagecount'],
            'messagecount must reflect what has been found so far, not 0');
    }
```

- [ ] **Step 3: Run it and watch it fail**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter test_search_incomplete_renders_partial_results`

Expected: FAIL. Today the action takes the `incomplete` branch, sets `$count = 0` and never calls
`list_messages`, so `'partial hit'` is absent from `exec`.

Record the actual failure message in your report. If it fails for some other reason — a missing
mock registration, say — fix the test until it fails for the right reason before going on.

- [ ] **Step 4: Restructure the block in `search.php`**

Replace this entire block (currently lines 123-158, from the `// Get the headers` comment down to
the `else if (!empty($result) && !empty($result->incomplete))` branch):

```php
        // Get the headers
        if (!isset($result) || empty($result->incomplete)) {
            $result_h = $rcmail->storage->list_messages($mbox, 1, $sort_column, $sort_order);
        }

        // Make sure we got the headers
        if (!empty($result_h)) {
            $count = $rcmail->storage->count($mbox, $rcmail->storage->get_threading() ? 'THREADS' : 'ALL');

            self::js_message_list($result_h, false);

            if ($search_str) {
                $all_count = $rcmail->storage->count(null, 'ALL');
                $rcmail->output->show_message('searchsuccessful', 'confirmation', ['nr' => $all_count]);
            }

            // remember last HIGHESTMODSEQ value (if supported)
            // we need it for flag updates in check-recent
            if ($mbox !== null) {
                $data = $rcmail->storage->folder_data($mbox);
                if (!empty($data['HIGHESTMODSEQ'])) {
                    $_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
                }
            }
        }
        // handle IMAP errors (e.g. #1486905)
        else if ($err_code = $rcmail->storage->get_error_code()) {
            $count = 0;
            self::display_server_error();
        }
        // advice the client to re-send the (cross-folder) search request
        else if (!empty($result) && !empty($result->incomplete)) {
            $count = 0;  // keep UI locked
            $rcmail->output->command('continue_search', $search_request);
        }
```

with:

```php
        // AVUZ PATCH — progressive search.
        //
        // A cross-folder search that has not finished still holds complete
        // results for the folders that DID finish: rcube_imap_search::exec()
        // caches them and reuses them on the next round. Upstream refuses to
        // list them, forcing count to 0 "to keep UI locked", so the user sees
        // an empty screen behind a spinner for as long as the whole search
        // takes — measured at up to 212s on this deployment, which is why
        // people abandon searches instead of waiting.
        //
        // List them instead. This costs ONE FETCH of at most mail_pagesize
        // headers; it does not re-search anything.
        $incomplete = !empty($result) && !empty($result->incomplete);
        $result_h   = $rcmail->storage->list_messages($mbox, 1, $sort_column, $sort_order);

        // Make sure we got the headers
        if (!empty($result_h)) {
            $count = $rcmail->storage->count($mbox, $rcmail->storage->get_threading() ? 'THREADS' : 'ALL');

            self::js_message_list($result_h, false);

            // Only claim success once. While incomplete the client keeps its
            // own "still searching" state, and announcing a total that is
            // about to grow would be a lie.
            if ($search_str && !$incomplete) {
                $all_count = $rcmail->storage->count(null, 'ALL');
                $rcmail->output->show_message('searchsuccessful', 'confirmation', ['nr' => $all_count]);
            }

            // remember last HIGHESTMODSEQ value (if supported)
            // we need it for flag updates in check-recent
            if ($mbox !== null) {
                $data = $rcmail->storage->folder_data($mbox);
                if (!empty($data['HIGHESTMODSEQ'])) {
                    $_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
                }
            }
        }
        // handle IMAP errors (e.g. #1486905)
        else if ($err_code = $rcmail->storage->get_error_code()) {
            $count = 0;
            self::display_server_error();
        }
        else if ($incomplete) {
            // nothing found YET — no rows, but the search is still running
            $count = 0;
        }
```

Then, immediately after that block and **before** the `else {` branch that handles "no match",
insert the continuation request so it fires whether or not rows were rendered:

```php
        // Ask the client to continue, independently of whether we just rendered
        // rows. Upstream only reached this inside the no-rows branch, so simply
        // listing partial results would have silently stopped the search.
        if ($incomplete) {
            $rcmail->output->command('continue_search', $search_request);
        }
```

**Get the placement right.** The final `else { ... searchnomatch ... }` must still only run when the
search is genuinely finished and empty. If `$incomplete` is true it must not run, or the user is
told "no matches" while the search is still going.

- [ ] **Step 5: Verify it passes**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Actions_Mail_Search`

Expected: PASS, including the two pre-existing tests in that file.

- [ ] **Step 6: Verify the whole Actions suite against your Step 1 baseline**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Actions`

Expected: identical to the Step 1 baseline. Any new failure is yours.

- [ ] **Step 7: Ship the patched core file**

In `Dockerfile`, immediately after the line
`COPY program/lib/Roundcube/rcube_session.php /var/www/roundcube/program/lib/Roundcube/rcube_session.php`,
add:

```dockerfile
# search.php: progressive search — render partial cross-folder results instead of
# holding the UI blank until every folder finishes. Without this COPY the build
# uses the stock file and searches look like a hang again.
COPY program/actions/mail/search.php /var/www/roundcube/program/actions/mail/search.php
```

- [ ] **Step 8: Confirm it would actually ship**

Run: `grep -c "actions/mail/search.php" Dockerfile`
Expected: `1`

- [ ] **Step 9: Commit**

```bash
git add program/actions/mail/search.php tests/Actions/Mail/Search.php Dockerfile
git commit -m "fix(search): render cross-folder results as folders complete

Roundcube already searches incrementally and caches each finished folder's
result for the next round, but search.php only fetched headers when the whole
search was complete and forced count to 0 'to keep UI locked'. So a cross-folder
search showed an empty list behind a spinner for its entire duration - measured
at up to 212s on prod, which is why users abandon searches rather than wait.

List the accumulated results on every round instead. Costs one FETCH of at most
mail_pagesize headers; nothing is re-searched.

The continuation request moves out of the no-rows branch, because once rows are
rendered that branch is no longer reached and the search would have stopped
after the first round."
```

---

### Task 2: Make the per-round time limit configurable and short

With partial results rendering, the search time limit becomes the repaint interval. 60 seconds is
far too long to wait for the first rows.

**Files:**
- Modify: `program/lib/Roundcube/rcube_imap.php:1655`
- Modify: `config/config.inc.php`
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: Task 1's behaviour (partial results are rendered each round).
- Produces: config key `imap_search_timelimit` (int seconds, default 60).

- [ ] **Step 1: Make the limit configurable**

In `program/lib/Roundcube/rcube_imap.php`, replace:

```php
            // set limit to not exceed the client's request timeout
            $searcher->set_timelimit(60);
```

with:

```php
            // Set limit to not exceed the client's request timeout. With
            // progressive search this is also the repaint interval: the shorter
            // it is, the sooner the user sees the first rows, at the cost of
            // more continuation round trips. Configurable so it can be tuned
            // without a code change; upstream's value is the default.
            $searcher->set_timelimit((int) $this->options['search_timelimit'] ?: 60);
```

**Verify how `$this->options` is populated before you write this.** `rcube_imap::$options` comes
from `rcube::storage_init()`, which builds the array explicitly — if `search_timelimit` is not
among the keys it passes through, this reads null and you will silently get 60 forever. If it is not
plumbed, read the config directly instead:

```php
            $searcher->set_timelimit((int) rcube::get_instance()->config->get('imap_search_timelimit', 60));
```

Use whichever is correct for this codebase, and state in your report which one you used and why.

- [ ] **Step 2: Prove the config value is actually read**

This is the step that catches the silent-null failure. Add a temporary line immediately after the
`set_timelimit(...)` call:

```php
            error_log('AVUZ timelimit=' . var_export($searcher_timelimit_debug ?? 'n/a', true));
```

Simpler and sufficient: run PHP directly to confirm the config lookup returns your value.

```bash
php -r '
require "program/include/iniset.php";
$c = new rcube_config();
var_dump($c->get("imap_search_timelimit", 60));
'
```

Expected after Step 3: `int(8)`. Remove any temporary debugging before committing.

- [ ] **Step 3: Set the values for this deployment**

In `config/config.inc.php`, immediately after the `$config['refresh_interval'] = 120;` block, add:

```php
// -- Search pacing --
// With progressive search (see docs/superpowers/specs/2026-07-23-progressive-search-design.md)
// this is the repaint interval, not just a safety cap: each round searches for
// this long, renders what it found, and asks the client to continue. Upstream
// hardcoded 60s, which meant the first rows could be a minute away. 8s trades a
// few more round trips for results that start appearing almost immediately.
$config['imap_search_timelimit'] = 8;

// Hard ceiling on a single logical search across all its continuation rounds.
// Without this the client loops forever (app.js re-issues every 100ms), which is
// the "search never finishes" complaint this work exists to fix. On reaching it
// the user is told the search was stopped and the results are partial.
$config['imap_search_total_timelimit'] = 120;
```

- [ ] **Step 4: Verify syntax and the config value**

```bash
php -l config/config.inc.php
php -l program/lib/Roundcube/rcube_imap.php
```
Both: `No syntax errors detected`

- [ ] **Step 5: Run the suites**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Actions && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`

Expected: Actions matches Task 1 Step 1's baseline; Framework shows 7 errors, 0 failures.

- [ ] **Step 6: Ship the patched core file**

In `Dockerfile`, immediately after the `search.php` COPY added in Task 1, add:

```dockerfile
# rcube_imap.php: search time limit read from config (imap_search_timelimit)
# instead of a hardcoded 60s. With progressive search that value is the repaint
# interval. Without this COPY the build uses the stock file and the setting is
# silently ignored.
COPY program/lib/Roundcube/rcube_imap.php /var/www/roundcube/program/lib/Roundcube/rcube_imap.php
```

- [ ] **Step 7: Commit**

```bash
git add program/lib/Roundcube/rcube_imap.php config/config.inc.php Dockerfile
git commit -m "feat(search): configurable search round limit, set to 8s

The 60s search time limit was hardcoded. With progressive search rendering
partial results each round, that limit is the repaint interval - so 60s meant
the first rows could be a minute away.

Read it from imap_search_timelimit (default 60, upstream's value) and set 8 for
this deployment. Also adds imap_search_total_timelimit, consumed by the next
task to stop the continuation loop running forever."
```

---

### Task 3: Bound the total search, and say so

`app.js` re-issues the search every 100ms for as long as the server keeps saying "incomplete", with
no ceiling. Rendering partial results makes that tolerable to look at but does not make it finite.

**Files:**
- Modify: `program/actions/mail/search.php`
- Modify: `tests/Actions/Mail/Search.php`

**Interfaces:**
- Consumes: `imap_search_total_timelimit` from Task 2; `$incomplete` from Task 1.
- Produces: no new API.

- [ ] **Step 1: Write the failing test**

Add to `tests/Actions/Mail/Search.php`:

```php
    /**
     * Once the total budget is spent the client must NOT be asked to continue,
     * so the search ends instead of looping forever.
     */
    function test_search_stops_continuing_past_total_timelimit()
    {
        $action = new rcmail_action_mail_search;
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $_GET = [
            '_q'        => 'test',
            '_mbox'     => 'INBOX',
            '_scope'    => 'all',
            '_continue' => 'searchreq1',
        ];

        // this logical search started well beyond the 120s budget
        $_SESSION['search_start'] = time() - 999;

        $index = new rcube_result_index('INBOX', 'SEARCH 10');

        $partial = new rcube_result_multifolder(['INBOX', 'Archive']);
        $partial->add($index);
        $partial->incomplete = true;

        self::initStorage()
            ->registerFunction('set_page')
            ->registerFunction('set_search_set')
            ->registerFunction('search', $partial)
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('list_messages', [
                10 => rcube_message_header::from_array([
                    'id'           => 42,
                    'uid'          => 10,
                    'subject'      => 'partial hit',
                    'from'         => 'test1@test.com',
                    'to'           => 'Test <test2@test.com>',
                    'date'         => 'Sun, 13 Mar 2022 17:08:18 +0100',
                    'size'         => 889,
                    'content-type' => 'text/plain',
                ]),
            ])
            ->registerFunction('get_error_code', null)
            ->registerFunction('count', 1)
            ->registerFunction('count', 1)
            ->registerFunction('folder_data', [])
            ->registerFunction('get_quota', false);

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        $this->assertFalse(strpos($result['exec'], 'this.continue_search(') !== false,
            'past the total budget the client must not be asked to continue');
        $this->assertTrue(strpos($result['exec'], 'partial hit') !== false,
            'the results found so far must still be shown');
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter test_search_stops_continuing_past_total_timelimit`

Expected: FAIL — `continue_search` is currently emitted regardless of elapsed time.

- [ ] **Step 3: Implement the budget**

In `program/actions/mail/search.php`, where `$_SESSION['search_request']` is set (around line 118),
record when this logical search began. A search that is NOT a continuation starts the clock:

```php
        $_SESSION['search_request']  = $search_request;
        // AVUZ PATCH — start the clock for the total search budget. A request
        // without _continue is a NEW logical search, so it resets it.
        if (empty($_GET['_continue'])) {
            $_SESSION['search_start'] = time();
        }
```

Then replace the continuation block added in Task 1 with:

```php
        // Ask the client to continue, independently of whether we just rendered
        // rows. Upstream only reached this inside the no-rows branch, so simply
        // listing partial results would have silently stopped the search.
        //
        // Bounded: app.js re-issues every 100ms for as long as we keep saying
        // "incomplete", with no ceiling of its own. Past the budget we stop
        // asking and tell the user plainly, rather than spinning forever.
        if ($incomplete) {
            $total_limit = (int) $rcmail->config->get('imap_search_total_timelimit', 120);
            $elapsed     = time() - (int) ($_SESSION['search_start'] ?? time());

            if ($total_limit > 0 && $elapsed >= $total_limit) {
                $rcmail->output->show_message('searchpartial', 'notice');
            }
            else {
                $rcmail->output->command('continue_search', $search_request);
            }
        }
```

- [ ] **Step 4: Add the message text**

In `program/localization/en_US/messages.inc`, add:

```php
$messages['searchpartial'] = 'Search stopped early. These are partial results — refine your search or try again.';
```

In `program/localization/pt_BR/messages.inc`, add:

```php
$messages['searchpartial'] = 'A busca foi interrompida. Estes são resultados parciais — refine a busca ou tente novamente.';
```

Both localization files are core, so both need a Dockerfile COPY — see Step 6.

- [ ] **Step 5: Verify**

```bash
cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Actions_Mail_Search
cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Actions
```
First: PASS. Second: matches the Task 1 Step 1 baseline.

- [ ] **Step 6: Ship the localization files**

In `Dockerfile`, after the `rcube_imap.php` COPY from Task 2, add:

```dockerfile
# messages.inc (en_US, pt_BR): adds 'searchpartial', shown when a search hits its
# total budget. Without these COPYs the label is missing and the user sees a raw
# message key.
COPY program/localization/en_US/messages.inc /var/www/roundcube/program/localization/en_US/messages.inc
COPY program/localization/pt_BR/messages.inc /var/www/roundcube/program/localization/pt_BR/messages.inc
```

- [ ] **Step 7: Commit**

```bash
git add program/actions/mail/search.php tests/Actions/Mail/Search.php program/localization/en_US/messages.inc program/localization/pt_BR/messages.inc Dockerfile
git commit -m "feat(search): bound the total search and report partial results

app.js re-issues an incomplete search every 100ms with no ceiling of its own, so
a search that cannot finish spins forever - the 'search never finishes' report.
Rendering partial results makes that tolerable to watch but still infinite.

Record when a logical search starts (a request without _continue resets it) and
stop asking the client to continue once imap_search_total_timelimit is spent,
telling the user the results are partial instead of leaving them guessing."
```

---

### Task 4: Warn when the user searches the whole message

Body/`TEXT` search costs Zoho's server-side compute — 69s measured — which no client-side change can
reduce. The user gets told before they wait.

**Files:**
- Create: `plugins/avuz_search_notice/avuz_search_notice.php`
- Create: `plugins/avuz_search_notice/search_notice.js`
- Modify: `config/config.inc.php`
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed later.

- [ ] **Step 1: Create the plugin**

Create `plugins/avuz_search_notice/avuz_search_notice.php`:

```php
<?php

/**
 * avuz_search_notice — warn before an expensive whole-message search.
 *
 * Zoho's IMAP has no SORT, THREAD or MULTISEARCH, and a body/TEXT search costs
 * real server-side compute on their side: 69s measured on this deployment for a
 * single all-folder body search. No amount of client or round-trip work removes
 * that, so the honest thing is to set the expectation before the user waits.
 *
 * Client-side only: it hooks the existing search UI rather than the search
 * itself, so it cannot slow down or break a search.
 */
class avuz_search_notice extends rcube_plugin
{
    public $task = 'mail';

    function init()
    {
        $this->include_script('search_notice.js');
        $this->add_texts('localization/', true);
    }
}
```

Create `plugins/avuz_search_notice/localization/en_US.inc`:

```php
<?php

$labels = [];
$labels['slowsearchnotice'] = 'Searching the entire message is much slower — it can take a minute or more.';
```

Create `plugins/avuz_search_notice/localization/pt_BR.inc`:

```php
<?php

$labels = [];
$labels['slowsearchnotice'] = 'Buscar na mensagem inteira é bem mais lento — pode levar um minuto ou mais.';
```

Create `plugins/avuz_search_notice/search_notice.js`:

```js
/**
 * Warn once per session when the user picks whole-message search.
 *
 * Zoho charges real server time for a body/TEXT search (69s measured for one
 * all-folder body search), which nothing on our side can reduce. Telling the
 * user up front is the honest alternative to a surprise wait.
 */
(function () {
  if (!window.rcmail) return;

  var WARNED_KEY = 'avuz_slow_search_warned';

  function alreadyWarned() {
    try { return sessionStorage.getItem(WARNED_KEY) === '1'; }
    catch (e) { return false; }
  }

  function rememberWarned() {
    try { sessionStorage.setItem(WARNED_KEY, '1'); }
    catch (e) { /* private mode: warn again next time, harmless */ }
  }

  rcmail.addEventListener('init', function () {
    // Roundcube fires this when a search is submitted. The scope/headers the
    // user chose are on the request, so read them there rather than poking at
    // DOM that differs between skins.
    rcmail.addEventListener('beforesearch', function () {
      if (alreadyWarned()) return;

      var headers = $('input[name="s_mods[]"]:checked, #s_scope_all').length
        ? $('input[name="s_mods[]"]:checked').map(function () { return this.value; }).get()
        : [];

      if (headers.indexOf('text') === -1 && headers.indexOf('body') === -1) return;

      rcmail.display_message(rcmail.get_label('slowsearchnotice', 'avuz_search_notice'), 'notice');
      rememberWarned();
    });
  });
})();
```

- [ ] **Step 2: Verify syntax**

```bash
php -l plugins/avuz_search_notice/avuz_search_notice.php
php -l plugins/avuz_search_notice/localization/en_US.inc
php -l plugins/avuz_search_notice/localization/pt_BR.inc
node --check plugins/avuz_search_notice/search_notice.js 2>/dev/null || echo "node not available - skip JS check"
```

- [ ] **Step 3: Register the plugin**

In `config/config.inc.php`, in the `$config['plugins']` array, after `'avuz_poll_scope',` add:

```php
    'avuz_search_notice',
```

- [ ] **Step 4: Ship it**

In `Dockerfile`, after the `COPY plugins/avuz_poll_scope ...` line, add:

```dockerfile
COPY plugins/avuz_search_notice /var/www/roundcube/plugins/avuz_search_notice
```

- [ ] **Step 5: Confirm it would ship, and that config parses**

```bash
php -l config/config.inc.php
grep -c "avuz_search_notice" Dockerfile
```
Expected: no syntax errors, and `1`.

- [ ] **Step 6: Run the Plugins suite**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Plugins`
Expected: exactly 1 pre-existing error and 1 pre-existing failure, nothing new.

- [ ] **Step 7: Commit**

```bash
git add plugins/avuz_search_notice config/config.inc.php Dockerfile
git commit -m "feat(search): warn before a whole-message search

Body/TEXT search costs Zoho server-side compute - 69s measured here for one
all-folder body search - and no client or round-trip change reduces it. Warn
once per session when the user selects it, so the wait is expected rather than
a surprise.

Client-side only: hooks the search UI, never the search itself, so it cannot
slow down or break a search."
```

---

### Task 5: Register the customizations

Every deviation from upstream is tracked so an upstream rebase has a checklist. This change adds
three core patches and a plugin.

**Files:**
- Modify: `customizations.json`

**Interfaces:** none.

- [ ] **Step 1: Add the entries**

Add these two objects to the `entries` array in `customizations.json`, at the end:

```json
  {
    "id": "progressive-search",
    "type": "core-patch",
    "paths": [
      "program/actions/mail/search.php",
      "program/lib/Roundcube/rcube_imap.php",
      "program/localization/en_US/messages.inc",
      "program/localization/pt_BR/messages.inc",
      "config/config.inc.php",
      "Dockerfile"
    ],
    "description": "Renders cross-folder search results as each folder completes, instead of holding the UI blank until the whole search finishes. Makes the search round limit configurable (imap_search_timelimit, set to 8s) and bounds the total (imap_search_total_timelimit, 120s) so a search that cannot finish ends with a partial-results notice rather than an endless retry loop.",
    "risk": "medium",
    "notes": "Upstream search.php fetched headers only when the result was complete and set 'count = 0 // keep UI locked'; measured on prod, an all-folder search could show nothing for 212s. Roundcube already searches incrementally and caches finished folder results in the session, so listing them costs one FETCH of at most mail_pagesize headers and re-searches nothing. WATCH ON REBASE: the continue_search call was moved OUT of the no-rows branch — if upstream restructures that block, re-check that continuation still fires when rows are rendered, or the search silently stops after one round. Also re-check that the final searchnomatch branch cannot run while incomplete. The 60s time limit at rcube_imap.php was hardcoded upstream."
  },
  {
    "id": "avuz-search-notice-plugin",
    "type": "plugin",
    "paths": [
      "plugins/avuz_search_notice/",
      "config/config.inc.php",
      "Dockerfile"
    ],
    "description": "Warns once per session when the user selects whole-message (body/TEXT) search, which costs Zoho server-side compute that no client change can reduce.",
    "risk": "low",
    "notes": "Client-side only; hooks the search UI via the 'beforesearch' event, never the search itself. 69s measured for one all-folder body search on this deployment. If Roundcube renames or removes the beforesearch event the warning silently stops appearing — harmless, but re-check on upgrade."
  }
```

- [ ] **Step 2: Verify the JSON**

```bash
python3 -c "import json; json.load(open('customizations.json')); print('valid')"
git diff --stat customizations.json
```
Expected: `valid`, and only `customizations.json` changed, additions only.

- [ ] **Step 3: Commit**

```bash
git add customizations.json
git commit -m "docs(customizations): register progressive search and the search notice

Records the rebase trap explicitly: continue_search was moved out of the no-rows
branch, so if upstream restructures that block the search will silently stop
after one round unless continuation still fires when rows are rendered."
```

---

### Task 6: Verify on staging

Everything above is unit-tested logic. What cannot be unit tested — that results actually paint
progressively against real IMAP, and that the search terminates — is verified here.

**Files:** none modified.

**Interfaces:** none.

- [ ] **Step 1: Build and deploy to staging**

```bash
./scripts/build-push.sh latest staging
PORTAINER_ENV_FILE=scripts/deploy.env ./scripts/deploy.sh -y avuz-mail-roundcube-2
```

- [ ] **Step 2: Verify the patches are IN THE IMAGE, not just the repo**

This is the check that has caught two silently-inert deployments on this project.

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 sh -c '
echo -n "progressive search patch: "; grep -c "AVUZ PATCH — progressive search" /var/www/roundcube/program/actions/mail/search.php
echo -n "configurable timelimit:   "; grep -c "imap_search_timelimit" /var/www/roundcube/program/lib/Roundcube/rcube_imap.php
echo -n "search notice plugin:     "; test -f /var/www/roundcube/plugins/avuz_search_notice/avuz_search_notice.php && echo present || echo MISSING
echo -n "config values:            "; grep -c "imap_search_timelimit" /var/www/roundcube/config/config.inc.php
echo -n "searchpartial label:      "; grep -c "searchpartial" /var/www/roundcube/program/localization/pt_BR/messages.inc
echo "serving:"; curl -s -o /dev/null -w "root=%{http_code}\n" http://localhost/
echo -n "fatal/parse errors: "; grep -icE "PHP (Fatal|Parse)|Failed to load plugin" /var/log/supervisor/php-fpm.err.log'
```

Expected: `1`, `1`, `present`, `1`, `1`, `root=200`, `0`.

- [ ] **Step 3: Measure time-to-first-row**

Truncate the perf log, then run an all-folder search from a browser on an account with many folders.

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 sh -c ': > /var/www/roundcube/logs/php-perf.log; echo ready'
```

After searching:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 sh -c '
grep " search " /var/www/roundcube/logs/php-perf.log | awk "{printf \"%s %6.2fs\n\", strftime(\"%H:%M:%S\",int(\$1)), \$2}"'
```

Expected shape: several rounds of roughly the configured 8s rather than one long request. **The
number this work exists to move is time-to-first-row** — previously equal to total search time, now
expected to be about one round.

- [ ] **Step 4: Confirm what the user sees**

By observation in the browser, confirm all four:
1. Rows appear before the search finishes, and grow between rounds.
2. The list is ordered correctly, not merely appended in arrival order.
3. A finished search shows the normal "search successful" message exactly once.
4. Selecting whole-message search shows the slow-search warning once per session.

- [ ] **Step 5: Confirm the search terminates**

Search something broad enough to exceed 120s across all folders. Confirm it **stops**, shows the
partial-results notice, and does not keep re-issuing. Verify with:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 sh -c '
grep -c " search " /var/www/roundcube/logs/php-perf.log'
```

The count must stop increasing once the notice appears.

- [ ] **Step 6: Record the result and commit**

Append a `## Staging verification` section to this plan file with the date, the measured
time-to-first-row versus total search time, the round count, and anything that deviated. Commit it.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| Results appear as folders complete | 1 |
| User can act on partial results | 1 (rows are real rows) |
| Distinguish "still searching" from "finished" | 1 (success message suppressed while incomplete), 3 (partial notice) |
| Warn on whole-message search | 4 |
| Never an endless spinner | 3 |
| Configurable, shorter round limit | 2 |
| Do not patch app.js | Task 4 is plugin JS; no task touches app.js |
| Every core patch gets a Dockerfile COPY | 1 Step 7, 2 Step 6, 3 Step 6 |
| customizations.json entries | 5 |
| Verify in the running container, not the repo | 6 Step 2 |
| Measure time-to-first-row | 6 Step 3 |
| Works whether pipelining is on or off | No task touches `run_pipelined`; Task 1 changes only display |

**Placeholder scan:** none — every code step contains the code, every command its expected output.
Task 2 Step 1 deliberately asks the implementer to verify how `$this->options` is populated and
report which form they used; that is a verification instruction with both alternatives written out,
not a TBD.

**Type consistency:** `$incomplete` is introduced in Task 1 and consumed in Task 3 with the same
meaning. `imap_search_timelimit` and `imap_search_total_timelimit` are defined in Task 2 and used in
Task 3 exactly as named. The `searchpartial` message key is defined and used in Task 3.

**Gap found and closed during review:** Task 1's first draft moved header listing out of the
conditional but left `continue_search` inside the no-rows branch — which would have stopped the
search after the first round that found anything, silently returning partial results as if complete.
That is now an explicit step, an explicit warning in the task, and a recorded rebase trap in
`customizations.json`.
