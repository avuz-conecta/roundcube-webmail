# Multi-Provider Email Routing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route each Roundcube login to its email provider via a named key carried in the SSO token, with the provider fixed per Nextcloud instance.

**Architecture:** One shared Roundcube image holds a `key → host` map (`avuz_providers`). The per-client Nextcloud container resolves one instance-level provider key (env `ROUNDCUBE_PROVIDER`) and signs it into the SSO token. Roundcube reads the key, applies IMAP host to `login()` (persists in session) and stashes SMTP host for a per-request `smtp_connect` hook. Unknown/missing key → Zoho default.

**Tech Stack:** PHP 8.2, Roundcube plugin API (`rcube_plugin`, hooks), PHPUnit 9, Nextcloud app framework (`IConfig`).

**Two repos:**
- `roundcube-webmail` (this repo) — Tasks A1–A6. Branch `feat/multi-provider`.
- `avuz-server` `apps/roundcube/` — Tasks B1–B3. Branch `feat/roundcube-multi-provider`.

**Provider key contract (both repos must agree):**

| Key | IMAP | SMTP |
|-----|------|------|
| `zoho` | `ssl://imap.zoho.com:993` | `tls://smtp.zoho.com:587` |
| `digrepal` | `tls://mail.digrepal.com.br:143` | `tls://mail.digrepal.com.br:587` |

**Prerequisite — test runner:** This repo has no local `vendor/`. Before running any PHPUnit step:
```bash
composer install
```
Expected: `vendor/bin/phpunit` exists afterward. If `composer` is unavailable on the host, run the test steps inside the app container (`docker run --rm -v "$PWD":/app -w /app <base-image> vendor/bin/phpunit ...`).

---

## File Structure

| File | Responsibility | Action |
|------|----------------|--------|
| `config/config.inc.php` | Holds `avuz_providers` map; keeps Zoho keys as fallback | Modify |
| `plugins/nextcloud_sso/nextcloud_sso.php` | Reads provider key, applies IMAP host, `smtp_connect` hook | Modify |
| `plugins/nextcloud_sso/tests/NextcloudSso.php` | Unit tests for `lookupProvider` + `applySmtp` | Create |
| `tests/phpunit.xml` | Registers the plugin test in the Plugins suite | Modify |
| `avuz-server/apps/roundcube/lib/Service/CredentialService.php` | Instance-level provider resolution + token field | Modify |

---

## Task A1: Provider map in config

**Files:**
- Modify: `config/config.inc.php:11-22`

- [ ] **Step 1: Add the `avuz_providers` map after the SMTP block**

Insert after line 22 (`$config['smtp_timeout'] = 15;`):

```php

// -- Multi-provider map (key -> hosts). NC sends the key in the SSO token.
// Unknown/missing key falls back to the Zoho defaults above.
$config['avuz_providers'] = [
    'zoho' => [
        'imap' => 'ssl://imap.zoho.com:993',
        'smtp' => 'tls://smtp.zoho.com:587',
    ],
    'digrepal' => [
        'imap' => 'tls://mail.digrepal.com.br:143',
        'smtp' => 'tls://mail.digrepal.com.br:587',
    ],
];
```

- [ ] **Step 2: Lint the file**

Run: `php -l config/config.inc.php`
Expected: `No syntax errors detected in config/config.inc.php`

- [ ] **Step 3: Commit**

```bash
git add config/config.inc.php
git commit -m "avuz(config): add avuz_providers map for multi-provider routing"
```

---

## Task A2: `lookupProvider` pure resolver (TDD)

**Files:**
- Create: `plugins/nextcloud_sso/tests/NextcloudSso.php`
- Modify: `plugins/nextcloud_sso/nextcloud_sso.php`
- Modify: `tests/phpunit.xml`

- [ ] **Step 1: Register the test file in the Plugins suite**

In `tests/phpunit.xml`, inside `<testsuite name="Plugins">`, add (alphabetical order, before `new_user_dialog`):

```xml
      <file>./../plugins/nextcloud_sso/tests/NextcloudSso.php</file>
```

