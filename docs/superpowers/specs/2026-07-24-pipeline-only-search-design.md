# Pipeline-only search: drop progressive, force arrival sort

**Date**: 2026-07-24
**Status**: design, agreed — ready to build
**Supersedes the direction of**: `2026-07-23-progressive-search-design.md` (progressive is being removed)
**Builds on**: accent-literal fix (search.php CR/LF sanitizer moved to raw `$filter`), verified 2026-07-24

## Context

The accent+pipeline desync is fixed and the pipeline is proven fast and correct on staging.
Progressive (folder-by-folder streaming via `continue_search`) is the source of the remaining
UX problems: old-messages-first, selection lost on continuation, and a refresh that re-fires on
paginate/open. The pipeline makes a cross-folder search complete in ~1 batch, so progressive is
no longer needed to hide latency.

## Decisions (locked)

1. **Sort: arrival only.** Keep a single sort option — "Data de recebimento" (arrival = INTERNALDATE,
   received date, newest-first). Remove/hide the other sort columns (date-header, subject, from, to,
   cc, size) from the message-list options. Rationale: they are near-useless for search results, and
   arrival never trips `run_pipelined`'s `$needs_header_sort` guard, so the pipeline **always**
   engages with no guard change. Cross-folder arrival IS globally chronological — it routes through
   the sorted branch (`rcube_imap.php:1091` → `sortHeaders`), confirmed by test.

2. **Drop progressive.** Remove the AVUZ progressive-search additions from
   `program/actions/mail/search.php`: the partial-listing on incomplete, the `continue_search`
   re-issue, the clear-on-continuation, the search-budget/`searchpartial` path. Restore the action to
   a single complete render. The pipeline now returns complete results fast, so `incomplete` should
   not occur for a normal search.

3. **Stop the refresh churn.** Two sources seen on the wire:
   - the periodic all-folders walk (`SELECT <folder>` + `UID SEARCH <n>` per folder, one/sec) — the
     new-mail/unread check firing while a search is displayed;
   - the `continue_search` loop (removed by #2).
   While a search result is active, suppress the periodic refresh / all-folder walk so paginating or
   opening a message does not re-fire it.

4. **Server-side search-result cache.** Reuse the matched UID set (already in `$_SESSION['search']`)
   across paginate/open instead of re-running SEARCH on Zoho. Confirm what re-triggers the search
   today and make the session set authoritative until the query/folder/filter changes.

## Out of scope (own spec)

- **Browser IndexedDB body cache** for instant message reopen. Bigger, and carries a privacy
  dimension (email bodies persisted client-side, survive logout, possibly-shared machines, inside
  the Nextcloud iframe). Deliberate follow-up.

## Build order

1. **Verify baseline** (test, no code): on staging, run a received-date all-folder search; confirm it
   engages the pipeline (batched SELECT+SEARCH, no serial fallback) and returns correct newest-first.
2. **Force arrival-only sort** + hide other sort columns. Re-test: pipeline engages, order correct.
3. **Drop progressive** from `search.php`. Re-test: complete single render, no streaming, selection
   sticks, no old-first.
4. **Gate the refresh** while a search is active. Re-test: paginate/open no longer re-fires search or
   the folder walk.
5. **Search-result cache**: reuse session UID set across paginate/open; invalidate on query change.

Each step: build → deploy staging → test on staging (no local docker). Keep `AVUZ_PIPELINED_SEARCH=1`.

## Open questions to resolve during build

- Does received-date sort currently map to `arrival` or `date` in this codebase? (decides whether
  step 1 already pipelines or needs the sort forced first)
- Exact hook to suppress the periodic all-folder walk during an active search (config
  `check_all_folders` / refresh_interval / plugin `avuz_prefetch`?).
- Cleanest place to make the session search set authoritative for pagination.
