# Roundcube Latency Optimization — Design (v2)

**Date**: 2026-06-30
**Branch**: avuz-customization
**Status**: approved diagnosis, design for review

## What changed from v1

v1 proposed OPcache + gzip + refresh_interval. **Staging evidence killed it.**
An `imap_debug` trace of a single message open proved the cost is per-request
IMAP **connect + TLS + AUTHENTICATE + SELECT** round-trips to Zoho-US, not PHP
compile time or asset size.

### Evidence (logs/imap.log, 2026-06-30)

```
14:23:46  Connecting to ssl://imap.zoho.com:993     ← fresh connection
14:23:47  CAPABILITY                                 +1s  TCP+TLS handshake
14:23:47  AUTHENTICATE PLAIN
14:23:48  SELECT INBOX OK                            +1s  RTT to Zoho
```
A *second* request seconds later opens a **brand-new** connection again, and one
request issued three redundant SELECTs (Enviadas → INBOX → Enviadas), ~1s each.

Per message open ≈ TLS (~1s) + auth + 1–3 SELECT (~1–3s) = **2–4s**, matching the
2.56s browser waterfall for a 7.5 kB response.

**Root cause:** PHP-FPM is stateless. Roundcube opens a new TCP+TLS connection
and re-authenticates to Zoho on *every* HTTP request. From Brazil to Zoho-US each
round trip is ~130ms and TLS + auth + selects stack into seconds.

## Symptoms targeted

- Slow message-list load
- Slow message open
- Slow new-mail detection

(Send/SMTP out of scope — symptoms don't include it; SMTP stays direct to Zoho.)

## Solution: local IMAP connection-caching proxy (up-imapproxy)

Insert `up-imapproxy` (SquirrelMail imapproxy) inside the container, between
Roundcube and Zoho. It keeps **pre-authenticated backend sockets warm** and
reuses them across Roundcube's per-request reconnects.

```
Roundcube ── 127.0.0.1:1143 (imapproxy, loopback plaintext)
                   │  cache of warm, already-authenticated TLS sockets
                   ▼
             ssl://imap.zoho.com:993   ← TLS + LOGIN paid once, reused
```

On a cache hit, per-request cost drops from "full TLS+AUTH to US (~1–2s)" to
"localhost socket reuse (~1ms)." First login per credential still pays full cost
(unavoidable); everything after reuses.

**Why not nginx mail proxy:** nginx opens one backend connection per *client*
connection and closes it when the client disconnects. Roundcube disconnects after
every request → backend closes → no reuse. imapproxy specifically keeps the
backend socket cached *after* client disconnect, keyed by user/pass. It is the
only option that solves this.

## Multi-provider handling

`config.inc.php` defines `avuz_providers` (zoho + digrepal). A single imapproxy
instance has one fixed `server_hostname`. Run **one imapproxy instance per
provider** on its own loopback port; point each provider's `imap` entry at it.

| Provider | imapproxy listen | backend |
|---|---|---|
| zoho | 127.0.0.1:1143 | ssl://imap.zoho.com:993 |
| digrepal | 127.0.0.1:1144 | tls://mail.digrepal.com.br:143 |

Two providers = two lightweight instances. Acceptable; revisit only if provider
count grows large.

## Changes

### 1. Base image — add imapproxy (`Dockerfile.base`)

- Install `up-imapproxy`. **Decision/risk:** confirm an Alpine package exists
  (`apk add imapproxy`). If not packaged, compile from source in the base image
  (small C program, OpenSSL already present). Resolve before implementation.
- imapproxy must be built/enabled **with TLS backend support** (it connects to
  Zoho over SSL).

### 2. imapproxy configs (`docker/imapproxy-zoho.conf`, `docker/imapproxy-digrepal.conf`)

Per instance, key settings:
- `listen_address 127.0.0.1`, `listen_port 1143` (1144 for digrepal)
- `server_hostname imap.zoho.com`, `server_port 993`
- backend TLS **on, with certificate verification** (preserve current
  `verify_peer` posture — do not downgrade security)
- `cache_size` ~ expected concurrent users
- `cache_expiration_time` ~300s (keep idle backend warm 5 min)
- sensible `connect_timeout` / `connect_retries`

### 3. Supervisor (`docker/supervisor.conf`)

Add a `[program:imapproxy-zoho]` and `[program:imapproxy-digrepal]` block so each
proxy starts and is auto-restarted alongside php-fpm and nginx.

### 4. Roundcube config (`config.inc.php`)

- Point IMAP at the local proxy:
  - `default_host` → `127.0.0.1`, `default_port` → `1143` (plaintext loopback)
  - `avuz_providers['zoho']['imap']` → `127.0.0.1:1143`
  - `avuz_providers['digrepal']['imap']` → `127.0.0.1:1144`
- Drop/relax `imap_conn_options` TLS verify for the loopback hop (TLS now
  terminates at imapproxy → Zoho, not Roundcube → proxy).
- Keep `password_hosts` matching logic intact — `$_SESSION['storage_host']`
  becomes `127.0.0.1`; **verify the password plugin's host gate still passes**
  (it currently expects `imap.zoho.com`). Likely needs updating.

### 5. refresh_interval (now low-cost) — `config.inc.php`

Polling was expensive because every poll reconnected. With warm reuse, polls are
cheap. Lower default `refresh_interval` to 30s for snappier new-mail detection —
now safe.

## Out of scope / rejected

- OPcache / gzip — proven marginal (ms vs seconds). Optionally revisit later as
  separate minor cleanup; not part of this work.
- IMAP IDLE push — Roundcube doesn't support server-push; polling stays.
- SMTP pooling — out of scope (no send-latency symptom).
- Fixing redundant SELECT churn — separate Roundcube behavior; reuse makes each
  SELECT loopback-fast, so deprioritized.

## Security notes

- imapproxy holds credentials in memory to keep sessions warm. It listens on
  loopback only — not reachable outside the container.
- Roundcube → imapproxy hop is loopback plaintext (same container). Acceptable;
  document it.
- Backend imapproxy → Zoho stays TLS with cert verification. No downgrade.
- Net effect on Zoho: **fewer** connections than today (reuse vs new-per-request)
  — reduces any throttling risk.

## Verification (on staging — image is linux, no local run)

1. **Connection reuse:** re-enable `imap_debug`. Roundcube log now shows
   `Connecting to 127.0.0.1` (fast); the Zoho TLS+AUTH no longer appears per
   request. Confirm no `Connecting to ssl://imap.zoho.com` storm.
2. **Latency:** browser devtools — message-open `get` request drops from ~2.5s
   toward <0.5s after warm-up.
3. **Multi-provider:** log in as a digrepal user; confirm it routes through
   127.0.0.1:1144 and mail loads.
4. **Password change:** confirm the password plugin still works after the
   `storage_host` change (host gate).
5. **New-mail:** appears within ~30s.

## Risk

Medium (was Low). New moving part (imapproxy process + per-provider instances).
Main risks: (a) Alpine packaging/compile of imapproxy, (b) password-plugin host
gate breaking on the `127.0.0.1` storage_host, (c) backend TLS verification
config in imapproxy. All have explicit verification steps above.
