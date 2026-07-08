<?php

/**
 * avuz_prefetch — warm the message cache for the messages in the current list page
 * so a click serves the body from cache (Redis) instead of a fresh Brazil→Zoho fetch.
 *
 * Warms text parts only (text/html + text/plain). Roundcube's messages_cache does
 * NOT persist binary/image parts, so prefetching inline images is wasted bandwidth
 * — they re-fetch on open regardless. Fetches with BODY.PEEK: never sets \Seen.
 */
class avuz_prefetch extends rcube_plugin
{
    public $task = 'mail';

    private const MAX_UIDS = 10;

    function init()
    {
        $this->register_action('plugin.avuz_prefetch', [$this, 'prefetch']);
        $this->include_script('prefetch.js');
    }

    function prefetch()
    {
        $rcmail = rcmail::get_instance();
        $uids   = (string) rcube_utils::get_input_value('_uids', rcube_utils::INPUT_POST);
        $mbox   = (string) rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);

        $list = array_slice(array_filter(explode(',', $uids), 'strlen'), 0, self::MAX_UIDS);

        foreach ($list as $rawUid) {
            $uid = (int) $rawUid;
            if ($uid <= 0) {
                continue;
            }

            try {
                $message = new rcube_message($uid, $mbox !== '' ? $mbox : null);
                if (empty($message->headers)) {
                    continue;
                }

                foreach ($message->mime_parts as $mimeId => $part) {
                    $mimetype = (string) ($part->mimetype ?? '');
                    if ($mimetype === 'text/html' || $mimetype === 'text/plain') {
                        // PEEK fetch — warms the cache, never flags \Seen. Discard the body.
                        $message->get_part_body($mimeId, false, 0);
                    }
                }
            } catch (Throwable $e) {
                rcube::raise_error("avuz_prefetch uid {$uid}: " . $e->getMessage(), true, false);
            }
        }

        // Empty ACK — the side effect (warm cache) is the point.
        $rcmail->output->send();
    }
}
