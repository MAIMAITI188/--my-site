<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const PROFILE_COOLDOWN_MS = 259200000;
const CHAT_IMAGE_MAX_BYTES = 5242880;
const CHAT_IMAGE_DIR = 'images/chat';
const CHAT_MESSAGE_TTL_MS = 86400000;
const CHAT_BAN_FOREVER_UNTIL = 32503680000000;

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

function clean_phone($value): string {
    return preg_replace('/\D+/', '', (string) $value) ?? '';
}

function normalize_name(string $name): string {
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function now_ms(): int {
    return (int) round(microtime(true) * 1000);
}

function data_file(string $name): string {
    $dataDir = realpath(__DIR__ . '/../data');
    if ($dataDir === false || !is_dir($dataDir)) {
        respond(500, ['ok' => false, 'error' => 'data_directory_missing']);
    }
    return $dataDir . DIRECTORY_SEPARATOR . $name;
}

function public_path(string $path): string {
    return str_replace('\\', '/', $path);
}

function site_path(string $relativePath): string {
    $root = realpath(__DIR__ . '/..');
    if ($root === false || !is_dir($root)) {
        respond(500, ['ok' => false, 'error' => 'site_root_missing']);
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
        respond(500, ['ok' => false, 'error' => 'open_failed']);
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        respond(500, ['ok' => false, 'error' => 'lock_failed']);
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
        respond(500, ['ok' => false, 'error' => 'json_encode_failed']);
    }

    ftruncate($handle, 0);
    rewind($handle);
    if (fwrite($handle, $json . PHP_EOL) === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        respond(500, ['ok' => false, 'error' => 'write_failed']);
    }
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0664);
    return $payload;
}

