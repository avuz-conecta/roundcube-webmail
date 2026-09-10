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
        'zoho'     => ['imap' => $proxyHost . ':143',              'smtp' => 'tls://smtppro.zoho.com:587'],
        'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143', 'smtp' => 'tls://mail.digrepal.com.br:587'],
    ];
    $config['password_hosts'] = [$proxyHost]; // storage_host becomes the sidecar name; only zoho users get the form
} else {
    // imappro/smtppro = Zoho's endpoints for paid ORGANIZATION accounts on hosted
    // custom domains (all Avuz tenants are org accounts). The consumer imap.zoho.com
    // host tolerates them but is not the documented path and throttles org traffic.
    $config['default_host']      = 'ssl://imappro.zoho.com';
    $config['default_port']      = 993;
    $config['imap_conn_options'] = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]];
    $config['avuz_providers']    = [
        'zoho'     => ['imap' => 'ssl://imappro.zoho.com:993',     'smtp' => 'tls://smtppro.zoho.com:587'],
        'digrepal' => ['imap' => 'tls://mail.digrepal.com.br:143', 'smtp' => 'tls://mail.digrepal.com.br:587'],
    ];
    $config['password_hosts']    = ['imappro.zoho.com'];
}
$config['imap_timeout'] = 15;

// Skip Roundcube's ID-based vendor detection. Zoho is none of the vendors
// Roundcube special-cases (cyrus/dovecot/gmail), so detection buys nothing — and
// Zoho's imappro endpoint answers the ID command with "BAD Invalid folder name",
// a wasted round-trip and error on every connection. Naming the vendor here makes
// get_vendor() return early without ever sending ID.
$config['imap_vendor'] = 'zoho';

// Disable ACL so users can rename their own folders. Zoho advertises the ACL
// capability but NOT RIGHTS (RFC 4314), so rcube_imap::folder_info() runs
// MYRIGHTS and falls into the legacy-ACL branch, which marks a folder norename
// unless its rights contain the old letter 'd'. Zoho actually returns RFC 4314
// rights — e.g. `lrswikxtea`, where 'x' IS delete-mailbox — so the user has full
// rename rights but Roundcube checks the wrong letter and greys out the name
// field (verified on the wire, MYRIGHTS "<folder>" lrswikxtea). With ACL off,
// folder_info skips MYRIGHTS and uses namespace: personal folders are renamable.
// Safe here — we don't load the acl plugin and this tenant has no folder sharing.
$config['imap_disabled_caps'] = ['ACL'];

// NOTE: skip_deleted is deliberately left at its default (false).
// Setting it true would enable ESEARCH on index queries (compact UID ranges
// instead of ~16,000 individual UIDs), but it was evaluated and rejected —
// see docs/superpowers/specs/2026-07-20-roundcube-search-latency-design.md.
// Short version: it hides any message flagged \Deleted from the list, counts
// AND search regardless of what set the flag, and the performance benefit is
// unproven (it also swaps cheap STATUS counts for live SEARCH, and can add an
// 'ALL UNDELETED NOT UID <set>' upload on warm list requests).

// -- SMTP (Zoho) --
$config['smtp_server'] = 'tls://smtppro.zoho.com';
$config['smtp_port'] = 587;
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['smtp_timeout'] = 15;

