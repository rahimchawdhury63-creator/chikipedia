<?php
declare(strict_types=1);
require_once __DIR__ . '/ImageService.php';

final class EncyclopediaImportException extends RuntimeException {}

final class ImportService
{
    private const LICENSES = ['CC BY-SA 4.0', 'CC BY-SA 3.0', 'CC0 / Public domain', 'Permission obtained'];

    public function __construct(private PDO $pdo) {}

    public function importArticle(string $sourceUrl, string $license, int $userId): array
    {
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '' || strlen($sourceUrl) > 1000 || preg_match('/[\x00-\x20\\\\]/', $sourceUrl)) {
            throw new EncyclopediaImportException('Enter a valid encyclopedia URL no longer than 1,000 characters.');
        }
        if (!in_array($license, self::LICENSES, true)) {
            throw new EncyclopediaImportException('Choose a compatible license or confirm that permission was obtained.');
        }
        $parts = parse_url($sourceUrl);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new EncyclopediaImportException('Enter a complete public HTTPS encyclopedia article URL without credentials or custom ports.');
        }
        $insert = $this->pdo->prepare('INSERT INTO remote_imports (user_id, source_url, source_host, source_license, status) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$userId, mb_substr($sourceUrl, 0, 1000), mb_substr($host, 0, 190), $license, 'fetching']);
        $importId = (int) $this->pdo->lastInsertId();
        try {
            $article = $this->fetchMediaWikiArticle($sourceUrl);
            if ($article === null) {
                $article = $this->fetchGenericArticle($sourceUrl);
            }
            $imageWarnings = [];
            $importedImages = [];
            $imageService = new ImageService($this->pdo);
            foreach (array_slice($article['images'] ?? [], 0, 3) as $candidate) {
                if (empty($candidate['url'])) {
                    continue;
                }
                if (($candidate['reusable'] ?? true) === false && $license !== 'Permission obtained') {
                    $imageWarnings[] = 'An image marked “' . ($candidate['license'] ?: 'restricted') . '” was skipped because reusable rights were not verified.';
                    continue;
                }
                try {
                    $imageResult = $imageService->importRemote((string) $candidate['url'], (string) ($candidate['title'] ?: $article['title']), [
                        'source_page_url' => $sourceUrl,
                        'attribution' => $candidate['attribution'] ?? ($article['title'] . ' — ' . $host),
                        'license_name' => $candidate['license'] ?? $license,
                    ]);
                    $imageResult['caption'] = (string) ($candidate['title'] ?: $article['title']);
                    $imageResult['license'] = (string) ($candidate['license'] ?? $license);
                    $imageResult['source_url'] = (string) $candidate['url'];
                    $importedImages[] = $imageResult;
                } catch (Throwable $exception) {
                    $imageWarnings[] = 'One detected image could not be transferred: ' . $exception->getMessage();
                }
            }
            $content = $this->buildWikiSource($article, $sourceUrl, $license, $importedImages);
            $leadImage = $importedImages[0] ?? null;
            $update = $this->pdo->prepare("UPDATE remote_imports SET source_title = ?, source_image_url = ?, imported_image_url = ?, error_message = ?, status = 'ready', completed_at = UTC_TIMESTAMP() WHERE id = ?");
            $update->execute([
                mb_substr((string) $article['title'], 0, 255),
                mb_substr((string) ($leadImage['source_url'] ?? ($article['image'] ?? '')), 0, 1000) ?: null,
                mb_substr((string) ($leadImage['url'] ?? ''), 0, 1000) ?: null,
                mb_substr(implode(' ', $imageWarnings), 0, 500) ?: null,
                $importId,
            ]);
            log_activity($this->pdo, 'encyclopedia.imported', 'remote_import', $importId, ['host' => $host, 'images_imported' => count($importedImages)]);
            return [
                'import_id' => $importId, 'title' => $article['title'], 'content' => $content,
                'image' => $leadImage, 'images' => $importedImages, 'warning' => implode(' ', $imageWarnings), 'source_url' => $sourceUrl,
                'source_type' => $article['source_type'],
            ];
        } catch (Throwable $exception) {
            $this->pdo->prepare("UPDATE remote_imports SET status = 'failed', error_message = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([mb_substr($exception->getMessage(), 0, 500), $importId]);
            if ($exception instanceof EncyclopediaImportException || $exception instanceof RemoteFetchException || $exception instanceof ImageUploadException) {
                throw new EncyclopediaImportException($exception->getMessage(), 0, $exception);
            }
            error_log('Encyclopedia import failed: ' . $exception->getMessage());
            throw new EncyclopediaImportException('The encyclopedia article could not be imported.', 0, $exception);
        }
    }

    private function fetchMediaWikiArticle(string $sourceUrl): ?array
    {
        $parts = parse_url($sourceUrl);
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (!preg_match('~/(?:wiki|view)/(.+)$~u', $path, $match)) {
            return null;
        }
        $pageTitle = str_replace('_', ' ', trim($match[1], '/'));
        if ($pageTitle === '' || preg_match('/^(?:Special|File|Category|User|Talk):/i', $pageTitle)) {
            throw new EncyclopediaImportException('Choose a standard encyclopedia article page.');
        }
        $query = http_build_query([
            'action' => 'query', 'format' => 'json', 'formatversion' => '2', 'redirects' => '1',
            'prop' => 'extracts|pageimages|info|images', 'explaintext' => '1', 'exsectionformat' => 'wiki',
            'piprop' => 'name|original|thumbnail', 'pithumbsize' => '1400', 'imlimit' => '8', 'inprop' => 'url', 'titles' => $pageTitle,
        ], '', '&', PHP_QUERY_RFC3986);
        $apiUrl = 'https://' . mb_strtolower((string) $parts['host']) . '/w/api.php?' . $query;
        try {
            $json = (new RemoteFetcher())->fetchJson($apiUrl, 4 * 1024 * 1024);
        } catch (Throwable) {
            return null;
        }
        $page = $json['query']['pages'][0] ?? null;
        if (!is_array($page) || isset($page['missing']) || empty($page['extract'])) {
            return null;
        }
        $originalImage = $page['original']['source'] ?? null;
        $fallbackImage = ($originalImage && !preg_match('/\.svg(?:\?|$)/i', $originalImage)) ? $originalImage : ($page['thumbnail']['source'] ?? $originalImage);
        $fileTitles = [];
        if (!empty($page['pageimage'])) {
            $fileTitles[] = 'File:' . $page['pageimage'];
        }
        foreach ($page['images'] ?? [] as $file) {
            $fileTitle = (string) ($file['title'] ?? '');
            if ($fileTitle !== '' && preg_match('/\.(?:jpe?g|png|gif|webp|svg|tiff?|bmp)$/i', $fileTitle)) {
                $fileTitles[] = $fileTitle;
            }
        }
        $fileTitles = array_slice(array_values(array_unique($fileTitles)), 0, 8);
        $images = [];
        if ($fileTitles) {
            try {
                $metadataQuery = http_build_query([
                    'action' => 'query', 'format' => 'json', 'formatversion' => '2',
                    'prop' => 'imageinfo', 'iiprop' => 'url|extmetadata', 'iiurlwidth' => '1400',
                    'titles' => implode('|', $fileTitles),
                ], '', '&', PHP_QUERY_RFC3986);
                $metadata = (new RemoteFetcher())->fetchJson('https://' . mb_strtolower((string) $parts['host']) . '/w/api.php?' . $metadataQuery, 2 * 1024 * 1024);
                $metadataPages = $metadata['query']['pages'] ?? [];
                $fileOrder = array_flip(array_map('mb_strtolower', $fileTitles));
                usort($metadataPages, static fn(array $a, array $b): int => ($fileOrder[mb_strtolower((string) ($a['title'] ?? ''))] ?? 999) <=> ($fileOrder[mb_strtolower((string) ($b['title'] ?? ''))] ?? 999));
                foreach ($metadataPages as $filePage) {
                    $info = $filePage['imageinfo'][0] ?? [];
                    $metadataImage = $info['url'] ?? null;
                    $fileImage = ($metadataImage && preg_match('/\.(?:jpe?g|png|gif|webp)(?:\?|$)/i', $metadataImage)) ? $metadataImage : ($info['thumburl'] ?? null);
                    if (!$fileImage || preg_match('/\.svg(?:\?|$)/i', $fileImage)) {
                        continue;
                    }
                    $imageLicense = trim(html_entity_decode(strip_tags((string) ($info['extmetadata']['LicenseShortName']['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null;
                    $artist = trim(html_entity_decode(strip_tags((string) ($info['extmetadata']['Artist']['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $credit = trim(html_entity_decode(strip_tags((string) ($info['extmetadata']['Credit']['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    $images[] = [
                        'url' => $fileImage,
                        'title' => mb_substr((string) ($filePage['title'] ?? $page['title']), 0, 255),
                        'license' => $imageLicense,
                        'attribution' => mb_substr(trim($artist . ($credit && $credit !== $artist ? ' · ' . $credit : '')), 0, 500) ?: null,
                        'reusable' => $imageLicense === null || $this->imageLicenseAllowsReuse($imageLicense),
                    ];
                    if (count($images) >= 3) {
                        break;
                    }
                }
            } catch (Throwable) {
                // The page import remains useful when optional file metadata is unavailable.
            }
        }
        if (!$images && $fallbackImage) {
            $images[] = ['url' => $fallbackImage, 'title' => (string) ($page['title'] ?? $pageTitle), 'license' => null, 'attribution' => null, 'reusable' => true];
        }
        return [
            'title' => mb_substr(trim((string) ($page['title'] ?? $pageTitle)), 0, 255),
            'text' => mb_substr(trim((string) $page['extract']), 0, 300000),
            'description' => '', 'image' => $images[0]['url'] ?? null, 'images' => $images,
            'image_license' => $images[0]['license'] ?? null, 'image_attribution' => $images[0]['attribution'] ?? null,
            'source_type' => 'mediawiki',
        ];
    }

    private function imageLicenseAllowsReuse(string $license): bool
    {
        $license = mb_strtolower($license);
        foreach (['cc by', 'creative commons', 'cc0', 'public domain', 'gfdl', 'gnu free documentation', 'free art'] as $allowed) {
            if (str_contains($license, $allowed)) {
                return true;
            }
        }
        return false;
    }

    private function fetchGenericArticle(string $sourceUrl): array
    {
        $response = (new RemoteFetcher())->fetch($sourceUrl, ['text/html', 'application/xhtml+xml'], 3 * 1024 * 1024);
        $html = $response['body'];
        $title = '';
        $description = '';
        $image = null;
        $text = '';
        if (class_exists('DOMDocument')) {
            $document = new DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            libxml_clear_errors();
            $xpath = new DOMXPath($document);
            $meta = static function (DOMXPath $xpath, string $property): string {
                $node = $xpath->query("//meta[@property='{$property}' or @name='{$property}']/@content")->item(0);
                return $node ? trim($node->nodeValue) : '';
            };
            $title = $meta($xpath, 'og:title') ?: trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
            $description = $meta($xpath, 'description') ?: $meta($xpath, 'og:description');
            $image = $meta($xpath, 'og:image') ?: null;
            foreach (iterator_to_array($xpath->query('//script|//style|//nav|//footer|//header|//form|//aside') ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
            $root = $xpath->query('//article')->item(0) ?: $xpath->query('//main')->item(0) ?: $xpath->query('//body')->item(0);
            if ($root) {
                $segments = [];
                foreach (iterator_to_array($xpath->query('.//h2|.//h3|.//h4|.//p|.//li|.//blockquote', $root) ?: []) as $node) {
                    $segment = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
                    if ($segment === '') {
                        continue;
                    }
                    $tag = mb_strtolower($node->nodeName);
                    $segments[] = match ($tag) {
                        'h2' => '== ' . $segment . ' ==',
                        'h3' => '=== ' . $segment . ' ===',
                        'h4' => '==== ' . $segment . ' ====',
                        'li' => '* ' . $segment,
                        'blockquote' => '{{Quote|' . str_replace(['|', '}}'], ['—', ''], $segment) . '}}',
                        default => $segment,
                    };
                }
                $text = $segments ? implode("\n\n", $segments) : trim((string) $root->textContent);
            }
        } else {
            preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $titleMatch);
            preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i', $html, $imageMatch);
            $title = trim(strip_tags($titleMatch[1] ?? 'Imported encyclopedia article'));
            $image = $imageMatch[1] ?? null;
            $text = trim(strip_tags($html));
        }
        $text = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $text) ?? $text) ?? $text);
        if ($image && !preg_match('~^https://~i', $image)) {
            $base = parse_url($sourceUrl);
            if (str_starts_with($image, '//')) {
                $image = 'https:' . $image;
            } elseif (str_starts_with($image, '/')) {
                $image = 'https://' . ($base['host'] ?? '') . $image;
            } else {
                $image = 'https://' . ($base['host'] ?? '') . rtrim(str_replace('\\', '/', dirname($base['path'] ?? '/')), '/') . '/' . $image;
            }
        }
        if ($title === '' || mb_strlen($text) < 80) {
            throw new EncyclopediaImportException('This page does not expose enough article content to import.');
        }
        return [
            'title' => mb_substr($title, 0, 255), 'text' => mb_substr($text, 0, 300000),
            'description' => mb_substr($description, 0, 320), 'image' => $image,
            'images' => $image ? [['url' => $image, 'title' => $title, 'license' => null, 'attribution' => null, 'reusable' => true]] : [],
            'source_type' => 'open-graph',
        ];
    }

    private function buildWikiSource(array $article, string $sourceUrl, string $license, array $importedImages): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', str_replace(['|', '}}', ']]', '[['], ['—', '', '', ''], (string) $article['title'])) ?? 'Imported article');
        $safeSourceUrl = str_replace([']', '[', ' '], ['%5D', '%5B', '%20'], $sourceUrl);
        $sourceLabel = parse_url($sourceUrl, PHP_URL_HOST) ?: 'source encyclopedia';
        $blocks = [
            "{{Note|Imported as a draft from [{$safeSourceUrl} {$sourceLabel}]. License: {$license}. Review every claim, preserve attribution, and add independent reliable sources before publishing.}}",
        ];
        foreach ($importedImages as $index => $importedImage) {
            $caption = trim(preg_replace('/\s+/u', ' ', str_replace(['|', ']]', '[['], ['—', '', ''], (string) ($importedImage['caption'] ?? $title))) ?? $title);
            $licenseLabel = trim(str_replace(['|', ']]', '[['], ['—', '', ''], (string) ($importedImage['license'] ?? $license)));
            $position = $index === 0 ? 'right' : 'left';
            $blocks[] = '[[File:' . $importedImage['url'] . '|alt=' . $caption . '|' . $caption . ' (' . $licenseLabel . ')|' . $position . '|420px]]';
        }
        if (!empty($article['description'])) {
            $blocks[] = "'''{$title}''' — " . trim((string) $article['description']);
        }
        $blocks[] = trim((string) $article['text']);
        $blocks[] = "== Sources ==\n<ref>[{$safeSourceUrl} {$title}], {$sourceLabel}. Imported under {$license}; accessed " . gmdate('j F Y') . ".</ref>\n{{reflist}}";
        $blocks[] = '[[Category:Imported drafts]]';
        return implode("\n\n", array_filter($blocks));
    }
}
