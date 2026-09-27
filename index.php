<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';

$featuredStmt = $pdo->query("SELECT a.*, u.username FROM articles a LEFT JOIN users u ON u.id = a.author_id WHERE a.status = 'published' ORDER BY a.is_featured DESC, (a.views + a.likes * 6 + a.edit_count * 2) / GREATEST(DATEDIFF(UTC_TIMESTAMP(), a.created_at) + 1, 1) DESC, a.updated_at DESC LIMIT 1");
$featured = $featuredStmt->fetch() ?: null;

$trendingStmt = $pdo->prepare("SELECT a.id, a.title, a.slug, a.content, a.excerpt, a.views, a.likes, a.updated_at,
    (SELECT c.name FROM article_categories ac JOIN categories c ON c.id = ac.category_id WHERE ac.article_id = a.id ORDER BY c.name LIMIT 1) AS category_name
    FROM articles a
    WHERE a.status = 'published' AND (? IS NULL OR a.id != ?)
    ORDER BY (a.views + a.likes * 6 + a.edit_count * 2) / GREATEST(DATEDIFF(UTC_TIMESTAMP(), a.created_at) + 1, 1) DESC, a.updated_at DESC LIMIT 6");
$featuredId = $featured['id'] ?? null;
$trendingStmt->execute([$featuredId, $featuredId]);
$trending = $trendingStmt->fetchAll();

$recentStmt = $pdo->query("SELECT title, slug, content, excerpt, updated_at FROM articles WHERE status = 'published' ORDER BY published_at DESC, created_at DESC LIMIT 5");
$recent = $recentStmt->fetchAll();
$stats = $pdo->query("SELECT
    (SELECT COUNT(*) FROM articles WHERE status = 'published') AS articles,
    (SELECT COUNT(*) FROM revisions) AS edits,
    (SELECT COUNT(*) FROM users WHERE status = 'active') AS editors")->fetch() ?: ['articles' => 0, 'edits' => 0, 'editors' => 0];

$page_title = SITE_NAME . ' — মুক্ত জ্ঞানের বিশ্বকোষ';
$page_description = setting($pdo, 'default_meta_description', 'BanglaVerseWiki is a free community encyclopedia for Bengali knowledge, culture, history and ideas.');
$body_class = 'home-page';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content">
    <section class="home-hero" aria-labelledby="home-title">
        <span class="eyebrow">Knowledge without boundaries</span>
        <h1 id="home-title">Discover. Learn.<br><em>Share knowledge.</em></h1>
        <p><?= e(setting($pdo, 'site_tagline', 'A free, community-built encyclopedia where every reliable contribution makes knowledge more accessible.')) ?></p>
        <form class="hero-search" action="/search" method="get" role="search">
            <label class="sr-only" for="hero-query">Search articles</label>
            <input id="hero-query" type="search" name="q" placeholder="What do you want to learn today?" autocomplete="off">
            <button type="submit">Explore</button>
        </form>
        <div class="hero-stats" aria-label="Site statistics">
            <div class="hero-stat"><strong><?= format_number($stats['articles']) ?></strong><span>Articles</span></div>
            <div class="hero-stat"><strong><?= format_number($stats['edits']) ?></strong><span>Knowledge edits</span></div>
            <div class="hero-stat"><strong><?= format_number($stats['editors']) ?></strong><span>Editors</span></div>
            <div class="hero-stat"><strong>Free</strong><span>Forever</span></div>
        </div>
    </section>

    <div class="home-content">
        <?php if ($notice = setting($pdo, 'homepage_notice', '')): ?>
            <div class="home-notice"><span><strong>Community notice:</strong> <?= e($notice) ?></span><a href="/community">Visit community portal →</a></div>
        <?php endif; ?>

        <?php if ($featured): ?>
        <section aria-labelledby="featured-title">
            <div class="section-heading"><h2 id="featured-title">Featured knowledge</h2><a href="/special/popular">View popular articles →</a></div>
            <div class="featured-grid">
                <article class="featured-article">
                    <?php if (!empty($featured['featured_image'])): ?><img src="<?= e($featured['featured_image']) ?>" alt="" fetchpriority="high"><?php endif; ?>
                    <div class="featured-copy">
                        <span class="badge">Featured article</span>
                        <h2><a href="/wiki/<?= e($featured['slug']) ?>"><?= e($featured['title']) ?></a></h2>
                        <p><?= e($featured['excerpt'] ?: excerpt($featured['content'], 235)) ?></p>
                        <div class="meta-row featured-meta"><span><?= format_number($featured['views']) ?> reads</span><span>Updated <?= e(time_ago($featured['updated_at'])) ?></span></div>
                    </div>
                </article>
                <div class="home-side-stack">
                    <article class="fact-card">
                        <span class="eyebrow">Did you know?</span>
                        <?php $fact = $recent[array_key_last($recent)] ?? $featured; ?>
                        <h3><?= e($fact['title']) ?></h3>
                        <p><?= e($fact['excerpt'] ?: excerpt($fact['content'], 145)) ?></p>
                        <a href="/wiki/<?= e($fact['slug']) ?>">Continue reading →</a>
                    </article>
                    <aside class="contribute-card">
                        <h3>Knowledge is better when we build it together.</h3>
                        <p>Share a well-sourced topic, improve an article, or join the conversation.</p>
                        <a class="button" href="<?= is_logged_in() ? '/create' : '/register' ?>"><?= is_logged_in() ? 'Create an article' : 'Become an editor' ?></a>
                    </aside>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section aria-labelledby="trending-title">
            <div class="section-heading"><h2 id="trending-title">Trending now</h2><a href="/special/popular">See all →</a></div>
            <?php if ($trending): ?>
            <div class="article-grid">
                <?php foreach ($trending as $index => $article): ?>
                <a class="article-card" href="/wiki/<?= e($article['slug']) ?>">
                    <span class="card-kicker"><?= e($article['category_name'] ?: 'Encyclopedia') ?></span>
                    <h3><?= e($article['title']) ?></h3>
                    <p><?= e($article['excerpt'] ?: excerpt($article['content'], 150)) ?></p>
                    <span class="card-meta"><?= format_number($article['views']) ?> reads · updated <?= e(time_ago($article['updated_at'])) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="empty-state"><h2><?= $featured ? 'More knowledge is waiting to be written' : 'Help write the first chapter' ?></h2><p><?= $featured ? 'Create another reliable, well-sourced page for the community.' : 'There are no published articles yet. Create the first reliable, well-sourced page.' ?></p><a class="button button-primary" href="<?= is_logged_in() ? '/create' : '/register' ?>">Get started</a></div>
            <?php endif; ?>
        </section>

        <?php if ($recent): ?>
        <section aria-labelledby="recent-title">
            <div class="section-heading"><h2 id="recent-title">Recently added</h2><a href="/special/recent">All recent changes →</a></div>
            <div class="article-grid">
                <?php foreach ($recent as $article): ?>
                <a class="article-card" href="/wiki/<?= e($article['slug']) ?>">
                    <span class="card-kicker">New knowledge</span><h3><?= e($article['title']) ?></h3>
                    <p><?= e($article['excerpt'] ?: excerpt($article['content'], 140)) ?></p><span class="card-meta"><?= e(time_ago($article['updated_at'])) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>
</main>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => SITE_NAME,
    'url' => site_url('/'), 'description' => $page_description,
    'mainEntity' => array_map(fn($article) => ['@type' => 'Article', 'headline' => $article['title'], 'url' => site_url('/wiki/' . $article['slug'])], array_slice($trending, 0, 6)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php require APP_ROOT . '/includes/footer.php'; ?>
