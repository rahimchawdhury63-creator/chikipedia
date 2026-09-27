<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/includes/WikiParser.php';

$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$stmt = $pdo->prepare("SELECT a.*, u.username AS author_name,
    (SELECT MAX(r.created_at) FROM revisions r WHERE r.article_id = a.id) AS last_revision_at,
    (SELECT u2.username FROM revisions r2 LEFT JOIN users u2 ON u2.id = r2.user_id WHERE r2.article_id = a.id ORDER BY r2.id DESC LIMIT 1) AS last_editor
    FROM articles a LEFT JOIN users u ON u.id = a.author_id WHERE a.slug = ? LIMIT 1");
$stmt->execute([$slug]);
$article = $stmt->fetch();

if (!$article || !article_is_visible($article)) {
    http_response_code(404);
    $query = str_replace('-', ' ', $slug);
    $suggestStmt = $pdo->prepare("SELECT title, slug, content FROM articles WHERE status = 'published' AND title LIKE ? ORDER BY views DESC LIMIT 5");
    $suggestStmt->execute(['%' . $query . '%']);
    $suggestions = $suggestStmt->fetchAll();
    $page_title = 'Page not found — ' . SITE_NAME;
    $page_description = 'This page does not exist on ' . SITE_NAME . '.';
    $page_robots = 'noindex,follow';
    require APP_ROOT . '/includes/header.php';
    ?>
    <main id="main-content" class="page-narrow">
        <div class="page-heading"><span class="eyebrow">404 · Missing page</span><h1>We could not find “<?= e($query ?: 'this page') ?>”</h1><p>The title may be different, or this knowledge has not been added yet.</p></div>
        <form class="large-search" action="/search" method="get"><input type="search" name="q" value="<?= e($query) ?>" aria-label="Search"><button class="button button-primary" type="submit">Search</button></form>
        <?php if ($suggestions): ?><section><h2>Similar articles</h2><div class="filter-list"><?php foreach ($suggestions as $item): ?><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong><br><small><?= e(excerpt($item['content'], 110)) ?></small></a><?php endforeach; ?></div></section><?php endif; ?>
        <?php if (is_logged_in()): ?><p><a class="button" href="/create?title=<?= rawurlencode($query) ?>">Create this article</a></p><?php endif; ?>
    </main>
    <?php require APP_ROOT . '/includes/footer.php'; exit;
}

if (($article['status'] ?? 'published') === 'published' && empty($_SESSION['viewed_article_' . $article['id']])) {
    $pdo->prepare('UPDATE articles SET views = views + 1 WHERE id = ?')->execute([(int) $article['id']]);
    $_SESSION['viewed_article_' . $article['id']] = true;
    $article['views']++;
}

$parser = new WikiParser();
$parsed = $parser->parse((string) $article['content']);
$categoryStmt = $pdo->prepare('SELECT c.name, c.slug FROM categories c JOIN article_categories ac ON ac.category_id = c.id WHERE ac.article_id = ? ORDER BY c.name');
$categoryStmt->execute([(int) $article['id']]);
$categories = $categoryStmt->fetchAll();
$relatedStmt = $pdo->prepare("SELECT a.title, a.slug, a.excerpt, a.content, a.views, COUNT(ac.category_id) AS shared_categories
    FROM articles a
    LEFT JOIN article_categories ac ON ac.article_id = a.id
    WHERE a.status = 'published' AND a.id != ? AND (ac.category_id IN (SELECT category_id FROM article_categories WHERE article_id = ?) OR NOT EXISTS (SELECT 1 FROM article_categories WHERE article_id = ?))
    GROUP BY a.id, a.title, a.slug, a.excerpt, a.content, a.views
    ORDER BY shared_categories DESC, a.views DESC, a.updated_at DESC LIMIT 4");
$relatedStmt->execute([$article['id'], $article['id'], $article['id']]);
$relatedArticles = $relatedStmt->fetchAll();
$attributionStmt = $pdo->prepare('SELECT source_url, source_title, license_name, attribution_text FROM article_attributions WHERE article_id = ? ORDER BY id');
$attributionStmt->execute([$article['id']]);
$attributions = $attributionStmt->fetchAll();
$imageAttributions = [];
preg_match_all('/\[\[(?:File|Image|চিত্র):\s*(https:\/\/[^\]|\s]+)/iu', (string) $article['content'], $articleImages);
$imageUrls = array_slice(array_values(array_unique($articleImages[1] ?? [])), 0, 100);
if ($imageUrls) {
    $placeholders = implode(',', array_fill(0, count($imageUrls), '?'));
    $mediaStmt = $pdo->prepare("SELECT i.alt_text, i.file_path, ms.source_url, ms.source_page_url, ms.license_name, ms.attribution
        FROM images i JOIN media_sources ms ON ms.id = (SELECT MAX(ms2.id) FROM media_sources ms2 WHERE ms2.image_id = i.id)
        WHERE i.file_path IN ({$placeholders}) ORDER BY i.id");
    $mediaStmt->execute($imageUrls);
    $imageAttributions = $mediaStmt->fetchAll();
}

$isLiked = false;
$isWatched = false;
if (is_logged_in()) {
    $likedStmt = $pdo->prepare('SELECT EXISTS(SELECT 1 FROM article_likes WHERE article_id = ? AND user_id = ?) AS liked, EXISTS(SELECT 1 FROM watchlist WHERE article_id = ? AND user_id = ?) AS watched');
    $likedStmt->execute([$article['id'], current_user()['id'], $article['id'], current_user()['id']]);
    $state = $likedStmt->fetch();
    $isLiked = (bool) ($state['liked'] ?? false);
    $isWatched = (bool) ($state['watched'] ?? false);
}

$description = $article['seo_description'] ?: ($article['excerpt'] ?: excerpt($article['content'], 160));
$page_title = ($article['seo_title'] ?: $article['title']) . ' — ' . SITE_NAME;
$page_description = $description;
$page_canonical = site_url('/wiki/' . rawurlencode($article['slug']));
$page_type = 'article';
$page_image = $article['featured_image'] ?: site_url('/img/brand/social-default.jpg');
if ($article['status'] !== 'published') {
    $page_robots = 'noindex,nofollow';
}
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="wiki-layout">
    <aside class="wiki-rail wiki-left-rail" aria-label="Article navigation">
        <div class="rail-sticky">
            <h2 class="rail-heading">Explore</h2>
            <nav class="rail-nav">
                <a href="/">Main page</a><a href="/special/recent">Recent changes</a><a href="/special/popular">Popular pages</a><a href="/special/random">Random article</a><a href="/categories">Categories</a>
            </nav>
            <h2 class="rail-heading">Contribute</h2>
            <nav class="rail-nav">
                <a href="/create">Create article</a><a href="/wiki/help-editing">Editing help</a><a href="/wiki/community-portal">Community portal</a>
            </nav>
        </div>
    </aside>

    <article class="article-shell">
        <?php if ($article['status'] !== 'published'): ?><div class="draft-banner"><strong><?= e(ucfirst($article['status'])) ?> preview.</strong> This page is visible only to its author and moderators and is not indexed by search engines.</div><?php endif; ?>
        <header class="article-header">
            <div class="article-kicker"><?= $categories ? e($categories[0]['name']) : 'From ' . e(SITE_NAME) . ', the free encyclopedia' ?></div>
            <h1><?= e($article['title']) ?></h1>
            <?php if ($description): ?><p class="article-description"><?= e($description) ?></p><?php endif; ?>
            <nav class="article-tabs" aria-label="Page actions">
                <div class="tabs-left"><a class="active" href="/wiki/<?= e($article['slug']) ?>">Article</a><a href="/talk/<?= e($article['slug']) ?>">Talk</a></div>
                <div class="tabs-right"><a href="/history/<?= e($article['slug']) ?>">History</a><?php if (is_logged_in()): ?><a href="/edit/<?= e($article['slug']) ?>">Edit <span class="label">source</span></a><?php endif; ?></div>
            </nav>
            <div class="article-byline">
                <span>Last edited <?= e(time_ago($article['last_revision_at'] ?: $article['updated_at'])) ?><?= $article['last_editor'] ? ' by ' . e($article['last_editor']) : '' ?></span>
                <span><?= format_number($article['views']) ?> views · <?= format_number($article['edit_count']) ?> edits · <?= format_number($article['likes']) ?> appreciations</span>
            </div>
        </header>

        <?= $parsed['toc'] ?>
        <div class="wiki-content">
            <?= $parsed['html'] ?>
        </div>
        <?php if ($attributions): ?><aside class="wiki-notice attribution-notice"><strong>Content attribution</strong><ul><?php foreach ($attributions as $attribution): ?><li><a href="<?= e($attribution['source_url']) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= e($attribution['source_title'] ?: parse_url($attribution['source_url'], PHP_URL_HOST)) ?></a> · <?= e($attribution['license_name'] ?: 'source license') ?><?php if ($attribution['attribution_text']): ?> — <?= e($attribution['attribution_text']) ?><?php endif; ?></li><?php endforeach; ?></ul></aside><?php endif; ?>
        <?php if ($imageAttributions): ?><aside class="wiki-notice attribution-notice"><strong>Media attribution</strong><ul><?php foreach ($imageAttributions as $mediaCredit): ?><li><a href="<?= e($mediaCredit['source_page_url'] ?: $mediaCredit['source_url']) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= e($mediaCredit['alt_text'] ?: 'Imported image') ?></a> · <?= e($mediaCredit['license_name'] ?: 'reuse rights confirmed') ?><?php if ($mediaCredit['attribution']): ?> — <?= e($mediaCredit['attribution']) ?><?php endif; ?></li><?php endforeach; ?></ul></aside><?php endif; ?>
        <?php if ($categories): ?>
        <nav class="article-categories" aria-label="Categories"><strong>Categories</strong><?php foreach ($categories as $category): ?><a href="/category/<?= e($category['slug']) ?>"><?= e($category['name']) ?></a><?php endforeach; ?></nav>
        <?php endif; ?>
        <?php if ($relatedArticles): ?><section class="related-articles" aria-labelledby="related-title"><div class="section-heading"><h2 id="related-title">Continue exploring</h2><a href="/special/popular">More knowledge →</a></div><div class="article-grid"><?php foreach ($relatedArticles as $related): ?><a class="article-card" href="/wiki/<?= e($related['slug']) ?>"><span class="card-kicker">Related article</span><h3><?= e($related['title']) ?></h3><p><?= e($related['excerpt'] ?: excerpt($related['content'], 115)) ?></p><span class="card-meta"><?= format_number($related['views']) ?> reads</span></a><?php endforeach; ?></div></section><?php endif; ?>
        <div class="article-footer-nav"><a href="/special/random">← Read a random article</a><a href="#main-content">Back to top ↑</a></div>
    </article>

    <aside class="wiki-rail wiki-right-rail" aria-label="Article tools">
        <div class="rail-sticky">
            <h2 class="rail-heading">Tools</h2>
            <div class="rail-nav article-tools">
                <?php if (is_logged_in()): ?>
                <form action="/article-action" method="post"><?= csrf_field() ?><input type="hidden" name="article_id" value="<?= (int) $article['id'] ?>"><input type="hidden" name="action" value="watch"><button type="submit"><?= $isWatched ? '★ Unwatch page' : '☆ Watch page' ?></button></form>
                <form action="/article-action" method="post"><?= csrf_field() ?><input type="hidden" name="article_id" value="<?= (int) $article['id'] ?>"><input type="hidden" name="action" value="like"><button type="submit"><?= $isLiked ? '♥ Appreciated' : '♡ Appreciate' ?></button></form>
                <?php endif; ?>
                <button type="button" data-copy-url="<?= e($page_canonical) ?>">Copy permanent link</button>
                <button type="button" onclick="window.print()">Printable version</button>
                <a href="/report/<?= e($article['slug']) ?>">Report a concern</a>
                <a href="/feed.xml">RSS feed</a>
            </div>
            <h2 class="rail-heading">Page information</h2>
            <div class="article-facts"><dl><dt>Status</dt><dd><?= e(ucfirst($article['status'])) ?></dd><dt>Created</dt><dd><?= e(date('M j, Y', strtotime($article['created_at']))) ?></dd><dt>Updated</dt><dd><?= e(date('M j, Y', strtotime($article['updated_at']))) ?></dd><dt>Words</dt><dd><?= format_number(word_count_unicode($article['content'])) ?></dd></dl></div>
        </div>
    </aside>
</main>
<?php
$articleSchema = [
    '@context' => 'https://schema.org', '@type' => 'Article',
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $page_canonical],
    'headline' => $article['title'], 'description' => $description, 'image' => [$page_image],
    'datePublished' => $article['published_at'] ?: $article['created_at'], 'dateModified' => $article['updated_at'],
    'author' => ['@type' => 'Person', 'name' => $article['author_name'] ?: 'BanglaVerseWiki community'],
    'publisher' => ['@type' => 'Organization', 'name' => SITE_NAME, 'logo' => ['@type' => 'ImageObject', 'url' => site_url('/img/icon/android-chrome-512x512.png')]],
    'inLanguage' => SITE_LANGUAGE, 'isAccessibleForFree' => true,
];
$breadcrumbSchema = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => site_url('/')],
    ['@type' => 'ListItem', 'position' => 2, 'name' => $article['title'], 'item' => $page_canonical],
]];
?>
<script type="application/ld+json"><?= json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script type="application/ld+json"><?= json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php require APP_ROOT . '/includes/footer.php'; ?>
