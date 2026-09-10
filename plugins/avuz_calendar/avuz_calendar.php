<?php
class avuz_calendar extends rcube_plugin
{
    public $task = 'mail';
    public $has_calendar_part = false;

    function init()
    {
        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/lib/ical_invite.php';
        require_once __DIR__ . '/lib/itip_reply.php';
        require_once __DIR__ . '/lib/nc_calendar_client.php';

        $this->add_hook('message_part_structure', [$this, 'on_part_structure']);
        $this->add_hook('template_object_messagebody', [$this, 'on_message_body']);
        $this->register_action('plugin.avuz_calendar_rsvp', [$this, 'on_rsvp']);
        $this->include_stylesheet('skins/elastic/invite.css');
        $this->include_script('js/invite.js');
    }

    function on_part_structure($p) {
        if (stripos((string)($p['structure']->mimetype ?? ''), 'text/calendar') !== false) {
            $this->has_calendar_part = true;
        }
        return $p;
    }

    function on_message_body($p) {
        if (empty($this->has_calendar_part)) { return $p; }
        $rcmail = rcmail::get_instance();
        $uid  = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_GET);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_GET);
        $message = new rcube_message($uid, $mbox);
        foreach ($message->attachments as $part) {
            if (stripos((string) $part->mimetype, 'text/calendar') === false) { continue; }
            $ics = $message->get_part_body($part->mime_id, true);
            $inv = avuz_ical_invite::from_ics($ics);
            if (!$inv) { continue; }
            $this->add_texts('localization/', true);
            $rcmail->output->set_env('avuz_calendar_invite', [
                'uid' => $inv['uid'], 'mbox' => $mbox, 'msg_uid' => $uid, 'part' => $part->mime_id,
            ]);
            $p['content'] = $this->render_card($inv) . $p['content'];
            break;
        }
        return $p;
    }

    private function render_card(array $inv): string {
        $rc = rcmail::get_instance();
        $esc = fn($s) => rcube::Q((string) $s);
        $meet = $inv['meet_url'] ? '<a href="' . $esc($inv['meet_url']) . '" target="_blank">' . $esc($inv['meet_url']) . '</a>' : '';
        $btn = fn($k,$l) => '<button class="btn-avuz-rsvp" data-partstat="' . $k . '">' . $esc($rc->gettext($l, 'avuz_calendar')) . '</button>';
        return '<div id="avuz-invite-card" class="avuz-invite">'
            . '<div class="avuz-invite-title">' . $esc($inv['summary']) . '</div>'
            . '<div class="avuz-invite-meta">' . $esc($inv['start']) . ' — ' . $esc($inv['organizer']) . '</div>'
            . ($meet ? '<div class="avuz-invite-meet">' . $meet . '</div>' : '')
            . '<div class="avuz-invite-actions">' . $btn('ACCEPTED','accept') . $btn('TENTATIVE','tentative') . $btn('DECLINED','decline') . '</div>'
            . '<div class="avuz-invite-status" hidden></div></div>';
    }

    function on_rsvp() {
        $rcmail = rcmail::get_instance();
        $this->add_texts('localization/');
        $uid  = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST);
        $part = rcube_utils::get_input_string('_part', rcube_utils::INPUT_POST);
        $partstat = rcube_utils::get_input_string('_partstat', rcube_utils::INPUT_POST);
        $allowed = ['ACCEPTED','TENTATIVE','DECLINED'];
        if (!in_array($partstat, $allowed, true)) { return; }

        $message = new rcube_message($uid, $mbox);
        $ics = $message->get_part_body($part, true);
        $inv = avuz_ical_invite::from_ics($ics);
        if (!$inv) { $this->reply_done($rcmail, $this->gettext('add_failed')); return; }

        $me = $rcmail->get_user_email();
        $messages = [];

        // 1) iTip REPLY over SMTP
        $reply_ok = $this->send_reply($rcmail, $inv['organizer'], $me, $ics, $partstat);
        $messages[] = $reply_ok ? $this->gettext('reply_sent') : $this->gettext('add_failed');

        // 2) calendar add (accept/tentative only)
        if ($partstat !== 'DECLINED') {
            $instances = (array) $rcmail->config->get('avuz_nc_instances', []);
            $base = avuz_nc_client::resolve_base($instances, $me);
            if ($base) {
                $secret = (string) getenv('ROUNDCUBE_SSO_SECRET');
                $env = avuz_nc_client::sign($ics, $me, $secret, time());
                $res = avuz_nc_client::post($base, $ics, $inv['uid'], $env);
                $messages[] = $res['ok'] ? $this->gettext('added') : $this->gettext('add_failed');
            }
        }
        $this->reply_done($rcmail, implode(' · ', $messages));
    }

    private function send_reply($rcmail, string $organizer, string $me, string $request_ics, string $partstat): bool {
        try {
            $reply_ics = avuz_itip_reply::build($request_ics, $me, $partstat);
            $headers = [
                'From' => $me, 'To' => $organizer,
                'Subject' => $this->gettext('reply_subject'),
                'Content-Type' => 'text/calendar; method=REPLY; charset=UTF-8',
            ];
            $mime = new Mail_mime(["eol" => "\r\n"]);
            $mime->headers($headers);
            $mime->setTXTBody($reply_ics);
            $err = null;
            $body = null;
            return (bool) $rcmail->deliver_message($mime, $me, $organizer, $err, $body, null, false);
        } catch (\Throwable $e) {
            rcube::write_log('errors', 'avuz_calendar reply failed: ' . $e->getMessage());
            return false;
        }
    }

    private function reply_done($rcmail, string $message): void {
        $rcmail->output->command('plugin.avuz_calendar_rsvp_done', ['message' => $message]);
        $rcmail->output->send();
    }
}
