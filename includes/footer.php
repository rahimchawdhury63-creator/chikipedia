<?php
declare(strict_types=1);
?>
<footer class="site-footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <a class="brand" href="/"><img class="brand-logo" src="/img/brand/logo.svg" width="42" height="42" alt=""><span class="brand-copy"><strong>BanglaVerse</strong><small>WIKI</small></span></a>
            <p>Knowledge grows when it is shared. Built by readers and editors, for everyone.</p>
        </div>
        <div><strong>Explore</strong><a href="/special/recent">Recent changes</a><a href="/special/popular">Popular pages</a><a href="/categories">Categories</a><a href="/feed.xml">RSS feed</a></div>
        <div><strong>Contribute</strong><a href="/create">Create an article</a><a href="/wiki/help-editing">Editing guide</a><a href="/wiki/community-guidelines">Community guidelines</a><a href="mailto:rrc@bsdc.info.bd">Contact</a></div>
        <div><strong>Platform</strong><a href="/wiki/about-banglaversewiki">About</a><a href="/wiki/privacy-policy">Privacy</a><a href="/sitemap.xml">Sitemap</a><a href="/wiki/disclaimer">Disclaimer</a></div>
    </div>
    <div class="footer-bottom">
        <span>© <?= date('Y') ?> <?= e(SITE_NAME) ?>. Content is contributed by the community.</span>
        <span>Fast · Accessible · Open knowledge</span>
    </div>
</footer>
<script src="/assets/js/app.js?v=5" defer></script>
<?php if (!empty($page_scripts) && is_array($page_scripts)): foreach ($page_scripts as $script): ?>
<script src="<?= e($script) ?>" defer></script>
<?php endforeach; endif; ?>
</body>
</html>
