<?php
declare(strict_types=1);
$page_scripts = ['/assets/js/editor.js?v=7'];
$page_robots = 'noindex,nofollow';
require APP_ROOT . '/includes/header.php';
?>
<main id="main-content" class="editor-page">
    <header class="editor-header">
        <div><span class="eyebrow">Visual + source editor</span><h1><?= e($editorTitle) ?></h1><p>Edit visually or use MediaWiki-style source. Both modes share one autosaved, revisioned article.</p></div>
        <a class="button" href="<?= $articleId ? '/wiki/' . e($articleSlug) : '/' ?>">Cancel</a>
    </header>
    <?php if ($error): ?><div class="form-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e($formAction) ?>" data-editor-form data-article-id="<?= (int) $articleId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="article_updated_at" value="<?= e($articleUpdatedAt) ?>">
        <input type="hidden" name="remote_import_id" value="<?= (int) ($sourceImportId ?? 0) ?>" data-remote-import-id>
        <div class="form-group">
            <label for="editor-title">Article title</label>
            <input class="editor-title-input" id="editor-title" name="title" type="text" value="<?= e($sourceTitle) ?>" maxlength="255" required autocomplete="off" placeholder="A clear, recognizable title" data-editor-title>
        </div>
        <div class="editor-shell">
            <div class="editor-toolbar" role="toolbar" aria-label="Wiki formatting">
                <div class="toolbar-group">
                    <button type="button" title="Bold" data-wrap="'''|'''" data-visual-command="bold"><strong>B</strong></button>
                    <button type="button" title="Italic" data-wrap="''|''" data-visual-command="italic"><em>I</em></button>
                    <button type="button" title="Internal wiki link" data-wrap="[[|]]" data-visual-action="internal-link">Link</button>
                    <button type="button" title="External source link" data-wrap="[https://example.com |]" data-visual-action="external-link">↗</button>
                </div>
                <div class="toolbar-group">
                    <button type="button" title="Section heading" data-line="== | ==" data-visual-block="h2">H2</button>
                    <button type="button" title="Bulleted list" data-line="* |" data-visual-command="insertUnorderedList">• List</button>
                    <button type="button" title="Numbered list" data-line="# |" data-visual-command="insertOrderedList">1. List</button>
                    <button type="button" title="Quote" data-template="{{Quote|Quoted text|Source}}" data-visual-action="quote">“ ”</button>
                </div>
                <div class="toolbar-group">
                    <button type="button" title="Citation" data-wrap="<ref>|</ref>" data-visual-action="citation">Cite</button>
                    <button type="button" title="Infobox" data-template="{{Infobox\n| title = Article title\n| image = \n| caption = \n| type = \n| location = \n}}" data-visual-action="template">Info</button>
                    <button type="button" title="Table" data-template="{|\n|+ Table title\n|-\n! Heading 1 !! Heading 2\n|-\n| Cell 1 || Cell 2\n|}" data-visual-action="template">Table</button>
                    <button type="button" title="Upload image" data-image-upload>Image</button>
                    <?php if(!$articleId):?><button type="button" title="Import a licensed encyclopedia article and transfer its reusable images to ImgBB" data-article-import>Import</button><?php endif;?>
                </div>
                <div class="toolbar-group editor-history-tools">
                    <button type="button" title="Undo" data-editor-history="undo">↶</button>
                    <button type="button" title="Redo" data-editor-history="redo">↷</button>
                    <button type="button" title="Remove formatting" data-action="clear-formatting" data-visual-command="removeFormat">Clear</button>
                </div>
                <div class="editor-tabs" role="tablist">
                    <button type="button" role="tab" aria-selected="false" data-editor-tab="visual">Visual</button>
                    <button class="active" type="button" role="tab" aria-selected="true" data-editor-tab="source">Source</button>
                    <button type="button" role="tab" aria-selected="false" data-editor-tab="split">Split</button>
                    <button type="button" role="tab" aria-selected="false" data-editor-tab="preview">Preview</button>
                </div>
            </div>
            <div class="editor-workspace" data-editor-workspace>
                <div class="visual-editor wiki-content" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Visual article editor" data-visual-editor hidden></div>
                <textarea class="wiki-editor" id="wiki-source" name="content" spellcheck="true" data-wiki-editor placeholder="Start with a short lead paragraph.&#10;&#10;== History ==&#10;Write a well-sourced section here.<ref>Source title, publisher, date, URL</ref>&#10;&#10;[[Category:Knowledge]]"><?= e($sourceContent) ?></textarea>
                <div class="editor-preview wiki-content" data-editor-preview hidden><p class="empty-state">Your formatted preview will appear here.</p></div>
            </div>
            <div class="editor-status"><span data-save-status>Draft autosave is ready</span><span><span data-word-count>0</span> words · <span data-char-count>0</span> characters</span></div>
        </div>

        <div class="editor-options">
            <section class="panel">
                <div class="panel-header"><h2>Save your contribution</h2></div>
                <div class="panel-body">
                    <div class="form-group"><label for="edit-summary">Edit summary <span class="label-hint">Briefly describe what changed</span></label><input id="edit-summary" type="text" name="edit_summary" maxlength="255" value="<?= e($sourceSummary) ?>" placeholder="e.g. Added history section and two references" data-edit-summary></div>
                    <details class="form-group"><summary><strong>Search appearance</strong> <span class="form-help">Optional SEO overrides</span></summary><div style="padding-top:12px"><label for="seo-title">Search title <span class="label-hint">Recommended: 50–60 characters</span></label><input id="seo-title" type="text" name="seo_title" maxlength="255" value="<?= e($sourceSeoTitle) ?>" placeholder="Defaults to the article title" data-seo-title><label for="seo-description" style="margin-top:12px">Search description <span class="label-hint">Recommended: 140–160 characters</span></label><textarea id="seo-description" name="seo_description" rows="3" maxlength="320" placeholder="Defaults to an automatic article excerpt" data-seo-description><?= e($sourceSeoDescription) ?></textarea></div></details>
                    <label class="checkbox"><input type="checkbox" name="is_minor" value="1"> This is a minor edit (spelling, formatting, or small corrections)</label>
                    <div class="form-actions">
                        <button class="button" type="submit" name="submit_action" value="draft">Save draft</button>
                        <button class="button button-primary button-large" type="submit" name="submit_action" value="publish"><?= $canPublish ? ($articleId ? 'Publish changes' : 'Publish article') : 'Submit for review' ?></button>
                    </div>
                    <p class="form-help">By saving, you agree that your contribution may be edited by the community. Do not submit copyrighted text without permission.</p>
                </div>
            </section>
            <aside class="panel editor-help">
                <div class="panel-header"><h2>Wiki markup guide</h2></div>
                <div class="panel-body">
                    <details open><summary>Visual and source modes</summary><p>Visual mode handles everyday formatting. Blue source capsules preserve citations, templates, tables, categories, and media exactly; double-click or press Enter on a capsule to edit its wiki code.</p></details>
                    <details><summary>Text &amp; sections</summary><p><span class="syntax-chip">'''bold'''</span> · <span class="syntax-chip">''italic''</span><br><span class="syntax-chip">== Section heading ==</span></p></details>
                    <details><summary>Links &amp; media</summary><p><span class="syntax-chip">[[Article|label]]</span><br><span class="syntax-chip">[https://site.tld label]</span><br>Use the Image button to upload to ImgBB and insert the correct file code.</p></details>
                    <details><summary>Sources</summary><p>Add citations after a claim: <span class="syntax-chip">&lt;ref&gt;Reliable source details&lt;/ref&gt;</span>. References are listed automatically.</p></details>
                    <details><summary>Categories &amp; templates</summary><p><span class="syntax-chip">[[Category:History]]</span><br><span class="syntax-chip">{{Note|Useful context}}</span></p></details>
                    <p><a href="/policy/editing-style" target="_blank">Open the full editing guide ↗</a> · <a href="/policy/copyright-licensing" target="_blank">Copyright rules ↗</a></p>
                </div>
            </aside>
        </div>
    </form>
</main>
<dialog class="upload-dialog" aria-labelledby="upload-dialog-title" data-upload-dialog>
    <form method="dialog" data-upload-form>
        <h2 id="upload-dialog-title">Upload an image</h2>
        <p>Images are uploaded securely to ImgBB. Use only files you have the right to share.</p>
        <div class="upload-dropzone">
            <label for="wiki-image-file">Choose JPEG, PNG, GIF, or WebP (max 16 MB)</label>
            <input id="wiki-image-file" type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp" required data-upload-file>
        </div>
        <div class="form-group"><label for="wiki-image-alt">Alternative text</label><input id="wiki-image-alt" type="text" name="alt_text" maxlength="255" required placeholder="Describe what the image shows"></div>
        <div class="form-group"><label for="wiki-image-caption">Caption</label><input id="wiki-image-caption" type="text" name="caption" maxlength="255" placeholder="Context shown below the image"></div>
        <div class="upload-progress" role="progressbar" aria-label="Image upload progress" hidden data-upload-progress><span></span></div>
        <p class="form-error" role="alert" hidden data-upload-error></p>
        <div class="form-actions"><button class="button button-primary" type="submit" value="upload">Upload &amp; insert</button><button class="button" type="button" data-upload-close>Cancel</button></div>
    </form>
</dialog>
<dialog class="upload-dialog import-dialog" aria-labelledby="import-dialog-title" data-import-dialog>
    <form method="dialog" data-import-form>
        <h2 id="import-dialog-title">Import a licensed encyclopedia draft</h2>
        <p>Paste a Wikipedia, MediaWiki, or other encyclopedia article URL. BanglaVerseWiki will create an editable draft and transfer up to three detected reusable images to ImgBB—never hotlinking them.</p>
        <div class="form-group"><label for="import-source-url">Article URL</label><input id="import-source-url" type="url" name="source_url" required placeholder="https://en.wikipedia.org/wiki/Example"></div>
        <div class="form-group"><label for="import-license">Reuse license</label><select id="import-license" name="license" required><option value="CC BY-SA 4.0">CC BY-SA 4.0</option><option value="CC BY-SA 3.0">CC BY-SA 3.0</option><option value="CC0 / Public domain">CC0 / Public domain</option><option value="Permission obtained">Permission obtained</option></select></div>
        <label class="checkbox"><input type="checkbox" name="rights_confirmed" value="1" required> I verified that this source and its detected images permit reuse under the selected license. I will review the draft and preserve attribution.</label>
        <div class="upload-progress" role="progressbar" aria-label="Article import progress" hidden data-import-progress><span></span></div>
        <p class="form-error" role="alert" hidden data-import-error></p>
        <div class="form-actions"><button class="button button-primary" type="submit" value="import">Import draft &amp; images</button><button class="button" type="button" data-import-close>Cancel</button></div>
    </form>
</dialog>
<?php require APP_ROOT . '/includes/footer.php'; ?>
