<?php
/**
 * SPIKE — pipelined SELECT + UID SEARCH on one rcube_imap_generic connection.
 * Read-only. Runs against the local dovecot+up-imapproxy pair (optionally behind
 * a 198ms latency shim). Nothing in the repository is modified: the pipelined
 * path is a subclass, using only rcube_imap_generic's existing protected I/O.
 */
$RC = '/Users/patrickrezende/work/avuz/roundcube-webmail/program/lib/Roundcube';
require_once "$RC/rcube_utils.php";
require_once "$RC/rcube_imap_generic.php";
require_once "$RC/rcube_result_index.php";

class pipelined_imap extends rcube_imap_generic
{
    /**
     * Writes every (SELECT, UID SEARCH) pair before reading any reply, then
     * consumes the tagged replies in the order the commands were sent.
     *
     * @return array{0: array<string, rcube_result_index>, 1: array<string>} results by folder, tag order seen
     */
    public function searchPipelined(array $folders, string $criteria): array
    {
        $tags = [];
        foreach ($folders as $folder) {
            $select = $this->nextTag();
            $this->putLineC($select . ' SELECT ' . $this->escape($folder));
            $search = $this->nextTag();
            $this->putLineC($search . ' UID SEARCH RETURN (ALL) ' . $criteria);
            $tags[] = [$folder, $select, $search];
        }

        $results = [];
        $seen    = [];
        foreach ($tags as [$folder, $select, $search]) {
            $this->readTagged($select, $seen);
            $results[$folder] = new rcube_result_index($folder, $this->readTagged($search, $seen));
        }

        return [$results, $seen];
    }

    /** Reads lines until $tag's tagged reply; appends the tag actually seen to $seen. */
    private function readTagged(string $tag, array &$seen): string
    {
        $response = '';
        do {
            $line = $this->readFullLine(4096);
            if ($line === false) {
                throw new RuntimeException("connection closed while waiting for $tag");
            }
            $response .= $line;
        } while (!$this->startsWith($line, $tag . ' ', true, true));

        $seen[] = explode(' ', trim($line))[0];

        if ($this->parseResult($line, '') != self::ERROR_OK) {
            throw new RuntimeException("$tag failed: " . trim($line));
        }

        return rtrim(substr($response, 0, -strlen($line)), "\r\n");
    }

    public function searchSerial(array $folders, string $criteria): array
    {
        $results = [];
        foreach ($folders as $folder) {
            $results[$folder] = $this->search($folder, $criteria, true);
        }
        return $results;
    }
}

function connect(int $port): pipelined_imap
{
    $imap = new pipelined_imap();
    $ok = $imap->connect('127.0.0.1', 'spike@example.com', 'spikepass',
        ['port' => $port, 'ssl_mode' => null, 'auth_type' => 'LOGIN', 'timeout' => 20]);
    if (!$ok) {
        fwrite(STDERR, "connect failed: " . $imap->error . "\n");
        exit(1);
    }
    return $imap;
}

$port     = (int) ($argv[1] ?? 1143);
$folders  = ['INBOX', 'Sent', 'Archive', 'Junk', 'Trash'];
$criteria = 'HEADER SUBJECT "needle-hit"';

$imap = connect($port);
printf("capabilities: %s\n", implode(' ', array_slice($imap->capability ?? [], 0, 40)));

$t0 = microtime(true);
$serial = $imap->searchSerial($folders, $criteria);
$serial_ms = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
[$piped, $order] = $imap->searchPipelined($folders, $criteria);
$piped_ms = (microtime(true) - $t0) * 1000;

$flat = static fn(array $r) => array_map(static fn($x) => implode(',', $x->get()), $r);
$a = $flat($serial);
$b = $flat($piped);

printf("serial    : %8.1f ms  %s\n", $serial_ms, json_encode($a));
printf("pipelined : %8.1f ms  %s\n", $piped_ms, json_encode($b));
printf("tag order : %s\n", implode(' ', $order));
printf("IDENTICAL : %s\n", $a === $b ? 'yes' : 'NO');
printf("speedup   : %.1fx\n", $serial_ms / max($piped_ms, 0.001));
$imap->closeConnection();
