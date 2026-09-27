<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$articleStmt = $pdo->prepare('SELECT * FROM articles WHERE slug = ? LIMIT 1');
$articleStmt->execute([$slug]);
$article = $articleStmt->fetch();
if (!$article || !article_is_visible($article)) { http_response_code(404); exit('Article not found.'); }
$protection = active_article_protection($pdo, (int) $article['id']);
$oldId = (int) ($_GET['old'] ?? 0); $newId = (int) ($_GET['new'] ?? 0);
if (!$oldId || !$newId || $oldId === $newId) { flash('warning', 'Select two different revisions to compare.'); redirect('/history/' . rawurlencode($slug)); }
$revStmt = $pdo->prepare('SELECT r.*, u.username FROM revisions r LEFT JOIN users u ON u.id = r.user_id WHERE r.article_id = ? AND r.id IN (?, ?) ORDER BY r.id');
$revStmt->execute([$article['id'], $oldId, $newId]); $revs = $revStmt->fetchAll();
if (count($revs) !== 2) { flash('error', 'One of those revisions no longer exists.'); redirect('/history/' . rawurlencode($slug)); }
$old = $revs[0]; $new = $revs[1];

function line_diff(string $before, string $after): array
{
    $a = explode("\n", str_replace("\r", '', $before));
    $b = explode("\n", str_replace("\r", '', $after));
    if (count($a) > 600 || count($b) > 600) {
        $max = max(count($a), count($b)); $rows = [];
        for ($i = 0; $i < $max; $i++) { $left = $a[$i] ?? ''; $right = $b[$i] ?? ''; $rows[] = [$left === $right ? 'same' : 'changed', $left, $right]; }
        return $rows;
    }
    $m = count($a); $n = count($b); $matrix = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = $m - 1; $i >= 0; $i--) for ($j = $n - 1; $j >= 0; $j--) $matrix[$i][$j] = $a[$i] === $b[$j] ? $matrix[$i + 1][$j + 1] + 1 : max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
    $rows = []; $i = 0; $j = 0;
    while ($i < $m || $j < $n) {
        if ($i < $m && $j < $n && $a[$i] === $b[$j]) { $rows[] = ['same', $a[$i], $b[$j]]; $i++; $j++; }
        elseif ($j < $n && ($i === $m || $matrix[$i][$j + 1] >= $matrix[$i + 1][$j])) { $rows[] = ['added', '', $b[$j++]]; }
        else { $rows[] = ['removed', $a[$i++], '']; }
    }
    return $rows;
}
$diff = line_diff($old['content'], $new['content']);
$page_title = 'Difference between revisions of ' . $article['title'] . ' — ' . SITE_NAME; $page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container">
<header class="page-heading"><span class="eyebrow">Revision comparison</span><h1><?= e($article['title']) ?></h1><p>Removed lines appear in red; added lines appear in green.</p></header>
<div class="form-actions" style="margin-bottom:18px"><a class="button" href="/history/<?= e($slug) ?>">← Revision history</a><a class="button" href="/wiki/<?= e($slug) ?>">Current article</a><?php if (is_logged_in() && can_edit_article($pdo, $article)): ?><form action="/article-action" method="post" onsubmit="return confirm('Restore this older revision as a new version?')"><?= csrf_field() ?><input type="hidden" name="article_id" value="<?= (int) $article['id'] ?>"><input type="hidden" name="revision_id" value="<?= (int) $old['id'] ?>"><input type="hidden" name="action" value="rollback"><button class="button" type="submit">Restore older revision</button></form><?php endif; ?></div>
<div class="table-responsive"><table class="diff-table"><thead><tr><th>Revision <?= (int) $old['id'] ?> · <?= e($old['username'] ?: 'Unknown') ?> · <?= e($old['created_at']) ?></th><th>Revision <?= (int) $new['id'] ?> · <?= e($new['username'] ?: 'Unknown') ?> · <?= e($new['created_at']) ?></th></tr></thead><tbody><?php foreach ($diff as [$kind, $left, $right]): ?><tr><td class="diff-line-<?= $kind === 'removed' || $kind === 'changed' ? 'old' : 'same' ?>"><?= e(($kind === 'removed' || $kind === 'changed' ? '− ' : '  ') . $left) ?></td><td class="diff-line-<?= $kind === 'added' || $kind === 'changed' ? 'new' : 'same' ?>"><?= e(($kind === 'added' || $kind === 'changed' ? '+ ' : '  ') . $right) ?></td></tr><?php endforeach; ?></tbody></table></div>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
