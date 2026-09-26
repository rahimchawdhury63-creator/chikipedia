<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_moderator();
$tabs = is_admin()
    ? ['overview', 'articles', 'users', 'search', 'settings', 'media', 'reports', 'activity']
    : ['overview', 'articles', 'search', 'media', 'reports', 'activity'];
$tab = in_array($_GET['tab'] ?? 'overview', $tabs, true) ? $_GET['tab'] : 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'article_update') {
        $id = (int) ($_POST['article_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['published', 'pending', 'draft', 'archived'], true) ? $_POST['status'] : 'pending';
        $featured = !empty($_POST['is_featured']) ? 1 : 0;
        $articleStmt = $pdo->prepare('SELECT slug, status FROM articles WHERE id = ?'); $articleStmt->execute([$id]); $before = $articleStmt->fetch();
        $pdo->prepare('UPDATE articles SET status = ?, is_featured = ?, published_at = CASE WHEN ? = \'published\' THEN COALESCE(published_at, UTC_TIMESTAMP()) ELSE published_at END WHERE id = ?')->execute([$status, $featured, $status, $id]);
        log_activity($pdo, 'admin.article_updated', 'article', $id, ['status' => $status, 'featured' => $featured]);
        if ($before && $status === 'published' && $before['status'] !== 'published') notify_indexnow([site_url('/wiki/' . $before['slug']), site_url('/'), site_url('/feed.xml')]);
        flash('success', 'Article moderation settings updated.');
    } elseif ($action === 'user_update') {
        if (!is_admin()) { http_response_code(403); exit('Administrator access is required.'); }
        $id = (int) ($_POST['user_id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['editor', 'moderator', 'administrator'], true) ? $_POST['role'] : 'editor';
        $status = in_array($_POST['status'] ?? '', ['active', 'blocked'], true) ? $_POST['status'] : 'active';
        if ($id === (int) current_user()['id'] && ($role !== 'administrator' || $status !== 'active')) {
            flash('error', 'You cannot demote or block your own active administrator account.');
        } else {
            $pdo->prepare('UPDATE users SET role = ?, status = ? WHERE id = ?')->execute([$role, $status, $id]);
            log_activity($pdo, 'admin.user_updated', 'user', $id, ['role' => $role, 'status' => $status]);
            flash('success', 'User permissions updated.');
        }
    } elseif ($action === 'search_synonym_add') {
        $term = mb_substr(normalize_search_query((string) ($_POST['term'] ?? '')), 0, 120);
        $synonym = mb_substr(normalize_search_query((string) ($_POST['synonym'] ?? '')), 0, 120);
        $weight = max(0.1, min(2.0, (float) ($_POST['weight'] ?? 0.75)));
        if ($term === '' || $synonym === '' || $term === $synonym) {
            flash('error', 'Enter two different search terms.');
        } else {
            $pdo->prepare('INSERT INTO search_synonyms (term, synonym, weight, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE weight = VALUES(weight), is_active = 1')->execute([$term, $synonym, $weight]);
            log_activity($pdo, 'admin.search_synonym_saved', 'search');
            flash('success', 'Search synonym saved.');
        }
    } elseif ($action === 'search_synonym_delete') {
        $pdo->prepare('DELETE FROM search_synonyms WHERE id = ?')->execute([(int) ($_POST['synonym_id'] ?? 0)]);
        log_activity($pdo, 'admin.search_synonym_deleted', 'search');
        flash('success', 'Search synonym removed.');
    } elseif ($action === 'search_reindex') {
        $pdo->exec("INSERT INTO search_documents (article_id, title, normalized_title, body, excerpt, language, quality_score, popularity_score, updated_at)
            SELECT id, title, LOWER(title), content, excerpt, 'bn', LEAST(100, LEAST(30, CHAR_LENGTH(content) / 500) + LEAST(50, (CHAR_LENGTH(content) - CHAR_LENGTH(REPLACE(content, '<ref', ''))) / 4 * 10)), (LOG10(views + 10) * 8 + likes * 4 + edit_count), updated_at FROM articles
            ON DUPLICATE KEY UPDATE title = VALUES(title), normalized_title = VALUES(normalized_title), body = VALUES(body), excerpt = VALUES(excerpt), quality_score = VALUES(quality_score), popularity_score = VALUES(popularity_score), updated_at = VALUES(updated_at)");
        log_activity($pdo, 'admin.search_reindexed', 'search');
        flash('success', 'The search index was rebuilt.');
    } elseif ($action === 'settings_update') {
        if (!is_admin()) { http_response_code(403); exit('Administrator access is required.'); }
        $settings = [
            'site_tagline' => mb_substr(trim((string) ($_POST['site_tagline'] ?? '')), 0, 300),
            'homepage_notice' => mb_substr(trim((string) ($_POST['homepage_notice'] ?? '')), 0, 500),
            'default_meta_description' => mb_substr(trim((string) ($_POST['default_meta_description'] ?? '')), 0, 320),
            'allow_registration' => !empty($_POST['allow_registration']) ? '1' : '0',
            'require_review' => !empty($_POST['require_review']) ? '1' : '0',
        ];
        foreach ($settings as $key => $value) save_setting($pdo, $key, $value);
        log_activity($pdo, 'admin.settings_updated', 'settings'); flash('success', 'Site settings saved.');
    } elseif ($action === 'report_update') {
        $id = (int) ($_POST['report_id'] ?? 0); $status = in_array($_POST['status'] ?? '', ['open', 'reviewing', 'resolved', 'dismissed'], true) ? $_POST['status'] : 'open';
        $pdo->prepare('UPDATE reports SET status = ? WHERE id = ?')->execute([$status, $id]); log_activity($pdo, 'admin.report_updated', 'report', $id, ['status' => $status]); flash('success', 'Report status updated.');
    }
    redirect('/admin?tab=' . rawurlencode($tab));
}

$page = max(1, (int) ($_GET['page'] ?? 1)); $limit = 50; $offset = ($page - 1) * $limit;
$stats = $pdo->query("SELECT
 (SELECT COUNT(*) FROM articles WHERE status = 'published') published,
 (SELECT COUNT(*) FROM articles WHERE status = 'pending') pending,
 (SELECT COUNT(*) FROM users) users,
 (SELECT COUNT(*) FROM revisions) revisions,
 (SELECT COALESCE(SUM(views),0) FROM articles) views,
 (SELECT COUNT(*) FROM images) images,
 (SELECT COUNT(*) FROM reports WHERE status = 'open') reports")->fetch();
$recentActivity = $topArticles = $articles = $users = $media = $reports = $activity = $topQueries = $zeroQueries = $synonyms = [];
if ($tab === 'overview') {
    $recentActivity = $pdo->query('SELECT l.*, u.username FROM activity_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 12')->fetchAll();
    $topArticles = $pdo->query("SELECT title, slug, views, likes, edit_count FROM articles WHERE status = 'published' ORDER BY views DESC LIMIT 8")->fetchAll();
} elseif ($tab === 'articles') {
    $articles = $pdo->query("SELECT a.id, a.title, a.slug, a.status, a.is_featured, a.views, a.updated_at, u.username FROM articles a LEFT JOIN users u ON u.id = a.author_id ORDER BY CASE a.status WHEN 'pending' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, a.updated_at DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll();
} elseif ($tab === 'users') {
    $users = $pdo->query("SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login_at, COUNT(r.id) edits FROM users u LEFT JOIN revisions r ON r.user_id = u.id GROUP BY u.id, u.username, u.email, u.role, u.status, u.created_at, u.last_login_at ORDER BY u.created_at DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll();
} elseif ($tab === 'search') {
    $topQueries = $pdo->query("SELECT normalized_query, COUNT(*) searches, ROUND(AVG(result_count)) average_results, MAX(created_at) last_searched FROM search_queries WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY normalized_query ORDER BY searches DESC, last_searched DESC LIMIT 25")->fetchAll();
    $zeroQueries = $pdo->query("SELECT normalized_query, COUNT(*) searches, MAX(created_at) last_searched FROM search_queries WHERE result_count = 0 AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY normalized_query ORDER BY searches DESC, last_searched DESC LIMIT 20")->fetchAll();
    $synonyms = $pdo->query('SELECT * FROM search_synonyms ORDER BY term, weight DESC')->fetchAll();
} elseif ($tab === 'media') {
    $media = $pdo->query("SELECT i.*, u.username FROM images i LEFT JOIN users u ON u.id = i.user_id ORDER BY i.id DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll();
} elseif ($tab === 'reports') {
    $reports = $pdo->query("SELECT r.*, a.title, a.slug, u.username FROM reports r LEFT JOIN articles a ON a.id = r.article_id LEFT JOIN users u ON u.id = r.user_id ORDER BY CASE r.status WHEN 'open' THEN 0 WHEN 'reviewing' THEN 1 ELSE 2 END, r.id DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll();
} elseif ($tab === 'activity') {
    $activity = $pdo->query("SELECT l.*, u.username FROM activity_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}")->fetchAll();
}
$adminNav = ['overview' => 'Overview', 'articles' => 'Articles', 'users' => 'Users & roles', 'search' => 'Search intelligence', 'settings' => 'Site settings', 'media' => 'Media library', 'reports' => 'Reports', 'activity' => 'Activity log'];
if (!is_admin()) { unset($adminNav['users'], $adminNav['settings']); }
$page_title = (is_admin() ? 'Administration' : 'Moderation') . ' — ' . SITE_NAME; $page_robots = 'noindex,nofollow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="admin-layout">
<aside class="admin-sidebar"><strong><?= is_admin() ? 'Control center' : 'Moderation' ?></strong><nav><?php foreach ($adminNav as $key => $label): ?><a class="<?= $tab === $key ? 'active' : '' ?>" href="/admin?tab=<?= e($key) ?>"><?= e($label) ?><?= $key === 'reports' && $stats['reports'] ? ' (' . (int) $stats['reports'] . ')' : '' ?></a><?php endforeach; ?></nav></aside>
<section class="admin-main">
<h1><?= e(ucwords(str_replace('_', ' ', $tab))) ?></h1>
<?php if ($tab === 'overview'): ?>
<div class="admin-grid"><div class="stat-card"><strong><?= format_number($stats['published']) ?></strong><span>Published articles</span></div><div class="stat-card"><strong><?= format_number($stats['views']) ?></strong><span>Total views</span></div><div class="stat-card"><strong><?= format_number($stats['users']) ?></strong><span>Community members</span></div><div class="stat-card"><strong><?= format_number($stats['pending']) ?></strong><span>Awaiting review</span></div></div>
<div class="admin-two-column"><section class="panel"><div class="panel-header"><h2>Recent system activity</h2><a href="?tab=activity">View log</a></div><div class="table-responsive"><table class="list-table"><thead><tr><th>Action</th><th>User</th><th>When</th></tr></thead><tbody><?php foreach ($recentActivity as $item): ?><tr><td><?= e(str_replace('.', ' · ', $item['action'])) ?></td><td><?= e($item['username'] ?: 'System') ?></td><td><?= e(time_ago($item['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section><aside class="panel"><div class="panel-header"><h2>System health</h2></div><div class="panel-body health-list"><div class="health-item"><span>Database schema</span><span class="health-good">v<?= e(setting($pdo, 'schema_version', '?')) ?></span></div><div class="health-item"><span>Secure HTTPS URL</span><span class="<?= str_starts_with(SITE_URL, 'https://') ? 'health-good' : 'health-warn' ?>"><?= str_starts_with(SITE_URL, 'https://') ? 'Ready' : 'Check URL' ?></span></div><div class="health-item"><span>ImgBB uploads</span><span class="<?= IMGBB_API_KEY !== '' ? 'health-good' : 'health-warn' ?>"><?= IMGBB_API_KEY !== '' ? 'Configured' : 'Needs key' ?></span></div><div class="health-item"><span>IndexNow</span><span class="<?= INDEXNOW_KEY !== '' ? 'health-good' : 'health-warn' ?>"><?= INDEXNOW_KEY !== '' ? 'Configured' : 'Optional' ?></span></div><div class="health-item"><span>PHP runtime</span><strong><?= e(PHP_VERSION) ?></strong></div></div></aside></div>
<section class="panel" style="margin-top:20px"><div class="panel-header"><h2>Most-read articles</h2></div><div class="table-responsive"><table class="list-table"><thead><tr><th>Article</th><th>Views</th><th>Likes</th><th>Edits</th></tr></thead><tbody><?php foreach ($topArticles as $item): ?><tr><td><a href="/wiki/<?= e($item['slug']) ?>"><?= e($item['title']) ?></a></td><td><?= format_number($item['views']) ?></td><td><?= format_number($item['likes']) ?></td><td><?= format_number($item['edit_count']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif ($tab === 'articles'): ?>
<section class="panel"><div class="panel-header"><h2>Article moderation</h2><span class="badge"><?= format_number(count($articles)) ?> shown</span></div><div class="table-responsive"><table class="list-table"><thead><tr><th>Article</th><th>Author</th><th>Views</th><th>Moderation</th></tr></thead><tbody><?php foreach ($articles as $item): ?><tr><td><a href="/wiki/<?= e($item['slug']) ?>"><strong><?= e($item['title']) ?></strong></a><br><small><?= e(time_ago($item['updated_at'])) ?></small></td><td><?= e($item['username'] ?: 'Unknown') ?></td><td><?= format_number($item['views']) ?></td><td><form class="inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="article_update"><input type="hidden" name="article_id" value="<?= (int) $item['id'] ?>"><select name="status" aria-label="Status"><option value="published" <?= $item['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="pending" <?= $item['status'] === 'pending' ? 'selected' : '' ?>>Pending</option><option value="draft" <?= $item['status'] === 'draft' ? 'selected' : '' ?>>Draft</option><option value="archived" <?= $item['status'] === 'archived' ? 'selected' : '' ?>>Archived</option></select><label class="checkbox"><input type="checkbox" name="is_featured" value="1" <?= $item['is_featured'] ? 'checked' : '' ?>> Featured</label><button class="button" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif ($tab === 'users'): ?>
<section class="panel"><div class="panel-header"><h2>Users and permissions</h2><span>Administrators can promote other administrators.</span></div><div class="table-responsive"><table class="list-table"><thead><tr><th>User</th><th>Contact</th><th>Edits</th><th>Role &amp; status</th></tr></thead><tbody><?php foreach ($users as $item): ?><tr><td><a href="/user/<?= rawurlencode($item['username']) ?>"><strong><?= e($item['username']) ?></strong></a><br><small>Joined <?= e(date('M j, Y', strtotime($item['created_at']))) ?></small></td><td><?= e($item['email']) ?><br><small>Last login: <?= e($item['last_login_at'] ? time_ago($item['last_login_at']) : 'never') ?></small></td><td><?= format_number($item['edits']) ?></td><td><form class="inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_update"><input type="hidden" name="user_id" value="<?= (int) $item['id'] ?>"><select name="role"><option value="editor" <?= $item['role'] === 'editor' ? 'selected' : '' ?>>Editor</option><option value="moderator" <?= $item['role'] === 'moderator' ? 'selected' : '' ?>>Moderator</option><option value="administrator" <?= $item['role'] === 'administrator' ? 'selected' : '' ?>>Administrator</option></select><select name="status"><option value="active" <?= $item['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="blocked" <?= $item['status'] === 'blocked' ? 'selected' : '' ?>>Blocked</option></select><button class="button" type="submit">Update</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif ($tab === 'search'): ?>
<div class="admin-two-column"><section class="panel"><div class="panel-header"><h2>Top searches · last 30 days</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="search_reindex"><button class="button" type="submit">Rebuild index</button></form></div><?php if ($topQueries): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Query</th><th>Searches</th><th>Average results</th><th>Last used</th></tr></thead><tbody><?php foreach ($topQueries as $item): ?><tr><td><a href="/search?q=<?= rawurlencode($item['normalized_query']) ?>"><?= e($item['normalized_query']) ?></a></td><td><?= format_number($item['searches']) ?></td><td><?= format_number($item['average_results']) ?></td><td><?= e(time_ago($item['last_searched'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">Search analytics will appear after readers begin searching.</div><?php endif; ?></section><aside class="panel"><div class="panel-header"><h2>Zero-result opportunities</h2></div><div class="panel-body"><?php if ($zeroQueries): ?><nav class="filter-list"><?php foreach ($zeroQueries as $item): ?><a href="/create?title=<?= rawurlencode($item['normalized_query']) ?>"><strong><?= e($item['normalized_query']) ?></strong><br><small><?= format_number($item['searches']) ?> searches · create article</small></a><?php endforeach; ?></nav><?php else: ?><p class="form-help">No zero-result searches in the last 30 days.</p><?php endif; ?></div></aside></div>
<section class="panel" style="margin-top:20px"><div class="panel-header"><h2>Synonym intelligence</h2><span>Expand abbreviations, Bengali/English equivalents, and alternate spellings.</span></div><div class="panel-body"><form class="form-row" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="search_synonym_add"><div><label for="synonym-term">Reader searches</label><input id="synonym-term" name="term" maxlength="120" required placeholder="e.g. bd"></div><div><label for="synonym-value">Also match</label><input id="synonym-value" name="synonym" maxlength="120" required placeholder="e.g. bangladesh"></div><div><label for="synonym-weight">Weight</label><input id="synonym-weight" name="weight" type="number" min="0.1" max="2" step="0.05" value="0.75"></div><div style="align-self:end"><button class="button button-primary" type="submit">Save synonym</button></div></form></div><?php if ($synonyms): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Term</th><th>Expanded match</th><th>Weight</th><th></th></tr></thead><tbody><?php foreach ($synonyms as $item): ?><tr><td><?= e($item['term']) ?></td><td><?= e($item['synonym']) ?></td><td><?= e($item['weight']) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="search_synonym_delete"><input type="hidden" name="synonym_id" value="<?= (int) $item['id'] ?>"><button class="button button-quiet" type="submit" data-confirm="Remove this synonym?">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php elseif ($tab === 'settings'): ?>
<section class="panel"><div class="panel-header"><h2>Public site settings</h2></div><div class="panel-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings_update"><div class="form-group"><label for="tagline">Homepage tagline</label><input id="tagline" type="text" name="site_tagline" maxlength="300" value="<?= e(setting($pdo, 'site_tagline', '')) ?>"></div><div class="form-group"><label for="notice">Homepage notice</label><textarea id="notice" name="homepage_notice" rows="3" maxlength="500"><?= e(setting($pdo, 'homepage_notice', '')) ?></textarea></div><div class="form-group"><label for="meta-description">Default SEO description</label><textarea id="meta-description" name="default_meta_description" rows="3" maxlength="320"><?= e(setting($pdo, 'default_meta_description', '')) ?></textarea></div><div class="form-group"><label class="checkbox"><input type="checkbox" name="allow_registration" value="1" <?= setting($pdo, 'allow_registration', '1') === '1' ? 'checked' : '' ?>> Allow new account registration</label></div><div class="form-group"><label class="checkbox"><input type="checkbox" name="require_review" value="1" <?= setting($pdo, 'require_review', '0') === '1' ? 'checked' : '' ?>> Require moderator review for new articles</label></div><button class="button button-primary" type="submit">Save settings</button></form><hr><p class="form-help">Database, ImgBB, site URL, and IndexNow secrets are intentionally managed in <code>config/local.php</code> or environment variables—not in this public admin form.</p></div></section>
<?php elseif ($tab === 'media'): ?>
<section class="panel"><div class="panel-header"><h2>ImgBB media library</h2><span class="badge"><?= format_number($stats['images']) ?> files</span></div><?php if ($media): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Preview</th><th>Details</th><th>Uploaded by</th><th>Date</th></tr></thead><tbody><?php foreach ($media as $item): ?><tr><td><a href="<?= e($item['file_path']) ?>" target="_blank" rel="noopener"><img src="<?= e($item['file_path']) ?>" alt="<?= e($item['alt_text']) ?>" loading="lazy" style="width:80px;height:55px;object-fit:cover"></a></td><td><?= e($item['alt_text'] ?: 'No alternative text') ?><br><small><?= e($item['mime_type'] ?: 'image') ?><?= $item['width'] ? ' · ' . (int) $item['width'] . '×' . (int) $item['height'] : '' ?></small></td><td><?= e($item['username'] ?: 'Unknown') ?></td><td><?= e(time_ago($item['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">No images have been uploaded through ImgBB yet.</div><?php endif; ?></section>
<?php elseif ($tab === 'reports'): ?>
<section class="panel"><div class="panel-header"><h2>Community reports</h2></div><?php if ($reports): ?><div class="table-responsive"><table class="list-table"><thead><tr><th>Report</th><th>Page</th><th>Reporter</th><th>Status</th></tr></thead><tbody><?php foreach ($reports as $item): ?><tr><td><strong><?= e($item['reason']) ?></strong><br><small><?= e($item['details'] ?: 'No additional detail') ?></small></td><td><?= $item['slug'] ? '<a href="/wiki/' . e($item['slug']) . '">' . e($item['title']) . '</a>' : '—' ?></td><td><?= e($item['username'] ?: 'Anonymous') ?></td><td><form class="inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="report_update"><input type="hidden" name="report_id" value="<?= (int) $item['id'] ?>"><select name="status"><?php foreach (['open','reviewing','resolved','dismissed'] as $state): ?><option value="<?= $state ?>" <?= $item['status'] === $state ? 'selected' : '' ?>><?= ucfirst($state) ?></option><?php endforeach; ?></select><button class="button" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state">No community reports. Everything looks clear.</div><?php endif; ?></section>
<?php elseif ($tab === 'activity'): ?>
<section class="panel"><div class="panel-header"><h2>Audit trail</h2><span>IP addresses are stored only as irreversible hashes.</span></div><div class="table-responsive"><table class="list-table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th></tr></thead><tbody><?php foreach ($activity as $item): ?><tr><td><?= e($item['created_at']) ?> UTC</td><td><?= e($item['username'] ?: 'System') ?></td><td><?= e($item['action']) ?></td><td><?= e(($item['entity_type'] ?: '—') . ($item['entity_id'] ? ' #' . $item['entity_id'] : '')) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
</section></main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
