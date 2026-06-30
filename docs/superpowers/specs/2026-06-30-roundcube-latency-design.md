# Roundcube Latency Optimization — Design (v3)

**Date**: 2026-06-30
**Branch**: avuz-customization
**Status**: diagnosis confirmed, design hardened after grilling — for review

## History

- **v1** OPcache + gzip + refresh_interval. Killed by staging evidence: cost is
  IMAP round-trips, not PHP/asset size.
- **v2** local imapproxy. Survived diagnosis but a grilling exposed three flaws:
  auth-caching mechanism, no implicit-SSL to Zoho, and an oversold latency claim.
- **v3 (this doc)** resolves all three. See "Grilling resolutions".

## Evidence (logs/imap.log, 2026-06-30)

```
14:23:46  Connecting to ssl://imap.zoho.com:993     ← fresh connection
14:23:47  CAPABILITY                                 +1s  TCP+TLS handshake
14:23:47  AUTHENTICATE PLAIN
14:23:48  SELECT INBOX OK                            +1s  RTT to Zoho
```
Next request opens a brand-new connection again; one request issued three
redundant SELECTs (Enviadas → INBOX → Enviadas), ~1s each. Per open ≈ 2–4s,
matching the 2.56s browser waterfall for a 7.5 kB response.

**Root cause:** PHP-FPM is stateless → Roundcube reconnects + re-authenticates to
Zoho every HTTP request. Brazil→Zoho-US RTT ~130ms; TLS + auth + selects stack
into seconds.

## Symptoms targeted

Slow list load, slow message open, slow new-mail detection. (SMTP/send out of
scope — no symptom, stays direct to Zoho.)

## Solution: local connection-caching IMAP proxy + TLS terminator

```
Roundcube ── 127.0.0.1:1143 ──► imapproxy ──► stunnel ──► ssl://imap.zoho.com:993
   (loopback plaintext, LOGIN)   (caches warm   (TLS, cert    (handshake+auth
                                  auth'd socket  verify)        paid once)
                                  + SELECT cache)
```

On a cache hit, per-request cost drops from "full TLS+AUTH to US (~1–2s)" to
"localhost socket reuse." First login per credential still pays full cost.

**Why stunnel is required:** imapproxy has no native implicit-SSL (993) support
(its docs: 993-only servers must use README.ssl, i.e. an external TLS wrapper).
Zoho is 993-only, advertises no STARTTLS. stunnel terminates TLS to Zoho;
imapproxy talks plaintext to stunnel on loopback.

**Why not nginx mail proxy / stunnel alone:** neither caches authenticated
backend sockets across client disconnects. Roundcube disconnects each request →
no reuse. Only imapproxy keeps the backend socket warm keyed by credentials.

## Grilling resolutions (the three v2 flaws)

### G1 — auth caching: force LOGIN
imapproxy caches `username + MD5(password)` from the **`LOGIN` command**. It does
not cache SASL `AUTHENTICATE PLAIN` (what Roundcube uses today) → no reuse as-is.
**Resolution:** `$config['imap_auth_type'] = 'LOGIN'`. Zoho's CAPABILITY shows no
`LOGINDISABLED`, so plaintext LOGIN is accepted (and travels only over loopback +
stunnel TLS, never cleartext on the wire). Verify on staging.

### G2 — implicit SSL 993: add stunnel
Confirmed imapproxy can't open a 993-implicit backend. stunnel bridges it (see
diagram). Preserves cert verification via stunnel `verifyChain` + CA bundle — no
security downgrade vs today's `verify_peer`.

### G3 — honest magnitude + SELECT cache
`<0.5s` (v2) was wrong. imapproxy removes TLS handshake (~1s) + auth, **not** the
SELECT/FETCH RTTs (physical distance). **But** `enable_select_cache` caches SELECT
responses, eliminating the redundant 3× SELECT churn.
**Revised estimate: ~2.5s → ~1.0–1.5s.** Residual is Brazil→US distance on the
real FETCH — only relocation removes that (see Escalation).

## Multi-provider handling

