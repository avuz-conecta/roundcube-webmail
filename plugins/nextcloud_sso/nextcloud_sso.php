<?php

/**
 * Nextcloud SSO plugin for Roundcube
 *
 * Reads a signed token passed by the Nextcloud roundcube app via ?nc_token=
 * and auto-authenticates the user. Falls back to the normal login form if
 * the token is absent, invalid, or expired.
 *
 * Token format: base64(json_payload).hmac_sha256(payload, SSO_SECRET)
 * Payload: { email, enc_pass, exp }
 * enc_pass: AES-256-CBC(base64(password), CREDENTIAL_KEY)
 *
 * Required env vars (same values on both Nextcloud and Roundcube containers):
 *   ROUNDCUBE_SSO_SECRET      — HMAC signing key
 *   ROUNDCUBE_CREDENTIAL_KEY  — AES encryption key for the password
 */
class nextcloud_sso extends rcube_plugin
{
    public $task = '.*';

    public function init(): void
    {
        $this->include_stylesheet('avuz-overrides.css');
        $this->include_script('avuz-overrides.js');
        $this->add_hook('startup', [$this, 'handleStartup']);
        $this->add_hook('smtp_connect', [$this, 'applySmtp']);
        $this->add_hook('login_after', [$this, 'gatePasswordChange']);
        $this->add_hook('password_change', [$this, 'flagPasswordChanged']);

        // The password plugin re-evaluates the forced-change redirect on every
        // request, reading config fresh — so re-apply the exemption each request.
        $this->enforceTenantPasswordGate(rcmail::get_instance(), false);
    }

    /**
     * login_after hook — resolve tenant membership for the just-logged-in user
     * and exempt non-tenant domains from the forced password change. Runs before
     * the password plugin's login_after (this plugin loads first), so the
     * exemption is in place when password decides whether to force the change.
     */
    public function gatePasswordChange(array $args): array
    {
        $this->enforceTenantPasswordGate(rcmail::get_instance(), true);
        return $args;
    }

    /**
     * password_change hook — fires only on a successful password change. Sets an
     * env flag in the AJAX response so the client (avuz-overrides.js) can redirect
     * to the inbox. Reliable across the settings iframe, unlike the forwarded
     * confirmation message.
     */
    public function flagPasswordChanged(array $args): array
    {
        rcmail::get_instance()->output->set_env('avuz_password_changed', true);
        return $args;
    }

    /**
     * Pure exemption rule: a non-tenant user is added to the password plugin's
     * login-exceptions list (which suppresses the forced/first-login password
     * change). Tenant users are left untouched so they are still forced.
     *
     * @param list<string> $exceptions
     * @return list<string>
     */
    public static function applyTenantExemption(array $exceptions, bool $isTenant, string $username): array
    {
        if ($isTenant || $username === '') {
            return $exceptions;
        }

        if (!in_array($username, $exceptions, true)) {
            $exceptions[] = $username;
        }

        return $exceptions;
    }

    private function enforceTenantPasswordGate(rcmail $rcmail, bool $recheck): void
    {
        $username = isset($_SESSION['username']) ? (string) $_SESSION['username'] : '';
        if ($username === '') {
            return;
        }

        if ($recheck || !array_key_exists('avuz_is_tenant', $_SESSION)) {
            $_SESSION['avuz_is_tenant'] = $this->isTenantDomain($rcmail, $username);
        }

        $exceptions = (array) $rcmail->config->get('password_login_exceptions', []);
        $updated    = self::applyTenantExemption($exceptions, (bool) $_SESSION['avuz_is_tenant'], $username);
        if ($updated !== $exceptions) {
            $rcmail->config->set('password_login_exceptions', $updated);
        }
    }

