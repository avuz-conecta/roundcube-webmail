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
 * METHOD:REQUEST is KEPT: AvuzConecta now imports via IManager::handleIMip,
 * which needs the iTip method to upsert the event (SEQUENCE downgrades are
 * gated by the importer, not by handleIMip — the broker overwrites regardless).
 */
class avuz_calendar_event {
    public static function for_calendar(string $request_ics, string $email, string $partstat): string {
        $vcal = Reader::read($request_ics);
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

        if (isset($event->DESCRIPTION)) {
            $clean = self::clean_description((string) $event->DESCRIPTION);
            if ($clean === '') { unset($event->DESCRIPTION); }
            else { $event->DESCRIPTION = $clean; }
        }

        return $vcal->serialize();
    }

    /**
     * Strip Google Meet's auto-generated boilerplate from the description. Google
     * wraps it between decorative separator lines (-::~:~::~ … ::-) and repeats
     * "Join with Google Meet:", "Learn more about Meet at:", "Please do not edit
     * this section." The Meet URL is already surfaced (LOCATION / the card), so
     * the whole block is noise in the stored event. Any real user-written text
     * outside the block is preserved.
     */
    static function clean_description(string $desc): string {
        $lines = preg_split('/\r\n|\r|\n/', $desc);
        $out = [];
        $in_block = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*-::~[~:\s.-]*::-\s*$/', $line)) { $in_block = !$in_block; continue; }
            if ($in_block) { continue; }
            if (preg_match('/^\s*(Join with Google Meet:|Learn more about Meet at:|Please do not edit this section\.)/i', $line)) { continue; }
            $out[] = $line;
        }
        return trim(implode("\n", $out));
    }

    private static function normalize(string $addr): string {
        return strtolower(preg_replace('/^mailto:/i', '', trim($addr)));
    }
}
