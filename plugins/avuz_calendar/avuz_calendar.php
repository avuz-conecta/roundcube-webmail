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

    // Implemented in Task 12:
    function on_rsvp() {}
}
