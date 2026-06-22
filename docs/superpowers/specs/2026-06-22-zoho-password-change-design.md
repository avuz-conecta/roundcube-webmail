# Forced Zoho Password Change in Roundcube — Design

**Date:** 2026-06-22
**Branch:** `feat/zoho-password-change`
**Status:** Approved after grilling; pending implementation plan

## Goal

New client mailboxes are created in Zoho with an admin-set temporary password. On the
user's first standalone Roundcube login (outside avuz conecta, in a browser), force them to
set a new password. Setting it changes the **real Zoho mailbox password**. After that the
user logs into avuz conecta and (manually) aligns the same password for SSO.

## Onboarding flow (context)

1. Deal closed → create mailbox in Zoho (manual, Admin Console) with a temp password →
   create the user in avuz conecta with that email.
2. First email access is **standalone** Roundcube (browser, no SSO token).
3. Temp password works for IMAP login.
4. User is **forced** to change the password before using mail. Change hits Zoho.
5. User then logs into avuz conecta; recommended to set the same password there so SSO works.
6. Subsequent email access is embedded in avuz conecta via SSO (`?nc_token=`).

## Key decisions (from design grilling)

- **Zoho org topology: single org.** All client mailboxes are users under one Zoho
  organization (custom domains). → one admin token, one broker.
- **The org-admin token must NOT live in Roundcube.** Roundcube is internet-facing and
  renders hostile email HTML; an RCE/LFI there would leak an org-wide key. The token lives
  in a separate **password-broker** container on the internal network.
- **Least privilege scope:** `ZohoMail.organization.accounts.READ` +
  `ZohoMail.organization.accounts.UPDATE` (not `.ALL`). Token can set passwords and read
  users, but cannot create/delete accounts.
- **Broker verifies the current password before any reset.** A popped Roundcube on the
  internal network must not be able to reset arbitrary mailboxes. Authorization = proof of
  the current password (IMAP login as that user) + shared-secret/mTLS between Roundcube and
  broker. Blast radius collapses to "accounts whose current password the caller already has."
- **On success: force logout → re-login.** Zoho may invalidate the live session on password
  change; re-login is clean, predictable, and proves the new password works.
- **Force-lock allow-list:** while a new user is pinned to the change-password screen, allow
  only (a) the change-password screen, (b) its save action, (c) logout. Bounce everything
  else.
- **Accepted limitation:** the force is Roundcube-only. The temp password keeps working over
  IMAP elsewhere until changed. No Zoho-side temp-password expiry in scope.

## Verified Zoho API

- Reset: `PUT https://mail.zoho.com/api/organization/{zoid}/accounts/{zuid}`
  - Headers: `Authorization: Zoho-oauthtoken <access>`, `Content-Type: application/json`
  - Body: `{"password": "<new>", "mode": "resetPassword"}`
  - Sets the password to the **exact value provided** (not random, not a force-reset flag).
- `zuid` (account id) ← GET all-org-users API, looked up by email.
- Scope: `ZohoMail.organization.accounts.UPDATE` for the reset, plus a read scope for the
  user lookup.
- Access token obtained from the org refresh token (Self Client created once in the Zoho
  API Console).

Sources:
- https://www.zoho.com/mail/help/api/put-reset-user-password.html
- https://www.zoho.com/mail/help/adminconsole/password-reset.html

## Architecture

```
Browser ──(HTTPS)──> Roundcube (internet-facing, no Zoho secret)
                         │  internal network only, shared secret / mTLS
                         ▼
                   password-broker container  (holds Zoho refresh token + ZOID)
                         │  1. verify current_pass via IMAP login as user
                         │  2. resolve zuid by email (GET org users)
                         │  3. PUT reset to new_pass
                         ▼
                   Zoho Mail Admin API (public internet)
```

### Components

**1. `password-broker` container (new)**
- Holds `ZOHO_CLIENT_ID/SECRET/REFRESH_TOKEN/ZOID`. Not internet-published; reachable only
  on the internal docker network.
- Single endpoint, e.g. `POST /reset` with `{ email, current_pass, new_pass }`,
  authenticated by a shared secret (header) and/or mTLS.
- Steps: verify `current_pass` (IMAP login to Zoho as `email`) → on success resolve `zuid`
  (cached) → obtain Zoho access token from refresh token (cached, ~1h) → `PUT` reset.
- Returns success / mapped error (bad current pass → 403; account not found; Zoho reject;
  upstream error). Logs with secrets truncated.
