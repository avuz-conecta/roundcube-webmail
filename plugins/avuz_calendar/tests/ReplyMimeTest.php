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

    public function testEncodesUtf8SubjectAndBody(): void {
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nEND:VCALENDAR\r\n";
        $mime = avuz_reply_mime::build_reply_mime($ics, 'user@empresa.com', 'org@example.com', 'Aceito: Reunião Comercial', 'user@empresa.com — Aceito: Reunião Comercial');
        $message = $mime->getMessage();
        $headers = $mime->txtHeaders();
        // Subject must be RFC 2047 UTF-8 encoded, not raw ISO-8859-1 mojibake.
        $this->assertMatchesRegularExpression('/Subject:\s*=\?UTF-8\?/i', $headers);
        $this->assertStringNotContainsString('ReuniÃ£o', $headers);
        // The text/plain part must declare UTF-8.
        $this->assertMatchesRegularExpression('/text\/plain;\s*charset=UTF-8/i', $message);
    }

    public function testMultipartAlternativeWhenTextProvided(): void {
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REPLY\r\nEND:VCALENDAR\r\n";
        $mime = avuz_reply_mime::build_reply_mime($ics, 'user@empresa.com', 'organizer@example.com', 'Accepted: teste', 'user accepted: teste');
        $mime->get();
        $headers = $mime->txtHeaders();

        $this->assertMatchesRegularExpression('/^Content-Type:\s*multipart\/alternative/mi', $headers);
        $message = $mime->getMessage();
        $this->assertStringContainsString('text/plain', $message);
        $this->assertStringContainsString('text/calendar', $message);
        $this->assertStringContainsString('method=REPLY', $message);
    }
}
