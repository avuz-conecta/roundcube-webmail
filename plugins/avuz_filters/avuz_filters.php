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
