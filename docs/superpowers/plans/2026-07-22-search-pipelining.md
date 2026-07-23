# Pipelined Multi-Folder Search (Candidate C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all-folder search cost ~1 IMAP round trip instead of `2 × folder_count`, by writing every `SELECT`+`SEARCH` pair to the connection before reading any reply.

**Architecture:** Add `rcube_imap_generic::searchMulti()` — a pipelined sibling of the existing `search()` that reuses the same command-building logic and the same read-until-tag loop, on the same single connection. `rcube_imap_search::exec()` attempts one pipelined pass over all its jobs, then falls back to today's serial `foreach ($this->jobs as $job) { $job->run(); }` for anything the pipelined pass did not fill. Pipelining is **all-or-nothing**: any reply that is not `OK`, any tag out of order, or any dead socket abandons the pipelined result entirely and lets the serial path answer. No new state, no new connections, no extra work for Zoho.

**Tech Stack:** PHP 8.2, Roundcube 1.6.14 framework classes, PHPUnit 9 (`tests/phpunit.xml`).

## Global Constraints

- **Do NOT deploy to prod or staging.** This plan ends at "tests pass on a branch". Deployment is a separate, explicitly-approved step.
- **No Claude Code attribution in commit messages.** Conventional Commits format.
- **Reduce backend work; never add concurrency against Zoho.** One connection, same commands, same Zoho workload. Any change that opens a second connection is out of scope and wrong.
- **Do not raise `pm.max_children`.** Ruled out with evidence in `docs/superpowers/specs/2026-07-22-PROD-ROLLBACK-ANCHORS.md`.
- **Correctness gate:** a pipelined result set must be *identical* to a serial one, never merely similar. When in doubt, fall back to serial.
- **Zoho advertises no `SORT`, no `THREAD`, no `MULTISEARCH`, no `QRESYNC`.** It does advertise `ESEARCH` and `LITERAL-`. Do not assume anything outside that list.
- **Kill switch without a rebuild:** `AVUZ_PIPELINED_SEARCH=0` in the stack disables the pipelined path and restores today's behaviour exactly. Matches the existing `AVUZ_PERF_LOG=0` convention.
- Run tests with: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`

## Evidence this plan rests on

`docs/superpowers/spikes/2026-07-22-search-pipelining/RESULTS.md` — measured, at 198ms simulated RTT, through the production `up-imapproxy` build, with the real `rcube_imap_generic`:

| Folders | Serial | Pipelined | Result sets |
|---|---|---|---|
| 26 | 11,056 ms | 422 ms | identical |
| 107 | 44,961 ms | 517 ms | identical |

---

## File Structure

| File | Responsibility | Change |
|---|---|---|
| `program/lib/Roundcube/rcube_imap_generic.php` | IMAP wire protocol. Gains `searchParams()` (extracted), `readPipelined()`, `searchMulti()`. | Modify |
| `program/lib/Roundcube/rcube_imap_search.php` | Multi-folder search orchestration. `exec()` gains a pipelined first pass; `rcube_imap_search_job` gains accessors so both paths share one criteria string. | Modify |
| `tests/Framework/ImapGenericPipelined.php` | Behaviour tests for `searchMulti()` over a real socket pair with a scripted server. | Create |
| `tests/Framework/ImapSearchPipelined.php` | Behaviour tests for `rcube_imap_search::exec()` choosing pipelined vs serial. | Create |
| `docs/superpowers/spikes/2026-07-22-search-pipelining/integration_test.php` | Manual end-to-end check against the local dovecot + up-imapproxy harness. | Create |

`tests/phpunit.xml` needs **no** edit — the `Framework` suite already globs `<directory suffix=".php">Framework</directory>`.

`program/lib/Roundcube/rcube_imap.php` needs **no** edit. `set_timelimit(60)` at `:1655` stays exactly as it is; at ~0.5s per search it simply stops being reachable.

---

## Task 1: Extract `searchParams()` so pipelined and serial build identical commands

The pipelined path must produce byte-identical `SEARCH` parameters to the serial path, or the two can silently disagree. Extract the parameter-building half of `search()` first, with no behaviour change.

**Files:**
- Modify: `program/lib/Roundcube/rcube_imap_generic.php:1990-2032` (`search()`)
- Test: `tests/Framework/ImapGenericPipelined.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `protected function searchParams(string $criteria, array $items = []): string` — returns the text that follows `UID SEARCH ` on the wire (e.g. `RETURN (ALL) UNDELETED HEADER SUBJECT "x"`).

- [ ] **Step 1: Write the failing test**

Create `tests/Framework/ImapGenericPipelined.php`:

