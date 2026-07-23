# Folder Rename — Findings

**Date**: 2026-07-23
**Status**: Investigation only. No code, config, or deployed system changed.
**Question**: Can users rename mail folders today, and if not, why not / what would it take?

---

## Answer: partly

- **The feature exists and is unmodified from upstream 1.6.14.** Settings > Folders lets a user
  click a folder and rename it; nothing in this fork disables the UI or the IMAP call.
- **It works for ordinary user-created folders** (e.g. `Financeiro/Maiara`). IMAP `RENAME` is base
  IMAP4rev1 and Zoho's capability string doesn't need to advertise it separately.
- **It does not work — by design — for the four folders users actually think of as "my folders":**
  Rascunho (Drafts), Enviadas (Sent), Lixeira (Trash), Spam. Those are blocked client-side by
  `protect_default_folders` (default `true`, not overridden in this fork), independent of Zoho
  IMAP capabilities.
- **Nothing in this fork updates plugin-owned state when a rename succeeds.** `avuz_filters` rule
  targets are the one that actually breaks silently (see below); `avuz_poll_scope` and
  `avuz_prefetch` degrade gracefully.

---

## 1. Does the UI expose folder rename, and is anything disabling it?

Unmodified upstream code path, confirmed present and wired end-to-end:

- `program/actions/settings/folders.php` renders the folder list; `folder_edit.php:100-119` builds
  the rename `<input>` unless the folder is `protected` or `norename`.
- `program/actions/settings/folder_rename.php` is the AJAX handler for the `rename-folder` command.
  It fires `exec_hook('folder_rename', ['oldname' => ..., 'newname' => ...])` (line 55) — **no
  plugin in this fork listens to this hook** (grepped `plugins/avuz_filters`, `avuz_poll_scope`,
  `avuz_prefetch` — zero hits on `folder_rename` or `folder_update`).
- `program/js/app.js:548` enables the `rename-folder` command; `app.js:7447` POSTs
  `_folder_oldname`/`_folder_newname` to it. Standard double-click-to-rename in the folder manager.
- `program/lib/Roundcube/rcube_imap.php:3408` `rename_folder()` calls
  `rcube_imap_generic::renameFolder()` (`rcube_imap_generic.php:1484`), which is a bare
  `RENAME <from> <to>` — no fork customization on this path at all.

**What gates whether a given folder can be renamed** —
`program/actions/settings/folders.php:329-338` (`folder_options()`):

```php
$options['protected'] = !empty($options['is_root'])
    || strtoupper($mailbox) === 'INBOX'
    || (!empty($options['special']) && $rcmail->config->get('protect_default_folders'));
```

and `rcube_imap.php:3862-3894` (`folder_info()`), which sets `$options['special']` from
`is_special_folder()` (`rcube_storage.php:861-864`) and `$options['norename']` from ACL rights
(`MYRIGHTS`) when the server reports them, or `is_root`/non-personal-namespace otherwise.

**Config in this fork:**

- `config/defaults.inc.php:928` `$config['protect_default_folders'] = true;` — **not overridden**
  in `config/config.inc.php` (confirmed identically on staging via `portainer-exec.sh`:
  `grep protect_default_folders` returns nothing in the deployed file, so the compiled-in default
  applies).
- `config/config.inc.php:71-74` sets `drafts_mbox = 'Rascunho'`, `sent_mbox = 'Enviadas'`,
  `trash_mbox = 'Lixeira'`, `junk_mbox = 'Spam'`.
- `program/lib/Roundcube/rcube_storage.php:869-884` `get_special_folders()` (the **base**
  implementation, used by `is_special_folder()`) builds its list straight from these `*_mbox`
  config values — **it is not gated on the `SPECIAL-USE` IMAP capability**. Only the *subclass*
  override, `rcube_imap::get_special_folders($forced = true)` (`rcube_imap.php:3508`), is gated on
  `SPECIAL-USE` (used for auto-detection/UI preferences, not for `is_special_folder()`).
- Net effect: even though Zoho does not advertise `SPECIAL-USE`
  (`docs/superpowers/specs/2026-07-22-special-folder-detection-concern.md` capability capture,
  2026-07-22), `is_special_folder('Rascunho')` etc. still return `true` because they match by
  **name** against config, not by server flag. So Rascunho/Enviadas/Lixeira/Spam are `protected`
  and un-renamable in the UI today, and this is **not accidental** — it is the intended, working
  fallback for a server with no `SPECIAL-USE`.
