<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';
require_once APP_ROOT . '/includes/ImageService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
$rate = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = 'image.local_upload_requested' AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)");
$rate->execute([current_user()['id']]);
if ((int) $rate->fetchColumn() >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Upload limit reached. Wait one minute and try again.']);
    exit;
}
log_activity($pdo, 'image.local_upload_requested', 'image');
try {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $binary = file_get_contents($file['tmp_name']);
    if (!is_string($binary)) {
        throw new ImageUploadException('The image could not be read.');
    }
    $name = pathinfo((string) ($file['name'] ?? 'wiki-image'), PATHINFO_FILENAME);
    $result = (new ImageService($pdo))->uploadBinary($binary, (string) $mime, $name, (string) ($_POST['alt_text'] ?? ''));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (ImageUploadException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Image upload error: ' . $exception->getMessage());
    http_response_code(502);
    echo json_encode(['error' => 'The image service is temporarily unavailable.']);
}
