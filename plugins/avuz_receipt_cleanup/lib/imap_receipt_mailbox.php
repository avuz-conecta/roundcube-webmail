<?php
require_once __DIR__ . '/receipt_sweeper.php';

/** avuz_receipt_mailbox over the user's live rcube_imap session. */
class avuz_imap_receipt_mailbox implements avuz_receipt_mailbox
{
    const CONTENT_TYPE_FETCH = 'BODY.PEEK[HEADER.FIELDS (CONTENT-TYPE)]';
    const MIME_DEFAULT       = 'text/plain';

    private $storage;

    public function __construct(rcube_imap $storage)
    {
        $this->storage = $storage;
    }

    public function status(string $folder): array
    {
        $status = $this->storage->conn->status($folder, ['UIDNEXT', 'UIDVALIDITY']);
        if (!is_array($status) || !isset($status['UIDNEXT'], $status['UIDVALIDITY'])) {
            throw new RuntimeException("STATUS failed for folder $folder");
        }

        return ['uidvalidity' => (int) $status['UIDVALIDITY'], 'uidnext' => (int) $status['UIDNEXT']];
    }

    public function uids_after(string $folder, int $last_uid): array
    {
        $index = $this->storage->conn->search($folder, 'UID ' . ($last_uid + 1) . ':*', true);
        if ($index->is_error()) {
            throw new RuntimeException("UID SEARCH failed for folder $folder");
        }

        // "UID n:*" always matches the newest message, even when its UID < n.
        $uids = array_filter(array_map('intval', $index->get()), function ($uid) use ($last_uid) {
            return $uid > $last_uid;
        });
        sort($uids, SORT_NUMERIC);

        return array_values($uids);
    }

    public function content_types(string $folder, array $uids): array
    {
        $types = [];
        foreach ($this->fetch($folder, $uids, self::CONTENT_TYPE_FETCH) as $message) {
            $types[(int) $message->uid] = $message->ctype ?: self::MIME_DEFAULT;
        }

        return $types;
    }

    public function structures(string $folder, array $uids): array
    {
        $structures = [];
        foreach ($this->fetch($folder, $uids, 'BODYSTRUCTURE') as $message) {
            $structures[(int) $message->uid] = is_array($message->bodystructure ?? null) ? $message->bodystructure : [];
        }

        return $structures;
    }

    public function move(string $folder, array $uids, string $target): bool
    {
        return (bool) $this->storage->move_message($uids, $target, $folder);
    }

    /** @return rcube_message_header[] */
    private function fetch(string $folder, array $uids, string $item): array
    {
        $messages = $this->storage->conn->fetch($folder, $uids, true, ['UID', $item]);
        if ($messages === false) {
            throw new RuntimeException("UID FETCH $item failed for folder $folder");
        }

        return $messages;
    }
}
