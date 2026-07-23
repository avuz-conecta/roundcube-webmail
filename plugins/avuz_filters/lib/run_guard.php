<?php

/**
 * Once-per-request guard.
 *
 * avuz_filters hooks both 'check_recent' and 'refresh'; both fire in a single
 * refresh request, so the filter pass ran twice, issuing duplicate
 * UID SEARCH commands against Zoho. Neither hook can be dropped —
 * see the hook-registration comment in avuz_filters.php's init() for why
 * 'check_recent' is primary and 'refresh' the fallback.
 *
 * State is per PHP process — but note FPM *reuses* that OS process across many
 * requests, so it's not the process boundary that resets this. What resets it
 * is that PHP tears down and rebuilds all userland state (including class
 * statics like $claimed) at the end of every request, so in practice this
 * still ends up scoped to one HTTP request under FPM. Do not port this
 * reasoning to a persistent runtime (Swoole, RoadRunner, etc.) where userland
 * state — and this static — would survive across requests and need an
 * explicit reset per request instead.
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
