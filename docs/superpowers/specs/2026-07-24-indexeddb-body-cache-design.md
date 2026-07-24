# Browser-side IndexedDB cache of message bodies for instant open

**Date**: 2026-07-24
**Status**: design — approved (brainstorm 2026-07-24), ready to plan
**Builds on**: `2026-07-24-pipeline-only-search-design.md` (this is the "Out of scope" IndexedDB body cache deferred there)
**Related code already shipped**: `plugins/avuz_prefetch/` (server-side Redis body cache)

## Goal: Zimbra-parity instant open

The client came from Zimbra, where opening mail — including search results — is
near-instant. Even a Redis-warmed server render is ~100–300ms (PHP + browser↔server
round-trip from Brazil), which cannot match that. The only way to hit ~0ms is to read
the body from the **browser** with no network. So this cache is not just reactive
(store-on-view, §8); it is **proactive**: it prefetches the bodies of the messages
currently listed — visible rows plus a lookahead window — into IndexedDB *ahead of the
click*, exactly as Zimbra/Gmail do. See §12.

**Decisions locked in the 2026-07-24 brainstorm:**
- **Fetch mechanism = per-message background fetch of the existing preview render**
  (approach A), throttled, visible-first. Not a bulk endpoint (kept as a later
  server-load optimization). Rationale: reuses Roundcube's exact washtml render (no
  duplicate sanitization), delivers the clicked row's body soonest (parallel +
  progressive), and rides the Redis warming that already exists.
- **Lean on Redis; IndexedDB is the only new cache.** Bodies are immutable, so the two
  tiers never need syncing — no two-cache management burden.
- **Prefetch scope = visible + lookahead**, on **all listings** (folders and search).
- **Eviction = moderate LRU** (~500 msgs / ~50 MB, clear on logout).

## 1. Context and motivation

Zoho IMAP (`imap.zoho.com`) is high-latency from Brazil. The whole Avuz performance
effort exists to hide that latency. Two server-side caches already remove most of the
Zoho round-trips:

- Roundcube `imap_cache='redis'`, `messages_cache='db'` (Postgres) — headers/index,
  but **not body content**. `rcube_imap_cache` strips `$msg->body`
  (documented in `plugins/avuz_prefetch/avuz_prefetch.php:5-8`).
- `avuz_prefetch` — a Redis body cache keyed `folder:uid:mime_id`, served via the
  `message_part_body` hook before the IMAP fetch
  (`plugins/avuz_prefetch/avuz_prefetch.php:33-36`, `serve_cached_body`).

What still costs time on **reopen** of an already-viewed message: the browser makes a
fresh HTTP request to the Roundcube app for the `preview`/`show` action, PHP boots,
touches the session (Redis), reconstructs `rcube_message`, re-runs washtml
sanitization, and streams a full HTML document into the message iframe. Even with a
warm server body cache, that is a full server render on every click. This spec removes
that too: after the first view, the sanitized body lives in the **browser**, and
reopen paints from local storage with **zero network**.

### How a message body reaches the screen today (the integration point)

This is the single most important architectural fact for this design: **the message
body is not injected into the DOM as a JS string. It is a server-rendered HTML
document loaded into an `<iframe>` by navigating the iframe's URL.**

- Client: `show_message(id, safe, preview)` in `program/js/app.js:2529`. It builds a
  URL for the `preview` (or `show`) action, adds `_framed=1`
  (`app.js:2537-2552`), and navigates the content iframe to it via
  `location_href(url, target)` (`app.js:2569`). Preview URL shape is
  `?_action=preview&_uid=<uid>&_mbox=<folder>&_framed=1&_caps=...`
  (`app.js:837-838`).
- Server: `rcmail_action_mail_show::run()` (`program/actions/mail/show.php:30`)
  builds `rcube_message` (`show.php:63`) and renders the message template; the body
  HTML is sanitized through `rcmail_action_mail_index::wash_html()`
  (`program/actions/mail/index.php:911`, `->wash()` at `index.php:1004`).
- The iframe document is same-origin with the Roundcube top window (the override
  overlay code relies on this: `plugins/nextcloud_sso/avuz-overrides.js` reaches
  `window.top.document`).

So the browser cache must sit at the **iframe navigation** boundary, not at an AJAX
response. Two consequences: (a) we can populate the iframe from cache with `srcdoc`
instead of navigating; (b) the thing worth caching is the already-sanitized rendered
body HTML, captured after the iframe loads.

