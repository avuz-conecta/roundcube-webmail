# Avuz Roundcube Webmail

## What Is This

Fork of [roundcube/roundcubemail](https://github.com/roundcube/roundcubemail) customized for the Avuz Conecta SaaS platform. Embedded inside Nextcloud via the `roundcube` app in [avuz-conecta/avuz-server](https://github.com/avuz-conecta/avuz-server).

- **Base version**: 1.6.14
- **Branch**: `avuz-customization`
- **Email provider**: Zoho (imap.zoho.com / smtp.zoho.com)

## Git Remotes

| Remote | URL |
|--------|-----|
| `origin` | git@github.com:avuz-conecta/roundcube-webmail.git |
| `upstream` | git@github.com:roundcube/roundcubemail.git |

## Our Customizations

All changes from upstream are documented in `customizations.json`.

| File | What it does |
|------|-------------|
| `skins/avuz/` | Elastic child skin — cyan/lime brand colors |
| `plugins/nextcloud_sso/` | SSO token validation for Nextcloud integration |
| `config/config.inc.php` | Zoho IMAP/SMTP, Redis cache, pt_BR locale |
| `docker/` | Entrypoint, Nginx, Supervisor configs |
| `Dockerfile.base` | PHP 8.2 FPM + extensions (rebuild rarely) |
| `Dockerfile` | App image — rebuilds on every code change |

## Build

```bash
# First time (or when PHP extensions change)
./scripts/build-base.sh latest local

# Every deploy
./scripts/build-push.sh latest local        # local test
./scripts/build-push.sh 1.0.0 staging       # push to staging registry
./scripts/build-push.sh 1.0.0 prod          # push to prod registry
```

## Required Environment Variables

| Variable | Description |
|----------|-------------|
| `ROUNDCUBE_DES_KEY` | 24-byte encryption key — **never change after first deploy** |
| `ROUNDCUBE_SSO_SECRET` | HMAC key shared with Nextcloud roundcube app |
| `ROUNDCUBE_CREDENTIAL_KEY` | AES key for password encryption — shared with NC app |
| `ROUNDCUBE_DB_DSN` | DB connection string (default: SQLite in temp/) |
| `REDIS_HOST` | Redis host (optional — enables cache if set) |

## Upgrading Upstream

```bash
git fetch upstream
git checkout avuz-customization

# Rebase onto new tag
git rebase upstream/release-1.7   # or the new stable tag

# Resolve conflicts using customizations.json as checklist
# Check each entry: do CSS selectors still apply? plugin hooks still work?

# Rebuild
./scripts/build-base.sh latest local
./scripts/build-push.sh latest local
```

## Nextcloud Integration

The companion Nextcloud app lives at `apps/roundcube/` in `avuz-conecta/avuz-server`.
It handles credential storage on login and generates the SSO token passed to this app via `?nc_token=`.

## Zoho IMAP/SMTP

| Setting | Value |
|---------|-------|
| IMAP host | imap.zoho.com |
| IMAP port | 993 (SSL) |
| SMTP host | smtp.zoho.com |
| SMTP port | 587 (STARTTLS) |
| Auth | Email address as username |
