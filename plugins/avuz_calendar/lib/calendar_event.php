<?php
use Sabre\VObject\Reader;

/**
 * Turns an iTip REQUEST into the calendar copy we store in the user's
 * AvuzConecta calendar after they RSVP from the email card.
 *
 * Two things must be true so the calendar does NOT re-prompt the user and does
 * NOT send a second reply to the organizer (we already sent the iTip REPLY over
 * SMTP from the card):
 *   1. The user's ATTENDEE PARTSTAT is set to their choice (ACCEPTED/TENTATIVE)
 *      — otherwise it keeps the REQUEST's NEEDS-ACTION and the calendar shows an
 *      "accept?" prompt.
 *   2. ORGANIZER carries SCHEDULE-AGENT=CLIENT (RFC 6638) — tells the CalDAV
 *      server the client owns scheduling, so the server sends no iMip reply.
 * METHOD is stripped (a stored CalDAV object must not carry one).
 */
class avuz_calendar_event {
    public static function for_calendar(string $request_ics, string $email, string $partstat): string {
        $vcal = Reader::read($request_ics);
        unset($vcal->METHOD);
        if (!isset($vcal->VEVENT)) { return $vcal->serialize(); }
        $event = $vcal->VEVENT;

        if (isset($event->ORGANIZER)) {
            $event->ORGANIZER['SCHEDULE-AGENT'] = 'CLIENT';
        }

        $me = self::normalize($email);
        $matched = false;
        if (isset($event->ATTENDEE)) {
            foreach ($event->ATTENDEE as $attendee) {
                if (self::normalize((string) $attendee) === $me) {
                    $attendee['PARTSTAT'] = $partstat;
                    $attendee['SCHEDULE-AGENT'] = 'CLIENT';
                    $matched = true;
                }
            }
        }
        if (!$matched) {
            $event->add('ATTENDEE', 'mailto:' . $email, [
                'PARTSTAT' => $partstat,
                'SCHEDULE-AGENT' => 'CLIENT',
            ]);
        }
        return $vcal->serialize();
    }

    private static function normalize(string $addr): string {
        return strtolower(preg_replace('/^mailto:/i', '', trim($addr)));
    }
}
