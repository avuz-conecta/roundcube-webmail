# Roundcube MIME Structure Batching — Design

**Date**: 2026-07-22
**Branch**: avuz-customization
**Status**: DESIGN — awaiting approval
**Priority**: BLOCKED — the spike (below) falsified this spec's premise for the tested message.

## SPIKE RESULT — premise falsified for the tested message (2026-07-22)

The mandatory spike ran (core `fetchMIMEHeaders` temporarily instrumented on staging, message 162
opened, reverted). Result, on verified facts:

- `BODY.PEEK[N.MIME]` is built only at `rcube_imap_generic.php:2839`, inside `fetchMIMEHeaders`,
  which has only two callers — both in `structure_part`.
- `is_attachment_part` returns **false** for cid-inline images (their content-id makes `$part[3]`
  non-empty). Message 162's parts are all cid-inline images, so `structure_part` collects none and
  `fetchMIMEHeaders` is never called. The instrumented open confirmed **zero** calls.
- Therefore the "12 `BODY.PEEK[N.MIME]` for message 162" that motivated this spec was
  **misattributed** — interleaved prefetch/other-message commands in the shared `imap.log`. Message
  162 does not trigger the structure walk at all; its slow open is the **inline images' content**
  (8-13 large BASE64 part fetches), which this fix does not touch.

**Consequence:** the MIME-batching fix helps only messages with *real attachments*
(attachment-disposition parts or `message/rfc822` children) spread across nesting levels — a class
that exists (per-UID logs show messages with 20+ MIME fetches) but which has **not** been confirmed
to be what this client actually opens and feels as slow. The one message tested (162) is the wrong
class.

**Gate before any implementation:** identify, from real usage, whether the client's slow opens are
the attachment/rfc822 class (fix helps) or the cid-inline-image class (fix does nothing — the cost
is image content, addressed only by image caching, which is out of scope for bandwidth reasons).
Do not build this until that is answered. If the slow opens are predominantly cid-image invoices
like 162, this spec should be abandoned in favor of revisiting image handling.

---

*(Original priority claim, retained for context: "the real fix for slow cold message opens." The
spike shows this was true only for a message class we have not confirmed the client hits.)*

## Problem

Building the MIME structure of a complex, deeply-nested message is expensive because Roundcube
fetches each part's MIME header in a **separate** IMAP command, sequentially. A controlled cold
open of a real 13-part invoice message (measurement below) issued **12 consecutive
`BODY.PEEK[N.MIME]` commands** — one per part, unbatched — inside the single open request. At
~200ms/round-trip to Zoho that is **~2.4s of pure structure walk on a 13-part message**, and scales
with part count (log aggregates show messages up to ~24 parts).

Complex messages — forwarded chains with embedded PDFs and inline images, common in this client's
invoice/construction folders — spread parts across nesting levels such that the current per-level
batching fails to combine them. The cost is entirely on the first, cold build: the structure is
then cached in Postgres (`messages_cache`), so the second open is instant.

### Clean single-open measurement (verified)

A controlled cold open was captured with prefetch idle and the structure cache flushed for the
account, so the trace is uncontaminated. The `preview` (structure-build) request for a real 13-part
invoice message issued, in order, on one connection:

```
A0005  UID FETCH 162 (... BODYSTRUCTURE ...)     ← build structure
A0006  UID FETCH 162 (BODY.PEEK[2.MIME])
A0007  UID FETCH 162 (BODY.PEEK[3.MIME])
 ...   (one command per part, sequential)
A0017  UID FETCH 162 (BODY.PEEK[13.MIME])        ← 12 consecutive MIME-header fetches
A0018  UID FETCH 162 (BODY.PEEK[1.2])            ← body
```

**12 consecutive single-section `BODY.PEEK[N.MIME]` commands** inside one open request — the
`get_structure`/`structure_part` walk, NOT batched (the current per-level batching fails to catch
them, which is exactly the "spread across levels" case the `@TODO` names). At ~200ms each that is
~2.4s of the open. The fix collapses those 12 into **one** `UID FETCH (BODY.PEEK[2.MIME] …
BODY.PEEK[13.MIME])`.

**Measured saving on this foreground open: ~11 round trips ≈ ~2.2s.** The rest of the open (~8s
wall-clock) was 10+ parallel part-content fetches (the images/PDFs) — inherent, correctly untouched
by this fix.

An earlier draft cited "one 16s open, 42 commands." That trace was **contaminated** — a 6-message
prefetch batch interleaved with an open in the shared `imap.log`, misread as one open. Discarded in
favor of the clean measurement above.

### This fixes BOTH of the two real problems

A concurrency split of message-open latency (perf log, `during-prefetch` vs `prefetch-idle`):

```
opens DURING prefetch:  n=32  avg=4.20s  max=29.14s
opens prefetch-IDLE:    n=48  avg=2.31s  max=17.93s
```

Two distinct costs: **contention** (opens 1.8× slower while prefetch runs) and **intrinsic**
structure walk (idle opens still hit 17.93s). This one fix addresses both, because prefetch runs
the *same* `get_structure` walk on every message it warms:

- **Foreground cold opens** drop by the removed round trips — **measured ~2.2s** on the 13-part
  message above (12 MIME round trips → 1).
- **Prefetch batches shrink.** Prefetch builds `rcube_message` per warmed UID, so it pays the same
  ~12-round-trip walk per complex message. A cold batch of 8 such messages sheds ~88 round trips
  (~18s); the observed 27s cold batches would drop toward the low single digits, closing the
  contention window — the 1.8× slowdown exists *because* prefetch holds connections for tens of
  seconds.

