<?php
/**
 * Copy this file to config/local.php and add your hosting credentials.
 * Never commit config/local.php.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'banglaversewiki',
    'db_user' => 'database_user',
    'db_pass' => 'database_password',
    'site_url' => 'https://banglaversewiki.unaux.com',

    // ImgBB API key. Uploads are server-to-server; this is never sent to browsers.
    'imgbb_api_key' => 'paste-the-provided-imgbb-key-here',

    // Optional IndexNow key. See https://www.indexnow.org/documentation
    'indexnow_key' => '',
    'google_site_verification' => '2pHt_phns6GkO5b7NdMWpt9wJypEjRsfSnSC6YNvCjY',

    // Optional one-time administrator bootstrap. Remove these two values after the
    // account has been created, or create the first administrator at /setup.
    'admin_bootstrap_email' => '',
    // Preferred: a PASSWORD_BCRYPT/PASSWORD_DEFAULT hash generated offline.
    'admin_bootstrap_password_hash' => '',
    // Plaintext fallback for one-time manual installs. Remove it immediately.
    'admin_bootstrap_password' => '',
];
