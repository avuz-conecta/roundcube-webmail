# Avuz Filters (in-session) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Roundcube plugin (`avuz_filters`) that applies user-defined incoming-mail filters (move / mark-read / flag / delete) to INBOX using the user's own live IMAP session, triggered while they use webmail. No daemon, no stored credentials.

**Architecture:** Rules + per-folder progress stored in Postgres. A rule engine (pure PHP) evaluates message headers and returns the first matching rule's actions; an applier runs those via `rcube_storage`. Filter passes are triggered on `login_after` and `new_messages`, plus a manual "apply to existing inbox" action. Bounded to 1,000 messages + a time budget per pass.

**Tech Stack:** PHP 8.2, Roundcube plugin API (`rcube_plugin`), Postgres (via `$rcmail->get_dbh()`), Roundcube Elastic skin templates + JS.

**Spec:** `docs/superpowers/specs/2026-07-19-avuz-filters-design.md`

## Global Constraints

- **In-session only.** All work uses the user's authenticated IMAP connection (`$rcmail->get_storage()`); NEVER store or read mailbox passwords.
- **INBOX only.** V1 filters only the INBOX folder.
- **First-match.** A message receives only the first matching rule's actions.
- **Bounded pass:** max **1,000** messages per pass; also a wall-clock budget (default 5s) — whichever hits first commits `last_uid` and resumes next trigger.
- **Delete = move to Trash** (the configured `trash_mbox`), never hard-expunge.
- **Target DB is Postgres** (the migration is a prerequisite); DDL is Postgres.
- **Never block the UI:** any error in a pass is caught and logged; the message list still renders.
- Data model: `conditions` and `actions` are stored as JSON **text** (decoded in PHP) — no reliance on Postgres jsonb operators.
- Deploy on a **separate image tag** (`:filters`), never `:latest`, until proven on staging.
- Remove `'managesieve'` from `$config['plugins']`; add `'avuz_filters'`.

## File Structure

- `plugins/avuz_filters/avuz_filters.php` — plugin entry: hooks, actions, schema init.
- `plugins/avuz_filters/lib/rules_store.php` — CRUD for `avuz_filters` + state read/write (`avuz_filter_state`).
- `plugins/avuz_filters/lib/rule_engine.php` — pure: `match_message(headers, rules) → actions|null`.
- `plugins/avuz_filters/lib/filter_runner.php` — orchestrates one pass (search UID>last, fetch headers, engine, apply, advance).
- `plugins/avuz_filters/SQL/postgres.sql` — schema.
- `plugins/avuz_filters/avuz_filters.js` — settings UI behavior.
- `plugins/avuz_filters/skins/elastic/templates/filters.html` — settings page template.
- `plugins/avuz_filters/skins/elastic/filters.css` — minimal styling.
- `plugins/avuz_filters/localization/en_US.inc`, `pt_BR.inc` — labels.
- `plugins/avuz_filters/tests/rule_engine_test.php` — unit tests for the engine.
- `config/config.inc.php` — swap managesieve → avuz_filters (Modify).
- `Dockerfile` — `COPY plugins/avuz_filters …` (Modify).

---

### Task 1: Plugin skeleton + Postgres schema

**Files:**
- Create: `plugins/avuz_filters/avuz_filters.php`
- Create: `plugins/avuz_filters/SQL/postgres.sql`

**Interfaces:**
- Produces: class `avuz_filters extends rcube_plugin` with `init()`; `ensure_schema()` that idempotently creates the two tables.

- [ ] **Step 1: Write the schema**

`plugins/avuz_filters/SQL/postgres.sql`:
```sql
CREATE TABLE IF NOT EXISTS avuz_filters (
  filter_id  SERIAL PRIMARY KEY,
  user_id    INTEGER NOT NULL,
  name       VARCHAR(255) NOT NULL,
  enabled    SMALLINT NOT NULL DEFAULT 1,
  match_type VARCHAR(3) NOT NULL DEFAULT 'all',   -- 'all' | 'any'
  priority   INTEGER NOT NULL DEFAULT 0,
  conditions TEXT NOT NULL,                        -- JSON array
  actions    TEXT NOT NULL,                        -- JSON array
  created    TIMESTAMP DEFAULT now()
);
CREATE INDEX IF NOT EXISTS avuz_filters_user_idx ON avuz_filters (user_id, priority);

CREATE TABLE IF NOT EXISTS avuz_filter_state (
  user_id     INTEGER NOT NULL,
  folder      VARCHAR(255) NOT NULL,
  last_uid    INTEGER NOT NULL DEFAULT 0,
  uidvalidity INTEGER,
  last_run    TIMESTAMP,
  PRIMARY KEY (user_id, folder)
);
```

