<?php

/**
 * Test class to test rcmail_action_mail_search
 *
 * @package Tests
 */
class Actions_Mail_Search extends ActionTestCase
{
    /**
     * Test searching mail (empty result)
     */
    function test_search_empty_result()
    {
        $action = new rcmail_action_mail_search;
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $this->assertTrue($action->checks());

        $_GET = [
            '_q'    => 'test',
            '_mbox' => 'INBOX',
        ];

        // Set expected storage function calls/results
        self::initStorage();
        rcmail::get_instance()->storage
            ->registerFunction('set_page')
            ->registerFunction('set_search_set')
            ->registerFunction('search', new rcube_result_index())
            ->registerFunction('get_search_set', [])
            ->registerFunction('get_search_set', [])
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('list_messages', [])
            ->registerFunction('get_error_code', null)
            ->registerFunction('count', 0)
            ->registerFunction('get_quota', false);

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        $this->assertSame(['Content-Type: application/json; charset=UTF-8'], $output->headers);
        $this->assertSame('search', $result['action']);
        $this->assertSame(0, $result['env']['messagecount']);
        $this->assertSame(0, $result['env']['pagecount']);
        $this->assertSame(0, $result['env']['exists']);
        $this->assertTrue(strpos($result['exec'], 'this.display_message("Search returned no matches.","notice",0);') !== false);
        $this->assertTrue(strpos($result['exec'], 'this.set_rowcount("Mailbox is empty","INBOX");') !== false);
        $this->assertTrue(strpos($result['exec'], 'this.set_pagetitle("Roundcube Webmail :: Search result");') !== false);
        $this->assertTrue(strpos($result['exec'], 'this.set_quota') !== false);
    }

