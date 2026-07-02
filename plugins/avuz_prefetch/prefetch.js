/**
 * avuz_prefetch — viewport-based prefetch with lookahead.
 *
 * Warms message bodies (+ inline images) for rows visible in the list PLUS the
 * next LOOKAHEAD rows below the fold, so scrolling lands on already-warm messages.
 * Visibility is computed by geometry against the list's scroll container (robust
 * across skins), and re-evaluated on scroll. A forward-only frontier + seen{}
 * ensure each row is warmed at most once.
 */
(function () {
  if (!window.rcmail) return;

  var LOOKAHEAD = 10; // warm this many rows beyond the last visible one
  var BATCH     = 8;  // UIDs per background request (PHP caps at 10)

  var seen = {};
  var ordered = [];     // [{uid, el}] in DOM order for the current page
  var frontier = -1;    // highest index already queued (forward-only)
  var mbox = '';
  var scrollTarget = null;

  function scrollParent(el) {
    var p = el && el.parentElement;
    while (p) {
      var oy = window.getComputedStyle(p).overflowY;
      if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) return p;
      p = p.parentElement;
    }
    return null; // falls back to the window/viewport
  }

  function orderedRows() {
    var out = [];
    var trs = document.querySelectorAll('tr[id^="rcmrow"]');
    for (var i = 0; i < trs.length; i++) {
      var uid = trs[i].id.replace(/^rcmrow/, '');
      if (uid) out.push({ uid: uid, el: trs[i] });
    }
    return out;
  }

  function visibleBand() {
    var sp = scrollTarget && scrollTarget !== window ? scrollTarget : null;
    if (sp) { var r = sp.getBoundingClientRect(); return { top: r.top, bottom: r.bottom }; }
    return { top: 0, bottom: window.innerHeight || document.documentElement.clientHeight };
  }

  function lastVisibleIndex() {
    var band = visibleBand();
    var last = -1;
    for (var i = 0; i < ordered.length; i++) {
      var r = ordered[i].el.getBoundingClientRect();
      if (r.bottom > band.top && r.top < band.bottom) last = i; // overlaps the visible band
    }
    return last;
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
      idle(next);
    })();
  }

  function warmUpTo(maxVisibleIdx) {
    var target = Math.min(maxVisibleIdx + LOOKAHEAD, ordered.length - 1);
    if (target <= frontier) return;
    var uids = [];
    for (var i = frontier + 1; i <= target; i++) {
      var key = mbox + ':' + ordered[i].uid;
      if (seen[key]) continue;
      seen[key] = 1;
      uids.push(ordered[i].uid);
    }
    frontier = target;
    sendBatches(uids);
  }

  function tick() {
    if (!ordered.length) return;
    var lv = lastVisibleIndex();
    if (lv >= 0) warmUpTo(lv);
  }

  var scrollTimer;
  function onScroll() { window.clearTimeout(scrollTimer); scrollTimer = window.setTimeout(tick, 150); }

  function detachScroll() {
    if (scrollTarget) scrollTarget.removeEventListener('scroll', onScroll);
    scrollTarget = null;
  }

  function setup() {
    if (rcmail.env.task !== 'mail') return;

    mbox = rcmail.env.mailbox;
    ordered = orderedRows();
    frontier = -1;

    if (window.console) console.log('avuz_prefetch: observing', ordered.length, 'rows in', mbox);
    if (!ordered.length) return;

    detachScroll();
    scrollTarget = scrollParent(ordered[0].el) || window;
    scrollTarget.addEventListener('scroll', onScroll, { passive: true });

    tick(); // warm the initially-visible band + lookahead
  }

  var setupTimer;
  function scheduleSetup() { window.clearTimeout(setupTimer); setupTimer = window.setTimeout(setup, 300); }

  rcmail.addEventListener('init', function () {
    rcmail.addEventListener('afterlist', scheduleSetup);  // load + page change
    rcmail.addEventListener('listupdate', scheduleSetup);
    rcmail.addEventListener('insertrow', scheduleSetup);
    scheduleSetup();
  });
})();
