<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/run_guard.php';

class run_guard_test extends TestCase
{
    protected function setUp(): void { avuz_run_guard::reset(); }

    function testGrantsFirstClaim() {
        $this->assertTrue(avuz_run_guard::claim('filters'));
    }

    function testRefusesSecondClaimOfSameToken() {
        avuz_run_guard::claim('filters');
        $this->assertFalse(avuz_run_guard::claim('filters'));
    }

    function testTracksTokensIndependently() {
        avuz_run_guard::claim('filters');
        $this->assertTrue(avuz_run_guard::claim('prefetch'));
    }

    function testResetAllowsClaimingAgain() {
        avuz_run_guard::claim('filters');
        avuz_run_guard::reset();
        $this->assertTrue(avuz_run_guard::claim('filters'));
    }
}
