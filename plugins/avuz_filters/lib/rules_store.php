<?php

/** All Postgres access for filters + per-folder state. conditions/actions are JSON text. */
class avuz_rules_store
{
    private $db;
    function __construct($db) { $this->db = $db; }

    function list_rules(int $user): array
    {
        $res = $this->db->query(
            'SELECT filter_id, name, enabled, match_type, priority, conditions, actions'
            . ' FROM avuz_filters WHERE user_id = ? ORDER BY priority, filter_id', $user);
        $rules = [];
        while ($r = $this->db->fetch_assoc($res)) {
            $r['conditions'] = json_decode($r['conditions'], true) ?: [];
            $r['actions']    = json_decode($r['actions'], true) ?: [];
            $rules[] = $r;
        }
        return $rules;
    }

    function save_rule(int $user, array $rule): int
    {
        $conds   = json_encode(array_values($rule['conditions'] ?? []));
        $actions = json_encode(array_values($rule['actions'] ?? []));
        $enabled = !empty($rule['enabled']) ? 1 : 0;
        $match   = ($rule['match_type'] ?? 'all') === 'any' ? 'any' : 'all';
        $prio    = (int) ($rule['priority'] ?? 0);
        $name    = (string) ($rule['name'] ?? 'Filter');

        if (!empty($rule['filter_id'])) {
            $this->db->query(
                'UPDATE avuz_filters SET name=?, enabled=?, match_type=?, priority=?, conditions=?, actions=?'
                . ' WHERE filter_id=? AND user_id=?',
                $name, $enabled, $match, $prio, $conds, $actions, (int) $rule['filter_id'], $user);
            return (int) $rule['filter_id'];
        }
        $res = $this->db->query(
            'INSERT INTO avuz_filters (user_id,name,enabled,match_type,priority,conditions,actions)'
            . ' VALUES (?,?,?,?,?,?,?) RETURNING filter_id',
            $user, $name, $enabled, $match, $prio, $conds, $actions);
        $row = $this->db->fetch_assoc($res);
        return (int) $row['filter_id'];
    }

    function delete_rule(int $user, int $id): void
    {
        $this->db->query('DELETE FROM avuz_filters WHERE filter_id=? AND user_id=?', $id, $user);
    }

    function get_state(int $user, string $folder): array
    {
        $res = $this->db->query(
            'SELECT last_uid, uidvalidity FROM avuz_filter_state WHERE user_id=? AND folder=?', $user, $folder);
        $r = $this->db->fetch_assoc($res);
        return $r ? ['exists'=>true, 'last_uid'=>(int)$r['last_uid'], 'uidvalidity'=>isset($r['uidvalidity'])?(int)$r['uidvalidity']:null]
                  : ['exists'=>false, 'last_uid'=>0, 'uidvalidity'=>null];
    }

    function set_state(int $user, string $folder, int $lastUid, ?int $uidv): void
    {
        // upsert
        $res = $this->db->query('UPDATE avuz_filter_state SET last_uid=?, uidvalidity=?, last_run=now()'
            . ' WHERE user_id=? AND folder=?', $lastUid, $uidv, $user, $folder);
        if (!$this->db->affected_rows($res)) {
            $this->db->query('INSERT INTO avuz_filter_state (user_id,folder,last_uid,uidvalidity,last_run)'
                . ' VALUES (?,?,?,?,now())', $user, $folder, $lastUid, $uidv);
        }
    }
}
