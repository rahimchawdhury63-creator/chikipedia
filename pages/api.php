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
    $alternatives = $search['total'] < 3 ? array_map(static fn(array $row): array => ['title' => $row['title'], 'slug' => $row['slug']], search_title_suggestions($pdo, $query, 4)) : [];
    echo json_encode(['results' => $results, 'total' => $search['total'], 'suggestions' => $alternatives], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

if ($action === 'import-article') {
    if (setting($pdo, 'remote_import_enabled', '1') !== '1') {
        http_response_code(403);
        echo json_encode(['error' => 'Remote imports are disabled by an administrator.']);
        exit;
    }
    if (empty($_POST['rights_confirmed'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Confirm that the source license permits reuse.']);
        exit;
    }
    $rate = $pdo->prepare('SELECT COUNT(*) FROM remote_imports WHERE user_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
    $rate->execute([current_user()['id']]);
    if ((int) $rate->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode(['error' => 'Remote import limit reached. Try again later.']);
        exit;
    }
    require_once APP_ROOT . '/includes/ImportService.php';
    try {
        $result = (new ImportService($pdo))->importArticle((string) ($_POST['source_url'] ?? ''), (string) ($_POST['license'] ?? ''), (int) current_user()['id']);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (EncyclopediaImportException $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'import-image') {
    if (setting($pdo, 'remote_import_enabled', '1') !== '1' || empty($_POST['rights_confirmed'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Confirm the image reuse rights before importing.']);
        exit;
    }
    $rate = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'image.remote_import_requested' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)");
    $rate->execute([current_user()['id']]);
    if ((int) $rate->fetchColumn() >= 10) {
        http_response_code(429);
        echo json_encode(['error' => 'Image transfer limit reached. Wait one minute and try again.']);
        exit;
    }
    require_once APP_ROOT . '/includes/ImageService.php';
    $sourceUrl = (string) ($_POST['source_url'] ?? '');
    log_activity($pdo, 'image.remote_import_requested', 'image', null, ['host' => parse_url($sourceUrl, PHP_URL_HOST)]);
    try {
        $license = in_array($_POST['license'] ?? '', ['CC BY-SA 4.0', 'CC BY-SA 3.0', 'CC0 / Public domain', 'Permission obtained', 'Reuse rights confirmed by editor'], true) ? (string) $_POST['license'] : 'Reuse rights confirmed by editor';
        $result = (new ImageService($pdo))->importRemote($sourceUrl, (string) ($_POST['alt_text'] ?? 'Imported encyclopedia image'), [
            'source_page_url' => (string) ($_POST['source_page_url'] ?? ''),
            'attribution' => (string) ($_POST['attribution'] ?? ''),
            'license_name' => $license,
        ]);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (ImageUploadException|RemoteFetchException $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        error_log('Remote image import failed: ' . $exception->getMessage());
        http_response_code(502);
        echo json_encode(['error' => 'The remote image could not be transferred to ImgBB.']);
    }
    exit;
}

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
    $remoteImportId = max(0, (int) ($_POST['remote_import_id'] ?? 0));
    $articleId = max(0, (int) ($_POST['article_id'] ?? 0));
    if (strlen($content) > 2 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'The draft is too large to autosave.']);
        exit;
    }
    if ($articleId > 0) {
        $articleStmt = $pdo->prepare('SELECT * FROM articles WHERE id = ? LIMIT 1');
        $articleStmt->execute([$articleId]);
        $autosaveArticle = $articleStmt->fetch();
        if (!$autosaveArticle) {
            http_response_code(404);
            echo json_encode(['error' => 'Article not found.']);
            exit;
        }
        if (!can_edit_article($pdo, $autosaveArticle)) {
            http_response_code(423);
            echo json_encode(['error' => 'This article is administrator-protected.']);
            exit;
        }
        $draftStmt = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ? AND article_id = ? ORDER BY updated_at DESC LIMIT 1');
        $draftStmt->execute([current_user()['id'], $articleId]);
    } else {
        $draftStmt = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ? AND article_id IS NULL ORDER BY updated_at DESC LIMIT 1');
        $draftStmt->execute([current_user()['id']]);
    }
    if ($draftId = $draftStmt->fetchColumn()) {
        $pdo->prepare('UPDATE drafts SET title = ?, content = ?, edit_summary = ?, seo_title = ?, seo_description = ?, remote_import_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ?')->execute([$title, $content, $summary, $seoTitle, $seoDescription, $remoteImportId ?: null, $draftId, current_user()['id']]);
    } else {
        $pdo->prepare('INSERT INTO drafts (user_id, article_id, title, content, edit_summary, seo_title, seo_description, remote_import_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([current_user()['id'], $articleId ?: null, $title, $content, $summary, $seoTitle, $seoDescription, $remoteImportId ?: null]);
        $draftId = $pdo->lastInsertId();
    }
    echo json_encode(['saved' => true, 'draft_id' => (int) $draftId, 'saved_at' => gmdate('c')]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown API action.']);
