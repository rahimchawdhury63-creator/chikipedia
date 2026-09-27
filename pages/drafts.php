<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'delete_draft') {
        $pdo->prepare('DELETE FROM drafts WHERE id = ? AND user_id = ?')->execute([(int) ($_POST['draft_id'] ?? 0), current_user()['id']]);
        flash('success', 'Draft discarded.'); redirect('/drafts');
    }
}
$draftStmt = $pdo->prepare('SELECT d.*, a.title AS article_title, a.slug AS article_slug FROM drafts d LEFT JOIN articles a ON a.id = d.article_id WHERE d.user_id = ? ORDER BY d.updated_at DESC'); $draftStmt->execute([current_user()['id']]); $drafts = $draftStmt->fetchAll();
$pendingStmt = $pdo->prepare("SELECT title, slug, status, updated_at FROM articles WHERE author_id = ? AND status IN ('draft','pending') ORDER BY updated_at DESC"); $pendingStmt->execute([current_user()['id']]); $pending = $pendingStmt->fetchAll();
$watchStmt = $pdo->prepare("SELECT a.title, a.slug, a.updated_at, a.edit_count FROM watchlist w JOIN articles a ON a.id = w.article_id WHERE w.user_id = ? AND a.status = 'published' ORDER BY a.updated_at DESC"); $watchStmt->execute([current_user()['id']]); $watched = $watchStmt->fetchAll();
$page_title = 'My workspace — ' . SITE_NAME; $page_robots = 'noindex,nofollow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Editor workspace</span><h1>Drafts &amp; watchlist</h1><p>Continue private drafts, check review status, and follow pages you care about.</p></header>
<div class="admin-two-column">
<section class="panel"><div class="panel-header"><h2>Private drafts</h2><a class="button button-primary" href="/create">New article</a></div><?php if ($drafts): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Draft</th><th>Last saved</th><th></th></tr></thead><tbody><?php foreach ($drafts as $draft): ?><tr><td><a href="<?= $draft['article_id'] ? '/edit/' . e($draft['article_slug']) . '?draft=' . (int) $draft['id'] : '/create?draft=' . (int) $draft['id'] ?>"><strong><?= e($draft['title'] ?: 'Untitled draft') ?></strong></a><br><small><?= e(excerpt($draft['content'], 90)) ?></small></td><td><?= e(time_ago($draft['updated_at'])) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_draft"><input type="hidden" name="draft_id" value="<?= (int) $draft['id'] ?>"><button class="button button-quiet" type="submit" data-confirm="Discard this private draft?">Discard</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">No private drafts. The editor autosaves here while you work.</div><?php endif; ?></section>
<aside><section class="panel"><div class="panel-header"><h2>Under review</h2></div><div class="panel-body"><?php if ($pending): ?><nav class="filter-list"><?php foreach ($pending as $item): ?><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong><br><span class="badge badge-<?= e($item['status']) ?>"><?= e($item['status']) ?></span> <small><?= e(time_ago($item['updated_at'])) ?></small></a><?php endforeach; ?></nav><?php else: ?><p class="form-help">No articles are waiting for review.</p><?php endif; ?></div></section></aside>
</div>
<section class="panel" style="margin-top:22px"><div class="panel-header"><h2>Watchlist</h2><span class="badge"><?= count($watched) ?> pages</span></div><?php if ($watched): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Article</th><th>Updated</th><th>Edits</th></tr></thead><tbody><?php foreach ($watched as $item): ?><tr><td><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong></a></td><td><?= e(time_ago($item['updated_at'])) ?></td><td><?= format_number($item['edit_count']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">Watch an article from its Tools panel to follow it here.</div><?php endif; ?></section>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