- [ ] **Step 2: Write the failing test**

Create `plugins/nextcloud_sso/tests/NextcloudSso.php`:

```php
<?php

class NextcloudSso_Plugin extends PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        include_once __DIR__ . '/../nextcloud_sso.php';
    }

    private function providers(): array
    {
        return [
            'zoho'     => ['imap' => 'ssl://imap.zoho.com:993', 'smtp' => 'tls://smtp.zoho.com:587'],
            'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143', 'smtp' => 'tls://mail.digrepal.com.br:587'],
        ];
    }

    function test_lookup_returns_entry_for_known_key()
    {
        $entry = nextcloud_sso::lookupProvider($this->providers(), 'digrepal');
        $this->assertSame('tls://mail.digrepal.com.br:143', $entry['imap']);
        $this->assertSame('tls://mail.digrepal.com.br:587', $entry['smtp']);
    }

    function test_lookup_returns_null_for_unknown_key()
    {
        $this->assertNull(nextcloud_sso::lookupProvider($this->providers(), 'nope'));
    }

    function test_lookup_returns_null_for_missing_key()
    {
        $this->assertNull(nextcloud_sso::lookupProvider($this->providers(), null));
        $this->assertNull(nextcloud_sso::lookupProvider($this->providers(), ''));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/phpunit.xml --filter NextcloudSso_Plugin`
Expected: FAIL — `Call to undefined method nextcloud_sso::lookupProvider()`

- [ ] **Step 4: Implement `lookupProvider`**

In `plugins/nextcloud_sso/nextcloud_sso.php`, add this public static method after `init()` (before `handleStartup`):

```php
    /**
     * Map a provider key to its host entry. Null for unknown/empty key.
     *
     * @param array<string,array{imap:string,smtp:string}> $providers
     */
    public static function lookupProvider(array $providers, ?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $providers[$key] ?? null;
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/phpunit.xml --filter NextcloudSso_Plugin`
Expected: PASS (3 tests)

- [ ] **Step 6: Commit**

```bash
git add plugins/nextcloud_sso/nextcloud_sso.php plugins/nextcloud_sso/tests/NextcloudSso.php tests/phpunit.xml
git commit -m "avuz(sso): add lookupProvider key resolver with tests"
```

---

## Task A3: Read optional `provider` from token

**Files:**
- Modify: `plugins/nextcloud_sso/nextcloud_sso.php:126-139`

- [ ] **Step 1: Include `provider` in the validated credentials**

In `validateToken`, replace the final return (line 139):

```php
        return ['email' => $payload['email'], 'password' => $password];
```

with:

```php
        return [
            'email' => $payload['email'],
            'password' => $password,
            'provider' => isset($payload['provider']) ? (string) $payload['provider'] : null,
        ];
```

Note: `provider` is optional — the existing checks at line 126 (`email`, `enc_pass`, `exp`) are unchanged, so old tokens without `provider` still validate.

- [ ] **Step 2: Lint**

Run: `php -l plugins/nextcloud_sso/nextcloud_sso.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/nextcloud_sso/nextcloud_sso.php
git commit -m "avuz(sso): read optional provider key from SSO token"
```

---

## Task A4: Apply provider at login (IMAP host)

**Files:**
- Modify: `plugins/nextcloud_sso/nextcloud_sso.php:44-52`

- [ ] **Step 1: Resolve provider and pass IMAP host to login()**

In `handleStartup`, replace lines 44-45:

```php
        $rcmail = rcmail::get_instance();
        $result = $rcmail->login($credentials['email'], $credentials['password'], null, false);
```

with:

```php
        $rcmail = rcmail::get_instance();

        $providers = (array) $rcmail->config->get('avuz_providers', []);
        $entry = self::lookupProvider($providers, $credentials['provider']);
        if ($entry === null && !empty($credentials['provider'])) {
            rcube::raise_error(
                "nextcloud_sso: unknown provider key '{$credentials['provider']}', using default",
                true, false
            );
        }

        $imapHost = $entry['imap'] ?? null;
        $result = $rcmail->login($credentials['email'], $credentials['password'], $imapHost, false);
```