function public_user(array $user): array {
    return [
        'phone' => (string) ($user['phone'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'avatar' => (string) ($user['avatar'] ?? '😀'),
        'profileChangedAt' => (int) ($user['profileChangedAt'] ?? 0),
    ];
}

function public_messages(array $messages): array {
    return array_map(static function (array $message): array {
        unset($message['ipHash']);
        unset($message['phone']);
        return $message;
    }, $messages);
}

function default_settings(): array {
    return [
        'pins' => [
            ['name' => '系统', 'avatar' => '📢', 'text' => '欢迎来到聊天室模板。'],
        ],
        'updatedAt' => 0,
    ];
}

function public_settings(): array {
    $fallback = default_settings();
    $settings = read_json_file(data_file('chat-settings.json'), $fallback);
    $pins = $settings['pins'] ?? [];
    if (!is_array($pins)) {
        $pins = [];
    }
    $cleanPins = [];
    foreach (array_slice($pins, 0, 1) as $key => $pin) {
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
    while (count($cleanPins) < 1) {
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

function is_ban_active(array $ban): bool {
    $until = (int) ($ban['until'] ?? 0);
    return $until === 0 || $until > now_ms();
}

function find_active_ban(string $phone, string $name = ''): ?array {
    $normalizedName = $name !== '' ? normalize_name($name) : '';
    $bans = read_json_file(data_file('chat-bans.json'));
    foreach ($bans as $ban) {
        if (!is_array($ban) || !is_ban_active($ban)) {
            continue;
        }
        if ($phone !== '' && ($ban['phone'] ?? '') === $phone) {
            return $ban;
        }
        if ($normalizedName !== '' && ($ban['normalizedName'] ?? '') === $normalizedName) {
            return $ban;
        }
    }
    return null;
}

function forbidden_text_reason(string $text): string {
    $compact = preg_replace('/[\s\-_.，。；;:：、|\/\\\\]+/u', '', $text) ?? $text;
    $lower = normalize_name($compact);
    if (preg_match('/(?<!\d)\d{11}(?!\d)/', $compact) === 1) {
        return 'phone_number';
    }
    foreach (['微信号', '微信', '联系方式', '联系我', '加微信', '二维码', '扫码', '扫一扫'] as $term) {
        if (strpos($lower, normalize_name($term)) !== false) {
            return 'forbidden_contact';
        }
    }
    foreach (['wechat', 'weixin', 'vx', 'v信'] as $term) {
        if (strpos($lower, $term) !== false) {
            return 'forbidden_contact';
        }
    }
    return '';
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

function permanently_ban_user(string $phone, string $name, string $reason): void {
    $normalizedName = $name !== '' ? normalize_name($name) : '';
    $now = now_ms();

    with_json_file_lock(data_file('chat-bans.json'), [], static function (array $bans) use ($phone, $name, $normalizedName, $reason, $now): array {
        $next = [];
        foreach ($bans as $ban) {
            if (!is_array($ban)) {
                continue;
            }
            if (($phone !== '' && ($ban['phone'] ?? '') === $phone) || ($normalizedName !== '' && ($ban['normalizedName'] ?? '') === $normalizedName)) {
                continue;
            }
            $next[] = $ban;
        }
        $next[] = [
            'phone' => $phone,
            'name' => $name,
            'normalizedName' => $normalizedName,
            'reason' => $reason,
            'until' => CHAT_BAN_FOREVER_UNTIL,
            'createdAt' => $now,
        ];
        return ['data' => $next, 'payload' => []];
    });

    with_json_file_lock(data_file('chat-users.json'), [], static function (array $users) use ($phone, $normalizedName): array {
        $next = array_values(array_filter($users, static function (array $user) use ($phone, $normalizedName): bool {
            $existingName = (string) ($user['normalizedName'] ?? normalize_name((string) ($user['name'] ?? '')));
            return !(($phone !== '' && ($user['phone'] ?? '') === $phone) || ($normalizedName !== '' && $existingName === $normalizedName));
        }));
        return ['data' => $next, 'payload' => []];
    });

    with_json_file_lock(data_file('chat.json'), [], static function (array $messages) use ($phone, $normalizedName): array {
        $removed = [];
        $next = [];
        foreach ($messages as $message) {
            $messageName = normalize_name((string) ($message['name'] ?? ''));
            $matched = ($phone !== '' && ($message['phone'] ?? '') === $phone) || ($normalizedName !== '' && $messageName === $normalizedName);
            if ($matched) {
                $removed[] = $message;
            } else {
                $next[] = $message;
            }
        }
        remove_chat_images_for_messages($removed);
        return ['data' => array_values($next), 'payload' => []];
    });
}

function enforce_not_banned(string $phone, string $name = ''): void {
    $ban = find_active_ban($phone, $name);
    if ($ban !== null) {
        $until = (int) ($ban['until'] ?? 0);
        $error = $until >= CHAT_BAN_FOREVER_UNTIL || $until === 0 ? 'permanent_ban' : 'muted';
        respond(403, ['ok' => false, 'error' => $error, 'until' => $until]);
    }
}

function enforce_safe_text_or_ban(string $phone, string $name, string $text): void {
    $reason = forbidden_text_reason($text);
    if ($reason !== '') {
        permanently_ban_user($phone, $name, $reason);
        respond(403, ['ok' => false, 'error' => 'permanent_ban']);
    }
}

function find_user_by_phone(array $users, string $phone): ?array {
    foreach ($users as $user) {
        if (($user['phone'] ?? '') === $phone) {
            return $user;
        }
    }
    return null;
}

function read_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 4096) {
        respond(400, ['ok' => false, 'error' => 'invalid_body']);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        respond(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    return $body;
}

function client_ip_hash(): string {
    $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $ip)[0] ?? '');
    return hash('sha256', $ip);
}

function assert_chat_rate_limit(array $messages, string $ipHash, int $now, string $text = '', ?string $cleanupFile = null): void {
    foreach (array_slice(array_reverse($messages), 0, 20) as $message) {
        if (($message['ipHash'] ?? '') !== $ipHash) {
            continue;
        }

        $age = $now - (int) ($message['createdAt'] ?? 0);
        if ($age < 3000) {
            if ($cleanupFile !== null) {
                @unlink($cleanupFile);
            }
            respond(429, ['ok' => false, 'error' => 'too_many_requests']);
        }
        if ($text !== '' && $age < 60000 && clean_text($message['text'] ?? '', 300) === $text) {
            if ($cleanupFile !== null) {
                @unlink($cleanupFile);
            }
            respond(429, ['ok' => false, 'error' => 'duplicate_message']);
        }
    }
}

function register_user(array $body): array {
    $phone = clean_phone($body['phone'] ?? '');
    $name = clean_text($body['name'] ?? '', 18);
    $avatar = clean_text($body['avatar'] ?? '😀', 8);
    if (!preg_match('/^\d{11}$/', $phone)) {
        respond(422, ['ok' => false, 'error' => 'invalid_phone']);
    }
    if ($name === '') {
        respond(422, ['ok' => false, 'error' => 'empty_name']);
    }
    enforce_not_banned($phone, $name);
    enforce_safe_text_or_ban($phone, $name, $name);

    $usersFile = data_file('chat-users.json');
    return with_json_file_lock($usersFile, [], static function (array $users) use ($phone, $name, $avatar): array {
        $now = now_ms();
        $normalizedName = normalize_name($name);
        foreach ($users as $existing) {
            $existingName = (string) ($existing['normalizedName'] ?? normalize_name((string) ($existing['name'] ?? '')));
            if ($existingName === $normalizedName && ($existing['phone'] ?? '') !== $phone) {
                respond(409, ['ok' => false, 'error' => 'id_taken']);
            }
        }

        foreach ($users as &$user) {
            if (($user['phone'] ?? '') !== $phone) {
                continue;
            }

            $existingName = (string) ($user['normalizedName'] ?? normalize_name((string) ($user['name'] ?? '')));
            if ($existingName === $normalizedName) {
                $nextUser = $user;
                break;
            }

            $lastChangedAt = (int) ($user['profileChangedAt'] ?? 0);
            $waitMs = max(0, PROFILE_COOLDOWN_MS - ($now - $lastChangedAt));
            if ($waitMs > 0) {
                respond(429, ['ok' => false, 'error' => 'profile_cooldown', 'waitMs' => $waitMs]);
            }

            $user['name'] = $name;
            $user['normalizedName'] = $normalizedName;
            $user['avatar'] = $avatar ?: ((string) ($user['avatar'] ?? '😀'));
            $user['updatedAt'] = $now;
            $user['profileChangedAt'] = $now;
            $nextUser = $user;
            break;
        }
        unset($user);

        if (!isset($nextUser)) {
            $nextUser = [
                'phone' => $phone,
                'name' => $name,
                'normalizedName' => $normalizedName,
                'avatar' => $avatar ?: '😀',
                'createdAt' => $now,
                'updatedAt' => $now,
                'profileChangedAt' => $now,
            ];
            $users[] = $nextUser;
        }

        return ['data' => $users, 'payload' => ['ok' => true, 'user' => public_user($nextUser)]];
    });
}

function update_profile(array $body): array {
    $phone = clean_phone($body['phone'] ?? '');
    $name = clean_text($body['name'] ?? '', 18);
    $avatar = clean_text($body['avatar'] ?? '😀', 8);
    if (!preg_match('/^\d{11}$/', $phone)) {
        respond(422, ['ok' => false, 'error' => 'invalid_phone']);
    }
    if ($name === '') {
        respond(422, ['ok' => false, 'error' => 'empty_name']);
    }
    enforce_not_banned($phone, $name);
    enforce_safe_text_or_ban($phone, $name, $name);

    $usersFile = data_file('chat-users.json');
    return with_json_file_lock($usersFile, [], static function (array $users) use ($phone, $name, $avatar): array {
        $now = now_ms();
        $normalizedName = normalize_name($name);
        $index = null;
        foreach ($users as $key => $existing) {
            $existingName = (string) ($existing['normalizedName'] ?? normalize_name((string) ($existing['name'] ?? '')));
            if ($existingName === $normalizedName && ($existing['phone'] ?? '') !== $phone) {
                respond(409, ['ok' => false, 'error' => 'id_taken']);
            }
            if (($existing['phone'] ?? '') === $phone) {
                $index = $key;
            }
        }
        if ($index === null) {
            respond(404, ['ok' => false, 'error' => 'user_not_registered']);
        }

        $lastChangedAt = (int) ($users[$index]['profileChangedAt'] ?? 0);
        $waitMs = max(0, PROFILE_COOLDOWN_MS - ($now - $lastChangedAt));
        if ($waitMs > 0) {
            respond(429, ['ok' => false, 'error' => 'profile_cooldown', 'waitMs' => $waitMs]);
        }

        $users[$index]['name'] = $name;
        $users[$index]['normalizedName'] = $normalizedName;
        $users[$index]['avatar'] = $avatar ?: '😀';
        $users[$index]['updatedAt'] = $now;
        $users[$index]['profileChangedAt'] = $now;

        return ['data' => $users, 'payload' => ['ok' => true, 'user' => public_user($users[$index])]];
    });
}

function create_message(array $body): array {
    $phone = clean_phone($body['phone'] ?? '');
    $text = clean_text($body['text'] ?? '', 300);
    if (!preg_match('/^\d{11}$/', $phone)) {
        respond(422, ['ok' => false, 'error' => 'invalid_phone']);
    }
    if ($text === '') {
        respond(422, ['ok' => false, 'error' => 'empty_message']);
    }

    $user = find_user_by_phone(read_json_file(data_file('chat-users.json')), $phone);
    if ($user === null) {
        respond(404, ['ok' => false, 'error' => 'user_not_registered']);
    }
    $name = (string) ($user['name'] ?? '');
    enforce_not_banned($phone, $name);
    enforce_safe_text_or_ban($phone, $name, $text);

    $ipHash = client_ip_hash();
    $messagesFile = data_file('chat.json');
    return with_json_file_lock($messagesFile, [], static function (array $messages) use ($phone, $text, $user, $name, $ipHash): array {
        $messages = fresh_messages($messages);
        $now = now_ms();
        assert_chat_rate_limit($messages, $ipHash, $now, $text);

        $messages[] = [
            'id' => bin2hex(random_bytes(8)),
            'phone' => $phone,
            'name' => $name !== '' ? $name : '游客',
            'avatar' => (string) ($user['avatar'] ?? '😀'),
            'text' => $text,
            'createdAt' => $now,
            'ipHash' => $ipHash,
        ];
        $messages = array_slice($messages, -200);
        return ['data' => $messages, 'payload' => ['ok' => true, 'messages' => public_messages($messages)]];
    });
}

function image_extension_from_mime(string $mime): ?string {
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    return $allowed[$mime] ?? null;
}

function uploaded_image_mime(string $file): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $file);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }
    $info = @getimagesize($file);
    return is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';
}

function uploaded_image_dimensions(string $file): array {
    $info = @getimagesize($file);
    if (!is_array($info)) {
        respond(422, ['ok' => false, 'error' => 'invalid_image']);
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000) {
        respond(422, ['ok' => false, 'error' => 'invalid_image']);
    }
    return ['width' => $width, 'height' => $height];
}

function ratio_for_area(array $matrix, int $x1, int $y1, int $x2, int $y2, bool $black): float {
    $hits = 0;
    $total = 0;
    for ($y = max(0, $y1); $y < min(count($matrix), $y2); $y++) {
        for ($x = max(0, $x1); $x < min(count($matrix[$y] ?? []), $x2); $x++) {
            $total++;
            if (($matrix[$y][$x] ?? false) === $black) {
                $hits++;
            }
        }
    }
    return $total > 0 ? $hits / $total : 0.0;
}

function qr_finder_window_matches(array $matrix, int $x, int $y, int $size): bool {
    $module = max(2, (int) floor($size / 7));
    $outer = ratio_for_area($matrix, $x, $y, $x + $size, $y + $module, true);
    $outer = min($outer, ratio_for_area($matrix, $x, $y + $size - $module, $x + $size, $y + $size, true));
    $outer = min($outer, ratio_for_area($matrix, $x, $y, $x + $module, $y + $size, true));
    $outer = min($outer, ratio_for_area($matrix, $x + $size - $module, $y, $x + $size, $y + $size, true));

    $whiteTop = ratio_for_area($matrix, $x + $module, $y + $module, $x + $size - $module, $y + 2 * $module, false);
    $whiteBottom = ratio_for_area($matrix, $x + $module, $y + $size - 2 * $module, $x + $size - $module, $y + $size - $module, false);
    $whiteLeft = ratio_for_area($matrix, $x + $module, $y + $module, $x + 2 * $module, $y + $size - $module, false);
    $whiteRight = ratio_for_area($matrix, $x + $size - 2 * $module, $y + $module, $x + $size - $module, $y + $size - $module, false);
    $white = min($whiteTop, $whiteBottom, $whiteLeft, $whiteRight);

    $center = ratio_for_area($matrix, $x + 2 * $module, $y + 2 * $module, $x + $size - 2 * $module, $y + $size - 2 * $module, true);
    return $outer > 0.62 && $white > 0.58 && $center > 0.62;
}

function qr_finder_in_region(array $matrix, int $x1, int $y1, int $x2, int $y2): bool {
    for ($size = 14; $size <= 34; $size += 2) {
        for ($y = $y1; $y <= $y2 - $size; $y += 2) {
            for ($x = $x1; $x <= $x2 - $size; $x += 2) {
                if (qr_finder_window_matches($matrix, $x, $y, $size)) {
                    return true;
                }
            }
        }
    }
    return false;
}

function image_looks_like_qr(string $file, array $dimensions): bool {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
        return false;
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return false;
    }
    $source = @imagecreatefromstring($raw);
    if (!$source) {
        return false;
    }
    $sampleSize = 96;
    $sample = imagecreatetruecolor($sampleSize, $sampleSize);
    if (!$sample) {
        imagedestroy($source);
        return false;
    }
    imagecopyresampled($sample, $source, 0, 0, 0, 0, $sampleSize, $sampleSize, imagesx($source), imagesy($source));
    imagedestroy($source);

    $matrix = [];
    $blackCount = 0;
    for ($y = 0; $y < $sampleSize; $y++) {
        $row = [];
        for ($x = 0; $x < $sampleSize; $x++) {
            $rgb = imagecolorat($sample, $x, $y);
            $r = ($rgb >> 16) & 255;
            $g = ($rgb >> 8) & 255;
            $b = $rgb & 255;
            $brightness = (int) (($r * 299 + $g * 587 + $b * 114) / 1000);
            $isBlack = $brightness < 105;
            if ($isBlack) {
                $blackCount++;
            }
            $row[] = $isBlack;
        }
        $matrix[] = $row;
    }
    imagedestroy($sample);

    $blackRatio = $blackCount / ($sampleSize * $sampleSize);
    if ($blackRatio < 0.18 || $blackRatio > 0.72) {
        return false;
    }

    $topLeft = qr_finder_in_region($matrix, 0, 0, 44, 44);
    $topRight = qr_finder_in_region($matrix, 52, 0, 96, 44);
    $bottomLeft = qr_finder_in_region($matrix, 0, 52, 44, 96);
    return $topLeft && $topRight && $bottomLeft;
}

