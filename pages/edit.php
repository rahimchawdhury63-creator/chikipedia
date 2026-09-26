<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_login();

$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$stmt = $pdo->prepare('SELECT * FROM articles WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$article = $stmt->fetch();
if (!$article || !article_is_visible($article)) {
    flash('error', 'That article could not be found.');
    redirect('/');
}

$error = '';
$articleId = (int) $article['id'];
$articleSlug = $article['slug'];
$articleUpdatedAt = $article['updated_at'];
$sourceTitle = $article['title'];
$sourceContent = $article['content'];
$sourceSummary = '';
$sourceSeoTitle = $article['seo_title'] ?? '';
$sourceSeoDescription = $article['seo_description'] ?? '';
$canPublish = $article['status'] === 'published' || can_moderate() || setting($pdo, 'require_review', '0') !== '1';

if (!empty($_GET['draft'])) {
    $draftStmt = $pdo->prepare('SELECT * FROM drafts WHERE id = ? AND user_id = ? AND article_id = ? LIMIT 1');
    $draftStmt->execute([(int) $_GET['draft'], current_user()['id'], $articleId]);
    if ($draft = $draftStmt->fetch()) {
        $sourceTitle = $draft['title'];
        $sourceContent = $draft['content'];
        $sourceSummary = $draft['edit_summary'] ?? '';
        $sourceSeoTitle = $draft['seo_title'] ?? $sourceSeoTitle;
        $sourceSeoDescription = $draft['seo_description'] ?? $sourceSeoDescription;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $sourceTitle = trim((string) ($_POST['title'] ?? ''));
    $sourceContent = trim((string) ($_POST['content'] ?? ''));
    $sourceSummary = trim((string) ($_POST['edit_summary'] ?? ''));
    $sourceSeoTitle = mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 255);
    $sourceSeoDescription = mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 320);
    $action = $_POST['submit_action'] ?? 'draft';

    if (mb_strlen($sourceTitle) < 2 || mb_strlen($sourceTitle) > 255) {
        $error = 'Use a clear title between 2 and 255 characters.';
    } elseif ($sourceContent === '') {
        $error = 'Article content cannot be empty.';
    } elseif ($action === 'publish' && mb_strlen(strip_tags($sourceContent)) < 50) {
        $error = 'Please add a little more encyclopedic content before submitting.';
    }

    if ($error === '' && $action === 'draft') {
        $draftStmt = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ? AND article_id = ? ORDER BY updated_at DESC LIMIT 1');
        $draftStmt->execute([current_user()['id'], $articleId]);
        if ($draftId = $draftStmt->fetchColumn()) {
            $pdo->prepare('UPDATE drafts SET title = ?, content = ?, edit_summary = ?, seo_title = ?, seo_description = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$sourceTitle, $sourceContent, $sourceSummary, $sourceSeoTitle, $sourceSeoDescription, $draftId]);
        } else {
            $pdo->prepare('INSERT INTO drafts (user_id, article_id, title, content, edit_summary, seo_title, seo_description) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([current_user()['id'], $articleId, $sourceTitle, $sourceContent, $sourceSummary, $sourceSeoTitle, $sourceSeoDescription]);
        }
        flash('success', 'Your private draft was saved.');
        redirect('/drafts');
    }

    if ($error === '') {
        $freshStmt = $pdo->prepare('SELECT updated_at FROM articles WHERE id = ?');
        $freshStmt->execute([$articleId]);
        $freshUpdated = (string) $freshStmt->fetchColumn();
        if (($postedVersion = (string) ($_POST['article_updated_at'] ?? '')) !== '' && $freshUpdated !== $postedVersion) {
            $error = 'Edit conflict: someone changed this article after you opened it. Copy your changes, reload the page, and merge them with the newest version.';
        }
    }

    if ($error === '' && !can_moderate()) {
        $rate = $pdo->prepare('SELECT created_at FROM revisions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $rate->execute([current_user()['id']]);
        $lastEdit = $rate->fetchColumn();
        if ($lastEdit && time() - strtotime((string) $lastEdit) < 15) {
            $error = 'Please wait a few seconds between edits. Your changes remain in the editor.';
        }
    }

    if ($error === '') {
        $status = $article['status'] === 'published' ? 'published' : ($canPublish ? 'published' : 'pending');
        $summary = $sourceSummary ?: 'Improved article';
        preg_match('/\[\[(?:File|Image|চিত্র):\s*(https:\/\/[^\]|]+)/iu', $sourceContent, $imageMatch);
        $featuredImage = $imageMatch[1] ?? $article['featured_image'];
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE articles SET title = ?, content = ?, excerpt = ?, status = ?, featured_image = ?, seo_title = ?, seo_description = ?, edit_count = edit_count + 1, score = (views + likes * 6 + (edit_count + 1) * 2) / GREATEST(DATEDIFF(UTC_TIMESTAMP(), created_at) + 1, 1), published_at = COALESCE(published_at, ?), updated_at = UTC_TIMESTAMP() WHERE id = ?');
            $update->execute([$sourceTitle, $sourceContent, excerpt($sourceContent, 220), $status, $featuredImage, $sourceSeoTitle ?: null, $sourceSeoDescription ?: null, $status === 'published' ? gmdate('Y-m-d H:i:s') : null, $articleId]);
            $revision = $pdo->prepare('INSERT INTO revisions (article_id, user_id, title, content, edit_summary, is_minor) VALUES (?, ?, ?, ?, ?, ?)');
            $revision->execute([$articleId, current_user()['id'], $sourceTitle, $sourceContent, $summary, !empty($_POST['is_minor']) ? 1 : 0]);
            sync_article_categories($pdo, $articleId, extract_categories($sourceContent));
            sync_search_document($pdo, $articleId);
            $pdo->prepare('DELETE FROM drafts WHERE user_id = ? AND article_id = ?')->execute([current_user()['id'], $articleId]);
            $pdo->commit();
            log_activity($pdo, 'article.edited', 'article', $articleId, ['summary' => $summary]);
            if ($status === 'published') {
                notify_indexnow([site_url('/wiki/' . $articleSlug), site_url('/feed.xml')]);
                flash('success', 'Your changes are now live.');
                redirect('/wiki/' . rawurlencode($articleSlug));
            }
            flash('success', 'Your changes were submitted for moderator review.');
            redirect('/drafts');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Edit article failed: ' . $exception->getMessage());
            $error = 'We could not save your changes. Please try again.';
        }
    }
}

$page_title = 'Editing ' . $article['title'] . ' — ' . SITE_NAME;
$page_description = 'Edit the source of ' . $article['title'] . '.';
$editorTitle = 'Editing “' . $article['title'] . '”';
$formAction = '/edit/' . rawurlencode($articleSlug);
require APP_ROOT . '/includes/editor.php';
