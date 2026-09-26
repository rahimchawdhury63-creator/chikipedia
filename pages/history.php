<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$stmt = $pdo->prepare('SELECT * FROM articles WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$article = $stmt->fetch();
if (!$article || !article_is_visible($article)) { http_response_code(404); exit('Article not found.'); }
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50; $offset = ($page - 1) * $perPage;
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM revisions WHERE article_id = ?'); $countStmt->execute([$article['id']]); $count = (int) $countStmt->fetchColumn();
$revisionsStmt = $pdo->prepare("SELECT r.id, r.title, r.edit_summary, r.is_minor, r.created_at, LENGTH(r.content) AS bytes, u.username FROM revisions r LEFT JOIN users u ON u.id = r.user_id WHERE r.article_id = ? ORDER BY r.id DESC LIMIT {$perPage} OFFSET {$offset}");
$revisionsStmt->execute([$article['id']]); $revisions = $revisionsStmt->fetchAll();
$page_title = 'Revision history of ' . $article['title'] . ' — ' . SITE_NAME;
$page_description = 'Complete revision history for ' . $article['title'] . '.';
$page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Revision history</span><h1><?= e($article['title']) ?></h1><p>Every saved version is preserved. Select two revisions to compare their wiki source.</p></header>
<nav class="article-tabs" aria-label="Page actions"><div class="tabs-left"><a href="/wiki/<?= e($article['slug']) ?>">Article</a><a href="/talk/<?= e($article['slug']) ?>">Talk</a></div><div class="tabs-right"><a class="active" href="/history/<?= e($article['slug']) ?>">History</a><?php if (is_logged_in()): ?><a href="/edit/<?= e($article['slug']) ?>">Edit source</a><?php endif; ?></div></nav>
<section style="margin-top:24px">
<div class="section-heading"><h2><?= format_number($count) ?> saved version<?= $count === 1 ? '' : 's' ?></h2></div>
<?php if ($revisions): ?><form action="/diff/<?= e($article['slug']) ?>" method="get"><div class="revision-list"><?php foreach ($revisions as $index => $revision): ?><article class="revision-item"><div><label class="sr-only" for="old-<?= (int) $revision['id'] ?>">Old revision</label><input id="old-<?= (int) $revision['id'] ?>" type="radio" name="old" value="<?= (int) $revision['id'] ?>" <?= $index === 1 ? 'checked' : '' ?>> <label class="sr-only" for="new-<?= (int) $revision['id'] ?>">New revision</label><input id="new-<?= (int) $revision['id'] ?>" type="radio" name="new" value="<?= (int) $revision['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>></div><div><strong><?= e($revision['title'] ?: $article['title']) ?></strong> <?php if ($revision['is_minor']): ?><span class="badge">minor</span><?php endif; ?><div class="revision-meta"><a href="/user/<?= rawurlencode($revision['username'] ?? '') ?>"><?= e($revision['username'] ?: 'Unknown editor') ?></a> · <?= e($revision['edit_summary'] ?: 'No edit summary') ?> · <?= format_number($revision['bytes']) ?> bytes</div></div><time datetime="<?= e(date('c', strtotime($revision['created_at']))) ?>" title="<?= e($revision['created_at']) ?> UTC"><?= e(time_ago($revision['created_at'])) ?></time></article><?php endforeach; ?></div><div class="form-actions" style="margin-top:18px"><button class="button button-primary" type="submit">Compare selected revisions</button></div></form><?php else: ?><div class="empty-state">No revisions were found.</div><?php endif; ?>
</section>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