### Identity, folder, UID, UIDVALIDITY — what's available

- Client env exposes `rcmail.env.mailbox` and `rcmail.env.uid`
  (`program/actions/mail/get.php:81-82`, `index.php:118`), and `rcmail.env.delimiter`.
- UID + folder identify a message *within one UIDVALIDITY epoch*. UIDVALIDITY is
  available server-side via `rcube_imap::folder_data()` returning
  `$this->conn->data` (`rcube_imap.php:3777-3796`), which carries the SELECT
  response's `UIDVALIDITY`. Roundcube itself is lax here — there is an explicit
  `// @TODO: UIDVALIDITY checking` (`rcube_imap.php:1372`) — so **we cannot rely on
  the core to notice an epoch change**; the cache must carry UIDVALIDITY itself.
- User identity: there is no per-user secret on the client. The authenticated
  identity is the IMAP username (the Zoho email address), established via the
  Nextcloud SSO token (`?nc_token=`, `plugins/nextcloud_sso/`). We must surface a
  stable per-user key to the client for namespacing (see §5).

## 2. Goals / non-goals

**Goals**
- Reopening a previously-viewed message paints instantly (no Zoho, no Roundcube
  render) when the cache entry is fresh.
- Correctness first: a stale entry must **never** be shown as truth. When in doubt,
  fall back to the normal server render.
- Self-contained: ship as an Avuz plugin + client JS, no core `app.js` patch if
  avoidable.
- Privacy/security first-class: email content on disk in a possibly-shared,
  iframe-embedded browser.

**Non-goals (this iteration)**
- Attachments are **not** cached (size; see §4).
- Offline compose/send/queue. Read-only offline only, and only as a stretch (§9).
- Replacing `avuz_prefetch`'s server cache. The two are complementary layers.
- A general-purpose sync engine. This is an LRU read cache, nothing more.

## 3. Storage choice — IndexedDB (decision + rationale)

**Decision: IndexedDB**, one database per Roundcube origin, object stores namespaced
per authenticated user (§5).

| Option | Verdict | Why |
|---|---|---|
| **IndexedDB** | **Chosen** | Async (never blocks the UI thread); large quota (hundreds of MB under the Storage Standard, vs ~5 MB for localStorage); structured values (store the HTML string plus metadata object without manual serialization gymnastics); indexable for LRU eviction by `lastAccess`. |
| localStorage | Rejected | Synchronous (janks the main thread on every read/write of multi-KB bodies), ~5 MB hard cap (a few HTML emails blow it), string-only, no eviction primitives. `avuz_prefetch` already only uses `sessionStorage` for a tiny dedupe map — not for bodies. |
| Cache API (Service Worker) | Rejected as the store; **considered as the interception mechanism** | Cache API is keyed by `Request`/`Response`, which fits the iframe-GET model elegantly, but a Service Worker registered on the Roundcube origin controls **every** request on that origin, complicating the Nextcloud-iframe embedding, SW update lifecycle, and cross-user safety. Too much blast radius for a read cache. We keep interception in-page (§8) and the store in IndexedDB. Revisit SW only if offline (§9) becomes a hard requirement. |

## 4. What to store (size-aware)

Store the **server-sanitized rendered body HTML** for the message's displayed part,
plus lightweight metadata. Concretely, per entry:

- `bodyHtml`: the sanitized HTML (or plain-text-rendered HTML) as produced by
  washtml (`index.php:1004`) — i.e. exactly what the iframe showed. Typical email
  body 5–150 KB.
- **Inline images (CID parts)**: cache **only if already inlined as `data:` URIs**
  in the sanitized HTML. Do not fetch or store attachment binaries separately. Many
  Roundcube inline images render as separate `?_action=get&_part=...` URLs rather
  than `data:`; those are **left as URLs** and will re-fetch on reopen (server body
  cache / browser HTTP cache absorbs them). Rationale: bounding entry size and
  avoiding a second storage subsystem. A message whose inline images are URL-based
  still gets an instant body paint; only its images may repaint.
- **Attachments**: never. Downloaded on demand through the normal path.
- **Raw MIME**: never. We do **not** store raw MIME and sanitize client-side — there
  is no JS equivalent of washtml, and re-sanitizing client-side would be a new XSS
  surface. We store post-sanitization output only (§5 XSS).

