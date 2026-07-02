/**
 * avuz_prefetch — warm every message body (+ inline images) on the current list
 * page in throttled background batches, so any click on the page serves from cache
 * instead of a fresh remote IMAP fetch. Bounded by mail_pagesize (set to 30).
 * Re-runs on page change; seen{} dedupes.
 */
(function () {
  if (!window.rcmail) return;

  var BATCH = 8; // UIDs per background request (PHP caps at 10)
  var seen = {};
  var mbox = '';

  function pageUids() {
    var out = [];
    var trs = document.querySelectorAll('tr[id^="rcmrow"]');
    for (var i = 0; i < trs.length; i++) {
      var uid = trs[i].id.replace(/^rcmrow/, '');
      if (uid) out.push(uid);
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
    if (window.console) console.log('avuz_prefetch: page has', all.length, 'rows in', mbox);

    var uids = [];
    for (var i = 0; i < all.length; i++) {
      var key = mbox + ':' + all[i];
      if (seen[key]) continue;
      seen[key] = 1;
      uids.push(all[i]);
    }
    sendBatches(uids);
  }

  var t;
  function schedule() { window.clearTimeout(t); t = window.setTimeout(prefetchPage, 300); }

  rcmail.addEventListener('init', function () {
    rcmail.addEventListener('afterlist', schedule);   // load + page change
    rcmail.addEventListener('listupdate', schedule);
    schedule();
  });
})();
