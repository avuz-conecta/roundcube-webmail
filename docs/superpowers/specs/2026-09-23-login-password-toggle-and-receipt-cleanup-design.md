# Login password toggle + read-receipt cleanup — design

Date: 2026-09-23

## 1. Login page: show/hide password

**Goal:** the user can see the password they type on the login page.

- Eye button inside the password pill (`#rcmloginpwd`), right-aligned.
- Click toggles `type=password` ↔ `type=text`. Icon swaps eye ↔ eye-off.
- a11y: real `<button type="button">`, `aria-label` "Mostrar senha"/"Ocultar senha"
  (localized via plugin texts), `aria-pressed`, `aria-controls`, visible focus ring.
  Focus returns to the input with the caret preserved.
- Submitting the form flips the field back to `password` first, so the browser's
  password manager still recognizes it.
- Edge's native reveal (`::-ms-reveal`) hidden, so there is one eye, not two.
- Lives in `plugins/nextcloud_sso` (the plugin that already styles the login page;
  `skins/avuz` CSS is not loaded on login). Script is only included on the login task.

## 2. Read-receipt (MDN) cleanup

**Problem:** a client's colleagues request read receipts on internal mail. Every
receipt sent back is saved by Zoho server-side into the sender's Sent folder
(Zoho auto-saves all SMTP submissions — see `applySentPolicy`), polluting it.
This happens whichever client sent the receipt (Roundcube, Outlook, phone).

**Goal:** move sent read receipts — and only those — to Trash. Never delete.

### What is a read receipt

RFC 8098 MDN: top-level `Content-Type: multipart/report; report-type=disposition-notification`.
Roundcube (`sendmdn.php`), Outlook and Thunderbird all produce this.
Match is strict: subtype `report` AND `report-type=disposition-notification`.
Delivery reports (`report-type=delivery-status`) and everything else are untouched.
Subject text ("Lida:", "Read:") is never used — too easy to hit real mail.

### Which folders

- The account's Sent folder (`sent_mbox`, XLIST-detected per user: Enviadas/Sent).
- Any outbox folder that exists: config `avuz_receipt_cleanup_extra_folders`,
  default `['Outbox', 'Caixa de saída']`. Missing folders are skipped.

### How it runs (the "job")

Same model as `avuz_filters`: in-session, over the user's own live IMAP session.
No daemon, no stored passwords.

- Triggers: `check_recent`, `refresh` (once per request). Not `login_after`: a backlog
  batch must not slow the login redirect, and SSO logins never fire it. First pass
  lands on the first refresh (≤ 2 min after opening webmail).
- Throttle: when caught up, next pass after 5 minutes. While a backlog remains,
  every refresh (~2 min) runs another batch.
- Per folder, per pass:
  1. `STATUS` → UIDVALIDITY, UIDNEXT. Caught up → stop (one round trip).
  2. `UID SEARCH RETURN (ALL) UID <last+1>:*` (ESEARCH) → next 200 UIDs.
  3. Fetch `Content-Type` headers; candidates = `multipart/report`.
  4. Fetch `BODYSTRUCTURE` of candidates; confirm `report-type=disposition-notification`.
  5. `UID MOVE` confirmed receipts to Trash (`trash_mbox`). Move fails → cursor stays,
     retried next pass.
  6. Advance watermark to the highest scanned UID.
- Watermark persisted in user prefs `avuz_receipt_cleanup` (`folder → uidvalidity, last_uid`).
  UIDVALIDITY change → rescan from 0.
- Cold start scans the whole folder from UID 1 in batches → existing receipts are
  cleaned too (to Trash, recoverable).
- Every failure is logged and swallowed: the cleanup must never break the UI.
- Observability: `avuz_receipt_cleanup` log line per folder that moved anything.

### Limitations

- Runs only while the user has webmail open. Receipts sent from Outlook/phone are
  swept on the next webmail session. A true server-side cron would need stored
  IMAP credentials or Zoho mailbox-level API access — out of scope.
- Receipts older than the Zoho Trash retention are purged by Zoho, as any trash.
