<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$limit = 45000;
$total = (int) $pdo->query("SELECT COUNT(*) FROM articles WHERE status = 'published'")->fetchColumn();
$page = max(0, (int) ($_GET['page'] ?? 0));
if ($page === 0 && $total > $limit) {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $pages = (int) ceil($total / $limit);
    for ($i = 1; $i <= $pages; $i++) {
        echo '<sitemap><loc>' . $escape(site_url('/sitemap-' . $i . '.xml')) . '</loc><lastmod>' . gmdate('c') . '</lastmod></sitemap>';
    }
    echo '</sitemapindex>'; exit;
}
$articlePage = max(1, $page); $offset = ($articlePage - 1) * $limit;
$stmt = $pdo->query("SELECT slug, updated_at FROM articles WHERE status = 'published' ORDER BY id LIMIT {$limit} OFFSET {$offset}");
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
if ($articlePage === 1) {
    foreach ([['/', 'daily', '1.0'], ['/categories', 'weekly', '0.7'], ['/special/recent', 'hourly', '0.6'], ['/special/popular', 'daily', '0.7']] as [$path, $frequency, $priority]) {
        echo '<url><loc>' . $escape(site_url($path)) . '</loc><lastmod>' . gmdate('c') . '</lastmod><changefreq>' . $frequency . '</changefreq><priority>' . $priority . '</priority></url>';
    }
    $categories = $pdo->query('SELECT slug FROM categories ORDER BY id')->fetchAll();
    foreach ($categories as $category) echo '<url><loc>' . $escape(site_url('/category/' . rawurlencode($category['slug']))) . '</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>';
}
while ($article = $stmt->fetch()) {
    echo '<url><loc>' . $escape(site_url('/wiki/' . rawurlencode($article['slug']))) . '</loc><lastmod>' . $escape(gmdate('c', strtotime($article['updated_at']))) . '</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>';
}
echo '</urlset>';
