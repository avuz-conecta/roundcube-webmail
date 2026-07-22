<?php

require_once __DIR__ . '/lib/prefetch_cache.php';

/**
 * avuz_prefetch — instant message opens against a remote IMAP (Zoho).
 *
 * Roundcube's own messages_cache does NOT persist body content (rcube_imap_cache
 * strips $msg->body), so we keep our own redis-backed body cache:
 *
 *   1. prefetch(): a background request warms the text bodies of the visible list
 *      page, storing each into our cache (keyed folder:uid:mime_id).
 *   2. serve_cached_body(): the 'message_part_body' hook (fires before the IMAP
 *      fetch in rcube_message::get_part_body) sets $part->body from our cache, so
 *      Roundcube's `if ($part->body === null) fetch` is skipped → no IMAP round-trip.
 *
 * Text parts only (html/plain). Bodies fetched with BODY.PEEK: never sets \Seen.
 */
class avuz_prefetch extends rcube_plugin
{
    public $task = 'mail';

    private const MAX_UIDS = 10;
    // 5 days, not 10: halves the accumulated body working set so Redis stays well
    // under maxmemory and allkeys-lru never evicts a session. A message untouched
    // for 5 days simply re-warms once on next open. See the Wave 1.5 design doc.
    private const TTL      = '5d';

    /** @var rcube_cache|false|null */
    private $bodyCache;

    function init()
    {
        $this->register_action('plugin.avuz_prefetch', [$this, 'prefetch']);
        $this->add_hook('message_part_body', [$this, 'serve_cached_body']);
        $this->include_script('prefetch.js');
    }

    /** redis-backed, per-user body cache (false if unavailable, e.g. not logged in) */
    private function cache()
    {
        if ($this->bodyCache === null) {
            $this->bodyCache = rcmail::get_instance()->get_cache('avuz_body', 'redis', self::TTL) ?: false;
        }
        return $this->bodyCache;
    }

    private function key($folder, $uid, $mimeId)
    {
        return avuz_prefetch_cache::body_key($folder, $uid, $mimeId);
    }

    private function is_text($part)
    {
        $m = (string) ($part->mimetype ?? '');
        return $m === 'text/html' || $m === 'text/plain';
    }

    /**
     * Hook: serve a cached text body. Setting $part->body makes rcube_message's
     * `if ($part->body === null) { ...fetch... }` skip the IMAP fetch.
     */
    function serve_cached_body($args)
    {
        $part = $args['part'];
        if ($part->body !== null || !$this->is_text($part)) {
            return $args;
        }

        $cache = $this->cache();
        if (!$cache) {
            return $args;
        }

        $message = $args['object'];
        $cached  = $cache->get($this->key($message->folder, $message->uid, $part->mime_id));
        if (is_string($cached) && $cached !== '') {
            $part->body = $cached;
        }

        return $args;
    }

    /** Background: fetch text bodies for the given uids and store them in our cache. */
    function prefetch()
    {
        $rcmail = rcmail::get_instance();
        $uids   = (string) rcube_utils::get_input_value('_uids', rcube_utils::INPUT_POST);
        $mbox   = (string) rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $mbox   = $mbox !== '' ? $mbox : null;

        $cache = $this->cache();
        $list  = array_slice(array_filter(explode(',', $uids), 'strlen'), 0, self::MAX_UIDS);
        $folder = $mbox !== null ? $mbox : $rcmail->storage->get_folder();

        foreach ($list as $rawUid) {
            $uid = (int) $rawUid;
            if ($uid <= 0) {
                continue;
            }

            // Already warmed on an earlier run: skip before building rcube_message,
            // which would cost a BODYSTRUCTURE fetch plus per-level MIME header fetches.
            if (avuz_prefetch_cache::is_warm($cache, (string) $folder, $uid)) {
                continue;
            }

            try {
                $message = new rcube_message($uid, $mbox);
                if (empty($message->headers)) {
                    continue;
                }

                $cachedMimeIds = [];
                foreach ($message->mime_parts as $mimeId => $part) {
                    if (!$this->is_text($part)) {
                        continue;
                    }
                    // get_part_body fires our hook first (cache miss on first run) →
                    // then BODY.PEEK fetch → we store the result for next time.
                    $body = $message->get_part_body($mimeId, false, 0);
                    if ($cache && is_string($body) && $body !== '') {
                        $cache->set($this->key($message->folder, $uid, $mimeId), $body);
                        $cachedMimeIds[] = $mimeId;
                    }
                }

                avuz_prefetch_cache::mark_warm($cache, $folder, $uid, $cachedMimeIds);
            } catch (Throwable $e) {
                rcube::raise_error("avuz_prefetch uid {$uid}: " . $e->getMessage(), true, false);
            }
        }

        $rcmail->output->send();
    }
}
