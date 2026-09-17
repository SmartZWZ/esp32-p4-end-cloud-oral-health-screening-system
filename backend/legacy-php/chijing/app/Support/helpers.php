<?php
declare(strict_types=1);

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function request_header(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$key] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
}

function app_public_id(): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $time = (int) floor(microtime(true) * 1000);
    $output = '';
    for ($i = 0; $i < 10; $i++) {
        $output = $alphabet[$time & 31] . $output;
        $time >>= 5;
    }
    $bytes = random_bytes(10);
    for ($i = 0; $i < 16; $i++) {
        $output .= $alphabet[ord($bytes[intdiv($i * 5, 8)]) >> (7 - (($i * 5) % 8)) & 31];
    }
    return $output;
}

function base64url_random(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function csrf_token(): string
{
    start_web_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = base64url_random();
    }
    return (string) $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_web_session();
    $token = request_header('X-CSRF-Token') ?? ($_POST['csrf_token'] ?? null);
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $token)) {
        json_response(['ok' => false, 'error' => '请求已过期，请刷新页面后重试。'], 419);
    }
}

function current_user(): ?array
{
    start_web_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !ctype_digit((string)$id)) {
        return null;
    }
    $stmt = database()->prepare('SELECT id, public_id, email, nickname, role, status FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([(int)$id]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['ok' => false, 'error' => '请先登录。'], 401);
    }
    return $user;
}

function storage_path(string $relative = ''): string
{
    $base = BASE_PATH . '/storage';
    return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
}

function is_valid_jpeg(string $bytes): array|false
{
    if (strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
        return false;
    }
    if (!function_exists('getimagesizefromstring')) {
        return ['width' => null, 'height' => null, 'mime' => 'image/jpeg'];
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') {
        return false;
    }
    return ['width' => (int)$info[0], 'height' => (int)$info[1], 'mime' => 'image/jpeg'];
}