```php
<?php

/**
 * Behaviour tests for pipelined multi-folder search on rcube_imap_generic.
 *
 * The connection is a real socket pair. The server's replies are written into
 * the far end BEFORE the call, so a single blocking process can drive both
 * sides deterministically: the client writes all its commands into the socket
 * buffer, then reads the replies that are already waiting.
 *
 * @package Tests
 */
class Framework_ImapGenericPipelined extends PHPUnit\Framework\TestCase
{
    /** @var resource[] far ends of the socket pairs opened by a test, closed in tearDown */
    private $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        $this->sockets = [];
    }

    /**
     * Builds a stub connection whose server end already holds $replies.
     *
     * @return array{0: pipelined_imap_stub, 1: resource} the client, and the server end of the pair
     */
    private function connection(string $replies)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($replies !== '') {
            fwrite($pair[1], $replies);
        }

        $imap = new pipelined_imap_stub();
        $imap->attach($pair[0]);

        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        return [$imap, $pair[1]];
    }

    /** Everything the client wrote, as one string. */
    private function sent($server): string
    {
        stream_set_blocking($server, false);

        $out = '';
        while (($chunk = fread($server, 65536)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    function test_searchParams_uses_esearch_return_all_for_a_text_criteria()
    {
        list($imap, $server) = $this->connection('');

        $this->assertSame('RETURN (ALL) UNDELETED HEADER SUBJECT "x"',
            $imap->expose_searchParams('UNDELETED HEADER SUBJECT "x"'));
    }

    function test_searchParams_omits_esearch_for_a_numeric_criteria()
    {
        list($imap, $server) = $this->connection('');

        $this->assertSame('13339', $imap->expose_searchParams('13339'));
    }

    function test_searchParams_defaults_to_all_when_criteria_is_empty()
    {
        list($imap, $server) = $this->connection('');

        $this->assertSame('ALL', $imap->expose_searchParams(''));
    }
}

/**
 * Test double exposing rcube_imap_generic's protected connection state.
 */
class pipelined_imap_stub extends rcube_imap_generic
{
    /** @param resource $fp */
    public function attach($fp): void
    {
        $this->fp              = $fp;
        $this->logged          = true;
        $this->prefs['timeout'] = 5;
        $this->capability       = ['IMAP4REV1', 'ESEARCH', 'LITERAL-'];
        $this->capability_read  = true;
    }

    public function expose_searchParams(string $criteria, array $items = []): string
    {
        return $this->searchParams($criteria, $items);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Framework_ImapGenericPipelined`
Expected: FAIL — `Call to protected method` / `searchParams()` does not exist.

- [ ] **Step 3: Extract the method**

In `program/lib/Roundcube/rcube_imap_generic.php`, replace the body of `search()` between the empty-folder early return and the `execute()` call. The whole method becomes:

```php
    public function search($mailbox, $criteria, $return_uid = false, $items = [])
    {
        $old_sel = $this->selected;

        if (!$this->select($mailbox)) {
            return new rcube_result_index($mailbox);
        }

        // return empty result when folder is empty and we're just after SELECT
        if ($old_sel != $mailbox && !$this->data['EXISTS']) {
            return new rcube_result_index($mailbox, '* SEARCH');
        }

        $params = $this->searchParams($criteria, $items);

        list($code, $response) = $this->execute($return_uid ? 'UID SEARCH' : 'SEARCH', [$params]);

        if ($code != self::ERROR_OK) {
            $response = null;
        }

        return new rcube_result_index($mailbox, $response);
    }

    /**
     * Builds the argument text of a SEARCH command.
     *
     * Shared by search() and searchMulti() so the serial and pipelined paths
     * can never send subtly different commands for the same criteria.
     *
     * @param string $criteria Searching criteria
     * @param array  $items    Return items (MIN, MAX, COUNT, ALL)
     *
     * @return string Text following "SEARCH " on the wire
     */
    protected function searchParams($criteria, $items = [])
    {
        // If ESEARCH is supported always use ALL
        // but not when items are specified or using simple id2uid search
        if (empty($items) && preg_match('/[^0-9]/', $criteria)) {
            $items = ['ALL'];
        }

        $esearch  = empty($items) ? false : $this->getCapability('ESEARCH');
        $criteria = trim($criteria);
        $params   = '';

        // RFC4731: ESEARCH
        if (!empty($items) && $esearch) {
            $params .= 'RETURN (' . implode(' ', $items) . ')';
        }

        if (!empty($criteria)) {
            $params .= ($params ? ' ' : '') . $criteria;
        }
        else {
            $params .= 'ALL';
        }

        return $params;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`
Expected: PASS, including the pre-existing `Framework_ImapGeneric` suite (this is a pure refactor — nothing else may change).

- [ ] **Step 5: Commit**

```bash
git add program/lib/Roundcube/rcube_imap_generic.php tests/Framework/ImapGenericPipelined.php
git commit -m "refactor(imap): extract searchParams() from search()"
```

---

## Task 2: `readPipelined()` — read one tagged reply, refusing to guess

The read half. It is the same loop as `execute()` with two additions that make a shared connection safe: a dead socket must not spin, and a tag arriving out of order must kill the connection rather than be attributed to the wrong folder.

**Files:**
- Modify: `program/lib/Roundcube/rcube_imap_generic.php` (add after `searchParams()`)
- Test: `tests/Framework/ImapGenericPipelined.php`

**Interfaces:**
- Consumes: `searchParams()` from Task 1.
- Produces: `protected function readPipelined(string $tag): array|false` — returns `['code' => int, 'response' => string]` where `code` is one of the `self::ERROR_*` constants and `response` is the untagged lines with the tagged line removed; returns `false` after closing the socket when the connection died or a foreign tag arrived.

- [ ] **Step 1: Write the failing tests**

Append to the `Framework_ImapGenericPipelined` class in `tests/Framework/ImapGenericPipelined.php`:

```php
    function test_readPipelined_returns_the_untagged_lines_for_its_own_tag()
    {
        list($imap, $server) = $this->connection(
            "* SEARCH 1 4 7\r\n"
            . "A0001 OK Search completed\r\n"
        );

        $reply = $imap->expose_readPipelined('A0001');

        $this->assertSame(rcube_imap_generic::ERROR_OK, $reply['code']);
        $this->assertSame('* SEARCH 1 4 7', $reply['response']);
    }

    function test_readPipelined_reports_a_failed_command_without_closing_the_connection()
    {
        list($imap, $server) = $this->connection("A0001 NO Mailbox doesn't exist\r\n");

        $reply = $imap->expose_readPipelined('A0001');

        $this->assertSame(rcube_imap_generic::ERROR_NO, $reply['code']);
        $this->assertTrue($imap->connected(), 'a NO reply is an answer, not a desync');
    }

    function test_readPipelined_closes_the_connection_when_a_foreign_tag_arrives()
    {
        list($imap, $server) = $this->connection("A0002 OK Search completed\r\n");

        $this->assertFalse($imap->expose_readPipelined('A0001'));
        $this->assertFalse($imap->connected(), 'a desynchronised connection must never be reused');
    }

    function test_readPipelined_closes_the_connection_when_the_socket_is_empty()
    {
        list($imap, $server) = $this->connection('');
        fclose($server);

        $this->assertFalse($imap->expose_readPipelined('A0001'));
        $this->assertFalse($imap->connected());
    }
```

