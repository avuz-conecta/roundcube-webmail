<?php

/**
 * In-memory session driver, so a write race can be reproduced without Redis.
 */
class rcube_session_race_double extends rcube_session
{
    /** @var array key => serialized vars string */
    public $store = [];

    function open($save_path, $session_name) { return true; }
    function close() { return true; }
    function destroy($key) { unset($this->store[$key]); return true; }

    function read($key)
    {
        $this->key     = $key;
        $this->vars    = $this->store[$key] ?? '';
        $this->changed = time();

        return $this->vars;
    }

    function write($key, $vars)
    {
        $this->store[$key] = $vars;
        return true;
    }

    function update($key, $newvars, $oldvars)
    {
        $this->store[$key] = $newvars;
        return true;
    }

    /** Pretend this request started $seconds ago, so get_cache() re-reads the store. */
    public function started_seconds_ago($seconds)
    {
        $this->start = microtime(true) - $seconds;
    }

    /** serialize() is protected; tests need to build store contents. */
    public function pack(array $vars)
    {
        return $this->serialize($vars);
    }
}

/**
 * Concurrent-write behaviour of rcube_session.
 *
 * Roundcube keeps compose attachments only in $_SESSION['compose_data_<id>'].
 * Every request writes its whole $_SESSION back at shutdown, so a request that
 * is still running while an upload completes can put back the compose state as
 * it was when that request began — silently dropping the attachment. The user
 * then sends a message with the file missing and sees no error at all.
 *
 * Observed on prod 2026-07-22: an 86s `refresh` overlapped an upload; the sent
 * message carried only the identity signature image (added at compose init, so
 * inside the stale snapshot) and none of the user's files.
 *
 * @package Tests
 */
class Framework_SessionRace extends PHPUnit\Framework\TestCase
{
    private const SID         = 'testsessionid';
    private const COMPOSE_KEY = 'compose_data_1';

    /**
     * @return array{0: rcube_session_race_double, 1: array} driver and the
     *         session state a slow request would have loaded at its start
     */
    private function start_slow_request_during_compose()
    {
        $session = new rcube_session_race_double(rcube::get_instance()->config);

        // Compose is open. The signature image is attached at compose init.
        $at_compose_open = [
            'user_id'          => 5,
            self::COMPOSE_KEY  => ['attachments' => ['sig' => ['name' => 'signature.png']]],
        ];

        $session->store[self::SID] = $session->pack($at_compose_open);

        // A slow request (refresh, prefetch, a message fetch) starts here and
        // loads that state into its own $_SESSION.
        $session->read(self::SID);

        return [$session, $at_compose_open];
    }

    /**
     * The upload lands while the slow request is still running, and must still
     * be there once that request finishes.
     */
    function test_attachment_uploaded_during_a_slow_request_survives()
    {
        [$session, $slow_request_vars] = $this->start_slow_request_during_compose();

        // Upload request completes: adds the user's file and writes it out.
        $after_upload = $slow_request_vars;
        $after_upload[self::COMPOSE_KEY]['attachments']['file1'] = ['name' => 'invoice.pdf'];
        $session->store[self::SID] = $session->pack($after_upload);

        // The slow request now finishes. It began well over 0.5s ago, so
        // get_cache() re-reads the store rather than trusting its own copy.
        $session->started_seconds_ago(5);
        $session->sess_write(self::SID, $session->pack($slow_request_vars));

        $stored      = rcube_session::unserialize($session->store[self::SID]);
        $attachments = $stored[self::COMPOSE_KEY]['attachments'];

        $this->assertArrayHasKey('file1', $attachments,
            'an attachment uploaded while a slow request was in flight must not be erased when that request writes the session back');
        $this->assertArrayHasKey('sig', $attachments,
            'the signature attachment must still be there too');
    }

    /**
     * The same race, one level up: a whole compose started during the slow
     * request must not disappear either.
     */
    function test_compose_started_during_a_slow_request_survives()
    {
        [$session, $slow_request_vars] = $this->start_slow_request_during_compose();

        $with_second_compose                      = $slow_request_vars;
        $with_second_compose['compose_data_2']    = ['attachments' => ['file9' => ['name' => 'other.pdf']]];
        $session->store[self::SID]                = $session->pack($with_second_compose);

        $session->started_seconds_ago(5);
        $session->sess_write(self::SID, $session->pack($slow_request_vars));

        $stored = rcube_session::unserialize($session->store[self::SID]);

        $this->assertArrayHasKey('compose_data_2', $stored,
            'a compose opened while a slow request was in flight must not be erased');
    }

    /**
     * Guard against over-correcting: a request that deliberately changes its own
     * session data must still win. Only untouched keys defer to the store.
     */
    function test_a_requests_own_changes_are_still_written()
    {
        [$session, $slow_request_vars] = $this->start_slow_request_during_compose();

        $modified            = $slow_request_vars;
        $modified['user_id'] = 99;

        $session->started_seconds_ago(5);
        $session->sess_write(self::SID, $session->pack($modified));

        $stored = rcube_session::unserialize($session->store[self::SID]);

        $this->assertSame(99, $stored['user_id'], 'a value the request itself changed must be written');
    }
}
