# Bounding new-mail polling to folders that can actually surprise us

**Date**: 2026-07-22
**Status**: design agreed, not implemented
**Supersedes**: commit `659317984` (blanket `check_all_folders` disable), reverted in `13cd045f2`

## Requirement

New mail must be visible in the folder list without a user opening every folder, at a cost that
does not scale with how many folders that user has.

## The problem, measured

Two users on prod (`auxadm@` and `atendimento@grupovidalar.com.br` — the only two out of ~97) have
the per-user preference **"Check all folders for new messages"** enabled. `check_recent.php:42`:

```php
$check_all = $rcmail->action != 'refresh' || (bool) $rcmail->config->get('check_all_folders');
```

With it on, every background refresh walks every subscribed folder, issuing **three IMAP commands
per folder**. Captured from the wire on prod:

```
20:20:02 A0149 STATUS Financeiro/Maiara (MESSAGES UNSEEN)
20:20:02 A0150 SELECT Financeiro/Maiara
20:20:02 A0151 UID SEARCH 795
20:20:02 A0152 STATUS "Free Flow - PBE" (MESSAGES UNSEEN)
...
```

`auxadm@` has **107 folders** → ~321 serialized commands → refreshes of **20-136s**, every two
minutes, all day, each pinning a PHP worker. `atendimento@` has 42 folders and 20-49s refreshes.
Every other user refreshes in ~1s.

No individual command is slow — each averages ~0.2s, a single Zoho round trip. The cost is purely
`folders x 3 x RTT`, serialized, and Zoho offers no way to batch it.

This also caused data loss. A 64s+ refresh is a wide window for the session write race that
silently erased a user's attachments on send (fixed separately in `1.0.3`, see
[the session merge patch](../../../program/lib/Roundcube/rcube_session.php)). Removing the long
request removes the window.

## Why not simply disable it

The first attempt forced the option off globally via `dont_override`. Rejected on review, because
the feature is not useless — it is the only thing that surfaces mail which never passes through
INBOX. A prod survey of `cache_index` (folders users have actually opened, so real counts are
higher):

| mailbox | users |
|---|---|
| INBOX | 59 |
| Enviadas | 37 |
| **Spam** | **17** |
| **Newsletter** | **16** |
| **Notification** | **11** |
| Lixeira | 10 |
| **Junk** | **6** |
| Rascunho | 6 |
| Archive | 4 |
| Clientes | 2 |
| PMOC | 2 |

`Spam`, `Newsletter`, `Notification` and `Junk` recur across many unrelated accounts — nobody
hand-creates those on 16 mailboxes. They are **Zoho's automatic classification folders**, filled
server-side at delivery. Mail lands there without touching INBOX and without our filters running.

Note both `Spam` (17) and `Junk` (6) exist — Zoho provisions different names per account. Tracked
separately: `config.inc.php:59` hardcodes `junk_mbox = 'Spam'`, which is wrong for the 6 users
whose folder is `Junk`.

Everything else in the survey is either a system folder that never receives unread mail
(`Enviadas`, `Lixeira`, `Rascunho`, `Archive`) or clearly user-created (`Clientes`, `PMOC`).

So the accurate statement is not "checking folders is useless" but: **checking *all* folders is
useless; checking a specific few is not.** The cost is spread over 107 folders when about 4 matter.

## Design

Two independent changes.

### 1. `avuz_filters` pushes its own unread counts

Our filter plugin moves messages by calling storage directly
(`plugins/avuz_filters/lib/filter_runner.php`):

```php
$storage->move_message($uid, $move_to, $folder);
```

This bypasses `move.php:128`, which is what normally pushes the badge:

```php
self::send_unread_count($target, true);
```

Result today: mail filed by our own filters updates **no** badge. The user sees INBOX tick up, then
tick back down as the filter moves the message out, and the destination folder stays silent. This
is a defect in our plugin and exists regardless of any polling setting.

Fix: call `rcmail_action_mail_index::send_unread_count($target, true)` after each move. Precedent
in core: `plugins/archive/archive.php:291` does exactly this.

Consequence: **filter-target folders never need polling.** The request that files the message also
reports the new count. Known gap: the push reaches only the client that made the request, so a
second tab or session will not see it until it reloads. Not worth polling 107 folders to fix.

