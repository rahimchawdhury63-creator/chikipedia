<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_login();

$error = '';
$articleId = 0;
$articleSlug = '';
$articleUpdatedAt = '';
$sourceTitle = trim((string) ($_GET['title'] ?? ''));
$sourceContent = '';
$sourceSummary = '';
$sourceSeoTitle = '';
$sourceSeoDescription = '';
$sourceImportId = 0;

if (!empty($_GET['draft'])) {
    $draftStmt = $pdo->prepare('SELECT * FROM drafts WHERE id = ? AND user_id = ? LIMIT 1');
    $draftStmt->execute([(int) $_GET['draft'], current_user()['id']]);
    if ($draft = $draftStmt->fetch()) {
        $sourceTitle = $draft['title'];
        $sourceContent = $draft['content'];
        $sourceSummary = $draft['edit_summary'] ?? '';
        $sourceSeoTitle = $draft['seo_title'] ?? '';
        $sourceSeoDescription = $draft['seo_description'] ?? '';
        $sourceImportId = (int) ($draft['remote_import_id'] ?? 0);
    }
}

$canPublish = can_moderate() || setting($pdo, 'require_review', '0') !== '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $sourceTitle = trim((string) ($_POST['title'] ?? ''));
    $sourceContent = trim((string) ($_POST['content'] ?? ''));
    $sourceSummary = trim((string) ($_POST['edit_summary'] ?? ''));
    $sourceSeoTitle = mb_substr(trim((string) ($_POST['seo_title'] ?? '')), 0, 255);
    $sourceSeoDescription = mb_substr(trim((string) ($_POST['seo_description'] ?? '')), 0, 320);
    $sourceImportId=max(0,(int)($_POST['remote_import_id']??0));
    $action=in_array($_POST['submit_action']??'', ['draft','publish'],true)?$_POST['submit_action']:'draft';
    $verifiedImport=null;
    if($sourceImportId>0){$importCheck=$pdo->prepare("SELECT source_url,source_host,source_license,source_revision,source_revision_timestamp FROM remote_imports WHERE id=? AND user_id=? AND status='ready'");$importCheck->execute([$sourceImportId,current_user()['id']]);$verifiedImport=$importCheck->fetch();if(!$verifiedImport)$error='The imported source record is unavailable or does not belong to this account.';elseif(!preg_match('/\{\{Imported\|/i',$sourceContent)){$safeUrl=str_replace([']','[',' '],['%5D','%5B','%20'],$verifiedImport['source_url']);$revision=trim((string)$verifiedImport['source_revision']).($verifiedImport['source_revision_timestamp']?' @ '.$verifiedImport['source_revision_timestamp'].' UTC':'');$sourceContent='{{Imported|'.$safeUrl.'|'.str_replace('|','—',$verifiedImport['source_host']).'|'.str_replace('|','—',$verifiedImport['source_license']?:'source license').'|'.str_replace('|','—',$revision)."}}\n\n".$sourceContent;}}

    if($error===''&&(mb_strlen($sourceTitle)<2||mb_strlen($sourceTitle)>255)){
        $error='Use a clear title between 2 and 255 characters.';
    }elseif($error===''&&$sourceContent===''){
        $error='Article content cannot be empty.';
    }elseif($error===''&&$action==='publish'&&mb_strlen(strip_tags($sourceContent))<50){
        $error='Please add a little more reliable, encyclopedic content before submitting.';
    }elseif($error===''){
        $duplicate = $pdo->prepare('SELECT slug FROM articles WHERE LOWER(title) = LOWER(?) LIMIT 1');
        $duplicate->execute([$sourceTitle]);
        if ($existingSlug = $duplicate->fetchColumn()) {
            $error = 'An article with this title already exists. Please improve the existing page instead.';
        }
    }

    if ($error === '') {
        if ($action === 'draft') {
            $latest = $pdo->prepare('SELECT id FROM drafts WHERE user_id = ? AND article_id IS NULL ORDER BY updated_at DESC LIMIT 1');
            $latest->execute([current_user()['id']]);
            if ($draftId = $latest->fetchColumn()) {
                $pdo->prepare('UPDATE drafts SET title = ?, content = ?, edit_summary = ?, seo_title = ?, seo_description = ?, remote_import_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$sourceTitle, $sourceContent, $sourceSummary, $sourceSeoTitle, $sourceSeoDescription, $sourceImportId ?: null, $draftId]);
            } else {
                $pdo->prepare('INSERT INTO drafts (user_id, title, content, edit_summary, seo_title, seo_description, remote_import_id) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([current_user()['id'], $sourceTitle, $sourceContent, $sourceSummary, $sourceSeoTitle, $sourceSeoDescription, $sourceImportId ?: null]);
            }
            flash('success', 'Draft saved. Only you can see it.');
            redirect('/drafts');
        }

        $slug = unique_slug($pdo, $sourceTitle);
        // Licensed imports always enter the review queue, even for privileged importers.
        $status=$sourceImportId>0?'pending':($canPublish?'published':'pending');
        $summary = $sourceSummary ?: 'Created article';
        $description = excerpt($sourceContent, 220);
        preg_match('/\[\[(?:File|Image|চিত্র):\s*(https:\/\/[^\]|]+)/iu', $sourceContent, $imageMatch);
        $featuredImage = $imageMatch[1] ?? null;

        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO articles (title, slug, content, excerpt, status, author_id, featured_image, seo_title, seo_description, edit_count, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
            $insert->execute([$sourceTitle, $slug, $sourceContent, $description, $status, current_user()['id'], $featuredImage, $sourceSeoTitle ?: null, $sourceSeoDescription ?: null, $status === 'published' ? gmdate('Y-m-d H:i:s') : null]);
            $articleId = (int) $pdo->lastInsertId();
            $revision = $pdo->prepare('INSERT INTO revisions (article_id, user_id, title, content, edit_summary, is_minor) VALUES (?, ?, ?, ?, ?, ?)');
            $revision->execute([$articleId, current_user()['id'], $sourceTitle, $sourceContent, $summary, !empty($_POST['is_minor']) ? 1 : 0]);
            sync_article_categories($pdo, $articleId, extract_categories($sourceContent));
            sync_search_document($pdo, $articleId);
            sync_article_links($pdo, $articleId, $sourceContent);
            attach_remote_import($pdo, $sourceImportId, $articleId, (int) current_user()['id']);
            $pdo->prepare('DELETE FROM drafts WHERE user_id = ? AND article_id IS NULL')->execute([current_user()['id']]);
            $pdo->commit();
            log_activity($pdo, $status === 'published' ? 'article.created' : 'article.submitted', 'article', $articleId, ['title' => $sourceTitle]);
            if ($status === 'published') {
                notify_indexnow([site_url('/wiki/' . $slug), site_url('/'), site_url('/feed.xml')]);
                flash('success', 'Your article is live. Thank you for expanding free knowledge.');
                redirect('/wiki/' . rawurlencode($slug));
            }
            flash('success', 'Your article was submitted for review. A moderator can now publish it.');
            redirect('/drafts');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Create article failed: ' . $exception->getMessage());
            $error = 'We could not save the article. Please try again; your browser copy is still available.';
        }
    }
}

$page_title = 'Create an article — ' . SITE_NAME;
$page_description = 'Contribute a well-sourced article to ' . SITE_NAME . '.';
$editorTitle = 'Create a new article';
$formAction = '/create';
require APP_ROOT . '/includes/editor.php';
