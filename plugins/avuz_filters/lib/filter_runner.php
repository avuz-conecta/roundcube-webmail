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
            rcube::write_log('avuz_filters', "seed user=$user uidnext=" . ($uidnext ?? 'null') . " seed=$seed rules=" . count($rules));
            return 0;
        }
        $last = $from_scratch ? 0 : $state['last_uid'];

        // UID search for new messages (or ALL when applying to existing on demand).
        $criteria = $from_scratch ? 'ALL' : ('UID ' . ($last + 1) . ':*');
        $index    = $storage->search_once($folder, $criteria);
        $uids     = $index ? $index->get() : [];
        rcube::write_log('avuz_filters', "run user=$user scratch=" . (int)$from_scratch . " last=$last uidnext=" . ($uidnext ?? 'null') . " crit='$criteria' found=" . count($uids) . " rules=" . count($rules));
        if (!$uids) { $store->set_state($user, $folder, $last, $uidv); return 0; }

        sort($uids, SORT_NUMERIC);
        $uids  = array_slice($uids, 0, self::MAX_PER_PASS);
        $start = microtime(true);
        $acted = 0; $maxUid = $last;

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
                self::apply($rcmail, $storage, $folder, $trash, (int) $uid, $actions, $already_fwd);
                $acted++;
            }
        }
        $store->set_state($user, $folder, $maxUid, $uidv);
        $sample = '';
        if (!empty($uids)) {
            $sh = $headersList[$uids[0]] ?? null;
            if ($sh) $sample = " sample_uid={$uids[0]} from='" . substr((string)$sh->from,0,60) . "' subj='" . substr((string)$sh->subject,0,40) . "'";
        }
        rcube::write_log('avuz_filters', "done user=$user acted=$acted maxUid=$maxUid" . $sample);
        return $acted;
    }

    /**
     * Apply one rule's actions to a single UID via the live IMAP session.
     * Flags MUST be set while the message is still in INBOX; the move/delete is the
     * terminal action (removes it from INBOX), so it runs LAST regardless of the
     * order the actions were configured in. Prevents dropping later actions and
     * prevents flagging a message that already left the folder.
     */
    private static function apply(rcmail $rcmail, $storage, string $folder, string $trash, int $uid, array $actions, bool $already_fwd): void
    {
        $move_to = null; $fwd = [];
        foreach ($actions as $a) {
            switch ($a['type']) {
                case 'mark_read': $storage->set_flag($uid, 'SEEN', $folder); break;
                case 'flag':      $storage->set_flag($uid, 'FLAGGED', $folder); break;
                case 'forward':   if (!empty($a['to'])) $fwd[] = $a['to']; break;  // copy, not terminal
                case 'delete':    $move_to = $trash; break;                        // last-wins
                case 'move':      if (!empty($a['folder'])) $move_to = $a['folder']; break;
            }
        }
        // Redirect a copy to each target while the message is still in INBOX. Skip if
        // this message is itself one of our redirects (loop guard).
        if ($fwd && !$already_fwd) {
            foreach ($fwd as $to) self::redirect($rcmail, $folder, $uid, $to);
        }
        if ($move_to !== null) {
            $storage->move_message($uid, $move_to, $folder); // terminal: removes from INBOX
        }
    }

    /**
     * Redirect (resend) the original message untouched to $to (Sieve 'redirect'
     * semantics — recipient sees the original sender). Uses Roundcube's native
     * resend/bounce machinery + the user's in-session SMTP. Stamps X-Avuz-Forwarded
     * so a copy that lands back can't be redirected again.
     */
    private static function redirect(rcmail $rcmail, string $folder, int $uid, string $to): void
    {
        try {
            $message = new rcube_message((string) $uid, $folder);
            if (empty($message->headers)) return;
            $from = $rcmail->get_user_email();
            $bounce_headers = [
                'Resent-From'      => $from,
                'Resent-To'        => $to,
                'Resent-Date'      => date('r'),
                'Resent-Message-ID'=> $rcmail->gen_message_id($from),
                'X-Avuz-Forwarded' => '1',
            ];
            $BOUNCE = new rcmail_resend_mail([
                'bounce_message' => $message,
                'bounce_headers' => $bounce_headers,
            ]);
            $error = null;
            $rcmail->deliver_message($BOUNCE, $from, $to, $error);
            if ($error) rcube::write_log('errors', "avuz_filters redirect uid=$uid to=$to error=" . json_encode($error));
        } catch (\Throwable $e) {
            rcube::write_log('errors', 'avuz_filters redirect: ' . $e->getMessage());
        }
    }
}
