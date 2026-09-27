<?php
declare(strict_types=1);
require_once __DIR__ . '/RemoteFetcher.php';

final class ImageUploadException extends RuntimeException {}

final class ImageService
{
    private const MAX_BYTES = 16777216;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private PDO $pdo) {}

    public function importRemote(string $url, string $altText, array $source = []): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 1000) {
            throw new ImageUploadException('The remote image URL is missing or too long.');
        }
        $cached = $this->pdo->prepare('SELECT i.id, i.file_path, i.width, i.height FROM media_sources ms JOIN images i ON i.id = ms.image_id WHERE ms.source_url = ? ORDER BY ms.id DESC LIMIT 1');
        $cached->execute([$url]);
        if ($image = $cached->fetch()) {
            return ['id' => (int) $image['id'], 'url' => $image['file_path'], 'location' => $image['file_path'], 'width' => $image['width'], 'height' => $image['height'], 'cached' => true, 'alt' => $altText];
        }
        $download = (new RemoteFetcher())->fetch($url, self::ALLOWED_MIME, self::MAX_BYTES);
        $dimensions = @getimagesizefromstring($download['body']);
        if ($dimensions === false || (int) $dimensions[0] < 120 || (int) $dimensions[1] < 120) {
            throw new ImageUploadException('Remote images must be valid and at least 120 by 120 pixels.');
        }
        $name = pathinfo((string) parse_url($download['final_url'], PHP_URL_PATH), PATHINFO_FILENAME) ?: 'encyclopedia-image';
        $source['source_url'] = $download['final_url'];
        return $this->uploadBinary($download['body'], $download['content_type'], $name, $altText, $source);
    }

    public function uploadBinary(string $binary, string $mime, string $name, string $altText, array $source = []): array
    {
        if (IMGBB_API_KEY === '') {
            throw new ImageUploadException('Image uploads are not configured.');
        }
        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
        if (!is_string($detectedMime) || !in_array($detectedMime, self::ALLOWED_MIME, true)) {
            throw new ImageUploadException('Only valid JPEG, PNG, GIF, and WebP images are supported.');
        }
        $mime = $detectedMime;
        $bytes = strlen($binary);
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new ImageUploadException('Images must be smaller than 16 MB.');
        }
        $altText = mb_substr(trim($altText), 0, 255);
        if (mb_strlen($altText) < 3) {
            throw new ImageUploadException('Write useful alternative text describing the image.');
        }
        $dimensions = @getimagesizefromstring($binary);
        if ($dimensions === false) {
            throw new ImageUploadException('The selected resource is not a valid image.');
        }
        $pixelCount = (int) $dimensions[0] * (int) $dimensions[1];
        if ((int) $dimensions[0] > 12000 || (int) $dimensions[1] > 12000 || $pixelCount > 40000000) {
            throw new ImageUploadException('The image dimensions are too large to process safely.');
        }
        $safeName = mb_substr(slugify($name ?: 'encyclopedia-image'), 0, 100);
        $endpoint = 'https://api.imgbb.com/1/upload?key=' . rawurlencode(IMGBB_API_KEY);
        // Required ImgBB contract: key in URL, base64-encoded image in POST body.
        $postBody = http_build_query(['image' => base64_encode($binary), 'name' => $safeName], '', '&', PHP_QUERY_RFC3986);
        [$status, $responseBody, $transportError] = $this->postToImgBB($endpoint, $postBody);
        $data = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['success']) || empty($data['data']['url'])) {
            error_log('ImgBB upload failed. HTTP ' . $status . ' ' . $transportError);
            throw new ImageUploadException('ImgBB could not accept the image. Please try again shortly.');
        }
        $url = (string) ($data['data']['display_url'] ?? $data['data']['url']);
        $urlHost = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        if (!str_starts_with($url, 'https://') || ($urlHost !== 'i.ibb.co' && !str_ends_with($urlHost, '.i.ibb.co'))) {
            throw new ImageUploadException('The image host returned an unexpected URL.');
        }
        $width = isset($data['data']['width']) ? (int) $data['data']['width'] : (int) $dimensions[0];
        $height = isset($data['data']['height']) ? (int) $data['data']['height'] : (int) $dimensions[1];
        $insert = $this->pdo->prepare('INSERT INTO images (user_id, imgbb_id, alt_text, file_path, delete_url, mime_type, width, height) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([
            current_user()['id'] ?? null, (string) ($data['data']['id'] ?? ''), $altText, $url,
            (string) ($data['data']['delete_url'] ?? ''), $mime, $width, $height,
        ]);
        $imageId = (int) $this->pdo->lastInsertId();
        if (!empty($source['source_url'])) {
            $sourceUrl = mb_substr((string) $source['source_url'], 0, 1000);
            $sourceHost = mb_substr((string) parse_url($sourceUrl, PHP_URL_HOST), 0, 190);
            $mediaSource = $this->pdo->prepare('INSERT INTO media_sources (image_id, source_url, source_page_url, source_host, attribution, license_name) VALUES (?, ?, ?, ?, ?, ?)');
            $mediaSource->execute([
                $imageId, $sourceUrl, mb_substr((string) ($source['source_page_url'] ?? ''), 0, 1000) ?: null,
                $sourceHost, mb_substr((string) ($source['attribution'] ?? ''), 0, 500) ?: null,
                mb_substr((string) ($source['license_name'] ?? ''), 0, 100) ?: null,
            ]);
        }
        log_activity($this->pdo, 'image.uploaded_to_imgbb', 'image', $imageId, ['host' => parse_url($url, PHP_URL_HOST), 'remote_import' => !empty($source['source_url'])]);
        $thumb = (string) ($data['data']['thumb']['url'] ?? $url);
        $thumbHost = mb_strtolower((string) parse_url($thumb, PHP_URL_HOST));
        if (!str_starts_with($thumb, 'https://') || ($thumbHost !== 'i.ibb.co' && !str_ends_with($thumbHost, '.i.ibb.co'))) {
            $thumb = $url;
        }
        return [
            'id' => $imageId, 'location' => $url, 'url' => $url,
            'thumb' => $thumb,
            'width' => $width, 'height' => $height, 'alt' => $altText, 'cached' => false,
        ];
    }

    private function postToImgBB(string $endpoint, string $body): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($endpoint);
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            return [$status, is_string($response) ? $response : '', $error];
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST', 'timeout' => 40, 'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n",
                'content' => $body,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($endpoint, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }
        return [$status, is_string($response) ? $response : '', ''];
    }
}
