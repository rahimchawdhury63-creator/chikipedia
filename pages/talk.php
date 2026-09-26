<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
$slug = trim(rawurldecode((string) ($_GET['slug'] ?? '')));
$articleStmt = $pdo->prepare('SELECT * FROM articles WHERE slug = ? LIMIT 1');
$articleStmt->execute([$slug]); $article = $articleStmt->fetch();
if (!$article || !article_is_visible($article)) { http_response_code(404); exit('Article not found.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login(); verify_csrf();
    $body = trim((string) ($_POST['body'] ?? ''));
    if (!empty($_POST['website'])) $error = 'Your message could not be posted.';
    elseif (mb_strlen($body) < 5) $error = 'Write at least a short, constructive message.';
    elseif (mb_strlen($body) > 5000) $error = 'Discussion messages are limited to 5,000 characters.';
    else {
        $recent = $pdo->prepare('SELECT created_at FROM discussions WHERE user_id = ? ORDER BY id DESC LIMIT 1'); $recent->execute([current_user()['id']]); $last = $recent->fetchColumn();
        if ($last && time() - strtotime((string) $last) < 20) $error = 'Please wait a few seconds before posting another message.';
    }
    if ($error === '') {
        $pdo->prepare('INSERT INTO discussions (article_id, user_id, body) VALUES (?, ?, ?)')->execute([$article['id'], current_user()['id'], $body]);
        log_activity($pdo, 'discussion.posted', 'article', (int) $article['id']);
        flash('success', 'Your discussion message was posted.'); redirect('/talk/' . rawurlencode($slug));
    }
}
$postsStmt = $pdo->prepare("SELECT d.*, u.username, u.role FROM discussions d JOIN users u ON u.id = d.user_id WHERE d.article_id = ? AND d.status = 'visible' ORDER BY d.created_at"); $postsStmt->execute([$article['id']]); $posts = $postsStmt->fetchAll();
$page_title = 'Talk: ' . $article['title'] . ' — ' . SITE_NAME; $page_description = 'Community discussion about improvements to ' . $article['title'] . '.'; $page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-narrow">
<header class="page-heading"><span class="eyebrow">Article discussion</span><h1>Talk: <?= e($article['title']) ?></h1><p>Discuss reliable sources, neutrality, structure, and ways to improve the article. This is not a general forum.</p></header>
<nav class="article-tabs" aria-label="Page actions"><div class="tabs-left"><a href="/wiki/<?= e($slug) ?>">Article</a><a class="active" href="/talk/<?= e($slug) ?>">Talk</a></div><div class="tabs-right"><a href="/history/<?= e($slug) ?>">History</a><?php if (is_logged_in()): ?><a href="/edit/<?= e($slug) ?>">Edit source</a><?php endif; ?></div></nav>
<?php if ($error): ?><div class="form-error" style="margin-top:20px"><?= e($error) ?></div><?php endif; ?>
<?php if (is_logged_in()): ?><section class="panel" style="margin-top:24px"><div class="panel-header"><h2>Add to the discussion</h2></div><div class="panel-body"><form method="post"><?= csrf_field() ?><div class="sr-only"><label>Website<input name="website" tabindex="-1"></label></div><div class="form-group"><label for="talk-body">Message</label><textarea id="talk-body" name="body" rows="5" maxlength="5000" placeholder="Suggest a specific improvement and include a reliable source when possible." required></textarea></div><button class="button button-primary" type="submit">Post message</button></form></div></section><?php else: ?><div class="home-notice" style="margin-top:24px"><span><strong>Want to join this discussion?</strong> Log in or create a free editor account.</span><a href="/login?redirect=<?= rawurlencode('/talk/' . $slug) ?>">Log in →</a></div><?php endif; ?>
<section class="discussion-list" aria-label="Discussion messages"><?php if (!$posts): ?><div class="empty-state"><h2>No discussion yet</h2><p>Start with a focused suggestion for improving the article.</p></div><?php else: foreach ($posts as $post): ?><article class="discussion"><div class="discussion-avatar"><?= e(mb_strtoupper(mb_substr($post['username'], 0, 1))) ?></div><div class="discussion-body"><header><span><a href="/user/<?= rawurlencode($post['username']) ?>"><strong><?= e($post['username']) ?></strong></a> · <?= e($post['role']) ?></span><time datetime="<?= e(date('c', strtotime($post['created_at']))) ?>"><?= e(time_ago($post['created_at'])) ?></time></header><p><?= nl2br(e($post['body'])) ?></p></div></article><?php endforeach; endif; ?></section>
</main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
