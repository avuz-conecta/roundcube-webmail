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

    /** @return resource */
    public function socket()
    {
        return $this->fp;
    }

    public function expose_searchParams(string $criteria, array $items = []): string
    {
        return $this->searchParams($criteria, $items);
    }
}
