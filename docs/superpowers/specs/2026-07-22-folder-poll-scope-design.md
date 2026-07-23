# Bounding new-mail polling to folders that can actually surprise us

**Date**: 2026-07-22
**Status**: design agreed, not implemented
**Supersedes**: commit `659317984` (blanket `check_all_folders` disable), reverted in `13cd045f2`
**Revision 2**: reworked after an adversarial review found the first design did not bound
`getunread` at all, and would have shipped without delivering its headline number.

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

The dominant cost is `folders x 3 x RTT`, serialized, and Zoho offers no way to batch it. Most
commands are one round trip (~0.2s average), though not uniformly — `STATUS` peaked at 9s in the
capture. Even taking the average, volume alone accounts for the observed times.

This also caused data loss. A 64s+ refresh is a wide window for the session write race that
silently erased a user's attachments on send (fixed separately in `1.0.3`). Removing the long
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

Both `Spam` (17) and `Junk` (6) appear in the survey. **Correction (2026-07-22, verified):** this
is not "Zoho provisions different names per account". Dumping the full cached IMAP folder list
(Redis `<uid>:IMAP:mailboxes.*`) for all 55 Zoho users shows **every one of them has `Spam`**, in
Zoho's system-folder block (`INBOX`, `Rascunho`, `Enviadas`, `Spam`, `Lixeira`, `Archive`). `Junk`,
where it exists, sits in the alphabetical user-folder block and **coexists with `Spam`** — it is an
extra folder, not an alternative name. Zero users have `Junk` without `Spam`. The `cache_index`
survey only counts folders a user has *opened*, so it cannot distinguish the two cases; the folder
lists can. `config.inc.php:59`'s `junk_mbox = 'Spam'` is therefore correct for every current user.

Everything else in the survey is either a system folder that never receives unread mail
(`Enviadas`, `Lixeira`, `Rascunho`, `Archive`) or clearly user-created (`Clientes`, `PMOC`).

So the accurate statement is not "checking folders is useless" but: **checking *all* folders is
useless; checking a specific few is not.**

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
tick back down as the filter moves the message out, and the destination folder stays silent.

Fix: call `rcmail_action_mail_index::send_unread_count($target, true)` after each move. Precedent in
core: `plugins/archive/archive.php:291`.

**Scope, stated honestly:** only 3 users currently have any `avuz_filters` rules, and neither of the
two slow users is among them. This fixes a real defect but is not what makes the numbers move — it
is included because it is correct and because it removes any argument for polling filter-target
folders. Do not treat it as load-bearing for the performance goal.

**To verify during implementation:** that `send_unread_count()` called from the `refresh` hook lands
before output is flushed. `check_recent.php:207` fires `refresh` inside the action, before
`output->send()`, so it should — but it must be observed, not assumed.

### 2. Bound the polled folder set — by neutralising the flag, not by filtering one path

The obvious approach — filter the folder list in the `check_recent` hook — **does not work**, and
this is the correction that prompted revision 2.

`getunread.php` has **no hooks at all** (`grep -c exec_hook` → 0). It iterates every subscribed
folder, and the cheap cached path is gated on the same flag:

```php
if (!$check_all && $unseen_old !== null && $mbox != $current) {
    $unseen = $unseen_old;                                     // no IMAP
}
else {
    $unseen = $rcmail->storage->count($mbox, 'UNSEEN', ...);   // real IMAP, every folder
}
```

`app.js:429` fires `getunread` on page init. On prod it averages 5.43s (n=166), peaked at 21.58s,
and hit 20.16s for one user. Bounding only `check_recent` would leave this path walking all 107
folders — the design would not have delivered its headline number, and prod would have been how we
found out.

**The mechanism therefore inverts.** On the `ready` hook (`rcmail.php:228`, which fires once the
user is authenticated and before any action runs):

1. Read the user's real preference and stash it on the plugin.
2. Set `check_all_folders` to `false` in config.

Core now takes the cheap path *everywhere* — `check_recent` limits itself to current + INBOX, and
`getunread` reuses cached counts. Both paths are bounded by one change.

3. Then, on the `check_recent` hook (`check_recent.php:66`), if the stashed preference was true, add
   `allowlist ∩ subscribed` to the folder list.

