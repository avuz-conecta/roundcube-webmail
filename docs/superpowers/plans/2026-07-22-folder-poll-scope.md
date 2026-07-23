# Folder Poll Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop new-mail polling from walking every folder, so a user with 107 folders costs the same as a user with 8, without removing the feature from anyone who wants it.

**Architecture:** Two independent changes. (1) `avuz_filters` reports the unread count of any folder it moves a message into, so filter-target folders never need polling. (2) A new `avuz_poll_scope` plugin neutralises Roundcube's `check_all_folders` flag on the `ready` hook — which makes core take its cheap path in both `check_recent` and `getunread` — then re-adds a bounded allowlist of Zoho's auto-file folders on the `check_recent` hook for users who opted in. All decision logic lives in pure static functions that are unit-testable without a Roundcube bootstrap; the plugin classes are thin wiring.

**Tech Stack:** PHP 8.2, Roundcube 1.6.14 plugin API, PHPUnit 9.6 (`vendor/bin/phpunit`, config at `tests/phpunit.xml`).

**Spec:** `docs/superpowers/specs/2026-07-22-folder-poll-scope-design.md`

## Global Constraints

- Follow the existing plugin test pattern: pure-logic classes in `plugins/<name>/lib/`, tests in `plugins/<name>/tests/<name>_test.php`, using `require_once __DIR__ . '/../lib/<file>.php'` and extending `PHPUnit\Framework\TestCase`. No Roundcube bootstrap in unit tests.
- Every new test file must be registered in `tests/phpunit.xml` under the `Plugins` testsuite or it will not run.
- Run tests from the `tests/` directory: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter <name>`.
- The `Framework` suite has **7 pre-existing errors** unrelated to this work (missing `Net_LDAP3`, config env keys). A run is clean if it shows 7 errors and **0 failures**. Never "fix" those.
- The `Plugins` suite likewise carries **1 pre-existing error and 1 pre-existing failure** (Enigma crypto-key config, Password bcrypt-cost env). Confirmed against the unmodified branch during Task 1. A clean Plugins run shows those two and nothing new — earlier revisions of this plan wrongly said "0 failures and 0 errors".
- Allowlist config key: `avuz_poll_folders`. Default value: `['Spam', 'Junk', 'Newsletter', 'Notification']`.
- Allowlist result cap: **6 folders** (~3.6s of IMAP at 3 commands x ~0.2s RTT).
- Folder matching is on the **last path segment, case-insensitively** — Zoho nests folders under INBOX on some accounts.
- Deployment is by building and deploying an image. No container-local edits.
- Commit messages: no Claude Code attribution.

---

## File Structure

| File | Responsibility |
|---|---|
| `plugins/avuz_filters/lib/filter_runner.php` (modify) | Extract move-target decision into a pure function; collect moved-to folders; report unread counts |
| `plugins/avuz_filters/tests/filter_runner_test.php` (create) | Unit tests for the pure move-target logic |
| `plugins/avuz_poll_scope/lib/poll_folders.php` (create) | Pure folder-selection logic — the only place matching and capping happen |
| `plugins/avuz_poll_scope/tests/poll_folders_test.php` (create) | Unit tests for selection, matching, capping |
| `plugins/avuz_poll_scope/avuz_poll_scope.php` (create) | Thin plugin wiring: `ready` + `check_recent` hooks |
| `config/config.inc.php` (modify) | Register plugin, set `avuz_poll_folders` |
| `Dockerfile` (modify) | `COPY` the new plugin into the image |
| `tests/phpunit.xml` (modify) | Register both new test files |

---

## Decision recorded during planning (amends the spec)

The spec's behavior table described the periodic `refresh` only. While mapping the code, `check_recent.php:42` turned out to read:

```php
$check_all = $rcmail->action != 'refresh' || (bool) $rcmail->config->get('check_all_folders');
```

The `$rcmail->action != 'refresh'` term makes `check_all` **true for the explicit `check-recent` action for every user**, regardless of the preference. That is why prod measures `check-recent` at **43.07s average**. It is not limited to the two opted-in users.

**Decision:** the hook applies the same bounded set to every action, not just `refresh`. The polled set becomes, uniformly:

```
{current folder, INBOX} ∪ (allowlist ∩ subscribed, if the user enabled the preference)
```

Consequence: the manual "check for new mail" action stops walking all folders for everyone. It gets much faster (43s → ~1-4s) and checks fewer folders. Given 43s is itself pathological and the allowlist covers the folders that receive mail without our filters, this is an improvement — but it is a behavior change beyond what the spec described, applying to all 97 users.

---

### Task 1: Pure move-target logic in `avuz_filters`

Extracts the existing last-wins move/delete decision from `apply()` into a pure, testable function. No behavior change yet — this is a refactor with tests, so Task 2 can build on it safely.

**Files:**
- Modify: `plugins/avuz_filters/lib/filter_runner.php` (the `apply()` method, currently at lines 92-115)
- Create: `plugins/avuz_filters/tests/filter_runner_test.php`
- Modify: `tests/phpunit.xml`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `avuz_filter_runner::move_target(array $actions, string $trash): ?string` — returns the folder a set of matched rule actions would move a message into, or `null` if none. `delete` maps to `$trash`. Last matching action wins.

- [ ] **Step 1: Write the failing test**

Create `plugins/avuz_filters/tests/filter_runner_test.php`:

```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/filter_runner.php';

