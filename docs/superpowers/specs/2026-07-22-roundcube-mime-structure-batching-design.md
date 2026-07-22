# Roundcube MIME Structure Batching — Design

**Date**: 2026-07-22
**Branch**: avuz-customization
**Status**: DESIGN — awaiting approval
**Priority**: This is the real fix for slow cold message opens. It precedes Wave 2 (search index)
in value for the client's most-felt symptom after folder navigation.

## Problem

Opening a message with a complex, deeply-nested MIME structure is slow the first time — measured
at **16 seconds** on staging for one real message. Verbatim from the wire log (`imap.log`):

```
01:52:09 → 01:52:25   cmds=42   mime_walk=21    ← one message open, 16 seconds
```

That single open issued **42 IMAP commands, 21 of them `BODY.PEEK[N.MIME]` structure fetches**,
each a sequential round trip at ~200ms to Zoho. The message — a forwarded chain with embedded
PDFs and inline images from a construction/invoices folder — has ~21 MIME parts across several
nesting levels.

The second open of the same message is **instant**: the built structure is cached in Postgres
(`messages_cache`), so the walk never repeats. The cost is entirely on the **first, cold** open.

This is not the images (they load lazily and only slow the first open) and not contention — the
open was doing 16s of its own structure-fetch work, confirmed by the command trace, not waiting on
anything.

### Relationship to prefetch

`avuz_prefetch` is meant to pay this cost in the background (it builds `rcube_message`, which
builds and caches the structure) so foreground opens hit the warm cache. It works — *when it
reaches the message first*. When the user opens a message prefetch has not yet warmed (e.g. deep in
a list while prefetch is still on batch 1), the open pays the full cold walk. Making the walk cheap
fixes **both** paths at once, because they run the identical `get_structure` code.

## Root cause

`program/lib/Roundcube/rcube_imap.php`:

- `get_structure()` (~:2008) calls `structure_part($structure, 0, '', $headers)` once against the
  full BODYSTRUCTURE (which is already fetched in a single command — the whole MIME tree is known
  up front).
- `structure_part()` **recurses** per sub-part. At each multipart node (`:2100`) and each
  `message/rfc822` node (`:2233`) it issues its **own** `fetchMIMEHeaders()` call for that level's
  attachment / rfc822 children.
- So a message with attachments at N nesting levels issues **N sequential** `fetchMIMEHeaders`
  commands — N round trips — instead of one.

The code's own comment admits the fix (`:2097-2099`):

```php
// pre-fetch headers of all parts (in one command for better performance)
// @TODO: we could do this before _structure_part() call, to fetch
// headers for parts on all levels
```

## Upstream research (2026-07-22)

Searched roundcube/roundcubemail issues, PRs (open + closed), discussions, mailing list, and
plugins.

- **Nothing exists.** No issue, no PR, no plugin, no proposal addresses this. The `@TODO` is the
  original, introduced in commit `7a229b9e3` ("Improve messages display performance", Jan 2009) —
  **unaddressed for ~17 years**, identical on `master`, `release-1.7`, and `release-1.6` today.
- **The multi-section fetch is valid IMAP.** RFC 3501 §6.4.5 permits an arbitrary list of
  `BODY.PEEK[section]` items in one `FETCH`, with any nested dotted section number (`3.1.4` works
  like `1`). `rcube_imap_generic::fetchMIMEHeaders()` already accepts an array of arbitrary dotted
  part IDs and interpolates each into `BODY.PEEK[{part}.MIME]` — **no IMAP-wrapper change needed**.
- **The fix is purely a caller-side refactor** of `get_structure()` / `structure_part()`.
- **One cautionary precedent:** issue #8282 (2021) was a *correctness* bug in exactly this
  multi-part-in-one-command path — a regex broke parsing MIME headers with Cyrillic filenames. The
  fetch is safe; the **parse/assignment** is where bugs live. A wrong structure means a broken
  message display. This drives the testing rigor below.

**Decision: patch core, do NOT upstream** (per product call). The patch is carried in the fork and
documented in `customizations.json` alongside the existing washtml core patch. Upstreaming remains
an option later if the carry cost or a rebase conflict makes it worthwhile; it is explicitly out of
scope now.

## Design

Two passes over the already-fetched BODYSTRUCTURE tree, with a single header fetch between them.

