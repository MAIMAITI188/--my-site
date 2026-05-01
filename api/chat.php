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

function clean_text($value, int $limit): string {
    $text = trim((string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    $text = preg_replace('/\s{3,}/u', '  ', $text) ?? $text;
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return strlen($text) > $limit * 4 ? substr($text, 0, $limit * 4) : $text;
}

function chat_file(): string {
    $dataDir = realpath(__DIR__ . '/../data');
    if ($dataDir === false || !is_dir($dataDir)) {
        respond(500, ['ok' => false, 'error' => 'data_directory_missing']);
    }
    return $dataDir . DIRECTORY_SEPARATOR . 'chat.json';
}

function read_messages(string $file): array {
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function public_messages(array $messages): array {
    return array_map(static function (array $message): array {
        unset($message['ipHash']);
        return $message;
    }, $messages);
}

function write_messages(string $file, array $messages): void {
    $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        respond(500, ['ok' => false, 'error' => 'json_encode_failed']);
    }
    $temp = $file . '.tmp';
    if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false) {
        respond(500, ['ok' => false, 'error' => 'write_failed']);
    }
    if (!rename($temp, $file)) {
        @unlink($temp);
        respond(500, ['ok' => false, 'error' => 'rename_failed']);
    }
    @chmod($file, 0664);
}

$file = chat_file();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    respond(200, ['ok' => true, 'messages' => public_messages(read_messages($file))]);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 4096) {
    respond(400, ['ok' => false, 'error' => 'invalid_body']);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

$name = clean_text($body['name'] ?? '游客', 18);
$text = clean_text($body['text'] ?? '', 300);
if ($name === '') {
    $name = '游客';
}
if ($text === '') {
    respond(422, ['ok' => false, 'error' => 'empty_message']);
}

$ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $ip)[0] ?? '');
$ipHash = hash('sha256', $ip);
$messages = read_messages($file);
$now = (int) round(microtime(true) * 1000);

foreach (array_slice(array_reverse($messages), 0, 5) as $message) {
    if (($message['ipHash'] ?? '') === $ipHash && $now - (int) ($message['createdAt'] ?? 0) < 3000) {
        respond(429, ['ok' => false, 'error' => 'too_many_requests']);
    }
}

$messages[] = [
    'id' => bin2hex(random_bytes(8)),
    'name' => $name,
    'text' => $text,
    'createdAt' => $now,
    'ipHash' => $ipHash,
];

$messages = array_slice($messages, -200);
write_messages($file, $messages);

respond(200, ['ok' => true, 'messages' => public_messages($messages)]);