- Language/runtime: TBD in plan (small service; could share the Roundcube stack's tooling).

**2. `plugins/password/drivers/zoho_broker.php` — Roundcube password driver**
- Implements `rcube_password::save($currpass, $newpass, $username)`.
- Calls the broker over the internal network with the shared secret; passes the
  user-entered current password, new password, and email.
- Maps broker responses to Roundcube password codes (`PASSWORD_SUCCESS`,
  `PASSWORD_ERROR`, `PASSWORD_INCORRECT_CURRENT`, etc.).
- Rejects if effective provider ≠ zoho (defense in depth).
- The Roundcube password form **must require the current password** (do not pull silently
  from session — a hijacked session would bypass). Keep the plugin's default current-pass
  field on.

**3. `plugins/avuz_force_password/` — force-on-first-login plugin**
- `user_create` hook → set user pref `avuz_force_pwchange = 1` (only if effective
  provider == zoho).
- `startup` hook → if flag set and effective provider == zoho, and the request is not in the
  allow-list (change-password screen, its save action, logout, the static assets that screen
  needs) → redirect to the change-password screen with a notice; bounce everything else.
- On `PASSWORD_SUCCESS` → clear the flag, then force logout → user re-logs in with the new
  password. Self-healing: if flag-clear fails, the next login just re-shows the form.

## Provider gating

**Effective provider** = `$_SESSION['avuz_provider'] ?? 'zoho'`.

- **Prerequisite:** stash the provider key in session at login. Today
  `nextcloud_sso::handleStartup` stashes only `$_SESSION['avuz_smtp_host']`; add
  `$_SESSION['avuz_provider']` alongside it. Small, isolated change.
- Force plugin (`user_create`, `startup`) runs only when effective provider == zoho.
- Password settings action exposed only for Zoho; non-Zoho hides the tab (exact mechanism a
  planning detail) and the driver hard-rejects non-Zoho.

### Effect on existing flows

| Flow | Behavior |
|------|----------|
| Standalone first login (Zoho) | Forced change → broker → Zoho reset → logout/re-login. New. |
| SSO, provider = zoho | Flag already cleared after first standalone change; password change available. Otherwise unchanged. |
| SSO, provider ≠ zoho | Fully unaffected — no force, no Zoho tab, existing IMAP/SMTP routing intact. |

Multi-provider host routing itself is untouched.

## Config & secrets

Roundcube container (no org secret):

- Add `password` and `avuz_force_password` to `$config['plugins']`.
- `$config['password_driver'] = 'zoho_broker'`.
- `$config['avuz_broker_url']` (internal) + `$config['avuz_broker_secret']` (env-backed).
- Password strength rules (`password_minimum_length`, etc.) matching Zoho's policy to avoid
  reset rejection.

password-broker container (env):

| Var | Purpose |
|-----|---------|
| `ZOHO_CLIENT_ID` | Self Client id |
| `ZOHO_CLIENT_SECRET` | Self Client secret |
| `ZOHO_REFRESH_TOKEN` | OAuth refresh token (scopes: accounts.READ + accounts.UPDATE) |
| `ZOHO_ZOID` | Zoho organization id |
| `BROKER_SHARED_SECRET` | Shared secret Roundcube presents to the broker |

## Data flow (first login)

```
temp pw → IMAP login OK → user_create sets flag (provider==zoho)
  → startup bounces all but change-pw screen → user enters current + new pass
  → driver → broker: verify current (IMAP) → resolve zuid → Zoho PUT reset
  → success → clear flag → force logout → user logs in with new pass → normal mail
```

## Error handling

- Wrong current password → broker 403 → `PASSWORD_INCORRECT_CURRENT`, stay on form.
- zuid not found, Zoho policy reject, broker unreachable, upstream error → mapped Roundcube
  codes with clear messages; user stays pinned to the form.
- Logs (broker + Roundcube) truncate secrets, following the existing SSO key-logging pattern.

## Testing

- Broker: current-password verify (accept/reject), zuid resolution, token refresh + cache,
  reset call, error mapping, auth/shared-secret enforcement. Mock IMAP + Zoho HTTP.
- Driver: maps broker responses to Roundcube codes; rejects non-Zoho; requires current pass.
- Force plugin: flag set on `user_create` (Zoho only); allow-list enforcement (only
  change-pw screen/save/logout pass, everything else bounces); flag cleared + logout on
  success; non-Zoho never flagged.
- Follow the existing `plugins/nextcloud_sso/tests/` style.

## Upgrade / maintenance

- `plugins/password/drivers/zoho_broker.php` lives in the upstream `password/drivers/` dir →
  record in `customizations.json` so it survives upstream rebases.
- `plugins/avuz_force_password/` and the `password-broker` container are fully custom →
  record in `customizations.json` and the build/compose setup.

## Open items to verify during planning

- Broker runtime/language and how it ships in the stack (compose service, image).
- Exact GET all-org-users endpoint + response shape for email→zuid lookup, and pagination
  for large orgs.
- Whether `password` plugin fires a usable post-success hook, or the driver/plugin clears
  the force flag and triggers logout directly.
- Exact mechanism to hide the Password settings tab for non-Zoho providers.
- mTLS vs shared-secret-only for Roundcube↔broker (start with shared secret on internal net).
