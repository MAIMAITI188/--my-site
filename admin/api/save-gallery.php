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

function is_assoc_array($value): bool {
    return is_array($value) && array_keys($value) !== range(0, count($value) - 1);
}

function valid_text($value, int $limit): bool {
    return is_string($value) && strlen($value) <= $limit && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
}

function valid_image($value): bool {
    if (!valid_text($value, 5 * 1024 * 1024)) {
        return false;
    }
    if ($value === '') {
        return false;
    }
    if (preg_match('/^data:image\/(?:png|jpe?g|webp|gif);base64,/i', $value) === 1) {
        return true;
    }
    if (preg_match('/^https?:\/\//i', $value) === 1) {
        return true;
    }
    return preg_match('/^(?:\.\/)?images\/gallery\/[A-Za-z0-9_\/.%-]+$/', $value) === 1
        && strpos($value, '..') === false;
}

function valid_link($value): bool {
    if (!valid_text($value, 1000)) {
        return false;
    }
    if ($value === '') {
        return true;
    }
    return preg_match('/^(https?:\/\/|\/(?!\/)|\.\/|#)/i', $value) === 1;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!has_basic_auth()) {
    respond(403, [
        'ok' => false,
        'error' => 'admin_directory_not_protected',
        'message' => 'Protect the admin directory with server-side Basic Auth before server writes are enabled.'
    ]);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 20 * 1024 * 1024) {
    respond(400, ['ok' => false, 'error' => 'invalid_body']);
}

$items = json_decode($raw, true);
if (!is_array($items)) {
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$clean = [];
foreach ($items as $item) {
    if (!is_assoc_array($item)) {
        respond(422, ['ok' => false, 'error' => 'invalid_gallery_item']);
    }

    $title = isset($item['title']) ? (string) $item['title'] : '';
    $image = isset($item['image']) ? (string) $item['image'] : '';
    $link = isset($item['link']) ? (string) $item['link'] : '';
    $sort = isset($item['sort']) && is_numeric($item['sort']) ? (int) $item['sort'] : 0;
    $enabled = !array_key_exists('enabled', $item) || $item['enabled'] !== false;

    if (!valid_text($title, 200) || !valid_image($image) || !valid_link($link)) {
        respond(422, ['ok' => false, 'error' => 'invalid_gallery_item']);
    }

    $clean[] = [
        'title' => $title,
        'image' => $image,
        'link' => $link,
        'sort' => $sort,
        'enabled' => $enabled
    ];
}

$target = realpath(__DIR__ . '/../../data');
if ($target === false || !is_dir($target)) {
    respond(500, ['ok' => false, 'error' => 'data_directory_missing']);
}

$file = $target . DIRECTORY_SEPARATOR . 'gallery.json';
$json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
    respond(500, ['ok' => false, 'error' => 'write_failed']);
}

respond(200, ['ok' => true, 'file' => 'data/gallery.json']);
