<?php
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

class avuz_itip_reply {
    public static function build(string $request_ics, string $attendee_email, string $partstat): string {
        $src = Reader::read($request_ics);
        $srcEvent = $src->VEVENT;
        $reply = new VCalendar();
        $reply->METHOD = 'REPLY';
        $reply->PRODID = '-//Avuz Conecta//avuz_calendar//EN';
        $ev = $reply->add('VEVENT', [
            'UID'      => (string) $srcEvent->UID,
            'SEQUENCE' => (string) ($srcEvent->SEQUENCE ?? '0'),
        ]);
        if (isset($srcEvent->DTSTART)) { $ev->add('DTSTART', $srcEvent->DTSTART->getValue()); }
        if (isset($srcEvent->ORGANIZER)) { $ev->add('ORGANIZER', (string) $srcEvent->ORGANIZER); }
        $ev->add('ATTENDEE', 'mailto:' . $attendee_email, ['PARTSTAT' => $partstat]);
        return $reply->serialize();
    }
}
