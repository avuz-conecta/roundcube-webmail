<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/receipt_sweeper.php';

/**
 * In-memory mailbox: folder => uid => bodystructure. Receipts carry a
 * multipart/report + disposition-notification structure, everything else is
 * plain text.
 */
class fake_receipt_mailbox implements avuz_receipt_mailbox
{
    public $folders = [];
    public $uidvalidity = [];
    public $moves = [];
    public $fail_moves = false;
    public $structure_fetches = [];

    function add(string $folder, int $uid, array $structure): void
    {
        $this->folders[$folder][$uid] = $structure;
        $this->uidvalidity[$folder] = $this->uidvalidity[$folder] ?? 1;
    }

    function status(string $folder): array
    {
        $uids = array_keys($this->folders[$folder] ?? []);
        return [
            'uidvalidity' => $this->uidvalidity[$folder] ?? 1,
            'uidnext'     => ($uids ? max($uids) : 0) + 1,
        ];
    }

    function uids_after(string $folder, int $last_uid): array
    {
        $uids = array_filter(array_keys($this->folders[$folder] ?? []), function ($uid) use ($last_uid) {
            return $uid > $last_uid;
        });
        sort($uids);
        return array_values($uids);
    }

    function content_types(string $folder, array $uids): array
    {
        $types = [];
        foreach ($uids as $uid) {
            $structure = $this->folders[$folder][$uid];
            $types[$uid] = is_array($structure[0]) ? 'multipart/' . strtolower($structure[2]) : 'text/plain';
        }
        return $types;
    }

    function structures(string $folder, array $uids): array
    {
        $this->structure_fetches[] = $uids;
        return array_intersect_key($this->folders[$folder], array_flip($uids));
    }

    function move(string $folder, array $uids, string $target): bool
    {
        if ($this->fail_moves) {
            return false;
        }
        foreach ($uids as $uid) {
            $this->folders[$target][] = $this->folders[$folder][$uid];
            unset($this->folders[$folder][$uid]);
        }
        $this->moves[] = [$folder, $uids, $target];
        return true;
    }
}

class receipt_sweeper_test extends TestCase
{
    const SENT  = 'Enviadas';
    const TRASH = 'Lixeira';

    private $mailbox;

    protected function setUp(): void
    {
        $this->mailbox = new fake_receipt_mailbox();
    }

    private function receipt(): array
    {
        return [
            ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', '10', '1'],
            ['MESSAGE', 'DISPOSITION-NOTIFICATION', null, null, null, '7BIT', '10'],
            'REPORT', ['REPORT-TYPE', 'disposition-notification', 'BOUNDARY', 'x'], null, null, null,
        ];
    }

    private function bounce(): array
    {
        return [
            ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', '10', '1'],
            ['MESSAGE', 'DELIVERY-STATUS', null, null, null, '7BIT', '10'],
            'REPORT', ['REPORT-TYPE', 'delivery-status', 'BOUNDARY', 'x'], null, null, null,
        ];
    }

    private function ordinary_mail(): array
    {
        return ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', '10', '1'];
    }

    private function sweep(?array $cursor = null, int $batch_size = 200): array
    {
        return avuz_receipt_sweeper::sweep($this->mailbox, self::SENT, self::TRASH, $cursor, $batch_size);
    }

    function testMovesReadReceiptsToTrash()
    {
        $this->mailbox->add(self::SENT, 1, $this->ordinary_mail());
        $this->mailbox->add(self::SENT, 2, $this->receipt());
        $this->mailbox->add(self::SENT, 3, $this->receipt());

        $result = $this->sweep();

        $this->assertSame(2, $result['moved']);
        $this->assertSame([[self::SENT, [2, 3], self::TRASH]], $this->mailbox->moves);
        $this->assertCount(2, $this->mailbox->folders[self::TRASH]);
    }

    function testLeavesOtherMailInPlace()
    {
        $this->mailbox->add(self::SENT, 1, $this->ordinary_mail());
        $this->mailbox->add(self::SENT, 2, $this->bounce());
        $this->mailbox->add(self::SENT, 3, $this->receipt());

        $this->sweep();

        $this->assertSame([1, 2], array_keys($this->mailbox->folders[self::SENT]));
    }

    function testFetchesStructureOnlyForReportCandidates()
    {
        $this->mailbox->add(self::SENT, 1, $this->ordinary_mail());
        $this->mailbox->add(self::SENT, 2, $this->receipt());

        $this->sweep();

        $this->assertSame([[2]], $this->mailbox->structure_fetches);
    }

    function testAdvancesCursorToHighestScannedUid()
    {
        $this->mailbox->add(self::SENT, 4, $this->ordinary_mail());
        $this->mailbox->add(self::SENT, 9, $this->receipt());

        $result = $this->sweep();

        $this->assertSame(['uidvalidity' => 1, 'last_uid' => 9], $result['cursor']);
        $this->assertFalse($result['pending']);
    }

    function testScansOnlyMailAfterTheCursor()
    {
        $this->mailbox->add(self::SENT, 1, $this->receipt());
        $this->mailbox->add(self::SENT, 2, $this->receipt());

        $result = $this->sweep(['uidvalidity' => 1, 'last_uid' => 1]);

        $this->assertSame([[self::SENT, [2], self::TRASH]], $this->mailbox->moves);
        $this->assertSame(1, $result['moved']);
    }

    function testSkipsImapWhenCaughtUp()
    {
        $this->mailbox->add(self::SENT, 5, $this->receipt());

        $result = $this->sweep(['uidvalidity' => 1, 'last_uid' => 5]);

        $this->assertSame(0, $result['moved']);
        $this->assertSame([], $this->mailbox->structure_fetches);
        $this->assertSame(['uidvalidity' => 1, 'last_uid' => 5], $result['cursor']);
    }

    function testRescansFromStartWhenUidValidityChanges()
    {
        $this->mailbox->add(self::SENT, 1, $this->receipt());
        $this->mailbox->uidvalidity[self::SENT] = 2;

        $result = $this->sweep(['uidvalidity' => 1, 'last_uid' => 50]);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(['uidvalidity' => 2, 'last_uid' => 1], $result['cursor']);
    }

    function testWorksThroughBacklogInBatches()
    {
        foreach (range(1, 5) as $uid) {
            $this->mailbox->add(self::SENT, $uid, $this->receipt());
        }

        $first = $this->sweep(null, 2);
        $this->assertSame(2, $first['moved']);
        $this->assertTrue($first['pending']);
        $this->assertSame(2, $first['cursor']['last_uid']);

        $second = $this->sweep($first['cursor'], 2);
        $third  = $this->sweep($second['cursor'], 2);

        $this->assertFalse($third['pending']);
        $this->assertSame([], $this->mailbox->folders[self::SENT]);
    }

    function testKeepsCursorWhenMoveFailsSoNextPassRetries()
    {
        $this->mailbox->add(self::SENT, 3, $this->receipt());
        $this->mailbox->fail_moves = true;

        $result = $this->sweep(['uidvalidity' => 1, 'last_uid' => 2]);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(['uidvalidity' => 1, 'last_uid' => 2], $result['cursor']);
        $this->assertArrayHasKey(3, $this->mailbox->folders[self::SENT]);
    }
}
