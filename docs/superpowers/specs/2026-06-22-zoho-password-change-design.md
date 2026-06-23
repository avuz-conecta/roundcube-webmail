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

- **Zoho org topology: one org per client.** Each client is a separate Zoho organization
  (own ZOID, own admin). Avuz is admin in each → can mint a per-org Self Client
  (clientId/secret/refresh token) per client.
- **Broker resolves Zoho creds by email domain.** It holds a static
  `domain → {clientId, clientSecret, refreshToken, zoid}` map. Few clients, slow growth →
  static map (new client = add entry + redeploy broker). The lookup sits behind one
  resolver function so it can later swap to a dynamic store without changing the flow.
- **Only token + zoid are per-domain.** IMAP current-password verification is unchanged:
  every Zoho org (custom domains included) uses `imap.zoho.com`.
- **Org-admin tokens must NOT live in Roundcube.** Roundcube is internet-facing and renders
  hostile email HTML; an RCE/LFI there would leak the keys. All tenant creds live in the
  separate **password-broker** container, not internet-published. The broker now holds every
  client's org token → bigger crown jewel, but still isolated from Roundcube and still gated
  per-reset by current-password verification.
- **Least privilege scope:** `ZohoMail.organization.accounts.READ` +
  `ZohoMail.organization.accounts.UPDATE` (not `.ALL`).
- **Broker verifies the current password before any reset** (IMAP login as that user) +
  shared secret between Roundcube and broker. Blast radius collapses to "accounts whose
  current password the caller already has."
- **Reuse Roundcube's native `password` plugin.** It already implements forced-first-login,
  the hard-lock redirect, current-password confirmation, and strength checks. The only
  custom Roundcube code is a password **driver**. No custom force/gate plugin.
- **Provider gating via native `password_hosts`.** The plugin is active only for sessions
  whose `$_SESSION['storage_host']` matches the Zoho IMAP host. Non-Zoho sessions get no
  tab, no force, no driver. No session-provider stash needed.
- **On success: native in-place session update.** The plugin updates the session password
  and stays logged in; the next request reconnects with the new password. No forced logout.
- **Broker runtime: Node + TypeScript.** Small Alpine image; `fetch` for Zoho, `imapflow`
  for current-password verification.
- **Accepted limitation:** the force is Roundcube-only. The temp password keeps working over
  IMAP elsewhere until changed. No Zoho-side temp-password expiry in scope.

## Verified Zoho API

- Reset: `PUT https://mail.zoho.com/api/organization/{zoid}/accounts/{zuid}`
  - Headers: `Authorization: Zoho-oauthtoken <access>`, `Content-Type: application/json`
  - Body: `{"password": "<new>", "mode": "resetPassword"}`
  - Sets the password to the **exact value provided**.
- Account lookup: `GET https://mail.zoho.com/api/organization/{zoid}/accounts?start=&limit=`
  → array of users with `zuid`, `accountId`, `emailAddress`. Paginated (`start`/`limit`,
  default limit 10) → page through to find the matching email.
- Access token from the org refresh token (Self Client created once in the Zoho API Console).

Sources:
- https://www.zoho.com/mail/help/api/put-reset-user-password.html
- https://www.zoho.com/mail/help/api/get-org-users-details.html
- https://www.zoho.com/mail/help/api/getting-started-with-api.html

## Deployment topology

- **Roundcube is a single shared deployment** (one stack), multi-tenant via the SSO token's
  provider key. NOT per-tenant.
- **avuz-server / Nextcloud is per-tenant**, talks to the shared Roundcube via SSO only;
  never touches the broker.
- **One broker for the whole platform**, holding the per-client Zoho creds map. One
  container, resolves the right org by email domain per request.

## Architecture

```
Browser ──(HTTPS)──> Roundcube (internet-facing, no Zoho secret)
                         │  password plugin → zoho_broker driver
                         │  internal compose network only, shared secret
                         ▼
                   password-broker service (Node/TS; holds Zoho refresh token + ZOID; no public port)
                         │  1. verify current_pass via IMAP login as user
                         │  2. resolve zuid by email (GET org users, paged)
                         │  3. PUT reset to new_pass
                         ▼
                   Zoho Mail Admin API (public internet)
```

