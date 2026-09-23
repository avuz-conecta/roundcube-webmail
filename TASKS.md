# Tasks — login password toggle + read-receipt cleanup

Spec: `docs/superpowers/specs/2026-09-23-login-password-toggle-and-receipt-cleanup-design.md`

## 1. Login password toggle
- [x] Spec
- [x] Eye button JS (`plugins/nextcloud_sso/avuz-password-toggle.js`), login task only
- [x] Localized labels (pt_BR, en_US)
- [x] CSS in `avuz-overrides.css` (position, padding, focus ring, hide `::-ms-reveal`)
- [x] Verify in a browser (static harness: type, toggle, aria, caret, submit resets to password)

## 2. Read-receipt cleanup
- [x] Spec
- [x] Pure classifier: MDN detection from BODYSTRUCTURE + tests
- [x] Sweeper: watermark, batching, move-to-Trash + tests with fake mailbox
- [x] Schedule/throttle + folder selection + tests
- [x] IMAP adapter + wire-format tests (scripted STATUS/ESEARCH/FETCH over a socket pair)
- [x] Plugin wiring (hooks, prefs cursor, logging, guarded errors) + smoke load
- [x] Enable in `config/config.inc.php`, `Dockerfile` COPY, `customizations.json`, `tests/phpunit.xml`
- [x] Full Plugins suite: 228 tests, only the 2 pre-existing failures below

## Ship (needs Patrick)
- [ ] Commit (not done — waiting for go-ahead)
- [ ] Build + deploy staging (`./scripts/build-push.sh <ver> staging`)
- [ ] Staging check 1: login page shows eye, toggles, login still works
- [ ] Staging check 2: send a mail with read receipt to a staging user, open it, send receipt; within ~2–5 min it leaves Enviadas and shows in Lixeira; `avuz_receipt_cleanup` log line appears
- [ ] Staging check 3: Outlook/phone-sent receipt also swept on next webmail session
- [ ] Prod build/deploy (version tag + go-ahead)

## Found along the way
- [ ] Pre-existing test failures (not ours): `Enigma_EnigmaEngine::test_password_handler`, `Password_Plugin::test_hash_password` (PHP 8.5 bcrypt cost 12 vs expected 10)
- [x] `customizations.json` had no `avuz_calendar` entry and didn't mention `markasjunk` — added
- [x] `avuz_calendar` tests (26) existed but weren't in `tests/phpunit.xml` — wired, all pass
- [ ] Unknown whether Zoho exposes an Outbox folder over IMAP; config sweeps `Outbox`/`Caixa de saída` if present (read-only prod folder survey would confirm)
- [ ] Zoho webmail's own read receipts: format unverified; swept only if RFC 8098 `multipart/report`
- [ ] Cleanup only runs while webmail is open. True server-side cron needs stored IMAP creds or Zoho mailbox API — separate decision
- [ ] Large Sent backlog: 200 msgs/pass on each ~2 min refresh (e.g. 20k msgs ≈ 3.3 h of open webmail)
