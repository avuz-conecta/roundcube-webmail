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
        $index    = $storage->search_once($folder, $criteria);
        $uids     = $index ? $index->get() : [];
        if (!$uids) { $store->set_state($user, $folder, $last, $uidv); return 0; }

        sort($uids, SORT_NUMERIC);
        $uids  = array_slice($uids, 0, self::MAX_PER_PASS);
        $start = microtime(true);
        $acted = 0; $maxUid = $last; $filled = [];

        // Include our loop-guard marker header in the fetch, then fetch the batch.
        $storage->set_options(['fetch_headers' => 'X-Avuz-Forwarded']);
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
            // Already redirected by us → don't redirect again (loop guard).
            $already_fwd = !empty($h->others['x-avuz-forwarded']);
            $actions = avuz_rule_engine::match($hv, $rules);
            if ($actions) {
                $moved_to = self::apply($rcmail, $storage, $folder, $trash, (int) $uid, $actions, $already_fwd);
                if ($moved_to !== null) {
                    $filled[$moved_to] = true;
                }
                $acted++;
            }
        }
        $store->set_state($user, $folder, $maxUid, $uidv);

        // Tell the client the new unread count for every folder we just filed into.
        // Without this the destination badge never updates: we move messages via
        // $storage->move_message() directly, which bypasses move.php:128 where core
        // would normally push it. Same approach as plugins/archive/archive.php:291.
        //
        // MUST run after set_state() and MUST be individually guarded: every flag,
        // forward and move for this batch has already happened against live IMAP by
        // this point, so the watermark has to advance regardless of whether this
        // purely cosmetic badge push succeeds. If send_unread_count() throws (e.g.
        // $storage->count() on a dropped/timed-out IMAP connection through the
        // imapproxy sidecar) and this loop still sat before set_state(), the
        // exception would propagate to run()'s catch and set_state() would never
        // execute — the next pass would restart from the stale watermark and
        // re-match the same UIDs, causing forward-only rules to send duplicate
        // outbound email. Do not move this back above set_state().
        //
        // Each folder is guarded independently: a failure pushing one folder's
        // badge (e.g. it was renamed/deleted between the move and the report, or
        // hits a per-folder STATUS/ACL error) must not skip the badge push for the
        // other folders filed into during this pass.
        foreach (array_keys($filled) as $filled_folder) {
            try {
                rcmail_action_mail_index::send_unread_count($filled_folder, true);
            } catch (\Throwable $e) {
                rcube::write_log('errors', 'avuz_filters: unread count push failed for folder ' . $filled_folder . ': ' . $e->getMessage());
            }
        }

        return $acted;
    }

    /**
     * Which folder, if any, a matched rule's actions move the message into.
     * Pure so it can be unit tested; `apply()` performs the move itself.
     * Terminal actions are last-wins, matching the original inline logic.
     */
    public static function move_target(array $actions, string $trash): ?string
    {
        $move_to = null;

        foreach ($actions as $a) {
            $type = $a['type'] ?? '';

            if ($type === 'delete') {
                $move_to = $trash;
            }
            elseif ($type === 'move' && !empty($a['folder'])) {
                $move_to = $a['folder'];
            }
        }

        return $move_to;
    }

    /**
     * Apply one rule's actions to a single UID via the live IMAP session.
     * Flags MUST be set while the message is still in INBOX; the move/delete is the
     * terminal action (removes it from INBOX), so it runs LAST regardless of the
     * order the actions were configured in. Prevents dropping later actions and
     * prevents flagging a message that already left the folder.
     */
    /** @return string|null the folder the message was moved into, or null */
    private static function apply(rcmail $rcmail, $storage, string $folder, string $trash, int $uid, array $actions, bool $already_fwd): ?string
    {
        $fwd     = [];
        $move_to = self::move_target($actions, $trash);

        foreach ($actions as $a) {
            switch ($a['type']) {
                case 'mark_read': $storage->set_flag($uid, 'SEEN', $folder); break;
                case 'flag':      $storage->set_flag($uid, 'FLAGGED', $folder); break;
                case 'forward':   if (!empty($a['to'])) $fwd[] = $a['to']; break;  // copy, not terminal
                // 'delete' and 'move' are terminal and resolved by move_target() above
            }
        }
        // Redirect a copy to each target while the message is still in INBOX. Skip if
        // this message is itself one of our redirects (loop guard).
        if ($fwd && !$already_fwd) {
            foreach ($fwd as $to) self::redirect($rcmail, $folder, $uid, $to);
        }
        if ($move_to !== null) {
            $storage->move_message($uid, $move_to, $folder); // terminal: removes from INBOX
            return $move_to;
        }

        return null;
    }

    /**
     * Forward the message to $to, sent FROM the user's own address. A true Sieve
     * 'redirect' (preserving the original sender) is impossible on Zoho — it refuses
     * to relay mail whose sender is a foreign address (SMTP 553). So we rewrite the
     * top-level identity headers (From = authenticated user, Reply-To = original
     * sender so replies still reach them) while keeping the original body +
     * attachments byte-for-byte, then send via the user's in-session SMTP. Stamps
     * X-Avuz-Forwarded as a loop guard.
     */
    private static function redirect(rcmail $rcmail, string $folder, int $uid, string $to): void
    {
        try {
            $storage = $rcmail->get_storage();
            // get_raw_body() returns the ENTIRE message source (headers + body), so
            // split it once — do NOT also pull get_raw_headers or the header block
            // ends up duplicated (gmail rejects: "multiple To headers", 550 5.7.1).
            $raw = $storage->get_raw_body($uid);
            if (!$raw) return;
            $sep = "\r\n\r\n"; $pos = strpos($raw, $sep);
            if ($pos === false) { $sep = "\n\n"; $pos = strpos($raw, $sep); }
            if ($pos === false) return;
            $rawHead = substr($raw, 0, $pos);
            $rawBody = substr($raw, $pos + strlen($sep));

            $msg      = new rcube_message((string) $uid, $folder);
            $origFrom = trim((string) ($msg->headers->from ?? ''));
            $user     = $rcmail->get_user_email();
            $mid      = $rcmail->gen_message_id($user);

            // Keep ONLY content headers from the original + add clean identity headers.
            // Dropping the original routing/auth headers (Received, Authentication-Results,
            // ARC-*, DKIM, Return-Path, X-*, original From/To/…) is what keeps gmail from
            // spam-foldering: otherwise From=avuz.cloud but the headers show a gmail origin,
            // which reads as spoofing. The result is a clean avuz.cloud-authored message
            // that passes SPF/DKIM/DMARC on our domain.
            $head = self::forward_headers($rawHead, [
                'From'             => $user,
                'Sender'           => $user,
                'To'               => $to,
                'Reply-To'         => $origFrom ?: null,
                'Message-ID'       => $mid,
                'X-Avuz-Forwarded' => '1',
            ]);

            $mail  = new avuz_forward_mail($head, $rawBody, ['To' => $to, 'From' => $user, 'Message-ID' => $mid]);
            $error = null;
            $rcmail->deliver_message($mail, $user, $to, $error);
            if ($error) rcube::write_log('errors', "avuz_filters forward uid=$uid to=$to err=" . json_encode($error));
        } catch (\Throwable $e) {
            rcube::write_log('errors', 'avuz_filters forward: ' . $e->getMessage());
        }
    }

    /**
     * Build a clean forward header block: KEEP only content/MIME headers from the
     * original (everything needed to render the body correctly), drop all routing/auth
     * headers, then append the given identity headers (null values skipped).
     */
    private static function forward_headers(string $rawHead, array $set): string
    {
        // Content headers to preserve (lowercased). Everything else is dropped.
        $keep = [
            'subject', 'date', 'mime-version',
            'content-type', 'content-transfer-encoding', 'content-disposition',
            'content-id', 'content-description', 'content-language',
            'in-reply-to', 'references',
        ];
        $lines = preg_split('/\r?\n/', rtrim($rawHead));
        $out   = []; $keeping = false;
        foreach ($lines as $ln) {
            if ($ln !== '' && preg_match('/^[ \t]/', $ln)) { if ($keeping) $out[] = $ln; continue; } // folded
            $keeping = false;
            if (preg_match('/^([^\s:]+):/', $ln, $m) && in_array(strtolower($m[1]), $keep, true)) {
                $keeping = true; $out[] = $ln;
            }
        }
        $head = implode("\r\n", $out);
        foreach ($set as $k => $v) {
            if ($v !== null && $v !== '') $head .= "\r\n$k: $v";
        }
        return ltrim($head, "\r\n");
    }
}

/**
 * Minimal message object that rcube::deliver_message() can send: it only needs
 * headers() (for recipients + logging), txtHeaders() (the header block), get()
 * (the body) and getParam(). Lets us send a raw, header-rewritten message via
 * Roundcube's SMTP without rebuilding it as a Mail_mime (preserves attachments).
 */
class avuz_forward_mail
{
    private $head;
    private $body;
    private $hdrs;

    function __construct(string $head, string $body, array $hdrs)
    {
        $this->head = $head;
        $this->body = $body;
        $this->hdrs = $hdrs;
    }

    function headers($add = [], $overwrite = false, $skip_content = false) { return $this->hdrs; }
    function txtHeaders($add = [], $overwrite = false, $skip_content = false) { return $this->head; }
    function getParam($name) { return false; }
    function get($params = null) { return $this->body; }
}
