<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sign in before uploading images.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST is required.']);
    exit;
}
verify_csrf();

if (IMGBB_API_KEY === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Image uploads are not configured. Add IMGBB_API_KEY to the server configuration.']);
    exit;
}
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['error' => 'Choose an image to upload.']);
    exit;
}
$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'The upload did not complete. Please choose the image again.']);
    exit;
}
if (($file['size'] ?? 0) < 1 || $file['size'] > 16 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Images must be smaller than 16 MB.']);
    exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
if (!isset($allowed[$mime])) {
    http_response_code(415);
    echo json_encode(['error' => 'Upload a JPEG, PNG, GIF, or WebP image.']);
    exit;
}
if (@getimagesize($file['tmp_name']) === false) {
    http_response_code(422);
    echo json_encode(['error' => 'The selected file is not a valid image.']);
    exit;
}

$rateStmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE user_id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)');
$rateStmt->execute([current_user()['id']]);
if ((int) $rateStmt->fetchColumn() >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Upload limit reached. Wait one minute and try again.']);
    exit;
}

$binary = file_get_contents($file['tmp_name']);
if ($binary === false) {
    http_response_code(500);
    echo json_encode(['error' => 'The image could not be read.']);
    exit;
}
$altText = mb_substr(trim((string) ($_POST['alt_text'] ?? '')), 0, 255);
if (mb_strlen($altText) < 3) {
    http_response_code(422);
    echo json_encode(['error' => 'Write useful alternative text describing the image.']);
    exit;
}
$name = slugify(pathinfo((string) ($file['name'] ?? 'wiki-image'), PATHINFO_FILENAME));

// ImgBB requires this exact format: API key in the URL and base64 image in the POST body.
$endpoint = 'https://api.imgbb.com/1/upload?key=' . rawurlencode(IMGBB_API_KEY);
$postBody = http_build_query([
    'image' => base64_encode($binary),
    'name' => mb_substr($name, 0, 100),
], '', '&', PHP_QUERY_RFC3986);

$responseBody = false;
$status = 0;
if (function_exists('curl_init')) {
    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postBody,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $responseBody = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
} else {
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 35,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($postBody) . "\r\n",
        'content' => $postBody, 'ignore_errors' => true,
    ]]);
    $responseBody = @file_get_contents($endpoint, false, $context);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $status = (int) $match[1];
    }
    $curlError = '';
}

$data = is_string($responseBody) ? json_decode($responseBody, true) : null;
if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['success']) || empty($data['data']['url'])) {
    error_log('ImgBB upload failed. HTTP ' . $status . ' ' . ($curlError ?? '') . ' ' . mb_substr((string) $responseBody, 0, 500));
    http_response_code(502);
    echo json_encode(['error' => 'ImgBB could not accept the image. Please try again shortly.']);
    exit;
}

$url = (string) ($data['data']['display_url'] ?? $data['data']['url']);
if (!str_starts_with($url, 'https://')) {
    http_response_code(502);
    echo json_encode(['error' => 'The image host returned an unsafe URL.']);
    exit;
}
$imageData = $data['data']['image'] ?? [];
$width = isset($data['data']['width']) ? (int) $data['data']['width'] : null;
$height = isset($data['data']['height']) ? (int) $data['data']['height'] : null;
$insert = $pdo->prepare('INSERT INTO images (user_id, imgbb_id, alt_text, file_path, delete_url, mime_type, width, height) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insert->execute([
    current_user()['id'], (string) ($data['data']['id'] ?? ''), mb_substr($altText, 0, 255), $url,
    (string) ($data['data']['delete_url'] ?? ''), $mime, $width, $height,
]);
log_activity($pdo, 'image.uploaded', 'image', (int) $pdo->lastInsertId(), ['host' => parse_url($url, PHP_URL_HOST)]);

echo json_encode([
    'location' => $url,
    'url' => $url,
    'thumb' => (string) ($data['data']['thumb']['url'] ?? $url),
    'width' => $width,
    'height' => $height,
    'alt' => $altText,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
