/* avuz_body_cache: instant open from cache. Miss -> caller falls back to normal open. */
(function () {
  // Paint method proven by the Task 0 spike. (srcdoc variant shown.)
  function paintFromCache(iframe, html) {
    iframe.removeAttribute('src');
    iframe.srcdoc = html;
  }

  window.avuzOpen = {
    tryHit: function (uid, folder, unread) {
      if (!rcmail.env.avuz_body_cache) return Promise.resolve(false);
      var iframe = rcmail.env.contentframe && document.getElementById(rcmail.env.contentframe);
      if (!iframe) return Promise.resolve(false);    // no preview frame -> normal open
      var key = avuzPrefetch.keyFor(folder, String(uid));
      return avuzIdb.get(key).then(function (rec) {
        if (!rec) return false;
        // Preserve the state setup show_message would do before painting.
        rcmail.preview_id = uid;
        rcmail.env.uid = uid;
        rcmail.show_contentframe(true);
        paintFromCache(iframe, rec.html);
        // SPIKE FINDING (Task 0): srcdoc renders the body but the framed page's scripts
        // do NOT re-enable the message toolbar in the srcdoc frame — so we enable the
        // message-context commands ourselves (verified: reply then opens compose with the
        // correct quoted body once env.uid is set). Match the set a normal open enables.
        rcmail.enable_command('reply', 'reply-all', 'reply-list', 'forward',
          'forward-attachment', 'forward-inline', 'print', 'delete', 'move', 'copy',
          'mark', 'viewsource', 'download', 'edit', 'open', 'more', true);
        // The fast path skipped the render that marks read: mark it now (real open),
        // but only when the message was actually unread (mirror core, which only marks
        // inside the empty(SEEN) block).
        if (unread) {
          rcmail.set_unread_message(uid, folder);       // client unread counters
          rcmail.http_post('mark', { _uid: uid, _mbox: folder, _flag: 'SEEN', _quiet: 1 }); // server \Seen
        }
        return true;
      }).catch(function () { return false; });
    }
  };
})();
