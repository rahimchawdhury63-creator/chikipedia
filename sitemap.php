<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once APP_ROOT . '/includes/PolicyCatalog.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$limit = 45000;

if (!empty($_GET['images'])) {
    $imageWhere = "status = 'published' AND (featured_image IS NOT NULL OR content LIKE '%[[File:https://%' OR content LIKE '%[[Image:https://%')";
    $imageTotal = (int) $pdo->query("SELECT COUNT(*) FROM articles WHERE {$imageWhere}")->fetchColumn();
    $imagePage = max(0, (int) ($_GET['page'] ?? 0));
    if ($imagePage === 0 && $imageTotal > $limit) {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        for ($index = 1, $pages = (int) ceil($imageTotal / $limit); $index <= $pages; $index++) {
            echo '<sitemap><loc>' . $escape(site_url('/sitemap-images-' . $index . '.xml')) . '</loc><lastmod>' . gmdate('c') . '</lastmod></sitemap>';
        }
        echo '</sitemapindex>';
        exit;
    }
    $imagePage = max(1, $imagePage);
    $imageOffset = ($imagePage - 1) * $limit;
    $stmt = $pdo->query("SELECT title, slug, content, featured_image FROM articles WHERE {$imageWhere} ORDER BY id DESC LIMIT {$limit} OFFSET {$imageOffset}");
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    while ($article = $stmt->fetch()) {
        $images = [];
        if (!empty($article['featured_image'])) {
            $images[] = $article['featured_image'];
        }
        preg_match_all('/\[\[(?:File|Image|চিত্র):\s*(https:\/\/[^\]|\s]+)/iu', (string) $article['content'], $matches);
        foreach ($matches[1] ?? [] as $imageUrl) {
            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $images[] = $imageUrl;
            }
        }
        $images = array_slice(array_values(array_unique($images)), 0, 100);
        if (!$images) {
            continue;
        }
        echo '<url><loc>' . $escape(site_url('/wiki/' . rawurlencode($article['slug']))) . '</loc>';
        foreach ($images as $imageUrl) {
            echo '<image:image><image:loc>' . $escape($imageUrl) . '</image:loc><image:title>' . $escape($article['title']) . '</image:title></image:image>';
        }
        echo '</url>';
    }
    echo '</urlset>';
    exit;
}

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
$stmt = $pdo->query("SELECT title, slug, updated_at, featured_image FROM articles WHERE status = 'published' ORDER BY id LIMIT {$limit} OFFSET {$offset}");
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
if ($articlePage === 1) {
    foreach ([['/', 'daily', '1.0'], ['/community', 'daily', '0.8'], ['/events', 'daily', '0.75'], ['/bots/requests', 'daily', '0.65'], ['/api/docs', 'monthly', '0.5'], ['/policies', 'monthly', '0.8'], ['/categories', 'weekly', '0.7'], ['/special/recent', 'hourly', '0.6'], ['/special/popular', 'daily', '0.7']] as [$path, $frequency, $priority]) {
        echo '<url><loc>' . $escape(site_url($path)) . '</loc><lastmod>' . gmdate('c') . '</lastmod><changefreq>' . $frequency . '</changefreq><priority>' . $priority . '</priority></url>';
    }
    foreach (array_keys(policy_catalog()) as $policySlug) {
        echo '<url><loc>' . $escape(site_url('/policy/' . rawurlencode($policySlug))) . '</loc><changefreq>monthly</changefreq><priority>0.65</priority></url>';
    }
    $categories = $pdo->query('SELECT slug FROM categories ORDER BY id')->fetchAll();
    foreach ($categories as $category) echo '<url><loc>' . $escape(site_url('/category/' . rawurlencode($category['slug']))) . '</loc><changefreq>weekly</changefreq><priority>0.6</priority></url>';
    $events = $pdo->query("SELECT slug,updated_at FROM community_events WHERE status IN ('published','cancelled') AND ends_at>=UTC_TIMESTAMP() ORDER BY starts_at")->fetchAll();
    foreach($events as $event) echo '<url><loc>'.$escape(site_url('/event/'.rawurlencode($event['slug']))).'</loc><lastmod>'.$escape(gmdate('c',strtotime($event['updated_at']))).'</lastmod><changefreq>weekly</changefreq><priority>0.65</priority></url>';
}
while ($article = $stmt->fetch()) {
    echo '<url><loc>' . $escape(site_url('/wiki/' . rawurlencode($article['slug']))) . '</loc><lastmod>' . $escape(gmdate('c', strtotime($article['updated_at']))) . '</lastmod><changefreq>weekly</changefreq><priority>0.8</priority>';
    if (!empty($article['featured_image'])) {
        echo '<image:image><image:loc>' . $escape($article['featured_image']) . '</image:loc><image:title>' . $escape($article['title']) . '</image:title></image:image>';
    }
    echo '</url>';
}
echo '</urlset>';
