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

function time_until(?string $date): string
{
    if (!$date) {
        return 'not scheduled';
    }
    $seconds = strtotime($date) - time();
    if ($seconds <= 0) {
        return 'due now';
    }
    $units = [86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $size => $label) {
        if ($seconds >= $size) {
            $count = (int) floor($seconds / $size);
            return 'in ' . $count . ' ' . $label . ($count === 1 ? '' : 's');
        }
    }
    return 'in less than a minute';
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

function active_article_protection(PDO $pdo, int $articleId): ?array
{
    $stmt = $pdo->prepare("SELECT p.*, u.username AS protected_by_name FROM article_protections p LEFT JOIN users u ON u.id = p.protected_by WHERE p.article_id = ? AND (p.expires_at IS NULL OR p.expires_at > UTC_TIMESTAMP()) LIMIT 1");
    $stmt->execute([$articleId]);
    return $stmt->fetch() ?: null;
}

function can_edit_article(PDO $pdo, array $article): bool
{
    return active_article_protection($pdo, (int) $article['id']) === null || is_admin();
}

function link_target_key(string $title): string
{
    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));
    return hash('sha256', $normalized);
}

function extract_internal_links(string $source): array
{
    preg_match_all('/\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|[^]]*)?\]\]/u', $source, $matches);
    $links = [];
    foreach ($matches[1] ?? [] as $title) {
        $title = trim($title);
        if ($title === '' || preg_match('/^(?:File|Image|চিত্র|Category|বিষয়শ্রেণী|Special|User|Talk):/iu', $title)) {
            continue;
        }
        $links[link_target_key($title)] = mb_substr($title, 0, 255);
    }
    return $links;
}

function sync_article_links(PDO $pdo, int $articleId, string $source): void
{
    $pdo->prepare('DELETE FROM article_links WHERE source_article_id = ?')->execute([$articleId]);
    $find = $pdo->prepare('SELECT id FROM articles WHERE LOWER(title) = LOWER(?) OR slug = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT IGNORE INTO article_links (source_article_id, target_article_id, target_title, target_key) VALUES (?, ?, ?, ?)');
    foreach (extract_internal_links($source) as $key => $title) {
        $find->execute([$title, slugify($title)]);
        $targetId = $find->fetchColumn();
        $insert->execute([$articleId, $targetId ? (int) $targetId : null, $title, $key]);
    }
    $titleStmt = $pdo->prepare('SELECT title FROM articles WHERE id = ?');
    $titleStmt->execute([$articleId]);
    if ($title = $titleStmt->fetchColumn()) {
        $pdo->prepare('UPDATE article_links SET target_article_id = ? WHERE target_article_id IS NULL AND target_key = ?')->execute([$articleId, link_target_key((string) $title)]);
    }
}

