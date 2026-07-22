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
sessions survive. Rollback to pre-FPM-change = redeploy :1.0.0 (Wave 1+1.5 with max_children 20).
