/**
 * avuz_prefetch — warm every message body (+ inline images) on the current list
 * page in throttled background batches, so any click on the page serves from cache
 * instead of a fresh remote IMAP fetch. Bounded by mail_pagesize (set to 30).
 * Re-runs on page change; seen{} dedupes.
 */
(function () {
  if (!window.rcmail) return;

  var BATCH = 8; // UIDs per background request (PHP caps at 10)
  // Persisted for the tab's lifetime: a reload or task switch must not re-queue
  // UIDs we already warmed. Keys are already folder-scoped (mbox + ':' + uid).
  var SEEN_KEY = 'avuz_prefetch_seen';
  var seen = loadSeen();

  function loadSeen() {
    try { return JSON.parse(sessionStorage.getItem(SEEN_KEY)) || {}; }
    catch (e) { return {}; }
  }

  function saveSeen() {
    try { sessionStorage.setItem(SEEN_KEY, JSON.stringify(seen)); }
    catch (e) { /* quota or private mode: in-memory only, no behavior change */ }
  }

  var mbox = '';

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

  function sendBatches(uids) {
    if (!uids.length) return;
    var i = 0;
    (function next() {
      if (i >= uids.length) return;
      var batch = uids.slice(i, i + BATCH);
      i += BATCH;
      rcmail.http_post('plugin.avuz_prefetch', { _uids: batch.join(','), _mbox: mbox });
      idle(next); // one batch per idle slot — don't flood Zoho or block the click
    })();
  }

  function prefetchPage() {
    if (rcmail.env.task !== 'mail') return;
    mbox = rcmail.env.mailbox;

    var all = pageUids();

    var uids = [];
    for (var i = 0; i < all.length; i++) {
      var key = mbox + ':' + all[i];
      if (seen[key]) continue;
      seen[key] = 1;
      uids.push(all[i]);
    }

    if (uids.length) {
      saveSeen();
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
