<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
$stmt = $pdo->query("SELECT a.title, a.slug, a.content, a.excerpt, a.published_at, a.updated_at, u.username FROM articles a LEFT JOIN users u ON u.id = a.author_id WHERE a.status = 'published' ORDER BY COALESCE(a.published_at, a.created_at) DESC LIMIT 50");
$articles = $stmt->fetchAll();
$x = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
<title><?= $x(SITE_NAME) ?> — Recent articles</title>
<link><?= $x(site_url('/')) ?></link>
<description><?= $x(setting($pdo, 'default_meta_description', 'Recent articles from the free encyclopedia.')) ?></description>
<language>bn-BD</language>
<lastBuildDate><?= gmdate(DATE_RSS) ?></lastBuildDate>
<atom:link href="<?= $x(site_url('/feed.xml')) ?>" rel="self" type="application/rss+xml" />
<?php foreach ($articles as $article): $url = site_url('/wiki/' . rawurlencode($article['slug'])); ?>
<item>
<title><?= $x($article['title']) ?></title>
<link><?= $x($url) ?></link>
<guid isPermaLink="true"><?= $x($url) ?></guid>
<description><?= $x($article['excerpt'] ?: excerpt($article['content'], 300)) ?></description>
<pubDate><?= gmdate(DATE_RSS, strtotime($article['published_at'] ?: $article['updated_at'])) ?></pubDate>
<dc:creator><?= $x($article['username'] ?: SITE_NAME . ' community') ?></dc:creator>
</item>
<?php endforeach; ?>
</channel>
</rss>
