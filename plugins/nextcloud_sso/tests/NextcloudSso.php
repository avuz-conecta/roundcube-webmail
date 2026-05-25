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
}
