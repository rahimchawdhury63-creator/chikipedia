<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$stmt = $pdo->query("SELECT c.name, c.slug, c.description, COUNT(a.id) AS article_count FROM categories c LEFT JOIN article_categories ac ON ac.category_id = c.id LEFT JOIN articles a ON a.id = ac.article_id AND a.status = 'published' GROUP BY c.id, c.name, c.slug, c.description HAVING article_count > 0 ORDER BY c.name");
$categories = $stmt->fetchAll();
$page_title = 'Categories — ' . SITE_NAME;
$page_description = 'Browse BanglaVerseWiki articles by subject and category.';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Browse knowledge</span><h1>Categories</h1><p>Explore the encyclopedia by subject. Categories are added automatically from article wiki code.</p></header>
<?php if ($categories): ?><div class="article-grid"><?php foreach ($categories as $category): ?><a class="article-card" href="/category/<?= e($category['slug']) ?>"><span class="card-kicker"><?= format_number($category['article_count']) ?> article<?= (int) $category['article_count'] === 1 ? '' : 's' ?></span><h3><?= e($category['name']) ?></h3><p><?= e($category['description'] ?: 'Explore articles, related topics, and community knowledge in this category.') ?></p><span class="card-meta">Browse category →</span></a><?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>No categories yet</h2><p>Editors can add <span class="syntax-chip">[[Category:Name]]</span> to an article source.</p></div><?php endif; ?>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
