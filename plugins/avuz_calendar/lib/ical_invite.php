<?php
use Sabre\VObject\Reader;

class avuz_ical_invite {
    public static function from_ics(string $ics): ?array {
        try { $vcal = Reader::read($ics); } catch (\Throwable $e) { return null; }
        if (strtoupper((string)($vcal->METHOD ?? '')) !== 'REQUEST' || !isset($vcal->VEVENT)) {
            return null;
        }
        $e = $vcal->VEVENT;
        $org = (string) ($e->ORGANIZER ?? '');
        $loc = (string) ($e->LOCATION ?? '');
        $desc = (string) ($e->DESCRIPTION ?? '');
        return [
            'uid'          => (string) ($e->UID ?? ''),
            'summary'      => (string) ($e->SUMMARY ?? ''),
            'organizer'    => self::strip_mailto($org),
            'start'        => isset($e->DTSTART) ? $e->DTSTART->getDateTime()->format(DATE_ATOM) : '',
            'end'          => isset($e->DTEND) ? $e->DTEND->getDateTime()->format(DATE_ATOM) : '',
            'location'     => $loc,
            'meet_url'     => self::meet_url($loc . ' ' . $desc),
            'is_recurring' => isset($e->RRULE),
        ];
    }
    private static function strip_mailto(string $v): string {
        return preg_replace('/^mailto:/i', '', trim($v));
    }
    private static function meet_url(string $text): ?string {
        return preg_match('~https://meet\.google\.com/[a-z0-9-]+~i', $text, $m) ? $m[0] : null;
    }
}