Note: `login()` parses the full URI (`tls://host:143`) for scheme/port via `parse_host_uri` and persists it in the session as `storage_host`/`storage_port`, so IMAP routing survives every later request automatically. Passing `null` keeps the Zoho `default_host`.

- [ ] **Step 2: Stash SMTP host in session on successful login**

In the same method, inside the `if ($result) {` block, add as the first line (before `$rcmail->session->remove('temp');`):

```php
            if ($entry !== null) {
                $_SESSION['avuz_smtp_host'] = $entry['smtp'];
            }
```

- [ ] **Step 3: Lint**

Run: `php -l plugins/nextcloud_sso/nextcloud_sso.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add plugins/nextcloud_sso/nextcloud_sso.php
git commit -m "avuz(sso): route IMAP host by provider, stash SMTP in session"
```

---

## Task A5: `smtp_connect` hook applies stashed SMTP host (TDD)

**Files:**
- Modify: `plugins/nextcloud_sso/nextcloud_sso.php:22-26` (register hook) and add `applySmtp`
- Modify: `plugins/nextcloud_sso/tests/NextcloudSso.php`

- [ ] **Step 1: Write the failing test**

Add to `plugins/nextcloud_sso/tests/NextcloudSso.php`:

```php
    function test_applySmtp_overrides_host_from_session()
    {
        $rcube  = rcube::get_instance();
        $plugin = new nextcloud_sso($rcube->plugins);

        $_SESSION['avuz_smtp_host'] = 'tls://mail.digrepal.com.br:587';
        $result = $plugin->applySmtp(['smtp_host' => 'tls://smtp.zoho.com:587']);
        $this->assertSame('tls://mail.digrepal.com.br:587', $result['smtp_host']);

        unset($_SESSION['avuz_smtp_host']);
    }

    function test_applySmtp_leaves_host_when_session_empty()
    {
        $rcube  = rcube::get_instance();
        $plugin = new nextcloud_sso($rcube->plugins);

        unset($_SESSION['avuz_smtp_host']);
        $result = $plugin->applySmtp(['smtp_host' => 'tls://smtp.zoho.com:587']);
        $this->assertSame('tls://smtp.zoho.com:587', $result['smtp_host']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/phpunit.xml --filter NextcloudSso_Plugin`
Expected: FAIL — `Call to undefined method nextcloud_sso::applySmtp()`

- [ ] **Step 3: Register the hook**

In `init()` (line 25), add after the existing `startup` hook line:

```php
        $this->add_hook('smtp_connect', [$this, 'applySmtp']);
```

- [ ] **Step 4: Implement `applySmtp`**

Add this public method after `handleStartup`:

```php
    /**
     * smtp_connect hook — runs every request. Overrides the SMTP host with the
     * provider stashed at login. SMTP (unlike IMAP) is not session-persisted by
     * Roundcube, so without this the host would revert to the Zoho config default.
     */
    public function applySmtp(array $args): array
    {
        if (!empty($_SESSION['avuz_smtp_host'])) {
            $args['smtp_host'] = $_SESSION['avuz_smtp_host'];
        }

        return $args;
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/phpunit.xml --filter NextcloudSso_Plugin`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add plugins/nextcloud_sso/nextcloud_sso.php plugins/nextcloud_sso/tests/NextcloudSso.php
git commit -m "avuz(sso): smtp_connect hook applies provider SMTP host"
```

---

## Task A6: Manual end-to-end verification (Roundcube)

**Files:** none (verification only)

- [ ] **Step 1: Build the image and run locally**

Run: `./scripts/build-push.sh latest local`
Expected: image builds, container starts.

- [ ] **Step 2: Verify digrepal routing**

Craft a token with `provider:"digrepal"` (or set the NC instance per Task B), open the iframe URL, log in. In the container, confirm the IMAP connection targets `mail.digrepal.com.br:143` and a sent test mail leaves via `mail.digrepal.com.br:587`:

Run: `docker logs <container> 2>&1 | grep -iE "imap|smtp" | tail`
Expected: connections to `mail.digrepal.com.br`, not `zoho.com`.

- [ ] **Step 3: Verify backward compatibility**

Log in with a token that has **no** `provider` field. Confirm IMAP/SMTP both hit `*.zoho.com` (default path unchanged).

---

## Task B1: Instance-level `resolveProvider` (NC)

**Files:**
- Modify: `avuz-server/apps/roundcube/lib/Service/CredentialService.php`

Work in repo `avuz-server` on branch `feat/roundcube-multi-provider`.

- [ ] **Step 1: Add the resolver method**

After `resolveEmail` (ends line 83), add:

```php
    private function resolveProvider(): string
    {
        $value = $this->config->getAppValue(self::APP_ID, 'provider', (string) getenv('ROUNDCUBE_PROVIDER'));
        return $value !== '' ? $value : 'zoho';
    }
