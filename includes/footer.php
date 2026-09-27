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
        <div><strong>Contribute</strong><a href="/create">Create an article</a><a href="/community">Community portal</a><a href="/events">Community events</a><a href="/bots/requests">Bot approvals</a><a href="/api/docs">Bot API</a></div>
        <div><strong>Policies</strong><a href="/policies">All policies</a><a href="/policy/privacy">Privacy</a><a href="/policy/copyright-licensing">Copyright</a><a href="mailto:rrc@bsdc.info.bd">Contact administration</a></div>
    </div>
    <div class="footer-bottom">
        <span>© <?= date('Y') ?> <?= e(SITE_NAME) ?>. Content is contributed by the community.</span>
        <span>Fast · Accessible · Open knowledge</span>
    </div>
</footer>
<script src="/assets/js/app.js?v=7" defer></script>
<?php if (!empty($page_scripts) && is_array($page_scripts)): foreach ($page_scripts as $script): ?>
<script src="<?= e($script) ?>" defer></script>
<?php endforeach; endif; ?>
</body>
</html>