The preference becomes a signal our plugin interprets, rather than something core acts on.

| `check_all_folders` | Folders polled per refresh | Users |
|---|---|---|
| **off** (default) | INBOX + current folder | ~95 — **unchanged from today** |
| **on** (opt-in) | INBOX + current + allowlist ∩ subscribed | 2 — **107 folders → at most 8** |

**Invariant, stated precisely:** for a user who has NOT enabled the preference, the periodic
`refresh` poll and the `getunread` path are unchanged — those are the paths responsible for the
20-136s refreshes. For users who HAVE enabled it, the set strictly shrinks.

One deliberate exception, which an earlier looser wording of this invariant ("no change
whatsoever") papered over: the explicit **check-recent** action narrows for *everyone*, opted in or
not. `check_recent.php:42` forces `$check_all` true for any action that is not `refresh`, so core
walks every folder there regardless of preference — which is why that action measures 43s average
on prod. The hook overwrites the folder list on every action, so that walk is bounded too. This is
an improvement, not a regression, but it is a behavior change affecting all users and should not be
hidden behind the word "invariant".

#### Folder matching

Exact string matching is not safe. The survey itself contains `INBOX/2- FINANCEIRO`, so **Zoho
nests folders under INBOX on some accounts.** If a user's auto-file folders are `INBOX/Newsletter`,
exact matching finds nothing and the feature silently degrades to off — for precisely the users who
asked for it.

Match on the **last path segment, case-insensitively**: `INBOX/Newsletter`, `Newsletter` and
`newsletter` all match the allowlist entry `Newsletter`. Delimiter comes from the IMAP server rather
than being assumed (`rcube_storage::get_hierarchy_delimiter()`).

#### Configuration

```php
$config['avuz_poll_folders'] = ['Spam', 'Junk', 'Newsletter', 'Notification'];
```

Listing both `Spam` and `Junk` is free — the list is intersected with subscribed folders, so a name
absent from an account is skipped. This covers both Zoho naming conventions.

**Cap: 6 entries.** Derived from a cost budget, not picked arbitrarily — at 3 commands per folder
and ~0.2s per command, 6 folders is ~3.6s of IMAP, which is the most we are willing to add to a
refresh that otherwise takes ~1s. A future config edit listing 50 folders would otherwise quietly
recreate the original problem. Exceeding the cap truncates and logs a warning.

### Rejected alternatives

- **Blanket disable via `dont_override`** — takes the feature from everyone to fix two users, and
  silences Spam/Notification permanently. Committed, then reverted.
- **Filtering only the `check_recent` hook** — the original revision-1 design. Leaves `getunread`
  walking every folder; would not have worked.
- **Allowlist polled for everyone on every refresh** — takes the ~95 well-behaved users from 2
  folders to 6, adding ~1.8s to every refresh to deliver a signal nobody asked for.
- **Allowlist on a slower cadence (~10 min) for everyone** — solves the cost, but needs timestamp
  state and still changes behavior for users who did not request it. Unnecessary once the existing
  preference is the gate.
- **Clear the two users' stored preference, change nothing else** — any user can re-enable it and
  reproduce the outage.

## Trade-offs found in final review (recorded, not fixed)

**1. The two opted-in users have NO filter rules.** Verified against prod: `auxadm@` and
`atendimento@` both return 0 rows from `avuz_filters`. The spec's justification for narrowing —
"everything else is a folder the user made themselves, which our filters already report on" — does
not hold for them, because nothing of ours files into their folders.

**RESOLVED by the service owner, 2026-07-22.** This is a managed tenant: we administer their Zoho
domain and the users have no access to the Zoho interface, so no user-created server-side rule can
exist. The only automatic delivery outside INBOX is Zoho's own classification, which lands in
`Spam`/`Newsletter`/`Notification` — all on the allowlist.

That closes the gap rather than merely bounding it. Their folders (`Financeiro/Maiara`, `PBA
Projetos/Ana Paula`, `Free Flow - PBE`, `Gustavo`, `Modelos`, …) are manual organisational folders;
mail reaches them when the user drags it there, and `move.php:128` already pushes the badge on that
path.

This assumption is worth re-checking if a future tenant is ever given Zoho console access — at that
point users could create server-side rules filing into arbitrary folders, and the allowlist would
no longer cover every automatic-delivery path.

**2. Stale badges now survive a page reload, for opted-in users.** `$_SESSION['unseen_count']` is
written but never invalidated (only `folder_purge.php` zeroes an entry). Previously an opted-in
user's F5 forced `getunread` to recount every folder and self-heal any drift; with the flag
neutralised, `$unseen_old !== null` from the second page load onward, so a stale badge stays stale
until logout. `session_lifetime` here is one week.

**3. `getunread`'s cold pass is still unbounded.** `getunread.php:42-49` skips the cheap branch
whenever `$unseen_old === null`, regardless of the flag, and `app.js:429` fires it on every full
mail page load. So the 107-folder user still pays ~107 forced UNSEEN counts (~20s) on the FIRST
mail page load of each session. The claim that neutralising the flag puts both paths on their cheap
branch is true from load #2, not load #1. Still a large improvement — was every load, now once per
session — but the first paint stays slow.

## Failure modes

| Case | Behavior |
|---|---|
| Allowlist folder absent from the account | Skipped by the intersection; no error, no cost |
| Folders nested (`INBOX/Newsletter`) | Matched by last-segment comparison — the reason that rule exists |
| Allowlist misconfigured with many folders | Truncated at 6, warning logged |
| `ready` hook not reached (plugin disabled) | Core behavior returns: the 107-folder walk comes back for opted-in users. Loud and obvious, not silent |
| `send_unread_count` fails mid-move | Move already happened; badge stale until next visit — no mail lost |
| Second tab open | Does not receive the push; stale until reload |
| Zoho renames its auto folders | Allowlist stops matching; re-run the survey query in this doc |

## Testing

Behavior, not implementation:

- `avuz_filters` reports the destination folder's unread count after moving a message
- ...and reports nothing when no message matched
- With the preference **off**, the polled folder set is unchanged from core's (current + INBOX)
- With the preference **on**, the set is current + INBOX + allowlisted subscribed folders
- ...and never exceeds the cap, even when the config lists more
- ...matches nested folders by last path segment, case-insensitively
- ...excludes allowlist entries the user is not subscribed to
- `getunread` takes the cached path regardless of the user's preference

## Deployment

Normal path: build the image, push, redeploy the stack — no container-local edits. This is config
plus plugin code, so it ships like any other change and carries the usual redeploy downtime, which
is acceptable for this work.

Sequence: build a new version tag, deploy to staging, verify the behavior table above against a
staging account with the preference on, then deploy to prod with the user's go-ahead. Rollback is
the documented `:latest` re-tag procedure in `2026-07-22-PROD-ROLLBACK-ANCHORS.md`.

## Measurement

Session ids rotate on re-login and `logs/php-perf.log` records only the 8-char session id, never a
username — so "watch these two sessions" is not a measurement that survives to the after-state.
Measure the **distribution** instead, which is stable across logins:

```sh
# busybox awk in the container has no asort/gensub — sort in the pipeline instead.
grep " refresh " /var/www/roundcube/logs/php-perf.log \
  | awk '{print $2}' | sort -n > /tmp/r.txt
awk 'END {n=NR} {a[NR]=$1; s+=$1}
     END {printf "n=%d mean=%.2f p50=%.2f p99=%.2f max=%.2f\n",
                 n, s/n, a[int(n*0.5)], a[int(n*0.99)], a[n]}' /tmp/r.txt
```

Acceptance, comparing a full business-hours window before and after:

- `refresh` **max** drops from >100s to <10s
- `refresh` **p99** drops from ~40s to <5s
- `refresh` **mean** and the count of sub-2s requests are unchanged — proving the ~95 unaffected
  users really were unaffected

If a per-user check is wanted at verification time, session ids can be mapped to usernames from the
`userlogins` lines in the container log, but only for sessions created since the last restart.

## Decisions taken

- **Settings label stays as-is** ("Check all folders for new messages") even though the set is now
  bounded. Behavior strictly improves; rewording buys nothing.
- **The preference stays opt-in**, default off. No user's cost changes without them asking for it.
