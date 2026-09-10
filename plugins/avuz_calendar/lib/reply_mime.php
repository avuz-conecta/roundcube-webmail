<?php
class avuz_reply_mime {
    /**
     * Build the iTip REPLY email as a Mail_mime message whose primary body
     * part is text/calendar; method=REPLY (not text/plain).
     *
     * Mail_mime::setTXTBody() always produces a text/plain part regardless of
     * any manually-set Content-Type header, since Mail_mime::headers()
     * unconditionally recomputes Content-Type from the body parts that were
     * set (contentHeaders()). setCalendarBody() is the API that registers a
     * calbody part and the calendar_method/calendar_charset build params
     * that contentHeaders() uses to emit `text/calendar; charset=...;
     * method=...`.
     *
     * A human-readable text/plain part is added alongside so the message is
     * multipart/alternative (text + calendar) — the shape Google/Outlook send
     * and expect. A calendar-only body is more likely to be spam-filed or
     * ignored by the receiving server's scheduling processor.
     */
    public static function build_reply_mime(string $reply_ics, string $from, string $to, string $subject, string $text = ''): Mail_mime {
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $mime = new Mail_mime(['eol' => "\r\n"]);
        $mime->headers([
            'From'       => $from,
            'To'         => $to,
            'Subject'    => $subject,
            'Date'       => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        ]);
        if ($text !== '') { $mime->setTXTBody($text); }
        $mime->setCalendarBody($reply_ics, false, false, 'REPLY', 'UTF-8');
        return $mime;
    }
}
