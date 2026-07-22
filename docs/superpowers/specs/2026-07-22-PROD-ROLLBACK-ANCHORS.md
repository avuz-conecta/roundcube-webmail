# Prod rollback anchors — captured 2026-07-22 before Wave 1 + 1.5 deploy

Live prod: Portainer endpoint 5 ("apps", 10.50.99.99), stack id 36, `avuz-mail-roundcube`.
(Endpoint 9 "peramix-us" has a stale stack definition, nothing running — ignore.)

Prod uses `:latest` tags, which the deploy OVERWRITES. To roll back, redeploy these exact digests:

- app:       registry.avuz.app/admin/avuz-roundcube@sha256:e2ed97a315cd5449b177d11581142cc9f678293b94ded1a1a9d87f0982e769f2
- imapproxy: registry.avuz.app/admin/avuz-imapproxy-sidecar@sha256:3a76f9593016760c73fa7f7b590e30fed69db40327738081d05490ae0c291809

Also being deployed as version tag `1.0.0` (build-push pushes :1.0.0 + :latest), so the NEW build
is preserved as :1.0.0 regardless of future :latest overwrites.

Redis change: prod stack redis command `--maxmemory 128mb` -> `512mb` (manual Portainer stack edit).
Rollback that = set it back to 128mb in the stack and redeploy.

---
## DEPLOYED 2026-07-22 (build 0ed140938, version 1.0.0)
Wave 1 + 1.5 live on prod (endpoint 5, stack 36). Verified:
- prefetch warmer 4 markers, run_guard present, TTL 5d
- Redis maxmemory 536870912 (512mb), sessions survived (1778), evicted_keys 0
- imapproxy cache_expiration_time 1800
- roundcube healthy, HTTP 200, 0 fatal/parse errors, perf instrumentation writing
Users get new prefetch.js on next page load via ?s=1784681400 (a mtime prod never served before,
so all browsers fetch fresh). No manual user action needed.
Rollback: redeploy app @sha256:e2ed97a3... (or the images are also tagged :1.0.0 for the NEW build).

---
## Update: FPM max_children 20 -> 30 (build 587cc143f, version 1.0.1)
App-image-only change (sed on www.conf), no base rebuild. Host is 7GB/4CPU; 30 keeps RAM headroom
(~80MB/worker), 40 would risk OOM. Redeploy pulls new :latest app image; Redis not recreated so
sessions survive.

Rollback: see "HOW TO ACTUALLY ROLL BACK" below. `./scripts/deploy.sh` alone does NOT roll back —
the stack references `:latest`, so a redeploy re-pulls whatever `:latest` currently points at.

---
## HOW TO ACTUALLY ROLL BACK (read this before you need it)

The prod stack file pins **no version tags**. Confirmed 2026-07-22 by reading stack 36's compose:

    image: registry.avuz.app/admin/avuz-roundcube:latest
    image: registry.avuz.app/admin/avuz-imapproxy-sidecar:latest
    image: registry.avuz.app/admin/avuz-password-broker:latest

So `deploy.sh` (which re-sends the stack file with `pullImage=true`) always pulls `:latest`.
Redeploying after a bad release just reinstalls the bad release. Version tags are a *record* of
what was built, not a rollback mechanism on their own.

Two ways to actually go back — pick one:

**A. Re-point `:latest` at the old image, then redeploy.** No stack edit, works with deploy.sh.

    docker pull registry.avuz.app/admin/avuz-roundcube:<GOOD_VERSION>
    docker tag  registry.avuz.app/admin/avuz-roundcube:<GOOD_VERSION> registry.avuz.app/admin/avuz-roundcube:latest
    docker push registry.avuz.app/admin/avuz-roundcube:latest
    PORTAINER_ENV_FILE=scripts/deploy.prod.env ./scripts/deploy.sh avuz-mail-roundcube

Leaves `:latest` lying about which build is newest — fix it on the next real release.

**B. Pin the stack.** Edit stack 36 in Portainer, change `:latest` to `:<GOOD_VERSION>`, redeploy.
Slower but honest, and it makes the running version visible in the stack file. Preferred if the
rollback is expected to stand for more than a few hours.

Either way Redis/Postgres are separate services in the stack and are not recreated, so sessions
survive the rollback.

---
## Update: FPM 40 + instrumentation + session fix + /healthz (version 1.0.3)

Built and pushed 2026-07-22, **staging-verified, prod deploy pending an explicit go-ahead**.

Digests (these are the rollback anchors for whatever comes next):
- app:       registry.avuz.app/admin/avuz-roundcube@sha256:a3ecde7400027d4d2cc3de22fd256187ce143b52b67be9ef489154c8bc23a475
- broker:    registry.avuz.app/admin/avuz-password-broker@sha256:55783c023bf9c47e8a04dc4e27b7c61bb88f3a270ef0ace3995a0041a9b1ed30
- imapproxy: registry.avuz.app/admin/avuz-imapproxy-sidecar@sha256:b2a83b8e0def4432d72d74a5c04160fddca8dca5bfe3ba342c8c52ea5e55924f

Rolling back to the currently-live build means going to **1.0.1**
(app @sha256:f8d05839825969b3228c04042ae524e92678d2ec1917e63618120f6eed1571ee) via method A or B above.

Contents:
1. `pm.max_children` 30 -> 40, warm pool (start 12, spare 8-20), `max_requests` 2000. The old
   "40 would risk OOM" note was based on ~80-100MB/worker; measured RSS on prod is ~28MB (mostly
   shared opcache), so 40 costs ~1.1GB against 3.6GB free. Host is 7.9GB/**20 CPU**, not 4 CPU.
2. Instrumentation: `pm.status_path=/fpm-status` (nginx: 127.0.0.1 only), FPM slowlog at >5s,
   and `docker/perf-prepend.php` logging per-request PHP duration keyed by session id to
   `logs/php-perf.log`. Disable without a rebuild by setting `AVUZ_PERF_LOG=0` in the stack.
3. `refresh_interval` 60 (Roundcube default) -> 120. `refresh` was the largest consumer of worker
   time: 5519 requests averaging 4.46s.
4. nginx: FPM keepalive, `fastcgi_buffers` 16x16k -> 64x16k, static assets out of the perf log.
5. **Session three-way merge** (`program/lib/Roundcube/rcube_session.php`) — fixes silent
   attachment loss on send. Covered by `tests/Framework/SessionRace.php`.
6. PHP-free `/healthz`; the healthcheck no longer starts a Roundcube session every 30s.

NOTE: `:1.0.2` exists in the registry (items 1-4 only, no session fix, no /healthz) and briefly
held `:latest`. It was never deployed. `1.0.3` supersedes it — do not roll back to 1.0.2.