- [ ] **Step 2: Write the plugin entry with idempotent schema init**

`plugins/avuz_filters/avuz_filters.php`:
```php
<?php

/**
 * avuz_filters — in-session incoming-mail filters for Zoho (no daemon, no stored
 * credentials). Applies user rules to INBOX using the user's live IMAP session,
 * triggered on login and on new-mail refresh. See docs/superpowers/specs.
 */
class avuz_filters extends rcube_plugin
{
    public $task = 'mail|settings';

    function init()
    {
        $this->add_texts('localization/', true);
        $this->ensure_schema();

        // Triggers (in-session): first sort on login, then on new-mail refresh.
        $this->add_hook('login_after', [$this, 'on_login']);
        $this->add_hook('new_messages', [$this, 'on_new_messages']);

        // Settings UI + manual "apply to existing" action.
        $this->add_hook('settings_actions', [$this, 'settings_menu']);
        $this->register_action('plugin.avuz_filters', [$this, 'ui_index']);
        $this->register_action('plugin.avuz_filters.save', [$this, 'ui_save']);
        $this->register_action('plugin.avuz_filters.delete', [$this, 'ui_delete']);
        $this->register_action('plugin.avuz_filters.apply_existing', [$this, 'apply_existing']);
    }

    private function db() { return rcmail::get_instance()->get_dbh(); }

    /** Idempotent — CREATE TABLE IF NOT EXISTS from SQL/postgres.sql. */
    function ensure_schema()
    {
        static $done = false;
        if ($done) return;
        $db  = $this->db();
        $sql = file_get_contents(__DIR__ . '/SQL/postgres.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $db->query($stmt);
        }
        $done = true;
    }
}
```

- [ ] **Step 3: PHP lint**

Run: `php -l plugins/avuz_filters/avuz_filters.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add plugins/avuz_filters/avuz_filters.php plugins/avuz_filters/SQL/postgres.sql
git commit -m "feat(filters): plugin skeleton + postgres schema"
```

---

### Task 2: Rule engine (pure, TDD)

**Files:**
- Create: `plugins/avuz_filters/lib/rule_engine.php`
- Test: `plugins/avuz_filters/tests/rule_engine_test.php`

**Interfaces:**
- Produces: `avuz_rule_engine::match($headers, $rules) : ?array` — `$headers` = `['from'=>..,'to'=>..,'cc'=>..,'subject'=>..]` (lowercased strings), `$rules` = array of decoded rule rows ordered by priority. Returns the **first** matching rule's `actions` array, or `null`.

- [ ] **Step 1: Write the failing test**

`plugins/avuz_filters/tests/rule_engine_test.php`:
```php
<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../lib/rule_engine.php';

class AvuzRuleEngineTest extends TestCase
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
```

- [ ] **Step 2: Run it — expect failure**

Run: `vendor/bin/phpunit plugins/avuz_filters/tests/rule_engine_test.php`
Expected: FAIL (class `avuz_rule_engine` not found).

- [ ] **Step 3: Implement the engine**

`plugins/avuz_filters/lib/rule_engine.php`:
```php
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
```

- [ ] **Step 4: Run tests — expect pass**

Run: `vendor/bin/phpunit plugins/avuz_filters/tests/rule_engine_test.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_filters/lib/rule_engine.php plugins/avuz_filters/tests/rule_engine_test.php
git commit -m "feat(filters): rule engine (first-match, all/any) + tests"
```

---

### Task 3: Rules store (CRUD + state)

**Files:**
- Create: `plugins/avuz_filters/lib/rules_store.php`

**Interfaces:**
- Consumes: `$rcmail->get_dbh()`, `$rcmail->user->ID`.
- Produces: `avuz_rules_store` with: `list_rules(int $user):array` (decoded, ordered by priority), `save_rule(int $user, array $rule):int`, `delete_rule(int $user, int $id):void`, `get_state(int $user, string $folder):array` (`['last_uid'=>int,'uidvalidity'=>?int]`), `set_state(int $user, string $folder, int $lastUid, ?int $uidv):void`.

