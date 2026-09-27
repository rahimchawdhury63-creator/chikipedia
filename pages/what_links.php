<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$stmt = $pdo->prepare("SELECT id, title, slug FROM articles WHERE slug = ? AND status = 'published' LIMIT 1");
$stmt->execute([$slug]);
$article = $stmt->fetch();
if (!$article) {
    http_response_code(404);
    exit('Article not found.');
}
$links = $pdo->prepare("SELECT a.title, a.slug, a.updated_at FROM article_links l JOIN articles a ON a.id = l.source_article_id WHERE l.target_article_id = ? AND a.status = 'published' ORDER BY a.updated_at DESC LIMIT 500");
$links->execute([$article['id']]);
$incoming = $links->fetchAll();
$redirects = $pdo->prepare('SELECT source_slug, created_at FROM page_redirects WHERE target_article_id = ? ORDER BY source_slug');
$redirects->execute([$article['id']]);
$pageRedirects = $redirects->fetchAll();
$page_title = 'What links here: ' . $article['title'] . ' — ' . SITE_NAME;
$page_description = 'Articles and redirects linking to ' . $article['title'] . ' on ' . SITE_NAME . '.';
$page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Page tools</span><h1>What links here</h1><p>Pages that link to <a href="/wiki/<?= e($article['slug']) ?>"><strong><?= e($article['title']) ?></strong></a>.</p></header>
<div class="admin-two-column"><section class="panel"><div class="panel-header"><h2>Incoming article links</h2><span class="badge"><?= format_number(count($incoming)) ?></span></div><?php if ($incoming): ?><nav class="filter-list"><?php foreach ($incoming as $item): ?><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong><br><small>Updated <?= e(time_ago($item['updated_at'])) ?></small></a><?php endforeach; ?></nav><?php else: ?><div class="empty-state">No published articles currently link here.</div><?php endif; ?></section><aside class="panel"><div class="panel-header"><h2>Redirects</h2><span class="badge"><?= format_number(count($pageRedirects)) ?></span></div><?php if ($pageRedirects): ?><div class="panel-body"><ul><?php foreach ($pageRedirects as $redirect): ?><li><code><?= e($redirect['source_slug']) ?></code></li><?php endforeach; ?></ul></div><?php else: ?><div class="empty-state">No redirects point to this article.</div><?php endif; ?></aside></div>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
