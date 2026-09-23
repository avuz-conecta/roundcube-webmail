<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/imap_receipt_mailbox.php';

/**
 * rcube_imap_generic reading a scripted server over a socket pair. Responses
 * are queued up front; tags follow the client's A0001, A0002, … sequence.
 */
class scripted_imap_connection extends rcube_imap_generic
{
    public $server;

    function __construct(array $responses)
    {
        [$client, $this->server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($this->server, implode("\r\n", $responses) . "\r\n");
        $this->fp              = $client;
        $this->prefs           = ['timeout' => 5];
        $this->logged          = true;
        $this->capability_read = true;
        $this->capability      = ['IMAP4REV1', 'ESEARCH', 'MOVE', 'UIDPLUS'];
    }

    function sent_commands(): array
    {
        stream_set_blocking($this->server, false);
        return array_values(array_filter(explode("\r\n", (string) stream_get_contents($this->server))));
    }
}

class imap_receipt_mailbox_test extends TestCase
{
    private function mailbox(array $responses): array
    {
        $storage       = new rcube_imap();
        $storage->conn = new scripted_imap_connection($responses);
        return [new avuz_imap_receipt_mailbox($storage), $storage->conn];
    }

    private function selected_sent(int $tag): array
    {
        return [
            '* 7 EXISTS',
            '* OK [UIDVALIDITY 1650000000] UIDs valid',
            '* OK [UIDNEXT 31] Predicted next UID',
            sprintf('A%04d OK [READ-WRITE] SELECT completed', $tag),
        ];
    }

    function testReadsUidValidityAndUidNextFromStatus()
    {
        [$mailbox] = $this->mailbox([
            '* STATUS "Enviadas" (MESSAGES 7 UNSEEN 0 UIDNEXT 31 UIDVALIDITY 1650000000)',
            'A0001 OK STATUS completed',
        ]);

        $this->assertSame(['uidvalidity' => 1650000000, 'uidnext' => 31], $mailbox->status('Enviadas'));
    }

    function testListsUidsAfterTheCursorFromEsearch()
    {
        [$mailbox, $connection] = $this->mailbox(array_merge($this->selected_sent(1), [
            '* ESEARCH (TAG "A0002") UID ALL 21:23,30',
            'A0002 OK SEARCH completed',
        ]));

        $this->assertSame([21, 22, 23, 30], $mailbox->uids_after('Enviadas', 20));
        $this->assertStringContainsString('UID SEARCH RETURN (ALL) UID 21:*', $connection->sent_commands()[1]);
    }

    function testDropsTheNewestMessageThatStarRangeAlwaysMatches()
    {
        [$mailbox] = $this->mailbox(array_merge($this->selected_sent(1), [
            '* ESEARCH (TAG "A0002") UID ALL 30',
            'A0002 OK SEARCH completed',
        ]));

        $this->assertSame([], $mailbox->uids_after('Enviadas', 30));
    }

    function testReadsMimeTypesFromContentTypeHeaders()
    {
        $receipt_header = "Content-Type: multipart/report; report-type=disposition-notification;\r\n boundary=\"=_abc\"\r\n\r\n";
        $plain_header   = "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        [$mailbox] = $this->mailbox(array_merge($this->selected_sent(1), [
            '* 1 FETCH (UID 21 BODY[HEADER.FIELDS (CONTENT-TYPE)] {' . strlen($receipt_header) . "}\r\n" . $receipt_header . ')',
            '* 2 FETCH (UID 22 BODY[HEADER.FIELDS (CONTENT-TYPE)] {' . strlen($plain_header) . "}\r\n" . $plain_header . ')',
            "* 3 FETCH (UID 23 BODY[HEADER.FIELDS (CONTENT-TYPE)] {2}\r\n\r\n)",
            'A0002 OK FETCH completed',
        ]));

        $this->assertSame(
            [21 => 'multipart/report', 22 => 'text/plain', 23 => 'text/plain'],
            $mailbox->content_types('Enviadas', [21, 22, 23])
        );
    }

    function testRecognizesAReadReceiptFromItsBodystructure()
    {
        [$mailbox] = $this->mailbox(array_merge($this->selected_sent(1), [
            '* 1 FETCH (UID 21 BODYSTRUCTURE (("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 312 9 NIL NIL NIL)'
                . '("MESSAGE" "DISPOSITION-NOTIFICATION" ("NAME" "MDNPart2.txt") NIL NIL "7BIT" 180 NIL ("INLINE" ("FILENAME" "MDNPart2.txt")) NIL)'
                . ' "REPORT" ("REPORT-TYPE" "disposition-notification" "BOUNDARY" "=_abc") NIL NIL NIL))',
            'A0002 OK FETCH completed',
        ]));

        $structures = $mailbox->structures('Enviadas', [21]);

        $this->assertTrue(avuz_read_receipt::is_read_receipt($structures[21]));
    }
}
