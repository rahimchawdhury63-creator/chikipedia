<?php
declare(strict_types=1);

/**
 * BanglaVerseWiki application bootstrap.
 * Secrets belong in environment variables or config/local.php, never in Git.
 */

define('APP_ROOT', dirname(__DIR__));
// Store and calculate all server timestamps in UTC; browsers localize display.
date_default_timezone_set('UTC');

$local = [];
$localFile = __DIR__ . '/local.php';
if (is_file($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

$getConfig = static function (string $environment, string $localKey, ?string $default = null) use ($local): ?string {
    $value = getenv($environment);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if (isset($local[$localKey]) && $local[$localKey] !== '') {
        return (string) $local[$localKey];
    }
    return $default;
};

define('SITE_NAME', $getConfig('SITE_NAME', 'site_name', 'BanglaVerseWiki'));
define('SITE_URL', rtrim((string) $getConfig('SITE_URL', 'site_url', 'https://banglaversewiki.unaux.com'), '/'));
define('SITE_LANGUAGE', $getConfig('SITE_LANGUAGE', 'site_language', 'bn'));
define('IMGBB_API_KEY', (string) $getConfig('IMGBB_API_KEY', 'imgbb_api_key', ''));
// IndexNow ownership keys are public by design and are served at /indexnow-key.txt.
define('INDEXNOW_KEY', (string) $getConfig('INDEXNOW_KEY', 'indexnow_key', 'dc7088dee401ff0786e6239cbfec3b92'));
define('GOOGLE_SITE_VERIFICATION', (string) $getConfig('GOOGLE_SITE_VERIFICATION', 'google_site_verification', '2pHt_phns6GkO5b7NdMWpt9wJypEjRsfSnSC6YNvCjY'));
define('ADMIN_BOOTSTRAP_EMAIL', (string) $getConfig('ADMIN_BOOTSTRAP_EMAIL', 'admin_bootstrap_email', ''));
define('ADMIN_BOOTSTRAP_PASSWORD', (string) $getConfig('ADMIN_BOOTSTRAP_PASSWORD', 'admin_bootstrap_password', ''));
define('ADMIN_BOOTSTRAP_PASSWORD_HASH', (string) $getConfig('ADMIN_BOOTSTRAP_PASSWORD_HASH', 'admin_bootstrap_password_hash', ''));
define('FRESH_CONTENT_RESET', (string) $getConfig('FRESH_CONTENT_RESET', 'fresh_content_reset', '0'));

if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('bvw_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once APP_ROOT . '/includes/functions.php';

$dbHost = (string) $getConfig('DB_HOST', 'db_host', 'localhost');
$dbPort = (string) $getConfig('DB_PORT', 'db_port', '3306');
$dbName = (string) $getConfig('DB_NAME', 'db_name', 'banglaversewiki');
$dbUser = (string) $getConfig('DB_USER', 'db_user', 'root');
$dbPass = (string) $getConfig('DB_PASS', 'db_pass', '');
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $exception) {
    error_log('BanglaVerseWiki database error: ' . $exception->getMessage());
    http_response_code(503);
    if (wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'The knowledge base is temporarily unavailable.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Service unavailable</title><style>body{font-family:system-ui;max-width:42rem;margin:12vh auto;padding:1.5rem;color:#202122}h1{font-family:Georgia,serif}a{color:#36c}</style><h1>BanglaVerseWiki is temporarily unavailable</h1><p>We could not connect to the database. Please check the deployment configuration and try again.</p><p><a href="/">Try again</a></p></html>';
    }
    exit;
}

require_once APP_ROOT . '/includes/migrations.php';
run_migrations($pdo);
reset_content_if_requested($pdo);
bootstrap_administrator($pdo);
refresh_session_user($pdo);

// Shared-hosting scheduler: due maintenance runs after a normal request, so no
// daemon, worker, or server cron is required. A database lease prevents overlap.
register_shutdown_function(static function () use ($pdo): void {
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    run_traffic_scheduler($pdo);
});
