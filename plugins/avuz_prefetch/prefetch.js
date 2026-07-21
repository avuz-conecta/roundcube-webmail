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
  var SEEN_TTL_MS = 60 * 60 * 1000; // 1 hour — well under the 10-day body TTL
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

  var mbox = '';
  // UIDs queued (module-scoped key mbox+':'+uid) but not yet POSTed. Guards
  // against a listupdate firing while sendBatches is still dribbling out
  // earlier batches — without this, the same not-yet-sent UIDs get collected
  // and POSTed a second time. Cleared per-UID the moment its batch is sent,
  // at which point it enters `seen` instead. Never persisted: if the tab dies
  // mid-dribble the UID was never sent, so it must stay retryable (Finding B).
  var inFlight = {};

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

  function idle(fn) {
    if (window.requestIdleCallback) window.requestIdleCallback(fn, { timeout: 3000 });
    else window.setTimeout(fn, 200);
  }

  // uids is only the not-yet-seen, not-already-inFlight set for this page pass
  // (dedup applied by the caller). Marking + persisting happens per batch,
  // AFTER it is actually POSTed — a reload/nav that kills batches 2-4 must not
  // claim them as seen, otherwise (with the server sentinel fixed to require
  // live bodies) nothing would ever retry them.
  //
  // The folder is captured ONCE here, not read from module-level `mbox` per
  // tick: sendBatches dribbles one batch per idle slot, so a folder switch
  // mid-dribble must not relabel a still-inflight INBOX closure's remaining
  // batches under the new folder (wrong _mbox POSTed, wrong UIDs marked seen).
  function sendBatches(uids) {
    if (!uids.length) return;
    var m = mbox;
    var i = 0;
    (function next() {
      if (i >= uids.length) return;
      var batch = uids.slice(i, i + BATCH);
      i += BATCH;
      rcmail.http_post('plugin.avuz_prefetch', { _uids: batch.join(','), _mbox: m });
      for (var j = 0; j < batch.length; j++) {
        var key = m + ':' + batch[j];
        seen[key] = Date.now();
        delete inFlight[key];
      }
      saveSeen();
      idle(next); // one batch per idle slot — don't flood Zoho or block the click
    })();
  }

  function prefetchPage() {
    if (rcmail.env.task !== 'mail') return;
    mbox = rcmail.env.mailbox;

    var all = pageUids();

    // Skip anything still fresh in `seen` or already queued by a dribble that
    // hasn't finished sending (inFlight) — the latter also serves as this
    // pass's own in-loop dedupe, since it's marked immediately below.
    var uids = [];
    for (var i = 0; i < all.length; i++) {
      var key = mbox + ':' + all[i];
      if (isSeen(key) || inFlight[key]) continue;
      inFlight[key] = 1;
      uids.push(all[i]);
    }

    if (uids.length) {
      sendBatches(uids);
    }
  }

  var t;
  function schedule() { window.clearTimeout(t); t = window.setTimeout(prefetchPage, 300); }

  rcmail.addEventListener('init', function () {
    rcmail.addEventListener('afterlist', schedule);   // load + page change
    rcmail.addEventListener('listupdate', schedule);
    schedule();
  });
})();
