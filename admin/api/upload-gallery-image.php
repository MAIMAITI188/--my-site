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

function ensure_directory(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true)) {
        respond(500, ['ok' => false, 'error' => 'upload_directory_missing']);
    }
}

function create_image_resource(string $path, string $mime) {
    return match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
}

function save_thumbnail(string $source, string $target, string $mime, int $maxSide = 1200): bool {
    if (!function_exists('imagecreatetruecolor') || $mime === 'image/gif') {
        return false;
    }

    $info = @getimagesize($source);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return false;
    }

    $width = (int) $info[0];
    $height = (int) $info[1];
    $scale = min(1, $maxSide / max($width, $height));
    $thumbWidth = max(1, (int) round($width * $scale));
    $thumbHeight = max(1, (int) round($height * $scale));

    $image = create_image_resource($source, $mime);
    if (!$image) {
        return false;
    }

    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    if (!$thumb) {
        imagedestroy($image);
        return false;
    }

    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefilledrectangle($thumb, 0, 0, $thumbWidth, $thumbHeight, $transparent);
    }

    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

    $saved = match ($mime) {
        'image/jpeg' => imagejpeg($thumb, $target, 88),
        'image/png' => imagepng($thumb, $target, 6),
        'image/webp' => imagewebp($thumb, $target, 88),
        default => false,
    };

    imagedestroy($thumb);
    imagedestroy($image);

    if ($saved) {
        chmod($target, 0664);
    }

    return $saved;
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

$maxSize = 50 * 1024 * 1024;
if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxSize) {
    respond(413, ['ok' => false, 'error' => 'image_too_large']);
}

$mime = mime_content_type($file['tmp_name']) ?: '';
if ($mime === 'application/octet-stream' || $mime === '') {
    $imageInfo = @getimagesize($file['tmp_name']);
    $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? (string) $imageInfo['mime'] : $mime;
}
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
$originalDir = $uploadDir . DIRECTORY_SEPARATOR . 'original';
$thumbDir = $uploadDir . DIRECTORY_SEPARATOR . 'thumbs';
ensure_directory($uploadDir);
ensure_directory($originalDir);
ensure_directory($thumbDir);

$base = strtolower(pathinfo((string)($file['name'] ?? 'image'), PATHINFO_FILENAME));
$base = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?: 'image';
$base = trim($base, '-_') ?: 'image';
$name = $base . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
$target = $originalDir . DIRECTORY_SEPARATOR . $name;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    respond(500, ['ok' => false, 'error' => 'move_failed']);
}

chmod($target, 0664);

$originalPath = 'data/gallery-images/original/' . $name;
$thumbPath = '';
$thumbTarget = $thumbDir . DIRECTORY_SEPARATOR . $name;
if (save_thumbnail($target, $thumbTarget, $mime)) {
    $thumbPath = 'data/gallery-images/thumbs/' . $name;
}

respond(200, [
    'ok' => true,
    'path' => $originalPath,
    'thumb' => $thumbPath ?: $originalPath,
]);
