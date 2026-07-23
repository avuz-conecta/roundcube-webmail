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
    /** @var resource[] socket ends opened by a test, closed in tearDown */
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

    function test_searchMulti_handles_many_folders_in_one_batch()
    {
        $folders = [];
        for ($i = 0; $i < 30; $i++) {
            $folders["F$i"] = '1';
        }

        list($imap, $server) = $this->connection($this->replies($folders));

        $results = $imap->searchMulti(array_keys($folders), 'HEADER SUBJECT "x"', true);

        $this->assertCount(30, $results);
        $this->assertSame(['1'], $results['F29']->get());
    }

    /**
     * Builds the canned server side of a successful N-folder pipelined search.
     *
     * Keep N small. These replies are written into the socket before the call,
     * with nothing draining the other end, so they must fit the socket buffer —
     * only ~8kB for a unix socket pair on macOS. Multi-batch runs are covered by
     * test_searchMulti_chunks_batches_so_replies_cannot_deadlock, which needs no
     * replies at all, and end-to-end by the harness in
     * docs/superpowers/spikes/2026-07-22-search-pipelining/.
     */
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
        $this->assertSame(['1', '4'], $results['INBOX']->get());
        $this->assertSame(['7'], $results['Sent']->get());
        $this->assertSame(['2', '3', '4', '5'], $results['Archive']->get());
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
        // Three batches' worth of folders, no replies waiting, client socket
        // non-blocking: the first read fails. A pipelined-but-unbatched
        // implementation would already have written all 90 SELECTs. A batched
        // one has written exactly one batch, which is the property that keeps
        // a batch's replies inside the socket buffers.
        $folders = array_map(
            static fn($i) => "F$i",
            range(1, rcube_imap_generic::SEARCH_PIPELINE_CHUNK * 3)
        );

        list($imap, $server) = $this->connection('');
        stream_set_blocking($imap->socket(), false);

        $this->assertFalse($imap->searchMulti($folders, 'HEADER SUBJECT "x"', true));

        $this->assertSame(
            rcube_imap_generic::SEARCH_PIPELINE_CHUNK,
            substr_count($this->sent($server), ' SELECT ')
        );
    }

    function test_searchMulti_refuses_an_empty_folder_list()
    {
        list($imap, $server) = $this->connection('');

        $this->assertFalse($imap->searchMulti([], 'HEADER SUBJECT "x"', true));
    }

    /**
     * Reproduces the accented-search-term production bug: an IMAP literal (any
     * non-ASCII search term, e.g. Portuguese "reunião") only becomes a
     * non-synchronizing {n+} literal — safe to pipeline — when prefs['literal+']
     * or prefs['literal-'] is already known. A connection whose capabilities are
     * known but incomplete (e.g. IMAP4REV1/ESEARCH seen, but no CAPABILITY
     * command has actually run yet — the state of a connection reused from
     * imapproxy, which is greeted with "* OK [XPROXYREUSE] ..." and never runs
     * the normal post-connect CAPABILITY exchange) must still resolve
     * LITERAL- before the first literal goes out, or putLineC() blocks reading
     * a '+' continuation that a pipelined batch's next command's reply gets
     * mistaken for, desynchronising every tag after it.
     */
    function test_searchMulti_reads_capability_before_pipelining_a_literal_on_an_incompletely_known_connection()
    {
        $literal = rcube_imap_generic::escape('reunião');
        $criteria = 'HEADER SUBJECT ' . $literal . ' HEADER FROM ' . $literal;

        $replies = "* CAPABILITY IMAP4REV1 ESEARCH LITERAL- UIDPLUS\r\n"
            . "A0001 OK CAPABILITY completed\r\n"
            . "* 3 EXISTS\r\n"
            . "A0002 OK [READ-WRITE] Select completed\r\n"
            . "* ESEARCH (TAG \"\") UID ALL 5\r\n"
            . "A0003 OK Search completed\r\n";

        list($imap, $server) = $this->connection($replies);
        // ESEARCH is already known — the caller must not be able to coast on
        // that check to also resolve LITERAL-; capability_read stays false,
        // exactly like a connection whose capabilities were only ever seen
        // partially, never through a real CAPABILITY command.
        $imap->attach_reused($imap->socket(), ['IMAP4REV1', 'ESEARCH']);

        $results = $imap->searchMulti(['INBOX'], $criteria, true);

        $this->assertIsArray($results, 'a known LITERAL- must keep the search pipelined, not fall back to serial');
        $this->assertSame(['5'], $results['INBOX']->get());

        $sent = $this->sent($server);
        $this->assertSame(1, substr_count($sent, ' CAPABILITY'), 'capability must be resolved exactly once');
        $this->assertStringContainsString("{" . strlen('reunião') . "+}\r\n", $sent,
            'the literal must be non-synchronizing once LITERAL- is known, or it would block on a "+" that never arrives');
        $this->assertStringNotContainsString("{" . strlen('reunião') . "}\r\n", $sent,
            'must not fall back to a synchronizing literal when LITERAL- is known');
    }

    /**
     * Safety net for the case above: if the capability truly cannot be
     * resolved (or resolves without LITERAL-/LITERAL+), a literal cannot be
     * trusted to pipeline — putLineC() would block on a '+' continuation
     * that, in a pipelined batch, desynchronises every tag after it. Declining
     * to the serial path is silent and correct; blocking is not.
     */
    function test_searchMulti_declines_a_literal_when_capability_resolves_without_a_literal_extension()
    {
        $literal  = rcube_imap_generic::escape('reunião');
        $criteria = 'HEADER SUBJECT ' . $literal;

        $replies = "* CAPABILITY IMAP4REV1 ESEARCH\r\n"
            . "A0001 OK CAPABILITY completed\r\n";

        list($imap, $server) = $this->connection($replies);
        $imap->attach_reused($imap->socket(), ['IMAP4REV1', 'ESEARCH']);

        $results = $imap->searchMulti(['INBOX'], $criteria, true);

        $this->assertFalse($results, 'without a known literal extension, pipelining a literal cannot be trusted');

        $sent = $this->sent($server);
        $this->assertStringNotContainsString(' SELECT ', $sent, 'must decline before writing anything for the batch');
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
        $this->fp               = $fp;
        $this->logged           = true;
        $this->prefs['timeout'] = 5;
        $this->capability       = ['IMAP4REV1', 'ESEARCH', 'LITERAL-'];
        $this->capability_read  = true;
    }

    /**
     * Attaches like attach(), but leaves capability_read false with whatever
     * capabilities are handed in — the state of a connection that has only
     * ever seen capabilities in passing (e.g. reused from imapproxy), never
     * through an actual CAPABILITY command.
     *
     * @param resource $fp
     */
    public function attach_reused($fp, array $capability = []): void
    {
        $this->fp               = $fp;
        $this->logged           = true;
        $this->prefs['timeout'] = 5;
        $this->capability       = $capability;
        $this->capability_read  = false;
    }

    /** @return resource */
    public function socket()
    {
        return $this->fp;
    }

    public function expose_searchParams(string $criteria, array $items = []): string
    {
        return $this->searchParams($criteria, $items);
    }

    public function expose_readPipelined(string $tag)
    {
        return $this->readPipelined($tag);
    }
}
