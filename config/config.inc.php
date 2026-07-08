<?php

// ============================================
// Avuz Roundcube Configuration
// All sensitive values come from environment variables.
// ============================================

// -- Database --
$config['db_dsnw'] = getenv('ROUNDCUBE_DB_DSN') ?: 'sqlite:////var/www/roundcube/temp/roundcube.db';

// -- IMAP: proxy-gated. IMAP_USE_PROXY=1 → Debian imapproxy sidecar (warm, cached
// connections to Zoho); unset/0 → direct to Zoho (today's behavior). --
$useProxy  = getenv('IMAP_USE_PROXY') === '1';
$proxyHost = getenv('IMAP_PROXY_HOST') ?: 'imapproxy'; // must match the sidecar service name in the stack
if ($useProxy) {
    $config['default_host']   = $proxyHost;
    $config['default_port']   = 143;
    $config['imap_auth_type'] = 'IMAP'; // plaintext LOGIN command — imapproxy caches it (it does NOT cache SASL)
    // no imap_conn_options: hop to the sidecar is plaintext, stunnel inside it does TLS to Zoho
    $config['avuz_providers'] = [
        'zoho'     => ['imap' => $proxyHost . ':143',              'smtp' => 'tls://smtp.zoho.com:587'],
        'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143', 'smtp' => 'tls://mail.digrepal.com.br:587'],
    ];
    $config['password_hosts'] = [$proxyHost]; // storage_host becomes the sidecar name; only zoho users get the form
} else {
    $config['default_host']      = 'ssl://imap.zoho.com';
    $config['default_port']      = 993;
    $config['imap_conn_options'] = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
    $config['avuz_providers']    = [
        'zoho'     => ['imap' => 'ssl://imap.zoho.com:993',        'smtp' => 'tls://smtp.zoho.com:587'],
        'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143', 'smtp' => 'tls://mail.digrepal.com.br:587'],
    ];
    $config['password_hosts']    = ['imap.zoho.com'];
}
$config['imap_timeout'] = 15;

// -- SMTP (Zoho) --
$config['smtp_server'] = 'tls://smtp.zoho.com';
$config['smtp_port'] = 587;
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['smtp_timeout'] = 15;

// -- Special folders — Zoho uses IMAP SPECIAL-USE flags with localized (pt_BR)
// names. Match them explicitly so Roundcube uses the folders that actually exist
// instead of trying to CREATE "Drafts"/"Sent"/… (which fails: "Folder exists").
$config['drafts_mbox']            = 'Rascunho';
$config['sent_mbox']              = 'Enviadas';
$config['trash_mbox']             = 'Lixeira';
$config['junk_mbox']              = 'Spam';
$config['archive_mbox']           = 'Archive';
$config['create_default_folders'] = false;

// -- Cache --
// Redis if REDIS_HOST is set, otherwise fall back to DB cache
$redisHost = getenv('REDIS_HOST');
if ($redisHost) {
    $config['redis_hosts']    = [$redisHost . ':' . (getenv('REDIS_PORT') ?: '6379')];
    $config['imap_cache']     = 'redis';
    $config['messages_cache'] = 'redis'; // backend TYPE (was `true` = broken → no message caching)
} else {
    $config['imap_cache']     = 'db';
    $config['messages_cache'] = 'db';
}
$config['messages_cache_ttl'] = '10d';

// -- Security --
// Generate with: pwgen -s 24 1
// IMPORTANT: never change this after first deploy — it decrypts stored passwords
$config['des_key'] = getenv('ROUNDCUBE_DES_KEY') ?: 'change-me-24-byte-des-key!!';
$config['ip_check'] = false; // required for iframe/proxy setups
$config['x_frame_options'] = '';

// -- SSO --
// Shared with the Nextcloud roundcube app — must match ROUNDCUBE_SSO_SECRET env var on both sides
// Set via environment only, never hardcoded here

// -- Plugins --
$config['plugins'] = [
    'nextcloud_sso',
    'archive',
    'zipdownload',
    'managesieve',
    'password',
    'avuz_prefetch',
];

// -- Password change (Zoho via internal broker) --
$config['password_driver']           = 'zoho_broker';
$config['password_force_new_user']   = true;
$config['password_confirm_current']  = true;
$config['password_minimum_length']   = 8;
// zxcvbn strength driver disabled: the bjeavons/zxcvbn-php lib isn't in the image,
// so it returns no score and rejects every password as "too weak". Length + Zoho's
// own complexity policy (enforced on the reset) cover strength. Re-enable only if the
// lib is added to the base image.
$config['password_strength_driver']  = null;
// password_hosts is set in the IMAP proxy-gate block above (differs by proxy vs direct mode)
$config['avuz_broker_url']           = getenv('AVUZ_BROKER_URL') ?: 'http://broker:9000';
$config['avuz_broker_secret']        = getenv('AVUZ_BROKER_SECRET') ?: '';

// -- Skin --
$config['skin'] = 'avuz';
$config['dont_override'] = ['skin'];
// Paths are skin-relative: Roundcube's file_callback resolves a leading-slash
// href against the skin tree (skins/avuz first), so '/images/x' → skins/avuz/images/x.
// A site-absolute '/skins/avuz/...' would be re-prefixed with the skin path (404).
$config['skin_logo'] = [
    ''             => '/images/icon.png',
    'login'        => '/images/login-logo.png',
    '[favicon]'    => '/images/favicon-avuz.ico',
    '[small]'      => '/images/icon.png',
    '[dark]'       => '/images/icon.png',
    '[small-dark]' => '/images/icon.png',
    '[print]'      => '/images/logo.png',
    '[link]'       => '',
];

// -- UI / Locale --
$config['product_name'] = 'Conecta Mail';
$config['language'] = 'pt_BR';
$config['timezone'] = 'America/Sao_Paulo';
$config['mail_pagesize'] = 30; // bounds prefetch to 30 bodies/page
// mail_read_time is the real 1.6 option (preview_pane_mark_read is ignored). >0
// defers the \Seen STORE to a separate client request N seconds after open, so
// the open itself isn't blocked by the round-trip to Zoho. 0 = mark synchronously.
$config['mail_read_time'] = 1;
$config['draft_autosave'] = 60;
$config['show_images'] = 1;
$config['htmleditor'] = 1;
$config['mime_param_folding'] = 1;

// -- Privacy --
$config['support_url'] = '';
$config['display_version'] = false;

// -- Session cookie — required for iframe embedding across subdomains --
// SameSite=None allows the session cookie to be sent inside an iframe
// served from a different subdomain. Requires HTTPS (Secure flag).
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');

// -- Logging --
$config['log_driver'] = 'stdout';
$config['log_logins'] = true;
$config['log_session'] = false;

// -- Debug (env-gated) — set ROUNDCUBE_DEBUG=1 in the stack to capture IMAP/SMTP
// wire logs to logs/imap.log + logs/smtp.log; unset to turn off. getenv() is read
// live per request, so it's not affected by OPcache. --
if (getenv('ROUNDCUBE_DEBUG') === '1') {
    $config['imap_debug'] = true;
    $config['smtp_debug'] = true;
    $config['log_driver'] = 'file';
    $config['log_dir']    = '/var/www/roundcube/logs';
}
