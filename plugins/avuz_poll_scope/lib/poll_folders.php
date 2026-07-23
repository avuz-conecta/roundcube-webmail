<?php

/**
 * Which of a user's folders are worth polling for new mail.
 *
 * Pure so it can be unit tested without a Roundcube bootstrap. The plugin class
 * supplies the subscribed list, the config allowlist and the server's hierarchy
 * delimiter; every matching and capping decision happens here.
 */
class avuz_poll_folders
{
    /**
     * Most folders we will ever add to a poll. At three IMAP commands per folder
     * and ~200ms per command against Zoho, six folders is ~3.6s — the most we are
     * willing to add to a refresh that otherwise takes ~1s. Without a cap, a
     * config edit listing many folders would quietly recreate the original
     * 107-folder problem.
     */
    public const CAP = 6;

    /**
     * @param string[] $subscribed folders the user is subscribed to
     * @param string[] $allowlist  folder names to poll, e.g. ['Spam', 'Newsletter']
     * @param string   $delimiter  IMAP hierarchy delimiter ('/' or '.')
     * @param int      $cap        maximum folders to return
     *
     * @return string[] subset of $subscribed, in $subscribed order
     */
    public static function select(array $subscribed, array $allowlist, string $delimiter, int $cap): array
    {
        if (empty($allowlist)) {
            return [];
        }

        // Compare on the last path segment: Zoho nests auto-filed folders under
        // INBOX on some accounts (INBOX/Newsletter), so an exact match on the full
        // name would silently find nothing for exactly the users who want this.
        $wanted = [];
        foreach ($allowlist as $name) {
            $wanted[mb_strtolower((string) $name)] = true;
        }

        $selected = [];
        foreach ($subscribed as $folder) {
            $folder = (string) $folder;
            $leaf   = $delimiter !== '' ? self::leaf($folder, $delimiter) : $folder;

            if (isset($wanted[mb_strtolower($leaf)])) {
                $selected[] = $folder;

                if (count($selected) >= $cap) {
                    break;
                }
            }
        }

        return $selected;
    }

    private static function leaf(string $folder, string $delimiter): string
    {
        $pos = strrpos($folder, $delimiter);

        return $pos === false ? $folder : substr($folder, $pos + strlen($delimiter));
    }

    /**
     * Decide which folders check_recent.php should end up polling, given the
     * list core already built and the (already resolved, already capped)
     * allowlist. Pure so it can be unit tested without a Roundcube bootstrap;
     * avuz_poll_scope::bound_folders() supplies the inputs.
     *
     *   - $all true  — core walked EVERY subscribed folder (the expensive case
     *     this plugin exists to bound). REPLACE: return INBOX, plus $current
     *     when non-empty, plus $allowlist_folders.
     *   - $all false — core made a deliberate, already-bounded choice (an open
     *     search's folders, or current+INBOX; see bound_folders()'s docblock).
     *     PRESERVE $core_folders in order and APPEND $allowlist_folders. Never
     *     remove anything core chose — that was the shipped regression this
     *     method exists to prevent.
     *
     * $allowlist_folders is not re-filtered or re-capped here; that already
     * happened in avuz_poll_folders::select().
     *
     * @param string[] $core_folders      $args['folders'] as core built it
     * @param bool     $all               $args['all'] from the check_recent hook
     * @param string   $current           the currently open folder, '' if none
     * @param string[] $allowlist_folders resolved allowlist, already capped
     *
     * @return string[] de-duplicated list, first occurrence wins
     */
    public static function merge(array $core_folders, bool $all, string $current, array $allowlist_folders): array
    {
        if ($all) {
            $folders = ['INBOX'];
            if ($current !== '') {
                $folders[] = $current;
            }
            $folders = array_merge($folders, $allowlist_folders);
        }
        else {
            $folders = array_merge($core_folders, $allowlist_folders);
        }

        return array_values(array_unique($folders));
    }
}
