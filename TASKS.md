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
- [x] Commit (5952a4272, 1dfbf0291, b51c93227)
- [x] Build + deploy staging (stack avuz-mail-roundcube-2, image :staging, healthz 200, plugin + eye script served)
- [ ] Staging check 1: login page shows eye, toggles, login still works
- [ ] Staging check 2: send a mail with read receipt to a staging user, open it, send receipt; within ~2–5 min it leaves Enviadas and shows in Lixeira; `avuz_receipt_cleanup` log line appears
- [ ] Staging check 3: Outlook-sent receipt also swept on next webmail session
- [x] Staging checks passed (Patrick, 2026-09-23)
- [x] Prod images built + pushed as `:1.8.0` only (roundcube, password-broker, imapproxy-sidecar); `:latest` untouched; git tag `avuz-1.8.0` (local)
- [ ] **GO (waits for Patrick):** move `:latest` → 1.8.0 for the 3 images, redeploy prod stack 36 (endpoint 5), check healthz + login page + error log
- Rollback: re-point `:latest` to `1.7.0` and redeploy stack 36

## Found along the way
- [ ] Pre-existing test failures (not ours): `Enigma_EnigmaEngine::test_password_handler`, `Password_Plugin::test_hash_password` (PHP 8.5 bcrypt cost 12 vs expected 10)
- [x] `customizations.json` had no `avuz_calendar` entry and didn't mention `markasjunk` — added
- [x] `avuz_calendar` tests (26) existed but weren't in `tests/phpunit.xml` — wired, all pass
- [x] Outbox: Patrick's folder list (Configurações → Pastas) shows only Caixa de entrada, Rascunhos, Enviados, Spam, Lixeira — Zoho exposes no Outbox over IMAP. The client's "caixa de saída" = Enviados (covered). Extra-folder config kept as zero-cost insurance
- [x] Zoho webmail's own read receipts: out of scope (Patrick: only Outlook + Roundcube matter; both send RFC 8098)
- [ ] Cleanup only runs while webmail is open. True server-side cron needs stored IMAP creds or Zoho mailbox API — separate decision
- [ ] Large Sent backlog: 200 msgs/pass on each ~2 min refresh (e.g. 20k msgs ≈ 3.3 h of open webmail)
