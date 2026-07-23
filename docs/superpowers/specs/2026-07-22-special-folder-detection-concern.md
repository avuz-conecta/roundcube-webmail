# Special-Folder Detection on Zoho — Deferred Concern

**Date**: 2026-07-22
**Status**: Investigated, verified, **deliberately not fixed**. No user is affected today.
**Trigger to revisit**: the first Zoho account provisioned in a locale other than pt_BR.

---

## The report that started this, and why it was wrong

Reported: `config.inc.php` hardcodes `junk_mbox = 'Spam'`, but a prod `cache_index` survey showed
both `Spam` (17 users) and `Junk` (6 users), so junk handling must be broken for the `Junk` users.

**It is not.** `cache_index` only records folders a user has *opened*, so it cannot tell "this
account's spam folder is named Junk" apart from "this account has an extra folder named Junk".
The full cached IMAP folder lists can, and they live in Redis under `<uid>:IMAP:mailboxes.<hash>`.

Dumping all 55 Zoho users' lists (prod, 2026-07-22):

- **55/55 have `Spam`**, in Zoho's system-folder block: `INBOX, Rascunho, Enviadas, Spam, Lixeira, Archive`.
- `Junk`, where present, sits in the **alphabetical user-folder block** and **coexists** with `Spam`.
- **0 users** have `Junk` without `Spam`. No `Junk` folder has ever been indexed (`<uid>:IMAP:*Junk*` → none).

`Junk` is a leftover/imported folder, not Zoho's spam folder. `junk_mbox = 'Spam'` is correct for
every current user. Do not "fix" it to `Junk`, and do not add a Spam/Junk fallback — there is
nothing to fall back from.

Also worth recording: there is no junk UI to break. `markasjunk` is on disk but **not enabled**
(`config.inc.php` plugins list). No junk/spam command exists in core JS or any skin. `junk_mbox`
reaches the browser as `env.junk_mailbox` and is read in exactly one place, `app.js:3313`, gated on
`delete_junk`, which is `false`. `skip_deleted` is default `false` and unrelated to junk.

Reproduction command for the folder-list dump:

```bash
PORTAINER_ENDPOINT=5 PORTAINER_ENV_FILE=scripts/deploy.prod.env \
  ./scripts/portainer-exec.sh avuz-mail-roundcube-redis-1 sh -c \
  'for k in $(redis-cli --scan --pattern "*:IMAP:mailboxes.05c7329682137ecdb00f5e396343c575"); do
     uid=${k%%:*}; v=$(redis-cli get "$k")
     out="$uid"; for f in Rascunho Enviadas Lixeira Spam Archive; do
       echo "$v" | grep -q "\"$f\"" && out="$out $f"; done; echo "$out"; done'
```

## The real concern underneath

Zoho does **not** advertise `SPECIAL-USE`. Capability capture, 2026-07-22:

```
* CAPABILITY IMAP4rev1 UNSELECT CHILDREN XLIST NAMESPACE IDLE MOVE ID AUTH=PLAIN
  SASL-IR AUTH=XOAUTH2 UIDPLUS ESEARCH LIST-EXTENDED LIST-STATUS WITHIN LITERAL- ACL CONDSTORE
```

`rcube_imap::get_special_folders()` (`program/lib/Roundcube/rcube_imap.php:3436`) returns early
without `SPECIAL-USE`, so Roundcube's **per-user** special-folder detection never runs for Zoho.
The global names in `config.inc.php` are the only thing pointing at Drafts/Sent/Trash/Junk.

Confirmed in the prod DB: Zoho users have **no** `*_mbox` rows in `users.preferences`, while the
digrepal users (`vinicius@`, `priscila@` — that server *does* advertise `SPECIAL-USE`) auto-detected
`INBOX.Drafts` / `INBOX.Sent` / `INBOX.Trash` / `INBOX.Spam` and are correct **despite** the same
global config saying `Rascunho` / `Enviadas` / `Lixeira`. The safety net works; Zoho just never
gets it.

**So the failure mode is:** Zoho names system folders in the account's own language. A Zoho account
provisioned in English gets `Drafts` / `Sent` / `Trash`, Roundcube keeps pointing at
`Rascunho` / `Enviadas` / `Lixeira`, and the user silently loses sent-mail copies, gets failed
deletes, and loses drafts. No error banner — just the wrong folders. Today every Zoho account in
the tenant happens to be pt_BR, which is coincidence, not design.

## The fix, when it is needed

Zoho advertises `XLIST` (pre-RFC6154 Gmail-era equivalent; returns `\Sent \Drafts \Trash \Spam`
style flags per folder). Roundcube 1.6 implements none of it.

Sketch:

1. Probe an authenticated Zoho session for what `XLIST "" "*"` actually returns and which flag
   names it uses (`\Spam` vs `\Junk`). **Do this first — everything below is sized by it.**
2. Add an XLIST path to special-folder detection: hook `storage_init` / override
   `get_special_folders()` to fall back to XLIST when `SPECIAL-USE` is absent, mapping XLIST flags
   onto `rcube_storage::$folder_types`.
3. Demote the `config.inc.php` names to fallback-only, so detection is per-user and the globals
   only apply when the server offers neither mechanism.

This also removes the digrepal/Zoho asymmetry: one detection path, both providers.

**Not doing it now** — no user is affected, it touches core `rcube_imap` (rebase cost at every
upstream merge), and it cannot even be designed without step 1.

## Unrelated issue found during this investigation

`pblpcp@austerengenharia.com.br` (uid 30) has **no `Lixeira` folder**, but does have
`Lixeira/foldertest*` children. Its `trash_mbox` points at a folder that does not exist, so deletes
will fail for that user. The `foldertest*` folders come from the system health checker (`8aea13561`)
and are scattered under `Lixeira/` across several accounts. Parent gone, children left. Tracked
separately.
