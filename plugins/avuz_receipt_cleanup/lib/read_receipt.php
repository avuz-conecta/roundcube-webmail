<?php

/**
 * Recognizes a read receipt (RFC 8098 MDN): a top-level
 * multipart/report with report-type=disposition-notification. Roundcube,
 * Outlook and Thunderbird all send this shape. Delivery-status reports
 * (bounces) share multipart/report but carry a different report-type, so the
 * parameter check is what keeps real mail safe.
 */
class avuz_read_receipt
{
    const CANDIDATE_TYPE = 'multipart/report';
    const REPORT_SUBTYPE = 'report';
    const REPORT_TYPE    = 'disposition-notification';

    /** Cheap pre-filter on the Content-Type mime type (header fetch). */
    public static function is_candidate(string $mime_type): bool
    {
        return strtolower($mime_type) === self::CANDIDATE_TYPE;
    }

    /**
     * Decides on the parsed IMAP BODYSTRUCTURE. A multipart structure lists its
     * parts as arrays, then the subtype string, then the parameter list.
     */
    public static function is_read_receipt(?array $structure): bool
    {
        if (empty($structure) || !is_array($structure[0] ?? null)) {
            return false;
        }

        $subtype_index = 0;
        while (is_array($structure[$subtype_index] ?? null)) {
            $subtype_index++;
        }

        $subtype = $structure[$subtype_index] ?? null;
        if (!is_string($subtype) || strtolower($subtype) !== self::REPORT_SUBTYPE) {
            return false;
        }

        $report_type = self::parameter((array) ($structure[$subtype_index + 1] ?? []), 'report-type');
        return $report_type !== null && strtolower($report_type) === self::REPORT_TYPE;
    }

    private static function parameter(array $parameters, string $name): ?string
    {
        for ($index = 0; $index + 1 < count($parameters); $index += 2) {
            if (is_string($parameters[$index]) && strtolower($parameters[$index]) === $name) {
                return (string) $parameters[$index + 1];
            }
        }

        return null;
    }
}
