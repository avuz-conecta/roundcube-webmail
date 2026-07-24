<?php

require_once __DIR__ . '/lib/body_cache.php';

/**
 * avuz_body_cache — browser-side (IndexedDB) message-body cache with proactive
 * prefetch, for Zimbra-parity instant open. Server side is thin: emit the env the
 * client needs and include the client scripts. Gated by AVUZ_BODY_CACHE=1.
 */
class avuz_body_cache extends rcube_plugin
{
    public $task = 'mail';

    function init()
    {
        if (getenv('AVUZ_BODY_CACHE') !== '1') {
            return; // inert unless explicitly enabled
        }
        $this->add_hook('render_page', [$this, 'emit_env']);
        $this->include_script('js/idb.js');
        $this->include_script('js/controller.js');
        $this->include_script('js/open.js');
        $this->include_script('js/bodycache.js');
    }

    function emit_env($args)
    {
        $rcmail = rcmail::get_instance();
        $user   = (string) $rcmail->get_user_name();
        $deskey = (string) $rcmail->config->get('des_key');

        $rcmail->output->set_env('avuz_body_cache', true);
        $rcmail->output->set_env('avuz_cache_user', avuz_body_cache_lib::user_tag($user, $deskey));
        $rcmail->output->set_env('avuz_sanitizer_version', avuz_body_cache_lib::SANITIZER_VERSION);
        // The effective remote-image safety the prefetch should render with, so the
        // cached _safe matches what a real open shows. show_images is a config, not an
        // env var, so surface it explicitly.
        // Full integer mode: 0=never, 1=contacts-only, 2=always, 3=ask. Only mode 2 is an
        // unconditional "load remote images"; the prefetch renders everything else _safe=0
        // (blocked), because the per-message contacts decision (index.php check_safe) cannot
        // be pre-computed in a background fetch.
        $rcmail->output->set_env('avuz_show_images', (int) $rcmail->config->get('show_images'));
        // Default render format used by preview when a message carries no per-message
        // format override; part of the cache key (spec §5) so stale-format bodies never
        // get served after a user changes their format preference.
        $rcmail->output->set_env('avuz_default_format', $rcmail->config->get('prefer_html') ? 'html' : 'plain');

        $map  = [];
        $mbox = $rcmail->output->get_env('mailbox') ?: $rcmail->storage->get_folder();
        if ($mbox) {
            $data = $rcmail->storage->folder_data($mbox);
            if (!empty($data['UIDVALIDITY'])) {
                $map[$mbox] = (string) $data['UIDVALIDITY'];
            }
        }
        $rcmail->output->set_env('avuz_uidvalidity', $map);

        return $args;
    }
}