function attach_remote_import(PDO $pdo, int $importId, int $articleId, int $userId): void
{
    if ($importId < 1) {
        return;
    }
    $stmt = $pdo->prepare("SELECT source_url, source_title, source_license, source_host FROM remote_imports WHERE id = ? AND user_id = ? AND (status = 'ready' OR (status = 'consumed' AND article_id = ?)) LIMIT 1 FOR UPDATE");
    $stmt->execute([$importId, $userId, $articleId]);
    $import = $stmt->fetch();
    if (!$import) {
        return;
    }
    $attribution = 'Imported from ' . ($import['source_title'] ?: $import['source_host']) . ' (' . $import['source_host'] . ') under ' . ($import['source_license'] ?: 'the stated source license') . '.';
    $existing = $pdo->prepare('SELECT id FROM article_attributions WHERE article_id = ? AND source_url = ? LIMIT 1');
    $existing->execute([$articleId, $import['source_url']]);
    if (!$existing->fetchColumn()) {
        $insert = $pdo->prepare('INSERT INTO article_attributions (article_id, source_url, source_title, license_name, attribution_text) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$articleId, $import['source_url'], $import['source_title'], $import['source_license'], $attribution]);
    }
    $pdo->prepare("UPDATE remote_imports SET article_id = ?, status = 'consumed', completed_at = COALESCE(completed_at, UTC_TIMESTAMP()) WHERE id = ?")->execute([$articleId, $importId]);
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

function smart_search(PDO $pdo, string $query, string $scope = 'all', int $limit = 15, int $offset = 0, string $sort = 'relevance'): array
{
    $normalized = normalize_search_query($query);
    if ($normalized === '') {
        return ['results' => [], 'total' => 0, 'synonyms' => [], 'terms' => []];
    }
    $scope = in_array($scope, ['all', 'title', 'content'], true) ? $scope : 'all';
    $sort = in_array($sort, ['relevance', 'newest', 'popular'], true) ? $sort : 'relevance';
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);

    $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $lookupTerms = array_slice(array_values(array_unique([$normalized, ...$tokens])), 0, 8);
    $placeholders = implode(',', array_fill(0, count($lookupTerms), '?'));
    $synonymStmt = $pdo->prepare("SELECT term, synonym, weight FROM search_synonyms WHERE is_active = 1 AND (term IN ({$placeholders}) OR synonym IN ({$placeholders})) ORDER BY weight DESC LIMIT 12");
    $synonymStmt->execute([...$lookupTerms, ...$lookupTerms]);
    $synonyms = [];
    $termWeights = [];
    foreach ($synonymStmt->fetchAll() as $row) {
        $related = (string) (in_array($row['term'], $lookupTerms, true) ? $row['synonym'] : $row['term']);
        $synonyms[] = $related;
        $termWeights[$related] = max($termWeights[$related] ?? 0.0, max(0.1, min(2.0, (float) $row['weight'])));
    }
    $synonyms = array_values(array_unique(array_filter(array_map('strval', $synonyms))));
    foreach ($tokens as $token) {
        $termWeights[$token] = max($termWeights[$token] ?? 0.0, 1.0);
    }
    $terms = array_slice(array_keys($termWeights), 0, 8);
    if (!$terms) {
        $terms = [$normalized];
        $termWeights[$normalized] = 1.0;
    }

    $contains = '%' . $normalized . '%';
    $prefix = $normalized . '%';
    $scoreParts = [
        'CASE WHEN sd.normalized_title = ? THEN 1400 ELSE 0 END',
        'CASE WHEN sd.normalized_title LIKE ? THEN 620 ELSE 0 END',
        'CASE WHEN sd.normalized_title LIKE ? THEN 260 ELSE 0 END',
    ];
    $baseScoreParams = [$normalized, $prefix, $contains];
    $likeWhere = [];
    $likeWhereParams = [];
    foreach ($terms as $term) {
        $needle = '%' . $term . '%';
        $weight = max(0.1, min(2.0, (float) ($termWeights[$term] ?? 1.0)));
        $titleSignal = number_format(110 * $weight, 2, '.', '');
        $contentSignal = number_format(24 * $weight, 2, '.', '');
        if ($scope === 'title') {
            $scoreParts[] = 'CASE WHEN sd.normalized_title LIKE ? THEN ' . $titleSignal . ' ELSE 0 END';
            $baseScoreParams[] = $needle;
            $likeWhere[] = 'sd.normalized_title LIKE ?';
            $likeWhereParams[] = $needle;
        } elseif ($scope === 'content') {
            $scoreParts[] = 'CASE WHEN sd.body LIKE ? THEN ' . number_format(35 * $weight, 2, '.', '') . ' ELSE 0 END';
            $baseScoreParams[] = $needle;
            $likeWhere[] = 'sd.body LIKE ?';
            $likeWhereParams[] = $needle;
        } else {
            $scoreParts[] = '(CASE WHEN sd.normalized_title LIKE ? THEN ' . $titleSignal . ' ELSE 0 END + CASE WHEN sd.body LIKE ? THEN ' . $contentSignal . ' ELSE 0 END)';
            array_push($baseScoreParams, $needle, $needle);
            $likeWhere[] = '(sd.normalized_title LIKE ? OR sd.body LIKE ?)';
            array_push($likeWhereParams, $needle, $needle);
        }
    }
    $scoreParts[] = 'LN(a.views + 2) * 5 + a.likes * 4 + a.edit_count * .6 + sd.quality_score * .35 + sd.popularity_score * .08';
    $fallbackScoreSql = '(' . implode(' + ', $scoreParts) . ')';
    $fallbackWhereSql = '(' . implode(' OR ', $likeWhere) . ')';
    $scoreSql = $fallbackScoreSql;
    $whereSql = $fallbackWhereSql;
    $scoreParams = $baseScoreParams;
    $whereParams = $likeWhereParams;

    if ($scope === 'all') {
        $fulltext = trim($normalized . ' ' . implode(' ', $synonyms));
        $scoreSql = '(' . implode(' + ', $scoreParts) . ' + MATCH(sd.title, sd.normalized_title, sd.body) AGAINST (? IN NATURAL LANGUAGE MODE) * 85)';
        $scoreParams[] = $fulltext;
        $whereSql = '(MATCH(sd.title, sd.normalized_title, sd.body) AGAINST (? IN NATURAL LANGUAGE MODE) > 0 OR ' . implode(' OR ', $likeWhere) . ')';
        $whereParams = [$fulltext, ...$likeWhereParams];
    }

    $orderSql = match ($sort) {
        'newest' => 'a.updated_at DESC, search_score DESC',
        'popular' => 'a.views DESC, a.likes DESC, search_score DESC',
        default => 'search_score DESC, a.updated_at DESC',
    };
    $select = "SELECT a.id, a.title, a.slug, a.content, a.excerpt, a.views, a.likes, a.updated_at, sd.quality_score, sd.popularity_score, %s AS search_score,
        (SELECT c.name FROM article_categories ac JOIN categories c ON c.id = ac.category_id WHERE ac.article_id = a.id ORDER BY c.name LIMIT 1) AS category_name
        FROM search_documents sd JOIN articles a ON a.id = sd.article_id
        WHERE a.status = 'published' AND %s
        ORDER BY {$orderSql} LIMIT {$limit} OFFSET {$offset}";

    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM search_documents sd JOIN articles a ON a.id = sd.article_id WHERE a.status = 'published' AND {$whereSql}");
        $count->execute($whereParams);
        $stmt = $pdo->prepare(sprintf($select, $scoreSql, $whereSql));
        $stmt->execute([...$scoreParams, ...$whereParams]);
        return ['results' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn(), 'synonyms' => $synonyms, 'terms' => $terms];
    } catch (Throwable $exception) {
        error_log('Smart search fallback: ' . $exception->getMessage());
        $count = $pdo->prepare("SELECT COUNT(*) FROM search_documents sd JOIN articles a ON a.id = sd.article_id WHERE a.status = 'published' AND {$fallbackWhereSql}");
        $count->execute($likeWhereParams);
        $stmt = $pdo->prepare(sprintf($select, $fallbackScoreSql, $fallbackWhereSql));
        $stmt->execute([...$baseScoreParams, ...$likeWhereParams]);
        return ['results' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn(), 'synonyms' => $synonyms, 'terms' => $terms];
    }
}

