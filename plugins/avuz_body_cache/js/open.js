/* avuz_body_cache: instant open from cache. Miss -> caller falls back to normal open. */
(function () {
  // Base URL for the srcdoc so root-absolute asset paths (/skins/...) resolve.
  function paintFromCache(iframe, html) {
    var base = location.href.split(/[?#]/)[0];
    var tag = '<base href="' + base + '">';
    var withBase = /<head[^>]*>/i.test(html)
      ? html.replace(/<head([^>]*)>/i, '<head$1>' + tag)
      : tag + html;
    // Attach the navigation guard once the srcdoc document exists.
    iframe.onload = function () { iframe.onload = null; guardFrame(iframe); };
    iframe.removeAttribute('src');
    iframe.srcdoc = withBase;
  }

  // A srcdoc frame has no URL, so the message's in-frame controls (Detalhes,
  // Cabeçalhos, Texto simples, load-remote, links) whose handlers depend on the
  // frame's own rcmail/UI — which isn't fully initialised here — fall through to
  // their default href and navigate the frame to a full page (the whole app).
  // Guard it: external links open in a new tab; ANY internal navigation is blocked
  // and instead upgrades to a real, full-fidelity open of this message (the frame
  // loads the real preview URL, Redis-warm ~200ms, where every control works).
  function guardFrame(iframe) {
    var doc;
    try { doc = iframe.contentDocument; } catch (e) { return; }
    if (!doc) return;
    doc.addEventListener('click', function (e) {
      var a = e.target && e.target.closest && e.target.closest('a[href]');
      if (!a) return;
      if (/^(mailto|tel):/i.test(a.getAttribute('href') || '')) return; // let the OS handle
      if (/^https?:\/\//i.test(a.href) && a.hostname && a.hostname !== location.hostname) {
        a.setAttribute('target', '_blank');            // external -> new tab
        a.setAttribute('rel', 'noopener noreferrer');
        return;
      }
      // Attachment / message-part download or inline view (_action=get / _part=):
      // let it proceed in a new tab on the FIRST click — it never navigates the
      // message frame, so no upgrade-reload is needed.
      if (/[?&]_action=get(?:&|$)/.test(a.href) || /[?&]_part=/.test(a.href)) {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        return;
      }
      e.preventDefault();                              // block the frame nav (no nested app)
      e.stopPropagation();
      realOpen(iframe);                                // upgrade to the real render
    }, true);
    doc.addEventListener('submit', function (e) { e.preventDefault(); realOpen(iframe); }, true);
  }

  // Swap the cached srcdoc for a real framed navigation of the current message.
  // Bypasses show_message (and thus the cache), so all controls work natively.
  function realOpen(iframe) {
    var uid = rcmail.preview_id;
    if (!uid) return;
    var folder = rcmail.get_message_mailbox(uid);
    var url = rcmail.url('preview', { _uid: uid, _mbox: folder, _framed: 1 });
    iframe.removeAttribute('srcdoc');
    iframe.src = url;
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
        // DO NOT set rcmail.env.uid here. get_single_uid() (app.js) is
        // `this.env.uid || message_list.get_single_selection()` — during normal preview
        // browsing the parent env.uid stays UNSET so it falls through to the list
        // selection. Pinning env.uid to this message froze get_single_uid(), so every
        // later click reopened the first-opened message. Reply/forward already target
        // get_single_uid() = the highlighted row, so env.uid is unnecessary here.
        rcmail.show_contentframe(true);
        paintFromCache(iframe, rec.html);
        // SPIKE FINDING (Task 0): srcdoc renders the body but the framed page's scripts
        // do NOT re-enable the message toolbar in the srcdoc frame — so we enable the
        // message-context commands ourselves. (Belt-and-suspenders: msglist_select also
        // enables message_commands on selection.) Match the set a normal open enables.
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
