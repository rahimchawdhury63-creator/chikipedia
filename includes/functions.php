<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function site_url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return rtrim(SITE_URL, '/') . ($path === '/' ? '/' : $path);
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return is_string($path) ? $path : '/';
}

function current_url(): string
{
    $query = $_SERVER['QUERY_STRING'] ?? '';
    return site_url(request_path()) . ($query !== '' ? '?' . $query : '');
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with(request_path(), '/api/');
}

function redirect(string $path, int $status = 302): never
{
    if (!preg_match('~^https?://~i', $path)) {
        $path = '/' . ltrim($path, '/');
    }
    header('Location: ' . $path, true, $status);
    exit;
}

function safe_redirect_target(?string $target, string $fallback = '/'): string
{
    if (!$target || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
        return $fallback;
    }
    return $target;
}

function slugify(string $title): string
{
    $title = trim($title);
    if ($title === '') {
        return 'untitled';
    }
    if (function_exists('transliterator_transliterate')) {
        $latin = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $title);
    } else {
        $latin = strtolower($title);
    }
    $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', (string) $latin);
    $slug = trim((string) $slug, '-');
    return mb_substr($slug !== '' ? $slug : rawurlencode($title), 0, 190);
}

function excerpt(string $source, int $length = 180): string
{
    $source = preg_replace('/\{\{.*?\}\}/s', ' ', $source) ?? $source;
    $source = preg_replace('/\[\[(?:File|Image|Category):.*?\]\]/isu', ' ', $source) ?? $source;
    $source = preg_replace('/\[\[([^]|]+)\|?([^]]*)\]\]/u', '$2 $1', $source) ?? $source;
    $source = preg_replace('/<[^>]+>/', ' ', $source) ?? $source;
    $source = preg_replace("/[={}|\\[\\]#*']+/", ' ', $source) ?? $source;
    $source = trim(preg_replace('/\s+/u', ' ', strip_tags($source)) ?? '');
    if (mb_strlen($source) <= $length) {
        return $source;
    }
    return rtrim(mb_substr($source, 0, $length - 1)) . '…';
}

function format_number(int|float|string|null $number): string
{
    return number_format((float) ($number ?? 0));
}

function word_count_unicode(string $text): int
{
    preg_match_all('/[\p{L}\p{N}]+/u', strip_tags($text), $matches);
    return count($matches[0] ?? []);
}

function time_ago(?string $date): string
{
    if (!$date) {
        return 'just now';
    }
    $seconds = max(0, time() - strtotime($date));
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $size => $label) {
        if ($seconds >= $size) {
            $count = (int) floor($seconds / $size);
            return $count . ' ' . $label . ($count === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        if (wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Your session expired. Refresh the page and try again.']);
        } else {
            echo 'Your session expired. Please go back, refresh, and try again.';
        }
        exit;
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($flashes) ? $flashes : [];
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']['id']);
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'] ?? 'editor', $roles, true);
}

function is_admin(): bool
{
    return has_role('administrator');
}

function can_moderate(): bool
{
    return has_role('administrator', 'moderator');
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('info', 'Please sign in to continue.');
        redirect('/login?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    }
    if ((current_user()['status'] ?? 'active') !== 'active') {
        session_destroy();
        http_response_code(403);
        exit('This account is not active.');
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Administrator access is required.');
    }
}

function require_moderator(): void
{
    require_login();
    if (!can_moderate()) {
        http_response_code(403);
        exit('Moderator access is required.');
    }
}

function refresh_session_user(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) {
        unset($_SESSION['user']);
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $stmt = $pdo->prepare('SELECT id, username, email, role, status, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['user']);
        return;
    }
    $_SESSION['username'] = $user['username'];
    $_SESSION['user'] = $user;
}

function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ip . '|' . SITE_URL);
}

function log_activity(PDO $pdo, string $action, ?string $entityType = null, ?int $entityId = null, array $metadata = []): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO activity_log (user_id, action, entity_type, entity_id, metadata, ip_hash) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            current_user()['id'] ?? null,
            mb_substr($action, 0, 100),
            $entityType,
            $entityId,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            client_ip_hash(),
        ]);
    } catch (Throwable $exception) {
        error_log('Activity log failed: ' . $exception->getMessage());
    }
}

function setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $cache[$key] = ($value === false ? $default : (string) $value);
    } catch (Throwable) {
        return $default;
    }
}

function save_setting(PDO $pdo, string $key, string $value, bool $public = true): void
{
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value, is_public) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_public = VALUES(is_public)');
    $stmt->execute([$key, $value, $public ? 1 : 0]);
}