    /**
     * Ask the broker whether the email's domain is a configured Zoho tenant.
     * On any failure (no config, non-200, unreachable) returns false so the
     * forced password change is skipped — a non-tenant domain can never be
     * reset by the broker, so forcing it is a dead-end.
     */
    private function isTenantDomain(rcmail $rcmail, string $email): bool
    {
        $url    = (string) $rcmail->config->get('avuz_broker_url');
        $secret = (string) $rcmail->config->get('avuz_broker_secret');
        if ($url === '' || $secret === '') {
            return false;
        }

        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 5]);
            $response = $client->get(rtrim($url, '/') . '/is-tenant', [
                'query'       => ['email' => $email],
                'headers'     => ['X-Broker-Secret' => $secret],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) && !empty($data['tenant']);
        } catch (\Exception $e) {
            rcube::write_log('errors', 'nextcloud_sso: is-tenant check failed: ' . $e->getMessage());
            return false;
        }
    }

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

    public function handleStartup(array $args): array
    {
        if ($args['task'] !== 'login') {
            return $args;
        }

        $rawToken = rcube_utils::get_input_string('nc_token', rcube_utils::INPUT_GET);
        if (empty($rawToken)) {
            return $args;
        }

        $credentials = $this->validateToken($rawToken);
        if ($credentials === null) {
            return $args;
        }

        $rcmail = rcmail::get_instance();

        $providers = (array) $rcmail->config->get('avuz_providers', []);
        $entry = self::lookupProvider($providers, $credentials['provider']);
        if ($entry === null && !empty($credentials['provider'])) {
            $loggedKey = substr($credentials['provider'], 0, 32);
            rcube::raise_error(
                "nextcloud_sso: unknown provider key '{$loggedKey}', using default",
                true, false
            );
        }

        $imapHost = $entry['imap'] ?? null;
        $result = $rcmail->login($credentials['email'], $credentials['password'], $imapHost, false);

        if ($result) {
            if ($entry !== null) {
                $_SESSION['avuz_smtp_host'] = $entry['smtp'];
            }
            $rcmail->session->remove('temp');
            $rcmail->session->regenerate_id(false);
            $rcmail->session->set_auth_cookie();
            $args['task'] = 'mail';
            $args['action'] = '';
        } else {
            $email = json_encode($credentials['email']);
            $rcmail->output->add_script(
                "(function(){"
                . "function escHtml(t){var d=document.createElement('div');d.appendChild(document.createTextNode(t));return d.innerHTML;}"
                . "var email=" . $email . ";"
                . "var ov=document.createElement('div');"
                . "ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;z-index:10000;';"
                . "ov.innerHTML="
                . "'<div style=\"background:#fff;border-radius:16px;padding:32px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.18)\">'"
                . "+'<h3 style=\"margin:0 0 12px;font-size:1.1rem;color:#2f3a3f\">Autenticação de email necessária</h3>'"
                . "+'<p style=\"margin:0 0 8px;color:#555;font-size:.9rem\">Sua senha do Conecta não coincide com a senha do email <strong>'+escHtml(email)+'</strong>.</p>'"
                . "+'<p style=\"margin:0 0 8px;color:#555;font-size:.9rem\">Informe a senha do seu email:</p>'"
                . "+'<input type=\"password\" id=\"rc-sso-pw\" autocomplete=\"current-password\" placeholder=\"Senha do email\" style=\"width:100%;padding:10px 12px;border:1px solid #ccc;border-radius:8px;margin:8px 0 16px;font-size:1rem;box-sizing:border-box\" />'"
                . "+'<p id=\"rc-sso-err\" style=\"display:none;color:#c00;font-size:.85rem;margin:0 0 8px\"></p>'"
                . "+'<div style=\"display:flex;gap:8px;justify-content:flex-end\">'"
                . "+'<button id=\"rc-sso-cancel\" style=\"padding:9px 20px;border-radius:50px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:.9rem;color:#555\">Cancelar</button>'"
                . "+'<button id=\"rc-sso-submit\" style=\"padding:9px 20px;border-radius:50px;border:none;background:#2bb5e3;color:#fff;cursor:pointer;font-size:.9rem\">Conectar</button>'"
                . "+'</div></div>';"
                . "document.body.appendChild(ov);"
                . "document.getElementById('rc-sso-pw').focus();"
                . "document.getElementById('rc-sso-cancel').addEventListener('click',function(){ov.remove();});"
                . "function submit(){"
                . "  var pw=document.getElementById('rc-sso-pw').value;"
                . "  if(!pw)return;"
                . "  var btn=document.getElementById('rc-sso-submit');"
                . "  btn.disabled=true;btn.textContent='Conectando...';"
                . "  document.getElementById('rc-sso-err').style.display='none';"
                . "  window.parent.postMessage({type:'roundcube-sso-password',email:email,password:pw},'*');"
                . "}"
                . "document.getElementById('rc-sso-submit').addEventListener('click',submit);"
                . "document.getElementById('rc-sso-pw').addEventListener('keydown',function(e){if(e.key==='Enter')submit();});"
                . "window.addEventListener('message',function(e){"
                . "  if(!e.data||e.data.type!=='roundcube-sso-error')return;"
                . "  var err=document.getElementById('rc-sso-err');"
                . "  err.textContent=e.data.message||'Falha ao salvar senha. Tente novamente.';"
                . "  err.style.display='block';"
                . "  var btn=document.getElementById('rc-sso-submit');"
                . "  btn.disabled=false;btn.textContent='Conectar';"
                . "});"
                . "}());",
                'docready'
            );
        }

        return $args;
    }

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

    private function validateToken(string $rawToken): ?array
    {
        $parts = explode('.', $rawToken, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $receivedSig] = $parts;

        $secret = getenv('ROUNDCUBE_SSO_SECRET') ?: '';
        if (empty($secret)) {
            rcube::raise_error('nextcloud_sso: ROUNDCUBE_SSO_SECRET is not set', true, false);
            return null;
        }

        $expectedSig = hash_hmac('sha256', $encodedPayload, $secret);
        if (!hash_equals($expectedSig, $receivedSig)) {
            return null;
        }

        $payload = json_decode(base64_decode($encodedPayload), true);
        if (!is_array($payload)) {
            return null;
        }

        if (empty($payload['email']) || empty($payload['enc_pass']) || empty($payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < time()) {
            return null;
        }

        $password = $this->decryptPassword($payload['enc_pass']);
        if (empty($password)) {
            return null;
        }

        return [
            'email' => $payload['email'],
            'password' => $password,
            'provider' => isset($payload['provider']) ? (string) $payload['provider'] : null,
        ];
    }

    private function decryptPassword(string $encrypted): string
    {
        $rawKey = getenv('ROUNDCUBE_CREDENTIAL_KEY') ?: '';
        if (empty($rawKey)) {
            rcube::raise_error('nextcloud_sso: ROUNDCUBE_CREDENTIAL_KEY is not set', true, false);
            return '';
        }

        $key = substr(hash('sha256', $rawKey, true), 0, 32);

        $decoded = base64_decode($encrypted);
        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return '';
        }

        $encryptedData = base64_decode($parts[0]);
        $iv = base64_decode($parts[1]);

        $decrypted = openssl_decrypt($encryptedData, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return '';
        }

        $plain = base64_decode($decrypted);
        if ($plain === false || $plain === '') {
            return '';
        }

        return $plain;
    }
}
