<?php
$RC = dirname(__DIR__, 4) . '/program/lib/Roundcube';
foreach (['rcube_utils','rcube_imap_generic','rcube_result_index'] as $c) require_once "$RC/$c.php";

// Subclass to observe whether the post-login drain fired.
class TestImap extends rcube_imap_generic {
    public $resynced = false;
    public $hadBuffered = false;
    protected function hasBufferedData() { $r = parent::hasBufferedData(); if ($r) $this->hadBuffered = true; return $r; }
    protected function resyncConnection() { $this->resynced = true; return parent::resyncConnection(); }
}

function poisonPooledConn() {
    // raw: login, fire a multi-folder batch, read only the first reply, close abruptly.
    $s = fsockopen('127.0.0.1', 1143, $e, $s2, 5);
    fgets($s); // greeting
    fwrite($s, "l1 LOGIN spike@example.com spikepass\r\n"); fgets($s);
    $batch = '';
    for ($i=1;$i<=6;$i++) $batch .= "s$i SELECT F00$i\r\nq$i UID SEARCH HEADER SUBJECT needle-hit\r\n";
    fwrite($s, $batch);
    usleep(300000);
    fgets($s);            // read only ONE line, leave the rest buffered upstream
    fclose($s);           // abrupt close, no LOGOUT -> imapproxy pools it dirty
}

$clean_runs = 0; $resync_runs = 0;
for ($attempt=1; $attempt<=6; $attempt++) {
    poisonPooledConn();
    usleep(200000);
    $imap = new TestImap();
    $imap->connect('127.0.0.1','spike@example.com','spikepass',['port'=>1143,'ssl_mode'=>null,'auth_type'=>'LOGIN','timeout'=>20]);
    if (!$imap->connected()) { echo "attempt $attempt: connect FAILED\n"; continue; }
    // A clean connection returns a correct EXISTS for INBOX.
    $imap->select('INBOX');
    $exists = $imap->data['EXISTS'] ?? null;
    $ok = is_int($exists);
    if ($imap->resynced) $resync_runs++;
    if ($ok) $clean_runs++;
    echo "attempt $attempt: buffered=".($imap->hadBuffered?'Y':'n')." resynced=".($imap->resynced?'Y':'n')." SELECT_INBOX_exists=".var_export($exists,true)." -> ".($ok?'CLEAN':'DIRTY')."\n";
    $imap->closeConnection();
}
echo "\n>>> clean=$clean_runs/6  resync_fired=$resync_runs\n";
