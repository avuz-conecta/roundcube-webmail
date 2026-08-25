<?php
$RC = dirname(__DIR__, 4) . '/program/lib/Roundcube';
foreach (['rcube_utils','rcube_imap_generic','rcube_result_index','rcube_result_thread','rcube_result_multifolder','rcube_imap_search'] as $c) require_once "$RC/$c.php";
putenv('AVUZ_PIPELINED_SEARCH=1');
$folders = array_merge(['INBOX'], array_map(fn($i)=>sprintf('F%03d',$i), range(1,20)));
$imap = new rcube_imap_generic();
$imap->connect('127.0.0.1','spike@example.com','spikepass',['port'=>1143,'ssl_mode'=>null,'auth_type'=>'LOGIN','timeout'=>60]);
$s = new rcube_imap_search(['skip_deleted'=>false], $imap);
$s->set_timelimit(60);
// accented (non-ASCII) term -> escape() emits an IMAP literal {N}\r\n<bytes>
$r = $s->exec($folders, 'HEADER SUBJECT "reunião"');
$hits = 0; foreach ($folders as $f) $hits += count($r->get_set($f)->get());
$reusable = $imap->select('INBOX') !== false;   // stream still aligned?
echo "accented search completed. hits=$hits (expected 0, ASCII corpus)\n";
echo "connection reusable after accented batch: ".($reusable?'y':'n')."\n";
echo "ACCENT PATH SAFE (no desync): ".($reusable?'PASS':'FAIL')."\n";
$imap->closeConnection();
exit($reusable?0:1);
