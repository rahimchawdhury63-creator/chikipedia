(() => {
    'use strict';
    const form = document.querySelector('[data-editor-form]');
    const editor = document.querySelector('[data-wiki-editor]');
    if (!form || !editor) return;

    const title = document.querySelector('[data-editor-title]');
    const summary = document.querySelector('[data-edit-summary]');
    const seoTitle = document.querySelector('[data-seo-title]');
    const seoDescription = document.querySelector('[data-seo-description]');
    const remoteImportId = document.querySelector('[data-remote-import-id]');
    const preview = document.querySelector('[data-editor-preview]');
    const workspace = document.querySelector('[data-editor-workspace]');
    const status = document.querySelector('[data-save-status]');
    const words = document.querySelector('[data-word-count]');
    const chars = document.querySelector('[data-char-count]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const articleId = form.dataset.articleId || '0';
    const storageKey = `bvw-editor-${articleId}`;
    let dirty = false;
    let autosaveTimer;
    let previewTimer;
    let submitting = false;

    try {
        const recovered = JSON.parse(localStorage.getItem(storageKey) || 'null');
        const isRecent = recovered?.at && Date.now() - recovered.at < 7 * 24 * 60 * 60 * 1000;
        const differs = recovered?.content && recovered.content !== editor.value;
        if (isRecent && differs && window.confirm('A newer unsent browser draft was found. Restore it?')) {
            title.value = recovered.title || title.value;
            editor.value = recovered.content;
            if (summary) summary.value = recovered.summary || '';
            if (seoTitle) seoTitle.value = recovered.seoTitle || '';
            if (seoDescription) seoDescription.value = recovered.seoDescription || '';
            if (remoteImportId) remoteImportId.value = recovered.remoteImportId || '0';
            dirty = true;
            status.textContent = 'Browser draft restored · not yet synced';
        }
    } catch (_) {}

    const counts = () => {
        const value = editor.value.trim();
        words.textContent = value ? value.split(/\s+/u).length.toLocaleString() : '0';
        chars.textContent = editor.value.length.toLocaleString();
    };
    counts();

    const safeWikiText = value => String(value || '').replace(/[|\[\]\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();

    const selectInsertion = (prefix, suffix = '', placeholder = '') => {
        editor.focus();
        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        const selected = editor.value.slice(start, end) || placeholder;
        editor.setRangeText(prefix + selected + suffix, start, end, 'end');
        editor.selectionStart = start + prefix.length;
        editor.selectionEnd = start + prefix.length + selected.length;
        changed();
    };

    document.querySelectorAll('[data-wrap]').forEach(button => button.addEventListener('click', () => {
        const [prefix, ...rest] = button.dataset.wrap.split('|');
        selectInsertion(prefix, rest.join('|'), 'text');
    }));
    document.querySelectorAll('[data-line]').forEach(button => button.addEventListener('click', () => {
        const [prefix, suffix = ''] = button.dataset.line.split('|');
        const start = editor.value.lastIndexOf('\n', editor.selectionStart - 1) + 1;
        const lineEnd = editor.value.indexOf('\n', editor.selectionEnd);
        const end = lineEnd < 0 ? editor.value.length : lineEnd;
        const selected = editor.value.slice(start, end) || 'Text';
        editor.setRangeText(prefix + selected + suffix, start, end, 'end');
        editor.focus(); changed();
    }));
    document.querySelectorAll('[data-template]').forEach(button => button.addEventListener('click', () => selectInsertion(`\n${button.dataset.template}\n`)));

    editor.addEventListener('keydown', event => {
        if (event.key === 'Tab') {
            event.preventDefault();
            selectInsertion('    ');
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'b') {
            event.preventDefault(); selectInsertion("'''", "'''", 'bold text');
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'i') {
            event.preventDefault(); selectInsertion("''", "''", 'italic text');
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault(); autosave();
        }
    });

    const saveLocal = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify({ title: title.value, content: editor.value, summary: summary?.value || '', seoTitle: seoTitle?.value || '', seoDescription: seoDescription?.value || '', remoteImportId: remoteImportId?.value || '0', at: Date.now() }));
        } catch (_) {}
    };

    async function autosave() {
        if (!dirty || submitting || (!title.value.trim() && !editor.value.trim())) return;
        status.textContent = 'Saving private draft…';
        saveLocal();
        const data = new FormData();
        data.set('csrf_token', csrf);
        data.set('article_id', articleId);
        data.set('title', title.value);
        data.set('content', editor.value);
        data.set('edit_summary', summary?.value || '');
        data.set('seo_title', seoTitle?.value || '');
        data.set('seo_description', seoDescription?.value || '');
        data.set('remote_import_id', remoteImportId?.value || '0');
        try {
            const response = await fetch('/api/autosave', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Autosave failed');
            dirty = false;
            status.textContent = `Draft saved at ${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}`;
        } catch (_) {
            status.textContent = 'Saved in this browser · server sync unavailable';
        }
    }

    function changed() {
        dirty = true;
        counts();
        saveLocal();
        status.textContent = 'Unsaved changes';
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(autosave, 4000);
        if (!preview.hidden) {
            clearTimeout(previewTimer);
            previewTimer = setTimeout(loadPreview, 450);
        }
    }
    editor.addEventListener('input', changed);
    title.addEventListener('input', changed);
    summary?.addEventListener('input', changed);
    seoTitle?.addEventListener('input', changed);
    seoDescription?.addEventListener('input', changed);

    async function loadPreview() {
        preview.innerHTML = '<p class="empty-state">Formatting preview…</p>';
        const data = new FormData();
        data.set('csrf_token', csrf);
        data.set('content', editor.value);
        try {
            const response = await fetch('/api/preview', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Preview unavailable');
            preview.innerHTML = result.html || '<p class="empty-state">Nothing to preview yet.</p>';
        } catch (error) {
            preview.innerHTML = `<p class="form-error">${String(error.message).replace(/[&<>]/g, '')}</p>`;
        }
    }

    document.querySelectorAll('[data-editor-tab]').forEach(button => button.addEventListener('click', () => {
        const mode = button.dataset.editorTab;
        document.querySelectorAll('[data-editor-tab]').forEach(tab => {
            const active = tab === button;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        workspace.classList.toggle('split', mode === 'split');
        editor.hidden = mode === 'preview';
        preview.hidden = mode === 'source';
        if (mode !== 'source') loadPreview();
    }));

    form.addEventListener('submit', () => {
        submitting = true;
        dirty = false;
        localStorage.removeItem(storageKey);
    });
    window.addEventListener('beforeunload', event => {
        if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; }
    });

    const uploadDialog = document.querySelector('[data-upload-dialog]');
    const uploadForm = document.querySelector('[data-upload-form]');
    const uploadFile = document.querySelector('[data-upload-file]');
    const uploadError = document.querySelector('[data-upload-error]');
    const uploadProgress = document.querySelector('[data-upload-progress]');
    document.querySelector('[data-image-upload]')?.addEventListener('click', () => uploadDialog.showModal());
    document.querySelector('[data-upload-close]')?.addEventListener('click', () => uploadDialog.close());

    uploadForm?.addEventListener('submit', event => {
        event.preventDefault();
        uploadError.hidden = true;
        const file = uploadFile.files?.[0];
        if (!file) return;
        const data = new FormData(uploadForm);
        data.set('file', file);
        data.set('csrf_token', csrf);
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '/api/upload-image');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        uploadProgress.hidden = false;
        uploadProgress.setAttribute('aria-valuenow', '0');
        xhr.upload.onprogress = progressEvent => {
            if (progressEvent.lengthComputable) {
                const percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                uploadProgress.style.setProperty('--upload-progress', `${percent}%`);
                uploadProgress.setAttribute('aria-valuenow', String(percent));
            }
        };
        xhr.onload = () => {
            let result = {};
            try { result = JSON.parse(xhr.responseText); } catch (_) {}
            if (xhr.status < 200 || xhr.status >= 300 || !result.url) {
                uploadError.textContent = result.error || 'The upload failed. Please try again.';
                uploadError.hidden = false;
                uploadProgress.hidden = true;
                return;
            }
            const alt = safeWikiText(uploadForm.elements.alt_text.value);
            const caption = safeWikiText(uploadForm.elements.caption.value);
            selectInsertion(`\n[[File:${result.url}|alt=${alt}|${caption}|right|420px]]\n`);
            uploadForm.reset();
            uploadProgress.hidden = true;
            uploadDialog.close();
        };
        xhr.onerror = () => {
            uploadError.textContent = 'Network error during upload. Your article text is safe.';
            uploadError.hidden = false;
            uploadProgress.hidden = true;
        };
        xhr.send(data);
    });

    const importDialog = document.querySelector('[data-import-dialog]');
    const importForm = document.querySelector('[data-import-form]');
    const importError = document.querySelector('[data-import-error]');
    const importProgress = document.querySelector('[data-import-progress]');
    document.querySelector('[data-article-import]')?.addEventListener('click', () => importDialog.showModal());
    document.querySelector('[data-import-close]')?.addEventListener('click', () => importDialog.close());

    importForm?.addEventListener('submit', async event => {
        event.preventDefault();
        importError.hidden = true;
        if ((title.value.trim() || editor.value.trim()) && !window.confirm('Replace the current editor text with the imported draft? Copy anything you still need before continuing.')) return;
        const submitButton = importForm.querySelector('[type="submit"]');
        submitButton.disabled = true;
        importProgress.hidden = false;
        importProgress.style.setProperty('--upload-progress', '35%');
        importProgress.setAttribute('aria-valuenow', '35');
        status.textContent = 'Fetching article and transferring its reusable images to ImgBB…';
        const data = new FormData(importForm);
        data.set('csrf_token', csrf);
        try {
            const response = await fetch('/api/import-article', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Import failed.');
            title.value = result.title || title.value;
            editor.value = result.content || '';
            if (remoteImportId) remoteImportId.value = String(result.import_id || 0);
            if (summary) summary.value = `Imported a licensed draft from ${new URL(result.source_url).hostname}`;
            importProgress.style.setProperty('--upload-progress', '100%');
            importProgress.setAttribute('aria-valuenow', '100');
            importForm.reset();
            importDialog.close();
            dirty = true;
            changed();
            status.textContent = result.warning || `Imported ${result.source_type === 'mediawiki' ? 'MediaWiki' : 'encyclopedia'} draft and attribution. Review before publishing.`;
        } catch (error) {
            importError.textContent = error.message || 'The article could not be imported.';
            importError.hidden = false;
            status.textContent = 'Import failed · your editor text is unchanged';
        } finally {
            submitButton.disabled = false;
            setTimeout(() => { importProgress.hidden = true; importProgress.style.setProperty('--upload-progress', '0%'); importProgress.removeAttribute('aria-valuenow'); }, 600);
        }
    });

    // Rich-copying an article from a wiki can include several images in the
    // HTML clipboard. Keep the pasted text, then transfer a small bounded set
    // to ImgBB only after the editor confirms reuse rights.
    editor.addEventListener('paste', event => {
        const html = event.clipboardData?.getData('text/html') || '';
        if (!html || !/<img\b/i.test(html)) return;
        const documentFragment = new DOMParser().parseFromString(html, 'text/html');
        const candidates = [];
        for (const image of documentFragment.images) {
            let rawUrl = image.getAttribute('src') || image.getAttribute('data-src') || '';
            if (!rawUrl && image.getAttribute('srcset')) {
                rawUrl = image.getAttribute('srcset').split(',').pop().trim().split(/\s+/)[0];
            }
            const imageUrl = rawUrl.startsWith('//') ? `https:${rawUrl}` : rawUrl;
            let parsedImageUrl;
            try { parsedImageUrl = new URL(imageUrl); } catch (_) { continue; }
            if (parsedImageUrl.protocol !== 'https:' || /\.svg(?:\?|$)/i.test(imageUrl) || candidates.some(item => item.url === imageUrl)) continue;
            candidates.push({ url: imageUrl, host: parsedImageUrl.hostname, alt: image.getAttribute('alt') || '' });
            if (candidates.length === 3) break;
        }
        if (!candidates.length) return;
        const uriSource = event.clipboardData?.getData('text/uri-list')?.split(/\r?\n/).find(line => /^https:\/\//i.test(line)) || '';
        setTimeout(async () => {
            const noun = candidates.length === 1 ? 'image was' : 'images were';
            if (!window.confirm(`${candidates.length} remote encyclopedia ${noun} detected. Confirm their licenses permit reuse and transfer them to ImgBB?`)) return;
            const inserted = [];
            const transferErrors = [];
            let failures = 0;
            for (const [index, image] of candidates.entries()) {
                status.textContent = `Transferring pasted image ${index + 1} of ${candidates.length} to ImgBB…`;
                const alt = safeWikiText(image.alt || title.value || 'Imported encyclopedia image');
                const data = new FormData();
                data.set('csrf_token', csrf);
                data.set('source_url', image.url);
                data.set('source_page_url', uriSource);
                data.set('alt_text', alt);
                data.set('attribution', uriSource || image.host);
                data.set('license', 'Reuse rights confirmed by editor');
                data.set('rights_confirmed', '1');
                try {
                    const response = await fetch('/api/import-image', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || 'Image transfer failed.');
                    inserted.push(`[[File:${result.url}|alt=${alt}|${alt}|right|420px]]`);
                } catch (error) {
                    failures++;
                    transferErrors.push(error.message || 'transfer failed');
                }
            }
            if (inserted.length) selectInsertion(`\n${inserted.join('\n')}\n`);
            status.textContent = failures
                ? `Pasted text kept · ${inserted.length} image${inserted.length === 1 ? '' : 's'} transferred, ${failures} skipped (${transferErrors[0]})`
                : `${inserted.length} pasted image${inserted.length === 1 ? '' : 's'} transferred to ImgBB with source metadata`;
        }, 0);
    });
})();
