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
        rcube::write_log('avuz_prefetch', sprintf('REQ mbox=%s uids=%s', $mbox, implode(',', $list)));

        foreach ($list as $rawUid) {
            $uid = (int) $rawUid;
            if ($uid <= 0) {
                continue;
            }

            try {
                $message = new rcube_message($uid, $mbox !== '' ? $mbox : null);
                $hdr    = empty($message->headers) ? 0 : 1;
                $nparts = is_array($message->mime_parts) ? count($message->mime_parts) : 0;
                $warmed = 0;

                if ($hdr) {
                    foreach ($message->mime_parts as $mimeId => $part) {
                        $mimetype    = (string) ($part->mimetype ?? '');
                        $disposition = strtolower((string) ($part->disposition ?? ''));
                        $size        = (int) ($part->size ?? 0);

                        $isText = $mimetype === 'text/html' || $mimetype === 'text/plain';
                        $isInlineImage = strpos($mimetype, 'image/') === 0
                            && $disposition !== 'attachment'
                            && $size > 0 && $size <= self::MAX_PART_BYTES;

                        if ($isText || $isInlineImage) {
                            $body = $message->get_part_body($mimeId, false, 0);
                            $warmed++;
                            rcube::write_log('avuz_prefetch', sprintf('  uid=%d part=%s type=%s bytes=%d', $uid, $mimeId, $mimetype, strlen((string) $body)));
                        }
                    }
                }

                rcube::write_log('avuz_prefetch', sprintf('uid=%d hdr=%d parts=%d warmed=%d', $uid, $hdr, $nparts, $warmed));
            } catch (Throwable $e) {
                rcube::write_log('avuz_prefetch', sprintf('uid=%d ERROR %s', $uid, $e->getMessage()));
            }
        }

        // Empty ACK — the JS ignores the payload; the side effect (warm cache) is the point.
        $rcmail->output->send();
    }
}
