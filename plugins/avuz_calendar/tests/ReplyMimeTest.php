<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../lib/reply_mime.php';

class ReplyMimeTest extends TestCase {
    public function testBuildsCalendarReplyContentType(): void {
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nEND:VCALENDAR\r\n";
        $mime = avuz_reply_mime::build_reply_mime($ics, 'user@empresa.com', 'organizer@example.com', 'Reply to invitation');

        $headers = $mime->txtHeaders();

        $this->assertMatchesRegularExpression('/^Content-Type:\s*text\/calendar/mi', $headers);
        $this->assertStringContainsString('method=REPLY', $headers);
        $this->assertStringNotContainsString('text/plain', $headers);
    }

    public function testBodyContainsTheIcsReply(): void {
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nEND:VCALENDAR\r\n";
        $mime = avuz_reply_mime::build_reply_mime($ics, 'user@empresa.com', 'organizer@example.com', 'Reply to invitation');

        $message = $mime->get();

        $this->assertStringContainsString('METHOD:REPLY', $message);
    }
}
