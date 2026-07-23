# Search Alternatives — Research Findings

**Date**: 2026-07-23
**Branch**: `avuz-customization`
**Status**: Research only. No decision taken, no code written.
**Relates to**: `2026-07-22-search-latency-design.md` (the round-trip work this document does *not*
replace), `2026-07-23-search-pipelining-hardening-design.md`, `2026-06-30-roundcube-latency-design.md`

## The question

Round-trip optimisation (pipelining, prefetch, poll scoping) has taken all-folder search from
serial N×2×198ms down to ~6.4s in a spike. It cannot go further, and it cannot touch two costs:

1. **Zoho's own SEARCH TEXT compute** — 69s observed for a body search. That is server-side work on
   Zoho's machines. No client-side change removes it.
2. **Sorting by anything but arrival** — Zoho advertises no `SORT`, so Roundcube must
   `FETCH 1:* (UID INTERNALDATE BODY.PEEK[HEADER.FIELDS (DATE)])`. 82s on a 20k INBOX.

A heavy user wants "search entire message" as the *default*. At 69s per search, the current
architecture cannot deliver that. This document asks what experienced operators do instead.

## Evidence conventions

Every claim below is tagged:

- **[VERIFIED-DOC]** — read directly in vendor documentation, linked.
- **[VERIFIED-SRC]** — read directly in source code (Dovecot or this repository), file and line cited.
- **[VERIFIED-REPORT]** — a named person's first-hand account in a public thread, linked.
- **[INFERRED]** — my reasoning from the above. Not observed. **Treat as a hypothesis needing a spike.**

Where I looked for evidence and did **not** find it, I say so rather than filling the gap.

## Ground truth being reasoned from

Zoho IMAP capability, re-verified 2026-07-22 both through `up-imapproxy` and direct:

```
IMAP4rev1 UNSELECT CHILDREN XLIST NAMESPACE IDLE MOVE ID AUTH=PLAIN SASL-IR
AUTH=XOAUTH2 UIDPLUS ESEARCH LIST-EXTENDED LIST-STATUS WITHIN LITERAL- ACL CONDSTORE
```

Absent: `SORT`, `THREAD`, `MULTISEARCH`, `QRESYNC`, `COMPRESS=DEFLATE`.
Present and load-bearing for what follows: **`CONDSTORE`**, `ESEARCH`, `UIDPLUS`, `LITERAL-`,
`AUTH=XOAUTH2`.

---

# Option 1 — Dovecot `imapc` in front of Zoho, with local indexes and FTS

## What it is

