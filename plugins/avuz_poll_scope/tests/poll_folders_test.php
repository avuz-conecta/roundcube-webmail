<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/poll_folders.php';

class poll_folders_test extends TestCase
{
    private const ALLOW = ['Spam', 'Junk', 'Newsletter', 'Notification'];

    function testSelectsMatchingTopLevelFolders()
    {
        $subscribed = ['INBOX', 'Spam', 'Clientes', 'Newsletter'];
        $this->assertSame(
            ['Spam', 'Newsletter'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testMatchesNestedFoldersByLastSegment()
    {
        $subscribed = ['INBOX', 'INBOX/Newsletter', 'INBOX/2- FINANCEIRO'];
        $this->assertSame(
            ['INBOX/Newsletter'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testMatchingIsCaseInsensitive()
    {
        $subscribed = ['inbox/newsletter', 'SPAM'];
        $this->assertSame(
            ['inbox/newsletter', 'SPAM'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testHonoursServerHierarchyDelimiter()
    {
        $subscribed = ['INBOX.Newsletter', 'INBOX.Projetos'];
        $this->assertSame(
            ['INBOX.Newsletter'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '.', 6)
        );
    }

    function testExcludesAllowlistEntriesTheUserIsNotSubscribedTo()
    {
        $subscribed = ['INBOX', 'Spam'];
        $this->assertSame(
            ['Spam'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testTruncatesToTheCap()
    {
        $subscribed = ['a/Spam', 'b/Spam', 'c/Spam', 'd/Spam', 'e/Spam', 'f/Spam', 'g/Spam'];
        $this->assertCount(6, avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6));
    }

    function testEmptyAllowlistSelectsNothing()
    {
        $this->assertSame([], avuz_poll_folders::select(['INBOX', 'Spam'], [], '/', 6));
    }

    function testEmptyDelimiterFallsBackToWholeName()
    {
        $this->assertSame(['Spam'], avuz_poll_folders::select(['Spam'], self::ALLOW, '', 6));
    }
}
