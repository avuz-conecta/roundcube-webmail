<?php
// Asserts a bad folder in the set no longer collapses the whole pipelined run.
$RC = dirname(__DIR__, 4) . '/program/lib/Roundcube';
foreach (['rcube_utils','rcube_imap_generic','rcube_result_index','rcube_result_thread','rcube_result_multifolder','rcube_imap_search'] as $c) require_once "$RC/$c.php";

// good folders + one that SELECT will reject (does not exist)
$folders = ['INBOX', 'F001', 'DoesNotExist_ZZZ', 'F002', 'F003'];

function run(array $folders, bool $pipelined): array {
    putenv('AVUZ_PIPELINED_SEARCH=' . ($pipelined ? '1' : '0'));
    $imap = new rcube_imap_generic();
    $imap->connect('127.0.0.1','spike@example.com','spikepass',['port'=>1143,'ssl_mode'=>null,'auth_type'=>'LOGIN','timeout'=>60]);
    if (!$imap->connected()) { fwrite(STDERR,"connect failed\n"); exit(2); }
    $s = new rcube_imap_search(['skip_deleted'=>false], $imap);
    $s->set_timelimit(60);
    $r = $s->exec($folders, 'HEADER SUBJECT "needle-hit"');
    $out = [];
    foreach ($folders as $f) $out[$f] = implode(',', $r->get_set($f)->get());
    // is the connection still usable AFTER the run? (pool-clean proxy check)
    $alive = $imap->connected();
    $sel = $imap->select('INBOX'); // must work if stream isn't desynced
    $imap->closeConnection();
    return [$out, $alive, $sel !== false];
}

list($serial, , )              = run($folders, false);
list($pipe, $alive, $reusable) = run($folders, true);

echo "serial good-folder hits : INBOX=".($serial['INBOX']?:'-').", F001=".($serial['F001']?:'-').", F002=".($serial['F002']?:'-')."\n";
echo "pipe   good-folder hits : INBOX=".($pipe['INBOX']?:'-').", F001=".($pipe['F001']?:'-').", F002=".($pipe['F002']?:'-')."\n";
echo "bad folder handled      : DoesNotExist -> '".($pipe['DoesNotExist_ZZZ']?:'(empty)')."'\n";
echo "connection reusable after: alive=".($alive?'y':'n').", re-SELECT ok=".($reusable?'y':'n')."\n";
$ok = ($serial===$pipe) && $reusable;
echo "IDENTICAL to serial + pool clean: ".($ok?'PASS':'FAIL')."\n";
exit($ok?0:1);