Dovecot's `imapc` mailbox format "accesses a remote IMAP server as if it were a regular (local)
Dovecot mailbox format" **[VERIFIED-DOC]**
(https://doc.dovecot.org/main/core/config/mailbox_formats/imapc.html). Roundcube would talk to a
local Dovecot; Dovecot talks to Zoho. Index files are stored locally (`mail_path = ~/imapc`) rather
than in memory.

This is a documented, first-class pattern, not a hack. Dovecot ships a dedicated howto
(https://doc.dovecot.org/main/howto/imapc_proxy.html) and Timo Sirainen described the intent as
"using Dovecot as a smart (caching) proxy" when introducing it around v2.1 in 2012
**[VERIFIED-REPORT]** (https://dovecot.org/list/dovecot/2011-January/056975.html).

## Is it used in production for exactly our situation?

**Partially.** The documented and widely-discussed uses are (a) migration source
(`dsync`/`doveadm backup` from a remote server), and (b) putting Dovecot in front of Exchange or
Office 365 to fix broken IMAP behaviour and to add TLS. The official howto's worked example is
Exchange **[VERIFIED-DOC]**.

**I did not find a public, named production report of "webmail + Dovecot imapc + FTS in front of a
slow third-party IMAP, running for years."** That is a real gap. The pieces are each well-trodden;
the *combination* is not something I can point at someone else running. I searched the Dovecot
mailing-list archives, `dovecot/core` issues, `grosjo/fts-xapian` issues,
`slusarz/dovecot-fts-flatcurve` issues, ServerFault, Reddit and the Cloudron/iRedMail/DirectAdmin
forums. `imapc` + `fts` returns essentially nothing in any of them.

Equally, **I found no report of anyone abandoning it either.** The absence cuts both ways: this is
a low-traffic corner of Dovecot, so we would be an early adopter of the combination, without the
benefit of other people having hit the bugs first.

## What it genuinely buys

### Sorting: solved, and this is verified in Dovecot's source

`src/lib-storage/index/imapc/imapc-search.c`, `imapc_build_sort_query()` **[VERIFIED-SRC]**:

```c
if ((mbox->capabilities & IMAPC_CAPABILITY_SORT) == 0) {
        /* SORT command passthrough not possible */
        return FALSE;
}
```

and `imapc_search_init()` falls through to
`index_storage_search_init(t, args, sort_program, ...)` — **Dovecot sorts locally from its own index
when the remote lacks SORT.** Dovecot then advertises `SORT` and `THREAD` to Roundcube, which stops
Roundcube doing its own `FETCH 1:*`.

The 82s header fetch does not vanish — it moves. Dovecot must populate `dovecot.index.cache` with
the Date header once per folder, paying the same bytes to Zoho. The difference is that it is paid
**once and persisted**, instead of on every sort change by every user, and it can be paced offline
by `doveadm index` rather than blocking a user's page load. **[INFERRED]** from the cache
architecture; the "once" claim is solid, the pacing claim needs a spike.

### All-folder search round trips: solved, structurally

Per-folder SEARCH still runs N times, but over a Unix socket / loopback instead of a 198ms link.
`107 folders × 2 RTT` goes from ~42s of pure latency to sub-millisecond. This is the same win the
pipelining work is chasing, obtained differently and more completely — it also fixes folder listing,
`STATUS` polling, and message opens, not just search. **[INFERRED]** but the mechanism is not in
doubt.

### Body/TEXT search: solved **only if FTS is wired correctly, and there is a trap**

This is the important part and the part I am least able to hand you verified.

**The trap [VERIFIED-SRC].** In `src/plugins/fts/fts-storage.c`, `fts_mailbox_search_init()` calls
the underlying storage's `search_init` **first, with the arguments unmodified**:

```c
ctx = fbox->module_ctx.super.search_init(t, args, sort_program,
                                         wanted_fields, wanted_headers);

if (!fbox->set->search ||
    !fts_backend_can_lookup(flist->backend, args->args))
        return ctx;
```

The underlying storage here is `imapc`. And `imapc_build_search_query_args()` happily converts
`SEARCH_BODY` and `SEARCH_TEXT` into an IMAP search string and sends it upstream
(`imapc-search.c` case list at lines 204–209). **So a naïve `imapc` + `fts` deployment sends the
slow `TEXT` search to Zoho anyway, *in addition* to doing the local FTS lookup.** You would install
the whole thing and measure no improvement on body search.

**The fix [INFERRED, needs a spike].** Set `imapc_features = no-search`, documented as: "Disable
searching messages using the IMAP SEARCH command. Instead, all the message headers/bodies are
fetched to perform the search locally." **[VERIFIED-DOC]** Read alone this sounds catastrophic —
fetch every body on every search. But with FTS loaded, the body/text predicates are satisfied from
the Xapian index and header predicates from `dovecot.index.cache`, so the local evaluation should
not need to pull bodies for messages already indexed. **I could not find documentation or a report
confirming this interaction.** It is the single highest-risk assumption in this document and the
first thing any spike must measure — specifically: with `no-search` + FTS + a fully-indexed folder,
does a `TEXT` search generate *zero* upstream FETCHes?

### Offline/degraded behaviour

Not a goal, but a side effect: cached headers keep the message list rendering when Zoho is slow.

## FTS engine choice

| Engine | Availability | Notes |
|---|---|---|
| **fts-flatcurve** | **Bundled in Dovecot core from 2.4.0** **[VERIFIED-DOC]** (https://doc.dovecot.org/main/core/plugins/fts_flatcurve.html) | Xapian-based, written by Michael Slusarz. No third-party package. Requires Xapian 1.4+. No fuzzy search. Substring search costs "significant additional storage space". |
| **fts-xapian** | Debian package `dovecot-fts-xapian`: 1.5.5 in bookworm, 1.9.1 in trixie **[VERIFIED]** (sources.debian.org API) | The community plugin. Maintained (grosjo). Works on 2.3. |
| fts-solr | Needs a JVM sidecar | Rejected on RAM grounds — see constraints. |
| fts-lucene / squat | Deprecated in 2.3 | Do not use. |

**Debian ships Dovecot 2.4.1 in trixie and 2.3.19 in bookworm [VERIFIED]** (sources.debian.org
API). Given the existing operational note that Alpine builds of mail infrastructure segfault and the
`up-imapproxy` sidecar is already Debian, **Debian trixie + Dovecot 2.4.1 + bundled fts-flatcurve is
the zero-compile path.** That directly answers the "don't compile on Alpine" constraint.

Index size, real-world anchor **[VERIFIED-REPORT]**: a user on roundcube#7825 reports 160,000
messages / ~24 GB Maildir fully indexed with fts-xapian, describing search as "faster than a Google
search", on a Xeon E3-1275 v6 (https://github.com/roundcube/roundcubemail/pull/7825). Dovecot's own
guidance elsewhere is roughly 10 GB of mail → ~4 GB of index, i.e. **budget 30–40% of mail volume**.
Note `imapc` does *not* store message bodies locally (only ≥32 kB bodies are opportunistically
cached in `dovecot.index.cache`, per NEWS), so the disk cost is index-only.

## What it costs to run

- **Initial backfill is the dangerous part.** FTS indexing must fetch every message body once.
  Against a stated ~1 GB/15min Zoho ceiling, indexing 97 mailboxes is a multi-day paced job, not an
  afternoon. `doveadm index -u <user>` is blocking and per-user **[VERIFIED-DOC]**, which makes
  pacing straightforward: a scheduler that runs one user at a time with sleeps, and watches for
  Zoho's block response. This must be built and it must be conservative.
- **Disk**: index + FTS ≈ 30–40% of total mail volume. Unknown today — **measure total mailbox bytes
  across the 97 users before costing this.**
- **RAM**: fts-flatcurve indexing memory is bounded by `fts_flatcurve_commit_limit` (default 500)
  **[VERIFIED-DOC]**; lower it on a 7.9 GB shared host. No JVM. This is why flatcurve/xapian beats
  Solr here.
- **Staying in sync without QRESYNC**: Zoho *does* advertise `CONDSTORE`, and Dovecot's `no-modseq`
  documentation confirms MODSEQ is available "if the remote server advertises the CONDSTORE **or**
  the QRESYNC capability" **[VERIFIED-DOC]**. So incremental resync is cheaper than the missing
  QRESYNC suggests. Flag changes made outside Roundcube (Zoho web UI, phone) reconcile on folder
  SELECT.
- **New complexity**: a stateful service with on-disk data that now matters. Backups, index
  corruption recovery (`doveadm force-resync`), per-user disk growth, another thing to upgrade.
  For a small team this is the real cost, larger than the RAM.

## Failure modes

- **The `no-search` trap above** — deploy it wrong and you get zero body-search improvement.
- **`imapc` bug surface.** Dovecot's NEWS carries a steady drip of `imapc` fixes across every
  release — crashes on reconnect, wrong message sequence numbers, partial-header caching bugs,
  UID STORE malformed uidsets **[VERIFIED-SRC]** (`NEWS`, ~40 `imapc` entries). Most are old, but
  2.4.x still lists "imapc: SEARCH failure handling was done wrong" and "Fetching partial headers
  would cause other cached headers to [be lost]". This is not abandonware, but it is not the
  best-tested path through Dovecot either.
- **Credential handling.** `imapc_password` must be the user's Zoho password (or an XOAUTH2 bearer —
  `imapc_sasl_mechanisms` supports `XOAUTH2` **[VERIFIED-DOC]**). Today this fork already decrypts
  stored Zoho credentials for IMAP login; Dovecot would need the same via a `passdb`/`userdb`
  returning `userdb_imapc_password`, exactly as the official howto does with a `passdb imap`
  **[VERIFIED-DOC]**. Workable, but it puts plaintext upstream credentials into a second process.
- **A second connection pool.** `up-imapproxy` becomes redundant or actively harmful; Dovecot keeps
  its own upstream connections. Running both is a mistake. Removing imapproxy is a deploy-order
  hazard.
- **Zoho concurrent-connection blocks.** Zoho blocks accounts for "too many simultaneous IMAP
  connections" **[VERIFIED-REPORT]** (multiple threads on help.zoho.com). An indexer running
  alongside live users doubles per-user connections. Pacing must cover connection count, not just
  bytes.

---

# Option 2 — Roundcube-side configuration and plugins

## Short answer: there is nothing to turn on. This is by design.

**The accepted upstream answer is that Roundcube deliberately does not build a search index and
never will.** Thomas Brüderli, 2007, closing roundcube#1240 ("Use MySQL to store messages for faster
GUI and quick searches") **[VERIFIED-REPORT]**
(https://github.com/roundcube/roundcubemail/issues/1240):

> RC caches messages in the local database and there were many discussions about wether RoundCube
> should also build it's own fulltext index for faster searching. The devs agreed to leave this up
> to the IMAP server.

That position has not moved in 19 years. It is the direct answer to "is this a known pain point with
an accepted answer": **yes, and the accepted answer is "fix it at the IMAP server."**

## `messages_cache` / `imap_cache` — what they do and do not cover

**[VERIFIED-SRC]** from `program/lib/Roundcube/rcube_imap_cache.php`:

- It caches the **folder index** (`get_index`, `add_index_row`), **thread data** (`get_thread`), and
  **per-message header objects** (`get_message`, `add_message`).
- It tracks `UIDVALIDITY` and `HIGHESTMODSEQ` per folder for validation (`:781`, `:837`, `:1038`) —
  so it already exploits Zoho's `CONDSTORE`.
- The only `search` in the file is `search_once($mailbox, "ALL UNDELETED ...")` at `:945`, used to
  *validate the index*, not to answer user searches.

**There is no code path in Roundcube that answers a user search from the message cache.** Every
search is an IMAP `SEARCH`. Confirmed independently by the prior spec's finding that
`cache_messages.data` in Postgres is a **serialized opaque blob, not queryable columns**
(`SQL/postgres.initial.sql:289-298`). Even if we wanted to query it, we could not without a schema
change, and it holds only *browsed* messages — never a complete corpus.

Conclusion: **the Postgres `messages_cache` this fork already runs contributes exactly nothing to
search latency, and cannot be made to without writing a new index.** It does help sorting and list
rendering.

## Config knobs, honestly assessed

| Knob | Verified location | What it actually does |
|---|---|---|
| `message_sort_col = ''` | `config/defaults.inc.php:873` (already empty here) | Disables the sort-triggered `FETCH 1:*`. The standard advice from the Roundcube users list for servers without SORT. **Already in effect.** |
| `imap_disabled_caps` | `:228` | Hides capabilities *from* Roundcube. Can only make things worse here. |
| `search_mods` | `:1428` | Sets default search fields per folder. **This is the knob for the "entire message by default" request** — but it makes the 69s cost the default, not an opt-in. Do not do this without fixing the underlying cost. |
| `skip_deleted` | `:1366` | Adds `UNDELETED` to searches. Marginal. |
| `imap_cache` / `messages_cache` | `config.inc.php:83-98` | Already `redis`/`db`. No search effect (above). |

## One genuinely useful Roundcube-side detail, already correct in this fork

roundcube#3578 (alecpl, 2011) found that when a user selected "entire message" *alongside* Subject
and From, Roundcube emitted `OR HEADER SUBJECT ... OR HEADER FROM ... TEXT ...`, which **defeats
server-side FTS optimisation** — many IMAP servers only route a bare `TEXT` search to their index
**[VERIFIED-REPORT]** (https://github.com/roundcube/roundcubemail/issues/3578).

The fix is present in 1.6.14 in this tree **[VERIFIED-SRC]**, `program/actions/mail/search.php:249`:

```php
case 'text':
    // #1488208: get rid of other headers when searching by "TEXT"
    $subject = ['text' => 'TEXT'];
    break 2;
```

So if we ever put an FTS-capable server in front, Roundcube already emits the FTS-friendly query
shape. **No Roundcube change is needed to benefit from Option 1.** That is worth knowing before
anyone proposes patching search.php.

## Plugins

There is **no maintained Roundcube plugin that provides a local search index.** I searched the
plugin repository and the issue tracker. What exists:

- **roundcube#7825, "IMAP semi-asynchronous backwards search in batches"** — an *open, unmerged PR
  from 2021* **[VERIFIED-REPORT]**. It searches the N most recent messages first and pages backwards,
  so the user sees results in ~1s instead of waiting 69s for completeness. alecpl's response:

  > I didn't review yet, but I think I'd prefer this to be implemented as a plugin. This probably
  > makes most sense on a slow imap server.

  Five years later it is still open. **This is the closest thing to an off-the-shelf answer for the
  perceived latency of body search, and upstream explicitly blessed the plugin form.** It does not
  make search faster; it makes it feel faster and lets the user stop early. It has known gaps: it
  forces arrival-order results and had no multi-folder implementation.

- roundcube#9514 and #9212 are about consuming a *server-side* FTS engine (fts-solr), reinforcing
  that FTS is expected to live in Dovecot.

---

# Option 3 — Zoho Mail REST API for search only

## This is the most surprising finding in this document.

Zoho's IMAP is capability-poor, but **Zoho's REST API exposes their own search index, and it does
in one HTTP call what IMAP cannot do at all.**

`GET https://mail.zoho.com/api/accounts/{accountId}/messages/search` **[VERIFIED-DOC]**
(https://www.zoho.com/mail/help/api/get-search-emails.html)

- **Scopes**: `ZohoMail.messages.READ` (or `.ALL`).
- **Params**: `searchKey` (required), `start` (default 1), `limit` (**1–200**, default 10),
  `receivedTime`, `includeto`.
- **`searchKey` syntax** **[VERIFIED-DOC]**
  (https://www.zoho.com/mail/help/search-syntax.html):
  - **`entire:<term>`** — matches the word *anywhere in the email*, i.e. **body search**.
  - `content:` — body only. `subject:`, `sender:`, `to:`, `cc:`.
  - `fileName:`, **`fileContent:`** — searches *inside attachments*. IMAP cannot do this at all.
  - `in:<folder>` to scope; `has:`, `label:`, `fromDate`/`toDate` (DD-MMM-YYYY).
  - `::` = AND, `:or:` = OR. Quote for phrase match.
  - **"By default, searches scan across all folders"** unless restricted by `in:`.

Read that last line against our problem. The 107-folder × 2-round-trip fan-out — the thing the
entire pipelining effort exists to mitigate — **does not exist in this API**. One request, all
folders, body included, answered from Zoho's own index (the same index that makes Zoho's own web UI
search feel instant).

## What it costs and what blocks it

**Auth is the blocker, and it is a real one.**

- OAuth 2.0 with browser-based user consent **[VERIFIED-DOC]**
  (https://www.zoho.com/mail/help/api/using-oauth-2.html). Access tokens expire in **3600s**;
  refresh tokens are long-lived.
- Token limits **[VERIFIED-DOC]** (https://www.zoho.com/accounts/protocol/oauth/token-limits.html):
  max **20 active refresh tokens per user per client**; max **5 refresh tokens generated per
  minute**; max **30 active access tokens per refresh token**. The 5/min limit matters if we ever
  re-consent 97 users at once.
- The "Self Client" flow avoids the consent screen but issues a token **for your own account only**
  **[VERIFIED-DOC]**. It does not let an admin read other users' mail.
- **I found no documented org-admin path to search another user's mailbox via API.** A Zoho
  community thread asks exactly this ("Is there a way for me to retrieve/search for an email that is
  either received or sent by a user without having to log in to that user's account?") and
  **received no answer** **[VERIFIED-REPORT]**
  (https://help.zoho.com/portal/en/community/topic/can-admin-access-email-messages-from-other-user-accounts).
  Assume per-user consent is required until proven otherwise.

So: **97 users each complete a one-time OAuth consent.** That is friction, but it is one-time,
and there is a strong consolation prize — **Zoho advertises `AUTH=XOAUTH2` on IMAP.** A single
consent could yield a token that serves *both* IMAP login (Roundcube 1.6 has generic OAuth2 support:
`oauth_provider`, `oauth_auth_uri`, `oauth_token_uri`, `oauth_scope` at
`config/defaults.inc.php:331-372` **[VERIFIED-SRC]**) *and* the REST search API. That would also
retire the stored-plaintext-credential design. This is the strategically cleanest direction in the
whole document.

**Rate limits are undocumented, and that is a genuine risk.** Zoho's own getting-started page says
only "Each Zoho Mail's REST API has its own rate limit, which may vary depending on the specific
API" **[VERIFIED-DOC]**. A community thread asking for numbers got none **[VERIFIED-REPORT]**. We
would be building on an unquantified budget. Mitigation: search is user-initiated and low-frequency
(~7 logical searches in a 9.2h prod window, per the prior measurement) — we are talking about tens of
calls per day, not thousands. **[INFERRED]** that this is comfortably inside any sane limit, but it
is inferred.

**Integration is cheap.** The prior spec verified that `rcube_imap.php:1629` exposes an
`imap_search_before` hook, and **setting `result` bypasses all IMAP search entirely**
**[VERIFIED-SRC]**. A plugin can answer a search from Zoho's REST API and hand Roundcube a UID set
without touching core. The hard part is mapping Zoho's `messageId` back to IMAP UIDs per folder —
**unverified whether the search response carries anything that maps cleanly to IMAP UID; this needs
a spike against a real account.**

## Failure modes

- Silent divergence between what IMAP shows and what the API returns.
- Token expiry mid-session; refresh handling.
- Undocumented limits changing without notice.
- Zoho Mail API availability may depend on plan tier — **not verified**; check against our actual
  Zoho subscription before committing.
- A second auth system to operate for a small team.

---

# Option 4 — Move the compute closer to Zoho

Not researched as an "option others use" but it falls out of the measurements and deserves ranking.

198ms RTT is the dominant term in everything except body search. Zoho's `imap.zoho.com` is US-hosted;
the stack runs from Brazil. **Relocating only the IMAP-facing component (imapproxy, or a Dovecot
`imapc` instance) to a US region collapses RTT to single-digit milliseconds** and mechanically
divides every round-trip-bound cost by ~20–40×.

- Solves: all-folder search fan-out, the 82s sort FETCH, folder polling, message open latency.
- **Does not solve body search.** Zoho's 69s of server-side compute is unaffected. This is the
  same boundary every other round-trip fix hits.
- Cost: one small VPS, plus the fact that Roundcube↔proxy traffic now crosses the same 198ms link
  unless Roundcube moves too — which would move the Nextcloud-embedded UI away from users. **A
  US-side Dovecot `imapc` is strictly better than a US-side imapproxy here**, because Dovecot answers
  from local index over the long link in *one* round trip rather than proxying N.

Combining Options 1 and 4 — Dovecot `imapc` + FTS, hosted near Zoho — is the strongest technical
configuration in this document, and also the most operational work.

---

# Option 5 — Things operators do that we have not considered

- **Progressive / bounded search instead of complete search.** roundcube#7825's backwards-batched
  search, above. Cheapest possible intervention for the *perceived* problem. Users abandon searches
  because nothing happens for 15s, not because the 15th second is unacceptable. Showing the 20 most
  recent matches in 1s with "keep searching" changes the experience without changing a single
  round trip. **This is the highest value-per-hour item in the entire document.**
- **Dovecot `virtual` plugin — an "All Mail" virtual mailbox.** `dovecot-virtual` with `*` / `all`
  presents every folder as one mailbox **[VERIFIED-DOC]**
  (https://doc.dovecot.org/main/core/plugins/virtual.html). All-folder search becomes a *single*
  SEARCH against one mailbox. Only meaningful on top of Option 1. Note NEWS records
  "imapc: Crashed when a folder mapped through the virtual plugin ..." — the combination has had
  bugs. `virtual_max_open_mailboxes` defaults to 64 and we have accounts with 107 folders, so that
  needs raising. **[VERIFIED-DOC]**
- **Full local mirror (`mbsync`/`isync` → local Dovecot Maildir).** Everything Option 1 gives, plus
  offline resilience, at the cost of storing 100% of mail volume locally, bidirectional sync
  conflicts, and a second copy of customer data to secure and back up. `mbsync` is the maintained
  choice; OfflineIMAP is described as suffering "a lack of maintenance"
  **[VERIFIED-REPORT]** (https://anarc.at/blog/2021-11-21-mbsync-vs-offlineimap/). **Strictly worse
  than `imapc` for our constraints** — same upstream bandwidth problem, far more disk, and sync
  conflicts `imapc` does not have because `imapc` never claims to own the mail.
- **Zoho ActiveSync (EAS).** Available on business/paid plans only **[VERIFIED-DOC]**
  (https://www.zoho.com/mail/help/zoho-mail-active-sync.html). Would require an EAS client in PHP.
  No realistic path. **Zoho has no JMAP** — I found no reference to it anywhere in Zoho's docs.
- **Restricting the default search scope.** Currently all-folder is the target default. Making
  "current folder" the default and all-folder an explicit action is free and removes the worst-case
  212s path for the majority of searches. Unpopular, but it is what most Roundcube installs do.

---

# Ranking

| # | Option | Solves BODY search? | Effort | Risk | Verdict |
|---|---|---|---|---|---|
| 1 | **Progressive/bounded search (roundcube#7825 as a plugin)** | No — but removes the *wait* | Days | Low | **Do this first, regardless of anything else.** |
| 2 | **Zoho REST search API behind `imap_search_before`** | **Yes**, and attachments too, all folders, one call | Weeks + 97 consents | Medium (undocumented limits, UID mapping unproven) | **Do this second.** Highest ratio of problem solved to infrastructure added. Pair with OAuth/XOAUTH2 to also retire stored credentials. |
| 3 | **Dovecot `imapc` + fts-flatcurve, hosted near Zoho** | **Yes** (if `no-search` works as reasoned) | Months, plus a multi-day paced backfill | High — new stateful service, unproven `imapc`+FTS combination, no public precedent | **Do this only if #2 is blocked.** It is the "correct" architecture and also the one a small team will regret. |
| 4 | Move IMAP-facing component near Zoho | **No** | Days | Low | Good value, but a strict subset of what #3 does. Consider as a standalone win for sort/list/open latency. |
| 5 | Roundcube config / `messages_cache` tuning | No | Hours | None | **Nothing left to gain. Verified: no config makes search cheaper here.** |
| 6 | Full local mirror (`mbsync`) | Yes | Months | High | Dominated by #3. Do not. |

## The three things worth carrying away

1. **Roundcube will never help.** Upstream decided in 2007 to leave search indexing to the IMAP
   server and has not revisited it. Our Postgres `messages_cache` does not and cannot serve search.
   Stop looking on the Roundcube side for a search fix.
2. **Zoho already has the index we want, and exposes it — over HTTP, not IMAP.** `entire:` searches
   bodies, `fileContent:` searches attachments, and it spans all folders by default. The obstacle is
   OAuth consent for 97 users, not capability.
3. **The `imapc` + FTS combination has a source-level trap** (`fts` calls `imapc`'s `search_init`
   with unmodified args, so `BODY`/`TEXT` still goes upstream unless `imapc_features = no-search`).
   Anyone who deploys Option 3 without knowing this will measure no improvement and conclude the
   approach does not work.

## What would change the ranking

- **If Zoho's search API turns out not to expose an IMAP-mappable identifier**, Option 2 collapses
  and Option 3 becomes the answer. Spike this first — it is a one-hour test against one account.
- **If Zoho Mail API access requires a plan tier we do not have**, same.
- **If total mailbox volume across 97 users is small** (say under 100 GB), Option 3's backfill and
  disk cost stop being frightening and it climbs the ranking.
- **If a spike shows `no-search` + FTS still pulls bodies from Zoho on every search**, Option 3 does
  not solve body search at all and drops below Option 4.
- **If users' real complaint is "search doesn't finish" rather than "search is slow"**, Option 1
  alone may close the ticket and nothing else is needed.

## Open questions needing a human

1. What is the total mail volume across the 97 mailboxes? Everything in Option 3 is costed on it.
2. What Zoho plan are we on, and does it include Mail API access?
3. Is a one-time OAuth consent flow for 97 users acceptable to the business?
4. Is the heavy user's need "search bodies" or "find this specific thing I remember"? The second is
   often satisfied by attachment/filename search, which Zoho's API gives and IMAP does not.

## Sources

- Dovecot imapc mailbox format: https://doc.dovecot.org/main/core/config/mailbox_formats/imapc.html
- Dovecot imapc proxy howto: https://doc.dovecot.org/main/howto/imapc_proxy.html
- Dovecot imapc (2.3): https://doc.dovecot.org/2.3/configuration_manual/mail_location/imapc/
- Dovecot FTS plugin: https://doc.dovecot.org/main/core/plugins/fts.html
- Dovecot fts-flatcurve: https://doc.dovecot.org/main/core/plugins/fts_flatcurve.html
- Dovecot virtual plugin: https://doc.dovecot.org/main/core/plugins/virtual.html
- Timo Sirainen, "Smart IMAP proxying with imapc storage": https://dovecot.org/list/dovecot/2011-January/056975.html
- Dovecot source: `src/lib-storage/index/imapc/imapc-search.c`, `src/plugins/fts/fts-storage.c`, `NEWS` — https://github.com/dovecot/core
- fts-xapian: https://github.com/grosjo/fts-xapian
- Roundcube #1240 (devs decline to build an index): https://github.com/roundcube/roundcubemail/issues/1240
- Roundcube #3578 (TEXT-only search for FTS): https://github.com/roundcube/roundcubemail/issues/3578
- Roundcube #5973 (search fails without SORT): https://github.com/roundcube/roundcubemail/issues/5973
- Roundcube #7462 (paging broken without SORT): https://github.com/roundcube/roundcubemail/issues/7462
- Roundcube #4906 (body-search timeouts, 21k messages): https://github.com/roundcube/roundcubemail/issues/4906
- Roundcube #7825 (batched backwards search, open since 2021): https://github.com/roundcube/roundcubemail/pull/7825
- Zoho Mail search API: https://www.zoho.com/mail/help/api/get-search-emails.html
- Zoho Mail search syntax: https://www.zoho.com/mail/help/search-syntax.html
- Zoho Mail API getting started: https://www.zoho.com/mail/help/api/getting-started-with-api.html
- Zoho OAuth 2.0: https://www.zoho.com/mail/help/api/using-oauth-2.html
- Zoho OAuth token limits: https://www.zoho.com/accounts/protocol/oauth/token-limits.html
- Zoho ActiveSync: https://www.zoho.com/mail/help/zoho-mail-active-sync.html
- Zoho migration guidelines (throttling): https://www.zoho.com/mail/help/adminconsole/migration-guidelines.html
- Zoho: admin access to other mailboxes (unanswered): https://help.zoho.com/portal/en/community/topic/can-admin-access-email-messages-from-other-user-accounts
- mbsync vs OfflineIMAP: https://anarc.at/blog/2021-11-21-mbsync-vs-offlineimap/
- Debian package versions: https://sources.debian.org/api/src/dovecot/ , https://sources.debian.org/api/src/dovecot-fts-xapian/
