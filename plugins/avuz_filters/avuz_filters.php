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
        // 'refresh' fires on the periodic + manual refresh reliably; 'new_messages'
        // only fires when check_recent detects a status diff (not always), so we use
        // 'refresh' as the primary trigger and keep 'new_messages' as a bonus.
        $this->add_hook('login_after', [$this, 'on_login']);
        $this->add_hook('new_messages', [$this, 'on_new_messages']);
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