### 2. Bound the polled folder set

`check_recent.php:65` exposes a hook for precisely this:

```php
$plugin = $rcmail->plugins->exec_hook('check_recent', ['folders' => $a_mailboxes, 'all' => $check_all]);
$a_mailboxes = $plugin['folders'];
```

The plugin's behavior depends on the `all` flag the hook receives, which is core's evaluation of
the user's preference:

- **`all` is false** — return the folder list unchanged. Core has already limited it to current +
  INBOX; there is nothing to bound and nothing to add.
- **`all` is true** — *replace* the list (which at this point is every subscribed folder) with
  `{current, INBOX} ∪ (allowlist ∩ subscribed)`.

So the plugin only ever removes folders, never adds them. A user who has not opted in cannot have
their polling cost increased by this change under any configuration.

The `check_all_folders` preference keeps working but becomes bounded:

| `check_all_folders` | Folders polled per refresh | Users |
|---|---|---|
| **off** (default) | INBOX + current folder | ~95 — **unchanged from today** |
| **on** (opt-in) | INBOX + current + allowlist ∩ subscribed | 2 — **107 folders → ≤6** |

Nobody walks all folders. Nobody who did not opt in pays anything new. The two users keep the
feature they deliberately enabled, at ~3.6s instead of 64s.

Config:

```php
$config['avuz_poll_folders'] = ['Spam', 'Junk', 'Newsletter', 'Notification'];
```

Listing both `Spam` and `Junk` is free — the list is intersected with the user's subscribed
folders, so a name absent from an account is skipped. This covers both Zoho naming conventions.

The allowlist is **capped at 10 entries**. A future config edit listing 50 folders would otherwise
quietly recreate the original problem.

### Rejected alternatives

- **Blanket disable via `dont_override`** — takes the feature from everyone to fix two users, and
  silences Spam/Notification permanently. Committed, then reverted.
- **Allowlist polled for everyone on every refresh** — would take the ~95 well-behaved users from 2
  folders to 6, adding roughly 1.8s to every refresh, to deliver a signal nobody asked for.
- **Allowlist on a slower cadence (~10 min) for everyone** — solves the cost, but needs timestamp
  state in the session and still changes behavior for users who did not request it. Made
  unnecessary by gating on the existing preference instead.
- **Clear the two users' stored preference, change nothing else** — least effort, but any user can
  re-enable it and reproduce the outage.

## Failure modes

| Case | Behavior |
|---|---|
| Allowlist folder not subscribed / absent | Skipped by the intersection; no error, no cost |
| Allowlist misconfigured with many folders | Capped at 10 |
| Hook not reached (plugin disabled) | Core behavior; the 107-folder walk returns for opted-in users |
| `send_unread_count` fails mid-move | Move already happened; badge stale until next visit — no mail lost |
| Second tab open | Does not receive the push; stale until reload |
| Zoho renames its auto folders | Allowlist silently stops matching; needs a survey re-run |

## Testing

Behavior, not implementation:

- `avuz_filters` reports the destination folder's unread count after moving a message
- ...and does not report a count when no message matched
- The `check_recent` hook returns only allowlisted folders that the user is subscribed to
- ...includes INBOX and the current folder regardless of the allowlist
- ...never returns more than the cap, even when the config lists more
- ...leaves the folder list untouched when `check_all_folders` is off

## Measurement

Before/after on prod via `logs/php-perf.log`, which records PHP-only duration keyed by session id:

```
grep " refresh " logs/php-perf.log | awk '{n[$3]++; s[$3]+=$2} END{for(x in n) print s[x]/n[x], n[x], x}'
```

Acceptance: the two opted-in sessions drop from 20-136s to under 5s, and the ~95 other sessions
stay at ~1s (no regression). Both are directly observable without instrumentation changes.

## Open questions

- The Settings label still reads "Check all folders for new messages" while now checking a bounded
  set. Leave it, or override the label? Low stakes; behavior strictly improves either way.
- Should the preference default to on now that it is cheap? Recommendation: no — keep it opt-in so
  no user's cost changes without them asking.
