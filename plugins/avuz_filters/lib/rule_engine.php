<?php

/** Pure rule matcher. No I/O. First-match semantics. */
class avuz_rule_engine
{
    /**
     * @param array $headers ['from','to','cc','subject'] lowercased
     * @param array $rules   decoded rows: ['enabled','match_type','conditions','actions']
     * @return array|null    first matching rule's actions, or null
     */
    public static function match(array $headers, array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $conds = $rule['conditions'];
            $all   = ($rule['match_type'] ?? 'all') === 'all';
            $ok    = $all;
            foreach ($conds as $c) {
                $hit = self::cond_hit($headers, $c);
                if ($all && !$hit) { $ok = false; break; }
                if (!$all && $hit) { $ok = true;  break; }
            }
            if (!empty($conds) && $ok) {
                return $rule['actions'];
            }
        }
        return null;
    }

    private static function cond_hit(array $headers, array $c): bool
    {
        $hay = (string) ($headers[$c['field']] ?? '');
        $val = mb_strtolower((string) $c['value']);
        $hay = mb_strtolower($hay);
        return $c['op'] === 'is' ? ($hay === $val) : (strpos($hay, $val) !== false);
    }
}
