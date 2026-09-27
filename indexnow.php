<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
if (INDEXNOW_KEY === '') { http_response_code(404); exit; }
echo INDEXNOW_KEY;
