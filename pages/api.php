<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? '');

if ($action === 'search') {
    $query = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($query) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    $search = smart_search($pdo, $query, 'all', 8, 0);
    $results = array_map(static fn(array $row): array => [
        'title' => $row['title'], 'slug' => $row['slug'],
        'excerpt' => excerpt($row['excerpt'] ?: $row['content'], 95),
        'category' => $row['category_name'] ?? null,
    ], $search['results']);
    echo json_encode(['results' => $results, 'total' => $search['total']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST is required.']);
    exit;
}
verify_csrf();

if ($action === 'preview') {
    require_once APP_ROOT . '/includes/WikiParser.php';
    $content = (string) ($_POST['content'] ?? '');
    if (strlen($content) > 2 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'The preview is too large.']);
        exit;
    }
    $parsed = (new WikiParser())->parse($content);
    echo json_encode(['html' => $parsed['toc'] . $parsed['html']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'autosave') {
    $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255);
    $content = (string) ($_POST['content'] ?? '');
    $summary = mb_substr(trim((string) ($_POST['edit_summary'] ?? '')), 0, 255);
    $seoTitle = mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 255);
    $seoDescription = mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 320);
    $articleId = max(0, (int) ($_POST['article_id'] ?? 0));
    if (strlen($content) > 2 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'The draft is too large to autosave.']);
        exit;
    }
    if ($articleId > 0) {
        $articleStmt = $pdo->prepare('SELECT id FROM articles WHERE id = ? LIMIT 1');
        $articleStmt->execute([$articleId]);
        if (!$articleStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['error' => 'Article not found.']);
            exit;
        }
        $draftStmt = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ? AND article_id = ? ORDER BY updated_at DESC LIMIT 1');
        $draftStmt->execute([current_user()['id'], $articleId]);
    } else {
        $draftStmt = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ? AND article_id IS NULL ORDER BY updated_at DESC LIMIT 1');
        $draftStmt->execute([current_user()['id']]);
    }
    if ($draftId = $draftStmt->fetchColumn()) {
        $pdo->prepare('UPDATE drafts SET title = ?, content = ?, edit_summary = ?, seo_title = ?, seo_description = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')->execute([$title, $content, $summary, $seoTitle, $seoDescription, $draftId, current_user()['id']]);
    } else {
        $pdo->prepare('INSERT INTO drafts (user_id, article_id, title, content, edit_summary, seo_title, seo_description) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([current_user()['id'], $articleId ?: null, $title, $content, $summary, $seoTitle, $seoDescription]);
        $draftId = $pdo->lastInsertId();
    }
    echo json_encode(['saved' => true, 'draft_id' => (int) $draftId, 'saved_at' => gmdate('c')]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown API action.']);