    /**
     * Test searching mail (non-empty result)
     */
    function test_search_non_empty_result()
    {
        $action = new rcmail_action_mail_search;
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $_GET = [
            '_q'    => 'test',
            '_mbox' => 'INBOX',
        ];

        $index = new rcube_result_index('INBOX', 'SEARCH 10');

        // Set expected storage function calls/results
        self::initStorage()
            ->registerFunction('set_page')
            ->registerFunction('set_search_set')
            ->registerFunction('search',)
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $index, 'UTF-8', '', false])
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $index, 'UTF-8', '', false])
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('list_messages', [
                10 => rcube_message_header::from_array([
                    'id' => 42,
                    'uid' => 10,
                    'subject' => 'test message',
                    'from' => 'test1@test.com',
                    'to' => 'Test <test2@test.com>',
                    'date' => 'Sun, 13 Mar 2022 17:08:18 +0100',
                    'size' => 889,
                    'content-type' => 'text/plain',
                ])
            ])
            ->registerFunction('get_threading', false)
            ->registerFunction('get_threading', false)
            ->registerFunction('get_threading', false)
            ->registerFunction('count', 1)
            ->registerFunction('count', 1)
            ->registerFunction('count', 1)
            ->registerFunction('folder_data', [])
            ->registerFunction('get_quota', false);

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        $this->assertSame(['Content-Type: application/json; charset=UTF-8'], $output->headers);
        $this->assertSame('search', $result['action']);
        $this->assertSame(1, $result['env']['messagecount']);
        $this->assertSame(1, $result['env']['pagecount']);
        $this->assertSame(1, $result['env']['exists']);
        $this->assertTrue(strpos($result['exec'], 'this.display_message("1 messages found.","confirmation",0);') !== false);
        $this->assertTrue(strpos($result['exec'], 'this.set_rowcount("Messages 1 to 1 of 1","INBOX");') !== false);
        $this->assertTrue(strpos($result['exec'], 'this.set_pagetitle("Roundcube Webmail :: Search result");') !== false);
    }

    /**
     * Builds a message header for the partial-results test.
     *
     * id/uid/size aren't in rcube_message_header's header-name map, so
     * from_array() would route them into $header->others instead of the
     * object properties the message-list renderer actually reads. Set them
     * directly so the row isn't skipped as empty.
     */
    private static function partialHitHeader()
    {
        $header = rcube_message_header::from_array([
            'subject'      => 'partial hit',
            'from'         => 'test1@test.com',
            'to'           => 'Test <test2@test.com>',
            'date'         => 'Sun, 13 Mar 2022 17:08:18 +0100',
            'content-type' => 'text/plain',
        ]);

        $header->id   = 42;
        $header->uid  = 10;
        $header->size = 889;

        return $header;
    }

    /**
     * An unfinished cross-folder search must still render the rows found so far,
     * and must still ask the client to continue.
     */
    function test_search_incomplete_renders_partial_results()
    {
        $action = new rcmail_action_mail_search;
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $_GET = [
            '_q'     => 'test',
            '_mbox'  => 'INBOX',
            '_scope' => 'all',
        ];

        // a multifolder result triggers the message list header rebuild,
        // which reads current sort settings from the session
        $_SESSION['sort_col']   = '';
        $_SESSION['sort_order'] = null;

        $index = new rcube_result_index('INBOX', 'SEARCH 10');

        $partial = new rcube_result_multifolder(['INBOX', 'Archive']);
        $partial->add($index);
        $partial->incomplete = true;

        self::initStorage()
            ->registerFunction('set_page')
            ->registerFunction('set_search_set')
            ->registerFunction('list_folders_subscribed', ['INBOX', 'Archive'])
            ->registerFunction('search', $partial)
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('list_messages', [
                10 => self::partialHitHeader(),
            ])
            ->registerFunction('get_threading', false)
            ->registerFunction('get_threading', false)
            ->registerFunction('get_threading', false)
            ->registerFunction('get_error_code', null)
            ->registerFunction('count', 1)
            ->registerFunction('count', 1)
            ->registerFunction('folder_data', [])
            ->registerFunction('get_quota', false);

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        // the row the completed folder found must be on screen
        $this->assertTrue(strpos($result['exec'], 'partial hit') !== false,
            'partial results must be rendered while the search is still running');

        // and the client must still be told to continue
        $this->assertTrue(strpos($result['exec'], 'this.continue_search(') !== false,
            'an incomplete search must still ask the client to continue');

        // the UI must not be told there are zero messages
        $this->assertNotSame(0, $result['env']['messagecount'],
            'messagecount must reflect what has been found so far, not 0');
    }

    /**
     * Once the total budget is spent the client must NOT be asked to continue,
     * so the search ends instead of looping forever.
     */
    function test_search_stops_continuing_past_total_timelimit()
    {
        $action = new rcmail_action_mail_search;
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $_GET = [
            '_q'        => 'test',
            '_mbox'     => 'INBOX',
            '_scope'    => 'all',
            '_continue' => 'searchreq1',
        ];

        // this logical search started well beyond the 120s budget
        $_SESSION['search_start'] = time() - 999;

        // a multifolder result triggers the message list header rebuild,
        // which reads current sort settings from the session
        $_SESSION['sort_col']   = '';
        $_SESSION['sort_order'] = null;

        $index = new rcube_result_index('INBOX', 'SEARCH 10');

        $partial = new rcube_result_multifolder(['INBOX', 'Archive']);
        $partial->add($index);
        $partial->incomplete = true;

        self::initStorage()
            ->registerFunction('set_page')
            ->registerFunction('set_search_set')
            ->registerFunction('list_folders_subscribed', ['INBOX', 'Archive'])
            ->registerFunction('search', $partial)
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_search_set', ['SEARCH HEADER SUBJECT test', $partial, 'UTF-8', '', false])
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_pagesize', 10)
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('get_folder', 'INBOX')
            ->registerFunction('list_messages', [
                10 => self::partialHitHeader(),
            ])
            ->registerFunction('get_threading', false)
            ->registerFunction('get_threading', false)
            ->registerFunction('get_threading', false)
            ->registerFunction('get_error_code', null)
            ->registerFunction('count', 1)
            ->registerFunction('count', 1)
            ->registerFunction('folder_data', [])
            ->registerFunction('get_quota', false);

        $this->runAndAssert($action, OutputJsonMock::E_EXIT);

        $result = $output->getOutput();

        $this->assertFalse(strpos($result['exec'], 'this.continue_search(') !== false,
            'past the total budget the client must not be asked to continue');
        $this->assertTrue(strpos($result['exec'], 'partial hit') !== false,
            'the results found so far must still be shown');
    }

    /**
     * Test search_input() method
     */
    function test_search_input()
    {
        $output = $this->initOutput(rcmail_action::MODE_AJAX, 'mail', 'search');

        $result = rcmail_action_mail_search::search_input('', 'subject,from', 'base', 'INBOX');
        $this->assertSame([], $result[0]);
        $this->assertSame('', $result[1]);

        $result = rcmail_action_mail_search::search_input('test', 'subject,from', 'base', 'INBOX');
        $this->assertSame(['subject' => 'HEADER SUBJECT', 'from' => 'HEADER FROM'], $result[0]);
        $this->assertSame('test', $result[1]);

        $result = rcmail_action_mail_search::search_input('test', null, 'base', 'INBOX');
        $this->assertSame(['subject' => 'HEADER SUBJECT'], $result[0]);
        $this->assertSame('test', $result[1]);

        $result = rcmail_action_mail_search::search_input('body:test', 'subject,from', 'base', 'INBOX');
        $this->assertSame(['body' => 'BODY'], $result[0]);
        $this->assertSame('test', $result[1]);

        $result = rcmail_action_mail_search::search_input('to:test', '', 'base', 'INBOX');
        $this->assertSame(['to' => 'HEADER TO'], $result[0]);
        $this->assertSame('test', $result[1]);

        $result = rcmail_action_mail_search::search_input('reply-to:test', '', 'base', 'INBOX');
        $this->assertSame(['reply-to' => 'HEADER REPLY-TO'], $result[0]);
        $this->assertSame('test', $result[1]);

        $result = rcmail_action_mail_search::search_input('test', 'from,invalid entry', 'base', 'INBOX');
        $this->assertSame(['from' => 'HEADER FROM'], $result[0]);
        $this->assertSame('test', $result[1]);

        $result = rcmail_action_mail_search::search_input('test', 'replyto', 'base', 'INBOX');
        $this->assertSame(['reply-to' => 'HEADER REPLY-TO', 'mail-reply-to' => 'HEADER MAIL-REPLY-TO'], $result[0]);
        $this->assertSame('test', $result[1]);
    }

    /**
     * Test data for test_search_interval_criteria()
     */
    function data_search_interval_criteria()
    {
        $week  = new DateInterval('P1W');
        $month = new DateInterval('P1M');
        $year  = new DateInterval('P1Y');

        return [
            ['', null],
            ['1W', 'SINCE ' . (new DateTime('now'))->sub($week)->format('j-M-Y')],
            ['1M', 'SINCE ' . (new DateTime('now'))->sub($month)->format('j-M-Y')],
            ['1Y', 'SINCE ' . (new DateTime('now'))->sub($year)->format('j-M-Y')],
            ['-1W', 'BEFORE ' . (new DateTime('now'))->sub($week)->format('j-M-Y')],
            ['-1M', 'BEFORE ' . (new DateTime('now'))->sub($month)->format('j-M-Y')],
            ['-1Y', 'BEFORE ' . (new DateTime('now'))->sub($year)->format('j-M-Y')],
        ];
    }

    /**
     * Test search_interval_criteria() method
     *
     * @dataProvider data_search_interval_criteria
     */
    function test_search_interval_criteria($input, $output)
    {
        $result = rcmail_action_mail_search::search_interval_criteria($input);
        $this->assertSame($output, $result);
    }
}
