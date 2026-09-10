<?php
use PHPUnit\Framework\TestCase;

if (!class_exists('rcube_plugin')) {
    class rcube_plugin { public $task; function add_hook($a, $b) {} function register_action($a, $b) {} function include_stylesheet($a) {} function include_script($a) {} function add_texts($a, $b = false) {} }
}
require_once __DIR__ . '/../avuz_calendar.php';

class DetectPartTest extends TestCase {
    private function part(string $mimetype, string $mime_id) {
        return (object) ['mimetype' => $mimetype, 'mime_id' => $mime_id];
    }

    public function testFindsCalendarPartAmongAttachments(): void {
        $attachments = [
            $this->part('application/ics', '2'),
            $this->part('text/calendar; method=REQUEST', '1.3'),
        ];
        $found = avuz_calendar::find_calendar_part($attachments);
        $this->assertNotNull($found);
        $this->assertSame('1.3', $found->mime_id);
    }

    public function testReturnsNullWhenNoCalendarPart(): void {
        $attachments = [$this->part('application/pdf', '2'), $this->part('image/png', '3')];
        $this->assertNull(avuz_calendar::find_calendar_part($attachments));
    }

    public function testReturnsNullForEmptyAttachments(): void {
        $this->assertNull(avuz_calendar::find_calendar_part([]));
    }
}
