<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function has_basic_auth(): bool {
    return !empty($_SERVER['PHP_AUTH_USER'])
        || !empty($_SERVER['REMOTE_USER'])
        || !empty($_SERVER['REDIRECT_REMOTE_USER']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!has_basic_auth()) {
    respond(403, ['ok' => false, 'error' => 'admin_directory_not_protected']);
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    respond(400, ['ok' => false, 'error' => 'missing_image']);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
    respond(400, ['ok' => false, 'error' => 'upload_failed']);
}

$maxSize = 8 * 1024 * 1024;
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxSize) {
    respond(413, ['ok' => false, 'error' => 'image_too_large']);
}

$mime = mime_content_type($file['tmp_name']) ?: '';
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($extensions[$mime])) {
    respond(422, ['ok' => false, 'error' => 'unsupported_image_type']);
}

$dataDir = realpath(__DIR__ . '/../../data');
if ($dataDir === false || !is_dir($dataDir)) {
    respond(500, ['ok' => false, 'error' => 'data_directory_missing']);
}

$uploadDir = $dataDir . DIRECTORY_SEPARATOR . 'gallery-images';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
    respond(500, ['ok' => false, 'error' => 'upload_directory_missing']);
}

$base = strtolower(pathinfo((string)($file['name'] ?? 'image'), PATHINFO_FILENAME));
$base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?: 'image';
$base = trim($base, '-_') ?: 'image';
$name = $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
$target = $uploadDir . DIRECTORY_SEPARATOR . $name;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    respond(500, ['ok' => false, 'error' => 'move_failed']);
}

chmod($target, 0664);

respond(200, [
    'ok' => true,
    'path' => 'data/gallery-images/' . $name,
]);