### Components

**1. `services/password-broker/` — Node/TS service (new, subdirectory in this repo)**
- Code lives in `services/password-broker/` (a folder in this repo, not a submodule) with
  its own Dockerfile. Built into its own image, run as its own compose service alongside
  `roundcube` and `redis`. The container split is the security boundary; source stays in one
  repo.
- Holds `ZOHO_TENANTS` (JSON map `domain → {clientId, clientSecret, refreshToken, zoid}`) +
  `BROKER_SHARED_SECRET` + `ZOHO_IMAP_HOST/PORT`. **No `ports:` mapping** — reachable only on
  the internal compose network (`broker:9000`).
- Single endpoint `POST /reset` with `{ email, current_pass, new_pass }`, header
  `X-Broker-Secret: <BROKER_SHARED_SECRET>`.
- Steps: check shared secret → resolve tenant by email domain (unknown domain → `422`) →
  verify `current_pass` via IMAP login to Zoho as `email` → obtain that org's access token
  from its refresh token (cached per domain, ~1h) → resolve `zuid` by paging that org's
  users → `PUT` reset to `new_pass`.
- Returns `200 {ok:true}` / mapped error: `401` bad shared secret, `422` unknown tenant
  domain, `403` wrong current pass, `404` account not found, `502` Zoho upstream error. Logs
  with secrets truncated.

**2. `plugins/password/drivers/zoho_broker.php` — Roundcube password driver (new)**
- Implements `rcube_password::save($currpass, $newpass, $username)`.
- POSTs `{ email: $username, current_pass: $currpass, new_pass: $newpass }` to the broker
  with the shared-secret header, reading `avuz_broker_url` + `avuz_broker_secret` from config.
- Maps broker responses to Roundcube codes: `200`→`PASSWORD_SUCCESS`,
  `403`→`PASSWORD_ERROR` (wrong current pass message), other→`PASSWORD_CONNECT_ERROR` /
  `PASSWORD_ERROR`.
- No org secret in Roundcube; the driver only knows the broker URL + shared secret.

### No custom plugins

Forced-first-login, the hard-lock redirect, the current-password field, strength checks, and
provider gating are all native `password` plugin behavior driven by config (below). The only
new Roundcube file is the driver.

## Provider gating (native)

- `password_hosts = [<zoho imap host>]`. The plugin's `check_host_login_exceptions()` gates
  the tab, the force redirect, and the driver to sessions whose `$_SESSION['storage_host']`
  equals the Zoho host. Non-Zoho sessions are untouched.
- **Verify during implementation:** the exact value Roundcube stores in
  `$_SESSION['storage_host']` for a Zoho login (with/without `ssl://` scheme) so
  `password_hosts` matches it.

### Effect on existing flows

| Flow | Behavior |
|------|----------|
| Standalone first login (Zoho) | `password_hosts` matches → forced change → driver → broker → Zoho reset → in-place session update. New. |
| SSO, provider = zoho | Same host → password change available; flag already cleared after first change. Otherwise unchanged. |
| SSO, provider ≠ zoho | `storage_host` ≠ Zoho host → plugin inactive: no tab, no force, no driver. Existing IMAP/SMTP routing intact. |

Multi-provider host routing itself is untouched.

## Config & secrets

Roundcube container (`config/config.inc.php`, no org secret):

- Add `password` to `$config['plugins']`.
- `$config['password_driver'] = 'zoho_broker';`
- `$config['password_force_new_user'] = true;` (force change on first login + hard-lock)
- `$config['password_confirm_current'] = true;` (require current password)
- `$config['password_hosts'] = ['<zoho imap host>'];` (provider gating)
- `$config['password_strength_driver'] = 'zxcvbn';` + `password_minimum_length`,
  `password_minimum_score` matching Zoho's policy to avoid reset rejection.
- `$config['avuz_broker_url']` (e.g. `http://broker:9000`) — from env `AVUZ_BROKER_URL`.
- `$config['avuz_broker_secret']` — from env `AVUZ_BROKER_SECRET`.