And add the accessor to `pipelined_imap_stub`:

```php
    public function expose_readPipelined(string $tag)
    {
        return $this->readPipelined($tag);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Framework_ImapGenericPipelined`
Expected: FAIL — `readPipelined()` does not exist.

- [ ] **Step 3: Implement**

Add to `program/lib/Roundcube/rcube_imap_generic.php`, directly after `searchParams()`:

```php
    /**
     * Reads one tagged reply from a pipelined batch.
     *
     * Differs from execute()'s read loop in exactly two ways, both of which
     * exist because a pipelined batch shares one connection: an empty read
     * means the peer went away and must not be retried, and a tag other than
     * the expected one means the reply stream is desynchronised, at which
     * point every later reply would be attributed to the wrong folder.
     *
     * @param string $tag Command identifier to read up to
     *
     * @return array|false ['code' => int, 'response' => string], or false if the connection was closed
     */
    protected function readPipelined($tag)
    {
        $response = '';

        do {
            $line = $this->readFullLine(4096);

            if ($line === '' || $line === false) {
                $this->closeSocket();
                $this->setError(self::ERROR_COMMAND, "Connection closed while waiting for $tag");

                return false;
            }

            $response .= $line;

            // Untagged data starts with '*' or '+'; only a tagged line can match here.
            if (preg_match('/^(A[0-9]+) /', $line, $matches) && $matches[1] !== $tag) {
                $this->closeSocket();
                $this->setError(self::ERROR_COMMAND, "Pipelined reply out of order: expected $tag, got {$matches[1]}");

                return false;
            }
        }
        while (!$this->startsWith($line, $tag . ' ', true, true));

        $code = $this->parseResult($line, '');

        return [
            'code'     => $code,
            'response' => rtrim(substr($response, 0, -strlen($line)), "\r\n"),
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Framework_ImapGenericPipelined`
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add program/lib/Roundcube/rcube_imap_generic.php tests/Framework/ImapGenericPipelined.php
git commit -m "feat(imap): add readPipelined() with desync detection"
```

---

## Task 3: `searchMulti()` — write every command, then read every reply

**Files:**
- Modify: `program/lib/Roundcube/rcube_imap_generic.php` (add after `readPipelined()`; add the chunk constant beside the other class constants at `:73-79`)
- Test: `tests/Framework/ImapGenericPipelined.php`

**Interfaces:**
- Consumes: `searchParams()`, `readPipelined()`.
- Produces: `public function searchMulti(array $folders, string $criteria, bool $return_uid = false, array $items = []): array|false` — returns `rcube_result_index` keyed by folder name, in the order given; returns `false` if the pipelined run cannot be trusted, in which case the caller must use the serial path.

**Why chunked:** the client writes without reading, so the server's replies pile up in the kernel buffers. If a batch's replies exceed them, the server blocks writing while the client blocks writing, and both hang. 25 pairs is ~5 kB of commands and — with `ESEARCH RETURN (ALL)` compacting results to ranges, which the measured 2.3 kB all-folder response confirms — a few kB of replies. 107 folders becomes 5 batches, ~1.0s instead of 45s.

**Why all-or-nothing:** the serial path retries a failed `SEARCH` with a converted charset (`rcube_imap_search.php`, `search_index()`). A pipeline cannot retry mid-stream, and treating a failed `SEARCH` as "no matches" would silently lose mail. So any non-`OK` reply abandons the whole pipelined result and the serial path — which can retry — answers instead.

- [ ] **Step 1: Write the failing tests**

Append to the `Framework_ImapGenericPipelined` class:

```php
    /** Builds the canned server side of a successful N-folder pipelined search. */
    private function replies(array $folders, int $first_tag = 1): string
    {
        $out = '';
        $tag = $first_tag;

        foreach ($folders as $folder => $uids) {
            $out .= "* 3 EXISTS\r\n";
            $out .= sprintf("A%04d OK [READ-WRITE] Select completed\r\n", $tag++);
            $out .= "* ESEARCH (TAG \"\") UID ALL $uids\r\n";
            $out .= sprintf("A%04d OK Search completed\r\n", $tag++);
        }

        return $out;
    }

    function test_searchMulti_returns_one_result_per_folder_in_order()
    {
        $folders = ['INBOX' => '1,4', 'Sent' => '7', 'Archive' => '2:5'];
        list($imap, $server) = $this->connection($this->replies($folders));

        $results = $imap->searchMulti(array_keys($folders), 'HEADER SUBJECT "x"', true);

        $this->assertSame(['INBOX', 'Sent', 'Archive'], array_keys($results));
        $this->assertSame([1, 4], $results['INBOX']->get());
        $this->assertSame([7], $results['Sent']->get());
        $this->assertSame([2, 3, 4, 5], $results['Archive']->get());
    }

    function test_searchMulti_writes_every_command_before_reading_any_reply()
    {
        // No replies at all, and a non-blocking socket: the first read fails.
        // A serial implementation would therefore have written only the first
        // SELECT. A pipelined one has already written all six commands.
        list($imap, $server) = $this->connection('');
        stream_set_blocking($imap->socket(), false);

        $this->assertFalse($imap->searchMulti(['INBOX', 'Sent', 'Archive'], 'HEADER SUBJECT "x"', true));

        $sent = $this->sent($server);

        $this->assertSame(3, substr_count($sent, ' SELECT '), 'all SELECTs must be on the wire');
        $this->assertSame(3, substr_count($sent, ' UID SEARCH '), 'all SEARCHes must be on the wire');
        $this->assertStringContainsString("A0001 SELECT INBOX\r\nA0002 UID SEARCH ", $sent);
    }

    function test_searchMulti_sends_the_same_command_text_as_the_serial_path()
    {
        list($imap, $server) = $this->connection('');
        stream_set_blocking($imap->socket(), false);

        $imap->searchMulti(['INBOX'], 'UNDELETED HEADER SUBJECT "x"', true);

        $this->assertStringContainsString(
            'A0002 UID SEARCH RETURN (ALL) UNDELETED HEADER SUBJECT "x"' . "\r\n",
            $this->sent($server)
        );
    }

    function test_searchMulti_gives_up_when_any_folder_replies_not_ok()
    {
        $replies = "* 3 EXISTS\r\nA0001 OK [READ-WRITE] Select completed\r\n"
            . "* ESEARCH (TAG \"\") UID ALL 1\r\nA0002 OK Search completed\r\n"
            . "A0003 NO Mailbox doesn't exist: Gone\r\n"
            . "A0004 BAD No mailbox selected\r\n";

        list($imap, $server) = $this->connection($replies);

        $this->assertFalse($imap->searchMulti(['INBOX', 'Gone'], 'HEADER SUBJECT "x"', true),
            'a partial answer must never be returned as a complete one');
        $this->assertTrue($imap->connected(),
            'the batch drained cleanly, so the connection is still usable for the serial retry');
    }

    function test_searchMulti_clears_the_selected_mailbox_state()
    {
        $folders = ['INBOX' => '1', 'Sent' => '2'];
        list($imap, $server) = $this->connection($this->replies($folders));

        $imap->searchMulti(array_keys($folders), 'HEADER SUBJECT "x"', true);

        $this->assertNull($imap->selected, 'a later select() must not short-circuit on a stale folder');
        $this->assertArrayNotHasKey('EXISTS', $imap->data);
        $this->assertArrayNotHasKey('UIDNEXT', $imap->data);
    }

    function test_searchMulti_chunks_batches_so_replies_cannot_deadlock()
    {
        $folders = [];
        for ($i = 0; $i < 30; $i++) {
            $folders["F$i"] = '1';
        }

        list($imap, $server) = $this->connection($this->replies($folders));

        $results = $imap->searchMulti(array_keys($folders), 'HEADER SUBJECT "x"', true);

        $this->assertCount(30, $results);
        $this->assertSame([1], $results['F29']->get());
    }

    function test_searchMulti_refuses_an_empty_folder_list()
    {
        list($imap, $server) = $this->connection('');

        $this->assertFalse($imap->searchMulti([], 'HEADER SUBJECT "x"', true));
    }