function unique_slug(PDO $pdo, string $title, ?int $ignoreArticleId = null): string
{
    $base = slugify($title);
    $slug = $base;
    $suffix = 2;
    while (true) {
        $sql = 'SELECT id FROM articles WHERE slug = ?' . ($ignoreArticleId ? ' AND id != ?' : '') . ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $params = [$slug];
        if ($ignoreArticleId) {
            $params[] = $ignoreArticleId;
        }
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) {
            return $slug;
        }
        $slug = mb_substr($base, 0, 180) . '-' . $suffix++;
    }
}

function sync_article_categories(PDO $pdo, int $articleId, array $categoryNames): void
{
    $pdo->prepare('DELETE FROM article_categories WHERE article_id = ?')->execute([$articleId]);
    $insertCategory = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), id = LAST_INSERT_ID(id)');
    $link = $pdo->prepare('INSERT IGNORE INTO article_categories (article_id, category_id) VALUES (?, ?)');
    foreach (array_unique(array_filter(array_map('trim', $categoryNames))) as $name) {
        $insertCategory->execute([mb_substr($name, 0, 120), slugify($name)]);
        $link->execute([$articleId, (int) $pdo->lastInsertId()]);
    }
}

function extract_categories(string $source): array
{
    preg_match_all('/\[\[(?:Category|বিষয়শ্রেণী):\s*([^]|]+)(?:\|[^]]*)?\]\]/iu', $source, $matches);
    return array_values(array_unique(array_map('trim', $matches[1] ?? [])));
}

function normalize_search_query(string $query): string
{
    $query = mb_strtolower(trim($query));
    $query = preg_replace('/[^\p{L}\p{N}\s_-]+/u', ' ', $query) ?? $query;
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $query) ?? $query), 0, 190);
}

function sync_search_document(PDO $pdo, int $articleId): void
{
    $stmt = $pdo->prepare('SELECT title, content, excerpt, views, likes, edit_count, updated_at FROM articles WHERE id = ?');
    $stmt->execute([$articleId]);
    $article = $stmt->fetch();
    if (!$article) {
        $pdo->prepare('DELETE FROM search_documents WHERE article_id = ?')->execute([$articleId]);
        return;
    }
    $references = substr_count(mb_strtolower((string) $article['content']), '<ref');
    $lengthScore = min(30, word_count_unicode((string) $article['content']) / 35);
    $quality = min(100, $lengthScore + min(50, $references * 10));
    $popularity = log10((int) $article['views'] + 10) * 8 + (int) $article['likes'] * 4 + (int) $article['edit_count'];
    $upsert = $pdo->prepare("INSERT INTO search_documents (article_id, title, normalized_title, body, excerpt, language, quality_score, popularity_score, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), normalized_title = VALUES(normalized_title), body = VALUES(body), excerpt = VALUES(excerpt), language = VALUES(language), quality_score = VALUES(quality_score), popularity_score = VALUES(popularity_score), updated_at = VALUES(updated_at)");
    $upsert->execute([$articleId, $article['title'], normalize_search_query($article['title']), $article['content'], $article['excerpt'], SITE_LANGUAGE, $quality, $popularity, $article['updated_at']]);
}

