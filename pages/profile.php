<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$username = trim(rawurldecode((string) ($_GET['username'] ?? '')));
$stmt = $pdo->prepare('SELECT id, username, role, status, bio, created_at FROM users WHERE username = ? AND status = ? LIMIT 1');
$stmt->execute([$username, 'active']);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(404);
    $page_title = 'Editor not found — ' . SITE_NAME;
    $page_robots = 'noindex,follow';
    require APP_ROOT . '/includes/header.php';
    echo '<main id="main-content" class="page-narrow"><div class="empty-state"><h1>Editor not found</h1><p>This public editor profile does not exist.</p><a href="/">Return home</a></div></main>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}
$statsStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM revisions WHERE user_id = ?) AS edits,
    (SELECT COUNT(*) FROM articles WHERE author_id = ?) AS created,
    (SELECT COALESCE(SUM(views), 0) FROM articles WHERE author_id = ? AND status = 'published') AS impact,
    (SELECT COUNT(*) FROM images WHERE user_id = ?) AS images");
$statsStmt->execute(array_fill(0, 4, $user['id']));
$stats = $statsStmt->fetch();
$contribStmt = $pdo->prepare("SELECT r.created_at, r.edit_summary, r.is_minor, a.title, a.slug, a.status FROM revisions r JOIN articles a ON a.id = r.article_id WHERE r.user_id = ? AND (a.status = 'published' OR a.author_id = ?) ORDER BY r.id DESC LIMIT 20");
$contribStmt->execute([$user['id'], $user['id']]);
$contributions = $contribStmt->fetchAll();

$page_title = $user['username'] . ' — editor profile on ' . SITE_NAME;
$page_description = $user['username'] . ' has made ' . format_number($stats['edits']) . ' contributions to ' . SITE_NAME . '.';
$page_canonical = site_url('/user/' . rawurlencode($user['username']));
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
    <section class="profile-hero">
        <div class="profile-avatar"><?= e(mb_strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
        <div><span class="badge"><?= e($user['role']) ?></span><h1><?= e($user['username']) ?></h1><p>Community member since <?= e(date('F Y', strtotime($user['created_at']))) ?></p><?php if ($user['bio']): ?><p><?= e($user['bio']) ?></p><?php endif; ?></div>
    </section>
    <section class="stat-grid" aria-label="Contribution statistics">
        <div class="stat-card"><strong><?= format_number($stats['edits']) ?></strong><span>Total edits</span></div>
        <div class="stat-card"><strong><?= format_number($stats['created']) ?></strong><span>Articles started</span></div>
        <div class="stat-card"><strong><?= format_number($stats['impact']) ?></strong><span>Reader impact</span></div>
        <div class="stat-card"><strong><?= format_number($stats['images']) ?></strong><span>Media uploads</span></div>
    </section>
    <section class="panel">
        <div class="panel-header"><h2>Recent contributions</h2><span class="badge"><?= format_number(count($contributions)) ?> shown</span></div>
        <?php if ($contributions): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Article</th><th>Contribution</th><th>Date</th></tr></thead><tbody><?php foreach ($contributions as $item): ?><tr><td><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong></a> <?php if ($item['status'] !== 'published'): ?><span class="badge badge-<?= e($item['status']) ?>"><?= e($item['status']) ?></span><?php endif; ?></td><td><?= e($item['edit_summary'] ?: 'Edited article') ?><?= $item['is_minor'] ? ' · minor' : '' ?></td><td title="<?= e($item['created_at']) ?>"><?= e(time_ago($item['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">No public contributions yet.</div><?php endif; ?>
    </section>
</main>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'ProfilePage', 'dateCreated' => $user['created_at'], 'mainEntity' => ['@type' => 'Person', 'name' => $user['username'], 'url' => $page_canonical]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php require APP_ROOT . '/includes/footer.php'; ?>