```

Add the socket accessor to `pipelined_imap_stub`:

```php
    /** @return resource */
    public function socket()
    {
        return $this->fp;
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Framework_ImapGenericPipelined`
Expected: FAIL — `searchMulti()` does not exist.

- [ ] **Step 3: Implement**

Add the constant beside the existing `COMMAND_*` constants in `program/lib/Roundcube/rcube_imap_generic.php`:

```php
    const SEARCH_PIPELINE_CHUNK = 25;
```

Add the method after `readPipelined()`:

```php
    /**
     * Executes SELECT + SEARCH for many folders on one connection, pipelined.
     *
     * Every command in a batch is written before any reply is read, so N
     * folders cost roughly one round trip instead of 2N. Zoho performs exactly
     * the same work; only the waiting disappears.
     *
     * The run is all-or-nothing. Anything other than a clean OK for every
     * command returns false, and the caller must fall back to the serial
     * search() path — which, unlike a pipeline, can retry a folder.
     *
     * @param array  $folders    Folder names to search, in order
     * @param string $criteria   Searching criteria, identical for every folder
     * @param bool   $return_uid Enable UID in result instead of sequence ID
     * @param array  $items      Return items (MIN, MAX, COUNT, ALL)
     *
     * @return array|false rcube_result_index keyed by folder name, or false if the run cannot be trusted
     */
    public function searchMulti($folders, $criteria, $return_uid = false, $items = [])
    {
        if (!$this->connected() || empty($folders)) {
            return false;
        }

        $command = $return_uid ? 'UID SEARCH' : 'SEARCH';
        $params  = $this->searchParams($criteria, $items);
        $results = [];

        // A pipelined run walks through every folder and ends on an arbitrary
        // one, so the cached selected-mailbox state describes nothing. Drop it
        // before the first command, not after, so an abandoned run leaves the
        // connection in the same honest state as a completed one.
        $this->clear_mailbox_cache();
        unset($this->data['EXISTS'], $this->data['RECENT']);
        $this->selected = null;

        foreach (array_chunk($folders, self::SEARCH_PIPELINE_CHUNK) as $batch) {
            $tags = [];

            foreach ($batch as $folder) {
                $select = $this->nextTag();
                if ($this->putLineC($select . ' SELECT ' . $this->escape($folder)) === false) {
                    return false;
                }

                $search = $this->nextTag();
                if ($this->putLineC($search . ' ' . $command . ' ' . $params) === false) {
                    return false;
                }

                $tags[] = [$folder, $select, $search];
            }

            // Read the whole batch before judging it, so a folder that failed
            // leaves no unread replies behind for the next command to trip on.
            $failed = false;

            foreach ($tags as $tag) {
                list($folder, $select, $search) = $tag;

                $selected = $this->readPipelined($select);
                $found    = $selected === false ? false : $this->readPipelined($search);

                if ($selected === false || $found === false) {
                    return false;
                }

                if ($selected['code'] != self::ERROR_OK || $found['code'] != self::ERROR_OK) {
                    $failed = true;
                    continue;
                }

                $results[$folder] = new rcube_result_index($folder, $found['response']);
            }

            if ($failed) {
                return false;
            }
        }

        return $results;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`
Expected: PASS — 15 tests in `Framework_ImapGenericPipelined`, and the whole pre-existing Framework suite still green.

- [ ] **Step 5: Commit**

```bash
git add program/lib/Roundcube/rcube_imap_generic.php tests/Framework/ImapGenericPipelined.php
git commit -m "feat(imap): pipeline SELECT+SEARCH across folders on one connection"
```

---

## Task 4: Job accessors so both paths share one criteria string

`rcube_imap_search_job::search_index()` builds the final criteria (the `skip_deleted` prefix, the charset downgrade, the `CHARSET` prefix) inline. The pipelined path needs that same string. Extract it, and add the accessors `rcube_imap_search::exec()` will need in Task 5.

**Files:**
- Modify: `program/lib/Roundcube/rcube_imap_search.php` (class `rcube_imap_search_job`)
- Test: `tests/Framework/ImapSearchPipelined.php`

**Interfaces:**
- Consumes: nothing.
- Produces, on `rcube_imap_search_job`:
  - `public function get_folder(): string`
  - `public function get_criteria(): string` — the exact text handed to `rcube_imap_generic::search()`, including any `CHARSET x ` prefix and the `UNDELETED ` prefix
  - `public function has_result(): bool` — true once a real result has been stored
  - `public function set_result(rcube_result_index $result): void`

- [ ] **Step 1: Write the failing test**

Create `tests/Framework/ImapSearchPipelined.php`:

```php
<?php

/**
 * Behaviour tests for the pipelined pass in rcube_imap_search.
 *
 * @package Tests
 */
class Framework_ImapSearchPipelined extends PHPUnit\Framework\TestCase
{
    private function worker(array $options = []): rcube_imap_search
    {
        return new rcube_imap_search($options + ['skip_deleted' => false], new imap_search_conn_stub());
    }

    private function job(rcube_imap_search $worker, string $folder, string $criteria, $charset = null): rcube_imap_search_job
    {
        $job = new rcube_imap_search_job($folder, $criteria, $charset);
        $job->worker = $worker;

        return $job;
    }

    function test_get_criteria_returns_the_bare_criteria_when_nothing_applies()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"');

        $this->assertSame('HEADER SUBJECT "x"', $job->get_criteria());
    }

    function test_get_criteria_prefixes_undeleted_when_skip_deleted_is_on()
    {
        $job = $this->job($this->worker(['skip_deleted' => true]), 'INBOX', 'HEADER SUBJECT "x"');

        $this->assertSame('UNDELETED HEADER SUBJECT "x"', $job->get_criteria());
    }

    function test_get_criteria_drops_the_charset_for_an_ascii_criteria()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"', 'UTF-8');

        $this->assertSame('HEADER SUBJECT "x"', $job->get_criteria());
    }

    function test_get_criteria_keeps_the_charset_for_a_non_ascii_criteria()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "Relatório"', 'UTF-8');

        $this->assertSame('CHARSET UTF-8 HEADER SUBJECT "Relatório"', $job->get_criteria());
    }

    function test_a_fresh_job_has_no_result()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"');

        $this->assertFalse($job->has_result());
    }

    function test_set_result_marks_the_job_answered()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"');
        $job->set_result(new rcube_result_index('INBOX', '* SEARCH 1 4'));

        $this->assertTrue($job->has_result());
        $this->assertSame([1, 4], $job->get_result()->get());
        $this->assertSame('INBOX', $job->get_folder());
    }
}

