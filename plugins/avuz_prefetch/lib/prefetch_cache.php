<?php

/**
 * Cache key scheme + warmth check for avuz_prefetch.
 *
 * The "done" sentinel lets prefetch() skip a message whose text bodies are
 * already cached WITHOUT building an rcube_message first — building one costs a
 * BODYSTRUCTURE fetch plus, on nested multipart mail, one BODY.PEEK[N.MIME]
 * command per nesting level (rcube_imap.php:2097-2103 only batches within a level).
 * At 198ms RTT to Zoho those are the round trips worth removing.
 *
 * Redis is shared (allkeys-lru) with sessions and imap_cache, so the sentinel
 * can outlive the bodies it vouches for: eviction is size-driven, and a ~30 byte
 * sentinel is far less likely to be reclaimed than the ~180 kB bodies next to it.
 * So the sentinel's VALUE is the list of mime-ids it actually cached, and
 * is_warm() confirms the LAST of those body keys is still present before
 * trusting it. In a multipart/alternative, mime_parts (and so this list) puts
 * text/plain before text/html, so the last entry is the html part Roundcube
 * actually renders — the one worth verifying.
 *
 * This costs one extra Redis fetch, not one cheap GET: rcube_cache has no
 * exists(), so read_record() pulls and unserializes the whole cached value.
 * Still zero IMAP round trips, which is what matters at 198ms RTT to Zoho.
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

    /**
     * True when this message was warmed on an earlier run AND at least one of
     * the bodies it cached is still there. A legacy plain '1' sentinel (written
     * by older code, or by pre-upgrade Redis contents) is not a list, so it is
     * treated as not-warm rather than crashing — the next prefetch() pass then
     * rewrites it in the new shape, i.e. it self-heals.
     */
    public static function is_warm($cache, $folder, $uid)
    {
        if (!$cache) {
            return false;
        }

        $mimeIds = $cache->get(self::done_key($folder, $uid));
        if (!is_array($mimeIds) || empty($mimeIds)) {
            return false;
        }

        // end() rather than [count()-1]: the list survives a serialize round-trip
        // through Redis, and mark_warm() has no way to guarantee its caller passed
        // a sequential 0-indexed array. A gap in the keys would otherwise read as
        // "not warm" forever and re-fetch every message on every page.
        $lastMimeId = end($mimeIds);
        $body = $cache->get(self::body_key($folder, $uid, $lastMimeId));
        return is_string($body) && $body !== '';
    }

    /**
     * Record which mime-ids were actually cached for this message. A message
     * with no cacheable text part must NOT be marked warm — otherwise it would
     * be skipped forever with nothing ever having been stored for it.
     */
    public static function mark_warm($cache, $folder, $uid, array $mimeIds)
    {
        if (!$cache || empty($mimeIds)) {
            return;
        }
        $cache->set(self::done_key($folder, $uid), $mimeIds);
    }

    /**
     * Parse a client uid list into an ordered [uid,folder] map.
     * Accepts "12,15" (default folder) or "12:INBOX,15:Sent" (folder-qualified).
     */
    public static function parse_uid_folder_map($uids, $default_folder)
    {
        $out = [];
        foreach (array_filter(explode(',', (string) $uids), 'strlen') as $token) {
            $pos    = strpos($token, ':');
            $rawUid = $pos === false ? $token : substr($token, 0, $pos);
            $folder = $pos === false ? (string) $default_folder : substr($token, $pos + 1);
            $uid    = (int) $rawUid;
            if ($uid <= 0 || $folder === '') {
                continue;
            }
            $out[] = ['uid' => $uid, 'folder' => $folder];
            if (count($out) >= 10) { // avuz_prefetch::MAX_UIDS
                break;
            }
        }
        return $out;
    }
}