```

Resolution order: app value → env `ROUNDCUBE_PROVIDER` → `'zoho'`. Same for every user in this container.

- [ ] **Step 2: Lint**

Run: `php -l apps/roundcube/lib/Service/CredentialService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add apps/roundcube/lib/Service/CredentialService.php
git commit -m "avuz(app): resolve instance-level email provider"
```

---

## Task B2: Add `provider` to the token

**Files:**
- Modify: `avuz-server/apps/roundcube/lib/Service/CredentialService.php:67,85-96`

- [ ] **Step 1: Pass provider into buildToken**

In `buildIframeUrl`, replace line 67:

```php
        $token = $this->buildToken($email, $encryptedPassword);
```

with:

```php
        $token = $this->buildToken($email, $encryptedPassword, $this->resolveProvider());
```

- [ ] **Step 2: Accept and embed the provider in the payload**

Replace the `buildToken` signature and payload (lines 85-91):

```php
    private function buildToken(string $email, string $encryptedPassword): string
    {
        $payload = base64_encode((string) json_encode([
            'email' => $email,
            'enc_pass' => $encryptedPassword,
            'exp' => time() + self::TOKEN_TTL,
        ]));
```

with:

```php
    private function buildToken(string $email, string $encryptedPassword, string $provider): string
    {
        $payload = base64_encode((string) json_encode([
            'email' => $email,
            'enc_pass' => $encryptedPassword,
            'exp' => time() + self::TOKEN_TTL,
            'provider' => $provider,
        ]));
```

- [ ] **Step 3: Lint**

Run: `php -l apps/roundcube/lib/Service/CredentialService.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add apps/roundcube/lib/Service/CredentialService.php
git commit -m "avuz(app): include provider key in roundcube SSO token"
```

---

## Task B3: Manual verification (NC)

**Files:** none (verification only)

- [ ] **Step 1: Set provider on a test instance**

Run: `occ config:app:set roundcube provider --value digrepal`
(Or set `ROUNDCUBE_PROVIDER=digrepal` in the portainer stack and redeploy.)

- [ ] **Step 2: Inspect the generated token**

Open the roundcube page, capture the `?nc_token=` value, decode the payload:

Run: `php -r 'echo json_encode(json_decode(base64_decode(explode(".", $argv[1])[0]), true), JSON_PRETTY_PRINT);' '<token>'`
Expected: payload includes `"provider": "digrepal"`.

- [ ] **Step 3: Verify default**

Unset the value (`occ config:app:delete roundcube provider`, no env). Confirm decoded payload shows `"provider": "zoho"`.

---

## Notes for the executor

- **Task order:** A1–A6 first (Roundcube, backward-compatible), then B1–B3 (NC). Roundcube tolerates tokens with no `provider`, so it can ship before NC.
- **`rcube::raise_error`** in Task A4 is intentionally not unit-tested — it logs a global. The unknown-key path is covered by `lookupProvider` returning null (Task A2).
- **No NC test infra** exists in `apps/roundcube/`; B1–B2 are verified manually (B3). The logic is a config lookup and a payload field — low risk.
