# Roundcube IMAP Connection-Caching Proxy — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut per-request IMAP latency by inserting a local connection-caching proxy (up-imapproxy) + TLS terminator (stunnel) between Roundcube and Zoho, so the ~1s TLS+AUTH handshake is paid once and reused instead of every HTTP request.

**Architecture:** `Roundcube → imapproxy (loopback) → stunnel → ssl://imap.zoho.com:993`. imapproxy keeps warm authenticated backend sockets keyed by LOGIN credentials and caches SELECT responses; stunnel carries TLS to Zoho (imapproxy has no native implicit-993). One imapproxy instance per provider, bound to a distinct loopback IP so the existing host-based password gate keeps working.

**Tech Stack:** Alpine PHP 8.2 base image, up-imapproxy (compiled from source — not in Alpine repos), stunnel 5.76 (Alpine community), supervisor, Roundcube 1.6.14.

## Global Constraints

- Base image is `php:8.2-fpm-alpine`. imapproxy is **not packaged** for Alpine → compile from source in `Dockerfile.base`. stunnel IS packaged (`apk add stunnel`).
- **imapproxy source (pinned, exists):** Debian source package `up-imapproxy`, version `1.2.8~svn20171105`. Tarball:
  `https://deb.debian.org/debian/pool/main/u/up-imapproxy/up-imapproxy_1.2.8~svn20171105.orig.tar.bz2`
  (project's live home is Debian, not a GitHub tag.)
- **No local container run** — image is linux; behavior verification is on staging (per project memory). `docker build` runs locally; any `docker run` / connection / UI check is **staging-only** (marked per step).
- **Env-gated rollback:** all proxy routing is behind `IMAP_USE_PROXY`. Unset/`0` → Roundcube talks **direct** to `ssl://imap.zoho.com:993` (today's behavior). `1` → routes through the proxies. Default OFF — deploys are safe until the proxy is proven; revert is one env flip, no rebuild.
- **No SELECT cache.** `enable_select_cache` breaks new-mail visibility through imapproxy (Roundcube #4505). Must stay off — one of our target symptoms is new-mail detection.
- **Zoho connection-block guard:** Zoho temporarily blocks accounts on too many concurrent IMAP connections. imapproxy holds sockets warm, so keep `cache_expiration_time 60` (short) and watch for block errors on staging.
- Backend TLS to Zoho must keep certificate verification (no downgrade from current `verify_peer`).
- Loopback IP per provider: **zoho = 127.0.0.1**, **digrepal = 127.0.0.2**. `password_hosts` gates on `127.0.0.1` so only zoho users see the password form (proxy mode).
- SMTP/send is untouched — stays direct `tls://smtp.zoho.com:587`.
- Roundcube must use `imap_auth_type = 'LOGIN'` (proxy mode) — imapproxy caches the LOGIN command, not SASL AUTHENTICATE PLAIN.
- Git commits: no Claude co-author line (per user global config).

---

### Task 1: Compile imapproxy + install stunnel in the base image

**Files:**
- Modify: `Dockerfile.base:10-16` (extension/package installs)

**Interfaces:**
- Produces: binary `/usr/local/sbin/in.imapproxyd`, `stunnel` on PATH. Later tasks supply configs at `/etc/imapproxy-zoho.conf`, `/etc/imapproxy-digrepal.conf`, `/etc/stunnel/stunnel.conf`.

- [ ] **Step 1: Add stunnel + build toolchain and compile imapproxy**

Insert after the `apk add` block (`Dockerfile.base:16`):

```dockerfile
# stunnel: TLS terminator for Zoho 993 (imapproxy has no native implicit-SSL)
RUN apk add --no-cache stunnel

# up-imapproxy: not in Alpine repos — compile from pinned Debian source tarball
RUN apk add --no-cache --virtual .imapproxy-build \
      build-base autoconf automake openssl-dev bzip2 curl \
  && curl -fsSL "https://deb.debian.org/debian/pool/main/u/up-imapproxy/up-imapproxy_1.2.8~svn20171105.orig.tar.bz2" \
       -o /tmp/imapproxy.tar.bz2 \
  && mkdir -p /tmp/imapproxy && tar -xjf /tmp/imapproxy.tar.bz2 -C /tmp/imapproxy --strip-components=1 \
  && cd /tmp/imapproxy \
  && ( test -x ./configure || autoreconf -fi ) \
  && ./configure \
  && make \
  && cp src/in.imapproxyd /usr/local/sbin/in.imapproxyd \
  && cd / && rm -rf /tmp/imapproxy /tmp/imapproxy.tar.bz2 \
  && apk del .imapproxy-build
```

> Build note: the tarball URL is pinned to a version confirmed to exist in Debian's pool. Binary name `in.imapproxyd` and the `-f` config flag are confirmed. If `./configure` needs an explicit OpenSSL flag for this snapshot, add `--with-ssl`; otherwise SSL is auto-detected. Binary builds at `src/in.imapproxyd`.

- [ ] **Step 2: Build the base image (local, linux target)**

Run: `./scripts/build-base.sh latest local`
Expected: build completes; no compile errors from the imapproxy section.

- [ ] **Step 3: Verify binaries exist in the image**

Run:
```bash
docker run --rm --entrypoint sh avuz-roundcube-base:latest -c \
  'command -v stunnel && ls -l /usr/local/sbin/in.imapproxyd'
```
Expected: prints a stunnel path and the `in.imapproxyd` file listing.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile.base
git commit -m "build(base): add stunnel + compile up-imapproxy for IMAP connection caching"
```

---

### Task 2: stunnel config — TLS terminator for Zoho

**Files:**
- Create: `docker/stunnel.conf`

**Interfaces:**
- Produces: a plaintext loopback endpoint `127.0.0.1:9993` that tunnels TLS to `imap.zoho.com:993` with cert verification. Task 3 (imapproxy-zoho) connects to it.

- [ ] **Step 1: Write the stunnel client config**

Create `docker/stunnel.conf`:

```ini
foreground = yes
output = /dev/stdout
syslog = no
pid =

[imap-zoho]
client = yes
accept = 127.0.0.1:9993
connect = imap.zoho.com:993
verifyChain = yes
CAfile = /etc/ssl/certs/ca-certificates.crt
checkHost = imap.zoho.com
sslVersionMin = TLSv1.2
```

- [ ] **Step 2: Validate syntax against the built image**

Run:
```bash
docker run --rm -v "$PWD/docker/stunnel.conf:/etc/stunnel/stunnel.conf:ro" \
  avuz-roundcube-base:latest stunnel /etc/stunnel/stunnel.conf -test 2>&1 | head
```
Expected: no "syntax error" / config parse failure. (Connection isn't exercised here.)

- [ ] **Step 3: Commit**

```bash
git add docker/stunnel.conf
git commit -m "feat(proxy): stunnel TLS terminator for Zoho 993 with cert verification"
```

---

### Task 3: imapproxy configs (zoho + digrepal)

**Files:**
- Create: `docker/imapproxy-zoho.conf`
- Create: `docker/imapproxy-digrepal.conf`

**Interfaces:**
- Consumes: stunnel endpoint `127.0.0.1:9993` (Task 2).
- Produces: IMAP listeners `127.0.0.1:1143` (zoho) and `127.0.0.2:1143` (digrepal). Task 5 (Roundcube config) points providers at these.

- [ ] **Step 1: Write the zoho proxy config**

Create `docker/imapproxy-zoho.conf`:

```ini
server_hostname 127.0.0.1
server_port 9993
listen_address 127.0.0.1
listen_port 1143
cache_size 200
cache_expiration_time 60
enable_select_cache no
force_tls no
connect_retries 2
connect_timeout 10
chroot_directory ""
```

> `force_tls no` — TLS to Zoho happens in stunnel. `enable_select_cache no` — caching SELECT serves stale message counts and hides new mail through imapproxy (Roundcube #4505). `cache_expiration_time 60` — short hold so warm idle sockets don't pile up against Zoho's concurrent-connection block.

- [ ] **Step 2: Write the digrepal proxy config**

digrepal is STARTTLS on 143 → imapproxy negotiates TLS natively, no stunnel needed.

Create `docker/imapproxy-digrepal.conf`:

```ini
server_hostname mail.digrepal.com.br
server_port 143
listen_address 127.0.0.2
listen_port 1143
cache_size 200
cache_expiration_time 60
enable_select_cache no
force_tls yes
tls_verify_server yes
connect_retries 2
connect_timeout 10
chroot_directory ""
```

> `enable_select_cache no` (same new-mail correctness reason as zoho), `cache_expiration_time 60` (connection-block guard). Verify directive names against the sample `imapproxy.conf` shipped in the source tree; if `tls_verify_server` differs in spelling for this snapshot, match the sample — adjust names, not intent.

- [ ] **Step 3: Commit**

```bash
git add docker/imapproxy-zoho.conf docker/imapproxy-digrepal.conf
git commit -m "feat(proxy): per-provider imapproxy configs (loopback IP per provider, SELECT cache)"
```

---

### Task 4: Supervisor + Dockerfile wiring

**Files:**
- Modify: `docker/supervisor.conf:18` (append programs)
- Modify: `Dockerfile:38-41` (COPY the three configs into the image)

**Interfaces:**
- Consumes: configs from Tasks 2-3.
- Produces: three managed processes (stunnel, imapproxy-zoho, imapproxy-digrepal) running before Roundcube serves traffic.

- [ ] **Step 1: Add supervisor programs**

Append to `docker/supervisor.conf` (after line 18):

```ini
[program:stunnel]
command=stunnel /etc/stunnel/stunnel.conf
autostart=true
autorestart=true
stderr_logfile=/var/log/supervisor/stunnel.err.log
stdout_logfile=/var/log/supervisor/stunnel.out.log

[program:imapproxy-zoho]
command=/usr/local/sbin/in.imapproxyd -f /etc/imapproxy-zoho.conf
autostart=true
autorestart=true
stderr_logfile=/var/log/supervisor/imapproxy-zoho.err.log
stdout_logfile=/var/log/supervisor/imapproxy-zoho.out.log

[program:imapproxy-digrepal]
command=/usr/local/sbin/in.imapproxyd -f /etc/imapproxy-digrepal.conf
autostart=true
autorestart=true
stderr_logfile=/var/log/supervisor/imapproxy-digrepal.err.log
stdout_logfile=/var/log/supervisor/imapproxy-digrepal.out.log
```

> Confirm `in.imapproxyd`'s foreground flag is `-f` for v1.2.8 (check `in.imapproxyd --help`). If it daemonizes by default with a different flag, use that so supervisor can track it.

- [ ] **Step 2: COPY configs in the Dockerfile**

Add after `Dockerfile:39` (the supervisor COPY line):

```dockerfile
COPY docker/stunnel.conf /etc/stunnel/stunnel.conf
COPY docker/imapproxy-zoho.conf /etc/imapproxy-zoho.conf
COPY docker/imapproxy-digrepal.conf /etc/imapproxy-digrepal.conf
```

- [ ] **Step 3: Build the app image**

Run: `./scripts/build-push.sh latest local`
Expected: build completes, configs present in image.

- [ ] **Step 4: Commit**

```bash
git add docker/supervisor.conf Dockerfile
git commit -m "feat(proxy): supervise stunnel + imapproxy and bake configs into image"
```

---

### Task 5: Point Roundcube at the proxies

**Files:**
- Modify: `config/config.inc.php:11-15` (IMAP host), `:26-35` (providers), `:80` (password_hosts), add `imap_auth_type` + `refresh_interval`

**Interfaces:**
- Consumes: proxy listeners `127.0.0.1:1143` (zoho), `127.0.0.2:1143` (digrepal).
- Produces: Roundcube logs in via LOGIN to the local proxies; `$_SESSION['storage_host']` becomes the provider's loopback IP.

- [ ] **Step 1: Replace IMAP host/provider/password block with an env-gated toggle**

Replace `config/config.inc.php:11-15` (IMAP block) and the `avuz_providers` block (`:26-35`) with one conditional. `IMAP_USE_PROXY=1` routes through the proxies; unset/`0` keeps today's direct-to-Zoho behavior (safe default + instant rollback):

```php
// -- IMAP: proxy-gated. IMAP_USE_PROXY=1 → local imapproxy; else direct to Zoho --
$useProxy = getenv('IMAP_USE_PROXY') === '1';
if ($useProxy) {
    $config['default_host']    = '127.0.0.1';
    $config['default_port']    = 1143;
    $config['imap_auth_type']  = 'LOGIN'; // imapproxy caches LOGIN, not SASL PLAIN
    $config['avuz_providers']  = [
        'zoho'     => ['imap' => '127.0.0.1:1143', 'smtp' => 'tls://smtp.zoho.com:587'],
        'digrepal' => ['imap' => '127.0.0.2:1143', 'smtp' => 'tls://mail.digrepal.com.br:587'],
    ];
    $config['password_hosts']  = ['127.0.0.1']; // only zoho (127.0.0.1) gets the form; digrepal=127.0.0.2
} else {
    $config['default_host']    = 'ssl://imap.zoho.com';
    $config['default_port']    = 993;
    $config['imap_conn_options'] = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
    $config['avuz_providers']  = [
        'zoho'     => ['imap' => 'ssl://imap.zoho.com:993',         'smtp' => 'tls://smtp.zoho.com:587'],
        'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143',  'smtp' => 'tls://mail.digrepal.com.br:587'],
    ];
    $config['password_hosts']  = ['imap.zoho.com'];
}
$config['imap_timeout'] = 15;
```

(Delete the old standalone `imap_conn_options`, `default_host`, `default_port`, `avuz_providers`, and `password_hosts` lines — they now live inside the conditional.)

- [ ] **Step 2: Lower refresh_interval**

Add near the UI section (`config/config.inc.php:105`):

```php
$config['refresh_interval'] = 30; // snappier new-mail; cheap once connections are reused
```

- [ ] **Step 3: Lint the PHP config**

Run: `php -l config/config.inc.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add config/config.inc.php
git commit -m "feat(proxy): env-gated IMAP routing through local imapproxy (IMAP_USE_PROXY), default direct"
```

---

### Task 6: Deploy to staging and verify the win

**Files:** none (verification only)

**Interfaces:** Consumes the full stack from Tasks 1-5.

- [ ] **Step 1: Build + push to staging, enable the toggle**

Run: `./scripts/build-base.sh latest local && ./scripts/build-push.sh <ver> staging`
Then set `IMAP_USE_PROXY=1` in the staging container's environment and restart it.
Expected: both images build/push; container boots with proxy mode on.
**Rollback at any point:** unset `IMAP_USE_PROXY` (or set `0`) and restart — Roundcube returns to direct Zoho, no rebuild.

- [ ] **Step 2: Confirm proxy processes are up (on staging container)**

Run (in staging shell): `supervisorctl status`
Expected: `stunnel`, `imapproxy-zoho`, `imapproxy-digrepal` all `RUNNING`.

- [ ] **Step 3: Verify connection reuse (the core goal)**

Temporarily set `$config['imap_debug'] = true; $config['log_driver'] = 'file';`, open several messages, then:

Run: `grep -aE "Connecting|LOGIN|SELECT" /var/www/roundcube/logs/imap.log | tail -30`
Expected: Roundcube connects to `127.0.0.1` (fast); **no** repeated `Connecting to ssl://imap.zoho.com` per request. LOGIN (not AUTHENTICATE) used. Revert debug flags after.

- [ ] **Step 4: Verify latency drop**

In browser devtools Network, open a cold message.
Expected: the `_action=get&_uid=...` request drops from ~2.5s toward ~1.5s after warm-up (no SELECT cache, so redundant-SELECT RTTs remain). Residual is Brazil→US FETCH distance — see spec Escalation if <0.5s is required.

- [ ] **Step 5: Verify stunnel cert chain**

Run: `grep -i "verify\|certificate" /var/log/supervisor/stunnel.out.log | tail`
Expected: successful chain verification to imap.zoho.com, no verify errors.

- [ ] **Step 6: Verify multi-provider + password gate**

- Log in as a **zoho** user → mail loads; Settings shows the password-change form.
- Log in as a **digrepal** user → mail loads via 127.0.0.2; password-change form is **absent** (correct — not zoho).

- [ ] **Step 7: Verify password change still works**

As a zoho user, change the password via the form.
Expected: succeeds through the zoho_broker driver (host gate now passes on 127.0.0.1).

- [ ] **Step 8: Watch for Zoho connection-block (run a while, multiple users)**

Exercise several mailboxes for 15–30 min, then:
Run: `grep -aiE "blocked|too many|concurrent" /var/www/roundcube/logs/imap.log /var/log/supervisor/imapproxy-*.out.log`
Expected: **no** "temporarily blocked for IMAP use" / concurrent-limit errors. If any appear → lower `cache_expiration_time`/`cache_size` or roll back via `IMAP_USE_PROXY=0`.

- [ ] **Step 9: Final commit / tag**

```bash
git commit --allow-empty -m "test(proxy): staging verification of imapproxy latency + multi-provider"
```

---

## Verification summary

| Spec requirement | Task |
|---|---|
| Local connection-caching proxy | 1, 3, 4 |
| stunnel TLS terminator (993 implicit) | 1, 2 |
| Force LOGIN auth (G1) | 5 |
| No SELECT cache (new-mail correctness, RC #4505) | 3 |
| Env-gated rollback (IMAP_USE_PROXY) | 5, 6 |
| Zoho connection-block guard (short TTL + watch) | 3, 6 |
| Multi-provider, distinct loopback IPs | 3, 5 |
| Password host gate preserved | 5, 6 |
| refresh_interval 30s | 5 |
| Backend cert verification preserved | 2, 6 |
| Honest latency check ~1.5s | 6 |

## Known risks carried into execution

1. **imapproxy build** — source tarball URL is pinned to a confirmed-existing Debian version; binary name (`in.imapproxyd`) and `-f` flag confirmed. Only the `./configure` SSL flag may need adjustment (Task 1). Directive names: the sample `imapproxy.conf` in the source tree is authoritative.
2. **Zoho connection-block** — imapproxy holds sockets warm; short TTL (60s) mitigates, Task 6 Step 8 actively watches. If it trips, roll back via `IMAP_USE_PROXY=0`.
3. **digrepal STARTTLS** — assumes `mail.digrepal.com.br:143` offers STARTTLS; verify at Task 6 with a digrepal login.
4. **Blast radius** — proxy fronts all mail; mitigated by the `IMAP_USE_PROXY` toggle (default OFF, one-flip revert, no rebuild).
5. **If imapproxy proves too fragile** to build/run reliably, abandon and fall to the spec's Escalation (relocate container near Zoho-US), which removes more latency anyway.
