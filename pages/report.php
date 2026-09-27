<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$stmt = $pdo->prepare("SELECT id, title, slug FROM articles WHERE slug = ? AND status = 'published' LIMIT 1"); $stmt->execute([$slug]); $article = $stmt->fetch();
if (!$article) { http_response_code(404); exit('Article not found.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); $reason = (string) ($_POST['reason'] ?? ''); $details = trim((string) ($_POST['details'] ?? ''));
    $allowed = ['Inaccurate information', 'Missing or unreliable sources', 'Copyright concern', 'Harassment or harmful content', 'Spam or vandalism', 'Other'];
    if (!in_array($reason, $allowed, true)) $error = 'Choose a report reason.';
    elseif (mb_strlen($details) > 3000) $error = 'Details are limited to 3,000 characters.';
    elseif (!empty($_POST['website'])) $error = 'The report could not be submitted.';
    elseif (!empty($_SESSION['last_report']) && time() - (int) $_SESSION['last_report'] < 60) $error = 'Please wait before submitting another report.';
    else { $pdo->prepare('INSERT INTO reports (user_id, article_id, reason, details) VALUES (?, ?, ?, ?)')->execute([current_user()['id'] ?? null, $article['id'], $reason, $details]); $_SESSION['last_report'] = time(); log_activity($pdo, 'article.reported', 'article', (int) $article['id'], ['reason' => $reason]); flash('success', 'Thank you. Administrators will review the report.'); redirect('/wiki/' . rawurlencode($slug)); }
}
$page_title = 'Report ' . $article['title'] . ' — ' . SITE_NAME; $page_robots = 'noindex,nofollow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-narrow"><header class="page-heading"><span class="eyebrow">Community safety</span><h1>Report “<?= e($article['title']) ?>”</h1><p>Use this form for accuracy, sourcing, copyright, safety, spam, or vandalism concerns. For ordinary improvements, edit the article or use its talk page.</p></header><?php if ($error): ?><div class="form-error"><?= e($error) ?></div><?php endif; ?><section class="panel"><div class="panel-body"><form method="post"><?= csrf_field() ?><div class="sr-only"><label>Website<input name="website" tabindex="-1"></label></div><div class="form-group"><label for="reason">Reason</label><select id="reason" name="reason" required><option value="">Select a reason</option><?php foreach (['Inaccurate information', 'Missing or unreliable sources', 'Copyright concern', 'Harassment or harmful content', 'Spam or vandalism', 'Other'] as $reason): ?><option><?= e($reason) ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="details">Details <span class="label-hint">Optional but helpful</span></label><textarea id="details" name="details" rows="7" maxlength="3000" placeholder="Point to the specific claim or section and explain the concern."></textarea></div><div class="form-actions"><button class="button button-primary" type="submit">Submit report</button><a class="button" href="/wiki/<?= e($slug) ?>">Cancel</a></div></form></div></section></main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