- [ ] **Step 1: Implement the store**

`plugins/avuz_filters/lib/rules_store.php`:
```php
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
```

- [ ] **Step 2: PHP lint**

Run: `php -l plugins/avuz_filters/lib/rules_store.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_filters/lib/rules_store.php
git commit -m "feat(filters): rules + state store (postgres CRUD)"
```

---

### Task 4: Filter runner (one pass over INBOX)

**Files:**
- Create: `plugins/avuz_filters/lib/filter_runner.php`

**Interfaces:**
- Consumes: `rcube_storage` (`$rcmail->get_storage()`), `avuz_rules_store`, `avuz_rule_engine`.
- Produces: `avuz_filter_runner::run(rcmail $rcmail, bool $from_scratch = false) : int` — processes INBOX messages with `UID > last_uid` (or all, if `$from_scratch`), applies first-match actions, advances `last_uid`, returns count acted-on. Bounded to 1000 msgs + 5s budget. Never throws (catches + logs).

- [ ] **Step 1: Implement the runner**

`plugins/avuz_filters/lib/filter_runner.php`:
```php
<?php
require_once __DIR__ . '/rule_engine.php';
require_once __DIR__ . '/rules_store.php';

class avuz_filter_runner
{
    const MAX_PER_PASS = 1000;
    const TIME_BUDGET  = 5.0; // seconds

    public static function run(rcmail $rcmail, bool $from_scratch = false): int
    {
        try {
            return self::do_run($rcmail, $from_scratch);
        } catch (\Throwable $e) {
            rcube::write_log('errors', 'avuz_filters: ' . $e->getMessage());
            return 0; // never break the UI
        }
    }

    private static function do_run(rcmail $rcmail, bool $from_scratch): int
    {
        $user  = (int) $rcmail->user->ID;
        $store = new avuz_rules_store($rcmail->get_dbh());
        $rules = $store->list_rules($user);
        if (!$rules) return 0;

        $storage = $rcmail->get_storage();
        $folder  = 'INBOX';
        $trash   = $rcmail->config->get('trash_mbox') ?: 'Trash';

        $state   = $store->get_state($user, $folder);
        $fdata   = $storage->folder_data($folder);
        $uidv    = isset($fdata['UIDVALIDITY']) ? (int) $fdata['UIDVALIDITY'] : null;
        $uidnext = isset($fdata['UIDNEXT'])     ? (int) $fdata['UIDNEXT']     : null;

        // COLD START (no state row) or UIDVALIDITY reset: seed the watermark to the
        // current top of the mailbox and process NOTHING. Existing/historical mail is
        // ONLY ever touched by the explicit "apply to existing" action (from_scratch).
        // Without this, first login would mass-move/-delete the entire inbox.
        $uidv_reset = $state['exists'] && $state['uidvalidity'] && $uidv && $state['uidvalidity'] !== $uidv;
        if (!$from_scratch && (!$state['exists'] || $uidv_reset)) {
            $seed = $uidnext ? $uidnext - 1 : 0;   // next new mail has UID >= UIDNEXT > seed
            $store->set_state($user, $folder, $seed, $uidv);
            return 0;
        }
        $last = $from_scratch ? 0 : $state['last_uid'];

        // UID search for new messages (or ALL when applying to existing on demand).
        $criteria = $from_scratch ? 'ALL' : ('UID ' . ($last + 1) . ':*');
        $index    = $storage->search_once($folder, $criteria);       // returns rcube_result_index
        $uids     = $index ? $index->get() : [];
        if (!$uids) { $store->set_state($user, $folder, $last, $uidv); return 0; }

        sort($uids, SORT_NUMERIC);
        $uids  = array_slice($uids, 0, self::MAX_PER_PASS);
        $start = microtime(true);
        $acted = 0; $maxUid = $last;

        // Fetch headers once for the batch.
        $headersList = $storage->fetch_headers($folder, $uids, false);
        foreach ($uids as $uid) {
            if (microtime(true) - $start > self::TIME_BUDGET) break;
            $maxUid = max($maxUid, (int) $uid);
            $h = $headersList[$uid] ?? null;
            if (!$h) continue;
            $hv = [
                'from'    => (string) $h->from,
                'to'      => (string) $h->to,
                'cc'      => (string) $h->cc,
                'subject' => (string) $h->subject,
            ];
            $actions = avuz_rule_engine::match($hv, $rules);
            if ($actions) {
                self::apply($storage, $folder, $trash, (int) $uid, $actions);
                $acted++;
            }
        }
        $store->set_state($user, $folder, $maxUid, $uidv);
        return $acted;
    }

    /**
     * Apply one rule's actions to a single UID via the live IMAP session.
     * Flags MUST be set while the message is still in INBOX; the move/delete is the
     * terminal action (removes it from INBOX), so it runs LAST regardless of the
     * order the actions were configured in. Prevents dropping later actions and
     * prevents flagging a message that already left the folder.
     */
    private static function apply($storage, string $folder, string $trash, int $uid, array $actions): void
    {
        $move_to = null;
        foreach ($actions as $a) {
            switch ($a['type']) {
                case 'mark_read': $storage->set_flag($uid, 'SEEN', $folder); break;
                case 'flag':      $storage->set_flag($uid, 'FLAGGED', $folder); break;
                case 'delete':    $move_to = $trash; break;                       // last-wins
                case 'move':      if (!empty($a['folder'])) $move_to = $a['folder']; break;
            }
        }
        if ($move_to !== null) {
            $storage->move_message($uid, $move_to, $folder); // terminal: removes from INBOX
        }
    }
}
```