- `dont_override` (`config/config.inc.php:160`) is `['skin']` only — irrelevant to folders.
- `managesieve` is removed from `$config['plugins']` (`config/config.inc.php:112-123`,
  comment at line 116-118) and has no relationship to folder rename; it only ever gated
  Settings > Filters, not Settings > Folders.

**Conclusion for Q1**: the rename feature is present, unmodified, and reachable. It is blocked only
for the four system folders, by design, via stock `protect_default_folders` logic matched against
this fork's Portuguese folder names. User-created folders (the overwhelming majority of the ~107
per-user folders described in the latency handoff) are renamable as far as the UI/permission layer
is concerned.

---

## 2. Does IMAP RENAME actually work against Zoho through imapproxy?

**Reasoning, since no rename was executed (per instructions):**

- `RENAME` is base IMAP4rev1 (present in every server that claims the string, including Zoho's
  capability list quoted in the task). No `X`-prefixed or optional extension is required for the
  bare rename of a leaf folder.
- Zoho advertises `CHILDREN`, which just means `LIST` responses carry `\HasChildren`/
  `\HasNoChildren` — irrelevant to whether rename itself is supported, but relevant to correctness:
  Roundcube's `rename_folder()` (`rcube_imap.php:3408-3457`) walks the **subscribed list** to also
  re-subscribe/unsubscribe children under the new name (lines 3439-3449) and clears the message
  cache per child. This is standard RFC 3501 behavior (renaming a non-hierarchical name renames
  its whole subtree server-side); Roundcube's job is only to fix up client-side
  bookkeeping (subscriptions, caches), which it does generically — nothing Zoho-specific breaks
  this.
- **System folders**: as covered in Q1, Rascunho/Enviadas/Lixeira/Spam are blocked client-side
  before any IMAP command is sent — the question of whether Zoho itself would refuse a `RENAME
  INBOX foo` or `RENAME Spam foo` never gets tested, because Roundcube's UI/AJAX layer already
  rejects it. (Most IMAP servers, Zoho included by convention, also refuse `RENAME` of `INBOX`
  server-side — `RENAME INBOX` is special-cased in RFC 3501 to *create* INBOX and move its
  contents rather than rename it, and servers commonly reject renaming their own Sent/Trash/
  Drafts/Spam mailboxes outright. Untested here because it is moot: the UI never sends it.)
- **Hierarchy delimiter**: `rename_folder()` uses `$storage->get_hierarchy_delimiter()` throughout
  for the child-subscription-fixup regexes (`rcube_imap.php:3418, 3441-3444`). This is read from
  Zoho's `LIST` response at connection time and is provider-supplied, not hardcoded — no fork
  customization here to worry about.
