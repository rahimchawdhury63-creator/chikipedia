<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$total = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT target_key FROM article_links WHERE target_article_id IS NULL GROUP BY target_key) missing")->fetchColumn();
$wanted = $pdo->query("SELECT target_key, MIN(target_title) AS target_title, COUNT(*) AS links, GROUP_CONCAT(source_article_id ORDER BY source_article_id DESC SEPARATOR ',') AS source_ids FROM article_links WHERE target_article_id IS NULL GROUP BY target_key ORDER BY links DESC, target_title LIMIT {$perPage} OFFSET {$offset}")->fetchAll();
$page_title = 'Wanted pages — ' . SITE_NAME;
$page_description = 'Topics linked from BanglaVerseWiki articles that do not have an article yet.';
$page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Community maintenance</span><h1>Wanted pages</h1><p>Published articles link to these topics, but the destination pages do not exist yet. Check reliable independent sources and notability before creating one.</p></header>
<div class="admin-two-column"><section class="panel"><div class="panel-header"><h2>Missing knowledge</h2><span class="badge"><?= format_number($total) ?> topics</span></div><?php if ($wanted): ?><div class="wanted-page-list"><?php foreach ($wanted as $item): $ids = array_values(array_filter(array_map('intval', explode(',', (string) $item['source_ids'])))); $sources = []; if ($ids) { $sourceLookup = $pdo->prepare('SELECT title, slug FROM articles WHERE id IN (' . implode(',', array_fill(0, min(20, count($ids)), '?')) . ") AND status = 'published' ORDER BY updated_at DESC LIMIT 3"); $sourceLookup->execute(array_slice($ids, 0, 20)); $sources = $sourceLookup->fetchAll(); } ?><article><div><h2><?= e($item['target_title']) ?></h2><p><?= format_number($item['links']) ?> published article<?= (int) $item['links'] === 1 ? '' : 's' ?> link here<?php if ($sources): ?> · from <?php foreach ($sources as $index => $source): ?><?= $index ? ', ' : '' ?><a href="/wiki/<?= e($source['slug']) ?>"><?= e($source['title']) ?></a><?php endforeach; ?><?php endif; ?></p></div><?php if (is_logged_in()): ?><a class="button" href="/create?title=<?= rawurlencode($item['target_title']) ?>">Start article</a><?php endif; ?></article><?php endforeach; ?></div><?php else: ?><div class="empty-state"><h2>No wanted pages yet</h2><p>Missing topics appear here automatically when published articles contain unresolved internal links.</p></div><?php endif; ?><?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?><nav class="pagination"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">←</a><?php endif; ?><span class="current"><?= $page ?></span><?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">→</a><?php endif; ?></nav><?php endif; ?></section><aside class="panel"><div class="panel-header"><h2>Before creating</h2></div><div class="panel-body"><ol><li>Search for alternate titles and existing coverage.</li><li>Confirm substantial coverage in reliable independent sources.</li><li>Write neutrally and cite each important claim.</li><li>Connect the new page to relevant categories.</li></ol><a href="/policy/notability">Read the notability policy →</a><br><a href="/policy/reliable-sources">Evaluate sources →</a></div></aside></div>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