One imapproxy + stunnel pair per provider on its own loopback port:

| Provider | Roundcube imap → | imapproxy | stunnel → backend |
|---|---|---|---|
| zoho | 127.0.0.1:1143 | :1143 | ssl://imap.zoho.com:993 |
| digrepal | 127.0.0.1:1144 | :1144 | tls://mail.digrepal.com.br:143 (STARTTLS — may skip stunnel, imapproxy STARTTLS direct) |

Two providers = two pairs. Acceptable; revisit if provider count grows.

## Changes

1. **`Dockerfile.base`** — install `up-imapproxy` (confirm Alpine package; else
   compile from source) and `stunnel`. **Verify:** does the imapproxy build do
   STARTTLS natively for digrepal? If yes, digrepal may skip stunnel.
2. **`docker/imapproxy-zoho.conf` / `-digrepal.conf`** — `listen_address 127.0.0.1`,
   listen ports 1143/1144, backend = local stunnel port, `enable_select_cache yes`,
   `cache_size`, `cache_expiration_time ~300`, timeouts.
3. **`docker/stunnel.conf`** — client mode, connect `imap.zoho.com:993`,
   `verifyChain = yes`, `CAfile` = system bundle, listen on a loopback port.
4. **`docker/supervisor.conf`** — `[program:stunnel]`, `[program:imapproxy-zoho]`,
   `[program:imapproxy-digrepal]`, auto-restart with php-fpm/nginx.
5. **`config.inc.php`**
   - `imap_auth_type = 'LOGIN'`
   - `default_host` → `127.0.0.1`, `default_port` → `1143`
   - `avuz_providers['zoho']['imap']` → `127.0.0.1:1143`,
     `['digrepal']['imap']` → `127.0.0.1:1144`
   - relax `imap_conn_options` TLS verify for the loopback hop (TLS now at stunnel)
   - **password plugin:** `password_hosts` / `$_SESSION['storage_host']` becomes
     `127.0.0.1`. Update the host gate or the password change breaks. Verify.
   - `refresh_interval = 30` — now cheap (warm reuse), so safe.

## Escalation (if proxy gain insufficient)

If the proxy lands ~1.2s and the target is <0.5s, the only further lever is
**relocating the Roundcube container to a US region near Zoho**, cutting every
residual RTT (SELECT, FETCH). Container is movable; deferred by choice to test the
proxy first. Not part of this work unless proxy results disappoint.

## Out of scope / rejected

OPcache/gzip (proven marginal), IMAP IDLE push (unsupported), SMTP pooling
(no symptom).

## Security notes

- imapproxy + stunnel listen on loopback only — unreachable outside container.
- LOGIN credentials cross loopback in plaintext (same container) then go TLS to
  Zoho via stunnel with cert verification. No on-wire downgrade.
- Net Zoho connections **drop** (reuse vs new-per-request) — less throttling risk.
- imapproxy is old/lightly-maintained C holding creds in memory: accept for a
  loopback-only daemon; flag for periodic review.

## Verification (staging — linux image, no local run)

1. **Reuse:** re-enable `imap_debug`; Roundcube log shows `Connecting to
   127.0.0.1` (fast); no per-request `Connecting to ssl://imap.zoho.com` storm.
2. **Auth path:** confirm LOGIN (not AUTHENTICATE) and successful login.
3. **TLS:** stunnel log shows verified cert chain to Zoho.
4. **Latency:** devtools — message-open `get` drops ~2.5s → ~1.0–1.5s after warm-up.
5. **Multi-provider:** digrepal user routes via :1144, mail loads.
6. **Password change:** still works after storage_host → 127.0.0.1.
7. **New-mail:** within ~30s.

## Risk

Medium-high. Three new processes (stunnel + 2 imapproxy). Primary risks, each with
a verification step: (a) Alpine packaging/compile of imapproxy, (b) password host
gate, (c) imapproxy LOGIN-caching actually engaging, (d) stunnel cert verify
config. If imapproxy proves too fragile, fall straight to relocation (Escalation).
