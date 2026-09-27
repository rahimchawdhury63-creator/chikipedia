<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$type = (string) ($_GET['type'] ?? 'recent');
if ($type === 'random') {
    $slug = $pdo->query("SELECT slug FROM articles WHERE status = 'published' ORDER BY RAND() LIMIT 1")->fetchColumn();
    if ($slug) redirect('/wiki/' . rawurlencode((string) $slug), 302);
    flash('info', 'There are no published articles yet.');
    redirect('/');
}
if (!in_array($type, ['recent', 'popular'], true)) {
    http_response_code(404); $type = 'recent';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;
if ($type === 'recent') {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM revisions r JOIN articles a ON a.id = r.article_id WHERE a.status = 'published'")->fetchColumn();
    $stmt = $pdo->query("SELECT a.title, a.slug, r.edit_summary, r.is_minor, r.created_at, u.username FROM revisions r JOIN articles a ON a.id = r.article_id LEFT JOIN users u ON u.id = r.user_id WHERE a.status = 'published' ORDER BY r.id DESC LIMIT {$perPage} OFFSET {$offset}");
    $items = $stmt->fetchAll();
    $heading = 'Recent changes';
    $intro = 'A transparent, live record of improvements made by the community.';
} else {
    $count = (int) $pdo->query("SELECT COUNT(*) FROM articles WHERE status = 'published'")->fetchColumn();
    $stmt = $pdo->query("SELECT title, slug, excerpt, content, views, likes, edit_count, updated_at, (views + likes * 6 + edit_count * 2) / GREATEST(DATEDIFF(UTC_TIMESTAMP(), created_at) + 1, 1) AS trend_score FROM articles WHERE status = 'published' ORDER BY trend_score DESC, views DESC LIMIT {$perPage} OFFSET {$offset}");
    $items = $stmt->fetchAll();
    $heading = 'Popular articles';
    $intro = 'Pages readers are exploring and editors are improving right now.';
}
$page_title = $heading . ' — ' . SITE_NAME;
$page_description = $intro;
$page_canonical = site_url('/special/' . $type . ($page > 1 ? '?page=' . $page : ''));
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
    <header class="page-heading"><span class="eyebrow">Special page</span><h1><?= e($heading) ?></h1><p><?= e($intro) ?></p></header>
    <nav class="article-tabs" aria-label="Lists"><div class="tabs-left"><a class="<?= $type === 'recent' ? 'active' : '' ?>" href="/special/recent">Recent changes</a><a class="<?= $type === 'popular' ? 'active' : '' ?>" href="/special/popular">Popular</a></div><div class="tabs-right"><a href="/feed.xml">RSS</a></div></nav>
    <?php if (!$items): ?><div class="empty-state"><h2>No activity yet</h2><p>The first community contribution will appear here.</p></div>
    <?php elseif ($type === 'recent'): ?>
    <div class="table-responsive"><table class="list-table"><thead><tr><th>Time</th><th>Page</th><th>Editor</th><th>Summary</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td title="<?= e($item['created_at']) ?>"><?= e(time_ago($item['created_at'])) ?></td><td><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong></a></td><td><?= $item['username'] ? '<a href="/user/' . rawurlencode($item['username']) . '">' . e($item['username']) . '</a>' : 'Unknown editor' ?></td><td><?= $item['is_minor'] ? '<span class="badge">minor</span> ' : '' ?><?= e($item['edit_summary'] ?: 'Edited article') ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php else: ?>
    <div class="article-grid" style="margin-top:25px"><?php foreach ($items as $rank => $item): ?><a class="article-card" href="/wiki/<?= e($item['slug']) ?>"><span class="card-kicker">#<?= $offset + $rank + 1 ?> trending</span><h3><?= e($item['title']) ?></h3><p><?= e($item['excerpt'] ?: excerpt($item['content'], 145)) ?></p><span class="card-meta"><?= format_number($item['views']) ?> views · <?= format_number($item['likes']) ?> likes · <?= format_number($item['edit_count']) ?> edits</span></a><?php endforeach; ?></div>
    <?php endif; ?>
    <?php $pages = (int) ceil($count / $perPage); if ($pages > 1): ?><nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">← Newer</a><?php endif; ?><span class="current"><?= $page ?></span><?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Older →</a><?php endif; ?></nav><?php endif; ?>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
