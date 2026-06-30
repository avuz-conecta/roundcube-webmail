/* Avuz: visible loading overlay while a password change is in flight.
 *
 * The password reset round-trips through the broker (IMAP verify + Zoho reset)
 * and can take a few seconds. Roundcube only shows the browser's own spinner,
 * so we add a full-window overlay between the save command and its response.
 */
(function () {
  if (typeof window.rcmail === 'undefined') {
    return;
  }

  rcmail.addEventListener('init', function () {
    var overlay = null;
    var timer = null;
    var saving = false;
    var succeeded = false;

    // The password form runs inside the settings content iframe; cover the top
    // window so the overlay spans the whole app (same-origin, so accessible).
    function targetDoc() {
      try {
        return (window.top && window.top.document) || document;
      } catch (e) {
        return document;
      }
    }

    function show() {
      if (overlay) {
        return;
      }

      var doc = targetDoc();
      overlay = doc.createElement('div');
      overlay.className = 'avuz-loading-overlay';
      overlay.innerHTML =
        '<div class="avuz-loading-box">' +
        '<div class="avuz-spinner"></div>' +
        '<div class="avuz-loading-text">Alterando senha…</div>' +
        '</div>';
      doc.body.appendChild(overlay);

      // Safety net: never leave the overlay stuck if no response arrives.
      timer = window.setTimeout(hide, 30000);
    }

    function hide() {
      if (timer) {
        window.clearTimeout(timer);
        timer = null;
      }
      if (overlay && overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
      overlay = null;
    }

    rcmail.addEventListener('beforeplugin.password-save', function () {
      saving = true;
      succeeded = false;
      show();
    });

    // A 'confirmation' message during the save means the password changed.
    // In a framed settings page rcmail.display_message forwards the message to
    // the parent window, so the 'message' event fires on parent.rcmail — listen
    // there too (falls back to self when not framed).
    function onMessage(prop) {
      if (saving && prop && prop.type === 'confirmation') {
        succeeded = true;
      }
    }

    rcmail.addEventListener('message', onMessage);
    try {
      if (window.parent && window.parent.rcmail && window.parent.rcmail !== rcmail) {
        window.parent.rcmail.addEventListener('message', onMessage);
      }
    } catch (e) {
      /* cross-origin parent — ignore */
    }

    rcmail.addEventListener('responseafterplugin.password-save', function () {
      saving = false;

      if (succeeded) {
        // Keep the overlay up and send the whole app to the inbox.
        try {
          (window.top || window).location.href = '?_task=mail';
        } catch (e) {
          window.location.href = '?_task=mail';
        }
        return;
      }

      hide();
    });
  });
})();
