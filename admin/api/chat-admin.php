<?php
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const CHAT_IMAGE_DIR = 'images/chat';
const CHAT_MESSAGE_TTL_MS = 86400000;
const CHAT_BAN_FOREVER_UNTIL = 32503680000000;

function respond_json(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_download(string $filename, string $content, string $contentType): void {
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

function has_basic_auth(): bool {
    return !empty($_SERVER['PHP_AUTH_USER'])
        || !empty($_SERVER['REMOTE_USER'])
        || !empty($_SERVER['REDIRECT_REMOTE_USER']);
}

function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function normalize_name(string $name): string {
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function clean_text($value, int $limit): string {
    $text = trim((string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }
    return strlen($text) > $limit * 4 ? substr($text, 0, $limit * 4) : $text;
}

function data_dir(): string {
    $dir = realpath(__DIR__ . '/../../data');
    if ($dir === false || !is_dir($dir)) {
        respond_json(500, ['ok' => false, 'error' => 'data_directory_missing']);
    }
    return $dir;
}

function data_file(string $name): string {
    return data_dir() . DIRECTORY_SEPARATOR . $name;
}

function site_path(string $relativePath): string {
    $root = realpath(__DIR__ . '/../..');
    if ($root === false || !is_dir($root)) {
        respond_json(500, ['ok' => false, 'error' => 'site_root_missing']);
    }
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function decode_json(string $raw, array $fallback): array {
    if ($raw === '') {
        return $fallback;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}

function read_json_file(string $file, array $fallback = []): array {
    if (!is_file($file)) {
        return $fallback;
    }
    $raw = file_get_contents($file);
    return $raw === false ? $fallback : decode_json($raw, $fallback);
}

function with_json_file_lock(string $file, array $fallback, callable $callback): array {
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        respond_json(500, ['ok' => false, 'error' => 'open_failed']);
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        respond_json(500, ['ok' => false, 'error' => 'lock_failed']);
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = $raw === false ? $fallback : decode_json($raw, $fallback);
    $result = $callback($data);
    $nextData = $result['data'] ?? $data;
    $payload = $result['payload'] ?? [];
    $json = json_encode($nextData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond_json(500, ['ok' => false, 'error' => 'json_encode_failed']);
    }

    ftruncate($handle, 0);
    rewind($handle);
    if (fwrite($handle, $json . PHP_EOL) === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond_json(500, ['ok' => false, 'error' => 'write_failed']);
    }
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0664);
    return $payload;
}

function read_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1024 * 1024) {
        respond_json(400, ['ok' => false, 'error' => 'invalid_body']);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        respond_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    return $body;
}

function default_settings(): array {
    return [
        'pins' => [
            ['name' => '系统', 'avatar' => '📢', 'text' => '欢迎来到聊天室模板。'],
            ['name' => '小港', 'avatar' => '💎', 'text' => '这里可以放公告、闲聊、开奖讨论。'],
        ],
        'updatedAt' => 0,
    ];
}

function normalize_settings(array $settings): array {
    $fallback = default_settings();
    $pins = $settings['pins'] ?? [];
    if (!is_array($pins)) {
        $pins = [];
    }
    $cleanPins = [];
    foreach (array_slice($pins, 0, 2) as $key => $pin) {
        if (!is_array($pin)) {
            $pin = [];
        }
        $default = $fallback['pins'][$key] ?? $fallback['pins'][0];
        $cleanPins[] = [
            'name' => clean_text($pin['name'] ?? $default['name'], 18) ?: $default['name'],
            'avatar' => clean_text($pin['avatar'] ?? $default['avatar'], 8) ?: $default['avatar'],
            'text' => clean_text($pin['text'] ?? $default['text'], 300),
        ];
    }
    while (count($cleanPins) < 2) {
        $cleanPins[] = $fallback['pins'][count($cleanPins)];
    }
    return [
        'pins' => $cleanPins,
        'updatedAt' => (int) ($settings['updatedAt'] ?? 0),
    ];
}

function fresh_messages(array $messages): array {
    $cutoff = now_ms() - CHAT_MESSAGE_TTL_MS;
    return array_values(array_filter($messages, static function (array $message) use ($cutoff): bool {
        return (int) ($message['createdAt'] ?? 0) >= $cutoff;
    }));
}

function public_message_for_admin(array $message): array {
    unset($message['ipHash']);
    return $message;
}

function is_ban_active(array $ban): bool {
    $until = (int) ($ban['until'] ?? 0);
    return $until === 0 || $until > now_ms();
}

function public_ban(array $ban): array {
    return [
        'phone' => (string) ($ban['phone'] ?? ''),
        'name' => (string) ($ban['name'] ?? ''),
        'normalizedName' => (string) ($ban['normalizedName'] ?? ''),
        'reason' => (string) ($ban['reason'] ?? ''),
        'until' => (int) ($ban['until'] ?? 0),
        'createdAt' => (int) ($ban['createdAt'] ?? 0),
        'active' => is_ban_active($ban),
    ];
}

function remove_chat_images_for_messages(array $messages): void {
    foreach ($messages as $message) {
        $src = (string) ($message['image']['src'] ?? '');
        if ($src === '' || strpos($src, CHAT_IMAGE_DIR . '/') !== 0 || strpos($src, '..') !== false) {
            continue;
        }
        $path = site_path($src);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function find_user_by_name(array $users, string $name): ?array {
    $normalized = normalize_name($name);
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $existing = (string) ($user['normalizedName'] ?? normalize_name((string) ($user['name'] ?? '')));
        if ($existing === $normalized) {
            return $user;
        }
    }
    return null;
}

function csv_cell($value): string {
    $text = str_replace('"', '""', (string) $value);
    return '"' . $text . '"';
}

function format_date_ms(int $ms): string {
    if ($ms <= 0) {
        return '';
    }
    return date('Y-m-d H:i:s', (int) floor($ms / 1000));
}

function overview(): array {
    $users = read_json_file(data_file('chat-users.json'));
    $messages = fresh_messages(read_json_file(data_file('chat.json')));
    $bans = read_json_file(data_file('chat-bans.json'));
    $settings = normalize_settings(read_json_file(data_file('chat-settings.json'), default_settings()));

    return [
        'ok' => true,
        'settings' => $settings,
        'users' => array_values(array_map(static function (array $user): array {
            return [
                'phone' => (string) ($user['phone'] ?? ''),
                'name' => (string) ($user['name'] ?? ''),
                'avatar' => (string) ($user['avatar'] ?? ''),
                'createdAt' => (int) ($user['createdAt'] ?? 0),
                'updatedAt' => (int) ($user['updatedAt'] ?? 0),
                'profileChangedAt' => (int) ($user['profileChangedAt'] ?? 0),
            ];
        }, array_filter($users, 'is_array'))),
        'messages' => array_values(array_map('public_message_for_admin', array_filter($messages, 'is_array'))),
        'bans' => array_values(array_map('public_ban', array_filter($bans, 'is_array'))),
    ];
}

function save_settings(array $body): array {
    $pins = $body['pins'] ?? [];
    if (!is_array($pins)) {
        respond_json(422, ['ok' => false, 'error' => 'invalid_pins']);
    }
    $settings = normalize_settings(['pins' => $pins, 'updatedAt' => now_ms()]);
    with_json_file_lock(data_file('chat-settings.json'), default_settings(), static function () use ($settings): array {
        return ['data' => $settings, 'payload' => []];
    });
    return ['ok' => true, 'settings' => $settings];
}

function mute_user(array $body): array {
    $name = clean_text($body['name'] ?? '', 18);
    $days = (int) ($body['days'] ?? 0);
    $reason = clean_text($body['reason'] ?? 'admin_mute', 80) ?: 'admin_mute';
    if ($name === '' || $days < 1 || $days > 3650) {
        respond_json(422, ['ok' => false, 'error' => 'invalid_mute']);
    }

    $users = read_json_file(data_file('chat-users.json'));
    $user = find_user_by_name($users, $name);
    if ($user === null) {
        respond_json(404, ['ok' => false, 'error' => 'user_not_found']);
    }

    $normalizedName = (string) ($user['normalizedName'] ?? normalize_name((string) ($user['name'] ?? $name)));
    $until = now_ms() + $days * 24 * 60 * 60 * 1000;
    return with_json_file_lock(data_file('chat-bans.json'), [], static function (array $bans) use ($user, $normalizedName, $reason, $until): array {
        $phone = (string) ($user['phone'] ?? '');
        $next = [];
        foreach ($bans as $ban) {
            if (!is_array($ban)) {
                continue;
            }
            if (($phone !== '' && ($ban['phone'] ?? '') === $phone) || (($ban['normalizedName'] ?? '') === $normalizedName)) {
                continue;
            }
            $next[] = $ban;
        }
        $ban = [
            'phone' => $phone,
            'name' => (string) ($user['name'] ?? ''),
            'normalizedName' => $normalizedName,
            'reason' => $reason,
            'until' => $until,
            'createdAt' => now_ms(),
        ];
        $next[] = $ban;
        return ['data' => $next, 'payload' => ['ok' => true, 'ban' => public_ban($ban)]];
    });
}

function unmute_user(array $body): array {
    $name = clean_text($body['name'] ?? '', 18);
    if ($name === '') {
        respond_json(422, ['ok' => false, 'error' => 'empty_name']);
    }
    $normalizedName = normalize_name($name);
    return with_json_file_lock(data_file('chat-bans.json'), [], static function (array $bans) use ($normalizedName): array {
        $before = count($bans);
        $next = array_values(array_filter($bans, static function ($ban) use ($normalizedName): bool {
            return is_array($ban) && (string) ($ban['normalizedName'] ?? '') !== $normalizedName;
        }));
        return ['data' => $next, 'payload' => ['ok' => true, 'removed' => $before - count($next)]];
    });
}

function delete_message(array $body): array {
    $id = clean_text($body['id'] ?? '', 80);
    if ($id === '') {
        respond_json(422, ['ok' => false, 'error' => 'empty_id']);
    }
    return with_json_file_lock(data_file('chat.json'), [], static function (array $messages) use ($id): array {
        $removed = [];
        $next = [];
        foreach ($messages as $message) {
            if (is_array($message) && (string) ($message['id'] ?? '') === $id) {
                $removed[] = $message;
                continue;
            }
            $next[] = $message;
        }
        remove_chat_images_for_messages($removed);
        return ['data' => array_values($next), 'payload' => ['ok' => true, 'removed' => count($removed)]];
    });
}

function clear_messages(): array {
    return with_json_file_lock(data_file('chat.json'), [], static function (array $messages): array {
        remove_chat_images_for_messages(array_filter($messages, 'is_array'));
        return ['data' => [], 'payload' => ['ok' => true, 'removed' => count($messages)]];
    });
}

function export_users_csv(): void {
    $users = array_filter(read_json_file(data_file('chat-users.json')), 'is_array');
    $rows = [
        ['手机号', 'ID昵称', '头像', '注册时间', '最后更新时间', '下次可改资料时间'],
    ];
    foreach ($users as $user) {
        $rows[] = [
            (string) ($user['phone'] ?? ''),
            (string) ($user['name'] ?? ''),
            (string) ($user['avatar'] ?? ''),
            format_date_ms((int) ($user['createdAt'] ?? 0)),
            format_date_ms((int) ($user['updatedAt'] ?? 0)),
            format_date_ms((int) ($user['profileChangedAt'] ?? 0) + 259200000),
        ];
    }
    $csv = "\xEF\xBB\xBF" . implode("\r\n", array_map(static function (array $row): string {
        return implode(',', array_map('csv_cell', $row));
    }, $rows)) . "\r\n";
    respond_download('chat-users-' . date('Ymd-His') . '.csv', $csv, 'text/csv; charset=utf-8');
}

if (!has_basic_auth()) {
    respond_json(403, ['ok' => false, 'error' => 'admin_directory_not_protected']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    if ($action === 'export-users') {
        export_users_csv();
    }
    respond_json(200, overview());
}

if ($method !== 'POST') {
    respond_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$body = read_body();
$action = (string) ($body['action'] ?? '');

if ($action === 'save-settings') {
    respond_json(200, save_settings($body));
}
if ($action === 'mute') {
    respond_json(200, mute_user($body));
}
if ($action === 'unmute') {
    respond_json(200, unmute_user($body));
}
if ($action === 'delete-message') {
    respond_json(200, delete_message($body));
}
if ($action === 'clear-messages') {
    respond_json(200, clear_messages());
}

respond_json(400, ['ok' => false, 'error' => 'unknown_action']);
