<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required.'); }
verify_csrf();
$articleId = (int) ($_POST['article_id'] ?? 0); $action = (string) ($_POST['action'] ?? '');
$stmt = $pdo->prepare('SELECT * FROM articles WHERE id = ? LIMIT 1'); $stmt->execute([$articleId]); $article = $stmt->fetch();
if (!$article || !article_is_visible($article)) { http_response_code(404); exit('Article not found.'); }
$return = '/wiki/' . rawurlencode($article['slug']);
if ($action === 'like') {
    $check = $pdo->prepare('SELECT 1 FROM article_likes WHERE article_id = ? AND user_id = ?'); $check->execute([$articleId, current_user()['id']]);
    if ($check->fetchColumn()) { $pdo->prepare('DELETE FROM article_likes WHERE article_id = ? AND user_id = ?')->execute([$articleId, current_user()['id']]); $message = 'Appreciation removed.'; }
    else { $pdo->prepare('INSERT INTO article_likes (article_id, user_id) VALUES (?, ?)')->execute([$articleId, current_user()['id']]); $message = 'Thank you for appreciating this article.'; }
    $pdo->prepare('UPDATE articles SET likes = (SELECT COUNT(*) FROM article_likes WHERE article_id = ?) WHERE id = ?')->execute([$articleId, $articleId]);
    flash('success', $message); redirect($return);
}
if ($action === 'watch') {
    $check = $pdo->prepare('SELECT 1 FROM watchlist WHERE article_id = ? AND user_id = ?'); $check->execute([$articleId, current_user()['id']]);
    if ($check->fetchColumn()) { $pdo->prepare('DELETE FROM watchlist WHERE article_id = ? AND user_id = ?')->execute([$articleId, current_user()['id']]); flash('success', 'Removed from your watchlist.'); }
    else { $pdo->prepare('INSERT INTO watchlist (article_id, user_id) VALUES (?, ?)')->execute([$articleId, current_user()['id']]); flash('success', 'Added to your watchlist.'); }
    redirect($return);
}
if ($action === 'protect' || $action === 'unprotect') {
    if (!is_admin()) { http_response_code(403); exit('Administrator access required.'); }
    $activeProtection = active_article_protection($pdo, $articleId);
    if ($action === 'protect') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $days = max(0, min(3650, (int) ($_POST['expiry_days'] ?? 0)));
        if ($reason === '') { flash('error', 'A protection reason is required.'); redirect($return); }
        if ($activeProtection) { flash('warning', 'This article is already protected. Remove the existing protection before replacing it.'); redirect($return); }
        $expiresAt = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) : null;
        $pdo->prepare("INSERT INTO article_protections (article_id, protection_level, reason, protected_by, expires_at) VALUES (?, 'administrator', ?, ?, ?) ON DUPLICATE KEY UPDATE reason = VALUES(reason), protected_by = VALUES(protected_by), expires_at = VALUES(expires_at), updated_at = UTC_TIMESTAMP()")
            ->execute([$articleId, mb_substr($reason, 0, 500), current_user()['id'], $expiresAt]);
        $pdo->prepare("INSERT INTO protection_log (article_id, administrator_id, action, reason, expires_at) VALUES (?, ?, 'protect', ?, ?)")->execute([$articleId, current_user()['id'], mb_substr($reason, 0, 500), $expiresAt]);
        log_activity($pdo, 'article.protect', 'article', $articleId, ['reason' => $reason, 'expires_at' => $expiresAt]);
        flash('success', 'Article protection applied. Only administrators may edit or restore it.');
    } else {
        if (!$activeProtection) { flash('warning', 'This article is not currently protected.'); redirect($return); }
        $oldReason = $activeProtection['reason'];
        $pdo->prepare('DELETE FROM article_protections WHERE article_id = ?')->execute([$articleId]);
        $pdo->prepare("INSERT INTO protection_log (article_id, administrator_id, action, reason) VALUES (?, ?, 'unprotect', ?)")->execute([$articleId, current_user()['id'], $oldReason]);
        log_activity($pdo, 'article.unprotect', 'article', $articleId);
        flash('success', 'Article protection removed.');
    }
    redirect($return);
}
if ($action === 'rollback') {
    if (!can_edit_article($pdo, $article)) {
        flash('error', 'Only an administrator can restore revisions on this protected article.');
        redirect($return);
    }
    $revisionId = (int) ($_POST['revision_id'] ?? 0);
    $revisionStmt = $pdo->prepare('SELECT * FROM revisions WHERE id = ? AND article_id = ? LIMIT 1'); $revisionStmt->execute([$revisionId, $articleId]); $revision = $revisionStmt->fetch();
    if (!$revision) { flash('error', 'That revision was not found.'); redirect('/history/' . rawurlencode($article['slug'])); }
    try {
        $pdo->beginTransaction();
        $title = $revision['title'] ?: $article['title'];
        $pdo->prepare('UPDATE articles SET title = ?, content = ?, excerpt = ?, edit_count = edit_count + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$title, $revision['content'], excerpt($revision['content'], 220), $articleId]);
        $pdo->prepare('INSERT INTO revisions (article_id, user_id, title, content, edit_summary) VALUES (?, ?, ?, ?, ?)')->execute([$articleId, current_user()['id'], $title, $revision['content'], 'Restored revision ' . $revisionId]);
        sync_article_categories($pdo, $articleId, extract_categories($revision['content']));
        sync_search_document($pdo, $articleId);
        sync_article_links($pdo, $articleId, $revision['content']);
        $pdo->commit();
        log_activity($pdo, 'article.rollback', 'article', $articleId, ['revision_id' => $revisionId]);
        notify_indexnow([site_url($return)]); flash('success', 'The selected revision was restored as a new version.'); redirect($return);
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error', 'The revision could not be restored.'); redirect('/history/' . rawurlencode($article['slug'])); }
}
http_response_code(400); exit('Unknown action.');
