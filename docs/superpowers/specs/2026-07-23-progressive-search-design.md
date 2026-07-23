# Progressive search: show results as they arrive

**Date**: 2026-07-23
**Status**: design, not implemented
**Related**: `2026-07-23-search-alternatives-research.md` (option 1 of the ranked list),
`2026-07-23-search-pipelining-hardening-design.md` (the dormant pipelining work)

## The problem, stated as the user reported it

Not "search is slow" — **"users can't finish any search"**. Measured on prod: all-folder search
median 15.5s, worst 212.3s. One session sat behind a spinner for 212 seconds for a single search.
People abandon the search rather than wait, so the feature is effectively unavailable regardless of
what the numbers say.

That distinction decides the design. Making search finish faster is a hard problem gated on Zoho's
API, an index, or infrastructure we have ruled out on cost. Making search *usable* is not: the
results already arrive incrementally, and we simply refuse to show them.

## What Roundcube already does

The incremental machinery exists and works. `rcube_imap_search::exec()`:

- creates one job per folder, and **reuses any folder result already completed in a previous round**
  (`$this->results->get_set($folder)`)
- runs jobs until a time limit, then returns a result flagged `incomplete`

`rcube_imap.php:1655` sets that limit:

```php
// set limit to not exceed the client's request timeout
$searcher->set_timelimit(60);
```

`search.php` then does this (line 124 and line 154):

```php
if (!isset($result) || empty($result->incomplete)) {
    $result_h = $rcmail->storage->list_messages($mbox, 1, $sort_column, $sort_order);
}
...
else if (!empty($result) && !empty($result->incomplete)) {
    $count = 0;  // keep UI locked
    $rcmail->output->command('continue_search', $search_request);
}
```

and `app.js:5549` re-issues the same search 100ms later with `_continue=<id>`, holding a
`stillsearching` busy lock.

**So the search is already progressive on the server and already resumable. The only thing missing
is showing the user what has been found.** Headers are fetched *only* when the whole thing is
complete, and the count is deliberately forced to 0 to "keep UI locked".

That is the entire bug, in two lines.

## Requirement

1. Results appear as folders complete, not only when every folder has been searched.
2. The user can read, open and act on partial results while the rest continues.
3. The user can always tell the difference between "still searching" and "finished".
4. Choosing whole-message (body) search warns that it will be slow, because that cost is Zoho's
   server-side compute and no client change can remove it.
5. A search that cannot finish must end in a clear, honest state — never an endless spinner.

## Design

### 1. Show partial results on every round

In `search.php`, list and render the accumulated result set even when `incomplete` is set, instead
of forcing `$count = 0`. The accumulated set is already correctly ordered — `rcube_result_multifolder`
sorts across folders — so each round re-lists page 1 and replaces the displayed rows.

Cost per round is **one FETCH of at most `mail_pagesize` (30) headers**, not a re-search: the folder
results are cached in the session and reused. This is cheap and bounded.

Keep `continue_search` firing. The difference is purely that the user sees rows accumulating.

### 2. Make the round short, and make the limit configurable

The 60s limit is hardcoded. With progressive display it becomes the update interval, and 60s is far
too long to wait for a first paint.

Introduce `$config['imap_search_timelimit']`, defaulting to the current 60 for upstream parity, and
set it to **8** for this deployment. First results then appear within roughly 8 seconds instead of
up to 60, and the display refreshes on that cadence.

Tuning note: shorter rounds mean more HTTP round trips and more session writes. 8s is a starting
point to be measured, not a proven value.

### 3. Show progress, and end honestly

While incomplete, show "searching… N found so far" rather than a bare spinner. When the search
completes normally, show the existing `searchsuccessful` message.

**Bound the total.** Add `$config['imap_search_total_timelimit']` (proposed default 120s). When
exceeded, stop issuing continuations and tell the user plainly that the search was stopped and the
results are partial, with the option to continue. An unbounded retry loop that looks like a hang is
the current behaviour and is the thing we are removing — replacing it with a *visible* unbounded
loop is not good enough.

### 4. Warn on whole-message search

When the user selects body/entire-message scope, show a one-line notice that it searches message
contents and will be slower. Zoho charges real server time for `TEXT` searches — 69s measured — and
this is the honest way to set expectation. Per the service owner: proceed with current behaviour and
warn.

## Where the code goes, and why

| Change | Where | Rebase risk |
|---|---|---|
| Show partial results; progress text; total bound | `program/actions/mail/search.php` | core patch, needs Dockerfile COPY |
| Configurable per-round limit | `program/lib/Roundcube/rcube_imap.php` | core patch, needs Dockerfile COPY |
| Body-search warning; any client behaviour | plugin JS | low |

**Do not patch `program/js/app.js`.** It is large, changes between releases, and a patched copy
would be a permanent rebase burden. Anything client-side belongs in plugin JS, following the
existing pattern (`plugins/nextcloud_sso/avuz-overrides.js`, `plugins/avuz_prefetch/prefetch.js`).

**Every core patch needs its `COPY` line in the Dockerfile and an entry in `customizations.json`.**
This has already bitten twice: the pipelined-search patches shipped inert because two core files had
no COPY, and staging ran stock code while the repo said otherwise. Verify by grepping the *running
container*, not the repo.

## Interaction with the dormant pipelining work

`AVUZ_PIPELINED_SEARCH=0` on prod. Progressive search must work correctly with pipelining both off
(today) and on (possibly later), because it changes only how results are *displayed*, not how they
are *gathered*. It must not depend on `run_pipelined()` having run.

If pipelining is later fixed and enabled, the two compose: fewer, faster rounds. If it is abandoned,
progressive search stands alone. This is the property that makes it worth building first — it is
correct under every outcome of the Zoho API research.

## Testing

Behaviour, not implementation:

- an incomplete search renders the rows found so far, and does not force the count to zero
- a completed search behaves exactly as today
- results already completed in an earlier round are not re-searched (assert no repeat SELECT/SEARCH
  for a folder already done — the session-cached results path)
- ordering across folders is correct in a partial result, not merely appended in arrival order
- exceeding the total limit stops the continuation loop and reports partial results, rather than
  looping
- a single-folder search is unaffected

Measure on staging with `ROUNDCUBE_DEBUG=1`: time-to-first-row for an all-folder search, versus
today's time-to-any-row (which equals total search time). That is the number this work exists to
move.

## What this explicitly does not do

It does not make search faster. Total time to a complete answer is unchanged; body searches still
cost Zoho's server time; a 107-folder account still pays `N x 2` round trips while pipelining is
off. It converts a 200-second blank wait into results that stream in from ~8 seconds, which is the
difference between a feature nobody can use and one they can.

The durable fix for *speed* remains the open question in the Zoho API research: whether search
results can be mapped back to IMAP messages. Zoho's documented search response carries `messageId`
and `folderId` but **no IMAP UID and no RFC822 Message-ID**, so that option currently hinges on
finding a bridge.

## Open questions

1. Is 8s the right round length? Needs measuring — too short wastes round trips, too long delays
   first paint.
2. Does re-listing page 1 each round disturb a user who has scrolled or selected a row? If so,
   refresh only when new results arrived, and preserve selection.
3. Should the total bound be a hard stop or a "keep going?" prompt? A hard stop is simpler and more
   honest; a prompt is friendlier for someone who genuinely wants the whole answer.
