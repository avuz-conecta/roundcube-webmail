# password-broker

Changes a user's Zoho Mail password for Avuz Conecta tenants. Roundcube's `zoho_broker` password driver and webmail-avuz call it. It runs on the internal network only (`http://broker:9000`). Every request needs the `X-Broker-Secret` header (`BROKER_SHARED_SECRET`).

## Endpoints

### `POST /reset`

Body: `{ "email": string, "currentPass": string, "newPass": string }` (camelCase).

1. Resolve the tenant from the email domain (`ZOHO_TENANTS`).
2. Check `currentPass` over IMAP.
3. Find the Zoho account (zuid, accountId) in the tenant's org.
4. Reset the password through the Zoho Mail Admin API. Zoho must answer HTTP 2xx **and** JSON `status.code === 200`.
5. Confirm: log in over IMAP with `newPass`. Up to 3 attempts, waiting 1 s, 2 s and 4 s before each (Zoho may take a moment to propagate).

It returns `200 {ok:true}` only after step 5 succeeds. It never rolls back.

### `GET /is-tenant?email=...`

Returns `{ ok: true, tenant: boolean, forcePasswordChange: boolean }`. `forcePasswordChange` is local tenant config for Roundcube's first-login screen. It is not sent to Zoho.

## `/reset` status table

| Status | `error` | Meaning |
|--------|---------|---------|
| 200 | — | Zoho confirmed and the new password opens IMAP |
| 400 | `bad request` | Body missing or not camelCase |
| 401 | `unauthorized` | Wrong or missing `X-Broker-Secret` |
| 403 | `current password incorrect` | IMAP server answered NO to the current password |
| 404 | `account not found` | No Zoho account with that email in the tenant's org |
| 422 | `unknown tenant domain` | Domain not in `ZOHO_TENANTS` |
| 502 | `zoho rejected reset` | Zoho returned non-2xx, an empty/non-JSON body, no `status`, or `status.code` ≠ 200 |
| 502 | `reset not confirmed` | Zoho said success, but the new password never opened IMAP (rejected or connection error on every attempt). Password state is unknown |
| 502 | `upstream error` | Anything else: IMAP drop/timeout while checking the current password, token refresh failure, network error, Zoho account lookup failure |

Callers map 502 to a connection/server error. No new statuses were added.

## Zoho request

Docs: https://www.zoho.com/mail/help/api/put-reset-user-password.html (response codes: https://www.zoho.com/mail/help/api/response-codes.html).

The doc shows `{zuid}` in the path. The broker sends `{accountId}` in the path and `zuid` in the body: that is what works in prod for every existing tenant (grupovidalar.com.br, austerengenharia.com.br, raiven.com.br). Tests pin this URL per prod tenant.

```http
PUT https://mail.zoho.com/api/organization/{zoid}/accounts/{accountId}
Authorization: Zoho-oauthtoken <redacted>
Content-Type: application/json

{"zuid":<zuid>,"password":"<redacted>","mode":"resetPassword","oneTimePassword":false,"resetAuthToken":true}
```

- `oneTimePassword: false`: the new password is permanent. Zoho's default (`true`) makes a temporary password that IMAP may refuse.
- `resetAuthToken: true`: Zoho signs out the user's other sessions. This matches the webmail note that other sessions end after a password change.
- The OAuth scope needs `ZohoMail.organization.accounts.ALL` or `.UPDATE`.

## IMAP check: rejection vs drop

imapflow sets `authenticationFailed = true` on any failed LOGIN/AUTHENTICATE, even when the socket closes mid-command (`lib/commands/login.js`, `lib/commands/authenticate.js`). Only a tagged server reply sets `responseStatus` (`lib/imap-flow.js`, NO/BAD branch) and `serverResponseCode` (e.g. `AUTHENTICATIONFAILED`).

The broker treats a password as wrong only when `authenticationFailed === true` and (`responseStatus === "NO"` or `serverResponseCode === "AUTHENTICATIONFAILED"`). Everything else throws, so a drop becomes 502, never 403.

## Logging

Each `/reset` writes exactly one JSON line: `console.log` on 200, `console.error` otherwise.

```json
{"event":"broker.reset","email":"user@tenant.com","zoid":"111","accountId":"5834474000000008002","zuid":928684132,"role":"member","primary":true,"zoho":{"httpStatus":200,"zohoCode":200,"description":"success","errorCode":null,"moreInfo":null},"zohoMs":412,"confirmAttempts":1,"confirmMs":1630,"confirmFailure":null,"failure":null,"status":200,"outcome":"ok","durationMs":3120}
```

Logged: email, zoid, accountId, zuid, the matched account's Zoho `role` and whether the email matched its primary address (`primary`; `false` = alias), Zoho HTTP status, whitelisted Zoho fields (`status.code`, `status.description`, `data.errorCode`, `data.moreInfo`, each cut to 200 chars), Zoho/confirm/total durations, confirm attempts and last confirm failure, error message, final status and outcome.

Never logged: passwords (any occurrence is replaced with `<redacted>`), the OAuth access token, refresh token, client secret, the `Authorization` header, `X-Broker-Secret`, request bodies, or raw Zoho response bodies.

## Config

| Env | Default | Purpose |
|-----|---------|---------|
| `BROKER_SHARED_SECRET` | required | Shared with Roundcube (`avuz_broker_secret`) and webmail |
| `ZOHO_TENANTS` | required | JSON `{ "<domain>": { clientId, clientSecret, refreshToken, zoid, forcePasswordChange? } }` |
| `ZOHO_IMAP_HOST` | `imap.zoho.com` | IMAP host for both password checks |
| `ZOHO_IMAP_PORT` | `993` | IMAP port (TLS) |
| `PORT` | `9000` | Listen port |

## Develop

```bash
npm test          # vitest
npx tsc --noEmit  # type-check
```
