/* Avuz: show/hide toggle for the login password field.
 *
 * Adds an eye button inside #rcmloginpwd. The field flips back to
 * type=password before submit so browsers still offer to save it.
 * Loaded on the login task only (see nextcloud_sso.php).
 */
(function () {
  if (typeof window.rcmail === 'undefined') {
    return;
  }

  var PASSWORD_INPUT_ID = 'rcmloginpwd';
  var SVG_OPEN = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" '
    + 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
  var EYE_ICON = SVG_OPEN
    + '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>'
    + '<circle cx="12" cy="12" r="3"/></svg>';
  var EYE_OFF_ICON = SVG_OPEN
    + '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>'
    + '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
    + '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>'
    + '<line x1="1" y1="1" x2="23" y2="23"/></svg>';

  function renderToggle(button, isVisible) {
    button.innerHTML = isVisible ? EYE_OFF_ICON : EYE_ICON;
    button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
    button.setAttribute('aria-label', rcmail.gettext(isVisible ? 'hidepassword' : 'showpassword', 'nextcloud_sso'));
    button.title = button.getAttribute('aria-label');
  }

  function togglePassword(input, button) {
    var isVisible = input.type === 'password';
    var caret = input.selectionStart;

    input.type = isVisible ? 'text' : 'password';
    renderToggle(button, isVisible);
    input.focus();

    if (caret !== null) {
      input.setSelectionRange(caret, caret);
    }
  }

  rcmail.addEventListener('init', function () {
    var input = document.getElementById(PASSWORD_INPUT_ID);
    if (!input || document.querySelector('.avuz-password-toggle')) {
      return;
    }

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'avuz-password-toggle';
    button.setAttribute('aria-controls', PASSWORD_INPUT_ID);
    renderToggle(button, false);
    button.addEventListener('click', function () {
      togglePassword(input, button);
    });

    input.parentNode.classList.add('avuz-password-field');
    input.insertAdjacentElement('afterend', button);

    if (input.form) {
      input.form.addEventListener('submit', function () {
        input.type = 'password';
        renderToggle(button, false);
      });
    }
  });
})();