API names verified in `program/lib/Roundcube/rcube_imap.php`: `search_once($folder, 'UID n:*')` returns an `rcube_result_index` (`->get()` → UID array); `folder_data($folder)['UIDVALIDITY']`; `fetch_headers($folder, $uids, false)` → `[uid => rcube_message_header]`; `move_message`, `set_flag($uid,'SEEN'|'FLAGGED',$folder)`.

- [ ] **Step 2: PHP lint**

Run: `php -l plugins/avuz_filters/lib/filter_runner.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_filters/lib/filter_runner.php
git commit -m "feat(filters): in-session INBOX filter runner (bounded, uidvalidity-safe)"
```

---

### Task 5: Wire triggers into the plugin

**Files:**
- Modify: `plugins/avuz_filters/avuz_filters.php`

**Interfaces:**
- Consumes: `avuz_filter_runner`.
- Produces: `on_login`, `on_new_messages`, `apply_existing` methods calling the runner.

- [ ] **Step 1: Add trigger methods**

Add to `avuz_filters.php` (require the runner at top of `init` via `require_once __DIR__.'/lib/filter_runner.php';`):
```php
    function on_login($args)
    {
        avuz_filter_runner::run(rcmail::get_instance(), false);
        return $args;
    }

    function on_new_messages($args)
    {
        // Fires on check-recent when the server reports new mail. Sort before render.
        avuz_filter_runner::run(rcmail::get_instance(), false);
        return $args;
    }

    function apply_existing()
    {
        $n = avuz_filter_runner::run(rcmail::get_instance(), true);
        $rcmail = rcmail::get_instance();
        $rcmail->output->show_message($rcmail->gettext(['name'=>'appliedn','vars'=>['n'=>$n]], 'avuz_filters'), 'confirmation');
        $rcmail->output->send();
    }
```

- [ ] **Step 2: PHP lint**

Run: `php -l plugins/avuz_filters/avuz_filters.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_filters/avuz_filters.php
git commit -m "feat(filters): triggers (login, new_messages, apply-existing)"
```

---

### Task 6: Settings UI (Filtros page)

**Files:**
- Modify: `plugins/avuz_filters/avuz_filters.php` (ui_index/ui_save/ui_delete/settings_menu)
- Create: `plugins/avuz_filters/skins/elastic/templates/filters.html`
- Create: `plugins/avuz_filters/avuz_filters.js`
- Create: `plugins/avuz_filters/skins/elastic/filters.css`

**Interfaces:**
- Consumes: `avuz_rules_store`.
- Produces: a Settings action `plugin.avuz_filters` rendering the rule list + editor; `ui_save`/`ui_delete` persist via the store; `settings_menu` adds the "Filtros" entry.

- [ ] **Step 1: Add the settings menu entry + UI actions**

