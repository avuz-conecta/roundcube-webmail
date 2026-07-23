<?php

/**
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Mail messages search action                                         |
 +-----------------------------------------------------------------------+
 | Author: Benjamin Smith <defitro@gmail.com>                            |
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 +-----------------------------------------------------------------------+
*/

class rcmail_action_mail_search extends rcmail_action_mail_index
{
    protected static $mode = self::MODE_AJAX;

    /**
     * Request handler.
     *
     * @param array $args Arguments from the previous step(s)
     */
    public function run($args = [])
    {
        $rcmail = rcmail::get_instance();

        @set_time_limit(170);  // extend default max_execution_time to ~3 minutes

        // reset list_page and old search results
        $rcmail->storage->set_page(1);
        $rcmail->storage->set_search_set(null);
        $_SESSION['page'] = 1;

        // get search string
        $str      = rcube_utils::get_input_string('_q', rcube_utils::INPUT_GET, true);
        $mbox     = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GET, true);
        $filter   = rcube_utils::get_input_string('_filter', rcube_utils::INPUT_GET);
        $headers  = rcube_utils::get_input_string('_headers', rcube_utils::INPUT_GET);
        $scope    = rcube_utils::get_input_string('_scope', rcube_utils::INPUT_GET);
        $interval = rcube_utils::get_input_string('_interval', rcube_utils::INPUT_GET);
        $continue = rcube_utils::get_input_string('_continue', rcube_utils::INPUT_GET);

        // Strip CR/LF from the filter here, at the raw input. $filter is the only
        // user value that reaches the SEARCH command unescaped (line ~58); a newline
        // in it could inject extra IMAP commands. It MUST be sanitized before the
        // command is assembled — NOT after, because the search term is added as a
        // length-counted IMAP literal (escape(): {N}\r\n...) whose mandatory \r\n a
        // whole-command preg_replace would corrupt, breaking the literal and
        // desyncing pipelined multi-folder search on any accented term.
        $filter         = preg_replace('/[\r\n]+/', ' ', trim((string) $filter));
        $search_request = md5($mbox . $scope . $interval . $filter . $str);

        // Parse input
        list($subject, $search) = self::search_input($str, $headers, $scope, $mbox);

        // add list filter string
        $search_str = $filter && $filter != 'ALL' ? $filter : '';

        if ($search_interval = self::search_interval_criteria($interval)) {
            $search_str .= ' ' . $search_interval;
        }

        if (!empty($subject)) {
            $search_str .= str_repeat(' OR', count($subject)-1);
            foreach ($subject as $sub) {
                $search_str .= ' ' . $sub . ' ' . rcube_imap_generic::escape($search);
            }
        }

        $search_str  = trim($search_str);
        $sort_column = self::sort_column();
        $sort_order  = self::sort_order();

        // NOTE: the upstream `preg_replace('/[\r\n]+/', ' ', $search_str)` that used
        // to sit here is deliberately GONE. It flattened the whole assembled command,
        // including the search term's IMAP literal {N}\r\n<bytes>, turning it into
        // {N} <bytes> — a malformed literal that putLineC neither converts to the
        // non-synchronizing {N+} nor handshakes, desyncing pipelined search on any
        // non-ASCII term (reunião etc). The CR/LF injection guard it provided now
        // lives at the raw $filter input above, which is the only unescaped value in
        // the command; the search term is already safe via escape()'s counted literal.

        // set message set for already stored (but incomplete) search request
        if (!empty($continue) && isset($_SESSION['search']) && $_SESSION['search_request'] == $continue) {
            $rcmail->storage->set_search_set($_SESSION['search']);
            $search_str = $_SESSION['search'][0];
        }

        // execute IMAP search
        if ($search_str) {
            $mboxes = [];

            // search all, current or subfolders folders
            if ($scope == 'all') {
                $mboxes = $rcmail->storage->list_folders_subscribed('', '*', 'mail', null, true);
                // we want natural alphabetic sorting of folders in the result set
                natcasesort($mboxes);
            }
            else if ($scope == 'sub') {
                $delim  = $rcmail->storage->get_hierarchy_delimiter();
                $mboxes = $rcmail->storage->list_folders_subscribed($mbox . $delim, '*', 'mail');
                array_unshift($mboxes, $mbox);
            }

            if ($scope != 'all') {
                // Remember current folder, it can change in meantime (plugins)
                // but we need it to e.g. recognize Sent folder to handle From/To column later
                $rcmail->output->set_env('mailbox', $mbox);
            }

            $result = $rcmail->storage->search($mboxes, $search_str, RCUBE_CHARSET, $sort_column);
        }

