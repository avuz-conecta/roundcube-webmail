window.rcmail && rcmail.addEventListener('init', function () {
  var inv = rcmail.env.avuz_calendar_invite;
  if (!inv) return;
  document.querySelectorAll('#avuz-invite-card .btn-avuz-rsvp').forEach(function (b) {
    b.addEventListener('click', function () {
      var status = document.querySelector('#avuz-invite-card .avuz-invite-status');
      status.hidden = false; status.textContent = rcmail.get_label('avuz_calendar.sending');
      rcmail.http_post('plugin.avuz_calendar_rsvp', {
        _uid: inv.msg_uid, _mbox: inv.mbox, _part: inv.part, _partstat: b.dataset.partstat
      }, rcmail.set_busy(true, 'loading'));
    });
  });
  rcmail.addEventListener('plugin.avuz_calendar_rsvp_done', function (r) {
    var status = document.querySelector('#avuz-invite-card .avuz-invite-status');
    if (status) status.textContent = r.message;
  });
});
