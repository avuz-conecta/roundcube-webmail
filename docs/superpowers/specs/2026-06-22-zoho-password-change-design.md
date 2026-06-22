# Forced Zoho Password Change in Roundcube — Design

**Date:** 2026-06-22
**Branch:** `feat/zoho-password-change`
**Status:** Approved, pending implementation plan

## Goal

New client mailboxes are created in Zoho with an admin-set temporary password. On the
user's first standalone Roundcube login (outside avuz conecta, in a browser), force them to
set a new password. Setting it changes the **real Zoho mailbox password** via the Zoho Mail
Admin API. After that the user logs into avuz conecta and (manually) aligns the same password
for SSO.

## Onboarding flow (context)

1. Deal closed → create mailbox in Zoho (manual, Admin Console) with a temp password →
   create the user in avuz conecta with that email.
2. First email access is **standalone** Roundcube (browser, no SSO token).
3. Temp password works for IMAP login.
4. User is **forced** to change the password before using mail. Change hits Zoho.
5. User then logs into avuz conecta; recommended to set the same password there so SSO works.
6. Subsequent email access is embedded in avuz conecta via SSO (`?nc_token=`).

## Constraints

- Roundcube cannot change a Zoho password over IMAP. Zoho is SaaS.
- Zoho exposes no public "change own password with current password" API. Only the
  **Zoho Mail Admin API** (org-scoped OAuth) can reset a password to a new value.
- User is already authenticated to Roundcube via IMAP with the current password → that
  re-auth covers current-password verification; the admin reset needs no old password.
- Zoho mailboxes are provisioned **manually** today → no existing OAuth creds. A one-time
  Zoho **Self Client** must be created (API Console → Self Client → scope
  `ZohoMail.organization.accounts.ALL` → refresh token). Done once for the whole org.
- Provider is selected **per login** via the SSO token's `provider` key
  (`nextcloud_sso::handleStartup` → `lookupProvider`). No/unknown key → Zoho defaults.
  Standalone login → no key → Zoho. The password feature must be **gated on provider** so
  non-Zoho users are unaffected.

## Components

Two separate concerns.

### 1. `plugins/password/drivers/zoho.php` — Zoho reset driver

- Implements Roundcube's `rcube_password` driver: `save($currpass, $newpass, $username)`.
- Flow: refresh token → access token (cached in Roundcube cache, ~1h TTL) → look up
  Zoho `accountId` by email → Zoho Admin API password update → return `PASSWORD_SUCCESS`
  or a mapped error code.
- Defense-in-depth: rejects if effective provider ≠ zoho.
- Reads config from env-backed `config.inc.php`.

### 2. `plugins/avuz_force_password/` — force-on-first-login plugin

- `user_create` hook → set user pref `avuz_force_pwchange = 1` (only if effective
  provider == zoho).
- `startup` hook → if flag set, effective provider == zoho, and the current task/action is
  not the password form (logout allowed) → redirect to Settings → Password with a notice;
  block other actions.
- On `PASSWORD_SUCCESS` → clear the flag (driver clears the pref directly, or via a
  post-success path verified in planning).

## Provider gating

**Effective provider** = `$_SESSION['avuz_provider'] ?? 'zoho'`.

- **Prerequisite:** stash the provider key in session at login. Today `handleStartup` stashes
  only `$_SESSION['avuz_smtp_host']`; add `$_SESSION['avuz_provider']` alongside it. Small,
  isolated change to `nextcloud_sso.php`.
- Force plugin (`user_create`, `startup`) runs only when effective provider == zoho.
- Password settings action exposed only for Zoho; non-Zoho hides the tab (exact mechanism a
  planning detail) and the driver hard-rejects non-Zoho.

### Effect on existing flows

| Flow | Behavior |
|------|----------|
| Standalone first login (Zoho) | Forced change → Zoho reset. New. |
| SSO, provider = zoho | Flag already cleared after first standalone change; password change available. Otherwise unchanged. |
| SSO, provider ≠ zoho | Fully unaffected — no force, no Zoho tab, existing IMAP/SMTP routing intact. |

Multi-provider host routing itself is untouched.

## Config & secrets

New env vars, injected in `config.inc.php` like the existing `ROUNDCUBE_*` secrets:

| Var | Purpose |
|-----|---------|
| `ZOHO_CLIENT_ID` | Self Client id |
| `ZOHO_CLIENT_SECRET` | Self Client secret |
| `ZOHO_REFRESH_TOKEN` | OAuth refresh token (scope `ZohoMail.organization.accounts.ALL`) |
| `ZOHO_ZOID` | Zoho organization id |

Config changes:

- Add `password` to `$config['plugins']`, add `avuz_force_password`.
- `$config['password_driver'] = 'zoho'`.
- Password strength rules (`password_minimum_length`, etc.) matching Zoho's policy to avoid
  API rejection.

## Data flow (first login)

```
temp pw → IMAP login OK → user_create sets flag (provider==zoho)
  → startup redirect → password form
  → submit → zoho driver: refresh→access token → accountId lookup → reset
  → PASSWORD_SUCCESS → session pw updated, flag cleared → normal mail
```

## Error handling

- Token/refresh failure, account-not-found, Zoho policy reject, network error → mapped to
  Roundcube password error codes with clear user messages.
- Log to Roundcube log; truncate secrets (follow existing SSO key-logging pattern).
- Zoho reset is immediate → update session password so the IMAP connection stays live.

## Testing

- Driver unit tests (mock HTTP): token exchange, account lookup, password update,
  error mapping, non-Zoho rejection.
- Force plugin tests: flag set on `user_create` (Zoho only), redirect when flag set,
  flag cleared on success, non-Zoho never flagged/redirected.
- Follow the existing `plugins/nextcloud_sso/tests/` style.

## Upgrade / maintenance

- `plugins/password/drivers/zoho.php` lives in the upstream `password/drivers/` dir →
  record it in `customizations.json` so it survives upstream rebases.
- `plugins/avuz_force_password/` is fully custom → also record in `customizations.json`.

## Open items to verify during planning

- Exact Zoho Admin API endpoint + payload for account lookup and password reset
  (against current Zoho Mail Admin API docs).
- Whether the `password` plugin fires a usable post-success hook, or the driver clears the
  force flag directly.
- Exact mechanism to hide the Password settings tab for non-Zoho providers.