// -- Special folders — Zoho does NOT advertise SPECIAL-USE (capability capture
// 2026-07-22: IMAP4rev1 UNSELECT CHILDREN XLIST NAMESPACE IDLE MOVE ID AUTH=PLAIN
// SASL-IR AUTH=XOAUTH2 UIDPLUS ESEARCH LIST-EXTENDED LIST-STATUS WITHIN LITERAL-
// ACL CONDSTORE). Roundcube's per-user auto-detection in
// rcube_imap::get_special_folders() is gated on SPECIAL-USE, so it never runs for
// Zoho and these global names are what every Zoho user gets. They provision as
// localized pt_BR names; set them explicitly so Roundcube uses folders that exist
// instead of trying to CREATE "Drafts"/"Sent"/… (which fails: "Folder exists").
//
// Verified against all 55 cached Zoho folder lists on prod (2026-07-22): every
// account has Rascunho, Enviadas and Spam. "Junk", where present, is an EXTRA
// user-level folder sitting in the alphabetical block, not Zoho's spam folder —
// do not switch junk_mbox to it. Servers that do advertise SPECIAL-USE (the
// digrepal provider) auto-detect per user and ignore these values entirely.
// These are now FALLBACK-ONLY for Zoho: the nextcloud_sso plugin detects the
// real special folders per user via XLIST (Zoho advertises it) on storage
// connect and overrides these at runtime — so a pt_BR account gets
// Enviadas/Rascunho/Lixeira and an English one gets Sent/Drafts/Trash, each
// correct. These globals apply only if XLIST detection yields nothing. See
// applyXlistFolders() in plugins/nextcloud_sso and
// docs/superpowers/specs/2026-07-22-special-folder-detection-concern.md
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
    // messages_cache is DB-only in Roundcube (rcube_imap_cache uses the SQL handle);
    // the value is only tested for truthiness. 'db' = cache messages in the main DB.
    // Safe now that the DB is Postgres (concurrent writers, no whole-file lock).
    $config['messages_cache'] = 'db';
    // Sessions on Redis, NOT the SQLite DB. Symptom: HTML-signature images saved
    // 100% blank. Cause: the image upload records its temp-file ref via a session
    // write; with sessions on SQLite the write hit "database is locked" (session +
    // cache contend on one file), the ref was lost, and on save attach_images()
    // found no file → stripped the <img src> → white image. Redis has no such lock.
    $config['session_storage'] = 'redis';
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
    // 'managesieve' removed: Zoho offers no ManageSieve server or filter API, so
    // the Filters UI could only ever show a connection error. Replaced by
    // 'avuz_filters' (in-session filters applied over the user's own IMAP session).
    'avuz_filters',
    'password',
    'avuz_prefetch',
    'avuz_poll_scope',
    'avuz_search_notice',
    'avuz_body_cache',   // AVUZ: browser IndexedDB body cache (inert unless AVUZ_BODY_CACHE=1)
    'markasjunk',        // AVUZ: Junk / Not-Junk buttons — pure IMAP folder move (Zoho has no learning API)
    'avuz_calendar',
];

// -- Mark as Junk / Not Junk (markasjunk plugin) --
// Zoho exposes NO spam-learning API (same reason managesieve is gone), so we run
// the plugin's default driver as a pure folder move: Junk -> Spam, Not-Junk ->
// INBOX. Moving a message OUT of Spam into INBOX is the only signal Zoho's
// classifier trains on over IMAP; a hard trusted-sender whitelist still lives
// only in Zoho Mail settings and cannot be set from here.
$config['markasjunk_learning_driver'] = null;   // no engine — just move
$config['markasjunk_spam_mbox']  = 'Spam';       // Zoho's spam folder is 'Spam'
$config['markasjunk_ham_mbox']   = 'INBOX';
$config['markasjunk_move_spam']  = true;         // Junk button -> move to Spam
$config['markasjunk_move_ham']   = true;         // Not-Junk button -> move to INBOX
$config['markasjunk_read_spam']  = true;         // mark read when filing to Spam

// -- New-mail polling scope --
// Folders that can receive mail WITHOUT our filters putting it there, so they are
// the only ones worth polling. A prod survey of cache_index found these recur
// across many unrelated accounts (Spam 17 users, Newsletter 16, Notification 11,
// Junk 6) — they are Zoho's automatic classification folders, filled server-side
// at delivery without ever touching INBOX. Everything else is either a system
// folder that never receives unread mail or a folder the user made themselves,
// which our filters already report on when they file into it.
//
// 'Junk' is listed alongside 'Spam' as cheap insurance, NOT because Zoho names
// the spam folder differently per account — that was checked and is false. Every
// Zoho user here has 'Spam' in the system-folder block; where 'Junk' exists it is
// an extra user folder that coexists with it, never a replacement. It is kept in
// the list because we cannot see what fills it, and the cost of a folder a user
// does not have is zero: the list is intersected with subscribed folders.
// Matched on the last path segment, so a nested INBOX/Newsletter matches too.
// Capped at avuz_poll_folders::CAP.
$config['avuz_poll_folders'] = ['Spam', 'Junk', 'Newsletter', 'Notification'];

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
// email-domain -> AvuzConecta (Nextcloud) base URL, for calendar invite import
$avuz_nc = getenv('AVUZ_NC_INSTANCES');
$config['avuz_nc_instances'] = $avuz_nc ? (array) json_decode($avuz_nc, true) : [];

