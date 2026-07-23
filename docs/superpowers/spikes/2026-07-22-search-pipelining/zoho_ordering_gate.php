<?php
/**
 * THE GATE: does Zoho keep SELECT and SEARCH ordered on a pipelined, authenticated connection?
 *
 * The spike proved pipelining works through up-imapproxy, and that Zoho pipelines
 * pre-auth. What it could not prove without a mailbox is the selected-state case
 * that RFC 3501 section 5.5 actually warns about. This closes that.
 *
 * Runs the real rcube_imap_search::exec() twice against a real account — once with
 * the pipelined path off, once on — and compares the two answers folder by folder.
 * Identical means Zoho ordered the batch correctly.
 *
 * READ-ONLY. It issues LIST, SELECT and UID SEARCH. It never writes, moves,
 * deletes, flags or fetches a message body.
 *
 * The password is read from stdin with terminal echo off. It is never taken from
 * argv (visible in `ps`), never from the environment, never logged, and never
 * written to disk. Nothing in this script persists it.
 *
 * Usage:
 *     php zoho_ordering_gate.php <user@domain> [search-term]
 *
 * Then type the password at the prompt. Default host is imap.zoho.com:993 over
 * TLS; override with IMAP_HOST / IMAP_PORT to aim at another endpoint (e.g. a
 * port-forwarded staging imapproxy, where IMAP_TLS=0).
 */

$RC = dirname(__DIR__, 4) . '/program/lib/Roundcube';
require_once "$RC/rcube_utils.php";
require_once "$RC/rcube_imap_generic.php";
require_once "$RC/rcube_result_index.php";
require_once "$RC/rcube_result_thread.php";
require_once "$RC/rcube_result_multifolder.php";
require_once "$RC/rcube_imap_search.php";

$user = $argv[1] ?? null;
$term = $argv[2] ?? 'nota';

if (!$user) {
    fwrite(STDERR, "usage: php zoho_ordering_gate.php <user@domain> [search-term]\n");
    exit(2);
}

$host = getenv('IMAP_HOST') ?: 'imap.zoho.com';
$port = (int) (getenv('IMAP_PORT') ?: 993);
$tls  = getenv('IMAP_TLS') === '0' ? null : 'ssl';

/**
 * Reads a secret from the terminal without echoing it.
 *
 * Falls back to a plain read only when stdin is not a terminal, so a piped
 * password still works in a pinch without silently echoing an interactive one.
 */
function read_password(string $prompt): string
{
    fwrite(STDERR, $prompt);

    $interactive = stream_isatty(STDIN);

    if ($interactive) {
        shell_exec('stty -echo');
    }

    $password = rtrim((string) fgets(STDIN), "\r\n");

    if ($interactive) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }

    return $password;
}

/**
 * @return array{0: float, 1: array<string, string>, 2: int} elapsed ms, UIDs per folder, folder count
 */
function run(string $host, int $port, ?string $tls, string $user, string $password, string $term, bool $pipelined): array
{
    putenv('AVUZ_PIPELINED_SEARCH=' . ($pipelined ? '1' : '0'));

    $imap = new rcube_imap_generic();
    $imap->connect($host, $user, $password, [
        'port'      => $port,
        'ssl_mode'  => $tls,
        'auth_type' => 'CHECK',
        'timeout'   => 180,
    ]);

    if (!$imap->connected()) {
        fwrite(STDERR, "connect/login failed: {$imap->error}\n");
        exit(3);
    }

    $folders = $imap->listMailboxes('', '*');

    if (!is_array($folders) || !$folders) {
        fwrite(STDERR, "LIST returned no folders: {$imap->error}\n");
        exit(3);
    }

    $searcher = new rcube_imap_search(['skip_deleted' => false], $imap);
    $searcher->set_timelimit(600);

    $started = microtime(true);
    $result  = $searcher->exec($folders, 'HEADER SUBJECT ' . rcube_imap_generic::escape($term, true));
    $elapsed = (microtime(true) - $started) * 1000;

    $sets = [];
    foreach ($folders as $folder) {
        $sets[$folder] = implode(',', $result->get_set($folder)->get());
    }

    $imap->closeConnection();

    return [$elapsed, $sets, count($folders)];
}

$password = read_password("IMAP password for {$user} (not echoed): ");

if ($password === '') {
    fwrite(STDERR, "no password given\n");
    exit(2);
}

fwrite(STDERR, "connecting to {$host}:{$port}" . ($tls ? ' (TLS)' : '') . ", searching subject \"{$term}\"\n");

list($serial_ms, $serial, $count)   = run($host, $port, $tls, $user, $password, $term, false);
list($pipelined_ms, $pipelined, $_) = run($host, $port, $tls, $user, $password, $term, true);

$password = null;

$matched = array_sum(array_map(static fn($uids) => $uids === '' ? 0 : substr_count($uids, ',') + 1, $serial));

printf("\nfolders   : %d\n", $count);
printf("matches   : %d\n", $matched);
printf("serial    : %8.1f ms\n", $serial_ms);
printf("pipelined : %8.1f ms\n", $pipelined_ms);
printf("speedup   : %.1fx\n", $serial_ms / max($pipelined_ms, 0.001));
printf("IDENTICAL : %s\n", $serial === $pipelined ? 'yes' : 'NO');

if ($serial !== $pipelined) {
    fwrite(STDERR, "\nGATE FAILED — {$host} did not order the pipelined batch. Do NOT deploy.\n");

    foreach ($serial as $folder => $uids) {
        if (($pipelined[$folder] ?? null) !== $uids) {
            fwrite(STDERR, sprintf("  %-40s serial=[%s] pipelined=[%s]\n", $folder, $uids, $pipelined[$folder] ?? '<missing>'));
        }
    }

    exit(1);
}

fwrite(STDERR, "\nGATE PASSED — {$host} keeps a pipelined batch in order on an authenticated connection.\n");
exit(0);
