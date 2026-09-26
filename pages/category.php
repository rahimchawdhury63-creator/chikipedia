<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = ? LIMIT 1');
$stmt->execute([$slug]);
$category = $stmt->fetch();
if (!$category) {
    http_response_code(404);
    $page_title = 'Category not found — ' . SITE_NAME; $page_robots = 'noindex,follow';
    require APP_ROOT . '/includes/header.php';
    echo '<main id="main-content" class="page-narrow"><div class="empty-state"><h1>Category not found</h1><a href="/categories">Browse all categories</a></div></main>';
    require APP_ROOT . '/includes/footer.php'; exit;
}
$articlesStmt = $pdo->prepare("SELECT a.title, a.slug, a.excerpt, a.content, a.views, a.updated_at FROM articles a JOIN article_categories ac ON ac.article_id = a.id WHERE ac.category_id = ? AND a.status = 'published' ORDER BY a.title");
$articlesStmt->execute([$category['id']]);
$articles = $articlesStmt->fetchAll();
$page_title = $category['name'] . ' articles — ' . SITE_NAME;
$page_description = $category['description'] ?: 'Browse ' . $category['name'] . ' articles on ' . SITE_NAME . '.';
$page_canonical = site_url('/category/' . rawurlencode($category['slug']));
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Category</span><h1><?= e($category['name']) ?></h1><p><?= e($category['description'] ?: format_number(count($articles)) . ' encyclopedia articles are organized in this category.') ?></p></header>
<div class="section-heading"><h2>Articles in <?= e($category['name']) ?></h2><span><?= format_number(count($articles)) ?> pages</span></div>
<?php if ($articles): ?><div class="article-grid"><?php foreach ($articles as $article): ?><a class="article-card" href="/wiki/<?= e($article['slug']) ?>"><span class="card-kicker"><?= e($category['name']) ?></span><h3><?= e($article['title']) ?></h3><p><?= e($article['excerpt'] ?: excerpt($article['content'], 145)) ?></p><span class="card-meta"><?= format_number($article['views']) ?> views · <?= e(time_ago($article['updated_at'])) ?></span></a><?php endforeach; ?></div><?php else: ?><div class="empty-state">This category currently has no published articles.</div><?php endif; ?>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
