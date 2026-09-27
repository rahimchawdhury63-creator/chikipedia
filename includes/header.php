<?php
declare(strict_types=1);

if (!isset($pdo)) {
    require_once dirname(__DIR__) . '/config/database.php';
}
$page_title = $page_title ?? SITE_NAME . ' — The free Bangla encyclopedia';
$page_description = $page_description ?? setting($pdo, 'default_meta_description', 'A free, community-built encyclopedia for Bengali knowledge, culture, history and ideas.');
$page_canonical = $page_canonical ?? site_url(request_path());
$page_robots = $page_robots ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
$page_type = $page_type ?? 'website';
$page_image = $page_image ?? site_url('/img/brand/social-default.jpg');
$body_class = $body_class ?? '';
$flashes = pull_flashes();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$websiteSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    '@id' => site_url('/#website'),
    'url' => site_url('/'),
    'name' => SITE_NAME,
    'alternateName' => ['Bangla Verse Wiki', 'বাংলাভার্স উইকি'],
    'inLanguage' => ['bn', 'en'],
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => ['@type' => 'EntryPoint', 'urlTemplate' => site_url('/search?q={search_term_string}')],
        'query-input' => 'required name=search_term_string',
    ],
];
?>
<!doctype html>
<html lang="<?= e(SITE_LANGUAGE) ?>" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title><?= e($page_title) ?></title>
    <meta name="description" content="<?= e($page_description) ?>">
    <meta name="robots" content="<?= e($page_robots) ?>">
    <?php if (GOOGLE_SITE_VERIFICATION !== ''): ?><meta name="google-site-verification" content="<?= e(GOOGLE_SITE_VERIFICATION) ?>"><?php endif; ?>
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#101418" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light dark">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="canonical" href="<?= e($page_canonical) ?>">
    <link rel="alternate" type="application/rss+xml" title="<?= e(SITE_NAME) ?> recent articles" href="<?= e(site_url('/feed.xml')) ?>">
    <link rel="sitemap" type="application/xml" href="<?= e(site_url('/sitemap.xml')) ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/img/brand/logo.svg" type="image/svg+xml">
    <link rel="icon" href="/img/icon/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="/img/icon/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/img/icon/apple-touch-icon.png">
    <meta property="og:site_name" content="<?= e(SITE_NAME) ?>">
    <meta property="og:type" content="<?= e($page_type) ?>">
    <meta property="og:title" content="<?= e($page_title) ?>">
    <meta property="og:description" content="<?= e($page_description) ?>">
    <meta property="og:url" content="<?= e($page_canonical) ?>">
    <meta property="og:image" content="<?= e($page_image) ?>">
    <meta property="og:image:alt" content="<?= e($page_title) ?>">
    <?php if (str_ends_with($page_image, '/img/brand/social-default.jpg')): ?><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><?php endif; ?>
    <meta property="og:locale" content="bn_BD">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($page_title) ?>">
    <meta name="twitter:description" content="<?= e($page_description) ?>">
    <meta name="twitter:image" content="<?= e($page_image) ?>">
    <link rel="stylesheet" href="/assets/css/app.css?v=6">
    <script type="application/ld+json"><?= json_encode($websiteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="<?= e($body_class) ?>">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="reading-progress" data-reading-progress hidden></div>
<header class="site-header" data-header>
    <div class="header-inner">
        <button class="icon-button mobile-menu-button" type="button" aria-label="Open navigation" aria-expanded="false" data-menu-toggle>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <a class="brand" href="/" aria-label="<?= e(SITE_NAME) ?> home">
            <img class="brand-logo" src="/img/brand/logo.svg" width="42" height="42" alt="">
            <span class="brand-copy"><strong>BanglaVerse</strong><small>WIKI · মুক্ত বিশ্বকোষ</small></span>
        </a>
        <form class="header-search" action="/search" method="get" role="search" data-search-form>
            <input type="hidden" name="go" value="1">
            <label class="sr-only" for="global-search">Search BanglaVerseWiki</label>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
            <input id="global-search" name="q" type="search" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search knowledge…" autocomplete="off" enterkeyhint="search" data-search-input>
            <button type="submit">Search</button>
            <div class="search-suggestions" data-search-suggestions hidden></div>
        </form>
        <nav class="header-actions" aria-label="Account">
            <a class="icon-link desktop-only" href="/community" title="Community portal">Community</a>
            <a class="icon-link desktop-only" href="/special/recent" title="Recent changes">Recent changes</a>
            <?php if (is_logged_in()): ?>
                <a class="button button-primary desktop-create" href="/create">Create article</a>
                <button class="avatar-button" type="button" aria-expanded="false" data-account-toggle><?= e(mb_strtoupper(mb_substr(current_user()['username'], 0, 1))) ?></button>
                <div class="account-menu" data-account-menu hidden>
                    <div class="account-summary"><strong><?= e(current_user()['username']) ?></strong><span><?= e(ucfirst(current_user()['role'])) ?></span></div>
                    <a href="/user/<?= rawurlencode(current_user()['username']) ?>">Profile</a>
                    <a href="/account">Account settings</a>
                    <a href="/drafts">Drafts &amp; watchlist</a>
                    <?php if (can_moderate()): ?><a href="/admin"><?= is_admin() ? 'Administration' : 'Moderation' ?></a><?php endif; ?>
                    <form action="/logout" method="post"><?= csrf_field() ?><button type="submit">Sign out</button></form>
                </div>
            <?php else: ?>
                <a class="text-link desktop-only" href="/login">Log in</a>
                <a class="button button-primary" href="/register">Join</a>
            <?php endif; ?>
        </nav>
    </div>
    <nav class="mobile-drawer" aria-label="Main navigation" data-mobile-menu hidden>
        <a href="/">Main page</a>
        <a href="/search">Explore</a>
        <a href="/special/recent">Recent changes</a>
        <a href="/special/popular">Popular articles</a>
        <a href="/special/random">Random article</a>
        <a href="/categories">Categories</a>
        <a href="/community">Community portal</a>
        <a href="/policies">Policies &amp; guidelines</a>
        <?php if (is_logged_in()): ?>
            <a href="/create">Create article</a>
            <a href="/drafts">My workspace</a>
        <?php else: ?>
            <a href="/login">Log in</a>
        <?php endif; ?>
    </nav>
</header>
<?php if ($flashes): ?>
<div class="flash-stack" aria-live="polite">
    <?php foreach ($flashes as $flash): ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status"><span><?= e($flash['message']) ?></span><button type="button" aria-label="Dismiss" data-dismiss>×</button></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
