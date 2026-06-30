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

    // Show the overlay while the (full-page) save POST is in flight.
    rcmail.addEventListener('beforeplugin.password-save', show);

    // The save is a full-page form POST, not AJAX — so there is no response
    // event to hook. On success the page reloads to plugin.password-save with
    // env.avuz_password_changed set by the password_change hook. Detect it here,
    // on the freshly loaded result page, and send the top window to the inbox.
    if (rcmail.env.avuz_password_changed) {
      show();
      var win = window.top || window;
      win.location.href = win.location.pathname + '?_task=mail&_mbox=INBOX';
    }
  });
})();
