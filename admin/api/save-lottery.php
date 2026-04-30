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

function valid_lottery_item($item): bool {
    if (!is_assoc_array($item)) {
        return false;
    }

    $num = isset($item['num']) ? (string) $item['num'] : '';
    $color = isset($item['color']) ? (string) $item['color'] : '';
    return preg_match('/^(0[1-9]|[1-4][0-9])$/', $num) === 1
        && in_array($color, ['red', 'blue', 'green'], true);
}

function valid_lottery_entry($entry): bool {
    if ($entry === null) {
        return true;
    }
    if (!is_assoc_array($entry)) {
        return false;
    }
    if (!isset($entry['items']) || !is_array($entry['items']) || count($entry['items']) !== 7) {
        return false;
    }
    foreach ($entry['items'] as $item) {
        if (!valid_lottery_item($item)) {
            return false;
        }
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!has_basic_auth()) {
    respond(403, [
        'ok' => false,
        'error' => 'admin_directory_not_protected',
        'message' => 'Protect the admin directory with Hostinger password protection before server writes are enabled.'
    ]);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 1024 * 1024) {
    respond(400, ['ok' => false, 'error' => 'invalid_body']);
}

$payload = json_decode($raw, true);
if (!is_assoc_array($payload)) {
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$current = $payload['current'] ?? null;
$history = $payload['history'] ?? [];
$countdown = $payload['countdown'] ?? null;

if (!valid_lottery_entry($current) || !is_array($history)) {
    respond(422, ['ok' => false, 'error' => 'invalid_lottery_data']);
}

foreach ($history as $entry) {
    if (!valid_lottery_entry($entry)) {
        respond(422, ['ok' => false, 'error' => 'invalid_history_data']);
    }
}

$data = [
    'current' => $current,
    'history' => array_values($history),
    'countdown' => is_assoc_array($countdown) ? $countdown : null,
    'exportedAt' => time() * 1000
];

$target = realpath(__DIR__ . '/../../data');
if ($target === false || !is_dir($target)) {
    respond(500, ['ok' => false, 'error' => 'data_directory_missing']);
}

$file = $target . DIRECTORY_SEPARATOR . 'lottery.json';
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false || file_put_contents($file, $json . PHP_EOL, LOCK_EX) === false) {
    respond(500, ['ok' => false, 'error' => 'write_failed']);
}

respond(200, ['ok' => true, 'file' => 'data/lottery.json']);
