<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/cleanup_plan.php';

class cleanup_plan_folders_test extends TestCase
{
    private function folders(string $sent, array $extras, array $existing, string $trash = 'Lixeira'): array
    {
        return avuz_cleanup_plan::folders($sent, $extras, $trash, function ($folder) use ($existing) {
            return in_array($folder, $existing, true);
        });
    }

    function testSweepsTheSentFolder()
    {
        $this->assertSame(['Enviadas'], $this->folders('Enviadas', [], ['Enviadas']));
    }

    function testAddsOutboxFoldersThatExist()
    {
        $folders = $this->folders('Enviadas', ['Outbox', 'Caixa de saída'], ['Enviadas', 'Caixa de saída']);
        $this->assertSame(['Enviadas', 'Caixa de saída'], $folders);
    }

    function testSkipsDuplicatesAndBlanks()
    {
        $this->assertSame(['Sent'], $this->folders('Sent', ['Sent', ''], ['Sent']));
    }

    function testNeverSweepsTheTrashItself()
    {
        $this->assertSame([], $this->folders('Lixeira', ['Lixeira'], ['Lixeira']));
    }
}

class cleanup_plan_schedule_test extends TestCase
{
    function testIsDueOnFirstRun()
    {
        $this->assertTrue(avuz_cleanup_plan::is_due(1000, null));
    }

    function testWaitsUntilTheNextRunTime()
    {
        $this->assertFalse(avuz_cleanup_plan::is_due(1000, 1001));
        $this->assertTrue(avuz_cleanup_plan::is_due(1001, 1001));
    }

    function testRunsAgainImmediatelyWhileBacklogRemains()
    {
        $this->assertSame(1000, avuz_cleanup_plan::next_run_at(1000, true));
    }

    function testWaitsTheIdleIntervalOnceCaughtUp()
    {
        $this->assertSame(1000 + avuz_cleanup_plan::IDLE_INTERVAL_SECONDS, avuz_cleanup_plan::next_run_at(1000, false));
    }
}
