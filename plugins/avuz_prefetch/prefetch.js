/**
 * avuz_prefetch — warm every message body (+ inline images) on the current list
 * page in throttled background batches, so any click on the page serves from cache
 * instead of a fresh remote IMAP fetch. Bounded by mail_pagesize (set to 30).
 * Re-runs on page change; seen{} dedupes for an hour, then expires so an
 * evicted-and-server-rewarmed body gets a fresh POST too.
 */
(function () {
  if (!window.rcmail) return;

  var BATCH = 8; // UIDs per background request (PHP caps at 10)
  // Persisted for the tab's lifetime: a reload or task switch must not re-queue
  // UIDs we already warmed. Keys are already folder-scoped (mbox + ':' + uid).
  // Values are the ms timestamp a UID's batch was sent, not a plain flag: the
  // server sentinel re-warms an evicted body, but that logic is never reached
  // if the client gate here still says seen, so entries expire and get
  // re-queued (see isSeen). A legacy plain `1` (written by pre-fix code) is
  // treated as expired too, so it self-heals on the next pass.
  var SEEN_KEY = 'avuz_prefetch_seen';
  var SEEN_TTL_MS = 60 * 60 * 1000; // 1 hour — well under the 5-day body TTL
  var seen = loadSeen();

  function loadSeen() {
    try { return JSON.parse(sessionStorage.getItem(SEEN_KEY)) || {}; }
    catch (e) { return {}; }
  }

  function saveSeen() {
    try { sessionStorage.setItem(SEEN_KEY, JSON.stringify(seen)); }
    catch (e) { /* quota or private mode: in-memory only, no behavior change */ }
  }

  function isSeen(key) {
    var sentAt = seen[key];
    if (!sentAt || sentAt === 1) return false; // never sent, or legacy `1` sentinel
    return (Date.now() - sentAt) < SEEN_TTL_MS;
  }

  function pageUids() {
    // Roundcube base64-encodes the uid in the row DOM id, so read the real uid
    // from the row object (rcmail.message_list.rows[*].uid) instead of the id.
    var out = [];
    var rows = (rcmail.message_list && rcmail.message_list.rows) || {};
    for (var id in rows) {
      var row = rows[id];
      if (row && row.uid) out.push(String(row.uid));
    }
    return out;
  }

  var PREFETCH_RESPONSE_EVENT = 'responseafterplugin.avuz_prefetch'; // VERIFIED in Step 1
  var BATCH_TIMEOUT_MS = 90000; // fallback if a response is lost; MUST exceed the
                                // worst cold batch (~69s) or it fires mid-request
                                // and double-sends. See the Wave 1.5 design doc.

  // One warmer for the whole tab, always warming the CURRENT folder. runToken
  // identifies the live run; starting a new run bumps it, so any pending
  // response/timeout for the old run becomes a no-op. runTimer/runListener are
  // the single outstanding batch's fallback timer and response listener.
  var runToken = 0;
  var runFolder = null;
  var runActive = false;
  var runTimer = null;
  var runListener = null;

  function detachRun() {
    if (runTimer) { window.clearTimeout(runTimer); runTimer = null; }
    if (runListener) { rcmail.removeEventListener(PREFETCH_RESPONSE_EVENT, runListener); runListener = null; }
  }

  // Warm `folder`, one batch at a time. Supersedes any previous run: detachRun
  // drops the old listener/timer and the ++runToken makes the old run's next
  // check fail, so its un-sent batches are abandoned. The one batch already
  // POSTed for the old folder still completes on the server — it cannot be
  // recalled — but the rest are never sent, so the new folder's foreground
  // request does not compete with the old folder's leftover prefetch.
  function startRun(folder) {
    detachRun();
    var myToken = ++runToken;
    runFolder = folder;

    // Collect this folder's not-recently-warmed UIDs. isSeen has a 1h TTL, so a
    // batch already sent this hour is skipped; un-sent batches of an earlier
    // abandoned visit to this folder are NOT seen, so they re-queue here —
    // coverage is deferred by a folder switch, never lost.
    var all = pageUids();
    var uids = [];
    for (var k = 0; k < all.length; k++) {
      if (!isSeen(folder + ':' + all[k])) uids.push(all[k]);
    }
    if (!uids.length) { runActive = false; return; }
    runActive = true;

    var i = 0;
    var deferrals = 0;
    var MAX_DEFERRALS = 20; // bounded starvation guard (~6s), then proceed anyway
    (function sendNext() {
      if (myToken !== runToken) return;              // superseded by a newer folder
      if (i >= uids.length) { runActive = false; detachRun(); return; }

      // Lose races against the user: if a locked foreground request is in flight,
      // wait and retry rather than compete for a backend connection. Bounded so a
      // permanently busy UI eventually gets prefetched rather than never. The
      // myToken check above still fires first, so a folder switch during a defer
      // still supersedes correctly.
      if (rcmail.busy && deferrals < MAX_DEFERRALS) {
        deferrals++;
        window.setTimeout(sendNext, 300);
        return;
      }
      deferrals = 0;

      var batch = uids.slice(i, i + BATCH);
      i += BATCH;

      var advanced = false;
      function advance() {
        // First of (response, timeout) wins, and only if this run is still
        // current. sendNext is scheduled OUT of the event-dispatch loop:
        // rcube's triggerEvent iterates its handler array live (common.js:389),
        // so re-registering synchronously here would fire the new listener in
        // the same loop and cascade every batch at once.
        if (advanced || myToken !== runToken) return;
        advanced = true;
        detachRun();
        window.setTimeout(sendNext, 0);
      }
      runListener = advance;
      rcmail.addEventListener(PREFETCH_RESPONSE_EVENT, advance);
      runTimer = window.setTimeout(advance, BATCH_TIMEOUT_MS);

      rcmail.http_post('plugin.avuz_prefetch', { _uids: batch.join(','), _mbox: folder });

      // Mark seen at dispatch: a UID is seen iff its batch was sent. Un-sent
      // batches (abandoned by a folder switch) are never marked, so they retry.
      for (var j = 0; j < batch.length; j++) seen[folder + ':' + batch[j]] = Date.now();
      saveSeen();
    })();
  }

  function prefetchPage() {
    if (rcmail.env.task !== 'mail') return;
    var folder = rcmail.env.mailbox;

    // Folder changed → supersede and warm the new folder. Same folder but the
    // previous run finished → pick up anything new (pagination, new mail). Same
    // folder with a run still draining → leave it; new UIDs are collected on the
    // next trigger after it finishes.
    if (folder !== runFolder || !runActive) startRun(folder);
  }

  var t;
  function schedule() { window.clearTimeout(t); t = window.setTimeout(prefetchPage, 300); }

  rcmail.addEventListener('init', function () {
    rcmail.addEventListener('afterlist', schedule);   // load + page change
    rcmail.addEventListener('listupdate', schedule);
    schedule();
  });
})();
