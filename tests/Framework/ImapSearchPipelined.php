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

    function test_exec_answers_every_folder_from_one_pipelined_pass()
    {
        $conn = new imap_search_conn_stub();
        $conn->multi_result = [
            'INBOX' => new rcube_result_index('INBOX', '* SEARCH 1 4'),
            'Sent'  => new rcube_result_index('Sent', '* SEARCH 7'),
        ];

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $result = $worker->exec(['INBOX', 'Sent'], 'HEADER SUBJECT "x"');

        $this->assertSame([['searchMulti', ['INBOX', 'Sent'], 'HEADER SUBJECT "x"']], $conn->calls,
            'a pipelined pass must not be followed by per-folder searches');
        $this->assertSame(3, $result->count());
    }

    function test_exec_falls_back_to_serial_when_the_pipeline_is_refused()
    {
        $conn = new imap_search_conn_stub();
        $conn->multi_result   = false;
        $conn->serial_results = ['INBOX' => '* SEARCH 1 4', 'Sent' => '* SEARCH 7'];

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $result = $worker->exec(['INBOX', 'Sent'], 'HEADER SUBJECT "x"');

        $this->assertSame(
            [
                ['searchMulti', ['INBOX', 'Sent'], 'HEADER SUBJECT "x"'],
                ['search', 'INBOX', 'HEADER SUBJECT "x"'],
                ['search', 'Sent', 'HEADER SUBJECT "x"'],
            ],
            $conn->calls
        );
        $this->assertSame(3, $result->count(), 'the fallback must produce the same answer');
    }

    function test_exec_does_not_pipeline_per_folder_criteria()
    {
        $conn = new imap_search_conn_stub();
        $conn->serial_results = ['INBOX' => '* SEARCH 1', 'Sent' => '* SEARCH 7'];

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $worker->exec(['INBOX', 'Sent'], ['INBOX' => 'HEADER SUBJECT "x"', 'Sent' => 'HEADER TO "y"']);

        $this->assertSame(
            [['search', 'INBOX', 'HEADER SUBJECT "x"'], ['search', 'Sent', 'HEADER TO "y"']],
            $conn->calls,
            'one pipeline sends one command shape; differing criteria must go serial'
        );
    }

    function test_exec_does_not_pipeline_a_threaded_search()
    {
        $conn = new imap_search_conn_stub();

        $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
        $worker->exec(['INBOX'], 'HEADER SUBJECT "x"', null, null, 'REFERENCES');

        $this->assertEmpty(array_filter($conn->calls, static fn($call) => $call[0] === 'searchMulti'));
    }

    function test_exec_honours_the_kill_switch()
    {
        putenv('AVUZ_PIPELINED_SEARCH=0');

        try {
            $conn = new imap_search_conn_stub();
            $conn->serial_results = ['INBOX' => '* SEARCH 1'];

            $worker = new rcube_imap_search(['skip_deleted' => false], $conn);
            $worker->exec(['INBOX'], 'HEADER SUBJECT "x"');

            $this->assertSame([['search', 'INBOX', 'HEADER SUBJECT "x"']], $conn->calls);
        }
        finally {
            putenv('AVUZ_PIPELINED_SEARCH');
        }
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
