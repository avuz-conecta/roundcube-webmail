/**
 * Warn once per session when the user picks whole-message search.
 *
 * Zoho charges real server time for a body/TEXT search (69s measured for one
 * all-folder body search), which nothing on our side can reduce. Telling the
 * user up front is the honest alternative to a surprise wait.
 */
(function () {
  if (!window.rcmail) return;

  var WARNED_KEY = 'avuz_slow_search_warned';

  function alreadyWarned() {
    try { return sessionStorage.getItem(WARNED_KEY) === '1'; }
    catch (e) { return false; }
  }

  function rememberWarned() {
    try { sessionStorage.setItem(WARNED_KEY, '1'); }
    catch (e) { /* private mode: warn again next time, harmless */ }
  }

  rcmail.addEventListener('init', function () {
    // Roundcube fires this when a search is submitted. The scope/headers the
    // user chose are on the request, so read them there rather than poking at
    // DOM that differs between skins.
    // This handler must never affect the search it observes: it runs
    // synchronously inside command('search', ...) before the search fires,
    // so any thrown exception here would propagate out of command() and
    // block that search. The try/catch below makes that impossible - on
    // any failure we do nothing and return normally (never false, never
    // rethrow).
    rcmail.addEventListener('beforesearch', function () {
      try {
        if (alreadyWarned()) return;

        var headers = $('input[name="s_mods[]"]:checked').map(function () { return this.value; }).get();

        if (headers.indexOf('text') === -1 && headers.indexOf('body') === -1) return;

        rcmail.display_message(rcmail.get_label('slowsearchnotice', 'avuz_search_notice'), 'notice');
        rememberWarned();
      } catch (e) {
        // Swallow everything: this plugin may never break or affect search.
      }
    });
  });
})();
