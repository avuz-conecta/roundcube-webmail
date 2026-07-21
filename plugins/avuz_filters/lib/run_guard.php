<?php

/**
 * Once-per-request guard.
 *
 * avuz_filters hooks both 'new_messages' and 'refresh'; both fire in a single
 * refresh request, so the filter pass ran twice, issuing duplicate
 * UID SEARCH commands against Zoho. Neither hook can be dropped —
 * 'new_messages' fires only when check_recent detects a status diff, so
 * 'refresh' is the reliable trigger and 'new_messages' the timely one.
 *
 * State is per PHP process, which for PHP-FPM means per HTTP request.
 */
class avuz_run_guard
{
    private static $claimed = [];

    /** True the first time this token is claimed in this request, false after. */
    public static function claim($token)
    {
        if (isset(self::$claimed[$token])) {
            return false;
        }
        self::$claimed[$token] = true;
        return true;
    }

    /** Test seam. */
    public static function reset()
    {
        self::$claimed = [];
    }
}
