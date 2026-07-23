<?php

/**
 * Behaviour tests for the pipelined pass in rcube_imap_search.
 *
 * @package Tests
 */
class Framework_ImapSearchPipelined extends PHPUnit\Framework\TestCase
{
    private function worker(array $options = []): rcube_imap_search
    {
        return new rcube_imap_search($options + ['skip_deleted' => false], new imap_search_conn_stub());
    }

    private function job(rcube_imap_search $worker, string $folder, string $criteria, $charset = null): rcube_imap_search_job
    {
        $job = new rcube_imap_search_job($folder, $criteria, $charset);
        $job->worker = $worker;

        return $job;
    }

    function test_get_criteria_returns_the_bare_criteria_when_nothing_applies()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"');

        $this->assertSame('HEADER SUBJECT "x"', $job->get_criteria());
    }

    function test_get_criteria_prefixes_undeleted_when_skip_deleted_is_on()
    {
        $job = $this->job($this->worker(['skip_deleted' => true]), 'INBOX', 'HEADER SUBJECT "x"');

        $this->assertSame('UNDELETED HEADER SUBJECT "x"', $job->get_criteria());
    }

    function test_get_criteria_drops_the_charset_for_an_ascii_criteria()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"', 'UTF-8');

        $this->assertSame('HEADER SUBJECT "x"', $job->get_criteria());
    }

    function test_get_criteria_keeps_the_charset_for_a_non_ascii_criteria()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "Relatório"', 'UTF-8');

        $this->assertSame('CHARSET UTF-8 HEADER SUBJECT "Relatório"', $job->get_criteria());
    }

    function test_a_fresh_job_has_no_result()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"');

        $this->assertFalse($job->has_result());
    }

    function test_set_result_marks_the_job_answered()
    {
        $job = $this->job($this->worker(), 'INBOX', 'HEADER SUBJECT "x"');
        $job->set_result(new rcube_result_index('INBOX', '* SEARCH 1 4'));

        $this->assertTrue($job->has_result());
        $this->assertSame(['1', '4'], $job->get_result()->get());
        $this->assertSame('INBOX', $job->get_folder());
    }
}

/**
 * Stands in for rcube_imap_generic. Records the searches it is asked for and
 * returns whatever the test scripted, so the tests assert on which path
 * rcube_imap_search chose rather than on any wire traffic.
 */
class imap_search_conn_stub extends rcube_imap_generic
{
    /** @var array|false result for searchMulti(), or false to force the serial fallback */
    public $multi_result = false;

    /** @var array<string, string> canned '* SEARCH ...' response per folder for the serial path */
    public $serial_results = [];

    /** @var array<int, array> every call made, in order */
    public $calls = [];

    public function connected()
    {
        return true;
    }

    public function searchMulti($folders, $criteria, $return_uid = false, $items = [])
    {
        $this->calls[] = ['searchMulti', $folders, $criteria];

        return $this->multi_result;
    }

    public function search($mailbox, $criteria, $return_uid = false, $items = [])
    {
        $this->calls[] = ['search', $mailbox, $criteria];

        return new rcube_result_index($mailbox, $this->serial_results[$mailbox] ?? '* SEARCH');
    }

    public function thread($mailbox, $algorithm = 'REFERENCES', $criteria = '', $return_uid = false, $encoding = 'US-ASCII')
    {
        $this->calls[] = ['thread', $mailbox, $criteria];

        return new rcube_result_thread($mailbox, '* THREAD');
    }
}