Add to `avuz_filters.php`:
```php
    function settings_menu($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.avuz_filters',
            'class'  => 'filter',
            'label'  => 'filters',
            'title'  => 'filters',
            'domain' => 'avuz_filters',
        ];
        return $args;
    }

    function ui_index()
    {
        $rcmail = rcmail::get_instance();
        $this->include_script('avuz_filters.js');
        $this->include_stylesheet($this->local_skin_path() . '/filters.css');
        $store = new avuz_rules_store($rcmail->get_dbh());
        $rcmail->output->set_env('avuz_filters', $store->list_rules((int)$rcmail->user->ID));
        $rcmail->output->set_pagetitle($this->gettext('filters'));
        $rcmail->output->send('avuz_filters.filters');
    }

    function ui_save()
    {
        $rcmail = rcmail::get_instance();
        $raw = rcube_utils::get_input_value('_rule', rcube_utils::INPUT_POST, true);
        $rule = json_decode($raw, true) ?: [];
        (new avuz_rules_store($rcmail->get_dbh()))->save_rule((int)$rcmail->user->ID, $rule);
        $rcmail->output->command('plugin.avuz_filters_saved');
        $rcmail->output->send();
    }

    function ui_delete()
    {
        $rcmail = rcmail::get_instance();
        $id = (int) rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
        (new avuz_rules_store($rcmail->get_dbh()))->delete_rule((int)$rcmail->user->ID, $id);
        $rcmail->output->command('plugin.avuz_filters_deleted');
        $rcmail->output->send();
    }
```

- [ ] **Step 2: Write the template**

`plugins/avuz_filters/skins/elastic/templates/filters.html` — a Roundcube Elastic settings template with: a rules table (`id`, name, enabled toggle), an "Add filter" button, an editor form (name; match all/any; repeatable condition rows [field select from/to/cc/subject, op select contains/is, value text]; repeatable action rows [type select move/mark_read/flag/delete, folder select shown when type=move]); Save + Delete buttons; and an **"Apply to existing inbox now"** button posting to `plugin.avuz_filters.apply_existing`. Use `<roundcube:object name="message" />` for notices. Model markup/classes on `plugins/managesieve/skins/elastic/templates/filteredit.html`.

- [ ] **Step 3: Write the JS**

`plugins/avuz_filters/avuz_filters.js` — renders `rcmail.env.avuz_filters` into the table; wires Add/Edit/Delete; builds the `_rule` JSON from the form and posts to `plugin.avuz_filters.save`; handles `plugin.avuz_filters_saved`/`_deleted` to refresh; folder select populated from `rcmail.env.mailboxes`. Register buttons with `rcmail.register_command`.

- [ ] **Step 4: Manual verification on staging (after Task 9 deploy)**

Load Settings → Filtros; create a rule (from contains X → move to a folder); save; confirm it persists (reload). This UI is exercised end-to-end in Task 9.

- [ ] **Step 5: Commit**

```bash
git add plugins/avuz_filters/avuz_filters.php plugins/avuz_filters/skins plugins/avuz_filters/avuz_filters.js
git commit -m "feat(filters): settings UI (rules CRUD + apply-existing)"
```

---

### Task 7: Localization

**Files:**
- Create: `plugins/avuz_filters/localization/en_US.inc`, `plugins/avuz_filters/localization/pt_BR.inc`

**Interfaces:**
- Produces: labels used by the UI + notices (`filters`, `addfilter`, `matchall`, `matchany`, `movetofolder`, `markread`, `flagmsg`, `deletemsg`, `applyexisting`, `appliedn`, field/op names).

- [ ] **Step 1: Write en_US**

`plugins/avuz_filters/localization/en_US.inc`:
```php
<?php
$labels['filters']      = 'Filters';
$labels['addfilter']    = 'Add filter';
$labels['matchall']     = 'Match all of the following';
$labels['matchany']     = 'Match any of the following';
$labels['field_from']   = 'From';
$labels['field_to']     = 'To';
$labels['field_cc']     = 'Cc';
$labels['field_subject']= 'Subject';
$labels['op_contains']  = 'contains';
$labels['op_is']        = 'is';
$labels['movetofolder'] = 'Move to folder';
$labels['markread']     = 'Mark as read';
$labels['flagmsg']      = 'Flag message';
$labels['deletemsg']    = 'Delete (to Trash)';
$labels['applyexisting']= 'Apply to existing inbox now';
$labels['appliedn']     = 'Filters applied to $n message(s).';
$labels['zohonote']     = 'Manage filters here OR in Zoho webmail — not both.';
```

