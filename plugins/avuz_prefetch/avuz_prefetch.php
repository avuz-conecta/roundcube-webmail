<?php

/**
 * avuz_prefetch — warm the message cache for the top messages in the list so a
 * click serves the body from cache (Redis) instead of a fresh Brazil→Zoho fetch.
 *
 * Fetches body parts with BODY.PEEK only — it NEVER sets \Seen. Opening a message
 * later still marks it read normally.
 */
class avuz_prefetch extends rcube_plugin
{
    public $task = 'mail';

    private const MAX_UIDS = 10;
    private const MAX_PART_BYTES = 262144; // 256 KB — skip big images/attachments

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
                    $mimetype    = (string) ($part->mimetype ?? '');
                    $disposition = strtolower((string) ($part->disposition ?? ''));
                    $size        = (int) ($part->size ?? 0);

                    $isText = $mimetype === 'text/html' || $mimetype === 'text/plain';
                    // inline images that render in the body — skip attachments and big parts
                    $isInlineImage = strpos($mimetype, 'image/') === 0
                        && $disposition !== 'attachment'
                        && $size > 0 && $size <= self::MAX_PART_BYTES;

                    if ($isText || $isInlineImage) {
                        // PEEK fetch — populates the cache, never flags \Seen. Discard the body.
                        $message->get_part_body($mimeId, false, 0);
                    }
                }
            } catch (Exception $e) {
                rcube::raise_error("avuz_prefetch uid {$uid}: " . $e->getMessage(), true, false);
            }
        }

        // Empty ACK — the JS ignores the payload; the side effect (warm cache) is the point.
        $rcmail->output->send();
    }
}
