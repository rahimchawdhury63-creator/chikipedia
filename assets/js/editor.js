(() => {
    'use strict';
    const form = document.querySelector('[data-editor-form]');
    const editor = document.querySelector('[data-wiki-editor]');
    const visual = document.querySelector('[data-visual-editor]');
    if (!form || !editor || !visual) return;

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
    let currentMode = 'source';
    let dirty = false;
    let autosaveTimer;
    let previewTimer;
    let submitting = false;

    const escapeHtml = value => String(value || '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const readJsonResponse=async response=>{
        const body=await response.text();
        try{return JSON.parse(body);}catch(error){
            const requestId=response.headers.get('x-request-id');
            throw new Error(`The server returned HTTP ${response.status} without a valid JSON response${requestId?` (request ${requestId})`:''}. The request may have timed out; please try again.`);
        }
    };
    const safeWikiText = value => String(value || '').replace(/[|\[\]\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
    const capsuleLabel = (source, type = 'markup') => {
        if (/^<ref/i.test(source)) return 'Citation';
        if (/^\[\[(?:File|Image|চিত্র):/iu.test(source)) return 'Image';
        if (/^\[\[(?:Category|বিষয়শ্রেণী):/iu.test(source)) return 'Category';
        if (/^\{\|/.test(source)) return 'Wiki table';
        const template = source.match(/^\{\{\s*([^|}\n]+)/);
        return template ? `Template: ${template[1].trim()}` : type;
    };
    const capsuleHtml = (source, type = 'markup') => `<span class="visual-source-capsule capsule-${escapeHtml(type)}" contenteditable="false" tabindex="0" title="Preserved wiki source — double click or press Enter to edit" data-wiki-source="${escapeHtml(source)}">${escapeHtml(capsuleLabel(source, type))}</span>`;

    function inlineToHtml(input) {
        let text = String(input || '');
        const tokens = [];
        const hold = html => {
            const key = `\uE100${tokens.length}\uE101`;
            tokens.push(html);
            return key;
        };
        text = text.replace(/<ref(?:\s[^>]*)?>[\s\S]*?<\/ref>/gi, source => hold(capsuleHtml(source, 'citation')));
        text = text.replace(/\{\{[\s\S]*?\}\}/g, source => hold(capsuleHtml(source, 'template')));
        text = text.replace(/\[\[(?:File|Image|চিত্র|Category|বিষয়শ্রেণী):[^\]]+\]\]/giu, source => hold(capsuleHtml(source, /^\[\[(?:Category|বিষয়শ্রেণী):/iu.test(source) ? 'category' : 'media')));
        text = text.replace(/\[\[([^|\]\n]+)(?:\|([^\]\n]+))?\]\]/g, (source, target, label) => {
            const cleanTarget = target.trim();
            const cleanLabel = (label || target).trim();
            return hold(`<a href="/wiki/${encodeURIComponent(cleanTarget.replace(/\s+/g, '-'))}" data-wiki-link="${escapeHtml(cleanTarget)}">${escapeHtml(cleanLabel)}</a>`);
        });
        text = text.replace(/\[(https:\/\/[^\s\]]+)(?:\s+([^\]]+))?\]/gi, (source, url, label) => hold(`<a href="${escapeHtml(url)}" data-wiki-external="${escapeHtml(url)}">${escapeHtml((label || url).trim())}</a>`));
        text = escapeHtml(text);
        text = text.replace(/'''([^\n]+?)'''/g, '<strong>$1</strong>');
        text = text.replace(/''([^\n]+?)''/g, '<em>$1</em>');
        text = text.replace(/\uE100(\d+)\uE101/g, (match, index) => tokens[Number(index)] || match);
        return text;
    }

    function sourceToVisual(source) {
        const blockTokens = [];
        let prepared = String(source || '').replace(/\{\{[\s\S]*?\}\}/g, value => {
            if (!value.includes('\n')) return value;
            const token = `\uE200${blockTokens.length}\uE201`;
            blockTokens.push(value);
            return `\n${token}\n`;
        });
        prepared = prepared.replace(/^\{\|[\s\S]*?^\|\}/gm, value => {
            const token = `\uE200${blockTokens.length}\uE201`;
            blockTokens.push(value);
            return `\n${token}\n`;
        });
        const lines = prepared.replace(/\r\n?/g, '\n').split('\n');
        const output = [];
        let listType = '';
        let listItems = [];
        const flushList = () => {
            if (!listType) return;
            output.push(`<${listType}>${listItems.map(item => `<li>${inlineToHtml(item)}</li>`).join('')}</${listType}>`);
            listType = '';
            listItems = [];
        };
        for (const line of lines) {
            const blockMatch = line.match(/^\uE200(\d+)\uE201$/);
            if (blockMatch) {
                flushList();
                output.push(`<p class="visual-capsule-line">${capsuleHtml(blockTokens[Number(blockMatch[1])] || '', 'block')}</p>`);
                continue;
            }
            const listMatch = line.match(/^([*#])\s*(.*)$/);
            if (listMatch) {
                const requested = listMatch[1] === '*' ? 'ul' : 'ol';
                if (listType && listType !== requested) flushList();
                listType = requested;
                listItems.push(listMatch[2]);
                continue;
            }
            flushList();
            if (!line.trim()) continue;
            const heading = line.match(/^(={2,6})\s*(.*?)\s*\1$/);
            if (heading) {
                const level = Math.min(6, heading[1].length);
                output.push(`<h${level}>${inlineToHtml(heading[2])}</h${level}>`);
            } else if (/^----+$/.test(line.trim())) {
                output.push('<hr>');
            } else if (/^:\s*/.test(line)) {
                output.push(`<blockquote>${inlineToHtml(line.replace(/^:\s*/, ''))}</blockquote>`);
            } else {
                output.push(`<p>${inlineToHtml(line)}</p>`);
            }
        }
        flushList();
        return output.join('') || '<p><br></p>';
    }

    function serializeNode(node, inline = false) {
        if (node.nodeType === Node.TEXT_NODE) return (node.nodeValue || '').replace(/\u00a0/g, ' ');
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        const element = node;
        if (element.dataset?.wikiSource !== undefined) return element.dataset.wikiSource;
        const tag = element.tagName.toLowerCase();
        const children = () => Array.from(element.childNodes).map(child => serializeNode(child, inline)).join('');
        if (tag === 'a' && element.dataset.wikiLink !== undefined) {
            const label = element.textContent.trim();
            const target = element.dataset.wikiLink.trim();
            return label === target ? `[[${target}]]` : `[[${target}|${label}]]`;
        }
        if (tag === 'a' && element.dataset.wikiExternal !== undefined) return `[${element.dataset.wikiExternal} ${element.textContent.trim()}]`;
        if (tag === 'strong' || tag === 'b') return `'''${children()}'''`;
        if (tag === 'em' || tag === 'i') return `''${children()}''`;
        if (tag === 'br') return '\n';
        if (tag === 'hr') return '\n----\n';
        if (/^h[2-6]$/.test(tag)) {
            const marker = '='.repeat(Number(tag.slice(1)));
            return `\n${marker} ${children().trim()} ${marker}\n\n`;
        }
        if (tag === 'ul' || tag === 'ol') {
            const marker = tag === 'ul' ? '*' : '#';
            return `\n${Array.from(element.children).filter(child => child.tagName.toLowerCase() === 'li').map(child => `${marker} ${serializeNode(child, true).trim()}`).join('\n')}\n\n`;
        }
        if (tag === 'li') return children();
        if (tag === 'blockquote') return `\n: ${children().trim()}\n\n`;
        if (tag === 'p' || tag === 'div') return `${children().trim()}\n\n`;
        return children();
    }

    function syncSourceFromVisual() {
        const source = Array.from(visual.childNodes).map(node => serializeNode(node)).join('')
            .replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
        editor.value = source;
    }
    const renderVisual = () => { visual.innerHTML = sourceToVisual(editor.value); };

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

    const saveLocal = () => {
        try {
            localStorage.setItem(storageKey, JSON.stringify({ title: title.value, content: editor.value, summary: summary?.value || '', seoTitle: seoTitle?.value || '', seoDescription: seoDescription?.value || '', remoteImportId: remoteImportId?.value || '0', at: Date.now() }));
        } catch (_) {}
    };

    async function autosave() {
        if (currentMode === 'visual') syncSourceFromVisual();
        if (!dirty || submitting || (!title.value.trim() && !editor.value.trim())) return;
        status.textContent = 'Saving private draft…';
        saveLocal();
        const data = new FormData();
        data.set('csrf_token', csrf); data.set('article_id', articleId); data.set('title', title.value); data.set('content', editor.value);
        data.set('edit_summary', summary?.value || ''); data.set('seo_title', seoTitle?.value || ''); data.set('seo_description', seoDescription?.value || ''); data.set('remote_import_id', remoteImportId?.value || '0');
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
        dirty = true; counts(); saveLocal(); status.textContent = 'Unsaved changes';
        clearTimeout(autosaveTimer); autosaveTimer = setTimeout(autosave, 4000);
        if (!preview.hidden) { clearTimeout(previewTimer); previewTimer = setTimeout(loadPreview, 450); }
    }

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
    const sourceLineInsertion = pattern => {
        const [prefix, suffix = ''] = pattern.split('|');
        const start = editor.value.lastIndexOf('\n', editor.selectionStart - 1) + 1;
        const lineEnd = editor.value.indexOf('\n', editor.selectionEnd);
        const end = lineEnd < 0 ? editor.value.length : lineEnd;
        const selected = editor.value.slice(start, end) || 'Text';
        editor.setRangeText(prefix + selected + suffix, start, end, 'end'); editor.focus(); changed();
    };
    const insertVisualHtml = html => {
        visual.focus();
        document.execCommand('insertHTML', false, html);
        syncSourceFromVisual(); changed();
    };
    const insertWikiBlock = source => {
        if (currentMode === 'visual') insertVisualHtml(capsuleHtml(source.trim(), 'markup'));
        else selectInsertion(`\n${source.trim()}\n`);
    };
    const selectedVisualText = () => window.getSelection()?.toString().trim() || '';

    document.querySelector('.editor-toolbar')?.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button || button.hasAttribute('data-editor-tab') || button.hasAttribute('data-image-upload') || button.hasAttribute('data-article-import')) return;
        if (button.dataset.editorHistory) {
            (currentMode === 'visual' ? visual : editor).focus();
            document.execCommand(button.dataset.editorHistory);
            if (currentMode === 'visual') syncSourceFromVisual(); changed(); return;
        }
        if (currentMode === 'visual') {
            if (button.dataset.visualCommand) {
                document.execCommand(button.dataset.visualCommand, false); syncSourceFromVisual(); changed(); return;
            }
            if (button.dataset.visualBlock) {
                document.execCommand('formatBlock', false, button.dataset.visualBlock); syncSourceFromVisual(); changed(); return;
            }
            const action = button.dataset.visualAction;
            if (action === 'internal-link') {
                const label = selectedVisualText() || window.prompt('Link label') || '';
                if (!label) return;
                const target = window.prompt('BanglaVerseWiki article title', label) || '';
                if (target.trim()) insertVisualHtml(`<a href="/wiki/${encodeURIComponent(target.trim().replace(/\s+/g, '-'))}" data-wiki-link="${escapeHtml(target.trim())}">${escapeHtml(label)}</a>`);
                return;
            }
            if (action === 'external-link') {
                const url = window.prompt('HTTPS source URL', 'https://');
                let parsedUrl;
                try { parsedUrl = new URL(url); } catch (_) { return; }
                if (parsedUrl.protocol !== 'https:') return;
                const label = selectedVisualText() || window.prompt('Link label', 'Source') || 'Source';
                insertVisualHtml(`<a href="${escapeHtml(url)}" data-wiki-external="${escapeHtml(url)}">${escapeHtml(label)}</a>`); return;
            }
            if (action === 'citation') {
                const citation = window.prompt('Source details (title, publisher, date, and HTTPS URL)');
                if (citation?.trim()) insertVisualHtml(capsuleHtml(`<ref>${citation.trim()}</ref>`, 'citation'));
                return;
            }
            if (action === 'quote') {
                const quote = selectedVisualText() || window.prompt('Quoted text') || '';
                if (!quote) return;
                const quoteSource = window.prompt('Quote source') || '';
                insertVisualHtml(capsuleHtml(`{{Quote|${safeWikiText(quote)}|${safeWikiText(quoteSource)}}}`, 'template')); return;
            }
            if (action === 'template' && button.dataset.template) { insertVisualHtml(capsuleHtml(button.dataset.template, 'template')); return; }
            return;
        }
        if (button.dataset.wrap) {
            const [prefix, ...rest] = button.dataset.wrap.split('|');
            selectInsertion(prefix, rest.join('|'), 'text');
        } else if (button.dataset.line) sourceLineInsertion(button.dataset.line);
        else if (button.dataset.template) insertWikiBlock(button.dataset.template);
        else if (button.dataset.action === 'clear-formatting') {
            const start = editor.selectionStart, end = editor.selectionEnd;
            const cleaned = editor.value.slice(start, end).replace(/'{2,3}|\[\[|\]\]|<\/?(?:ref|small|sup|sub)[^>]*>/gi, '');
            editor.setRangeText(cleaned, start, end, 'select'); changed();
        }
    });

    const editSourceCapsule = capsule => {
        const replacement = window.prompt('Edit the preserved wiki source', capsule.dataset.wikiSource);
        if (replacement === null || !replacement.trim()) return;
        capsule.dataset.wikiSource = replacement.trim();
        capsule.textContent = capsuleLabel(replacement.trim());
        syncSourceFromVisual(); changed();
    };
    visual.addEventListener('dblclick', event => {
        const capsule = event.target.closest('[data-wiki-source]');
        if (capsule) editSourceCapsule(capsule);
    });
    visual.addEventListener('keydown', event => {
        const capsule = event.target.closest?.('[data-wiki-source]');
        if (capsule && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault(); editSourceCapsule(capsule);
        }
    });
    visual.addEventListener('input', () => { syncSourceFromVisual(); changed(); });
    editor.addEventListener('input', changed);
    title.addEventListener('input', changed); summary?.addEventListener('input', changed); seoTitle?.addEventListener('input', changed); seoDescription?.addEventListener('input', changed);

    const editorShortcuts = event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); autosave(); return; }
        if (event.currentTarget === editor && event.key === 'Tab') { event.preventDefault(); selectInsertion('    '); }
        if (event.currentTarget === editor && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'b') { event.preventDefault(); selectInsertion("'''", "'''", 'bold text'); }
        if (event.currentTarget === editor && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'i') { event.preventDefault(); selectInsertion("''", "''", 'italic text'); }
    };
    editor.addEventListener('keydown', editorShortcuts); visual.addEventListener('keydown', editorShortcuts);

    async function loadPreview() {
        if (currentMode === 'visual') syncSourceFromVisual();
        preview.innerHTML = '<p class="empty-state">Formatting preview…</p>';
        const data = new FormData(); data.set('csrf_token', csrf); data.set('content', editor.value);
        try {
            const response = await fetch('/api/preview', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
            const result=await readJsonResponse(response);
            if (!response.ok) throw new Error(result.error || 'Preview unavailable');
            preview.innerHTML = result.html || '<p class="empty-state">Nothing to preview yet.</p>';
        } catch (error) { preview.innerHTML = `<p class="form-error">${escapeHtml(error.message || 'Preview unavailable')}</p>`; }
    }

    function setMode(mode, button) {
        if (currentMode === 'visual' && mode !== 'visual') syncSourceFromVisual();
        currentMode = mode;
        document.querySelectorAll('[data-editor-tab]').forEach(tab => { const active = tab === button; tab.classList.toggle('active', active); tab.setAttribute('aria-selected', String(active)); });
        workspace.classList.toggle('split', mode === 'split');
        visual.hidden = mode !== 'visual';
        editor.hidden = !['source', 'split'].includes(mode);
        preview.hidden = !['preview', 'split'].includes(mode);
        document.querySelector('.editor-shell')?.setAttribute('data-editor-mode', mode);
        if (mode === 'visual') renderVisual();
        if (mode === 'preview' || mode === 'split') loadPreview();
        status.textContent = `${mode.charAt(0).toUpperCase() + mode.slice(1)} mode · ${dirty ? 'unsaved changes' : 'draft synchronized'}`;
    }
    const editorTabs = [...document.querySelectorAll('[data-editor-tab]')];
    editorTabs.forEach((button, index) => {
        button.addEventListener('click', () => setMode(button.dataset.editorTab, button));
        button.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            editorTabs[(index + direction + editorTabs.length) % editorTabs.length].focus();
        });
    });

    form.addEventListener('submit', () => { if (currentMode === 'visual') syncSourceFromVisual(); submitting = true; dirty = false; localStorage.removeItem(storageKey); });
    window.addEventListener('beforeunload', event => { if (dirty && !submitting) { event.preventDefault(); event.returnValue = ''; } });

    const uploadDialog = document.querySelector('[data-upload-dialog]');
    const uploadForm = document.querySelector('[data-upload-form]');
    const uploadFile = document.querySelector('[data-upload-file]');
    const uploadError = document.querySelector('[data-upload-error]');
    const uploadProgress = document.querySelector('[data-upload-progress]');
    document.querySelector('[data-image-upload]')?.addEventListener('click', () => uploadDialog.showModal());
    document.querySelector('[data-upload-close]')?.addEventListener('click', () => uploadDialog.close());
    uploadForm?.addEventListener('submit', event => {
        event.preventDefault(); uploadError.hidden = true;
        const file = uploadFile.files?.[0]; if (!file) return;
        const data = new FormData(uploadForm); data.set('file', file); data.set('csrf_token', csrf);
        const xhr = new XMLHttpRequest(); xhr.open('POST', '/api/upload-image'); xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-CSRF-Token', csrf);
        uploadProgress.hidden = false; uploadProgress.setAttribute('aria-valuenow', '0');
        xhr.upload.onprogress = progressEvent => { if (progressEvent.lengthComputable) { const percent = Math.round((progressEvent.loaded / progressEvent.total) * 100); uploadProgress.style.setProperty('--upload-progress', `${percent}%`); uploadProgress.setAttribute('aria-valuenow', String(percent)); } };
        xhr.onload = () => {
            let result = {}; try { result = JSON.parse(xhr.responseText); } catch (_) {}
            if (xhr.status < 200 || xhr.status >= 300 || !result.url) { uploadError.textContent = result.error || 'The upload failed. Please try again.'; uploadError.hidden = false; uploadProgress.hidden = true; return; }
            const alt = safeWikiText(uploadForm.elements.alt_text.value); const caption = safeWikiText(uploadForm.elements.caption.value);
            insertWikiBlock(`[[File:${result.url}|alt=${alt}|${caption}|right|420px]]`);
            uploadForm.reset(); uploadProgress.hidden = true; uploadDialog.close();
        };
        xhr.onerror = () => { uploadError.textContent = 'Network error during upload. Your article text is safe.'; uploadError.hidden = false; uploadProgress.hidden = true; };
        xhr.send(data);
    });

    const importDialog = document.querySelector('[data-import-dialog]');
    const importForm = document.querySelector('[data-import-form]');
    const importError = document.querySelector('[data-import-error]');
    const importProgress = document.querySelector('[data-import-progress]');
    document.querySelector('[data-article-import]')?.addEventListener('click', () => importDialog.showModal());
    document.querySelector('[data-import-close]')?.addEventListener('click', () => importDialog.close());
    importForm?.addEventListener('submit', async event => {
        event.preventDefault(); importError.hidden = true;
        if ((title.value.trim() || editor.value.trim()) && !window.confirm('Replace the current editor text with the imported draft? Copy anything you still need before continuing.')) return;
        const submitButton = importForm.querySelector('[type="submit"]'); submitButton.disabled = true; importProgress.hidden = false; importProgress.style.setProperty('--upload-progress', '35%'); importProgress.setAttribute('aria-valuenow', '35');
        status.textContent = 'Fetching article and transferring its reusable images to ImgBB…';
        const data = new FormData(importForm); data.set('csrf_token', csrf);
        try {
            const response=await fetch('/api/import-article',{method:'POST',body:data,headers:{Accept:'application/json'}});const result=await readJsonResponse(response);
            if (!response.ok) throw new Error(result.error || 'Import failed.');
            title.value = result.title || title.value; editor.value = result.content || ''; if (remoteImportId) remoteImportId.value = String(result.import_id || 0);
            if (summary) summary.value = `Imported a licensed draft from ${new URL(result.source_url).hostname}`;
            if (currentMode === 'visual') renderVisual();
            importProgress.style.setProperty('--upload-progress', '100%'); importProgress.setAttribute('aria-valuenow', '100'); importForm.reset(); importDialog.close(); changed();
            const importCounts = `${Number(result.reference_count || 0)} references · ${Number(result.category_count || 0)} categories · ${Number(result.imported_image_count || 0)}/${Number(result.detected_image_count || 0)} reusable images transferred`;
            status.textContent = `Imported ${result.source_type === 'mediawiki' ? 'MediaWiki source' : 'encyclopedia draft'} · ${importCounts}. Review before publishing.${result.warning ? ` ${result.warning}` : ''}`;
        } catch (error) { importError.textContent = error.message || 'The article could not be imported.'; importError.hidden = false; status.textContent = 'Import failed · your editor text is unchanged'; }
        finally { submitButton.disabled = false; setTimeout(() => { importProgress.hidden = true; importProgress.style.setProperty('--upload-progress', '0%'); importProgress.removeAttribute('aria-valuenow'); }, 600); }
    });

    function remoteImagesFromPaste(event) {
        const html = event.clipboardData?.getData('text/html') || '';
        if (!html || !/<img\b/i.test(html)) return [];
        const documentFragment = new DOMParser().parseFromString(html, 'text/html');
        const candidates = [];
        for (const image of documentFragment.images) {
            let rawUrl = image.getAttribute('src') || image.getAttribute('data-src') || '';
            if (!rawUrl && image.getAttribute('srcset')) rawUrl = image.getAttribute('srcset').split(',').pop().trim().split(/\s+/)[0];
            const imageUrl = rawUrl.startsWith('//') ? `https:${rawUrl}` : rawUrl;
            let parsedImageUrl; try { parsedImageUrl = new URL(imageUrl); } catch (_) { continue; }
            if (parsedImageUrl.protocol !== 'https:' || /\.svg(?:\?|$)/i.test(imageUrl) || candidates.some(item => item.url === imageUrl)) continue;
            candidates.push({ url: imageUrl, host: parsedImageUrl.hostname, alt: image.getAttribute('alt') || '' }); if (candidates.length === 10) break;
        }
        return candidates;
    }

    async function transferPastedImages(candidates, uriSource) {
        const noun = candidates.length === 1 ? 'image was' : 'images were';
        if (!window.confirm(`${candidates.length} remote encyclopedia ${noun} detected. Confirm their licenses permit reuse and transfer them to ImgBB?`)) return;
        const inserted = []; const transferErrors = []; let failures = 0;
        for (const [index, image] of candidates.entries()) {
            status.textContent = `Transferring pasted image ${index + 1} of ${candidates.length} to ImgBB…`;
            const alt = safeWikiText(image.alt || title.value || 'Imported encyclopedia image');
            const data = new FormData(); data.set('csrf_token', csrf); data.set('source_url', image.url); data.set('source_page_url', uriSource); data.set('alt_text', alt); data.set('attribution', uriSource || image.host); data.set('license', 'Reuse rights confirmed by editor'); data.set('rights_confirmed', '1');
            try {
                const response=await fetch('/api/import-image',{method:'POST',body:data,headers:{Accept:'application/json'}});const result=await readJsonResponse(response);
                if (!response.ok) throw new Error(result.error || 'Image transfer failed.');
                inserted.push(`[[File:${result.url}|alt=${alt}|${alt}|right|420px]]`);
            } catch (error) { failures++; transferErrors.push(error.message || 'transfer failed'); }
        }
        if (inserted.length) insertWikiBlock(inserted.join('\n'));
        status.textContent = failures ? `Pasted text kept · ${inserted.length} image${inserted.length === 1 ? '' : 's'} transferred, ${failures} skipped (${transferErrors[0]})` : `${inserted.length} pasted image${inserted.length === 1 ? '' : 's'} transferred to ImgBB with source metadata`;
    }

    [editor, visual].forEach(surface => surface.addEventListener('paste', event => {
        const candidates = remoteImagesFromPaste(event);
        const uriSource = event.clipboardData?.getData('text/uri-list')?.split(/\r?\n/).find(line => /^https:\/\//i.test(line)) || '';
        if (surface === visual) {
            event.preventDefault();
            const plain = event.clipboardData?.getData('text/plain') || '';
            document.execCommand('insertText', false, plain);
            syncSourceFromVisual(); changed();
        }
        if (candidates.length) setTimeout(() => transferPastedImages(candidates, uriSource), 0);
    }));
})();