/**
 * Stands in for rcube_imap_generic. Records the searches it is asked for and
 * returns whatever the test scripted, so the tests assert on which path
 * rcube_imap_search chose rather than on any wire traffic.
 */
class imap_search_conn_stub extends rcube_imap_generic
{
    /** @var array|false result for searchMulti(), or false to force the serial fallback */
    public $multi_result = false;

    /** @var array<string, string> canned '* SEARCH ...' response per folder for the serial path */
    public $serial_results = [];

    /** @var array<int, array> every call made, in order */
    public $calls = [];

    public function connected()
    {
        return true;
    }

    public function searchMulti($folders, $criteria, $return_uid = false, $items = [])
    {
        $this->calls[] = ['searchMulti', $folders, $criteria];

        return $this->multi_result;
    }

    public function search($mailbox, $criteria, $return_uid = false, $items = [])
    {
        $this->calls[] = ['search', $mailbox, $criteria];

        return new rcube_result_index($mailbox, $this->serial_results[$mailbox] ?? '* SEARCH');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Framework_ImapSearchPipelined`
Expected: FAIL — `get_criteria()` does not exist.

- [ ] **Step 3: Implement**

In `program/lib/Roundcube/rcube_imap_search.php`, inside `class rcube_imap_search_job`, add these four methods after the constructor, and rewrite `search_index()` to use `get_criteria()`:

```php
    /**
     * The folder this job searches.
     */
    public function get_folder()
    {
        return $this->folder;
    }

    /**
     * The exact criteria text handed to the IMAP SEARCH command.
     *
     * Extracted so the pipelined and the serial path cannot diverge on the
     * skip_deleted prefix or the charset handling.
     *
     * @return string Criteria, including any CHARSET prefix
     */
    public function get_criteria()
    {
        $criteria = $this->search;
        $charset  = $this->charset;

        if ($this->worker->options['skip_deleted'] && !preg_match('/UNDELETED/', $criteria)) {
            $criteria = 'UNDELETED ' . $criteria;
        }

        // unset CHARSET if criteria string is ASCII, this way
        // SEARCH won't be re-sent after "unsupported charset" response
        if ($charset && $charset != 'US-ASCII' && is_ascii($criteria)) {
            $charset = 'US-ASCII';
        }

        return ($charset && $charset != 'US-ASCII' ? "CHARSET $charset " : '') . $criteria;
    }

    /**
     * True once a real result has been stored, by either path.
     */
    public function has_result()
    {
        return empty($this->result->incomplete);
    }

    /**
     * Stores a result obtained outside run(), i.e. from a pipelined batch.
     *
     * @param rcube_result_index $result Search result for this job's folder
     */
    public function set_result($result)
    {
        $result->incomplete = false;
        $this->result       = $result;
    }
```

Then replace the tail of `search_index()` — the `if ($this->threading)` block onwards keeps its `$imap` and threading/sort handling, but the plain-SEARCH branch now reads:

```php
        if (empty($messages) || $messages->is_error()) {
            $messages = $imap->search($this->folder, $this->get_criteria(), true);

            // Error, try with US-ASCII (some servers may support only US-ASCII)
            if ($messages->is_error() && $charset && $charset != 'US-ASCII') {
                $messages = $imap->search($this->folder,
                    rcube_imap::convert_criteria($criteria, $charset), true);
            }
        }
```

Keep the local `$criteria` and `$charset` variables at the top of `search_index()` exactly as they are — the threading, sort and charset-retry branches still use them. `get_criteria()` only replaces the plain-SEARCH call's argument.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`
Expected: PASS — 6 new tests, existing `Framework_ImapSearch` still green.

- [ ] **Step 5: Commit**

```bash
git add program/lib/Roundcube/rcube_imap_search.php tests/Framework/ImapSearchPipelined.php
git commit -m "refactor(search): expose job folder, criteria and result"
```

---

## Task 5: `exec()` tries one pipelined pass, then falls back

**Files:**
- Modify: `program/lib/Roundcube/rcube_imap_search.php:65-96` (`rcube_imap_search::exec()`)
- Test: `tests/Framework/ImapSearchPipelined.php`

**Interfaces:**
- Consumes: `rcube_imap_generic::searchMulti()` (Task 3); `get_folder()`, `get_criteria()`, `has_result()`, `set_result()` (Task 4).
- Produces: no new public API. `exec()`'s signature and return type are unchanged.

**Rules the implementation must obey:**
- Skip pipelining when `AVUZ_PIPELINED_SEARCH=0`, when threading or a sort field is requested (Zoho has neither `SORT` nor `THREAD`, and neither is expressible in this pipeline), when there are no jobs, or when the connection is down.
- Skip pipelining when the jobs do not all share one criteria string. `rcube_imap::search()` accepts a per-folder criteria array; one pipeline sends one command shape.
- After a pipelined pass, every job without a result still runs serially, still under the existing time limit. That is the same loop as today, untouched.

- [ ] **Step 1: Write the failing tests**

Append to `Framework_ImapSearchPipelined`:

```php
    function test_exec_answers_every_folder_from_one_pipelined_pass()
    {
        $conn = new imap_search_conn_stub();
        $conn->multi_result = [
            'INBOX' => new rcube_result_index('INBOX', '* SEARCH 1 4'),
            'Sent'  => new rcube_result_index('Sent', '* SEARCH 7'),
        ];

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $result = $worker->exec(['INBOX', 'Sent'], 'HEADER SUBJECT "x"');

        $this->assertSame([['searchMulti', ['INBOX', 'Sent'], 'HEADER SUBJECT "x"']], $conn->calls,
            'a pipelined pass must not be followed by per-folder searches');
        $this->assertSame(3, $result->count());
    }

    function test_exec_falls_back_to_serial_when_the_pipeline_is_refused()
    {
        $conn = new imap_search_conn_stub();
        $conn->multi_result   = false;
        $conn->serial_results = ['INBOX' => '* SEARCH 1 4', 'Sent' => '* SEARCH 7'];

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $result = $worker->exec(['INBOX', 'Sent'], 'HEADER SUBJECT "x"');

        $this->assertSame(
            [
                ['searchMulti', ['INBOX', 'Sent'], 'HEADER SUBJECT "x"'],
                ['search', 'INBOX', 'HEADER SUBJECT "x"'],
                ['search', 'Sent', 'HEADER SUBJECT "x"'],
            ],
            $conn->calls
        );
        $this->assertSame(3, $result->count(), 'the fallback must produce the same answer');
    }

    function test_exec_does_not_pipeline_per_folder_criteria()
    {
        $conn = new imap_search_conn_stub();
        $conn->serial_results = ['INBOX' => '* SEARCH 1', 'Sent' => '* SEARCH 7'];

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $worker->exec(['INBOX', 'Sent'], ['INBOX' => 'HEADER SUBJECT "x"', 'Sent' => 'HEADER TO "y"']);

        $this->assertSame(
            [['search', 'INBOX', 'HEADER SUBJECT "x"'], ['search', 'Sent', 'HEADER TO "y"']],
            $conn->calls,
            'one pipeline sends one command shape; differing criteria must go serial'
        );
    }

    function test_exec_does_not_pipeline_a_threaded_search()
    {
        $conn = new imap_search_conn_stub();

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $worker->exec(['INBOX'], 'HEADER SUBJECT "x"', null, null, 'REFERENCES');

        $this->assertEmpty(array_filter($conn->calls, fn($call) => $call[0] === 'searchMulti'));
    }

    function test_exec_honours_the_kill_switch()
    {
        putenv('AVUZ_PIPELINED_SEARCH=0');

        try {
            $conn = new imap_search_conn_stub();
            $conn->serial_results = ['INBOX' => '* SEARCH 1'];

            $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
            $worker->exec(['INBOX'], 'HEADER SUBJECT "x"');

            $this->assertSame([['search', 'INBOX', 'HEADER SUBJECT "x"']], $conn->calls);
        }
        finally {
            putenv('AVUZ_PIPELINED_SEARCH');
        }
    }
```

The threading test needs the stub to answer `thread()`; add to `imap_search_conn_stub`:

```php
    public function thread($mailbox, $algorithm = 'REFERENCES', $criteria = '', $return_uid = false, $encoding = 'US-ASCII')
    {
        $this->calls[] = ['thread', $mailbox, $criteria];

        return new rcube_result_thread($mailbox, '* THREAD');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --filter Framework_ImapSearchPipelined`
Expected: FAIL — `exec()` never calls `searchMulti`, so `$conn->calls` holds only `search` entries.

- [ ] **Step 3: Implement**

In `program/lib/Roundcube/rcube_imap_search.php`, replace the job-execution loop in `exec()` and add the new method:

```php
        // One pipelined pass over every pending job: all SELECT+SEARCH pairs go
        // out before any reply is read, so N folders cost ~1 round trip rather
        // than 2N. Anything it declines is answered by the serial loop below,
        // which is the unchanged behaviour.
        $this->run_pipelined($sort_field, $threading);

        // execute jobs and gather results
        foreach ($this->jobs as $job) {
            // only run search if within the configured time limit
            // TODO: try to estimate the required time based on folder size and previous search performance
            if (!$job->has_result()
                && (!$this->timelimit || floor(microtime(true)) - $start < $this->timelimit)
            ) {
                $job->run();
            }

            // add result (may have ->incomplete flag set)
            $results->add($job->get_result());
        }

        return $results;
    }

    /**
     * Answers as many pending jobs as possible from a single pipelined batch.
     *
     * Declines silently — leaving every job for the serial path — whenever the
     * pipeline cannot represent the search faithfully. Correctness first: a
     * fast wrong answer is worse than a slow right one.
     *
     * @param string $sort_field Header field to sort by, if any
     * @param bool   $threading  True if threaded listing is active
     */
    protected function run_pipelined($sort_field, $threading)
    {
        if (empty($this->jobs) || $threading || $sort_field || getenv('AVUZ_PIPELINED_SEARCH') === '0') {
            return;
        }

        $imap = $this->get_imap();

        if (!$imap->connected()) {
            return;
        }

        // One batch sends one command shape to many folders, so every job must
        // agree on the criteria. rcube_imap::search() allows a per-folder
        // criteria array; that case goes serial.
        $criteria = null;
        $folders  = [];

        foreach ($this->jobs as $job) {
            $job_criteria = $job->get_criteria();

            if ($criteria !== null && $job_criteria !== $criteria) {
                return;
            }

            $criteria  = $job_criteria;
            $folders[] = $job->get_folder();
        }

        $results = $imap->searchMulti($folders, $criteria, true);

        if (!is_array($results)) {
            return;
        }

        foreach ($this->jobs as $job) {
            if (isset($results[$job->get_folder()])) {
                $job->set_result($results[$job->get_folder()]);
            }
        }
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml --testsuite Framework`
Expected: PASS — 11 tests in `Framework_ImapSearchPipelined`, everything else green.

- [ ] **Step 5: Commit**

```bash
git add program/lib/Roundcube/rcube_imap_search.php tests/Framework/ImapSearchPipelined.php
git commit -m "feat(search): pipeline multi-folder search with a serial fallback"
```

---

## Task 6: End-to-end check against a real IMAP server behind the real proxy

The unit tests script the server. This proves the whole stack — `rcube_imap_search` → `rcube_imap_generic` → `up-imapproxy` → a real IMAP server — agrees, and produces the number to quote. It reuses the spike harness that is already in the repository.

**Files:**
- Create: `docs/superpowers/spikes/2026-07-22-search-pipelining/integration_test.php`
- Modify: `docs/superpowers/spikes/2026-07-22-search-pipelining/RESULTS.md` (append the post-implementation result)

**Interfaces:**
- Consumes: `rcube_imap_search::exec()`, `rcube_imap_generic::searchMulti()`.
- Produces: nothing consumed by other tasks.

- [ ] **Step 1: Bring the harness up**

```bash
cd docs/superpowers/spikes/2026-07-22-search-pipelining
docker compose up -d --build
python3 pipe_test.py --seed
python3 seed_many.py
python3 latency_proxy.py 1144 1143 99 &
```

Expected: `seeded`, then `seeded 107 folders`, then `delay proxy :1144 -> :1143 (99ms each way)`.

- [ ] **Step 2: Write the integration check**

Create `docs/superpowers/spikes/2026-07-22-search-pipelining/integration_test.php`:

```php
<?php
/**
 * End-to-end check for pipelined multi-folder search.
 *
 * Runs rcube_imap_search::exec() against the local harness (dovecot behind the
 * production up-imapproxy build, optionally behind the 198ms latency shim) with
 * the pipelined path on and then off, and asserts the two answers are identical.
 *
 * Usage: php integration_test.php [port]      (1143 = no latency, 1144 = 198ms RTT)
 */
$RC = dirname(__DIR__, 4) . '/program/lib/Roundcube';
require_once "$RC/rcube_utils.php";
require_once "$RC/rcube_imap_generic.php";
require_once "$RC/rcube_result_index.php";
require_once "$RC/rcube_result_thread.php";
require_once "$RC/rcube_result_multifolder.php";
require_once "$RC/rcube_imap_search.php";

$port    = (int) ($argv[1] ?? 1144);
$folders = array_merge(['INBOX'], array_map(fn($i) => sprintf('F%03d', $i), range(1, 106)));
$options = ['skip_deleted' => false];

function run(int $port, array $folders, array $options, bool $pipelined): array
{
    putenv('AVUZ_PIPELINED_SEARCH=' . ($pipelined ? '1' : '0'));

    $imap = new rcube_imap_generic();
    $imap->connect('127.0.0.1', 'spike@example.com', 'spikepass',
        ['port' => $port, 'ssl_mode' => null, 'auth_type' => 'LOGIN', 'timeout' => 60]);

    $searcher = new rcube_imap_search($options, $imap);
    $searcher->set_timelimit(60);

    $started = microtime(true);
    $result  = $searcher->exec($folders, 'HEADER SUBJECT "needle-hit"');
    $elapsed = (microtime(true) - $started) * 1000;

    $sets = [];
    foreach ($folders as $folder) {
        $sets[$folder] = implode(',', $result->get_set($folder)->get());
    }

    $imap->closeConnection();

    return [$elapsed, $sets];
}

list($serial_ms, $serial)       = run($port, $folders, $options, false);
list($pipelined_ms, $pipelined) = run($port, $folders, $options, true);

printf("folders   : %d\n", count($folders));
printf("serial    : %8.1f ms\n", $serial_ms);
printf("pipelined : %8.1f ms\n", $pipelined_ms);
printf("speedup   : %.1fx\n", $serial_ms / max($pipelined_ms, 0.001));
printf("IDENTICAL : %s\n", $serial === $pipelined ? 'yes' : 'NO');

exit($serial === $pipelined ? 0 : 1);
```

- [ ] **Step 3: Run it**

```bash
php docs/superpowers/spikes/2026-07-22-search-pipelining/integration_test.php 1144
```

Expected: `IDENTICAL : yes`, exit status 0, and a pipelined time under 2s against a serial time around 45s. **If `IDENTICAL` is `NO`, stop and fix — this is the correctness gate, not a performance number.**

- [ ] **Step 4: Record the result and tear the harness down**

Append a short "Post-implementation" section to `RESULTS.md` with the actual numbers printed in Step 3, then:

```bash
cd docs/superpowers/spikes/2026-07-22-search-pipelining && docker compose down -v --rmi local
pkill -f latency_proxy.py
```

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/spikes/2026-07-22-search-pipelining/
git commit -m "test(search): end-to-end pipelined search check through imapproxy"
```

---

## Task 7: Full suite, static check, and the handoff record

**Files:**
- Modify: `docs/superpowers/specs/2026-07-22-search-latency-design.md`
- Modify: `docs/superpowers/specs/2026-07-22-open-latency-HANDOFF.md` (the "What is shipped, and where" table)

- [ ] **Step 1: Run the whole test suite**

Run: `cd tests && ../vendor/bin/phpunit -c phpunit.xml`
Expected: PASS. No test that passed before this branch may fail now.

- [ ] **Step 2: Lint every touched file**

```bash
php -l program/lib/Roundcube/rcube_imap_generic.php
php -l program/lib/Roundcube/rcube_imap_search.php
php -l tests/Framework/ImapGenericPipelined.php
php -l tests/Framework/ImapSearchPipelined.php
```

Expected: `No syntax errors detected` four times.

- [ ] **Step 3: Update the design doc**

In `docs/superpowers/specs/2026-07-22-search-latency-design.md`, change the status header to record that candidate C is implemented, and add under candidate C the files that carry it:
`rcube_imap_generic::searchMulti()`, `rcube_imap_search::run_pipelined()`, kill switch `AVUZ_PIPELINED_SEARCH=0`, covered by `tests/Framework/ImapGenericPipelined.php` and `tests/Framework/ImapSearchPipelined.php`.

- [ ] **Step 4: Update the handoff table**

In `docs/superpowers/specs/2026-07-22-open-latency-HANDOFF.md`, replace the `Wave 2 (local search index)` row with a row recording that pipelined search is implemented, **not deployed**, with the measured numbers and the kill switch. State plainly that the Postgres index (candidate A) is deferred and why.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/specs/
git commit -m "docs: record pipelined search as implemented, index deferred"
```

---

## Deployment — explicitly out of scope for this plan

When it is authorised, separately:

1. Confirm Zoho's **authenticated** SELECT→SEARCH ordering. The spike proved it pre-auth only. Run `integration_test.php` against a real Zoho mailbox through the staging imapproxy. Binary gate.
2. Staging first. Measure `_action=search&_scope=all` p50/p95 in **5-minute buckets** (`PROD-ROLLBACK-ANCHORS.md` lesson 1: never compare whole-window averages; lesson 3: never read a bucket before it closes).
3. Compare result sets against a serial run on a real account. Identical, not similar.
4. Prod deploy by stack id: `PORTAINER_ENV_FILE=scripts/deploy.prod.env ./scripts/deploy.sh -y 36`. Record rollback digests first.
5. Only after that measurement: revisit making all-folder search the default.
