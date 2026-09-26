<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$query = trim((string) ($_GET['q'] ?? ''));
$type = in_array($_GET['type'] ?? 'all', ['all', 'title', 'content'], true) ? $_GET['type'] : 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$results = [];
$total = 0;
$synonyms = [];
if ($query !== '') {
    $search = smart_search($pdo, $query, $type, $perPage, $offset);
    $results = $search['results'];
    $total = $search['total'];
    $synonyms = $search['synonyms'];
    if ($page === 1) {
        record_search_query($pdo, $query, $total);
    }
}
$page_title = $query !== '' ? 'Search results for “' . $query . '” — ' . SITE_NAME : 'Search — ' . SITE_NAME;
$page_description = 'Search the free ' . SITE_NAME . ' encyclopedia.';
$page_robots = $query !== '' ? 'noindex,follow' : 'index,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
    <section class="search-hero"><span class="eyebrow">Explore the encyclopedia</span><h1><?= $query ? 'Search results' : 'Find reliable knowledge' ?></h1><form class="large-search" action="/search" method="get"><label class="sr-only" for="search-page-query">Search</label><input id="search-page-query" type="search" name="q" value="<?= e($query) ?>" placeholder="Search article titles and text" autofocus><button class="button button-primary" type="submit">Search</button></form></section>
    <div class="search-layout">
        <section>
            <?php if ($query === ''): ?>
                <div class="empty-state"><h2>What would you like to know?</h2><p>Search people, places, history, science, culture, and ideas—or browse a random page.</p><a class="button" href="/special/random">Surprise me</a></div>
            <?php elseif (!$results): ?>
                <div class="empty-state"><h2>No article matched “<?= e($query) ?>”</h2><p>Check the spelling, try fewer words, or help create this missing knowledge.</p><?php if (is_logged_in()): ?><a class="button button-primary" href="/create?title=<?= rawurlencode($query) ?>">Create “<?= e($query) ?>”</a><?php endif; ?></div>
            <?php else: ?>
                <div class="section-heading"><h2><?= format_number($total) ?> result<?= $total === 1 ? '' : 's' ?> for “<?= e($query) ?>”</h2><span>Page <?= $page ?></span></div>
                <?php if ($synonyms): ?><p class="meta-row"><strong>Also searching:</strong> <?= e(implode(', ', $synonyms)) ?></p><?php endif; ?>
                <?php foreach ($results as $result): ?><article class="search-result"><h2><a href="/wiki/<?= e($result['slug']) ?>"><?= e($result['title']) ?></a></h2><p><?= e($result['excerpt'] ?: excerpt($result['content'], 230)) ?></p><div class="meta-row"><?php if (!empty($result['category_name'])): ?><span class="badge"><?= e($result['category_name']) ?></span><?php endif; ?><span><?= format_number($result['views']) ?> views</span><span>Quality <?= format_number(round((float) ($result['quality_score'] ?? 0))) ?>/100</span><span>Updated <?= e(time_ago($result['updated_at'])) ?></span></div></article><?php endforeach; ?>
                <?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?><nav class="pagination" aria-label="Search pages"><?php if ($page > 1): ?><a href="?q=<?= rawurlencode($query) ?>&type=<?= e($type) ?>&page=<?= $page - 1 ?>">←</a><?php endif; ?><?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?><a class="<?= $i === $page ? 'current' : '' ?>" href="?q=<?= rawurlencode($query) ?>&type=<?= e($type) ?>&page=<?= $i ?>"><?= $i ?></a><?php endfor; ?><?php if ($page < $pages): ?><a href="?q=<?= rawurlencode($query) ?>&type=<?= e($type) ?>&page=<?= $page + 1 ?>">→</a><?php endif; ?></nav><?php endif; ?>
            <?php endif; ?>
        </section>
        <aside class="search-filters"><h2 class="rail-heading">Search in</h2><nav class="filter-list"><a class="<?= $type === 'all' ? 'active' : '' ?>" href="?q=<?= rawurlencode($query) ?>&type=all">All text</a><a class="<?= $type === 'title' ? 'active' : '' ?>" href="?q=<?= rawurlencode($query) ?>&type=title">Titles only</a><a class="<?= $type === 'content' ? 'active' : '' ?>" href="?q=<?= rawurlencode($query) ?>&type=content">Article content</a></nav><h2 class="rail-heading" style="margin-top:25px">Browse</h2><nav class="filter-list"><a href="/categories">Categories</a><a href="/special/recent">Recent changes</a><a href="/special/popular">Popular pages</a></nav></aside>
    </div>
</main>
<?php if ($query !== '' && $results): ?>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'SearchResultsPage', 'name' => 'Search results for ' . $query, 'url' => current_url(), 'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => array_map(static fn(array $item, int $index): array => ['@type' => 'ListItem', 'position' => $offset + $index + 1, 'url' => site_url('/wiki/' . rawurlencode($item['slug'])), 'name' => $item['title']], $results, array_keys($results))]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