function smart_search(PDO $pdo, string $query, string $scope = 'all', int $limit = 15, int $offset = 0): array
{
    $normalized = normalize_search_query($query);
    if ($normalized === '') {
        return ['results' => [], 'total' => 0, 'synonyms' => []];
    }
    $scope = in_array($scope, ['all', 'title', 'content'], true) ? $scope : 'all';
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $contains = '%' . $normalized . '%';
    $prefix = $normalized . '%';
    $synonymStmt = $pdo->prepare('SELECT synonym FROM search_synonyms WHERE term = ? AND is_active = 1 ORDER BY weight DESC LIMIT 4');
    $synonymStmt->execute([$normalized]);
    $synonyms = array_values(array_filter(array_map('strval', $synonymStmt->fetchAll(PDO::FETCH_COLUMN))));
    $fulltext = trim($normalized . ' ' . implode(' ', $synonyms));

    if ($scope === 'title') {
        $scoreSql = "(CASE WHEN sd.normalized_title = ? THEN 1200 ELSE 0 END + CASE WHEN sd.normalized_title LIKE ? THEN 500 ELSE 0 END + CASE WHEN sd.normalized_title LIKE ? THEN 220 ELSE 0 END + LN(a.views + 2) * 5 + a.likes * 4 + sd.quality_score * .25)";
        $whereSql = 'sd.normalized_title LIKE ?';
        $scoreParams = [$normalized, $prefix, $contains];
        $whereParams = [$contains];
    } elseif ($scope === 'content') {
        $scoreSql = '(CASE WHEN sd.body LIKE ? THEN 180 ELSE 0 END + LN(a.views + 2) * 5 + a.likes * 4 + sd.quality_score * .35)';
        $whereSql = 'sd.body LIKE ?';
        $scoreParams = [$contains];
        $whereParams = [$contains];
    } else {
        $scoreSql = "(CASE WHEN sd.normalized_title = ? THEN 1200 ELSE 0 END + CASE WHEN sd.normalized_title LIKE ? THEN 500 ELSE 0 END + CASE WHEN sd.normalized_title LIKE ? THEN 220 ELSE 0 END + MATCH(sd.title, sd.normalized_title, sd.body) AGAINST (? IN NATURAL LANGUAGE MODE) * 80 + LN(a.views + 2) * 5 + a.likes * 4 + a.edit_count * .6 + sd.quality_score * .35)";
        $whereSql = '(MATCH(sd.title, sd.normalized_title, sd.body) AGAINST (? IN NATURAL LANGUAGE MODE) > 0 OR sd.normalized_title LIKE ? OR sd.body LIKE ?)';
        $scoreParams = [$normalized, $prefix, $contains, $fulltext];
        $whereParams = [$fulltext, $contains, $contains];
    }

    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM search_documents sd JOIN articles a ON a.id = sd.article_id WHERE a.status = 'published' AND {$whereSql}");
        $count->execute($whereParams);
        $total = (int) $count->fetchColumn();
        $sql = "SELECT a.title, a.slug, a.content, a.excerpt, a.views, a.likes, a.updated_at, sd.quality_score, {$scoreSql} AS search_score,
            (SELECT c.name FROM article_categories ac JOIN categories c ON c.id = ac.category_id WHERE ac.article_id = a.id ORDER BY c.name LIMIT 1) AS category_name
            FROM search_documents sd JOIN articles a ON a.id = sd.article_id
            WHERE a.status = 'published' AND {$whereSql}
            ORDER BY search_score DESC, a.updated_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$scoreParams, ...$whereParams]);
        return ['results' => $stmt->fetchAll(), 'total' => $total, 'synonyms' => $synonyms];
    } catch (Throwable $exception) {
        error_log('Smart search fallback: ' . $exception->getMessage());
        $fallback = $pdo->prepare("SELECT title, slug, content, excerpt, views, likes, updated_at, 0 AS quality_score,
            (CASE WHEN LOWER(title) = ? THEN 1200 WHEN LOWER(title) LIKE ? THEN 500 WHEN title LIKE ? THEN 220 ELSE 20 END) AS search_score,
            NULL AS category_name
            FROM articles WHERE status = 'published' AND (title LIKE ? OR content LIKE ?)
            ORDER BY search_score DESC, views DESC, updated_at DESC LIMIT {$limit} OFFSET {$offset}");
        $fallback->execute([$normalized, $prefix, $contains, $contains, $contains]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE status = 'published' AND (title LIKE ? OR content LIKE ?)");
        $count->execute([$contains, $contains]);
        return ['results' => $fallback->fetchAll(), 'total' => (int) $count->fetchColumn(), 'synonyms' => $synonyms];
    }
}

function record_search_query(PDO $pdo, string $query, int $resultCount): void
{
    $agent = mb_strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('/bot|crawler|spider|slurp/', $agent)) {
        return;
    }
    $normalized = normalize_search_query($query);
    if ($normalized === '') {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO search_queries (query_text, normalized_query, query_hash, result_count, session_hash) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([mb_substr(trim($query), 0, 190), $normalized, hash('sha256', $normalized), max(0, $resultCount), hash('sha256', session_id())]);
}

function notify_indexnow(array $urls): bool
{
    if (INDEXNOW_KEY === '' || !$urls || !function_exists('curl_init')) {
        return false;
    }
    $host = parse_url(SITE_URL, PHP_URL_HOST);
    $payload = json_encode([
        'host' => $host,
        'key' => INDEXNOW_KEY,
        'keyLocation' => site_url('/indexnow-key.txt'),
        'urlList' => array_values(array_unique($urls)),
    ], JSON_UNESCAPED_SLASHES);
    $curl = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return in_array($status, [200, 202], true);
}

function article_is_visible(array $article): bool
{
    if (($article['status'] ?? 'published') === 'published') {
        return true;
    }
    return is_logged_in() && ((int) ($article['author_id'] ?? 0) === (int) current_user()['id'] || can_moderate());
}
