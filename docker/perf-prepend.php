<?php
/**
 * Per-request PHP timing, attributed to a Roundcube session.
 *
 * nginx's perf log records duration but has no idea which session a request
 * belonged to. That gap is what stopped the 2026-07-22 attachment-loss
 * investigation: an 86s `refresh` overlapped the upload that lost its
 * attachments, but nothing could prove the refresh belonged to that user.
 *
 * Loaded via auto_prepend_file, so it runs before Roundcube on every request.
 * One line is appended to logs/php-perf.log as the request ends:
 *
 *   <start_epoch> <php_duration> <session8> <action> <uri>
 *
 * session8 is the first 8 chars of the PHP session id — the same value
 * Roundcube prints as <baffb4b8> in its own logs, so lines join directly.
 *
 * php_duration measures PHP only. nginx's $upstream_response_time covers the
 * FPM queue *plus* PHP, so (upstream_response_time - php_duration) is the time
 * a request spent waiting for a free worker.
 *
 * Set AVUZ_PERF_LOG=0 in the stack to disable without rebuilding the image.
 */

// FPM only. Under CLI the process runs as root, and a root-owned php-perf.log
// would stop www-data workers from ever appending to it again.
if (PHP_SAPI !== 'fpm-fcgi' || getenv('AVUZ_PERF_LOG') === '0') {
    return;
}

/**
 * Written from a destructor rather than register_shutdown_function: shutdown
 * functions registered here run BEFORE Roundcube's own (rcube::shutdown, which
 * writes the session and may run GC), so they would stop the clock too early
 * and hide exactly the slow tail we are hunting. Destructors run last.
 */
final class avuz_request_timer
{
    private $start;

    public function __construct()
    {
        $this->start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    }

    public function __destruct()
    {
        // Empty when no session was started (static files, pre-login).
        $session = session_id();
        $session = is_string($session) && $session !== '' ? substr($session, 0, 8) : '--------';

        $action = $_GET['_action'] ?? ($_POST['_action'] ?? '');
        if (!is_string($action) || !preg_match('/^[a-zA-Z0-9._-]{1,40}$/', $action)) {
            $action = '-';
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '-';
        if (strlen($uri) > 200) {
            $uri = substr($uri, 0, 200);
        }

        $line = sprintf("%.3f %.3f %s %s %s\n",
            $this->start, microtime(true) - $this->start, $session, $action, $uri);

        // Appends this short are atomic enough across workers; a lost line is
        // acceptable for a measurement log and never worth failing a request.
        @file_put_contents('/var/www/roundcube/logs/php-perf.log', $line, FILE_APPEND);
    }
}

$GLOBALS['avuz_request_timer'] = new avuz_request_timer();
