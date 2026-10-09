<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/calendar_event.php';

class CalendarEventTest extends TestCase {
    private function request(string $attendee = 'patrick@avuz.cloud'): string {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Google//EN\r\nMETHOD:REQUEST\r\n"
            . "BEGIN:VEVENT\r\nUID:evt-1@google.com\r\nSUMMARY:teste\r\n"
            . "DTSTART:20260910T200000Z\r\nDTEND:20260910T210000Z\r\n"
            . "ORGANIZER:mailto:boss@gmail.com\r\n"
            . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:{$attendee}\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testKeepsMethodRequestForImipUpsert(): void {
        $out = avuz_calendar_event::for_calendar($this->request(), 'patrick@avuz.cloud', 'ACCEPTED');
        $this->assertStringContainsString('METHOD:REQUEST', $out);
    }

    public function testSetsChosenPartstatOnUserAttendee(): void {
        $out = avuz_calendar_event::for_calendar($this->request(), 'patrick@avuz.cloud', 'ACCEPTED');
        $this->assertStringContainsString('PARTSTAT=ACCEPTED', $out);
        $this->assertStringNotContainsString('NEEDS-ACTION', $out);
    }

    public function testMatchesAttendeeCaseInsensitively(): void {
        $out = avuz_calendar_event::for_calendar($this->request('Patrick@Avuz.Cloud'), 'patrick@avuz.cloud', 'TENTATIVE');
        $this->assertStringContainsString('PARTSTAT=TENTATIVE', $out);
        $this->assertStringNotContainsString('NEEDS-ACTION', $out);
    }

    public function testMarksScheduleAgentClientToSuppressServerReply(): void {
        $out = avuz_calendar_event::for_calendar($this->request(), 'patrick@avuz.cloud', 'ACCEPTED');
        $this->assertMatchesRegularExpression('/ORGANIZER;SCHEDULE-AGENT=CLIENT/i', $out);
        $this->assertMatchesRegularExpression('/ATTENDEE;[^\r\n]*SCHEDULE-AGENT=CLIENT/i', $out);
    }

    public function testAddsAttendeeWhenUserNotInvited(): void {
        $out = avuz_calendar_event::for_calendar($this->request('someone-else@x.com'), 'patrick@avuz.cloud', 'ACCEPTED');
        $this->assertStringContainsString('patrick@avuz.cloud', $out);
        $this->assertStringContainsString('PARTSTAT=ACCEPTED', $out);
    }

    public function testCleanDescriptionStripsGoogleMeetBoilerplate(): void {
        $desc = "-::~:~::~:~::~:~::-\nJoin with Google Meet: https://meet.google.com/abc\n"
            . "Learn more about Meet at: https://support.google.com/a/users/answer/9282720\n\n"
            . "Please do not edit this section.\n-::~:~::~:~::~:~::-";
        $this->assertSame('', avuz_calendar_event::clean_description($desc));
    }

    public function testCleanDescriptionKeepsUserText(): void {
        $desc = "Pauta: fechamento do trimestre\n-::~:~::~::-\nJoin with Google Meet: https://meet.google.com/abc\n-::~:~::~::-";
        $this->assertSame('Pauta: fechamento do trimestre', avuz_calendar_event::clean_description($desc));
    }

    public function testForCalendarStripsBoilerplateFromStoredEvent(): void {
        $req = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nUID:evt@google.com\r\n"
            . "SUMMARY:Reuniao\r\nDTSTART:20260913T200000Z\r\nORGANIZER:mailto:boss@gmail.com\r\n"
            . "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:patrick@avuz.cloud\r\n"
            . "DESCRIPTION:-::~:~::~:~::-\\nJoin with Google Meet: https://meet.google.com/abc\\n-::~:~::~:~::-\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";
        $out = avuz_calendar_event::for_calendar($req, 'patrick@avuz.cloud', 'ACCEPTED');
        $this->assertStringNotContainsString('~:~', $out);
        $this->assertStringNotContainsString('Join with Google Meet', $out);
    }
}
