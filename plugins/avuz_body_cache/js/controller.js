/* avuz_body_cache: prefetch controller. Fills IndexedDB for visible+lookahead rows. */
(function () {
  var CONCURRENCY = 3, token = 0;

  function userTag() { return rcmail.env.avuz_cache_user; }
  function sanitizerV() { return rcmail.env.avuz_sanitizer_version; }

  function windowRows() {
    // All rendered rows; each row carries uid and (for multifolder search) folder.
    var rows = (rcmail.message_list && rcmail.message_list.rows) || {}, out = [];
    for (var id in rows) {
      var r = rows[id];
      if (r && r.uid) out.push({ uid: String(r.uid), folder: r.folder || rcmail.env.mailbox });
    }
    return out; // rendered page already ~= visible + one page of lookahead in Elastic
  }

  function keyFor(folder, uid) { return userTag() + '|' + folder + '|' + uid + '|' + sanitizerV(); }

  function warmRedis(rows) {
    // Group into folder-qualified uid tokens, <=10 per POST (server caps at MAX_UIDS).
    var toks = rows.map(function (r) { return r.uid + ':' + r.folder; });
    for (var i = 0; i < toks.length; i += 10) {
      rcmail.http_post('plugin.avuz_prefetch', { _uids: toks.slice(i, i + 10).join(',') });
    }
  }

  function fetchBody(row) {
    var url = rcmail.url('preview', { _uid: row.uid, _mbox: row.folder, _framed: 1, _preload: 1, _safe: rcmail.env.avuz_show_images ? 1 : 0 });
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : null; });
  }

  function run() {
    if (!rcmail.env.avuz_body_cache) return;
    var myToken = ++token;
    var rows = windowRows();
    warmRedis(rows);

    var queue = rows.slice(), active = 0;
    function pump() {
      if (myToken !== token) return;                 // superseded
      if (rcmail.busy) { setTimeout(pump, 300); return; } // lose races to the user
      while (active < CONCURRENCY && queue.length) {
        (function (row) {
          active++;
          var key = keyFor(row.folder, row.uid);
          avuzIdb.has(key).then(function (hit) {
            if (hit || myToken !== token) return null;
            return fetchBody(row).then(function (html) {
              if (html && myToken === token) return avuzIdb.put(key, html);
            });
          }).then(function () {
            active--;
            avuzIdb.evictToBudget(500, 50 * 1024 * 1024);
            pump();
          }).catch(function () { active--; pump(); });
        })(queue.shift());
      }
    }
    pump();
  }

  window.avuzPrefetch = { keyFor: keyFor, run: run };
})();
