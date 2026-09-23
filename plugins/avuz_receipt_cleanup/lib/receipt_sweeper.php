<?php
require_once __DIR__ . '/read_receipt.php';

/** The IMAP operations one sweep needs. Real impl: avuz_imap_receipt_mailbox. */
interface avuz_receipt_mailbox
{
    /** @return array{uidvalidity:int, uidnext:int} */
    public function status(string $folder): array;

    /** @return int[] ascending UIDs greater than $last_uid */
    public function uids_after(string $folder, int $last_uid): array;

    /** @return array<int,string> uid => mime type from the Content-Type header */
    public function content_types(string $folder, array $uids): array;

    /** @return array<int,array> uid => parsed BODYSTRUCTURE */
    public function structures(string $folder, array $uids): array;

    public function move(string $folder, array $uids, string $target): bool;
}

/**
 * One incremental pass over one folder: scan the next batch of UIDs after the
 * cursor, move the read receipts among them to Trash, advance the cursor.
 * A cursor of null (or a UIDVALIDITY change) scans the folder from the start,
 * so existing receipts are cleaned too, one batch per pass.
 */
class avuz_receipt_sweeper
{
    const BATCH_SIZE = 200;

    /**
     * @param array{uidvalidity:int, last_uid:int}|null $cursor
     * @return array{cursor: array{uidvalidity:int, last_uid:int}, moved: int, pending: bool}
     */
    public static function sweep(avuz_receipt_mailbox $mailbox, string $folder, string $trash, ?array $cursor, int $batch_size = self::BATCH_SIZE): array
    {
        $status   = $mailbox->status($folder);
        $last_uid = self::last_uid($cursor, $status['uidvalidity']);
        $unchanged = ['uidvalidity' => $status['uidvalidity'], 'last_uid' => $last_uid];

        if ($status['uidnext'] - 1 <= $last_uid) {
            return ['cursor' => $unchanged, 'moved' => 0, 'pending' => false];
        }

        $uids  = $mailbox->uids_after($folder, $last_uid);
        $batch = array_slice($uids, 0, $batch_size);
        if (!$batch) {
            return ['cursor' => $unchanged, 'moved' => 0, 'pending' => false];
        }

        $receipts = self::receipts($mailbox, $folder, $batch);
        if ($receipts && !$mailbox->move($folder, $receipts, $trash)) {
            return ['cursor' => $unchanged, 'moved' => 0, 'pending' => false];
        }

        return [
            'cursor'  => ['uidvalidity' => $status['uidvalidity'], 'last_uid' => max($batch)],
            'moved'   => count($receipts),
            'pending' => count($uids) > count($batch),
        ];
    }

    private static function last_uid(?array $cursor, int $uidvalidity): int
    {
        if ($cursor === null || (int) ($cursor['uidvalidity'] ?? 0) !== $uidvalidity) {
            return 0;
        }

        return (int) ($cursor['last_uid'] ?? 0);
    }

    /** @return int[] */
    private static function receipts(avuz_receipt_mailbox $mailbox, string $folder, array $uids): array
    {
        $candidates = array_keys(array_filter(
            $mailbox->content_types($folder, $uids),
            [avuz_read_receipt::class, 'is_candidate']
        ));
        if (!$candidates) {
            return [];
        }

        $structures = $mailbox->structures($folder, $candidates);
        $receipts   = array_keys(array_filter($structures, [avuz_read_receipt::class, 'is_read_receipt']));
        sort($receipts);

        return $receipts;
    }
}
