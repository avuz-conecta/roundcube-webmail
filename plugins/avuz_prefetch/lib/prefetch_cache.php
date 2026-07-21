<?php

/**
 * Cache key scheme + warmth check for avuz_prefetch.
 *
 * The "done" sentinel lets prefetch() skip a message whose text bodies are
 * already cached WITHOUT building an rcube_message first — building one costs a
 * BODYSTRUCTURE fetch plus, on nested multipart mail, one BODY.PEEK[N.MIME]
 * command per nesting level (rcube_imap.php:2097-2103 only batches within a level).
 * At 198ms RTT to Zoho those are the round trips worth removing.
 */
class avuz_prefetch_cache
{
    /** Sentinel mime_id. Not a legal IMAP part number, so it can never collide. */
    private const DONE_MIME_ID = '#done';

    public static function body_key($folder, $uid, $mimeId)
    {
        return $folder . ':' . $uid . ':' . $mimeId;
    }

    public static function done_key($folder, $uid)
    {
        return self::body_key($folder, $uid, self::DONE_MIME_ID);
    }

    /** True when this message was fully warmed on an earlier run. */
    public static function is_warm($cache, $folder, $uid)
    {
        if (!$cache) {
            return false;
        }
        return $cache->get(self::done_key($folder, $uid)) === '1';
    }

    public static function mark_warm($cache, $folder, $uid)
    {
        if (!$cache) {
            return;
        }
        $cache->set(self::done_key($folder, $uid), '1');
    }
}
