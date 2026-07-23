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
$folders = array_merge(['INBOX'], array_map(static fn($i) => sprintf('F%03d', $i), range(1, 106)));
$options = ['skip_deleted' => false];

function run(int $port, array $folders, array $options, bool $pipelined): array
{
    putenv('AVUZ_PIPELINED_SEARCH=' . ($pipelined ? '1' : '0'));

    $imap = new rcube_imap_generic();
    $imap->connect('127.0.0.1', 'spike@example.com', 'spikepass',
        ['port' => $port, 'ssl_mode' => null, 'auth_type' => 'LOGIN', 'timeout' => 120]);

    if (!$imap->connected()) {
        fwrite(STDERR, "connect failed: {$imap->error}\n");
        exit(2);
    }

    $searcher = new rcube_imap_search($options, $imap);
    $searcher->set_timelimit(120);

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