- **imapproxy / `XPROXYREUSE` pooled connections**: this is the one area I could **not** verify
  without performing a live rename (out of scope). What is confirmed, read-only:
  - `docker/imapproxy-sidecar/imapproxy.conf:8-9` — `cache_expiration_time 1800`,
    `enable_select_cache no`. The comment in
    `docs/superpowers/plans/2026-06-30-roundcube-imapproxy.md:159` explains why:
    *"caching SELECT serves stale message counts and hides new mail through imapproxy
    (Roundcube #4505)"*. This means imapproxy does **not** cache the `SELECT` response body for a
    mailbox, which removes the most obvious rename hazard (a stale cached `EXISTS`/`UIDVALIDITY`
    for the pre-rename name being served after rename).
  - `up-imapproxy`'s whole point, though, is reusing an **authenticated** backend connection
    across HTTP requests via the `XPROXYREUSE` capability tag — confirmed elsewhere in this
    fork's own hardening work (`docs/superpowers/specs/2026-07-23-search-pipelining-hardening-design.md:175-180`)
    to have already caused real desync bugs under connection reuse ("if pooled connections are
    ever poisoned, restarting the imapproxy sidecar clears them — verified on staging
    2026-07-23"). Renaming the *currently selected* mailbox on a live connection is exactly the
    kind of state transition (RFC 3501 says the client must not issue further commands against
    the renamed mailbox without re-selecting) that a naive connection-reuse layer could mishandle
    if it does not track "was this backend connection's selected mailbox just renamed out from
    under it." **I found no evidence this specific case has been hit or tested** — it is a
    plausible risk given the class of bug already seen in this codebase, not a confirmed one.
    Recommended verification: on staging, rename a disposable test folder while it is the
    currently-open mailbox in another tab, and check `imap.log` for `BAD`/desync symptoms
    afterward (same signature already documented for the pipelining desync bug).

**Conclusion for Q2**: nothing in Zoho's advertised capability list or in this fork's IMAP layer
should stop `RENAME` from working for ordinary folders. The one open, unverified risk is
imapproxy connection-reuse interaction with a renamed *currently-selected* mailbox — flagged for a
staging smoke test, not confirmed as broken.

---

## 3. What breaks in this fork if a folder IS renamed?

Checked every plugin that binds folder names by identity (string), since Roundcube's core state
(subscriptions, per-folder `message_threading` prefs, cache) is already fixed up generically by
`rename_folder()` (`program/actions/settings/folder_rename.php:50-93`, itself upstream/unmodified).

### 3a. `avuz_filters` — real breakage, silent, no error surfaced (the biggest risk)

- Rule storage: `plugins/avuz_filters/SQL/postgres.sql` — `avuz_filters.actions` is a JSON text
  blob; `avuz_filter_state` has a `folder VARCHAR(255)` primary-key column
  (`plugins/avuz_filters/lib/rules_store.php:52-70`, `get_state`/`set_state`).
- **The watermark itself is safe**: `filter_runner.php:28` hardcodes `$folder = 'INBOX'` — the
  *only* folder ever watched/watermarked is INBOX, which is un-renamable (Q1). So
  `avuz_filter_state` is never at risk from a rename.
- **The actual exposure is rule *targets***. The filters UI stores a `move` action's destination
  folder as a plain name string: `plugins/avuz_filters/avuz_filters.js:121`
  `actions.push({ type: 'move', folder: $('.af-afolder', this).val() })`, persisted verbatim by
  `rules_store.php:save_rule()` into the `actions` JSON column. There is no foreign key, no
  listener on `folder_rename`, and no validation against the live folder list at save time beyond
  what the `<select>` (`avuz_folder_select()`, `avuz_filters.js:52-53`) happened to be populated
  with when the rule was last edited.
- **What happens when that target folder is renamed:**
  1. `filter_runner.php:122-138` (`move_target()`) resolves the stored (now-stale) name into
     `$move_to`.
  2. `filter_runner.php:166-169` (`apply()`):
     ```php
     if ($move_to !== null) {
         $storage->move_message($uid, $move_to, $folder); // terminal: removes from INBOX
         return $move_to;
     }
     ```
     **The return value of `move_message()` is never checked.** `rcube_imap::move_message()`
     (`program/lib/Roundcube/rcube_imap.php:2687-2755`) calls `$this->conn->move(...)` and simply
     returns `false` on IMAP failure (target mailbox doesn't exist → Zoho replies `NO`) — it does
     **not** throw. Roundcube's own trash-only auto-create fallback (line 2720-2726,
     `TRYCREATE`) exists solely for the trash case; there is no equivalent for an arbitrary `move`
     target, so the message is simply **not moved** and stays in INBOX.
  3. `apply()` still returns `$move_to` unconditionally (line 168), so the caller
     (`filter_runner.php:77-81`) records it in `$filled[$moved_to] = true` **as if the move
     succeeded**.
  4. The watermark advances anyway: `filter_runner.php:64` (`$maxUid = max($maxUid, (int)$uid);`)
     runs for every iterated UID regardless of whether `apply()` actually filed the message, and
     `set_state()` at line 84 persists `$maxUid`. **This specific message's UID is now behind the
     watermark forever** — the next pass's `UID search 'UID '.($last+1).':*'` (line 49) will never
     see it again. It is not retried; it just sits, permanently unfiled, in INBOX.
  5. The one place an error *could* surface — `send_unread_count($filled_folder, ...)` for the
     (nonexistent, renamed-away) folder at line 106-112 — is wrapped in its own try/catch and only
     writes to `errors.log` as *"unread count push failed for folder X"*. That message reads like
     a cosmetic badge-push bug, not "your filter silently stopped filing mail," which would mislead
     whoever reads the log during an incident.

  **Net effect**: a user renames a folder that a filter rule targets → every future matching
  message for that rule quietrly stays in INBOX forever, no error the user or an on-call engineer
  would recognize as filter breakage, and the rule looks "enabled" and unchanged in the UI (the
  rule editor would still show the *old* name in the folder `<select>` until edited, since nothing
  re-validates it — worth confirming visually on staging, not done here since it requires an
  actual rename).

### 3b. `avuz_poll_scope` — degrades safely

- `$config['avuz_poll_folders'] = ['Spam', 'Junk', 'Newsletter', 'Notification']`
  (`config/config.inc.php:142`) is matched against subscribed folders by **leaf name**, case-
  insensitive (`plugins/avuz_poll_scope/lib/poll_folders.php:38-58`, `select()`).
- If a user renames one of these folders (e.g. their `Newsletter`), the allowlist simply stops
  matching it — `isset($wanted[mb_strtolower($leaf)])` is `false` — and that folder silently drops
  out of the bounded new-mail poll. No error, no crash; the user just stops getting the
  new-mail-badge nudge for that folder until an admin updates the config (or they rename it back).
  Low severity: this is a config-level allowlist, not per-user data, so it can't get "corrupted,"
  only stale.

### 3c. `avuz_prefetch` — degrades safely (wasted cache, not corruption)

- Redis body cache keys are folder-name-keyed strings:
  `plugins/avuz_prefetch/lib/prefetch_cache.php:30-37` — `body_key($folder,$uid,$mimeId) = "$folder:$uid:$mimeId"`,
  `done_key()` likewise.
- A rename orphans every existing key under the old name (they simply age out at the existing 5-day
  TTL per the latency handoff's Wave 1 notes) and the folder cold-starts under the new name — the
  next prefetch pass re-warms it from scratch. This is the same "self-heals" property already
  documented for the legacy `'1'` sentinel migration in
  `docs/superpowers/specs/2026-07-22-open-latency-HANDOFF.md:241-242`. No user-visible error, just
  one avoidable round of cold opens for that folder and some wasted Redis memory until TTL.

### 3d. Anything else touching folder names by identity

- Core `message_threading` per-folder view-mode prefs are fixed up generically by
  `folder_rename.php:65-82` (regex rewrite of the pref array keyed by folder name) — this is
  stock upstream code, already correct, not a fork concern.
  `$_SESSION['mbox']` is also fixed up in the same handler (line 85-87).
- No other Avuz plugin (`nextcloud_sso`, `password`/`zoho_broker`, `archive`, `zipdownload`) stores
  or matches folder names; grepped each plugin directory for `folder` — only `archive` uses a
  configurable-but-single "Archive" root folder name, which is core upstream logic and out of
  scope for a user-initiated arbitrary rename (it's a well-known fixed target, would break exactly
  like any `*_mbox` target if renamed, same category as 3a but touching upstream `archive` plugin,
  not an Avuz customization).

**No Roundcube event a plugin could listen to already covers this end-to-end**: the only hook
fired is `folder_rename` (`folder_rename.php:55`, `['oldname', 'newname']`), and it is an
*abort/override* hook (a plugin can set `abort` + do the rename itself and set `result`), not a
*notification-after-the-fact* hook — but nothing stops a plugin from also treating it as the
latter (return nothing, let core do the rename, but still run migration side effects keyed off
`oldname`/`newname`). No plugin in this fork does that today.

---

## 4. Smallest safe change to make rename actually safe here

Rename is not "broken" today in the sense of failing — it is **unsafe** in the sense that IMAP
will happily do it and this fork's own filter feature will then silently misfire. Ranked:

1. **Smallest, highest-value fix — make `avuz_filters` fail loud instead of silently, and migrate
   rule targets on rename.**
   - Check `move_message()`'s return in `filter_runner.php::apply()` (currently ignored at line
     167). On `false`, do **not** add the folder to `$filled` and do **not** let the watermark
     silently swallow the UID — either retry-next-pass (do not advance `$maxUid` past it) or
     write a clear, greppable `errors.log` line naming the rule and the missing folder. This alone
     turns "permanently lost mail, no signal" into "message stays in INBOX, and it's visible in
     the log or still sitting there for the user to notice."
   - Add a `folder_rename` hook listener in `avuz_filters.php` that:
     - Loads all rules for the request's session user (or all users — the hook fires
       account-scoped, per current session, so per-user is natural and cheap: one query).
     - Rewrites any `actions[].folder === $oldname` (and, if hierarchical, any prefixed by
       `$oldname . $delimiter`, mirroring the exact prefix logic core already uses for
       `message_threading` at `folder_rename.php:69-78`) to `$newname`, and re-saves via
       `avuz_rules_store::save_rule()`.
     - This is a small, additive, low-risk change: one new hook registration + a rule rewrite
       using the same regex pattern core already applies to `message_threading`, no schema change
       (targets already live in the existing JSON `actions` column).
   - Estimated size: <100 lines, one plugin file, one hook, covered by unit tests against
     `rule_engine_test.php`'s existing harness style (pure functions, no live IMAP needed for the
     rewrite logic itself).

