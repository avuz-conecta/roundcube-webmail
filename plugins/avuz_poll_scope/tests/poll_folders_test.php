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
        $this->assertSame(
            ['a/Spam', 'b/Spam', 'c/Spam', 'd/Spam', 'e/Spam', 'f/Spam'],
            avuz_poll_folders::select($subscribed, self::ALLOW, '/', 6)
        );
    }

    function testDoesNotMatchNonLastPathSegment()
    {
        $this->assertSame(
            [],
            avuz_poll_folders::select(['Newsletter/Old', 'Spam/Archive'], self::ALLOW, '/', 6)
        );
    }

    function testEmptyAllowlistSelectsNothing()
    {
        $this->assertSame([], avuz_poll_folders::select(['INBOX', 'Spam'], [], '/', 6));
    }

    function testEmptyDelimiterFallsBackToWholeName()
    {
        $this->assertSame(['Spam'], avuz_poll_folders::select(['Spam'], self::ALLOW, '', 6));
    }

    // --- merge() -------------------------------------------------------

    function testMergeAllTrueReplacesCoresListWithInboxCurrentAndAllowlist()
    {
        $core = array_map(static fn ($i) => "Folder$i", range(1, 107));

        $this->assertSame(
            ['INBOX', 'Projetos', 'Spam', 'Newsletter'],
            avuz_poll_folders::merge($core, true, 'Projetos', ['Spam', 'Newsletter'])
        );
    }

    function testMergeAllFalsePreservesSearchFoldersVerbatimAndInOrder()
    {
        // This is the exact regression that shipped: an open search's folder
        // set must survive untouched, in order, when $all is false.
        $search = ['Clientes', 'INBOX', 'Projetos/2026', 'Arquivo'];

        $result = avuz_poll_folders::merge($search, false, 'Clientes', ['Spam']);

        $this->assertSame(['Clientes', 'INBOX', 'Projetos/2026', 'Arquivo', 'Spam'], $result);
    }

    function testMergeAllFalseWithEmptyAllowlistReturnsCoresListUnchanged()
    {
        $core = ['Clientes', 'INBOX', 'Projetos/2026'];

        $this->assertSame($core, avuz_poll_folders::merge($core, false, 'Clientes', []));
    }

    function testMergeAllFalseAppendsAllowlistWithoutRemovingAnything()
    {
        $core = ['INBOX', 'Projetos'];

        $this->assertSame(
            ['INBOX', 'Projetos', 'Spam', 'Newsletter'],
            avuz_poll_folders::merge($core, false, 'Projetos', ['Spam', 'Newsletter'])
        );
    }

    function testMergeAllTrueWithEmptyCurrentOmitsEmptyStringEntry()
    {
        $this->assertSame(
            ['INBOX', 'Spam'],
            avuz_poll_folders::merge(['irrelevant'], true, '', ['Spam'])
        );
    }

    function testMergeDeduplicatesKeepingFirstOccurrence()
    {
        $core = ['INBOX', 'Spam'];

        $this->assertSame(
            ['INBOX', 'Spam', 'Newsletter'],
            avuz_poll_folders::merge($core, false, 'INBOX', ['Spam', 'Newsletter', 'INBOX'])
        );
    }
}
