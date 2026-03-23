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
    public $task = 'login';

    public function init(): void
    {
        $this->add_hook('startup', [$this, 'handleStartup']);
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
        $result = $rcmail->login($credentials['email'], $credentials['password'], null, false);

        if ($result) {
            $rcmail->session->remove('temp');
            $rcmail->output->redirect(['_task' => 'mail']);
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

        return ['email' => $payload['email'], 'password' => $password];
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

        return base64_decode($decrypted);
    }
}