        // save search results in session
        if (!isset($_SESSION['search']) || !is_array($_SESSION['search'])) {
            $_SESSION['search'] = [];
        }

        if ($search_str) {
            $_SESSION['search'] = $rcmail->storage->get_search_set();
            $_SESSION['last_text_search'] = $str;
        }

        $_SESSION['search_request']  = $search_request;
        // AVUZ PATCH — start the clock for the total search budget. A request
        // without _continue is a NEW logical search, so it resets it.
        if (empty($_GET['_continue'])) {
            $_SESSION['search_start'] = time();
        }
        $_SESSION['search_scope']    = $scope;
        $_SESSION['search_interval'] = $interval;
        $_SESSION['search_filter']   = $filter;

        // AVUZ PATCH — progressive search.
        //
        // A cross-folder search that has not finished still holds complete
        // results for the folders that DID finish: rcube_imap_search::exec()
        // caches them and reuses them on the next round. Upstream refuses to
        // list them, forcing count to 0 "to keep UI locked", so the user sees
        // an empty screen behind a spinner for as long as the whole search
        // takes — measured at up to 212s on this deployment, which is why
        // people abandon searches instead of waiting.
        //
        // List them instead. This costs ONE FETCH of at most mail_pagesize
        // headers; it does not re-search anything.
        $incomplete = !empty($result) && !empty($result->incomplete);
        $result_h   = $rcmail->storage->list_messages($mbox, 1, $sort_column, $sort_order);

        // Make sure we got the headers
        if (!empty($result_h)) {
            $count = $rcmail->storage->count($mbox, $rcmail->storage->get_threading() ? 'THREADS' : 'ALL');

            // AVUZ PATCH — a continuation round re-lists the FULL accumulated
            // cross-folder result (list_messages() above returns the already
            // correctly-sorted page 1 of the whole search set, see
            // rcube_imap::list_search_messages()), not just the newest
            // folder's hits. The client only clears the list on the initial
            // qsearch; continue_search() never does. Left alone it would
            // dedupe-and-append the new rows after the ones it already has,
            // scrambling the sort order. Telling it to clear first forces a
            // clean rebuild in the correct order every round. The initial
            // round is untouched: the client already clears there itself,
            // and a second clear could race with it.
            if (!empty($continue)) {
                $rcmail->output->command('message_list.clear', true);
            }

            self::js_message_list($result_h, false);

            // Only claim success once. While incomplete the client keeps its
            // own "still searching" state, and announcing a total that is
            // about to grow would be a lie.
            if ($search_str && !$incomplete) {
                $all_count = $rcmail->storage->count(null, 'ALL');
                $rcmail->output->show_message('searchsuccessful', 'confirmation', ['nr' => $all_count]);
            }

            // remember last HIGHESTMODSEQ value (if supported)
            // we need it for flag updates in check-recent
            if ($mbox !== null) {
                $data = $rcmail->storage->folder_data($mbox);
                if (!empty($data['HIGHESTMODSEQ'])) {
                    $_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
                }
            }
        }
        // handle IMAP errors (e.g. #1486905)
        else if ($err_code = $rcmail->storage->get_error_code()) {
            $count = 0;
            self::display_server_error();
        }
        else if ($incomplete) {
            // nothing found YET — no rows, but the search is still running
            $count = 0;
        }
        else {
            $count = 0;

            $rcmail->output->show_message('searchnomatch', 'notice');
            $rcmail->output->set_env('multifolder_listing', isset($result) ? !empty($result->multi) : false);

            if (isset($result) && !empty($result->multi) && $scope == 'all') {
                $rcmail->output->command('select_folder', '');
            }
        }

        // Ask the client to continue, independently of whether we just rendered
        // rows. Upstream only reached this inside the no-rows branch, so simply
        // listing partial results would have silently stopped the search.
        //
        // Bounded: app.js re-issues every 100ms for as long as we keep saying
        // "incomplete", with no ceiling of its own. Past the budget we stop
        // asking and tell the user plainly, rather than spinning forever.
        if ($incomplete) {
            $total_limit = (int) $rcmail->config->get('imap_search_total_timelimit', 120);
            $elapsed     = time() - (int) ($_SESSION['search_start'] ?? time());

            if ($total_limit > 0 && $elapsed >= $total_limit) {
                $rcmail->output->show_message('searchpartial', 'notice');
            }
            else {
                $rcmail->output->command('continue_search', $search_request);
            }
        }