class filter_runner_test extends TestCase
{
    function testReturnsNullWhenNoTerminalAction()
    {
        $actions = [['type' => 'mark_read'], ['type' => 'flag']];
        $this->assertNull(avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testMoveReturnsItsFolder()
    {
        $actions = [['type' => 'move', 'folder' => 'Clientes']];
        $this->assertSame('Clientes', avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testDeleteReturnsTrashFolder()
    {
        $actions = [['type' => 'delete']];
        $this->assertSame('Lixeira', avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testLastTerminalActionWins()
    {
        $actions = [['type' => 'move', 'folder' => 'Clientes'], ['type' => 'delete']];
        $this->assertSame('Lixeira', avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testMoveWithEmptyFolderIsIgnored()
    {
        $actions = [['type' => 'move', 'folder' => '']];
        $this->assertNull(avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testForwardIsNotTerminal()
    {
        $actions = [['type' => 'forward', 'to' => 'a@b.com']];
        $this->assertNull(avuz_filter_runner::move_target($actions, 'Lixeira'));
    }
}
```

- [ ] **Step 2: Register the test file**

In `tests/phpunit.xml`, inside the `Plugins` testsuite, immediately after the line
`<file>./../plugins/avuz_filters/tests/rule_engine_test.php</file>`, add:

```xml
      <file>./../plugins/avuz_filters/tests/filter_runner_test.php</file>
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter filter_runner_test`
Expected: FAIL — `Error: Call to undefined method avuz_filter_runner::move_target()`

- [ ] **Step 4: Write minimal implementation**

In `plugins/avuz_filters/lib/filter_runner.php`, add this public static method to the
`avuz_filter_runner` class (place it directly above the existing `private static function apply`):

```php
    /**
     * Which folder, if any, a matched rule's actions move the message into.
     * Pure so it can be unit tested; `apply()` performs the move itself.
     * Terminal actions are last-wins, matching the original inline logic.
     */
    public static function move_target(array $actions, string $trash): ?string
    {
        $move_to = null;

        foreach ($actions as $a) {
            $type = $a['type'] ?? '';

            if ($type === 'delete') {
                $move_to = $trash;
            }
            elseif ($type === 'move' && !empty($a['folder'])) {
                $move_to = $a['folder'];
            }
        }

        return $move_to;
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter filter_runner_test`
Expected: PASS — `OK (6 tests, 6 assertions)`

- [ ] **Step 6: Use the new function inside `apply()`**

In `plugins/avuz_filters/lib/filter_runner.php`, in `apply()`, replace the inline
`$move_to`/`$fwd` loop. The current code reads:

```php
        $move_to = null; $fwd = [];
        foreach ($actions as $a) {
            switch ($a['type']) {
                case 'mark_read': $storage->set_flag($uid, 'SEEN', $folder); break;
                case 'flag':      $storage->set_flag($uid, 'FLAGGED', $folder); break;
                case 'forward':   if (!empty($a['to'])) $fwd[] = $a['to']; break;  // copy, not terminal
                case 'delete':    $move_to = $trash; break;                        // last-wins
                case 'move':      if (!empty($a['folder'])) $move_to = $a['folder']; break;
            }
        }
```

Replace it with:

```php
        $fwd     = [];
        $move_to = self::move_target($actions, $trash);

        foreach ($actions as $a) {
            switch ($a['type']) {
                case 'mark_read': $storage->set_flag($uid, 'SEEN', $folder); break;
                case 'flag':      $storage->set_flag($uid, 'FLAGGED', $folder); break;
                case 'forward':   if (!empty($a['to'])) $fwd[] = $a['to']; break;  // copy, not terminal
                // 'delete' and 'move' are terminal and resolved by move_target() above
            }
        }
```

- [ ] **Step 7: Run the whole plugin suite to confirm no regression**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Plugins`
Expected: PASS, 0 failures and 0 errors.

- [ ] **Step 8: Commit**

```bash
git add plugins/avuz_filters/lib/filter_runner.php plugins/avuz_filters/tests/filter_runner_test.php tests/phpunit.xml
git commit -m "refactor(filters): extract move_target() as pure, tested logic

apply() decided the terminal move/delete folder inline, which made the decision
untestable without an IMAP storage object. Same last-wins semantics, now a pure
static function with unit tests, so the next change can report unread counts for
the folders a filter moves into."
```

---

### Task 2: `avuz_filters` reports unread counts for folders it fills

**Files:**
- Modify: `plugins/avuz_filters/lib/filter_runner.php` (the `apply()` method and the `do_run()` loop)

**Interfaces:**
- Consumes: `avuz_filter_runner::move_target(array $actions, string $trash): ?string` from Task 1.
- Produces: no new public API. `apply()` gains a return type of `?string` (the folder moved into, or `null`).

- [ ] **Step 1: Change `apply()` to return the folder it moved into**

In `plugins/avuz_filters/lib/filter_runner.php`, change the `apply()` signature from:

```php
    private static function apply(rcmail $rcmail, $storage, string $folder, string $trash, int $uid, array $actions, bool $already_fwd): void
```

to:

```php
    /** @return string|null the folder the message was moved into, or null */
    private static function apply(rcmail $rcmail, $storage, string $folder, string $trash, int $uid, array $actions, bool $already_fwd): ?string
```

and change the end of the method from:

```php
        if ($move_to !== null) {
            $storage->move_message($uid, $move_to, $folder); // terminal: removes from INBOX
        }
    }
```

to:

```php
        if ($move_to !== null) {
            $storage->move_message($uid, $move_to, $folder); // terminal: removes from INBOX
            return $move_to;
        }

        return null;
    }
```

- [ ] **Step 2: Collect the destinations in `do_run()` and report their counts**

In `do_run()`, the loop currently contains:

```php
            $actions = avuz_rule_engine::match($hv, $rules);
            if ($actions) {
                self::apply($rcmail, $storage, $folder, $trash, (int) $uid, $actions, $already_fwd);
                $acted++;
            }
```

Replace with:

```php
            $actions = avuz_rule_engine::match($hv, $rules);
            if ($actions) {
                $moved_to = self::apply($rcmail, $storage, $folder, $trash, (int) $uid, $actions, $already_fwd);
                if ($moved_to !== null) {
                    $filled[$moved_to] = true;
                }
                $acted++;
            }
```

Declare `$filled` alongside the other loop accumulators. The line currently reads:

```php
        $acted = 0; $maxUid = $last;
```

Change it to:

```php
        $acted = 0; $maxUid = $last; $filled = [];
```

Then, immediately before the existing `$store->set_state($user, $folder, $maxUid, $uidv);` line at the end of `do_run()`, add:

```php
        // Tell the client the new unread count for every folder we just filed into.
        // Without this the destination badge never updates: we move messages via
        // $storage->move_message() directly, which bypasses move.php:128 where core
        // would normally push it. Same approach as plugins/archive/archive.php:291.
        foreach (array_keys($filled) as $filled_folder) {
            rcmail_action_mail_index::send_unread_count($filled_folder, true);
        }
```

- [ ] **Step 3: Run the plugin suite to confirm nothing regressed**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Plugins`
Expected: PASS, 0 failures and 0 errors.

Note: the push itself is not unit-testable — it needs a live IMAP session and an output object. It is verified on staging in Task 6.

- [ ] **Step 4: Commit**

```bash
git add plugins/avuz_filters/lib/filter_runner.php
git commit -m "fix(filters): update the destination folder's unread badge on move

The plugin moves messages with \$storage->move_message() directly, bypassing
move.php:128 where core pushes send_unread_count() for the target. Consequence:
mail filed by a user's own filters updated no badge at all — INBOX ticked up,
ticked back down as the message was moved out, and the destination stayed
silent until the user opened it.

Collect the destinations during the pass and report each one's count once, the
same way plugins/archive/archive.php:291 does."
```

---

### Task 3: Pure folder-selection logic

**Files:**
- Create: `plugins/avuz_poll_scope/lib/poll_folders.php`
- Create: `plugins/avuz_poll_scope/tests/poll_folders_test.php`
- Modify: `tests/phpunit.xml`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `avuz_poll_folders::select(array $subscribed, array $allowlist, string $delimiter, int $cap): array` — returns the subset of `$subscribed` whose **last path segment** case-insensitively matches an entry in `$allowlist`, in `$subscribed` order, truncated to `$cap` entries. Also `avuz_poll_folders::CAP` (int, 6) as the default cap.

- [ ] **Step 1: Write the failing test**

Create `plugins/avuz_poll_scope/tests/poll_folders_test.php`:

```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/poll_folders.php';

class poll_folders_test extends TestCase
{
    private const ALLOW = ['Spam', 'Junk', 'Newsletter', 'Notification'];

    function testSelectsMatchingTopLevelFolders()
    {
        $subscribed = ['INBOX', 'Spam', 'Clientes', 'Newsletter'];
        $this->assertSame(
            ['Spam', 'Newsletter'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testMatchesNestedFoldersByLastSegment()
    {
        $subscribed = ['INBOX', 'INBOX/Newsletter', 'INBOX/2- FINANCEIRO'];
        $this->assertSame(
            ['INBOX/Newsletter'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testMatchingIsCaseInsensitive()
    {
        $subscribed = ['inbox/newsletter', 'SPAM'];
        $this->assertSame(
            ['inbox/newsletter', 'SPAM'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testHonoursServerHierarchyDelimiter()
    {
        $subscribed = ['INBOX.Newsletter', 'INBOX.Projetos'];
        $this->assertSame(
            ['INBOX.Newsletter'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '.', 6)
        );
    }

    function testExcludesAllowlistEntriesTheUserIsNotSubscribedTo()
    {
        $subscribed = ['INBOX', 'Spam'];
        $this->assertSame(
            ['Spam'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testTruncatesToTheCap()
    {
        $subscribed = ['a/Spam', 'b/Spam', 'c/Spam', 'd/Spam', 'e/Spam', 'f/Spam', 'g/Spam'];
        $this->assertCount(6, avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6));
    }

    function testEmptyAllowlistSelectsNothing()
    {
        $this->assertSame([], avuz_poll_folders::select(['INBOX', 'Spam'], [], '/', 6));
    }

    function testEmptyDelimiterFallsBackToWholeName()
    {
        $this->assertSame(['Spam'], avuz_poll_folders::select(['Spam'], self::ALLOW, '', 6));
    }
}
```

- [ ] **Step 2: Register the test file**

In `tests/phpunit.xml`, inside the `Plugins` testsuite, immediately after the line
`<file>./../plugins/avuz_prefetch/tests/prefetch_cache_test.php</file>`, add:

```xml
      <file>./../plugins/avuz_poll_scope/tests/poll_folders_test.php</file>
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter poll_folders_test`
Expected: FAIL — `Failed to open stream` / `Class "avuz_poll_folders" not found`

- [ ] **Step 4: Write minimal implementation**

Create `plugins/avuz_poll_scope/lib/poll_folders.php`:

```php
<?php

/**
 * Which of a user's folders are worth polling for new mail.
 *
 * Pure so it can be unit tested without a Roundcube bootstrap. The plugin class
 * supplies the subscribed list, the config allowlist and the server's hierarchy
 * delimiter; every matching and capping decision happens here.
 */
class avuz_poll_folders
{
    /**
     * Most folders we will ever add to a poll. At three IMAP commands per folder
     * and ~200ms per command against Zoho, six folders is ~3.6s — the most we are
     * willing to add to a refresh that otherwise takes ~1s. Without a cap, a
     * config edit listing many folders would quietly recreate the original
     * 107-folder problem.
     */
    public const CAP = 6;

    /**
     * @param string[] $subscribed folders the user is subscribed to
     * @param string[] $allowlist  folder names to poll, e.g. ['Spam', 'Newsletter']
     * @param string   $delimiter  IMAP hierarchy delimiter ('/' or '.')
     * @param int      $cap        maximum folders to return
     *
     * @return string[] subset of $subscribed, in $subscribed order
     */
    public static function select(array $subscribed, array $allowlist, string $delimiter, int $cap): array
    {
        if (empty($allowlist)) {
            return [];
        }

        // Compare on the last path segment: Zoho nests auto-filed folders under
        // INBOX on some accounts (INBOX/Newsletter), so an exact match on the full
        // name would silently find nothing for exactly the users who want this.
        $wanted = [];
        foreach ($allowlist as $name) {
            $wanted[mb_strtolower((string) $name)] = true;
        }

        $selected = [];
        foreach ($subscribed as $folder) {
            $folder = (string) $folder;
            $leaf   = $delimiter !== '' ? self::leaf($folder, $delimiter) : $folder;

            if (isset($wanted[mb_strtolower($leaf)])) {
                $selected[] = $folder;

                if (count($selected) >= $cap) {
                    break;
                }
            }
        }

        return $selected;
    }

    private static function leaf(string $folder, string $delimiter): string
    {
        $pos = strrpos($folder, $delimiter);

        return $pos === false ? $folder : substr($folder, $pos + strlen($delimiter));
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter poll_folders_test`
Expected: PASS — `OK (8 tests, 8 assertions)`

- [ ] **Step 6: Commit**

```bash
git add plugins/avuz_poll_scope/lib/poll_folders.php plugins/avuz_poll_scope/tests/poll_folders_test.php tests/phpunit.xml
git commit -m "feat(poll-scope): pure folder-selection logic with a hard cap

Selects the folders worth polling for new mail: the subset of a user's
subscribed folders whose last path segment matches an allowlist entry,
case-insensitively, capped at six.

Last-segment matching is deliberate — Zoho nests auto-filed folders under INBOX
on some accounts, so matching whole names would find nothing for the users who
most need this. The cap comes from a cost budget: six folders is ~3.6s of IMAP
against Zoho, and without it a config edit could recreate the 107-folder walk."
```

---

### Task 4: The `avuz_poll_scope` plugin

**Files:**
- Create: `plugins/avuz_poll_scope/avuz_poll_scope.php`
- Modify: `config/config.inc.php`
- Modify: `Dockerfile`

**Interfaces:**
- Consumes: `avuz_poll_folders::select(array, array, string, int): array` and `avuz_poll_folders::CAP` from Task 3.
- Produces: no API consumed by later tasks.

- [ ] **Step 1: Write the plugin**

Create `plugins/avuz_poll_scope/avuz_poll_scope.php`:

```php
<?php

require_once __DIR__ . '/lib/poll_folders.php';

/**
 * avuz_poll_scope — bound how many folders new-mail polling touches.
 *
 * Roundcube polls folders in two places, and only one of them is extensible:
 *
 *   - check_recent.php walks every subscribed folder issuing STATUS + SELECT +
 *     UID SEARCH per folder. It exposes a 'check_recent' hook (line 66).
 *   - getunread.php iterates every subscribed folder too, and has NO hooks at
 *     all. Its cheap cached path is gated on the same check_all_folders flag.
 *
 * So filtering the hook alone would leave getunread walking everything. Instead
 * we neutralise the flag itself on 'ready' (rcmail.php:228, after the user is
 * authenticated and before any action runs), which puts BOTH paths on their
 * cheap branch, and then re-add a bounded allowlist in the hook for users who
 * had the preference on.
 *
 * Measured motivation: one user with 107 folders produced ~321 serialized IMAP
 * commands per refresh and refreshes of 20-136s, every two minutes, each pinning
 * a PHP worker. The explicit check-recent action was 43s on average for ALL
 * users, because check_recent.php:42 forces check_all true for any action that
 * is not 'refresh'.
 *
 * See docs/superpowers/specs/2026-07-22-folder-poll-scope-design.md
 */
class avuz_poll_scope extends rcube_plugin
{
    public $task = 'mail';

    /** The user's real preference, read before we overwrite it. */
    private $user_wants_all = false;

    function init()
    {
        $this->add_hook('ready', [$this, 'neutralise_flag']);
        $this->add_hook('check_recent', [$this, 'bound_folders']);
    }

    /**
     * Stash the user's preference and force the flag off, so core's own code
     * takes the cheap path in check_recent AND getunread.
     */
    function neutralise_flag($args)
    {
        $rcmail = rcmail::get_instance();

        $this->user_wants_all = (bool) $rcmail->config->get('check_all_folders');
        $rcmail->config->set('check_all_folders', false);

        return $args;
    }

    /**
     * Replace the folder list with the bounded set. Applied for every action, not
     * just 'refresh': check_recent.php:42 forces check_all true whenever the
     * action is not 'refresh', which is why the manual check-recent cost 43s for
     * every user regardless of their preference.
     */
    function bound_folders($args)
    {
        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $current = (string) $storage->get_folder();

        $folders = ['INBOX'];
        if ($current !== '') {
            $folders[] = $current;
        }

        if ($this->user_wants_all) {
            $allowlist = (array) $rcmail->config->get('avuz_poll_folders', []);

            if (!empty($allowlist)) {
                $folders = array_merge($folders, avuz_poll_folders::select(
                    (array) $storage->list_folders_subscribed('', '*', 'mail'),
                    $allowlist,
                    (string) $storage->get_hierarchy_delimiter(),
                    avuz_poll_folders::CAP
                ));
            }
        }

        $args['folders'] = array_values(array_unique($folders));

        return $args;
    }
}
```

- [ ] **Step 2: Verify it parses**

Run: `php -l plugins/avuz_poll_scope/avuz_poll_scope.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Register the plugin and the allowlist in config**

In `config/config.inc.php`, in the `$config['plugins']` array (currently ending with
`'avuz_prefetch',`), add a new entry after `'avuz_prefetch',`:

```php
    'avuz_poll_scope',
```

Then, immediately after the closing `];` of the `$config['plugins']` array, add:

```php
// -- New-mail polling scope --
// Folders that can receive mail WITHOUT our filters putting it there, so they are
// the only ones worth polling. A prod survey of cache_index found these recur
// across many unrelated accounts (Spam 17 users, Newsletter 16, Notification 11,
// Junk 6) — they are Zoho's automatic classification folders, filled server-side
// at delivery without ever touching INBOX. Everything else is either a system
// folder that never receives unread mail or a folder the user made themselves,
// which our filters already report on when they file into it.
//
// 'Junk' is listed alongside 'Spam' as cheap insurance, NOT because Zoho names
// the spam folder differently per account — that was checked and is false. Every
// Zoho user here has 'Spam' in the system-folder block; where 'Junk' exists it is
// an extra user folder that coexists with it, never a replacement. It is kept in
// the list because we cannot see what fills it, and the cost of a folder a user
// does not have is zero: the list is intersected with subscribed folders.
// Matched on the last path segment, so a nested INBOX/Newsletter matches too.
// Capped at avuz_poll_folders::CAP.
$config['avuz_poll_folders'] = ['Spam', 'Junk', 'Newsletter', 'Notification'];
```

- [ ] **Step 4: Verify the config parses**

Run: `php -l config/config.inc.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Copy the plugin into the image**

In `Dockerfile`, immediately after the line
`COPY plugins/avuz_filters /var/www/roundcube/plugins/avuz_filters`, add:

```dockerfile
COPY plugins/avuz_poll_scope /var/www/roundcube/plugins/avuz_poll_scope
```

- [ ] **Step 6: Confirm the plugin would actually be shipped**

Run: `grep -c avuz_poll_scope Dockerfile config/config.inc.php`
Expected: `Dockerfile:1` and `config/config.inc.php:1` (the allowlist line uses the distinct string `avuz_poll_folders`, so only the plugin registration matches)

A plugin registered in config but missing from the Dockerfile loads on a dev
checkout and is absent in the image — the same trap that makes core patches inert.

- [ ] **Step 7: Run the full suite**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Plugins && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`
Expected: Plugins PASS with 0 failures/0 errors; Framework shows **7 errors, 0 failures** (the pre-existing baseline).

- [ ] **Step 8: Commit**

```bash
git add plugins/avuz_poll_scope/avuz_poll_scope.php config/config.inc.php Dockerfile
git commit -m "feat(poll-scope): bound new-mail polling to INBOX, current and an allowlist

Roundcube polls folders in two places and only one is extensible: check_recent
exposes a hook, getunread has none and gates its cached path on the same
check_all_folders flag. Filtering the hook alone would leave getunread walking
every folder — on prod it averages 5.43s and fires on every page init.

So the plugin neutralises the flag on 'ready' instead, putting both paths on
their cheap branch, then re-adds a capped allowlist in the hook for users who
had the preference on. Users who never enabled it see no change.

The bounded set is applied for every action, not just refresh: check_recent.php:42
forces check_all true whenever the action is not 'refresh', which is why the
manual check-recent measured 43s on average for every user."
```

---

### Task 5: Update the customizations register

**Files:**
- Modify: `customizations.json`

**Interfaces:**
- Consumes: nothing. Produces: nothing.

- [ ] **Step 1: Add the entry**

Add a new object to the `entries` array in `customizations.json`, after the
`avuz-filters-plugin` entry:

```json
  {
    "id": "avuz-poll-scope-plugin",
    "type": "plugin",
    "paths": [
      "plugins/avuz_poll_scope/",
      "config/config.inc.php",
      "Dockerfile"
    ],
    "description": "Bounds new-mail polling. Neutralises check_all_folders on the 'ready' hook so core takes its cheap path in both check_recent and getunread, then re-adds a capped allowlist of Zoho auto-file folders (Spam/Junk/Newsletter/Notification) via the 'check_recent' hook for users who enabled the preference.",
    "risk": "medium",
    "notes": "Depends on two upstream details that must be re-checked after any rebase: check_recent.php exposing a 'check_recent' hook with a 'folders' key, and getunread.php having no hooks so that neutralising the config value is the only way to bound it. If upstream adds a hook to getunread, this can be simplified. Applies to every action, not just refresh, because check_recent.php forces check_all true for any action that is not 'refresh'. See docs/superpowers/specs/2026-07-22-folder-poll-scope-design.md."
  },
```

- [ ] **Step 2: Verify the JSON is valid**

Run: `python3 -c "import json; json.load(open('customizations.json')); print('valid')"`
Expected: `valid`

- [ ] **Step 3: Commit**

```bash
git add customizations.json
git commit -m "docs(customizations): register avuz_poll_scope

Records the two upstream details the plugin depends on, so a rebase that changes
either is caught: check_recent's hook contract, and getunread having no hook at
all — which is the only reason the flag has to be neutralised rather than the
folder list simply filtered."
```

---

### Task 6: Verify on staging

Everything above is unit-tested logic and wiring. The parts that cannot be unit
tested — the hooks firing at the right time, the unread push reaching the client,
the real IMAP cost — are verified here against a real mailbox.

**Files:** none modified.

**Interfaces:** none.

- [ ] **Step 1: Build and push the staging image**

```bash
./scripts/build-push.sh latest staging
```
Expected: ends with `✓ Pushed registry.avuz.app/admin/avuz-roundcube:staging`

- [ ] **Step 2: Deploy to staging**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env ./scripts/deploy.sh -y avuz-mail-roundcube-2
```
Expected: `→ avuz-mail-roundcube-2 ... redeployed`

- [ ] **Step 3: Confirm the plugin is present and the app serves**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 sh -c '
ls /var/www/roundcube/plugins/avuz_poll_scope/avuz_poll_scope.php
grep -c avuz_poll_scope /var/www/roundcube/config/config.inc.php
curl -s -o /dev/null -w "root=%{http_code}\n" http://localhost/'
```
Expected: the file path echoed, `2`, and `root=200`.

- [ ] **Step 4: Check for plugin load errors**

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh avuz-mail-roundcube-2-roundcube-1 sh -c '
grep -iE "PHP (Fatal|Parse)|avuz_poll_scope" /var/log/supervisor/php-fpm.err.log | tail -5 || echo "(no errors)"'
```
Expected: `(no errors)`. A "Failed to load plugin" line here means Task 4 Step 5 was missed.

- [ ] **Step 5: Verify the behavior table with a real account**

In a browser, log into staging. With **`check_all_folders` off** (Settings → Mailbox view), leave the
tab open for three minutes, then:

```bash
PORTAINER_ENV_FILE=scripts/deploy.env PORTAINER_ENDPOINT=3 ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-2-roundcube-1 sh -c '
grep " C: .* STATUS " /var/www/roundcube/logs/imap.log | tail -20 | sed "s/.*STATUS //; s/ (MESSAGES.*//" | sort -u'
```

Expected: **only INBOX and the folder being viewed.** (`imap.log` requires `ROUNDCUBE_DEBUG=1` in the
staging stack; if the log is empty, set it and redeploy staging before this step.)

Then enable **"Check all folders for new messages"** in Settings, wait three more minutes, and re-run
the same command. Expected: INBOX, the current folder, and **only** the allowlisted folders that
exist on that account — never the full folder list, and never more than six added.

- [ ] **Step 6: Verify the filter unread push**

On a staging account with an `avuz_filters` rule that moves mail into a folder, send a message that
matches the rule, wait for a refresh cycle, and confirm the destination folder's unread badge
increments **without opening that folder**. This is the only check that exercises Task 2 end to end.

- [ ] **Step 7: Record the result in the plan**

Append a short note to this file under a `## Staging verification` heading: the date, what was
observed for each of steps 5 and 6, and any deviation from the expected output. Commit it.

```bash
git add docs/superpowers/plans/2026-07-22-folder-poll-scope.md
git commit -m "docs(plan): record staging verification of folder poll scope"
```

---

### Task 7: Deploy to prod and measure

**Files:** none modified.

**Interfaces:** none.

- [ ] **Step 1: Capture the before-measurement**

```bash
PORTAINER_ENV_FILE=scripts/deploy.prod.env PORTAINER_ENDPOINT=5 ./scripts/portainer-exec.sh -u www-data avuz-mail-roundcube-roundcube-1 sh -c '
grep " refresh " /var/www/roundcube/logs/php-perf.log | awk "{print \$2}" | sort -n > /tmp/r.txt
awk "END {n=NR} {a[NR]=\$1; s+=\$1} END {printf \"n=%d mean=%.2f p50=%.2f p99=%.2f max=%.2f\n\", n, s/n, a[int(n*0.5)], a[int(n*0.99)], a[n]}" /tmp/r.txt'
```

Record the output. `sort` runs in the pipeline because busybox awk has no `asort`.

- [ ] **Step 2: Ask the user for a version tag and explicit go-ahead**

Prod deploys are gated. Do not proceed without both. Prod deploys use the stack **id**, because the
name is ambiguous across Portainer endpoints.

- [ ] **Step 3: Build, push and deploy**

```bash
./scripts/build-push.sh <VERSION> prod
PORTAINER_ENV_FILE=scripts/deploy.prod.env ./scripts/deploy.sh -y 36
```

- [ ] **Step 4: Verify health**

```bash
PORTAINER_ENV_FILE=scripts/deploy.prod.env PORTAINER_ENDPOINT=5 ./scripts/portainer-exec.sh avuz-mail-roundcube-roundcube-1 sh -c '
curl -s -o /dev/null -w "root=%{http_code} healthz=" http://localhost/; curl -s -o /dev/null -w "%{http_code}\n" http://localhost/healthz
grep -icE "PHP (Fatal|Parse)" /var/log/supervisor/php-fpm.err.log'
```
Expected: `root=200 healthz=200` and `0`.

- [ ] **Step 5: Capture the after-measurement**

Wait for a full business-hours window, then re-run the Step 1 command.

Acceptance:
- `refresh` **max** drops from >100s to <10s
- `refresh` **p99** drops from ~40s to <5s
- `refresh` **mean** and the proportion of sub-2s requests are unchanged — proving the ~95 users who never enabled the preference were unaffected

If mean rises, stop: the bounded set is being applied to users who did not opt in, which contradicts
the design's central invariant.

- [ ] **Step 6: Record the outcome**

Update `docs/superpowers/specs/2026-07-22-open-latency-HANDOFF.md` — mark the
`check_all_folders` open item resolved, with the before/after numbers. Update
`docs/superpowers/specs/2026-07-22-PROD-ROLLBACK-ANCHORS.md` with the new version, its digests and
what it contains. Commit.

---

## Self-Review

**Spec coverage:**

| Spec requirement | Task |
|---|---|
| `avuz_filters` pushes `send_unread_count` on move | 1, 2 |
| Neutralise the flag so `getunread` is bounded too | 4 |
| Re-add allowlist via `check_recent` hook for opted-in users | 4 |
| Last-segment, case-insensitive matching | 3 |
| Allowlist intersected with subscribed folders | 3 |
| Cap of 6, justified by cost budget | 3 |
| Config key `avuz_poll_folders` with the four defaults | 4 |
| Invariant: non-opted-in users unaffected | 4 (code), 7 Step 5 (measured) |
| Verify `send_unread_count` lands before output flush | 6 Step 6 |
| Deployment by image build, not container edit | 6, 7 |
| Distribution-based measurement | 7 Steps 1 and 5 |
| Label unchanged, preference stays opt-in | no code change needed — nothing sets either |

**Placeholder scan:** none — every code step contains the code, every command its expected output.

**Type consistency:** `move_target(array, string): ?string` is defined in Task 1 and consumed in Task 2 with that signature. `avuz_poll_folders::select(array, array, string, int): array` and `::CAP` are defined in Task 3 and consumed in Task 4 with those types. `apply()` changes to `?string` in Task 2 and every call site is updated in the same task.

**Gap found and closed during review:** Task 5 (customizations register) was missing; the repo requires every deviation from upstream to be recorded there, and this adds a plugin plus a config and Dockerfile change.

---
## Staging verification (2026-07-22)

Deployed `:staging` to stack `avuz-mail-roundcube-2`. Account used has **81 IMAP folders** — a good
proxy for the 107-folder production user.

Plugin present in the image, registered in config, allowlist
`['Spam','Junk','Newsletter','Notification']` live, `merge()` present, app serving 200 on `/` and
`/healthz`, **zero plugin load errors**.

### Behaviour table, verified on the wire

Separated by timestamp in `logs/imap.log`, since the log carries no action attribution:

| Refresh | Preference | STATUS commands issued | Folders |
|---|---|---|---|
| 02:29:15 | off | **1** | INBOX |
| 02:31:29 | off | **1** | INBOX |
| 02:33:28 | **on** | **5** | INBOX, Spam, Junk, Newsletter, Notification |

Account total, from the cold `getunread` pass at 02:25:56-02:26:13: **81 folders**.

So the opted-in case went **81 folders -> 5**, and the not-opted-in case is one folder, identical to
core. Both halves of the design's table confirmed against real IMAP.

### Also observed: the documented `getunread` cold pass, live

    02:25:48   25.46s  getunread   <- first page load of the session, ~85 STATUS in one burst
    02:27:15    0.11s  getunread   <- second load, cached

Exactly the trade-off recorded in the spec: neutralising the flag puts `getunread` on its cheap
branch from load #2, not load #1. The first mail page load of a session still counts every folder.
Large improvement (was every load), not a total fix.

### Not verified here

The `avuz_filters` unread-count push (Task 2) needs a staging account with a rule that moves mail;
not exercised. Its decision logic is unit-tested; the push itself remains verified only by
inspection.