Metadata per entry (the cache-key and validation fields):
`user`, `folder`, `uid`, `uidvalidity`, `mimeId`/format (`html`|`plain`), `safe`
(remote-images-allowed flag, from `_safe`), `sanitizerVersion`, `bytes`,
`createdAt`, `lastAccess`.

**Per-entry cap**: skip caching bodies over a threshold (proposed **512 KB** of
`bodyHtml`) — oversized newsletters are rare and dominate the budget.

## 5. Cache key, namespacing, and XSS handling

**Cache key** (compound):
`user` + `folder` + `uid` + `uidvalidity` + `format` + `safe` + `sanitizerVersion`.

- `user`: a stable, non-reversible per-user tag. **Recommendation**: the server emits
  `rcmail.env.avuz_cache_user = substr(hash_hmac('sha256', imap_username, des_key), 0, 16)`
  into the client env from the Avuz plugin. It is stable per mailbox, opaque, and not
  the raw email address sitting in the DB.
- `uidvalidity`: mandatory. The plugin surfaces the current folder's UIDVALIDITY
  (from `folder_data()`, `rcube_imap.php:3796`) into `rcmail.env` so the client can
  build the key. If UIDVALIDITY changed, **every** UID in that folder is a different
  message — old entries for that `folder` must be dropped (§7).
- `format` and `safe`: the same UID renders differently as html-vs-plain and with
  remote images allowed-vs-blocked; they are distinct entries, mirroring the
  server's own `$_SESSION['msg_formats']` distinction (`show.php:53-60`) and the
  `_safe` handling (`show.php:74`).
- `sanitizerVersion`: an integer the Avuz plugin controls. **Bump it on any washtml,
  skin, or CID-handling change.** This is the mechanism that guarantees "cache is
  never treated as pre-sanitized forever" (requirement 5): a changed sanitizer means
  a changed version means a cold cache. Wire it to the app/plugin build version so a
  deploy that touches rendering auto-invalidates.

**Per-user namespacing / cross-user leakage (the iframe problem).**
IndexedDB is partitioned by **origin** — the Roundcube app's own origin (the iframe
document's origin), *not* Nextcloud's. Every Avuz user who logs into the same
Roundcube deployment shares that origin and therefore that IndexedDB. This is the
core shared-machine / SSO-reuse risk: if user B signs in on the same browser after
user A (a new `nc_token` for a different mailbox), B must never see A's cached bodies.
Defenses, layered:

1. **Namespace every entry by `user`** (the HMAC tag). A read query always filters on
   the current session's `user`; a mismatched tag is invisible.
2. **Clear-on-logout and clear-on-identity-change** (§7) physically deletes, not just
   hides. On init the client compares `rcmail.env.avuz_cache_user` to the tag stored
   in the DB's `meta` record; if different, **drop the whole database** before use.
   This catches SSO re-login as a different user even when no explicit logout fired.
3. Never key on anything guessable/spoofable from client input alone; the `user` tag
   is minted server-side from the authenticated IMAP username + `des_key`.

**XSS surface.** The cached `bodyHtml` was produced by washtml and is the exact
document the iframe already trusted. On a cache hit we render it into the **same
sandboxed, same-origin message iframe** the server render uses — identical trust
boundary, no new sink. Rules:
- Never sanitize on the client; never store pre-sanitization HTML.
- The `sanitizerVersion` tag means a cache entry is only ever replayed under the
  exact sanitizer that produced it. A sanitizer fix invalidates all prior entries.
- On a hit, still route through the same iframe insertion the app uses (`srcdoc` on
  the existing message iframe), not `innerHTML` into the top document.

**Encryption at rest — recommendation: do NOT encrypt, document the limitation.**
A webmail client holds no client-side secret the browser can't also reach. Any key we
could derive (from `des_key`, the SSO token, session) would have to live in JS
reachable to the same code that reads the plaintext, so an attacker with the
plaintext IndexedDB also has the key — encryption would be theater. The honest
controls are **eviction and deletion**: aggressive clear-on-logout, clear-on-
identity-change, short TTL, and an opt-out. If a threat model genuinely requires
at-rest confidentiality on shared machines, the correct answer is **not enabling the
cache for that tenant** (feature flag, §10), not fake encryption. Note the OWASP
guidance that sensitive data should not be persisted in web storage; we accept a
scoped, controllable exception justified by the latency goal and mitigated by
deletion + flag, and we make it explicit rather than hidden.