1. **Collect.** Before building, walk the raw `$structure` array recursively and collect the dotted
   mime_id of every part that needs a MIME header — the same parts the current code selects:
   `message/rfc822` parts and attachment parts (`is_attachment_part`). This produces the complete
   across-all-levels ID list.

2. **Fetch once.** `$prefetched = $this->conn->fetchMIMEHeaders($folder, $uid, $all_ids)` — one
   IMAP command for every part header in the whole message. Skip entirely if the list is empty.

3. **Build.** Run the existing `structure_part()` recursion, but replace both per-level
   `fetchMIMEHeaders()` calls with lookups into `$prefetched`. Each part already receives its
   pre-fetched header via the existing `$mime_headers` parameter and the
   `!empty($mime_part_headers[$tmp_part_id])` pattern — the plumbing to pass headers down is
   already there; only the *source* changes from per-level fetch to the shared map.

Result: **N round trips → 1.** A 16s cold open becomes ~2s (one BODYSTRUCTURE + one MIME-header
batch + the body fetch), and every prefetch warm gets the same reduction.

### The one hard correctness constraint

The collector in pass 1 **must compute mime_ids identically** to `structure_part()` in pass 3, or
headers get assigned to the wrong parts. `structure_part` builds child IDs as
`$parent ? "$parent.".($i+1) : ($i+1)` for multipart children (`:2087`, `:2110`) and the analogous
`$subpart_id` for `message/rfc822` children (`:2218`). The collector must mirror this exactly.

To contain that risk, the collector should mirror `structure_part`'s traversal structure as closely
as possible — same branch conditions (`is_array($part[0])` for multipart, `$part[8]` for rfc822
children), same ID formula — rather than an independently-derived walk. Any drift between the two
traversals is the failure mode, and it is what the corpus test targets.

## Testing — heavy, real-message corpus

A wrong structure silently breaks message display (missing body, missing attachments, wrong
content). Synthetic tests alone would miss the #8282 class of real-world edge case. So:

**Build a fixture corpus of real, diverse BODYSTRUCTURE responses**, captured as the raw arrays
`structure_part` consumes, covering at least:

- simple `text/plain` and `text/html` single-part
- `multipart/alternative` (plain + html)
- `multipart/related` (html + inline images) nested in `multipart/alternative`
- `multipart/mixed` with file attachments (PDF, docx)
- **deeply nested forwarded chains** — `message/rfc822` containing multipart containing
  `message/rfc822` (the 21-part shape that motivated this)
- attachments at multiple nesting levels simultaneously
- **non-ASCII / RTL filenames** (the #8282 trigger) and RFC 2231 split parameters
- malformed / degenerate MIME (the `structure_part` fallback paths at `:2118+`)

**The core assertion is parity:** for every fixture, the structure object produced by the new
two-pass code must be **deep-equal** to the structure produced by the current per-level code —
same parts, same mime_ids, same mimetypes, same filenames, same disposition, same nesting. Build
the old-vs-new comparison as the primary test: the refactor is correct iff it changes performance
and nothing else.

Add an assertion that the new path issues **one** `fetchMIMEHeaders` call where the old issued N
(mock the connection, count calls) — proving the optimization actually happened, not just that the
output matches.

## Scope and rollout

- **Core patch** to `program/lib/Roundcube/rcube_imap.php` only. Document it in `customizations.json`
  next to the washtml patch, with the `@TODO` reference and the reason it can't be a plugin.
- Ships behind the same build/deploy as everything else; no new services, no config.
- Verify on staging with the perf instrumentation already in place: the same complex message that
  took 16s cold should open in ~2s cold (flush its cache first to force cold), and `mime_walk`
  command count per open should drop from ~21 to ~1.

## Out of scope

- **Caching inline images.** Separate concern (bandwidth vs Zoho's 1 GB/15min budget); not needed —
  images load lazily and the second open is already instant once structure is cached.
- **Smaller prefetch batches.** Rejected: more requests means more imapproxy backend churn against
  Zoho's per-mailbox connection limit. This fix removes the need — it makes each message's warm
  cheap rather than warming more aggressively.
- **Upstreaming the patch.** Deferred (product call); remains an option.
- **Wave 2** (local search index). Unaffected and still pending; this fix is higher-value for the
  open-latency symptom and comes first.