function create_image_message(): array {
    $phone = clean_phone($_POST['phone'] ?? '');
    $text = clean_text($_POST['text'] ?? '', 120);
    if (!preg_match('/^\d{11}$/', $phone)) {
        respond(422, ['ok' => false, 'error' => 'invalid_phone']);
    }
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        respond(422, ['ok' => false, 'error' => 'image_required']);
    }

    $image = $_FILES['image'];
    $error = (int) ($image['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        respond(422, ['ok' => false, 'error' => 'upload_failed']);
    }
    $size = (int) ($image['size'] ?? 0);
    $tmpName = (string) ($image['tmp_name'] ?? '');
    if ($size < 1 || $size > CHAT_IMAGE_MAX_BYTES || $tmpName === '' || !is_uploaded_file($tmpName)) {
        respond(422, ['ok' => false, 'error' => 'image_too_large']);
    }

    $mime = uploaded_image_mime($tmpName);
    $extension = image_extension_from_mime($mime);
    if ($extension === null) {
        respond(422, ['ok' => false, 'error' => 'invalid_image_type']);
    }
    $dimensions = uploaded_image_dimensions($tmpName);

    $user = find_user_by_phone(read_json_file(data_file('chat-users.json')), $phone);
    if ($user === null) {
        respond(404, ['ok' => false, 'error' => 'user_not_registered']);
    }
    $name = (string) ($user['name'] ?? '');
    enforce_not_banned($phone, $name);
    if ($text !== '') {
        enforce_safe_text_or_ban($phone, $name, $text);
    }
    if (image_looks_like_qr($tmpName, $dimensions)) {
        permanently_ban_user($phone, $name, 'qr_code');
        respond(403, ['ok' => false, 'error' => 'permanent_ban']);
    }

    $ipHash = client_ip_hash();
    $messagesFile = data_file('chat.json');
    $message = with_json_file_lock($messagesFile, [], static function (array $messages) use ($phone, $text, $user, $name, $ipHash, $mime, $size, $dimensions): array {
        $messages = fresh_messages($messages);
        $now = now_ms();
        assert_chat_rate_limit($messages, $ipHash, $now);
        return ['data' => $messages, 'payload' => [
            'id' => bin2hex(random_bytes(8)),
            'phone' => $phone,
            'name' => $name !== '' ? $name : '游客',
            'avatar' => (string) ($user['avatar'] ?? '😀'),
            'text' => $text,
            'imageMime' => $mime,
            'imageSize' => $size,
            'imageWidth' => $dimensions['width'],
            'imageHeight' => $dimensions['height'],
            'createdAt' => $now,
            'ipHash' => $ipHash,
        ]];
    });

    $uploadDir = site_path(CHAT_IMAGE_DIR);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        respond(500, ['ok' => false, 'error' => 'upload_directory_failed']);
    }

    $fileName = date('YmdHis') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $target = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $target)) {
        respond(500, ['ok' => false, 'error' => 'upload_save_failed']);
    }
    @chmod($target, 0664);

    $imagePath = public_path(CHAT_IMAGE_DIR . '/' . $fileName);
    return with_json_file_lock($messagesFile, [], static function (array $messages) use ($message, $imagePath, $target): array {
        $messages = fresh_messages($messages);
        assert_chat_rate_limit($messages, (string) $message['ipHash'], now_ms(), '', $target);
        $messages[] = [
            'id' => $message['id'],
            'phone' => $message['phone'],
            'name' => $message['name'],
            'avatar' => $message['avatar'],
            'text' => $message['text'],
            'image' => [
                'src' => $imagePath,
                'mime' => $message['imageMime'],
                'size' => $message['imageSize'],
                'width' => $message['imageWidth'],
                'height' => $message['imageHeight'],
            ],
            'createdAt' => $message['createdAt'],
            'ipHash' => $message['ipHash'],
        ];
        $messages = array_slice($messages, -200);
        return ['data' => $messages, 'payload' => ['ok' => true, 'messages' => public_messages($messages)]];
    });
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    respond(200, [
        'ok' => true,
        'messages' => public_messages(fresh_messages(read_json_file(data_file('chat.json')))),
        'settings' => public_settings(),
    ]);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'image') {
        respond(200, create_image_message());
    }
    respond(400, ['ok' => false, 'error' => 'unknown_action']);
}

$body = read_body();
$action = (string) ($body['action'] ?? (isset($body['text']) ? 'message' : ''));

if ($action === 'register') {
    respond(200, register_user($body));
}
if ($action === 'profile') {
    respond(200, update_profile($body));
}
if ($action === 'message') {
    respond(200, create_message($body));
}

respond(400, ['ok' => false, 'error' => 'unknown_action']);
