<?php

// ============================================
// Avuz Roundcube Configuration
// All sensitive values come from environment variables.
// ============================================

// -- Database --
$config['db_dsnw'] = getenv('ROUNDCUBE_DB_DSN') ?: 'sqlite:////var/www/roundcube/temp/roundcube.db';

// -- IMAP (Zoho) --
$config['default_host'] = 'ssl://imap.zoho.com';
$config['default_port'] = 993;
$config['imap_conn_options'] = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
$config['imap_timeout'] = 15;

// -- SMTP (Zoho) --
$config['smtp_server'] = 'tls://smtp.zoho.com';
$config['smtp_port'] = 587;
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['smtp_timeout'] = 15;

// -- Cache --
// Redis if REDIS_HOST is set, otherwise fall back to DB cache
$redisHost = getenv('REDIS_HOST');
if ($redisHost) {
    $config['redis_hosts'] = $redisHost . ':' . (getenv('REDIS_PORT') ?: '6379');
    $config['imap_cache'] = 'redis';
    $config['messages_cache_type'] = 'redis';
} else {
    $config['imap_cache'] = 'db';
}
$config['messages_cache'] = true;
$config['messages_cache_ttl'] = '10d';

// -- Security --
// Generate with: pwgen -s 24 1
// IMPORTANT: never change this after first deploy — it decrypts stored passwords
$config['des_key'] = getenv('ROUNDCUBE_DES_KEY') ?: 'change-me-24-byte-des-key!!';
$config['ip_check'] = false; // required for iframe/proxy setups
$config['x_frame_options'] = 'SAMEORIGIN';

// -- SSO --
// Shared with the Nextcloud roundcube app — must match ROUNDCUBE_SSO_SECRET env var on both sides
// Set via environment only, never hardcoded here

// -- Plugins --
$config['plugins'] = [
    'nextcloud_sso',
    'archive',
    'zipdownload',
    'managesieve',
];

// -- Skin --
$config['skin'] = 'avuz';

// -- UI / Locale --
$config['product_name'] = 'Conecta Mail';
$config['language'] = 'pt_BR';
$config['timezone'] = 'America/Sao_Paulo';
$config['draft_autosave'] = 60;
$config['show_images'] = 1;
$config['htmleditor'] = 1;
$config['mime_param_folding'] = 1;

// -- Privacy --
$config['support_url'] = '';
$config['display_version'] = false;

// -- Logging --
$config['log_driver'] = 'stdout';
$config['log_logins'] = true;
$config['log_session'] = false;
