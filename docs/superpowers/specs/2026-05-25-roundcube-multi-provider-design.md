# Multi-Provider Email Routing — Design

**Date**: 2026-05-25
**Status**: Approved (design), pending implementation
**Repos**: `roundcube-webmail` (this) + `avuz-server` (`apps/roundcube/`)

## Problem

Roundcube is hardwired to Zoho. Every user connects to `imap.zoho.com` /
`smtp.zoho.com` via static `config.inc.php`. A new client (digrepal) uses a
different mail server. We need one Roundcube image to route each user to their
provider, decided at login time.

## Decision summary

- Provider identified by a **named key** (`zoho`, `digrepal`), not raw hosts.
- Key travels in the **existing signed SSO token** (`?nc_token=`), as a new
  **optional** payload field. HMAC already protects it from tampering.
- Roundcube owns the **key → host map**. NC never sends hosts — tight trust
  boundary; NC cannot point user credentials at an arbitrary server.
- Unknown or missing key → **fall back to Zoho default**. Old tokens (no
  `provider` field) keep working unchanged.

## Provider keys (contract — both repos must agree)

| Key | IMAP | SMTP |
|-----|------|------|
| `zoho` | `ssl://imap.zoho.com:993` | `tls://smtp.zoho.com:587` |
| `digrepal` | `tls://mail.digrepal.com.br:143` (STARTTLS) | `tls://mail.digrepal.com.br:587` (STARTTLS) |

## Data flow

```
NC resolveProvider(userId): per-user setting -> app default -> "zoho"
        │
        ▼
NC buildToken: payload { email, enc_pass, exp, provider }  (provider optional)
        │  HMAC-signed, ?nc_token=
        ▼
Roundcube nextcloud_sso.handleStartup
        │  validateToken reads optional provider
        ▼
resolveProvider(key) -> avuz_providers map entry | Zoho default (unknown/missing)
        │
        ├─ IMAP: pass host URI as 3rd arg to $rcmail->login()
        │        → Roundcube persists storage_host/port in session,
        │          reconnects there automatically every request
        │
        └─ SMTP: stash server+port in $_SESSION
                 → smtp_connect hook (every request) overrides smtp_server/port
```

### Why SMTP needs a hook but IMAP does not

IMAP host passed to `login()` is saved in the session (`storage_host`,
`storage_port`) and reused on every subsequent request automatically. SMTP is
**not** session-persisted — `smtp_server` is re-read from `config.inc.php` on
each send. Without a per-request `smtp_connect` hook, login would succeed on the
right IMAP server while sending silently reverts to Zoho. This is the easy bug
to ship; the hook prevents it.

## Roundcube changes (this repo)

### `config/config.inc.php`
Add provider map. Keep existing Zoho `default_host`/`smtp_server` keys as the
untouched fallback path.
```php
$config['avuz_providers'] = [
    'zoho'     => ['imap' => 'ssl://imap.zoho.com:993',
                   'smtp' => 'tls://smtp.zoho.com:587'],
    'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143',
                   'smtp' => 'tls://mail.digrepal.com.br:587'],
];
```

### `plugins/nextcloud_sso/nextcloud_sso.php`
- `init`: register `smtp_connect` hook in addition to `startup`.
- `validateToken`: read optional `provider` from payload; include in returned
  array (absent → key omitted, no failure).
- New `resolveProvider(?string $key): array` — returns map entry; unknown
  key-but-present → `raise_error` (log) then Zoho; missing key → Zoho silently.
- `handleStartup` (token-valid branch): resolve provider, pass IMAP URI as 3rd
  arg to `$rcmail->login()`, stash SMTP server+port in `$_SESSION`.
- New `applySmtp(array $args): array` — `smtp_connect` hook; if session has
  stashed SMTP, set `smtp_server`/`smtp_port` from it.

## avuz-server changes (`apps/roundcube/lib/Service/CredentialService.php`)

- `resolveProvider(string $userId): string` — per-user `provider` setting →
  app `default_provider` → `'zoho'`. Mirrors existing `resolveEmail` (line 72).
- `buildToken` — add `'provider' => $provider` to payload.
- `buildIframeUrl` — resolve provider, pass to `buildToken`.

### Provider assignment (no UI — via occ, same as `roundcube_url`)
```bash
occ config:app:set roundcube default_provider --value zoho
occ user:setting <uid> roundcube provider digrepal
```

## Behavior matrix

| Token | IMAP | SMTP |
|-------|------|------|
| `provider:"digrepal"` | mail.digrepal:143 | mail.digrepal:587 |
| `provider:"zoho"` | imap.zoho:993 | smtp.zoho:587 |
| no provider field (old token) | Zoho default | Zoho default |
| unknown key | Zoho + logged error | Zoho + logged error |

## Backward compatibility

Roundcube side ships first. Old NC tokens lack `provider` → Zoho default →
nothing breaks. NC flips per-client (`occ user:setting`) after both deployed.

## Testing

- `resolveProvider` (Roundcube): known key → entry; unknown → Zoho + error;
  missing → Zoho, no error.
- `resolveProvider` (NC): per-user wins over app default wins over `zoho`.
- Token round-trip: NC builds with `provider`, Roundcube reads same key.
- Manual: digrepal user logs in (IMAP 143 STARTTLS), sends mail (SMTP 587) —
  confirm send hits digrepal, not Zoho. Then a Zoho user, confirm unaffected.

## Out of scope

- Admin UI for provider assignment (occ only, for now).
- More than two providers (map scales, but only zoho + digrepal defined).
- Per-provider skin/branding.

## Git workflow

- **roundcube-webmail**: work on a new branch off `avuz-customization`.
- **avuz-server**: push `feat/talk-recording-chunked-upload`, merge into
  `avuz-customization`, then branch the provider work off `avuz-customization`.
  (Confirm before pushing/merging — outward actions.)
