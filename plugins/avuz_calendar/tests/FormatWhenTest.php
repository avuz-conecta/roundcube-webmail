<?php
use PHPUnit\Framework\TestCase;

if (!class_exists('rcube_plugin')) {
    class rcube_plugin { public $task; function add_hook($a, $b) {} function register_action($a, $b) {} function include_stylesheet($a) {} function include_script($a) {} function add_texts($a, $b = false) {} }
}
require_once __DIR__ . '/../avuz_calendar.php';

class FormatWhenTest extends TestCase {
    public function testFormatsStartAsPtBr(): void {
        $out = avuz_calendar::format_when('2026-09-16T10:00:00-03:00');
        $this->assertSame('16/09/2026 10:00', $out);
    }

    public function testAppendsEndTime(): void {
        $out = avuz_calendar::format_when('2026-09-16T10:00:00-03:00', '2026-09-16T11:30:00-03:00');
        $this->assertSame('16/09/2026 10:00 – 11:30', $out);
    }

    public function testConvertsToGivenTimezone(): void {
        $out = avuz_calendar::format_when('2026-09-16T10:00:00-03:00', '', new DateTimeZone('UTC'));
        $this->assertSame('16/09/2026 13:00', $out);
    }

    public function testFallsBackToRawOnBadInput(): void {
        $this->assertSame('not-a-date', avuz_calendar::format_when('not-a-date'));
    }
}