// -- Skin --
$config['skin'] = 'avuz';

// -- Sort: received-date only (AVUZ) --
// Force arrival (INTERNALDATE / "Data de recebimento"), newest-first, everywhere.
// Why: cross-folder search must ALWAYS take the pipelined path. run_pipelined()
// declines a header sort (date/subject/from/size) and falls back to the slow
// serial-per-folder search that then streams via progressive; 'arrival' is the
// one sort it never declines, and it still yields a globally chronological result
// (rcube_imap.php:1091 sorted branch -> sortHeaders by INTERNALDATE). Locking it
// via dont_override also hides the sort dropdown (skins/elastic/templates/mail.html:179)
// and disables column-header sorting (program/actions/mail/index.php:622), so users
// cannot pick a sort that would force search back onto the serial path. The other
// sort columns are near-useless for search results anyway.
$config['message_sort_col']   = 'arrival';
$config['message_sort_order'] = 'DESC';
$config['dont_override'] = ['skin', 'message_sort_col', 'message_sort_order'];
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
// mail_read_time (the real 1.6 option; preview_pane_mark_read is ignored). 0 =
// mark \Seen immediately on open (reliable — even quick glances mark read). A
// positive value defers N seconds but then quick switches never mark read. The
// STORE is only ~1 round-trip; the body is cached, so keep marking reliable.
$config['mail_read_time'] = 0;
$config['draft_autosave'] = 60;
$config['show_images'] = 1;
$config['htmleditor'] = 1;
$config['mime_param_folding'] = 1;
// Max size (KB) of images embedded in HTML signatures (stored as data URIs).
// Upstream default 64 truncates client logos/banners; doubled to 128.
$config['identity_image_size'] = 128;

// -- Privacy --
$config['support_url'] = '';
$config['display_version'] = false;

// -- Session lifetime (minutes) — default 10 is too short; keep users logged in
// through a workday. Roundcube also sets PHP session.gc_maxlifetime from this. --
$config['session_lifetime'] = 10080; // 1 week (7 * 24 * 60 min)

// -- Background refresh interval (seconds) --
// Was unset, so Roundcube used its 60s default. On prod that made `refresh` the
// single largest consumer of PHP worker time: 5519 requests averaging 4.46s,
// against a 30-worker pool that peaked at exactly 30. Each slow refresh is also
// a window in which a concurrent attachment upload can be lost (the session
// merge in rcube_session::_fixvars lets a long request write back its stale
// compose_data), so halving the poll rate narrows that exposure too.
// Cost: new-mail notification is up to 2 minutes late instead of 1.
$config['refresh_interval'] = 120;

// -- Search pacing --
// With progressive search (see docs/superpowers/specs/2026-07-23-progressive-search-design.md)
// this is the repaint interval, not just a safety cap: each round searches for
// this long, renders what it found, and asks the client to continue. Upstream
// hardcoded 60s, which meant the first rows could be a minute away. 8s trades a
// few more round trips for results that start appearing almost immediately.
$config['imap_search_timelimit'] = 8;

// Hard ceiling on a single logical search across all its continuation rounds.
// Without this the client loops forever (app.js re-issues every 100ms), which is
// the "search never finishes" complaint this work exists to fix. On reaching it
// the user is told the search was stopped and the results are partial.
$config['imap_search_total_timelimit'] = 120;

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
