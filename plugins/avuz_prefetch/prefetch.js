/**
 * avuz_prefetch — background-warm the top messages' bodies after the list renders,
 * so clicking one serves from cache instead of a fresh remote IMAP fetch.
 */
(function () {
  if (!window.rcmail) return;

  var TOP_N = 8;
  var seen = {};

  function prefetchTop() {
    if (rcmail.env.task !== 'mail' || !rcmail.message_list) return;

    var rows = rcmail.message_list.rows || {};
    var mbox = rcmail.env.mailbox;
    var uids = [];
    var count = 0;

    for (var id in rows) {
      if (count >= TOP_N) break;
      count++;
      var uid = rows[id] && rows[id].uid;
      if (!uid) continue;
      var key = mbox + ':' + uid;
      if (seen[key]) continue;
      seen[key] = 1;
      uids.push(uid);
    }

    if (!uids.length) return;

    var send = function () {
      rcmail.http_post('plugin.avuz_prefetch', { _uids: uids.join(','), _mbox: mbox });
    };

    // low priority — don't compete with the user's actual click
    if (window.requestIdleCallback) window.requestIdleCallback(send, { timeout: 2000 });
    else window.setTimeout(send, 600);
  }

  rcmail.addEventListener('init', function () {
    rcmail.addEventListener('listupdate', prefetchTop);
    rcmail.addEventListener('afterlist', prefetchTop);
    // first paint may already have rows
    prefetchTop();
  });
})();
