<?php

/** Which folders to sweep, and when the next pass is due. */
class avuz_cleanup_plan
{
    const IDLE_INTERVAL_SECONDS = 300;

    /**
     * Sent first, then any configured outbox folder that exists. Trash is
     * never swept: receipts already there must stay there.
     *
     * @param callable(string):bool $folder_exists
     * @return string[]
     */
    public static function folders(string $sent, array $extra_folders, string $trash, callable $folder_exists): array
    {
        $folders = [];
        foreach (array_merge([$sent], $extra_folders) as $folder) {
            $folder = (string) $folder;
            if ($folder === '' || $folder === $trash || in_array($folder, $folders, true)) {
                continue;
            }
            if ($folder_exists($folder)) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    public static function is_due(int $now, ?int $next_run_at): bool
    {
        return $next_run_at === null || $now >= $next_run_at;
    }

    /** A remaining backlog is worked on the very next refresh. */
    public static function next_run_at(int $now, bool $pending): int
    {
        return $pending ? $now : $now + self::IDLE_INTERVAL_SECONDS;
    }
}