## 6. Invalidation and correctness

Bodies are largely immutable (an email's content doesn't change), but *state around
them* does. The cache stores body content only; **flags (read/unread), folder, and
existence are authoritative from the server list, never from this cache.**

- **Read/unread and other flags**: not stored in the body cache at all. The message
  list already carries flag state from the server; opening from cache does not assert
  read-state. Marking `\Seen` still happens on the real open (the body cache serves
  the body; the mark action is a separate, cheap request we still fire — see §8 so we
  don't silently stop marking-as-read).
- **Moved / deleted / expunged**: on move/delete the UID leaves the folder. The list
  no longer offers it, so it won't be opened from cache. Additionally: subscribe to
  the client `move`/`delete` paths (or the plugin's own hooks) to **evict** the moved
  UID's entry proactively so a stale reopen via history/back can't surface it.
- **UIDVALIDITY change**: if the surfaced UIDVALIDITY for a folder differs from what
  cached entries recorded, **purge all entries for that folder** before serving any.
  This is the guard Roundcube core lacks (`rcube_imap.php:1372`).
- **Never show cache as truth on doubt**: any missing/invalid metadata,
  version mismatch, user mismatch, or UIDVALIDITY mismatch → treat as a miss and do a
  normal server render.

## 7. Eviction, limits, quota handling

- **Budget**: soft cap **~50 MB** and **~500 entries** per user namespace (tunable
  via env, §10). Well within IndexedDB quota, generous for a working set of recently
  read mail.
- **Policy: LRU** by `lastAccess` (indexed field). On write, if over budget, delete
  least-recently-accessed entries until under it.
- **TTL**: entries older than **5 days** (mirroring `avuz_prefetch`'s TTL,
  `avuz_prefetch.php:27`) are treated as misses and lazily purged. Keeps the on-disk
  working set bounded and re-warms cheaply.
- **Quota-exceeded**: wrap writes; on `QuotaExceededError` evict a batch (e.g. 20% of
  entries by LRU) and retry once; if it still fails, disable writes for the session
  and log. A write failure is never user-visible — the message already rendered from
  the server; caching is best-effort.
- **Private-mode / IndexedDB unavailable**: degrade to no-op, exactly as
  `prefetch.js` degrades its `sessionStorage` writes (`prefetch.js:29-31`).

## 8. Integration approach

> **Superseded by §13** (2026-07-24 grill): the reactive "capture on view" in this
> section is **dropped** in favour of prefetch-only (§13.1), and the `_preload`
> mark-seen suppression is a **1-line show.php core patch** (§13.2), so the
> "no core patch" goal no longer holds. Read §12 + §13 as authoritative; this
> section is kept for the architectural context (iframe/srcdoc, keys) it establishes.

Ship as a new Avuz plugin **`avuz_body_cache`** (client-heavy), mirroring the
`avuz_prefetch` structure:

- **Server side (thin)**: on `render_page`/login, emit into `rcmail.env`:
  `avuz_cache_user` (HMAC tag), current folder `avuz_uidvalidity`,
  `avuz_sanitizer_version`, and the feature flag. Read the flag from the environment
  with the same pattern as the pipeline flag
  (`getenv('AVUZ_...')`, cf. `rcube_imap_search.php:132`). Include `bodycache.js`.
- **Client side (the substance)** — hook Roundcube events, do **not** edit `app.js`:
  - Intercept the preview/show open. Roundcube fires client events around message
    display; the plugin listens (e.g. `beforeshow_message` / the `init` +
    `show_message` seam already used by `avuz_prefetch` via
    `responseafterplugin.*` and command hooks). On open of `(folder, uid)`:
    1. Build the key. Look it up in IndexedDB.
    2. **Hit (fresh)**: write `bodyHtml` into the existing message iframe via
       `iframe.srcdoc = bodyHtml` instead of letting it navigate to the server URL —
       instant paint, no network. **Still fire the lightweight mark-as-read** request
       (`?_action=mark` / the existing `set_unread_message` path, `app.js:2574`) so
       read-state is not silently lost by skipping the server open.
    3. **Miss/stale**: let the normal navigation happen. After the iframe loads,
       read the sanitized body out of the loaded same-origin iframe document and
       **write it to the cache** (with current metadata). This "capture on first
       view" needs no server cooperation because the iframe is same-origin.
  - Maintain `lastAccess` on every hit for LRU.
  - Evict on `move`/`delete` commands; purge-folder on UIDVALIDITY mismatch; drop-DB
    on user-tag mismatch at init; clear-all on `logout` (`app.js:797` / `1557`
    fire a client `logout`-ish transition the plugin can hook, plus a server
    `logout_after` hook, `rcmail.php:1023`, as belt-and-braces).

**Where a core change would be needed (only if the event seam is insufficient):** the
one spot that might require a minimal core hook is a clean "message iframe finished
loading, here is its document" client event. If Roundcube's existing
`messagecontentframe`/`contentframe` load handling doesn't expose it, add a one-line
`triggerEvent('message_iframe_loaded', ...)` at the frame `onload` in `app.js` and
consume it from the plugin. Prefer discovering an existing event first; document the
exact line if the patch proves necessary.

## 9. Offline behavior (scoped OUT this iteration)

With bodies in IndexedDB, reading already-viewed mail offline is *technically* within
reach, but the app shell, list, and every non-cached action still require the server,
so a partial offline mode would be confusing and is easy to get subtly wrong
(stale flags shown as truth — the exact §6 hazard). **Out of scope.** Revisit only
behind a Service Worker (which we deliberately avoided in §3) once the read cache is
proven. Document as a future item, not a deliverable.

## 10. Rollout, feature flag, and measurement

- **Feature flag**: `AVUZ_BODY_CACHE` env (default **off**), read server-side and
  surfaced to `rcmail.env`, following the `AVUZ_PIPELINED_SEARCH` precedent
  (`rcube_imap_search.php:115,132`, `config.inc.php:175`). Also expose tunables:
  `AVUZ_BODY_CACHE_MAX_MB`, `AVUZ_BODY_CACHE_MAX_ENTRIES`, `AVUZ_BODY_CACHE_TTL`.
  A per-tenant off switch is the escape hatch for the shared-machine threat model
  (§5).
- **Measurement**: emit a client timing on every open — `cache_hit` vs
  `server_render`, and time-to-body-visible. Success = a cache-hit reopen paints in
  well under ~100 ms with no `preview`/`show` request on the wire, versus the
  server-render baseline. Verify on **staging** (no local Docker per project
  constraints): open a message, reopen it, confirm (a) instant paint, (b) no IMAP /
  no Roundcube `preview` request in the network panel, (c) read-state still updates,
  (d) after logout the IndexedDB store is empty, (e) logging in as a different
  mailbox shows none of the previous user's bodies.
- **Staged enablement**: dev tenant → one staging tenant → opt-in prod tenants →
  default. Keep `avuz_prefetch` on throughout; the two caches stack.

## 11. Open questions (resolve before building)

1. **Iframe-load event**: does Roundcube already fire a usable client event when the
   message content iframe finishes loading (exposing its document), or is the minimal
   `app.js` `triggerEvent` patch in §8 required? Settle before committing to
   "no core patch."
2. **Inline image strategy**: what fraction of real Zoho mail renders inline images as
   `data:` (cacheable inline) vs `?_action=get&_part=` URLs (left to re-fetch)? If
   most are URL-based, is a body-only cache's instant-paint win still worth it, or do
   we need a companion image cache (bigger scope, revisit §4)?
3. **Mark-as-read on cache hit**: confirm the cheapest server call that marks `\Seen`
   without re-rendering the body, and that firing it from the plugin keeps unread
   counters (`app.js:2593`) correct in both single- and multi-folder listings.
4. **UIDVALIDITY surfacing cost**: `folder_data()` may trigger a SELECT
   (`rcube_imap.php:3783-3788`). Confirm it's already warm during normal list render
   so surfacing UIDVALIDITY to the client adds no extra Zoho round-trip.
5. **Encryption-at-rest tenant policy**: is "do not enable for shared-machine
   tenants" an acceptable answer for the security review, or is a real at-rest scheme
   (accepting its limits) mandated by any Avuz compliance requirement?
6. **`_preload` no-mark-seen mechanism** (§12): what is the exact hook to render the
   preview body without setting `\Seen`? Confirm the show/preview action's mark-read
   path (config `preview_pane_mark_read`, the client mark command, or a server mark in
   `show.php`) and which one a `_preload=1` flag must suppress.
7. **Lookahead window size + throttle**: how many rows beyond the viewport, and how many
   concurrent fetches, before it becomes a load or bandwidth problem on the shared
   PHP-FPM pool (single user is fine; many users prefetching at once is the risk)?
8. **avuz_prefetch search warming**: `avuz_prefetch` warms Redis on folder-list render;
   what is the cleanest hook to also warm it on a cross-folder **search** result set
   (which spans many folders and is assembled in `search.php`)?

## 12. Proactive prefetch (the Zimbra-parity layer)

This is the core of the instant-open goal. The reactive cache (§8) makes *reopen* fast;
this makes the **first** open of any listed message fast by filling IndexedDB before the
click.

### 12.1 Trigger and window

On every message-list render — a folder open OR a search result — and on scroll/page
change, the client computes a **prefetch window**: the currently-visible rows plus a
lookahead (the next page and a few rows above/below). This is list-agnostic: the same
controller runs for folder listings and search results.

### 12.2 The prefetch controller (client)

For each UID in the window, in priority order **selected row → visible rows →
lookahead**:
1. If a fresh IndexedDB entry already exists for the key, skip.
2. Otherwise background-`fetch()` the existing preview render:
   `?_action=preview&_uid=<uid>&_mbox=<folder>&_framed=1&_preload=1&_safe=<0|1>`.
   Throttle to ~4–6 concurrent (HTTP/2 multiplexes them over one connection, so the
   per-request cost is low). Each response is the sanitized body HTML.
3. Store it in IndexedDB under the §5 key (user + folder + uid + uidvalidity + format +
   safe + sanitizerVersion), updating `lastAccess` for LRU.

The controller is cancellable: navigating away or issuing a new search abandons the
in-flight window and starts a new one, so prefetch never competes with a real open.

### 12.3 The `_preload` flag (server — the one non-trivial server touch)

A background prefetch MUST NOT mark messages `\Seen` — the user has not opened them.
Roundcube's preview/show path marks read. So `_preload=1` tells the server to render and
sanitize the body but suppress the mark-read side effect (and any other state change:
no `HIGHESTMODSEQ` bump attribution, no "last opened" tracking). Implemented as a small
hook in the Avuz plugin, not a fork of the show action if avoidable. This is the single
correctness-critical server change; everything else reuses the stock render.

### 12.4 Open path with correct read-state

- **Cache hit**: the open handler sets `iframe.srcdoc` from the cached HTML → ~0ms, no
  network. Because the user *actually opened* it now, fire the real lightweight
  mark-`\Seen` request (the normal `set_unread_message` path) so unread counters stay
  correct. The body came from cache; only the tiny mark request touches the server.
- **Cache miss / stale**: fall through to normal navigation (which marks read as usual),
  then capture the sanitized body from the same-origin iframe and store it (§8).

### 12.5 Server-side warming for search

`avuz_prefetch` already warms Redis on folder-list render. Extend it to also warm on a
search result set so the browser's prefetch `fetch()`es for search results hit warm
Redis (~200ms) instead of cold Zoho (~1–3s). Without this, the first search-result
prefetches are slow and may lose the race to the click; with it, search open is as
instant as folder open. (Exact hook: §11 open question 8.)

### 12.6 Why this is fast where it counts

Open speed is identical to any cache-hit design (`srcdoc`, ~0ms). The prefetch mechanism
(A) was chosen because it makes the **clicked** row's body ready soonest: parallel
requests, visible-first priority, and each body usable the instant its own request
returns (progressive), versus a bulk endpoint that must render the whole batch before
anything is usable. Redis warming keeps each of those fetches server-fast. The result:
by the time the user's eye moves to a row and clicks, its body is already on local disk.

## 13. Resolutions from the 2026-07-24 grill (authoritative)

This section supersedes any conflicting detail above (notably §8's reactive
capture-on-view, which is **dropped** — see 13.1). It records the code-grounded
answers that resolved the open questions and the two design forks.

### 13.1 Prefetch-only — reactive capture is dropped

The cache is filled **exclusively** by the proactive prefetch controller (§12). A cache
**miss falls through to a normal server open** — no reading of the rendered iframe DOM,
no "message iframe loaded" event. This removes former open-question 1 entirely and
deletes the trickiest, least-reliable part of the original design. Hit rate is still high
because the controller prefetches visible + lookahead; anything the user can click has
almost certainly been prefetched.

### 13.2 The `_preload` flag is a 1-line core patch to show.php

`mail_read_time = 0` (config), so `show.php:128` marks `\Seen` **immediately** on any
preview render. A background prefetch must not do that. Resolution: gate that block with
`&& empty($_GET['_preload'])`. Approach A (reuse the real preview render) is kept
deliberately over a plugin render endpoint, to preserve **render fidelity** — the cached
HTML must be byte-for-byte what a real open shows (flowed text, plain→html, CID handling,
charset), which only the real render guarantees. Cost: `program/actions/mail/show.php`
joins the Dockerfile overlay list + customizations.json. Confirmed no pre-mark hook
exists to do this without the patch.

### 13.3 Search is multifolder — warm and fetch per row-folder (built now)

A search result spans many folders; each row's UID belongs to `row.folder`, NOT
`env.mailbox`. Both the Redis warm and the browser preview fetch use the per-row folder.
Concretely, built in this iteration:
- **avuz_prefetch (server)**: `prefetch()` extended to accept per-UID folders (e.g. a
  `{uid: folder}` map or `uid:folder` tokens) instead of a single `_mbox`, so it can warm
  Redis for a multifolder set. `serve_cached_body` (read hook) is unchanged.
- **controller (client)**: groups the visible+lookahead window by `row.folder`, warms
  Redis per folder-batch via `plugin.avuz_prefetch`, then issues per-message preview
  fetches with `_mbox = row.folder`.

The Redis `message_part_body` hook is read-only (a preview render on a miss does NOT
repopulate Redis — only the `plugin.avuz_prefetch` action stores), which is exactly why
the explicit warm step stays. Warming makes each preview fetch Redis-fast (~200ms) rather
than Zoho-cold (~1–3s) so the background prefetch beats the click on search too.

### 13.4 One client controller supersedes prefetch.js's loop

`prefetch.js`'s current single-folder client loop is folded into the new
`avuz_body_cache` controller (multifolder-aware, fills both Redis via the warm POST and
IndexedDB via the preview fetch). The `plugin.avuz_prefetch` **server** action stays
(extended, 13.3). Throttle 3–4 concurrent, **defer while `rcmail.busy`** (reuse the exact
bounded-defer pattern already in `prefetch.js`), cancel the in-flight window on
navigation/new-search so prefetch never competes with a real open. Window: visible
(`mail_pagesize=30`) + ~30 lookahead.

### 13.5 Read-state on a cache-hit open

Hit → `srcdoc` the cached HTML → fire `set_unread_message` (app.js:2575) + the server
mark, because the fast path skipped the render that normally marks read. Unread counters
stay correct in both single-folder and multifolder listings.

### 13.6 Remaining open questions (narrowed)

- **UIDVALIDITY surfacing cost**: confirm `folder_data()` is already warm during list
  render so surfacing UIDVALIDITY per folder adds no extra Zoho round-trip (former Q4).
- **Inline images**: bodies cache with inline images as `?_action=get&_part=` URLs, so
  the body paints instantly but images re-fetch (Redis/HTTP-cache absorbed). Accepted for
  v1; a companion image cache is out of scope (former Q2).
- **Bulk endpoint (B)**: the scale escape hatch if per-message prefetch fetches exhaust
  the 40-worker FPM pool under many concurrent users. Not built; the trigger to build it
  is measured worker saturation (former Q on load).
- **Encryption at rest**: unchanged from §5 — do not encrypt; rely on deletion + TTL +
  per-tenant `AVUZ_BODY_CACHE` off switch; "do not enable for shared-machine tenants" is
  the security-review answer unless compliance mandates otherwise.

### 13.7 Implementation surface (what changes)

- `program/actions/mail/show.php` — `_preload` mark-seen gate (core patch + Dockerfile + customizations.json).
- `plugins/avuz_prefetch/` — `prefetch()` accepts per-UID folders (multifolder warm).
- `plugins/avuz_body_cache/` (new) — server: emit env (`avuz_cache_user` HMAC, per-folder `uidvalidity`, `sanitizerVersion`, `AVUZ_BODY_CACHE` flag), include `bodycache.js`. Client `bodycache.js`: prefetch controller + IndexedDB store + open interception + eviction; supersedes `prefetch.js`'s client loop.
- Dockerfile / customizations.json — overlay show.php and the new plugin; mind the `*.min.js` rule.
