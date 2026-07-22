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
