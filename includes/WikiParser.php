<?php
declare(strict_types=1);

/**
 * A dependency-free, safe MediaWiki-style parser designed for shared hosting.
 * User source is escaped before formatting; generated HTML is the only HTML emitted.
 */
final class WikiParser
{
    private array $references = [];
    private array $headings = [];
    private array $tokens = [];
    private array $usedIds = [];

    public function parse(string $source): array
    {
        $this->references = [];
        $this->headings = [];
        $this->tokens = [];
        $this->usedIds = [];
        $source = str_replace(["\r\n", "\r"], "\n", trim($source));
        $categories = extract_categories($source);
        $source = preg_replace('/\[\[(?:Category|বিষয়শ্রেণী):\s*[^]]+\]\]/iu', '', $source) ?? $source;

        if ($this->looksLikeLegacyHtml($source)) {
            return [
                'html' => $this->sanitizeLegacyHtml($source),
                'toc' => '',
                'categories' => $categories,
                'references' => [],
            ];
        }

        $source = $this->extractNowikiAndCode($source);
        $source = $this->extractReferences($source);
        $source = $this->extractTemplates($source);
        $source = preg_replace_callback('/\[\[(?:File|Image|চিত্র):\s*([^\]|]+)([^]]*)\]\]/iu', function (array $match): string {
            return $this->token($this->renderImage($match[1], $match[2] ?? ''));
        }, $source) ?? $source;

        $html = $this->parseBlocks($source);
        $referenceList = $this->renderReferences();
        if (str_contains($html, '%%REFERENCES%%')) {
            $html = str_replace('%%REFERENCES%%', $referenceList, $html);
        } elseif ($referenceList !== '') {
            $html .= $referenceList;
        }
        $html = $this->restoreTokens($html);

        return [
            'html' => $html,
            'toc' => $this->renderToc(),
            'categories' => $categories,
            'references' => $this->references,
        ];
    }

    private function looksLikeLegacyHtml(string $source): bool
    {
        return (bool) preg_match('/<(?:p|h[1-6]|ul|ol|table|figure|blockquote|div)\b/i', $source);
    }

    private function extractNowikiAndCode(string $source): string
    {
        $source = preg_replace_callback('/<nowiki>(.*?)<\/nowiki>/is', function (array $match): string {
            return $this->token('<span class="wiki-nowiki">' . e($match[1]) . '</span>');
        }, $source) ?? $source;
        return preg_replace_callback('/<code>(.*?)<\/code>/is', function (array $match): string {
            return $this->token('<code>' . e($match[1]) . '</code>');
        }, $source) ?? $source;
    }

    private function extractReferences(string $source): string
    {
        $source = preg_replace_callback('/<ref(?:\s+name=["\']?([^"\'>\s]+)["\']?)?>(.*?)<\/ref>/is', function (array $match): string {
            $name = $match[1] ?? '';
            $body = trim($match[2]);
            if ($name !== '') {
                foreach ($this->references as $index => $reference) {
                    if (($reference['name'] ?? '') === $name) {
                        $number = $index + 1;
                        return $this->token('<sup class="reference"><a href="#cite-' . $number . '" id="ref-' . $number . '-repeat">[' . $number . ']</a></sup>');
                    }
                }
            }
            $this->references[] = ['name' => $name, 'body' => $body];
            $number = count($this->references);
            return $this->token('<sup class="reference"><a href="#cite-' . $number . '" id="ref-' . $number . '">[' . $number . ']</a></sup>');
        }, $source) ?? $source;
        $source = preg_replace('/<references\s*\/?>/i', '%%REFERENCES%%', $source) ?? $source;
        return preg_replace('/\{\{\s*(?:reflist|references)\s*\}\}/i', '%%REFERENCES%%', $source) ?? $source;
    }

