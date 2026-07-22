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

**DEPLOYED to prod 2026-07-22 15:29 UTC and still live.** Verified: HTTP 200, sessions survived
(3246 -> 3247), evicted_keys 0, 0 PHP fatal/parse errors, 0 nginx errors.

Deploy by **stack id**, not name — `avuz-mail-roundcube` exists on both endpoint 5 (prod, id 36)
and endpoint 9 (stale, id 65), and deploy.sh refuses an ambiguous name:

    PORTAINER_ENV_FILE=scripts/deploy.prod.env ./scripts/deploy.sh -y 36

**`pm.max_children` is 40, container and image agree.** (It was live-edited to 30 during the outage
below, then put back to 40 the same way once the outage was traced to the link. Resolved — nothing
pending for the next deployer.)

**Changing FPM config without downtime.** A redeploy recreates the container and is real
unavailability; it is not needed for pool config. Edit the file and graceful-reload instead:

    sed -i 's/^pm.max_children = .*/pm.max_children = N/' /usr/local/etc/php-fpm.d/www.conf
    kill -USR2 <fpm-master-pid>      # master pid is 12 in this image

SIGUSR2 re-execs the master, so in-flight requests finish and workers are replaced cleanly.
Measured cost on prod: exactly one request at 0.206s (vs 0.05s warm), then baseline — the opcache
SHM is rebuilt, but it is shared across workers so the first compile warms it for all of them.
Staging showed the same shape (1.27s then 0.04-0.07s). What is NOT lost, and is what would actually
hurt: imapproxy's authenticated Zoho connections (separate container), sessions and prefetched
bodies (Redis), messages_cache (Postgres).

Caveat: an edit made this way lives only in the container. A redeploy restores the image's value —
so change the image too when the value is meant to stick.

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

---
## INCIDENT 2026-07-22 ~16:20-17:05 UTC — degraded internet link, NOT the deploy

Users reported the webmail "not loading". It was never down: HTTP 200 throughout, just slow enough
to be unusable (root request peaked at 41s).

**Cause: a bad internet link at the site, since removed by the infra team.** No code or config
change was responsible, and none was needed to fix it.

### Timeline (from logs/php-perf.log, `refresh`, excluding the two known-slow sessions)

    15:25   1.01s   before deploy
    15:30   1.02s   deploy of 1.0.3 lands (max_children 40)
    16:15   0.98s   still healthy — 45 min at 1.0s, vs a 4.5s baseline earlier that day
    16:20   7.25s   degradation begins
    16:50  11.44s   worst; container restart here changed nothing
    16:55   8.10s   bad link removed around here
    17:00   4.83s
    17:05   3.87s   outage over — but back to the ~4.5s baseline, NOT the 1.0s of 15:30-16:15

Note the last line was first read as "1.54s recovered" from a partial bucket (n=24) and corrected
once the bucket completed (n=74, 3.87s). Same trap as lesson 3 below, hit while writing lesson 3.
Never read a bucket before it closes.

Open question left by this: prod ran `refresh` at 1.0s for 45 minutes on 1.0.3 (max_children 40),
and sits at ~3.9s after recovery (max_children 30, live edit). Candidate explanations — post-restart
cold caches, time-of-day load, the worker-count difference, or residual link degradation — are not
yet separated. Worth resolving before drawing any conclusion about what 1.0.3 bought.

### What this rules out, with evidence

- **The deploy.** 1.0.3 ran at 1.0s for 45 minutes after landing — better than the 4.5s baseline.
  Degradation began 51 minutes later, at a sharp boundary.
- **Worker exhaustion.** Dropping `pm.max_children` 40 -> 30 did not help. The pool saturating was
  a symptom of slow upstream calls, not the cause.
- **Accumulated app state.** A full container restart (fresh FPM master, fresh workers, fresh
  opcache) changed nothing.
- **Load.** Request volume was flat (~100 per 5 min) and then FELL as users gave up. Same requests,
  each ~8x slower.
- **CPU/RAM.** Load 2.98 on 20 cores; 3.9GB free.
- **Zoho throttling.** Zero BYE/blocked/limit messages.

Positive signal: PHP itself stayed fast throughout (root request 0.014s, which does no IMAP work)
while IMAP-touching actions were 5-10x slow. The time was on the wire.

### Diagnostic lessons (worth more than the incident)

1. **Never diagnose from whole-window averages.** The first BEFORE/AFTER comparison split the day
   at the deploy — "before" was 11 hours including quiet overnight, "after" was one peak hour. It
   showed every action 2-12x slower and pointed straight at the deploy. It was an artifact of
   time-of-day. Bucket by 5 or 60 minutes and compare like with like.
2. **A saturated worker pool is a symptom.** Two hypotheses were formed and both were wrong
   (worker-count congestion collapse; the avuz_filters refresh hook). What settled it was
   per-5-minute, per-session data, not reasoning about mechanisms.
3. **An instantaneous reading right after a restart or reload proves nothing** — a cold pool with
   no traffic always looks fast. It misled twice. Wait for a full bucket under real traffic.
4. **Attribute latency per session.** `logs/php-perf.log` (session id + PHP-only duration) is what
   separated "two users are pathological" from "the system is slow", and it is the only reason the
   two 40s-refresh sessions are now visible at all.
