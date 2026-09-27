<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/includes/PolicyCatalog.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$catalog = policy_catalog();
if ($slug !== '') {
    $policy = policy_by_slug($slug);
    if (!$policy) {
        http_response_code(404);
        $page_title = 'Policy not found — ' . SITE_NAME;
        $page_robots = 'noindex,follow';
        require APP_ROOT . '/includes/header.php';
        echo '<main id="main-content" class="page-narrow"><div class="empty-state"><h1>Policy not found</h1><p>The requested policy page does not exist.</p><a class="button" href="/policies">Browse all policies</a></div></main>';
        require APP_ROOT . '/includes/footer.php';
        exit;
    }
    $page_title = $policy['title'] . ' — ' . SITE_NAME . ' policy';
    $page_description = $policy['summary'];
    $page_canonical = site_url('/policy/' . rawurlencode($slug));
    require APP_ROOT . '/includes/header.php';
    ?>
    <main id="main-content" class="policy-layout page-container">
        <aside class="policy-sidebar"><a href="/policies">← All policies</a><strong><?= e($policy['category']) ?></strong><nav><?php foreach ($catalog as $itemSlug => $item): if ($item['category'] !== $policy['category']) continue; ?><a class="<?= $itemSlug === $slug ? 'active' : '' ?>" href="/policy/<?= e($itemSlug) ?>"><?= e($item['title']) ?></a><?php endforeach; ?></nav></aside>
        <article class="policy-article">
            <header class="page-heading"><span class="eyebrow">BanglaVerseWiki policy</span><h1><?= e($policy['title']) ?></h1><p><?= e($policy['summary']) ?></p><div class="policy-status"><span>Applies to all contributors</span><span>Community standard</span><span>Administrator enforceable</span></div></header>
            <div class="wiki-notice"><strong>Policy in brief:</strong> Follow this standard when contributing. Discuss uncertain applications with the community; urgent safety, copyright, privacy, and living-person issues may be handled immediately.</div>
            <?php foreach ($policy['sections'] as $heading => $points): ?><section class="policy-section"><h2><?= e($heading) ?></h2><ul><?php foreach ($points as $point): ?><li><?= e($point) ?></li><?php endforeach; ?></ul></section><?php endforeach; ?>
            <section class="policy-help"><h2>Questions or concerns?</h2><p>Raise content questions on the relevant article talk page. Use the community portal for general discussion, or contact an administrator privately for security, legal, privacy, or harassment concerns.</p><div class="form-actions"><a class="button button-primary" href="/community">Community portal</a><a class="button" href="mailto:rrc@bsdc.info.bd">Contact administration</a></div></section>
        </article>
    </main>
    <script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $policy['title'], 'description' => $policy['summary'], 'publisher' => ['@type' => 'Organization', 'name' => SITE_NAME], 'mainEntityOfPage' => $page_canonical, 'inLanguage' => SITE_LANGUAGE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

$groups = [];
foreach ($catalog as $itemSlug => $policy) {
    $groups[$policy['category']][$itemSlug] = $policy;
}
$page_title = 'Policies and guidelines — ' . SITE_NAME;
$page_description = 'The complete content, conduct, editing, safety, automation, and administration standards for BanglaVerseWiki.';
$page_canonical = site_url('/policies');
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="page-container policy-index">
<header class="page-heading"><span class="eyebrow">Rules and guidelines</span><h1>Policies that protect open knowledge</h1><p>These standards explain how to write reliable articles, collaborate respectfully, use automation safely, and administer the encyclopedia accountably.</p></header>
<nav class="policy-principles" aria-label="Core policy principles"><a href="/policy/founding-principles"><strong>1</strong><span>Encyclopedic purpose</span></a><a href="/policy/neutral-point-of-view"><strong>2</strong><span>Neutrality</span></a><a href="/policy/verifiability"><strong>3</strong><span>Verifiability</span></a><a href="/policy/copyright-licensing"><strong>4</strong><span>Open licensing</span></a><a href="/policy/civility"><strong>5</strong><span>Respectful collaboration</span></a></nav>
<?php foreach ($groups as $group => $policies): ?><section class="policy-group"><div class="section-heading"><h2><?= e($group) ?></h2><span><?= count($policies) ?> standards</span></div><div class="policy-card-grid"><?php foreach ($policies as $itemSlug => $policy): ?><a class="policy-card" href="/policy/<?= e($itemSlug) ?>"><span class="card-kicker">Policy</span><h3><?= e($policy['title']) ?></h3><p><?= e($policy['summary']) ?></p><span>Read standard →</span></a><?php endforeach; ?></div></section><?php endforeach; ?>
</main>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'BanglaVerseWiki policies and guidelines', 'description' => $page_description, 'url' => $page_canonical, 'hasPart' => array_map(static fn(string $itemSlug, array $policy): array => ['@type' => 'WebPage', 'name' => $policy['title'], 'url' => site_url('/policy/' . $itemSlug)], array_keys($catalog), array_values($catalog))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php require APP_ROOT . '/includes/footer.php'; ?>