password-broker container (env):

| Var | Purpose |
|-----|---------|
| `ZOHO_TENANTS` | JSON map `domain → {clientId, clientSecret, refreshToken, zoid}` (refresh-token scopes: accounts.READ + accounts.UPDATE), one entry per client org |
| `BROKER_SHARED_SECRET` | Shared secret Roundcube must present |
| `ZOHO_IMAP_HOST` | IMAP host for current-password verify (default `imap.zoho.com`) |
| `ZOHO_IMAP_PORT` | IMAP port (default `993`) |
| `PORT` | Listen port (default 9000) |

`ZOHO_TENANTS` example:
```json
{"client-a.com":{"clientId":"...","clientSecret":"...","refreshToken":"...","zoid":"111"},
 "client-b.com":{"clientId":"...","clientSecret":"...","refreshToken":"...","zoid":"222"}}
```

## Compose (broker as a third service)

Add to the existing stack (`roundcube`, `redis`). Sketch:

```yaml
services:
  roundcube:
    # ...existing...
    environment:
      # ...existing...
      - AVUZ_BROKER_URL=http://broker:9000
      - AVUZ_BROKER_SECRET=$AVUZ_BROKER_SECRET

  broker:
    image: registry.avuz.app/admin/avuz-password-broker:staging
    build: ./services/password-broker
    restart: unless-stopped
    # NO ports: — internal compose network only
    environment:
      - ZOHO_TENANTS=$ZOHO_TENANTS
      - BROKER_SHARED_SECRET=$AVUZ_BROKER_SECRET
      - ZOHO_IMAP_HOST=imap.zoho.com
      - ZOHO_IMAP_PORT=993
      - PORT=9000
```

`build-push.sh` builds/pushes both images (Roundcube + broker).

## Data flow (first login)

```
temp pw → IMAP login OK (storage_host = zoho) → user_create sets newuserpassword pref
  → native init redirect bounces all but plugin.password → user enters current + new pass
  → zoho_broker driver → broker: shared-secret → resolve tenant by email domain
    → verify current (IMAP) → that org's token → resolve zuid → Zoho PUT reset
  → 200 → PASSWORD_SUCCESS → native clears flag + in-place session pw update → normal mail
```

## Error handling

- Wrong current password → broker 403 → driver `PASSWORD_ERROR` with "current password
  incorrect" → stay on form.
- zuid not found / Zoho reject / broker unreachable → mapped Roundcube codes, clear messages,
  user stays on the form.
- Logs (broker + Roundcube) truncate secrets, following the existing SSO key-logging pattern.

## Testing

- **Broker (Node/TS):** shared-secret enforcement, current-password verify (accept/reject),
  zuid resolution incl. pagination + not-found, token refresh + cache, reset call, error
  mapping. Mock IMAP + Zoho HTTP.
- **Driver (PHP):** maps broker HTTP responses to Roundcube codes; sends correct payload +
  header. Follow `plugins/nextcloud_sso/tests/` style.
- **Gating (manual/integration):** confirm `password_hosts` activates the plugin only for
  the Zoho storage_host.

## Upgrade / maintenance

- `plugins/password/drivers/zoho_broker.php` lives in the upstream `password/drivers/` dir →
  record in `customizations.json` so it survives upstream rebases.
- `services/password-broker/` is a fully-custom subdirectory + compose service → record in
  `customizations.json` and the build/compose setup. Upstream rebases don't touch it.
- Password-plugin config lives in `config/config.inc.php` (already tracked) → note in
  `customizations.json`.

## Per-client onboarding prerequisite

IMAP access must be enabled in each client's Zoho org (org-level "enable IMAP for all users")
so a fresh mailbox can IMAP-login with the admin-set temp password before any web login. The
forced-change flow and the broker's current-password verification both depend on it. Verified
in plan Task 0 before any code.

## Open items to verify during planning/implementation

- Exact `$_SESSION['storage_host']` value for a Zoho login → set `password_hosts` to match.
- Zoho `GET org users` pagination details for the email→zuid lookup at org scale.
- Broker Dockerfile + `build-push.sh` changes to build/push both images.