    private function extractTemplates(string $source): string
    {
        return preg_replace_callback('/\{\{\s*(Infobox|Note|Warning|Quote|Main)\s*(.*?)\}\}/isu', function (array $match): string {
            $type = mb_strtolower($match[1]);
            $parts = array_map('trim', explode('|', ltrim($match[2], '|')));
            if ($type === 'infobox') {
                $values = [];
                foreach ($parts as $part) {
                    if (str_contains($part, '=')) {
                        [$key, $value] = array_map('trim', explode('=', $part, 2));
                        $values[mb_strtolower($key)] = $value;
                    }
                }
                $title = $values['title'] ?? $values['name'] ?? 'Information';
                $image = $values['image'] ?? '';
                $caption = $values['caption'] ?? '';
                unset($values['title'], $values['name'], $values['image'], $values['caption']);
                $html = '<aside class="infobox"><div class="infobox-title">' . $this->parseInline($title) . '</div>';
                if ($image !== '' && $this->validImageUrl($image)) {
                    $html .= '<img src="' . e($image) . '" alt="' . e($caption ?: $title) . '" loading="lazy" decoding="async">';
                }
                if ($caption !== '') {
                    $html .= '<p class="infobox-caption">' . $this->parseInline($caption) . '</p>';
                }
                if ($values) {
                    $html .= '<dl>';
                    foreach ($values as $label => $value) {
                        $html .= '<dt>' . e(ucwords(str_replace('_', ' ', $label))) . '</dt><dd>' . $this->parseInline($value) . '</dd>';
                    }
                    $html .= '</dl>';
                }
                return $this->token($html . '</aside>');
            }
            $body = implode(' | ', $parts);
            if ($type === 'main') {
                $title = trim($parts[0] ?? '');
                return $this->token('<div class="wiki-hatnote">Main article: <a href="/wiki/' . e(slugify($title)) . '">' . e($title) . '</a></div>');
            }
            if ($type === 'quote') {
                $attribution = $parts[1] ?? '';
                return $this->token('<blockquote class="wiki-quote"><p>' . $this->parseInline($parts[0] ?? '') . '</p>' . ($attribution !== '' ? '<cite>— ' . e($attribution) . '</cite>' : '') . '</blockquote>');
            }
            $label = $type === 'warning' ? 'Warning' : 'Note';
            return $this->token('<aside class="wiki-notice ' . e($type) . '"><strong>' . $label . ':</strong> ' . $this->parseInline($body) . '</aside>');
        }, $source) ?? $source;
    }

    private function parseBlocks(string $source): string
    {
        $lines = explode("\n", $source);
        $html = '';
        $paragraph = [];
        $listType = null;
        $lineCount = count($lines);

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if (!$paragraph) {
                return;
            }
            $content = trim(implode(' ', $paragraph));
            if (preg_match('/^%%WIKITOKEN\d+%%$/', $content)) {
                $html .= $content;
            } elseif ($content !== '') {
                $html .= '<p>' . $this->parseInline($content) . '</p>';
            }
            $paragraph = [];
        };
        $closeList = function () use (&$listType, &$html): void {
            if ($listType !== null) {
                $html .= '</' . $listType . '>';
                $listType = null;
            }
        };