- [ ] **Step 2: Write pt_BR** (same keys, translated: Filtros, Adicionar filtro, "Corresponder a todas…", De/Para/Cc/Assunto, contém/é, Mover para pasta, Marcar como lida, Sinalizar, Excluir (para Lixeira), "Aplicar à caixa de entrada agora", "Filtros aplicados a $n mensagem(ns).", note).

- [ ] **Step 3: Commit**

```bash
git add plugins/avuz_filters/localization
git commit -m "feat(filters): en_US + pt_BR localization"
```

---

### Task 8: Swap managesieve → avuz_filters + bundle in image

**Files:**
- Modify: `config/config.inc.php`
- Modify: `Dockerfile`

**Interfaces:**
- Produces: plugin enabled + copied into the image; managesieve disabled.

- [ ] **Step 1: Edit the plugins list**

In `config/config.inc.php`, in `$config['plugins']`, remove `'managesieve'` and add `'avuz_filters'`.

- [ ] **Step 2: Overlay the plugin in the Dockerfile**

Add to `Dockerfile` (with the other plugin COPYs):
```dockerfile
COPY plugins/avuz_filters /var/www/roundcube/plugins/avuz_filters
```

- [ ] **Step 3: Lint config**

Run: `php -l config/config.inc.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add config/config.inc.php Dockerfile
git commit -m "feat(filters): enable avuz_filters, disable managesieve, bundle in image"
```

---

### Task 9: Build on `:filters` tag + validate on staging

**Files:** none (build/deploy/verify).

**Interfaces:** staging stack `avuz-mail-roundcube-2` (endpoint 3) which already runs Postgres.

- [ ] **Step 1: Build + push the `:filters` tag**

The prod/staging build scripts tag by env; for an isolated tag, build the app image directly:
```bash
docker buildx build --platform linux/amd64 \
  --build-arg BASE_IMAGE=registry.avuz.app/admin/avuz-roundcube-base:staging \
  -t registry.avuz.app/admin/avuz-roundcube:filters --load .
docker push registry.avuz.app/admin/avuz-roundcube:filters
```
Expected: pushed `:filters`.

- [ ] **Step 2: Point the staging roundcube at `:filters` + redeploy**

Update the staging stack's roundcube image to `…/avuz-roundcube:filters` (via `scripts/migrate/stack-add-postgres.sh`-style stack edit or Portainer), redeploy. Confirm healthy.

- [ ] **Step 3: Verify schema created**

Run: `export ROUNDCUBE_PG_PASSWORD=<staging-pw>; PORTAINER_ENV_FILE=scripts/deploy.env scripts/migrate/pg-oneshot.sh 3 avuz-mail-roundcube-2_default postgres:16-alpine - "PGPASSWORD='$ROUNDCUBE_PG_PASSWORD' psql 'postgresql://roundcube@postgres:5432/roundcube' -tAc \"SELECT to_regclass('public.avuz_filters'), to_regclass('public.avuz_filter_state');\""`
Expected: both tables exist.

- [ ] **Step 4: End-to-end functional test (manual, via UI)**

Log in on staging. Settings → Filtros → create rule: `subject contains [TEST] → move to a test folder`. Send yourself an email with `[TEST]` in the subject. Refresh inbox. Expected: message is moved to the test folder (not in INBOX). Then create `from contains <someone> → mark read`, use "Apply to existing inbox now", confirm matching mail marked read.

- [ ] **Step 5: Confirm no errors + managesieve gone**

Run: `scripts/logs.sh avuz-mail-roundcube-2-roundcube-1 errors grep "avuz_filters"` (expect none) and confirm the Filters menu no longer throws the managesieve connection error.

- [ ] **Step 6: Verify (project skill) — drive the real flow**

Exercise the filter end-to-end in the running app (create rule → deliver mail → observe move), not just unit tests. This is the acceptance gate before considering prod.

---

## Notes for the executor

- **Prerequisite:** Postgres must be the DB (migration done on staging; prod after cutover). The plugin's `ensure_schema()` needs a Postgres connection.
- IMAP APIs used by the runner are verified in `program/lib/Roundcube/rcube_imap.php`: `search_once`, `folder_data`, `fetch_headers`, `move_message`, `set_flag`.
- **`:filters` tag is isolated** — prod `:latest` is never touched by this work until you decide to fold it in.
- The `new_messages` hook fires on check-recent; if in practice it doesn't fire often enough, also hook `refresh`. Both are in-request and safe (bounded runner).
