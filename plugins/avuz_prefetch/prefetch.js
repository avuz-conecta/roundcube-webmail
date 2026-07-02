/**
 * avuz_prefetch — viewport-based prefetch with lookahead.
 *
 * Warms the message bodies (+ inline images) for rows visible in the list PLUS
 * the next LOOKAHEAD rows below the fold, so scrolling lands on already-warm
 * messages. A rolling "frontier" only ever moves forward; seen{} dedupes; nothing
 * you never scroll to is fetched. Re-runs on page change and on appended rows.
 */
(function () {
  if (!window.rcmail) return;

  var LOOKAHEAD = 10; // warm this many rows beyond the last visible one
  var BATCH     = 8;  // UIDs per background request (PHP caps at 10)

  var seen = {};      // mbox:uid already queued (persists across pages/folders)
  var observer = null;
  var ordered = [];   // [{uid, el}] in list (DOM) order for the current page
  var idxOf = null;   // Map el -> index
  var frontier = -1;  // highest index already queued for the current page
  var mbox = '';

  function scrollParent(el) {
    var p = el && el.parentElement;
    while (p) {
      var oy = window.getComputedStyle(p).overflowY;
      if (oy === 'auto' || oy === 'scroll') return p;
      p = p.parentElement;
    }
    return null; // fall back to the viewport
  }

  function orderedRows() {
    var out = [];
    // message rows are <tr id="rcmrow<uid>">, in DOM (display) order
    var trs = document.querySelectorAll('tr[id^="rcmrow"]');
    for (var i = 0; i < trs.length; i++) {
      var uid = trs[i].id.replace(/^rcmrow/, '');
      if (uid) out.push({ uid: uid, el: trs[i] });
    }
    return out;
  }

  function idle(fn) {
    if (window.requestIdleCallback) window.requestIdleCallback(fn, { timeout: 3000 });
    else window.setTimeout(fn, 300);
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

  function warmUpTo(maxVisibleIdx) {
    var target = Math.min(maxVisibleIdx + LOOKAHEAD, ordered.length - 1);
    if (target <= frontier) return;
    var uids = [];
    for (var i = frontier + 1; i <= target; i++) {
      var uid = ordered[i].uid;
      var key = mbox + ':' + uid;
      if (seen[key]) continue;
      seen[key] = 1;
      uids.push(uid);
    }
    frontier = target;
    sendBatches(uids);
  }

  function onIntersect(entries) {
    var maxVisible = -1;
    for (var k = 0; k < entries.length; k++) {
      if (!entries[k].isIntersecting) continue;
      var idx = idxOf.get(entries[k].target);
      if (idx !== undefined && idx > maxVisible) maxVisible = idx;
    }
    if (maxVisible >= 0) warmUpTo(maxVisible);
  }

  function setup() {
    if (rcmail.env.task !== 'mail' || !rcmail.message_list) return;

    mbox = rcmail.env.mailbox;
    ordered = orderedRows();
    frontier = -1;
    idxOf = new Map();
    ordered.forEach(function (r, i) { idxOf.set(r.el, i); });

    if (window.console) console.log('avuz_prefetch: observing', ordered.length, 'rows in', mbox);
    if (!ordered.length) return;

    if (!('IntersectionObserver' in window)) {
      warmUpTo(Math.min(LOOKAHEAD, ordered.length - 1)); // fallback: first window only
      return;
    }

    if (observer) observer.disconnect();
    observer = new IntersectionObserver(onIntersect, {
      root: scrollParent(ordered[0].el),
      rootMargin: '0px',
      threshold: 0.1
    });
    ordered.forEach(function (r) { observer.observe(r.el); });
  }

  var t;
  function scheduleSetup() { window.clearTimeout(t); t = window.setTimeout(setup, 300); }

  rcmail.addEventListener('init', function () {
    rcmail.addEventListener('afterlist', scheduleSetup);  // page load / page change
    rcmail.addEventListener('listupdate', scheduleSetup);
    rcmail.addEventListener('insertrow', scheduleSetup);  // appended rows (continuous list)
    scheduleSetup();
  });
})();