So the leverage is higher than a per-open saving alone: the same change cuts foreground open cost
(~2.2s, verified) **and** the contention that Wave 1.5's pacing could only partly mitigate. Be
honest about the split — the per-open saving is real but modest; the prefetch-batch shrink is where
most of the value is.

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

## UNRESOLVED: why is it 12 commands today? (mandatory spike, gates everything)

The design below assumes the 12 `BODY.PEEK[N.MIME]` fetches come from `structure_part`'s per-level
`fetchMIMEHeaders` calls, and that collecting IDs across all levels collapses them to one. **That
causal claim is not yet verified, and a careful code read actively contradicts it:**

- `fetchMIMEHeaders` batches — one call = one command (`rcube_imap_generic.php:2842`). So 12
  commands means **12 separate calls**, i.e. 12 `structure_part` invocations each collecting exactly
  one part.
- But the parts in question (`[2]`…`[13]`) are inline images with a **content-id**. `is_attachment_part`
  (`:2307`) requires `empty($part[3])`, and `$part[3]` is the content-id (non-empty) — so it returns
  **false** for these parts. They are not `message/rfc822` either. By the code as written,
  `structure_part` should add **none** of them to `$mime_part_headers` and issue **zero** MIME
  fetches for them.
- Yet the wire shows 12. `BODY.PEEK[N.MIME]` has only those two callers, both in `structure_part`.

The code as read and the observed behavior cannot both be right. Until that is resolved, **there is
no basis to claim the fix produces one command** — it may be targeting the wrong path.

**Spike (Task 1 of the plan, blocking):** reproduce the 12 fetches deterministically — a unit/
integration test that feeds message 162's real BODYSTRUCTURE array through `get_structure` against a
mock `rcube_imap_generic` that records every command — and identify the exact call site and
condition that emits each `[N.MIME]`. Only once the origin is proven, and a throwaway prototype of
the two-pass collection is shown to drop the recorded command count from 12 to 1 **on that same
fixture**, does the rest of this design proceed. If the origin turns out not to be the per-level
`fetchMIMEHeaders` batching described below, this design is wrong and must be redone.

The BODYSTRUCTURE for 162 (captured, to seed the fixture):
`((("TEXT" "PLAIN" …)("TEXT" "HTML" …) "ALTERNATIVE" …)("IMAGE" "PNG" ("name" "image001.png")
"<image001.png@…>" "image001.png" "BASE64" … ("inline" …)) …)` — root multipart with a nested
alternative and multiple inline (content-id) image parts.

## Design (contingent on the spike confirming the mechanism)

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

Result: **N round trips → 1** for the MIME-header walk. On the measured 13-part message that removes
~11 round trips ≈ **~2.2s** of the open; the open still pays SELECT + BODYSTRUCTURE + the parallel
part-content fetches, which is the rest of its time. Every prefetch warm gets the same per-message
reduction, which is what shrinks the observed 27s cold batches toward the low single digits.

State the benefit honestly: **~2.2s per complex foreground cold open** (measured, modest), and a
**large reduction in prefetch batch duration** (each warmed message sheds ~12 round trips) which is
where most of the value is. Do NOT inflate it to whole-open figures — the content/image fetches are
untouched. Re-measure on staging after the change rather than asserting.

### Chunk the fetch — do not assume one command fits

A pathological message (deep forwarded chain, 100+ parts) would produce a single
`UID FETCH uid (BODY.PEEK[1.MIME] … ×100)` command that can exceed the IMAP command-line length
limit and error — which, on this path, means a broken structure and an unopenable message. The old
per-level code chunked implicitly. The new collector must **chunk the ID list** (e.g. ≤ 50 sections
per `fetchMIMEHeaders` call, looping) so the worst case degrades to a few round trips, never a
protocol error. Observed messages top out at ~24 parts, so chunking is a safety rail, not the
common path — but it is mandatory, not optional.

### This fix stresses the #8282 parse harder than any prior code

Issue #8282 was a parse failure on *multiple part headers in one response* (a regex broke on a
Cyrillic filename). This change makes that response the **largest it has ever been** — every part
of the whole message in one response instead of one nesting level's worth. It does not merely risk
the #8282 class; it maximizes the exact condition #8282 broke on. The corpus's non-ASCII-filename
and RFC-2231 cases are therefore not optional edge cases — they are the primary risk this change
introduces, and must be in the corpus from the first test.

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
- Verify on staging with the perf instrumentation already in place, using the same controlled
  cold-open protocol as the baseline measurement (flush `cache_messages` for the account + Redis
  bodies, prefetch idle, open the same 13-part message). The count of `BODY.PEEK[N.MIME]` commands
  in the open must drop from **12 to 1**, and the open's `request_time` should fall by ~2s. Re-run
  a prefetch batch cold and confirm the batch duration drops substantially (the larger win).

## Out of scope

- **Caching inline images.** Separate concern (bandwidth vs Zoho's 1 GB/15min budget); not needed —
  images load lazily and the second open is already instant once structure is cached.
- **Smaller prefetch batches.** Rejected: more requests means more imapproxy backend churn against
  Zoho's per-mailbox connection limit. This fix removes the need — it makes each message's warm
  cheap rather than warming more aggressively.
- **Upstreaming the patch.** Deferred (product call); remains an option.
- **Wave 2** (local search index). Unaffected and still pending; this fix is higher-value for the
  open-latency symptom and comes first.
