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
        require_once __DIR__ . '/lib/filter_runner.php'; // pulls in rule_engine + rules_store
        require_once __DIR__ . '/lib/run_guard.php';
        $this->add_texts('localization/', true);
        $this->ensure_schema();

        // Triggers (in-session): first sort on login, then on every mail refresh.
        //
        // We hook 'check_recent' AND 'refresh' — never 'new_messages'. Ordering,
        // in program/actions/mail/check_recent.php's single run():
        //   line  66  exec_hook('check_recent', ...)  BEFORE the per-folder loop
        //   line  96  exec_hook('new_messages', ...)  INSIDE the loop, before the
        //             INBOX message list is built
        //   line 207  exec_hook('refresh', ...)        AFTER the loop and the list
        //
        // 'check_recent' is primary: it runs before the loop reads any folder
        // status or message count, so our UID MOVE completes first and the loop
        // builds the list from already-consistent state — a message filed by a
        // rule is gone from INBOX and present in its destination on the FIRST
        // refresh, with no stale-count side effect to worry about.
        //
        // 'refresh' stays registered as a fallback: it also fires on its own from
        // program/include/rcmail.php:292, for plain 'refresh' actions that don't
        // post '_folderlist' or '_list' — a request check_recent.php's run()
        // exits before line 36 without touching any hook. Keeping 'refresh' means
        // the filter pass still runs on those requests. avuz_run_guard makes the
        // two registrations mutually exclusive per request, and since
        // 'check_recent' (line 66) always fires before 'refresh' (line 207)
        // whenever both apply, 'check_recent' wins and 'refresh' is a no-op then.
        //
        // 'new_messages' must NEVER be re-added: it fires mid-loop, after
        // check_recent.php has already cached a per-folder count from the
        // pre-move state (line 88) and before the list is built. Filing a
        // message out of INBOX there invalidates that cached count for INBOX;
        // the later cached count() read at line 129 then returns 0 and
        // message_list.clear(true) wipes the list with nothing to repopulate it
        // (reproduced: INBOX went empty for one refresh cycle, fixed only by a
        // manual refresh; confirmed on the wire — that request issued no
        // UID SEARCH ALL at all). 'check_recent' gets the same "before the user
        // sees stale mail" benefit without that hazard, because it runs before
        // any folder status or count is read at all.
        $this->add_hook('login_after', [$this, 'on_login']);
        $this->add_hook('check_recent', [$this, 'on_new_messages']);
        $this->add_hook('refresh', [$this, 'on_new_messages']);

        // Settings UI + manual "apply to existing" action.
        $this->add_hook('settings_actions', [$this, 'settings_menu']);
        $this->register_action('plugin.avuz_filters', [$this, 'ui_index']);
        $this->register_action('plugin.avuz_filters.save', [$this, 'ui_save']);
        $this->register_action('plugin.avuz_filters.delete', [$this, 'ui_delete']);
        $this->register_action('plugin.avuz_filters.apply_existing', [$this, 'apply_existing']);
    }

    private function db() { return rcmail::get_instance()->get_dbh(); }

    function on_login($args)
    {
        // login_after can share a request with refresh/new_messages (e.g. a
        // login that immediately triggers a check-recent); claim the same
        // token so the pass still runs only once.
        if (!avuz_run_guard::claim('filters')) {
            return $args;
        }

        avuz_filter_runner::run(rcmail::get_instance(), false);
        return $args;
    }

    function on_new_messages($args)
    {
        // Both 'new_messages' and 'refresh' fire in the same request; run once.
        if (!avuz_run_guard::claim('filters')) {
            return $args;
        }

        // Fires on check-recent when the server reports new mail. Sort before render.
        avuz_filter_runner::run(rcmail::get_instance(), false);
        return $args;
    }

    function apply_existing()
    {
        $rcmail = rcmail::get_instance();
        $n = avuz_filter_runner::run($rcmail, true);
        $rcmail->output->show_message($rcmail->gettext(['name'=>'appliedn','vars'=>['n'=>$n]], 'avuz_filters'), 'confirmation');
        $rcmail->output->send();
    }

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
        // Rule data for the editor (client builds the form from this).
        $rcmail->output->set_env('avuz_filters', $store->list_rules((int) $rcmail->user->ID));

        // Native folder <select> prototype (proper localized folder NAMES, not ids).
        $sel = rcmail_action::folder_selector(['name' => '_af_folder', 'class' => 'af-afolder', 'maxlength' => 100]);
        $rcmail->output->set_env('avuz_folder_select', $sel->show());

        $rcmail->output->add_handler('avuzfilterslist', [$this, 'filters_list']);
        $rcmail->output->add_label('avuz_filters.deleteconfirm', 'avuz_filters.needcondaction', 'avuz_filters.nofilters');
        $rcmail->output->set_pagetitle($this->gettext('filters'));
        $rcmail->output->send('avuz_filters.filters');
    }

    /** Native Roundcube list of filters (rcube_list_widget on the client). */
    function filters_list($attrib)
    {
        $rcmail = rcmail::get_instance();
        if (empty($attrib['id'])) $attrib['id'] = 'avuz-filterslist';
        $store = new avuz_rules_store($rcmail->get_dbh());
        $rows  = [];
        foreach ($store->list_rules((int) $rcmail->user->ID) as $r) {
            $rows[] = ['id' => $r['filter_id'], 'name' => $r['name'],
                       'class' => $r['enabled'] ? '' : 'disabled'];
        }
        $out = rcmail_action::table_output($attrib, $rows, ['name'], 'id');
        $rcmail->output->add_gui_object('filterslist', $attrib['id']);
        $rcmail->output->include_script('list.js');
        return $out;
    }

    function ui_save()
    {
        $rcmail = rcmail::get_instance();
        $raw  = rcube_utils::get_input_value('_rule', rcube_utils::INPUT_POST, true);
        $rule = json_decode($raw, true) ?: [];
        $id   = (new avuz_rules_store($rcmail->get_dbh()))->save_rule((int) $rcmail->user->ID, $rule);
        $rcmail->output->command('plugin.avuz_filters_saved', ['id' => $id]);
        $rcmail->output->send();
    }

    function ui_delete()
    {
        $rcmail = rcmail::get_instance();
        $id = (int) rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
        (new avuz_rules_store($rcmail->get_dbh()))->delete_rule((int) $rcmail->user->ID, $id);
        $rcmail->output->command('plugin.avuz_filters_deleted', ['id' => $id]);
        $rcmail->output->send();
    }

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
