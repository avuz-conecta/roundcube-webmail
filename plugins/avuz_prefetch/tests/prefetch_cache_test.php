<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/prefetch_cache.php';

/** Minimal stand-in for rcube_cache: get/set only. */
class fake_cache
{
    public $data = [];
    public $writes = 0;
    function get($key) { return $this->data[$key] ?? null; }
    function set($key, $value) { $this->data[$key] = $value; $this->writes++; }
}

class prefetch_cache_test extends TestCase
{
    function testBodyKeyCombinesFolderUidAndMimeId() {
        $this->assertSame('INBOX:941:1.1', avuz_prefetch_cache::body_key('INBOX', 941, '1.1'));
    }

    function testDoneKeyIsDistinctFromAnyBodyKey() {
        $done = avuz_prefetch_cache::done_key('INBOX', 941);
        $this->assertNotSame(avuz_prefetch_cache::body_key('INBOX', 941, '1.1'), $done);
        $this->assertNotSame(avuz_prefetch_cache::body_key('INBOX', 941, ''), $done);
    }

    function testReportsColdWhenNothingCached() {
        $this->assertFalse(avuz_prefetch_cache::is_warm(new fake_cache(), 'INBOX', 941));
    }

    function testReportsWarmAfterMarking() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941);
        $this->assertTrue(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testWarmthIsScopedPerFolder() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'Enviadas', 941));
    }

    function testWarmthIsScopedPerUid() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 942));
    }

    function testTreatsMissingCacheAsCold() {
        $this->assertFalse(avuz_prefetch_cache::is_warm(false, 'INBOX', 941));
    }

    function testMarkingWithoutCacheDoesNotError() {
        avuz_prefetch_cache::mark_warm(false, 'INBOX', 941);
        $this->assertTrue(true);
    }
}
