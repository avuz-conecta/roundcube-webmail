<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/filter_runner.php';

class filter_runner_test extends TestCase
{
    function testReturnsNullWhenNoTerminalAction()
    {
        $actions = [['type' => 'mark_read'], ['type' => 'flag']];
        $this->assertNull(avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testMoveReturnsItsFolder()
    {
        $actions = [['type' => 'move', 'folder' => 'Clientes']];
        $this->assertSame('Clientes', avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testDeleteReturnsTrashFolder()
    {
        $actions = [['type' => 'delete']];
        $this->assertSame('Lixeira', avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testLastTerminalActionWins()
    {
        $actions = [['type' => 'move', 'folder' => 'Clientes'], ['type' => 'delete']];
        $this->assertSame('Lixeira', avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testMoveWithEmptyFolderIsIgnored()
    {
        $actions = [['type' => 'move', 'folder' => '']];
        $this->assertNull(avuz_filter_runner::move_target($actions, 'Lixeira'));
    }

    function testForwardIsNotTerminal()
    {
        $actions = [['type' => 'forward', 'to' => 'a@b.com']];
        $this->assertNull(avuz_filter_runner::move_target($actions, 'Lixeira'));
    }
}
