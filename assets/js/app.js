(() => {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

    const menuButton = $('[data-menu-toggle]');
    const mobileMenu = $('[data-mobile-menu]');
    if (menuButton && mobileMenu) {
        const close = () => {
            mobileMenu.hidden = true;
            menuButton.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('menu-open');
        };
        menuButton.addEventListener('click', () => {
            const opening = mobileMenu.hidden;
            mobileMenu.hidden = !opening;
            menuButton.setAttribute('aria-expanded', String(opening));
            document.body.classList.toggle('menu-open', opening);
        });
        mobileMenu.addEventListener('click', event => {
            if (event.target === mobileMenu || event.target.matches('a')) close();
        });
        window.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
    }

    const accountButton = $('[data-account-toggle]');
    const accountMenu = $('[data-account-menu]');
    if (accountButton && accountMenu) {
        accountButton.addEventListener('click', event => {
            event.stopPropagation();
            accountMenu.hidden = !accountMenu.hidden;
            accountButton.setAttribute('aria-expanded', String(!accountMenu.hidden));
        });
        document.addEventListener('click', event => {
            if (!accountMenu.contains(event.target) && event.target !== accountButton) {
                accountMenu.hidden = true;
                accountButton.setAttribute('aria-expanded', 'false');
            }
        });
    }

    $$('[data-dismiss]').forEach(button => {
        button.addEventListener('click', () => button.closest('.flash')?.remove());
    });
    $$('.flash').forEach(flash => setTimeout(() => {
        flash.style.opacity = '0';
        setTimeout(() => flash.remove(), 250);
    }, 6500));

    const tocButton = $('[data-toc-toggle]');
    if (tocButton) {
        tocButton.addEventListener('click', () => {
            const toc = tocButton.closest('.table-of-contents');
            const collapsed = toc.classList.toggle('collapsed');
            tocButton.textContent = collapsed ? 'show' : 'hide';
            tocButton.setAttribute('aria-expanded', String(!collapsed));
        });
    }

    const article = $('.wiki-content');
    const progress = $('[data-reading-progress]');
    if (article && progress && article.scrollHeight > 700) {
        progress.hidden = false;
        const updateProgress = () => {
            const rect = article.getBoundingClientRect();
            const total = article.offsetHeight + window.innerHeight * .3;
            const consumed = Math.min(total, Math.max(0, window.innerHeight * .2 - rect.top));
            progress.style.setProperty('--progress', `${(consumed / total) * 100}%`);
        };
        document.addEventListener('scroll', updateProgress, { passive: true });
        updateProgress();
    }

    const searchInput = $('[data-search-input]');
    const suggestions = $('[data-search-suggestions]');
    if (searchInput && suggestions) {
        let timer;
        let controller;
        let activeIndex = -1;
        const hide = () => { suggestions.hidden = true; activeIndex = -1; };
        const escapeHtml = value => String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
        const search = async () => {
            const query = searchInput.value.trim();
            if (query.length < 2) return hide();
            controller?.abort();
            controller = new AbortController();
            try {
                const response = await fetch(`/api/search?q=${encodeURIComponent(query)}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) return hide();
                const data = await response.json();
                if (!data.results?.length) {
                    const alternatives = (data.suggestions || []).map(item => `<a class="search-suggestion" href="/wiki/${encodeURIComponent(item.slug)}"><span><strong>${escapeHtml(item.title)}</strong><span>Suggested title</span></span></a>`).join('');
                    suggestions.innerHTML = alternatives + `<a class="search-suggestion" href="/create?title=${encodeURIComponent(query)}"><span><strong>Create “${escapeHtml(query)}”</strong><span>No exact page? Start one.</span></span></a>`;
                } else {
                    suggestions.innerHTML = data.results.map(item => `<a class="search-suggestion" href="/wiki/${encodeURIComponent(item.slug)}"><span><strong>${escapeHtml(item.title)}</strong><span>${escapeHtml(item.excerpt || '')}</span></span></a>`).join('');
                }
                suggestions.hidden = false;
            } catch (error) {
                if (error.name !== 'AbortError') hide();
            }
        };
        searchInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 180); });
        searchInput.addEventListener('keydown', event => {
            const items = $$('.search-suggestion', suggestions);
            if (!items.length || suggestions.hidden) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach((item, index) => item.classList.toggle('active', index === activeIndex));
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            } else if (event.key === 'Enter' && activeIndex >= 0) {
                event.preventDefault();
                items[activeIndex].click();
            } else if (event.key === 'Escape') hide();
        });
        document.addEventListener('click', event => {
            if (!suggestions.contains(event.target) && event.target !== searchInput) hide();
        });
    }

    $$('[data-copy-url]').forEach(button => button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copyUrl || location.href);
            const old = button.textContent;
            button.textContent = 'Copied';
            setTimeout(() => { button.textContent = old; }, 1400);
        } catch (_) {
            window.prompt('Copy this link:', button.dataset.copyUrl || location.href);
        }
    }));

    const password = $('[data-password]');
    const meter = $('[data-password-meter]');
    if (password && meter) {
        password.addEventListener('input', () => {
            const value = password.value;
            let score = 0;
            if (value.length >= 8) score++;
            if (value.length >= 12) score++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;
            const percentages = [0, 20, 40, 65, 82, 100];
            const colors = ['#b32424', '#b32424', '#ac6600', '#ac6600', '#14866d', '#14866d'];
            meter.style.setProperty('--strength', `${percentages[score]}%`);
            meter.style.setProperty('--meter-color', colors[score]);
        });
    }

    $$('[data-confirm]').forEach(element => element.addEventListener('click', event => {
        if (!window.confirm(element.dataset.confirm || 'Are you sure?')) event.preventDefault();
    }));

    // Database timestamps are UTC. Present exact times in the reader's locale while
    // retaining machine-readable UTC values for history, audits, and structured data.
    const timezoneSelect=$('[data-timezone-select]');
    if(timezoneSelect){const browserZone=Intl.DateTimeFormat().resolvedOptions().timeZone;if(browserZone&&[...timezoneSelect.options].some(option=>option.value===browserZone))timezoneSelect.value=browserZone;}

    $$('[data-utc-time]').forEach(element => {
        const date = new Date(element.dataset.utcTime);
        if (Number.isNaN(date.getTime())) return;
        const exact = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZoneName: 'short' }).format(date);
        element.title = exact;
        if (!element.dataset.relative) element.textContent = exact;
        else {
            const seconds = Math.round((date.getTime() - Date.now()) / 1000);
            const units = [[31536000,'year'],[2592000,'month'],[604800,'week'],[86400,'day'],[3600,'hour'],[60,'minute']];
            const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
            const selected = units.find(([size]) => Math.abs(seconds) >= size);
            element.textContent = selected ? formatter.format(Math.round(seconds / selected[0]), selected[1]) : 'just now';
        }
    });

    if ('serviceWorker' in navigator && location.protocol === 'https:') {
        window.addEventListener('load', () => navigator.serviceWorker.register('/service-worker.js').catch(() => {}));
    }
})();
