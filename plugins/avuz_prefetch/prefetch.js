/**
 * avuz_prefetch — background-warm the bodies (+ inline images) of every message
 * visible in the current list page, in throttled batches, so clicking one serves
 * from cache instead of a fresh remote IMAP fetch. Re-runs when the page changes.
 */
(function () {
  if (!window.rcmail) return;

  var BATCH = 8;      // UIDs per background request (PHP caps at 10)
  var seen = {};      // mbox:uid already queued

  function collectPending() {
    var rows = (rcmail.message_list && rcmail.message_list.rows) || {};
    var mbox = rcmail.env.mailbox;
    var pending = [];
    for (var id in rows) {
      var uid = rows[id] && rows[id].uid;
      if (!uid) continue;
      var key = mbox + ':' + uid;
      if (seen[key]) continue;
      seen[key] = 1;
      pending.push(uid);
    }
    return { mbox: mbox, uids: pending };
  }

  function schedule(fn) {
    if (window.requestIdleCallback) window.requestIdleCallback(fn, { timeout: 3000 });
    else window.setTimeout(fn, 700);
  }

  function prefetchVisible() {
    if (rcmail.env.task !== 'mail' || !rcmail.message_list) return;

    var job = collectPending();
    if (!job.uids.length) return;

    var i = 0;
    function nextBatch() {
      if (i >= job.uids.length) return;
      var batch = job.uids.slice(i, i + BATCH);
      i += BATCH;
      rcmail.http_post('plugin.avuz_prefetch', { _uids: batch.join(','), _mbox: job.mbox });
      schedule(nextBatch); // one batch per idle slot — don't flood Zoho or the click
    }
    schedule(nextBatch);
  }

  rcmail.addEventListener('init', function () {
    // afterlist fires on load AND on page change (next/prev) → covers pagination
    rcmail.addEventListener('afterlist', prefetchVisible);
    rcmail.addEventListener('listupdate', prefetchVisible);
    prefetchVisible();
  });
})();
