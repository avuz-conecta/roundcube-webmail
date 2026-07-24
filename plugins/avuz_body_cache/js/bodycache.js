/* avuz_body_cache: wire the store, controller and open-interception into rcmail. */
(function () {
  if (!window.rcmail) return;
  rcmail.addEventListener('init', function () {
    if (!rcmail.env.avuz_body_cache || !window.indexedDB) return;

    avuzIdb.open(rcmail.env.avuz_cache_user).then(function () {
      var schedule, t;
      schedule = function () { clearTimeout(t); t = setTimeout(function () { avuzPrefetch.run(); }, 300); };
      rcmail.addEventListener('afterlist', schedule);
      rcmail.addEventListener('listupdate', schedule);
      schedule();
    }).catch(function () { /* private mode / blocked: degrade to no-op */ });

    // Try cache before a normal open — only for the preview-pane path.
    var baseShow = rcmail.show_message;
    rcmail.show_message = function (id, safe, preview) {
      if (preview && id && rcmail.env.contentframe) {
        // params_from_uid resolves {_uid, _mbox} from the row id, including the
        // per-row folder for multifolder search — the correct Roundcube API for this.
        var p = rcmail.params_from_uid(id, {});
        var uid = p._uid, folder = p._mbox || rcmail.env.mailbox;
        // The list row object carries an `.unread` boolean (app.js:1059
        // `rows[uid].unread`), extended from env.messages — NOT a CSS class. Use it so
        // a cache-hit open of an unread message actually marks it read.
        var row = rcmail.message_list && rcmail.message_list.rows[id];
        var unread = !!(row && row.unread);
        avuzOpen.tryHit(uid, folder, unread).then(function (hit) {
          if (!hit) baseShow.call(rcmail, id, safe, preview);
        });
        return;
      }
      return baseShow.call(rcmail, id, safe, preview);
    };

    // No reliable client 'logout' EVENT exists in rcmail. Best-effort clear when the
    // logout COMMAND fires (navigation may cut it short — that is fine: the guaranteed
    // control against cross-user exposure is the identity-change DB drop in idb.js,
    // which fires on the next user's first load).
    var baseCommand = rcmail.command;
    rcmail.command = function (cmd) {
      if (cmd === 'logout') { try { avuzIdb.clearAll(); } catch (e) {} }
      return baseCommand.apply(this, arguments);
    };
  });
})();
