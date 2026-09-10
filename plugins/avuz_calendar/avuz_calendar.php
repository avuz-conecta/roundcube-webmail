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

    // Implemented in Task 8:
    function on_part_structure($p) { return $p; }
    function on_message_body($p) { return $p; }
    // Implemented in Task 12:
    function on_rsvp() {}
}
