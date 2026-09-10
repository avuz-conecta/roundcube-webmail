<?php
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

class avuz_itip_reply {
    /**
     * Build an iTip REPLY (RFC 5546) that Google/Outlook will actually process
     * into the organizer's event as an accept/decline. That means the VEVENT
     * MUST carry DTSTAMP (RFC 5545 — replies without it are silently dropped),
     * the same UID + SEQUENCE as the REQUEST, the ORGANIZER, and the responding
     * ATTENDEE with the chosen PARTSTAT. CN is copied from the REQUEST so the
     * organizer sees a name.
     */
    public static function build(string $request_ics, string $attendee_email, string $partstat): string {
        $src = Reader::read($request_ics);
        $srcEvent = $src->VEVENT;

        $reply = new VCalendar();
        $reply->METHOD = 'REPLY';
        $reply->PRODID = '-//Avuz Conecta//avuz_calendar//EN';

        $ev = $reply->add('VEVENT', [
            'UID'      => (string) $srcEvent->UID,
            'SEQUENCE' => (string) ($srcEvent->SEQUENCE ?? '0'),
            'DTSTAMP'  => new \DateTime('now', new \DateTimeZone('UTC')),
        ]);
        if (isset($srcEvent->DTSTART))     { $ev->add(clone $srcEvent->DTSTART); }
        if (isset($srcEvent->{'RECURRENCE-ID'})) { $ev->add(clone $srcEvent->{'RECURRENCE-ID'}); }
        if (isset($srcEvent->ORGANIZER))   { $ev->add('ORGANIZER', (string) $srcEvent->ORGANIZER); }

        $params = ['PARTSTAT' => $partstat];
        $cn = self::attendee_cn($srcEvent, $attendee_email);
        if ($cn !== null) { $params['CN'] = $cn; }
        $ev->add('ATTENDEE', 'mailto:' . $attendee_email, $params);

        return $reply->serialize();
    }

    private static function attendee_cn($event, string $email): ?string {
        if (!isset($event->ATTENDEE)) { return null; }
        $needle = strtolower($email);
        foreach ($event->ATTENDEE as $attendee) {
            $addr = strtolower(preg_replace('/^mailto:/i', '', trim((string) $attendee)));
            if ($addr === $needle && isset($attendee['CN'])) {
                return (string) $attendee['CN'];
            }
        }
        return null;
    }
}
