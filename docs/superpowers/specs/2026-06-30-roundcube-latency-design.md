# Roundcube Latency Optimization — Design

**Date**: 2026-06-30
**Branch**: avuz-customization
**Status**: approved scope, pending implementation

## Context

A latency guide (Zoho IMAP/SMTP) was proposed. Most of it targets a custom
stateful IMAP client (Python imaplib): persistent IDLE sockets, SMTP connection
pooling, pipelining. Roundcube is PHP / PHP-FPM — stateless, one IMAP+SMTP
lifecycle per HTTP request. Those points do not apply:

| Guide point | Verdict |
|---|---|
| Regional server | imap.zoho.com = US; no closer DC for BR. Nothing to do. |
| Persistent IMAP IDLE | Impossible in PHP-FPM (connection dies each request). |
| SMTP connection pooling | Impossible — per-request lifecycle. |
| Port 587 STARTTLS | Already in place (`config.inc.php:18-19`). |
| Batch header fetch | Roundcube already fetches ranges internally. |
| SMTP pipelining | Handled by the library; no config knob. |

The real latency surface is elsewhere: PHP execution, asset transfer, IMAP
cache. Audit found three gaps and one tunable.

## Symptoms targeted

- Slow inbox / message-list load
- Slow message open
- Slow new-mail detection

(Send latency is out of scope — nothing actionable in a stateless PHP app.)

## Confirmed environment facts

- Redis cache active in staging/prod (`REDIS_HOST` set). `imap_cache` and
  `messages_cache_type` already resolve to redis (`config.inc.php:40-43`).
  No change needed here. **Out of scope.**
- redis PHP extension already in base image (`Dockerfile.base:12`).
- Static assets already long-cached (`nginx.conf:44-46`).

## Changes

### A. Enable OPcache (Dockerfile.base) — biggest server-side win

Without OPcache, PHP recompiles every script on every request. Roundcube is a
large PHP codebase; this tax hits every list load and message open.

- Add `opcache` to the `install-php-extensions` line (ensures it is enabled).
- New ini in `conf.d`:
  - `opcache.enable=1`
  - `opcache.memory_consumption=128`
  - `opcache.interned_strings_buffer=16`
  - `opcache.max_accelerated_files=20000`
  - `opcache.validate_timestamps=0` — images are immutable per deploy; code
    never changes inside a running container, so skip the per-request `stat()`.
    A new deploy = new image = fresh OPcache.
  - `realpath_cache_size=4096K`, `realpath_cache_ttl=600`
- No JIT — negligible gain for request/response web workloads, added risk.

Cost: one base-image rebuild (`./scripts/build-base.sh latest local`).

### B. nginx gzip (docker/nginx.conf) — first-load + render win

Elastic ships large JS/CSS. They are long-cached but transferred uncompressed on
first load and after cache eviction. Add gzip:

- `gzip on; gzip_vary on; gzip_comp_level 5; gzip_min_length 256;`
- `gzip_types` for css, javascript, json, svg, xml, plain text.
- Do **not** gzip woff2/png/jpg/ico — already compressed.

Cost: app-image rebuild only.

### C. refresh_interval (config.inc.php) — new-mail detection

Default poll is 60s. Lower the default to 30s for snappier new-mail discovery.

- `$config['refresh_interval'] = 30;`
- Trade-off: ~2x new-mail poll frequency = more IMAP connections. 30s is the
  safe floor; going lower is not worth the load. User can still override in
  Settings.

## Out of scope / explicitly rejected

- IMAP IDLE / persistent sockets — architecturally impossible here.
- SMTP pooling / pipelining — same.
- PHP JIT — no benefit for this workload.
- `skip_deleted` — leave default; Zoho handles it fine, marginal at best.
- Redis deploy changes — already set.

## Verification

Image cannot run locally (linux image; verify on staging per project memory).

1. **OPcache**: after deploy, `php -i | grep opcache.enable` inside container →
   `On`; check `opcache_get_status()` hit rate climbs after warm-up.
2. **gzip**: `curl -H 'Accept-Encoding: gzip' -I https://<staging>/program/js/app.js`
   → `Content-Encoding: gzip`.
3. **refresh_interval**: confirm new mail appears within ~30s in the UI.
4. Overall: compare inbox-load and message-open timing in browser devtools
   Network panel before/after on staging.

## Risk

Low. No IMAP/SMTP protocol changes, no skin/plugin changes. Worst case:
`validate_timestamps=0` would cache stale code — mitigated because each deploy
ships a fresh image with empty OPcache.
