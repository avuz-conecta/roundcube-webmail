<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/ical_invite.php';

class IcalInviteTest extends TestCase {
    public function testParsesGoogleMeetRequest(): void {
        $ics = file_get_contents(__DIR__ . '/fixtures/google-meet.ics');
        $inv = avuz_ical_invite::from_ics($ics);
        $this->assertSame('abc123@google.com', $inv['uid']);
        $this->assertSame('Reuniao de Vendas', $inv['summary']);
        $this->assertSame('ana@empresa.com', $inv['organizer']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $inv['meet_url']);
        $this->assertFalse($inv['is_recurring']);
    }
    public function testReturnsNullForNonRequest(): void {
        $this->assertNull(avuz_ical_invite::from_ics("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"));
    }
}
