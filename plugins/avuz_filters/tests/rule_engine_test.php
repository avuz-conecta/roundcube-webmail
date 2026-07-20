<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/rule_engine.php';

class rule_engine_test extends TestCase
{
    private function rule($match, $conds, $actions, $enabled = 1) {
        return ['enabled'=>$enabled, 'match_type'=>$match, 'conditions'=>$conds, 'actions'=>$actions];
    }

    function testContainsMatchReturnsActions() {
        $rules = [$this->rule('all',
            [['field'=>'from','op'=>'contains','value'=>'boss@corp']],
            [['type'=>'move','folder'=>'Work']])];
        $h = ['from'=>'the boss@corp.com', 'to'=>'', 'cc'=>'', 'subject'=>'hi'];
        $this->assertSame([['type'=>'move','folder'=>'Work']], avuz_rule_engine::match($h, $rules));
    }

    function testMatchAllRequiresEveryCondition() {
        $rules = [$this->rule('all',
            [['field'=>'from','op'=>'contains','value'=>'boss'],
             ['field'=>'subject','op'=>'contains','value'=>'urgent']],
            [['type'=>'flag']])];
        $this->assertNull(avuz_rule_engine::match(
            ['from'=>'boss@x','to'=>'','cc'=>'','subject'=>'lunch'], $rules));
    }

    function testMatchAnyNeedsOne() {
        $rules = [$this->rule('any',
            [['field'=>'from','op'=>'is','value'=>'a@x.com'],
             ['field'=>'subject','op'=>'contains','value'=>'sale']],
            [['type'=>'delete']])];
        $this->assertSame([['type'=>'delete']], avuz_rule_engine::match(
            ['from'=>'z@y.com','to'=>'','cc'=>'','subject'=>'big SALE today'], $rules));
    }

    function testFirstMatchWins() {
        $rules = [
            $this->rule('any', [['field'=>'subject','op'=>'contains','value'=>'x']], [['type'=>'flag']]),
            $this->rule('any', [['field'=>'subject','op'=>'contains','value'=>'x']], [['type'=>'delete']]),
        ];
        $this->assertSame([['type'=>'flag']], avuz_rule_engine::match(
            ['from'=>'','to'=>'','cc'=>'','subject'=>'xy'], $rules));
    }

    function testDisabledRuleSkipped() {
        $rules = [$this->rule('any', [['field'=>'subject','op'=>'contains','value'=>'x']], [['type'=>'flag']], 0)];
        $this->assertNull(avuz_rule_engine::match(['from'=>'','to'=>'','cc'=>'','subject'=>'x'], $rules));
    }
}
