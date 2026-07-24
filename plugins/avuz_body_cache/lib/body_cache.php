<?php

/**
 * Pure helpers for avuz_body_cache. No Roundcube runtime dependencies, so unit-testable.
 */
class avuz_body_cache_lib
{
    /** Bump on ANY change to how bodies are rendered/sanitized (washtml, skin, CID). */
    public const SANITIZER_VERSION = 1;

    /** Stable opaque per-user tag for namespacing the browser cache. */
    public static function user_tag($imap_user, $des_key)
    {
        return substr(hash_hmac('sha256', (string) $imap_user, (string) $des_key), 0, 16);
    }
}