        for ($i = 0; $i < $lineCount; $i++) {
            $line = rtrim($lines[$i]);
            if (str_starts_with(ltrim($line), '{|')) {
                $flushParagraph();
                $closeList();
                $tableLines = [$line];
                while ($i + 1 < $lineCount) {
                    $tableLines[] = $lines[++$i];
                    if (trim($lines[$i]) === '|}') {
                        break;
                    }
                }
                $html .= $this->renderTable($tableLines);
                continue;
            }
            if (trim($line) === '') {
                $flushParagraph();
                $closeList();
                continue;
            }
            if (preg_match('/^(={2,6})\s*(.+?)\s*\1$/u', trim($line), $match)) {
                $flushParagraph();
                $closeList();
                $level = min(6, strlen($match[1]));
                $title = trim($match[2]);
                $id = $this->headingId($title);
                $this->headings[] = ['level' => $level, 'title' => strip_tags($title), 'id' => $id];
                $html .= '<h' . $level . ' id="' . e($id) . '">' . $this->parseInline($title) . '<a class="heading-anchor" href="#' . e($id) . '" aria-label="Link to this section">#</a></h' . $level . '>';
                continue;
            }
            if (preg_match('/^----+$/', trim($line))) {
                $flushParagraph();
                $closeList();
                $html .= '<hr>';
                continue;
            }
            if (preg_match('/^([*#]+)\s*(.+)$/u', $line, $match)) {
                $flushParagraph();
                $type = $match[1][0] === '#' ? 'ol' : 'ul';
                if ($listType !== $type) {
                    $closeList();
                    $listType = $type;
                    $html .= '<' . $type . '>';
                }
                $html .= '<li>' . $this->parseInline($match[2]) . '</li>';
                continue;
            }
            if (str_starts_with($line, ':')) {
                $flushParagraph();
                $closeList();
                $html .= '<blockquote>' . $this->parseInline(ltrim($line, ': ')) . '</blockquote>';
                continue;
            }
            if (str_starts_with($line, ' ')) {
                $flushParagraph();
                $closeList();
                $html .= '<pre>' . e(ltrim($line, ' ')) . '</pre>';
                continue;
            }
            $paragraph[] = $line;
        }
        $flushParagraph();
        $closeList();
        return $html;
    }

    private function parseInline(string $text): string
    {
        $text = preg_replace_callback('/\[\[([^\]|#]+)(?:#([^\]|]+))?(?:\|([^]]+))?\]\]/u', function (array $match): string {
            $title = trim($match[1]);
            $section = isset($match[2]) && $match[2] !== '' ? '#' . slugify($match[2]) : '';
            $label = trim($match[3] ?? '') ?: $title;
            return $this->token('<a href="/wiki/' . e(slugify($title)) . $section . '">' . e($label) . '</a>');
        }, $text) ?? $text;
        $text = preg_replace_callback('/\[(https?:\/\/[^\s\]]+)(?:\s+([^]]+))?\]/iu', function (array $match): string {
            $url = filter_var($match[1], FILTER_VALIDATE_URL) ? $match[1] : '#';
            $label = trim($match[2] ?? '') ?: $url;
            return $this->token('<a href="' . e($url) . '" rel="nofollow noopener noreferrer" target="_blank">' . e($label) . '<span class="external-mark" aria-hidden="true">↗</span></a>');
        }, $text) ?? $text;
        $escaped = e($text);
        $escaped = preg_replace("/'''''(.+?)'''''/u", '<strong><em>$1</em></strong>', $escaped) ?? $escaped;
        $escaped = preg_replace("/'''(.+?)'''/u", '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace("/''(.+?)''/u", '<em>$1</em>', $escaped) ?? $escaped;
        return $this->restoreTokens($escaped);
    }

    private function renderImage(string $url, string $options): string
    {
        $url = trim($url);
        if (!$this->validImageUrl($url)) {
            return '<span class="broken-media">Invalid image URL</span>';
        }
        $parts = array_values(array_filter(array_map('trim', explode('|', ltrim($options, '|')))));
        $align = 'right';
        $width = 420;
        $alt = '';
        $caption = '';
        foreach ($parts as $part) {
            if (in_array(mb_strtolower($part), ['left', 'right', 'center', 'none'], true)) {
                $align = mb_strtolower($part);
            } elseif (preg_match('/^(\d{2,4})px$/', $part, $size)) {
                $width = min(1200, max(120, (int) $size[1]));
            } elseif (str_starts_with(mb_strtolower($part), 'alt=')) {
                $alt = trim(substr($part, 4));
            } else {
                $caption = $part;
            }
        }
        $alt = $alt ?: $caption;
        return '<figure class="wiki-image align-' . e($align) . '" style="--image-width:' . $width . 'px"><a href="' . e($url) . '" target="_blank" rel="noopener"><img src="' . e($url) . '" alt="' . e($alt) . '" width="' . $width . '" loading="lazy" decoding="async"></a>' . ($caption !== '' ? '<figcaption>' . $this->parseInline($caption) . '</figcaption>' : '') . '</figure>';
    }

    private function validImageUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        return (bool) filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(mb_strtolower($url), 'https://');
    }

    private function renderTable(array $lines): string
    {
        $html = '<div class="table-scroll"><table class="wikitable">';
        $rowOpen = false;
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if ($line === '|}') {
                break;
            }
            if (str_starts_with($line, '|+')) {
                $html .= '<caption>' . $this->parseInline(trim(substr($line, 2))) . '</caption>';
                continue;
            }
            if (str_starts_with($line, '|-')) {
                if ($rowOpen) {
                    $html .= '</tr>';
                }
                $html .= '<tr>';
                $rowOpen = true;
                continue;
            }
            if (str_starts_with($line, '!')) {
                if (!$rowOpen) {
                    $html .= '<tr>';
                    $rowOpen = true;
                }
                foreach (preg_split('/!!/', substr($line, 1)) ?: [] as $cell) {
                    $html .= '<th>' . $this->parseInline(trim($cell)) . '</th>';
                }
                continue;
            }
            if (str_starts_with($line, '|')) {
                if (!$rowOpen) {
                    $html .= '<tr>';
                    $rowOpen = true;
                }
                foreach (preg_split('/\|\|/', substr($line, 1)) ?: [] as $cell) {
                    $html .= '<td>' . $this->parseInline(trim($cell)) . '</td>';
                }
            }
        }
        if ($rowOpen) {
            $html .= '</tr>';
        }
        return $html . '</table></div>';
    }

    private function renderReferences(): string
    {
        if (!$this->references) {
            return '';
        }
        $html = '<section class="references" aria-labelledby="references-title"><h2 id="references-title">References</h2><ol>';
        foreach ($this->references as $index => $reference) {
            $number = $index + 1;
            $html .= '<li id="cite-' . $number . '"><a href="#ref-' . $number . '" class="reference-back">↑</a> ' . $this->parseInline($reference['body']) . '</li>';
        }
        return $html . '</ol></section>';
    }

    private function renderToc(): string
    {
        if (count($this->headings) < 3) {
            return '';
        }
        $html = '<nav class="table-of-contents" aria-labelledby="toc-title"><div class="toc-heading"><strong id="toc-title">Contents</strong><button type="button" data-toc-toggle aria-expanded="true">hide</button></div><ol>';
        foreach ($this->headings as $index => $heading) {
            $html .= '<li class="toc-level-' . (int) $heading['level'] . '"><a href="#' . e($heading['id']) . '"><span>' . ($index + 1) . '</span> ' . e($heading['title']) . '</a></li>';
        }
        return $html . '</ol></nav>';
    }

    private function headingId(string $title): string
    {
        $base = slugify(strip_tags($title));
        $id = $base;
        $suffix = 2;
        while (isset($this->usedIds[$id])) {
            $id = $base . '-' . $suffix++;
        }
        $this->usedIds[$id] = true;
        return $id;
    }

    private function token(string $html): string
    {
        $token = '%%WIKITOKEN' . count($this->tokens) . '%%';
        $this->tokens[$token] = $html;
        return $token;
    }

    private function restoreTokens(string $value): string
    {
        // Tokens can contain other tokens (for example links inside an infobox).
        for ($i = 0; $i < 3; $i++) {
            $value = strtr($value, $this->tokens);
        }
        return $value;
    }

    private function sanitizeLegacyHtml(string $html): string
    {
        $allowed = ['p', 'br', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'caption', 'a', 'img', 'figure', 'figcaption', 'hr', 'sup', 'sub'];
        if (!class_exists('DOMDocument')) {
            // Prefer plain, safe text over incomplete attribute filtering when
            // the optional DOM extension is unavailable.
            return '<p>' . nl2br(e(trim(strip_tags($html)))) . '</p>';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div id="wiki-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $node) {
            if (!$node instanceof DOMElement || $node->getAttribute('id') === 'wiki-root') {
                continue;
            }
            if (!in_array(strtolower($node->tagName), $allowed, true)) {
                while ($node->firstChild) {
                    $node->parentNode?->insertBefore($node->firstChild, $node);
                }
                $node->parentNode?->removeChild($node);
                continue;
            }
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (!in_array(strtolower($attribute->name), ['href', 'src', 'alt', 'title', 'width', 'height', 'colspan', 'rowspan'], true)) {
                    $node->removeAttribute($attribute->name);
                }
            }
            if ($node->hasAttribute('href')) {
                $href = trim($node->getAttribute('href'));
                if (!preg_match('~^(https?://|/|#)~i', $href)) {
                    $node->removeAttribute('href');
                }
            }
            if ($node->tagName === 'a' && preg_match('~^https?://~i', $node->getAttribute('href'))) {
                $node->setAttribute('rel', 'nofollow noopener noreferrer');
            }
            if ($node->tagName === 'img') {
                $src = trim($node->getAttribute('src'));
                if (!$this->validImageUrl($src)) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }
                $node->setAttribute('loading', 'lazy');
                $node->setAttribute('decoding', 'async');
            }
        }
        $root = $document->getElementById('wiki-root');
        $safe = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $safe .= $document->saveHTML($child);
            }
        }
        return $safe;
    }
}
