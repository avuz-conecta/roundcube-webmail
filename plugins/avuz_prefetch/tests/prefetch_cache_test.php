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

    function testReportsWarmWhenSentinelAndBodiesArePresent() {
        $cache = new fake_cache();
        $cache->set(avuz_prefetch_cache::body_key('INBOX', 941, '1.1'), '<p>hi</p>');
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, ['1.1']);
        $this->assertTrue(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testNotWarmWhenSentinelExistsButBodyWasEvicted() {
        $cache = new fake_cache();
        // Simulate LRU eviction: sentinel written, but the body key it points
        // at was reclaimed (never set, or removed) before the check.
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, ['1.1']);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testProbesLastMimeIdNotFirst() {
        // multipart/alternative: mime_parts (and so the sentinel list) puts
        // text/plain ('1.1') before text/html ('1.2'). Only the html body is
        // still cached; probing the first entry would wrongly report cold.
        $cache = new fake_cache();
        $cache->set(avuz_prefetch_cache::body_key('INBOX', 941, '1.2'), '<p>hi</p>');
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, ['1.1', '1.2']);
        $this->assertTrue(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testNotWarmWhenLastMimeIdBodyWasEvicted() {
        // Inverse: the first (plain) body survived but the last (html) body,
        // the one that matters, was evicted — this must report cold.
        $cache = new fake_cache();
        $cache->set(avuz_prefetch_cache::body_key('INBOX', 941, '1.1'), 'hi');
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, ['1.1', '1.2']);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testNotWarmForLegacyPlainOneSentinel() {
        $cache = new fake_cache();
        // Pre-fix code wrote the literal string '1' as the sentinel value.
        $cache->data[avuz_prefetch_cache::done_key('INBOX', 941)] = '1';
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
    }

    function testNotMarkedWarmWhenNothingWasCacheable() {
        $cache = new fake_cache();
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, []);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 941));
        $this->assertSame(0, $cache->writes);
    }

    function testWarmthIsScopedPerFolder() {
        $cache = new fake_cache();
        $cache->set(avuz_prefetch_cache::body_key('INBOX', 941, '1.1'), '<p>hi</p>');
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, ['1.1']);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'Enviadas', 941));
    }

    function testWarmthIsScopedPerUid() {
        $cache = new fake_cache();
        $cache->set(avuz_prefetch_cache::body_key('INBOX', 941, '1.1'), '<p>hi</p>');
        avuz_prefetch_cache::mark_warm($cache, 'INBOX', 941, ['1.1']);
        $this->assertFalse(avuz_prefetch_cache::is_warm($cache, 'INBOX', 942));
    }

    function testTreatsMissingCacheAsCold() {
        $this->assertFalse(avuz_prefetch_cache::is_warm(false, 'INBOX', 941));
    }

    function testMarkingWithoutCacheDoesNotError() {
        avuz_prefetch_cache::mark_warm(false, 'INBOX', 941, ['1.1']);
        $this->assertTrue(true);
    }

    public function test_parse_plain_uids_uses_default_folder()
    {
        $out = avuz_prefetch_cache::parse_uid_folder_map('12,15,20', 'INBOX');
        $this->assertSame(
            [['uid'=>12,'folder'=>'INBOX'],['uid'=>15,'folder'=>'INBOX'],['uid'=>20,'folder'=>'INBOX']],
            $out
        );
    }

    public function test_parse_folder_qualified_tokens()
    {
        $out = avuz_prefetch_cache::parse_uid_folder_map('12:INBOX,15:Sent', null);
        $this->assertSame(
            [['uid'=>12,'folder'=>'INBOX'],['uid'=>15,'folder'=>'Sent']],
            $out
        );
    }

    public function test_parse_skips_invalid_and_empty_folder()
    {
        $out = avuz_prefetch_cache::parse_uid_folder_map('0:INBOX,abc,15:,20:Sent', 'INBOX');
        $this->assertSame([['uid'=>20,'folder'=>'Sent']], $out);
    }
}
