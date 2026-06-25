<?php

/**
 * Roundcube password driver for the Avuz Zoho password-broker.
 *
 * Sends the change to an internal broker service that verifies the current
 * password over IMAP and resets the Zoho mailbox password via the Zoho Mail
 * Admin API. Roundcube holds no Zoho org secret — only the broker URL and a
 * shared secret.
 *
 * Config:
 *   $config['avuz_broker_url']    = 'http://broker:9000';
 *   $config['avuz_broker_secret'] = '...';
 */
class rcube_zoho_broker_password
{
    public static function map_status(int $http): int
    {
        $map = [
            200 => PASSWORD_SUCCESS,
            403 => PASSWORD_ERROR,
            404 => PASSWORD_ERROR,
            502 => PASSWORD_CONNECT_ERROR,
        ];

        return $map[$http] ?? PASSWORD_ERROR;
    }

    public function save($curpass, $newpass, $username)
    {
        $rcmail = rcmail::get_instance();
        $url    = $rcmail->config->get('avuz_broker_url');
        $secret = $rcmail->config->get('avuz_broker_secret');

        if (empty($url) || empty($secret)) {
            return PASSWORD_ERROR;
        }

        try {
            $client = password::get_http_client();
            $response = $client->post($url . '/reset', [
                'headers' => ['X-Broker-Secret' => $secret],
                'json'    => ['email' => $username, 'currentPass' => $curpass, 'newPass' => $newpass],
                'http_errors' => false,
            ]);

            return self::map_status($response->getStatusCode());
        } catch (Exception $e) {
            rcube::write_log('errors', 'zoho_broker: ' . $e->getMessage());
            return PASSWORD_CONNECT_ERROR;
        }
    }
}
