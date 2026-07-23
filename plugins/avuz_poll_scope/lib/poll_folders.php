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
}
