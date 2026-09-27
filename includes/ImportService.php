<?php
declare(strict_types=1);
require_once __DIR__ . '/ImageService.php';

final class EncyclopediaImportException extends RuntimeException {}

final class ImportService
{
    private const LICENSES = ['CC BY-SA 4.0', 'CC BY-SA 3.0', 'CC0 / Public domain', 'Permission obtained'];
    // Keep the synchronous request below shared-host execution limits. The
    // complete detected count and skip reason remain visible for manual review.
    private const SYNCHRONOUS_IMAGE_LIMIT = 3;
    private ?string $mediaWikiFailure = null;

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
            $article=$this->fetchMediaWikiArticle($sourceUrl);$fallbackWarning='';
            if($article===null){try{$article=$this->fetchGenericArticle($sourceUrl);if($this->mediaWikiFailure)$fallbackWarning='The MediaWiki API was unavailable, so rendered page text was used: '.$this->mediaWikiFailure;}catch(Throwable $fallbackException){if($this->mediaWikiFailure)throw new EncyclopediaImportException('The source API failed ('.$this->mediaWikiFailure.') and the page fallback also failed ('.$fallbackException->getMessage().').',0,$fallbackException);throw $fallbackException;}}
            $imageWarnings=[];if($fallbackWarning!=='')$imageWarnings[]=$fallbackWarning;if(!empty($article['continuation_truncated']))$imageWarnings[]='The source API continuation safety bound was reached; compare the draft with the source for additional categories or files.';if(!empty($article['content_truncated']))$imageWarnings[]='The source article exceeded the safe import-size bound and was truncated; merge the remaining source manually before review.';
            $importedImages = [];
            $imageService = new ImageService($this->pdo);
            $candidateLimit=max(1,min(40,(int)setting($this->pdo,'import_max_reusable_images','40')));
            $detectedBeforeTransfer=(int)($article['detected_image_count']??count($article['images']??[]));$missingMetadata=max(0,min($detectedBeforeTransfer,$candidateLimit)-count($article['images']??[]));if($missingMetadata>0)$imageWarnings[]=$missingMetadata.' detected file(s) were skipped because complete reusable-license metadata or a supported rendition was unavailable.';
            $deferredReusableImages=0;$imageTransferDeadline=microtime(true)+20.0;
            foreach(array_slice($article['images']??[],0,$candidateLimit) as $candidate){
                if(empty($candidate['url']))continue;
                if(($candidate['reusable']??false)!==true){$imageWarnings[]='An image marked “'.($candidate['license']?:'restricted').'” was skipped because reusable rights were not verified.';continue;}
                if(count($importedImages)>=self::SYNCHRONOUS_IMAGE_LIMIT||microtime(true)>=$imageTransferDeadline){$deferredReusableImages++;continue;}
                try {
                    $imageResult = $imageService->importRemote((string) $candidate['url'], (string) ($candidate['title'] ?: $article['title']), [
                        'source_page_url' => (string) ($candidate['source_page_url'] ?? $sourceUrl),
                        'attribution' => $candidate['attribution'] ?? ($article['title'] . ' — ' . $host),
                        'license_name' => $candidate['license'] ?? $license,
                    ]);
                    $imageResult['caption'] = (string) ($candidate['title'] ?: $article['title']);
                    $imageResult['license'] = (string) ($candidate['license'] ?? $license);
                    $imageResult['source_url'] = (string) $candidate['url'];
                    $imageResult['source_title'] = (string) ($candidate['source_title'] ?? $candidate['title'] ?? '');
                    $importedImages[] = $imageResult;
                } catch (Throwable $exception) {
                    $imageWarnings[] = 'One detected image could not be transferred: ' . $exception->getMessage();
                }
            }
            if($deferredReusableImages>0)$imageWarnings[]=$deferredReusableImages.' additional reusable image(s) were left for manual transfer so the shared-host import request would not time out.';
            $content = $this->buildWikiSource($article, $sourceUrl, $license, $importedImages);
            $leadImage = $importedImages[0] ?? null;
            preg_match_all('/<ref\b/iu', $content, $referenceMatches);
            $categoryCount = count(extract_categories($content));
            $detectedImageCount = (int)($article['detected_image_count']??count($article['images']??[]));
            if($detectedImageCount>$candidateLimit)$imageWarnings[]=($detectedImageCount-$candidateLimit).' additional source image(s) exceeded the metadata inspection safety bound.';
            $update = $this->pdo->prepare("UPDATE remote_imports SET source_title=?,source_type=?,source_revision=?,source_revision_timestamp=?,source_api_url=?,source_content_hash=?,source_image_url=?,imported_image_url=?,imported_references=?,imported_categories=?,detected_images=?,imported_images=?,skipped_images=?,error_message=?,status='ready',completed_at=UTC_TIMESTAMP() WHERE id=?");
            $update->execute([
                mb_substr((string) $article['title'], 0, 255), mb_substr((string) ($article['source_type'] ?? ''), 0, 50) ?: null,
                mb_substr((string) ($article['revision'] ?? ''), 0, 100) ?: null, $article['revision_timestamp'] ?? null,
                mb_substr((string)($article['api_url']??''),0,1000)?:null,(string)($article['source_content_hash']??hash('sha256',(string)($article['text']??''))),
                mb_substr((string) ($leadImage['source_url'] ?? ($article['image'] ?? '')), 0, 1000) ?: null,
                mb_substr((string) ($leadImage['url'] ?? ''), 0, 1000) ?: null,
                count($referenceMatches[0] ?? []), $categoryCount, $detectedImageCount, count($importedImages), max(0, $detectedImageCount-count($importedImages)),
                mb_substr(implode(' ', $imageWarnings), 0, 500) ?: null, $importId,
            ]);
            log_activity($this->pdo, 'encyclopedia.imported', 'remote_import', $importId, ['host' => $host, 'images_imported' => count($importedImages)]);
            return [
                'import_id' => $importId, 'title' => $article['title'], 'content' => $content,
                'image'=>$leadImage,'images'=>$importedImages,'warning'=>mb_substr(implode(' ',$imageWarnings),0,2000),'source_url' => $sourceUrl,
                'source_type' => $article['source_type'], 'revision' => $article['revision'] ?? null,
                'reference_count' => count($referenceMatches[0] ?? []), 'category_count' => $categoryCount,
                'detected_image_count' => $detectedImageCount, 'imported_image_count' => count($importedImages),
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
        if (preg_match('~/(?:wiki|view)/(.+)$~u',$path,$match)) $pageTitle=str_replace('_',' ',trim($match[1],'/'));
        elseif(preg_match('~/(?:w/)?index\.php$~i',$path)){parse_str((string)($parts['query']??''),$sourceQuery);$pageTitle=str_replace('_',' ',trim((string)($sourceQuery['title']??'')));}
        else return null;
        if ($pageTitle === '' || preg_match('/^(?:Special|File|Category|User|Talk):/i', $pageTitle)) {
            throw new EncyclopediaImportException('Choose a standard encyclopedia article page.');
        }
        $query = http_build_query([
            'action' => 'query', 'format' => 'json', 'formatversion' => '2', 'redirects' => '1',
            'prop' => 'revisions|pageimages|info|images|categories',
            'rvprop' => 'ids|timestamp|content', 'rvslots' => 'main', 'rvlimit' => '1',
            'piprop' => 'name|original|thumbnail', 'pithumbsize' => '1400', 'imlimit' => 'max', 'cllimit' => 'max', 'clshow' => '!hidden', 'inprop' => 'url', 'titles' => $pageTitle,
        ], '', '&', PHP_QUERY_RFC3986);
        $apiHost='https://'.mb_strtolower((string)$parts['host']);$apiBases=[$apiHost.'/w/api.php',$apiHost.'/api.php'];
        $json=null;$apiUrl='';$apiBase='';
        $apiFailures=[];foreach($apiBases as $candidateBase){try{$candidateUrl=$candidateBase.'?'.$query;$candidateJson=(new RemoteFetcher())->fetchJson($candidateUrl,8*1024*1024,mb_strtolower((string)$parts['host']),8);if(isset($candidateJson['query']['pages'])){$json=$candidateJson;$apiUrl=$candidateUrl;$apiBase=$candidateBase;break;}}catch(Throwable $apiException){$apiFailures[]=$apiException->getMessage();}}
        if(!is_array($json)&&$apiFailures)$this->mediaWikiFailure=mb_substr(implode(' / ',array_unique($apiFailures)),0,300);
        if(!is_array($json))return null;
        $page = $json['query']['pages'][0] ?? null;
        $initialRevision=is_array($page)?($page['revisions'][0]??[]):[];
        $initialSource=(string)($initialRevision['slots']['main']['content']??$initialRevision['content']??'');
        if (!is_array($page) || isset($page['missing']) || (trim($initialSource)===''&&empty($page['extract']))) {
            return null;
        }
        // MediaWiki returns long image/category lists in continuation pages. Follow
        // every bounded continuation token so source metadata is not silently lost.
        $continuation = is_array($json['continue'] ?? null) ? $json['continue'] : [];
        $continuationRequests = 0;
        while ($continuation && $continuationRequests < 50 && (count($page['images'] ?? []) < 5000 || count($page['categories'] ?? []) < 5000)) {
            $continuedParams = [
                'action' => 'query', 'format' => 'json', 'formatversion' => '2', 'redirects' => '1',
                'prop'=>'images|categories','imlimit'=>'max','cllimit'=>'max','clshow'=>'!hidden','titles'=>$pageTitle,
            ];
            foreach ($continuation as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) $continuedParams[$key] = (string) $value;
            }
            $continuedUrl = $apiBase . '?' . http_build_query($continuedParams, '', '&', PHP_QUERY_RFC3986);
            $continued = (new RemoteFetcher())->fetchJson($continuedUrl,4*1024*1024,mb_strtolower((string)$parts['host']),8);
            $continuedPage = $continued['query']['pages'][0] ?? null;
            if (!is_array($continuedPage)) break;
            foreach (['images', 'categories'] as $collection) {
                if (!empty($continuedPage[$collection]) && is_array($continuedPage[$collection])) {
                    $page[$collection] = array_merge($page[$collection] ?? [], $continuedPage[$collection]);
                }
            }
            $continuation = is_array($continued['continue'] ?? null) ? $continued['continue'] : [];
            $continuationRequests++;
        }
        $continuationTruncated=(bool)$continuation;
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
        $fileTitleLimit = max(1, min(40, (int) setting($this->pdo, 'import_max_reusable_images', '40')));
        $fileTitles=array_values(array_unique($fileTitles));$detectedFileCount=count($fileTitles);
        $fileTitles = array_slice($fileTitles, 0, $fileTitleLimit);
        $images = [];
        if ($fileTitles) {
            try {
                $metadataPages=[];$fileChunks=[];$fileChunk=[];
                foreach($fileTitles as $fileTitle){$candidate=array_merge($fileChunk,[$fileTitle]);$candidateQuery=http_build_query(['action'=>'query','format'=>'json','formatversion'=>'2','prop'=>'imageinfo|info','inprop'=>'url','iiprop'=>'url|extmetadata','iiurlwidth'=>'1400','titles'=>implode('|',$candidate)],'','&',PHP_QUERY_RFC3986);if($fileChunk&&strlen($apiBase.'?'.$candidateQuery)>1900){$fileChunks[]=$fileChunk;$fileChunk=[$fileTitle];}else{$fileChunk=$candidate;}}if($fileChunk)$fileChunks[]=$fileChunk;
                foreach($fileChunks as $fileChunk){$metadataQuery=http_build_query(['action'=>'query','format'=>'json','formatversion'=>'2','prop'=>'imageinfo|info','inprop'=>'url','iiprop'=>'url|extmetadata','iiurlwidth'=>'1400','titles'=>implode('|',$fileChunk)],'','&',PHP_QUERY_RFC3986);try{$metadata=(new RemoteFetcher())->fetchJson($apiBase.'?'.$metadataQuery,2*1024*1024,mb_strtolower((string)$parts['host']),8);$metadataPages=array_merge($metadataPages,$metadata['query']['pages']??[]);}catch(Throwable){continue;}}

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
                        'source_title' => (string) ($filePage['title'] ?? ''),
                        'source_page_url' => (string) ($filePage['canonicalurl'] ?? $filePage['fullurl'] ?? ''),
                        'license' => $imageLicense,
                        'attribution' => mb_substr(trim($artist . ($credit && $credit !== $artist ? ' · ' . $credit : '')), 0, 500) ?: null,
                        'reusable'=>$imageLicense!==null&&$this->imageLicenseAllowsReuse($imageLicense),
                    ];
                    if (count($images) >= 40) {
                        break;
                    }
                }
            } catch (Throwable) {
                // The page import remains useful when optional file metadata is unavailable.
            }
        }
        if (!$images && $fallbackImage) {
            $images[] = ['url' => $fallbackImage, 'title' => (string) ($page['title'] ?? $pageTitle), 'license' => null, 'attribution' => null, 'reusable' => false];
        }
        $revision = $page['revisions'][0] ?? [];
        $rawSource = (string) ($revision['slots']['main']['content'] ?? $revision['content'] ?? '');
        $text = trim($rawSource !== '' ? $rawSource : (string) $page['extract']);
        $categoryNames = [];
        foreach ($page['categories'] ?? [] as $category) {
            $name=preg_replace('/^[^:]+:/u','',(string)($category['title']??''));
            if ($name !== '') $categoryNames[] = $name;
        }
        foreach ($categoryNames as $categoryName) {
            if (!preg_match('/\[\[Category:\s*' . preg_quote($categoryName, '/') . '/iu', $text)) $text .= "\n[[Category:" . $categoryName . ']]';
        }
        $revisionUnix=!empty($revision['timestamp'])?strtotime((string)$revision['timestamp']):false;$contentTruncated=mb_strlen($text)>1000000;
        return [
            'title' => mb_substr(trim((string) ($page['title'] ?? $pageTitle)), 0, 255),
            'text' => mb_substr($text, 0, 1000000), 'revision' => (string) ($revision['revid'] ?? $revision['timestamp'] ?? ''),
            'description' => '', 'image' => $images[0]['url'] ?? null, 'images' => $images, 'detected_image_count'=>max($detectedFileCount,count($images)),
            'image_license' => $images[0]['license'] ?? null, 'image_attribution' => $images[0]['attribution'] ?? null,
            'source_type'=>'mediawiki','api_url'=>$apiUrl,'continuation_truncated'=>$continuationTruncated,'content_truncated'=>$contentTruncated,'source_content_hash'=>hash('sha256',$rawSource!==''?$rawSource:$text),
            'revision_timestamp'=>$revisionUnix!==false?gmdate('Y-m-d H:i:s',$revisionUnix):null,
        ];
    }

    private function imageLicenseAllowsReuse(string $license): bool
    {
        $license=mb_strtolower(trim($license));
        foreach(['noncommercial','non-commercial','no derivatives','no-derivatives','cc by-nc','cc-by-nc','cc by-nd','cc-by-nd','fair use','all rights reserved'] as $blocked){if(str_contains($license,$blocked))return false;}
        if(str_contains($license,'cc0')||str_contains($license,'public domain')||str_contains($license,'gfdl')||str_contains($license,'gnu free documentation')||str_contains($license,'free art'))return true;
        return (bool)preg_match('/(?:cc|creative commons)\s*-?\s*(?:attribution\s*)?(?:by)(?:\s*-?\s*sa)?(?:\s|$|\d)/u',$license);
    }

    private function fetchGenericArticle(string $sourceUrl): array
    {
        $response=(new RemoteFetcher())->fetch($sourceUrl,['text/html','application/xhtml+xml'],3*1024*1024,null,10);
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
        $contentTruncated=mb_strlen($text)>300000;
        return [
            'title'=>mb_substr($title,0,255),'text'=>mb_substr($text,0,300000),
            'description' => mb_substr($description, 0, 320), 'image' => $image,
            'images'=>$image?[['url'=>$image,'title'=>$title,'license'=>null,'attribution'=>null,'reusable'=>false]]:[],
            'source_type'=>'open-graph','content_truncated'=>$contentTruncated,'source_content_hash'=>hash('sha256',$text),
        ];
    }

    private function buildWikiSource(array $article, string $sourceUrl, string $license, array $importedImages): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', str_replace(['|', '}}', ']]', '[['], ['—', '', '', ''], (string) $article['title'])) ?? 'Imported article');
        $safeSourceUrl = str_replace([']', '[', ' '], ['%5D', '%5B', '%20'], $sourceUrl);
        $sourceLabel = parse_url($sourceUrl, PHP_URL_HOST) ?: 'source encyclopedia';
        $revision = trim((string) ($article['revision'] ?? ''));
        $revisionTimestamp = trim((string) ($article['revision_timestamp'] ?? ''));
        $revisionLabel = $revision . ($revisionTimestamp !== '' ? ' @ ' . $revisionTimestamp . ' UTC' : '');
        $blocks = [
            '{{Imported|' . $safeSourceUrl . '|' . $sourceLabel . '|' . str_replace('|', '—', $license) . '|' . str_replace('|', '—', $revisionLabel) . '}}',
            '{{Warning|Imported content must remain reviewable. Verify references, attribution, neutrality, and media licenses before publication.}}',
        ];
        $articleText = trim((string) $article['text']);
        foreach ($importedImages as $index => $importedImage) {
            $caption = trim(preg_replace('/\s+/u', ' ', str_replace(['|', ']]', '[['], ['—', '', ''], (string) ($importedImage['caption'] ?? $title))) ?? $title);
            $licenseLabel = trim(str_replace(['|', ']]', '[['], ['—', '', ''], (string) ($importedImage['license'] ?? $license)));
            $position = $index === 0 ? 'right' : 'left';
            $replacement = '[[File:' . $importedImage['url'] . '|alt=' . $caption . '|' . $caption . ' (' . $licenseLabel . ')|' . $position . '|420px]]';
            $sourceTitle = preg_replace('/^(?:File|Image|চিত্র):/iu','',(string)($importedImage['source_title']??''));
            $replaced = 0;
            if ($sourceTitle !== '') {
                $articleText = preg_replace('/\[\[(?:File|Image|চিত্র):\s*'.preg_quote($sourceTitle,'/') . '(?:\|[^\]]*)?\]\]/iu', $replacement, $articleText, -1, $replaced) ?? $articleText;
            }
            if ($replaced === 0) $blocks[] = $replacement;
        }
        if (!empty($article['description'])) {
            $blocks[] = "'''{$title}''' — " . trim((string) $article['description']);
        }
        $blocks[] = $articleText;
        $blocks[] = "== Sources ==\n<ref>[{$safeSourceUrl} {$title}], {$sourceLabel}. Imported under {$license}; accessed " . gmdate('j F Y') . ".</ref>\n{{reflist}}";
        $blocks[] = '[[Category:Imported drafts]]';
        return implode("\n\n",array_filter($blocks));
    }
}
