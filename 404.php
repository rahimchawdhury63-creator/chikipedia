<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
http_response_code(404);
$page_title = 'Page not found — ' . SITE_NAME;
$page_description = 'The requested page could not be found.';
$page_robots = 'noindex,follow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-narrow"><div class="empty-state"><span class="eyebrow">Error 404</span><h1>This page wandered out of the encyclopedia.</h1><p>Try searching for the topic, visit the main page, or read something unexpected.</p><form class="large-search" action="/search" method="get"><input type="search" name="q" placeholder="Search knowledge" aria-label="Search"><button class="button button-primary" type="submit">Search</button></form><div class="form-actions" style="justify-content:center;margin-top:25px"><a class="button" href="/">Main page</a><a class="button" href="/special/random">Random article</a></div></div></main>
<?php require APP_ROOT . '/includes/footer.php'; ?>