2. **Cheap safety net regardless of (1): validate the target folder still exists when a rule
   fires**, not only when it's edited — `filter_runner.php` could call
   `$storage->folder_exists($move_to)` before `move_message()` and treat a missing target as a
   hard per-rule error (log + skip, don't advance watermark for that UID), so even an *unmigrated*
   stale rule fails safely instead of silently.

3. **Before enabling anything, confirm the imapproxy connection-reuse question from Q2** on
   staging (rename a disposable folder while it's the selected mailbox in a second tab, watch
   `imap.log` for `BAD`/desync) — this is orthogonal to (1)/(2) and affects rename's basic
   reliability, not just filters.

4. **Not recommended**: touching `protect_default_folders` or the special-folder detection gap
   documented in `2026-07-22-special-folder-detection-concern.md`. That gap is a *different*,
   already-tracked, deliberately-deferred concern (Zoho account locale mismatches with the
   hardcoded `*_mbox` config) and is unrelated to whether ordinary folder rename is safe. Do not
   conflate the two in any future fix.

**No fork-side code change is required to make plain folder rename "work"** — it already does,
end to end, for non-system folders. The only work needed is the plugin-state migration in (1),
which is a filters-plugin change, not a rename-feature change.

---

## Evidence index (file:line)

| Claim | File:line |
|---|---|
| Rename UI command wiring | `program/js/app.js:548`, `:7447` |
| AJAX handler + `folder_rename` hook | `program/actions/settings/folder_rename.php:30-93` (hook at line 55) |
| Core rename fixup (subscriptions, threading prefs, session) | `folder_rename.php:65-93` |
| `rcube_imap::rename_folder()` | `program/lib/Roundcube/rcube_imap.php:3408-3457` |
| `rcube_imap_generic::renameFolder()` (bare `RENAME`) | `program/lib/Roundcube/rcube_imap_generic.php:1484-1489` |
| Protected-folder gating | `program/actions/settings/folders.php:329-338` |
| `norename` from ACL/root/namespace | `program/lib/Roundcube/rcube_imap.php:3862-3894` |
| `is_special_folder()` matches by config name, not `SPECIAL-USE` | `program/lib/Roundcube/rcube_storage.php:861-884` |
| `protect_default_folders` default `true` | `config/defaults.inc.php:928` |
| Fork's `*_mbox` names (Rascunho/Enviadas/Lixeira/Spam) | `config/config.inc.php:71-74` |
| `dont_override` irrelevant to folders | `config/config.inc.php:160` |
| managesieve removed, unrelated to folder rename | `config/config.inc.php:112-123` |
| avuz_filters watermark hardcoded to INBOX (safe) | `plugins/avuz_filters/lib/filter_runner.php:28` |
| Rule `move` target stored as raw string | `plugins/avuz_filters/avuz_filters.js:121`, `plugins/avuz_filters/lib/rules_store.php:23-45` |
| `move_message()` return ignored; watermark advances regardless | `plugins/avuz_filters/lib/filter_runner.php:62-84, 148-172` |
| `move_message()` returns `false` silently on missing target | `program/lib/Roundcube/rcube_imap.php:2687-2755` |
| Misleading error surface (badge push, not routing failure) | `plugins/avuz_filters/lib/filter_runner.php:102-112` |
| `avuz_poll_scope` allowlist match-by-leaf-name (degrades safely) | `plugins/avuz_poll_scope/lib/poll_folders.php:29-58` |
| `avuz_prefetch` cache keyed by folder name (degrades safely, 5d TTL) | `plugins/avuz_prefetch/lib/prefetch_cache.php:30-37` |
| imapproxy `enable_select_cache no` (mitigates stale-SELECT risk) | `docker/imapproxy-sidecar/imapproxy.conf:8-9` |
| Rationale for `enable_select_cache no` | `docs/superpowers/plans/2026-06-30-roundcube-imapproxy.md:159` |
| Prior connection-reuse desync bug in this codebase (different feature, same connection-pooling class) | `docs/superpowers/specs/2026-07-23-search-pipelining-hardening-design.md:175-192` |
| Zoho has no `SPECIAL-USE`; per-user detection never runs | `docs/superpowers/specs/2026-07-22-special-folder-detection-concern.md` |
| Staging config confirmed to match repo (read-only check) | `portainer-exec.sh` against `avuz-mail-roundcube-2-roundcube-1`, 2026-07-23 |
