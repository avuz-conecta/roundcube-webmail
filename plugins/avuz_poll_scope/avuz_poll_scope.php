<?php

require_once __DIR__ . '/lib/poll_folders.php';

/**
 * avuz_poll_scope — bound how many folders new-mail polling touches.
 *
 * Roundcube polls folders in two places, and only one of them is extensible:
 *
 *   - check_recent.php walks every subscribed folder issuing STATUS + SELECT +
 *     UID SEARCH per folder. It exposes a 'check_recent' hook (line 66).
 *   - getunread.php iterates every subscribed folder too, and has NO hooks at
 *     all. Its cheap cached path is gated on the same check_all_folders flag.
 *
 * So filtering the hook alone would leave getunread walking everything. Instead
 * we neutralise the flag itself on 'ready' (rcmail.php:228, after the user is
 * authenticated and before any action runs), which puts BOTH paths on their
 * cheap branch, and then re-add a bounded allowlist in the hook for users who
 * had the preference on.
 *
 * Measured motivation: one user with 107 folders produced ~321 serialized IMAP
 * commands per refresh and refreshes of 20-136s, every two minutes, each pinning
 * a PHP worker. The explicit check-recent action was 43s on average for ALL
 * users, because check_recent.php:42 forces check_all true for any action that
 * is not 'refresh'.
 *
 * See docs/superpowers/specs/2026-07-22-folder-poll-scope-design.md
 */
class avuz_poll_scope extends rcube_plugin
{
    public $task = 'mail';

    /** The user's real preference, read before we overwrite it. */
    private $user_wants_all = false;

    function init()
    {
        $this->add_hook('ready', [$this, 'neutralise_flag']);
        $this->add_hook('check_recent', [$this, 'bound_folders']);
    }

    /**
     * Stash the user's preference and force the flag off, so core's own code
     * takes the cheap path in check_recent AND getunread.
     */
    function neutralise_flag($args)
    {
        $rcmail = rcmail::get_instance();

        $this->user_wants_all = (bool) $rcmail->config->get('check_all_folders');
        $rcmail->config->set('check_all_folders', false);

        return $args;
    }

    /**
     * Bound the folder list check_recent.php builds. Applied for every action,
     * not just 'refresh': check_recent.php:42 forces check_all true whenever the
     * action is not 'refresh', which is why the manual check-recent cost 43s for
     * every user regardless of their preference.
     *
     * check_recent.php:52-63 builds $args['folders'] one of three ways, and it
     * tells us which one via $args['all']:
     *
     *   (a) $args['all'] === true  — check_all_folders (or a non-refresh action)
     *       walked EVERY subscribed folder. This is the expensive case we exist
     *       to bound, so we REPLACE the list with {current, INBOX} plus the
     *       allowlist, same as before.
     *   (b) $args['all'] === false, an active search is open — the list is
     *       exactly the folders that search covers (check_recent.php:55; for a
     *       single-folder search, get_parameters('MAILBOX') returns a plain
     *       string, which core casts to array — this branch is not limited to
     *       all-folders searches), so new matching mail in any of them can
     *       appear in the results. Removing folders here silently stales the
     *       search results.
     *   (c) $args['all'] === false, no search — the list is already just
     *       {current, INBOX}.
     *
     * Cases (b) and (c) are both core's own deliberate, already-bounded choice —
     * we must not strip anything out of $args['folders'] in either. In the false
     * case, adding the user's allowlist on top is ALL we do; we never remove
     * from what core built. Do not "simplify" this back to an unconditional
     * replace: that's the bug this comment exists to prevent.
     *
     * The actual branch decision above is a pure function — avuz_poll_folders::
     * merge() — so it can be unit tested. This method only gathers the inputs
     * (subscribed list, delimiter, allowlist, current folder) and delegates.
     *
     * Accepted trade-off: the manual "Check for new mail" request also carries
     * _search (app.js check_recent_params(), ~line 9797), and check_recent.php:42
     * forces check_all true for any non-'refresh' action — so an open search plus
     * a manual check takes branch (a), not (b), for that one request: the
     * search's folders aren't polled and refresh_search() doesn't fire. This is
     * bounded staleness, not a bug — the next periodic refresh takes branch (b)
     * and picks it up. Do not "fix" this by weakening the bound.
     */
    function bound_folders($args)
    {
        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $current = (string) $storage->get_folder();

        $allowlist_folders = [];
        if ($this->user_wants_all) {
            $allowlist = (array) $rcmail->config->get('avuz_poll_folders', []);

            if (!empty($allowlist)) {
                $allowlist_folders = avuz_poll_folders::select(
                    (array) $storage->list_folders_subscribed('', '*', 'mail'),
                    $allowlist,
                    (string) $storage->get_hierarchy_delimiter(),
                    avuz_poll_folders::CAP
                );
            }
        }

        $args['folders'] = avuz_poll_folders::merge(
            (array) $args['folders'],
            !empty($args['all']),
            $current,
            $allowlist_folders
        );

        return $args;
    }
}