        // update message count display
        $rcmail->output->set_env('search_request', $search_str ? $search_request : '');
        $rcmail->output->set_env('search_filter', $_SESSION['search_filter']);
        $rcmail->output->set_env('messagecount', $count);
        $rcmail->output->set_env('pagecount', ceil($count / $rcmail->storage->get_pagesize()));
        $rcmail->output->set_env('exists', $mbox === null ? 0 : $rcmail->storage->count($mbox, 'EXISTS'));
        $rcmail->output->command('set_rowcount', self::get_messagecount_text($count, 1), $mbox);

        self::list_pagetitle();

        // update unseen messages count
        if ($search_str === '') {
            self::send_unread_count($mbox, false, empty($result_h) ? 0 : null);
        }

        if (isset($result) && empty($result->incomplete)) {
            $rcmail->output->command('set_quota', self::quota_content(null, !empty($result->multi) ? 'INBOX' : $mbox));
        }

        $rcmail->output->send();
    }

    /**
     * Creates BEFORE/SINCE search criteria from the specified interval
     * Interval can be: 1W, 1M, 1Y, -1W, -1M, -1Y
     */
    public static function search_interval_criteria($interval)
    {
        if (empty($interval)) {
            return;
        }

        if ($interval[0] == '-') {
            $search   = 'BEFORE';
            $interval = substr($interval, 1);
        }
        else {
            $search = 'SINCE';
        }

        $date     = new DateTime('now');
        $interval = new DateInterval('P' . $interval);

        $date->sub($interval);

        return $search . ' ' . $date->format('j-M-Y');
    }

    /**
     * Parse search input.
     *
     * @param string $str     Search string
     * @param string $headers Comma-separated list of headers/fields to search in
     * @param string $scope   Search scope (all | base | sub)
     * @param string $mbox    Folder name
     *
     * @return array Search criteria (1st element) and search value (2nd element)
     */
    public static function search_input($str, $headers, $scope, $mbox)
    {
        $rcmail    = rcmail::get_instance();
        $subject   = [];
        $srch      = null;
        $supported = ['subject', 'from', 'to', 'cc', 'bcc'];

        // Check the search string for type of search
        if (preg_match("/^(from|to|reply-to|cc|bcc|subject):.*/i", $str, $m)) {
            list(, $srch) = explode(":", $str);
            $subject[$m[1]] = 'HEADER ' . strtoupper($m[1]);
        }
        else if (preg_match("/^body:.*/i", $str)) {
            list(, $srch) = explode(":", $str);
            $subject['body'] = 'BODY';
        }
        else if (strlen(trim($str))) {
            if ($headers) {
                foreach (explode(',', $headers) as $header) {
                    switch ($header) {
                    case 'text':
                        // #1488208: get rid of other headers when searching by "TEXT"
                        $subject = ['text' => 'TEXT'];
                        break 2;
                    case 'body':
                        $subject['body'] = 'BODY';
                        break;
                    case 'replyto':
                    case 'reply-to':
                        $subject['reply-to'] = 'HEADER REPLY-TO';
                        $subject['mail-reply-to'] = 'HEADER MAIL-REPLY-TO';
                        break;
                    case 'followupto':
                    case 'followup-to':
                        $subject['followup-to'] = 'HEADER FOLLOWUP-TO';
                        $subject['mail-followup-to'] = 'HEADER MAIL-FOLLOWUP-TO';
                        break;
                    default:
                        if (in_array_nocase($header, $supported)) {
                            $subject[$header] = 'HEADER ' . strtoupper($header);
                        }
                    }
                }

                // save search modifiers for the current folder to user prefs
                if ($scope != 'all') {
                    $search_mods       = self::search_mods();
                    $search_mods_value = array_fill_keys(array_keys($subject), 1);

                    if (!isset($search_mods[$mbox]) || $search_mods[$mbox] != $search_mods_value) {
                        $search_mods[$mbox] = $search_mods_value;
                        $rcmail->user->save_prefs(['search_mods' => $search_mods]);
                    }
                }
            }
            else {
                // search in subject by default
                $subject['subject'] = 'HEADER SUBJECT';
            }
        }

        return [$subject, isset($srch) ? trim($srch) : trim($str)];
    }
}