function search_title_suggestions(PDO $pdo, string $query, int $limit = 5): array
{
    $normalized = normalize_search_query($query);
    if ($normalized === '') {
        return [];
    }
    $firstToken = preg_split('/\s+/u', $normalized, 2)[0] ?? $normalized;
    $stmt = $pdo->prepare("SELECT title, slug, views FROM articles WHERE status = 'published' AND (LOWER(title) LIKE ? OR LOWER(title) LIKE ?) ORDER BY views DESC, updated_at DESC LIMIT 60");
    $stmt->execute([$firstToken . '%', '%' . $firstToken . '%']);
    $candidates = [];
    foreach ($stmt->fetchAll() as $row) {
        $candidate = normalize_search_query((string) $row['title']);
        similar_text($normalized, $candidate, $similarity);
        if (str_starts_with($candidate, $normalized)) {
            $similarity += 35;
        }
        $row['similarity'] = $similarity + min(10, log10((int) $row['views'] + 1) * 2);
        $candidates[] = $row;
    }
    usort($candidates, static fn(array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
    return array_slice($candidates, 0, max(1, min(10, $limit)));
}

function search_result_excerpt(string $source, string $query, int $length = 230): string
{
    $clean = excerpt($source, max(1200, $length * 4));
    $terms = preg_split('/\s+/u', normalize_search_query($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $position = false;
    foreach ($terms as $term) {
        $found = mb_stripos($clean, $term);
        if ($found !== false && ($position === false || $found < $position)) {
            $position = $found;
        }
    }
    if ($position === false || mb_strlen($clean) <= $length) {
        return excerpt($clean, $length);
    }
    $start = max(0, (int) $position - (int) floor($length * .3));
    $snippet = trim(mb_substr($clean, $start, $length));
    return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($clean) > $start + $length ? '…' : '');
}

function resolve_search_destination(PDO $pdo, string $query): ?string
{
    $normalized = normalize_search_query($query);
    if ($normalized === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT a.slug FROM search_documents sd JOIN articles a ON a.id = sd.article_id WHERE a.status = 'published' AND (sd.normalized_title = ? OR a.slug = ?) ORDER BY a.views DESC LIMIT 1");
    $stmt->execute([$normalized, slugify($query)]);
    if ($slug = $stmt->fetchColumn()) {
        return (string) $slug;
    }
    $redirect = $pdo->prepare("SELECT a.slug FROM page_redirects pr JOIN articles a ON a.id = pr.target_article_id WHERE pr.source_slug = ? AND a.status = 'published' LIMIT 1");
    $redirect->execute([slugify($query)]);
    return ($slug = $redirect->fetchColumn()) ? (string) $slug : null;
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

function submit_indexnow_batch(array $urls): array
{
    $urls = array_slice(array_values(array_unique(array_filter($urls))), 0, 10000);
    if (INDEXNOW_KEY === '' || !$urls || !function_exists('curl_init')) {
        return ['success' => false, 'status' => 0, 'response' => 'IndexNow is unavailable.', 'count' => count($urls)];
    }
    $host = (string) parse_url(SITE_URL, PHP_URL_HOST);
    $payload = json_encode([
        'host' => $host,
        'key' => INDEXNOW_KEY,
        'keyLocation' => site_url('/indexnow-key.txt'),
        'urlList' => $urls,
    ], JSON_UNESCAPED_SLASHES);
    $curl = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = (string) curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [
        'success' => in_array($status, [200, 202], true),
        'status' => $status,
        'response' => mb_substr($error !== '' ? $error : $response, 0, 500),
        'count' => count($urls),
    ];
}

function notify_indexnow(array $urls): bool
{
    return (bool) submit_indexnow_batch($urls)['success'];
}

function run_bot_scheduler(PDO $pdo): void
{
    if (setting($pdo, 'bot_scheduler_enabled', '1') !== '1') {
        return;
    }
    try {
        $claim = $pdo->prepare("UPDATE scheduled_tasks SET locked_at = UTC_TIMESTAMP(), last_started_at = UTC_TIMESTAMP(), status = 'running', next_run_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL interval_seconds SECOND) WHERE task_name = 'bot_article_queue' AND next_run_at <= UTC_TIMESTAMP() AND (locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))");
        $claim->execute();
        if ($claim->rowCount() !== 1) {
            return;
        }
        require_once APP_ROOT . '/includes/BotService.php';
        $result = (new BotService($pdo))->runNext();
        $message = $result ? (string) $result['message'] : 'Queue checked; no due bot jobs.';
        $status = ($result['status'] ?? 'idle') === 'failed' ? 'failed' : 'idle';
        $finish = $pdo->prepare("UPDATE scheduled_tasks SET locked_at = NULL, last_finished_at = UTC_TIMESTAMP(), status = ?, last_message = ?, run_count = run_count + 1 WHERE task_name = 'bot_article_queue'");
        $finish->execute([$status, mb_substr($message, 0, 500)]);
    } catch (Throwable $exception) {
        error_log('Bot scheduler failed: ' . $exception->getMessage());
        try {
            $pdo->prepare("UPDATE scheduled_tasks SET locked_at = NULL, status = 'failed', last_message = ? WHERE task_name = 'bot_article_queue'")->execute([mb_substr($exception->getMessage(), 0, 500)]);
        } catch (Throwable) {
        }
    }
}

function run_traffic_scheduler(PDO $pdo): void
{
    run_bot_scheduler($pdo);
    if (INDEXNOW_KEY === '') {
        return;
    }
    try {
        $intervalMinutes = max(10, min(1440, (int) setting($pdo, 'indexnow_interval_minutes', '50')));
        $pdo->prepare("UPDATE scheduled_tasks SET interval_seconds = ? WHERE task_name = 'indexnow_full_refresh'")->execute([$intervalMinutes * 60]);
        $claim = $pdo->prepare("UPDATE scheduled_tasks
            SET locked_at = UTC_TIMESTAMP(), last_started_at = UTC_TIMESTAMP(), status = 'running',
                next_run_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL interval_seconds SECOND)
            WHERE task_name = 'indexnow_full_refresh' AND next_run_at <= UTC_TIMESTAMP()
              AND (locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))");
        $claim->execute();
        if ($claim->rowCount() !== 1) {
            return;
        }
        $started = gmdate('Y-m-d H:i:s');
        $urls = [site_url('/'), site_url('/community'), site_url('/policies'), site_url('/sitemap.xml'), site_url('/sitemap-images.xml'), site_url('/feed.xml'), site_url('/categories')];
        $articles = $pdo->query("SELECT slug FROM articles WHERE status = 'published' ORDER BY updated_at DESC LIMIT 9900")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($articles as $slug) {
            $urls[] = site_url('/wiki/' . rawurlencode((string) $slug));
        }
        $categories = $pdo->query('SELECT slug FROM categories ORDER BY id DESC LIMIT 90')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($categories as $slug) {
            $urls[] = site_url('/category/' . rawurlencode((string) $slug));
        }
        $result = submit_indexnow_batch($urls);
        $log = $pdo->prepare('INSERT INTO indexing_submissions (provider, url_count, status, http_status, response_excerpt, started_at, finished_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $log->execute(['indexnow', $result['count'], $result['success'] ? 'accepted' : 'failed', $result['status'] ?: null, $result['response'], $started]);
        $finish = $pdo->prepare("UPDATE scheduled_tasks SET locked_at = NULL, last_finished_at = UTC_TIMESTAMP(), status = ?, last_message = ?, run_count = run_count + 1 WHERE task_name = 'indexnow_full_refresh'");
        $finish->execute([$result['success'] ? 'idle' : 'failed', ($result['success'] ? 'Submitted ' : 'Failed to submit ') . $result['count'] . ' URLs (HTTP ' . $result['status'] . ').']);
    } catch (Throwable $exception) {
        error_log('Traffic scheduler failed: ' . $exception->getMessage());
        try {
            $stmt = $pdo->prepare("UPDATE scheduled_tasks SET locked_at = NULL, status = 'failed', last_message = ? WHERE task_name = 'indexnow_full_refresh'");
            $stmt->execute([mb_substr($exception->getMessage(), 0, 500)]);
        } catch (Throwable) {
            // The request must never fail because a background maintenance task failed.
        }
    }
}

function article_is_visible(array $article): bool
{
    if (($article['status'] ?? 'published') === 'published') {
        return true;
    }
    return is_logged_in() && ((int) ($article['author_id'] ?? 0) === (int) current_user()['id'] || can_moderate());
}
