<?php

/**
 * avuz_receipt_cleanup — moves sent read receipts (MDNs) out of Sent/Outbox
 * into Trash. Zoho saves every SMTP submission to Sent server-side, so each
 * receipt a user sends (from any client) lands there and buries real mail.
 *
 * Runs in-session like avuz_filters: over the user's own live IMAP session on
 * the periodic mail refresh. No daemon, no stored passwords. Never deletes —
 * only moves to Trash. See
 * docs/superpowers/specs/2026-09-23-login-password-toggle-and-receipt-cleanup-design.md
 */
class avuz_receipt_cleanup extends rcube_plugin
{
    public $task = 'mail';

    const CURSORS_PREF         = 'avuz_receipt_cleanup';
    const NEXT_RUN_SESSION_KEY = 'avuz_receipt_cleanup_next_run_at';
    const EXTRA_FOLDERS_CONFIG = 'avuz_receipt_cleanup_extra_folders';
    const LOG_NAME             = 'avuz_receipt_cleanup';

    /** One pass per request: check_recent and refresh both fire on a refresh. */
    private static $claimed = false;

    function init()
    {
        require_once __DIR__ . '/lib/cleanup_plan.php';
        require_once __DIR__ . '/lib/imap_receipt_mailbox.php';

        // Refresh only, not login_after: a backlog batch must not slow the login
        // redirect, and SSO logins never fire login_after anyway.
        $this->add_hook('check_recent', [$this, 'on_refresh']);
        $this->add_hook('refresh', [$this, 'on_refresh']);
    }

    function on_refresh($args)
    {
        if (self::$claimed) {
            return $args;
        }
        self::$claimed = true;

        $now = time();
        if (!avuz_cleanup_plan::is_due($now, $_SESSION[self::NEXT_RUN_SESSION_KEY] ?? null)) {
            return $args;
        }

        $pending = self::guarded('pass', false, function () {
            return $this->sweep_all(rcmail::get_instance());
        });
        $_SESSION[self::NEXT_RUN_SESSION_KEY] = avuz_cleanup_plan::next_run_at($now, $pending);

        return $args;
    }

    /** @return bool whether a backlog remains in any folder */
    private function sweep_all(rcmail $rcmail): bool
    {
        if (!$rcmail->storage_connect()) {
            return false;
        }

        $storage = $rcmail->get_storage();
        $trash   = (string) $rcmail->config->get('trash_mbox');
        if ($trash === '' || !$storage->folder_exists($trash)) {
            return false;
        }

        $folders = avuz_cleanup_plan::folders(
            (string) $rcmail->config->get('sent_mbox'),
            self::extra_folders($rcmail),
            $trash,
            function ($folder) use ($storage) {
                return $storage->folder_exists($folder);
            }
        );

        $mailbox = new avuz_imap_receipt_mailbox($storage);
        $cursors = (array) $rcmail->config->get(self::CURSORS_PREF, []);
        $updated = $cursors;
        $pending = false;

        foreach ($folders as $folder) {
            $result = self::guarded("folder=$folder", null, function () use ($mailbox, $folder, $trash, $cursors) {
                return avuz_receipt_sweeper::sweep($mailbox, $folder, $trash, $cursors[$folder] ?? null);
            });
            if ($result === null) {
                continue;
            }

            $updated[$folder] = $result['cursor'];
            $pending = $pending || $result['pending'];
            if ($result['moved'] > 0) {
                rcube::write_log(self::LOG_NAME, sprintf(
                    'user=%s folder=%s moved=%d to=%s', $rcmail->get_user_name(), $folder, $result['moved'], $trash
                ));
            }
        }

        if ($updated !== $cursors) {
            $rcmail->user->save_prefs([self::CURSORS_PREF => $updated]);
        }

        return $pending;
    }

    /** Configured as readable UTF-8; the storage layer speaks UTF7-IMAP. */
    private static function extra_folders(rcmail $rcmail): array
    {
        return array_map(function ($folder) {
            return rcube_charset::convert((string) $folder, RCUBE_CHARSET, 'UTF7-IMAP');
        }, (array) $rcmail->config->get(self::EXTRA_FOLDERS_CONFIG, []));
    }

    /** Runs $operation; logs and returns $fallback on failure. Cleanup must never break the UI. */
    private static function guarded(string $context, $fallback, callable $operation)
    {
        try {
            return $operation();
        } catch (\Throwable $error) {
            rcube::write_log('errors', sprintf('%s: %s failed: %s', self::LOG_NAME, $context, $error->getMessage()));
            return $fallback;
        }
    }
}
