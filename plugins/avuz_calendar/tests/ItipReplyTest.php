<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/itip_reply.php';

class ItipReplyTest extends TestCase {
    public function testBuildsAcceptedReply(): void {
        $req = file_get_contents(__DIR__ . '/fixtures/google-meet.ics');
        $reply = avuz_itip_reply::build($req, 'user@empresa.com', 'ACCEPTED');
        $this->assertStringContainsString('METHOD:REPLY', $reply);
        $this->assertStringContainsString('UID:abc123@google.com', $reply);
        $this->assertMatchesRegularExpression('/ATTENDEE[^\n]*PARTSTAT=ACCEPTED[^\n]*mailto:user@empresa.com/i', $reply);
    }

    public function testIncludesDtstampSoGoogleProcessesIt(): void {
        $req = file_get_contents(__DIR__ . '/fixtures/google-meet.ics');
        $reply = avuz_itip_reply::build($req, 'user@empresa.com', 'ACCEPTED');
        $this->assertMatchesRegularExpression('/DTSTAMP:\d{8}T\d{6}Z/', $reply);
    }

    public function testDeclinedPartstat(): void {
        $req = file_get_contents(__DIR__ . '/fixtures/google-meet.ics');
        $reply = avuz_itip_reply::build($req, 'user@empresa.com', 'DECLINED');
        $this->assertStringContainsString('PARTSTAT=DECLINED', $reply);
    }
}
