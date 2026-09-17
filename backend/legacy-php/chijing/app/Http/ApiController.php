<?php
declare(strict_types=1);

function api_user_payload(array $user): array
{
    return [
        'id' => $user['public_id'],
        'email' => $user['email'],
        'nickname' => $user['nickname'],
        'role' => $user['role'],
    ];
}

function api_register(): never
{
    verify_csrf();
    $data = request_data();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $nickname = trim((string)($data['nickname'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
        json_response(['ok' => false, 'error' => '请输入有效的邮箱地址。'], 422);
    }
    if (mb_strlen($nickname) < 2 || mb_strlen($nickname) > 64) {
        json_response(['ok' => false, 'error' => '昵称需要为 2 到 64 个字符。'], 422);
    }
    if (strlen($password) < 8 || strlen($password) > 128) {
        json_response(['ok' => false, 'error' => '密码需要为 8 到 128 个字符。'], 422);
    }
    $pdo = database();
    try {
        $stmt = $pdo->prepare('INSERT INTO users (public_id, email, password_hash, nickname) VALUES (?, ?, ?, ?)');
        $stmt->execute([app_public_id(), $email, password_hash($password, PASSWORD_DEFAULT), $nickname]);
        $userId = (int)$pdo->lastInsertId();
        start_web_session();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, target_type, ip_address) VALUES (?, 'auth.register', 'user', ?)")->execute([$userId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        json_response(['ok' => true, 'message' => '注册成功。', 'user' => api_user_payload(current_user() ?? [])], 201);
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            json_response(['ok' => false, 'error' => '该邮箱已经注册，请直接登录。'], 409);
        }
        throw $exception;
    }
}

function api_login(): never
{
    verify_csrf();
    $data = request_data();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    $stmt = database()->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        json_response(['ok' => false, 'error' => '邮箱或密码不正确。'], 401);
    }
    start_web_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf_token'] = base64url_random();
    database()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([(int)$user['id']]);
    database()->prepare("INSERT INTO audit_logs (user_id, action, target_type, ip_address) VALUES (?, 'auth.login', 'user', ?)")->execute([(int)$user['id'], substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    json_response(['ok' => true, 'message' => '登录成功。', 'user' => api_user_payload($user)]);
}

function api_logout(): never
{
    verify_csrf();
    $user = current_user();
    if ($user) {
        database()->prepare("INSERT INTO audit_logs (user_id, action, target_type, ip_address) VALUES (?, 'auth.logout', 'user', ?)")->execute([(int)$user['id'], substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    json_response(['ok' => true]);
}

function api_devices(array $user): never
{
    $stmt = database()->prepare('SELECT public_id, device_uid, display_name, status, firmware_version, last_seen_at, bound_at, created_at FROM devices WHERE owner_user_id = ? AND status <> \'retired\' ORDER BY bound_at DESC, id DESC');
    $stmt->execute([(int)$user['id']]);
    json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
}

function api_claim_device(array $user): never
{
    verify_csrf();
    $data = request_data();
    $uid = trim((string)($data['device_uid'] ?? ''));
    $activationCode = trim((string)($data['activation_code'] ?? ''));
    $displayName = trim((string)($data['display_name'] ?? '我的齿镜'));
    if ($uid === '' || mb_strlen($uid) > 96 || $activationCode === '') {
        json_response(['ok' => false, 'error' => '请填写设备编号和激活码。'], 422);
    }
    $pdo = database();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE device_uid = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$uid]);
        $device = $stmt->fetch();
        if (!$device || !$device['activation_code_hash'] || !hash_equals((string)$device['activation_code_hash'], hash('sha256', $activationCode))) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => '设备编号或激活码不正确。'], 422);
        }
        if ($device['owner_user_id'] !== null && (int)$device['owner_user_id'] !== (int)$user['id']) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => '该设备已绑定其他账户。'], 409);
        }
        $token = base64url_random(32);
        $stmt = $pdo->prepare("UPDATE devices SET owner_user_id = ?, display_name = ?, status = 'active', bound_at = NOW(), activation_code_hash = NULL WHERE id = ?");
        $stmt->execute([(int)$user['id'], mb_substr($displayName, 0, 64), (int)$device['id']]);
        $stmt = $pdo->prepare('INSERT INTO device_tokens (device_id, token_prefix, token_hash) VALUES (?, ?, ?)');
        $stmt->execute([(int)$device['id'], substr($token, 0, 12), hash('sha256', $token)]);
        $pdo->prepare("INSERT INTO audit_logs (user_id, device_id, action, target_type, target_public_id, ip_address) VALUES (?, ?, 'device.claim', 'device', ?, ?)")->execute([(int)$user['id'], (int)$device['id'], $device['public_id'], substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $pdo->commit();
        json_response(['ok' => true, 'message' => '设备绑定成功。请将令牌写入 ESP32 固件。', 'device_token' => $token, 'device_uid' => $device['device_uid']]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function api_unbind_device(array $user, string $devicePublicId): never
{
    verify_csrf();
    $pdo = database();
    $stmt = $pdo->prepare('SELECT id FROM devices WHERE public_id = ? AND owner_user_id = ? LIMIT 1');
    $stmt->execute([$devicePublicId, (int)$user['id']]);
    $device = $stmt->fetch();
    if (!$device) {
        json_response(['ok' => false, 'error' => '设备不存在。'], 404);
    }
    $activationCode = strtoupper(bin2hex(random_bytes(5)));
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE device_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL')->execute([(int)$device['id']]);
        $pdo->prepare("UPDATE devices SET owner_user_id = NULL, status = 'unbound', bound_at = NULL, activation_code_hash = ? WHERE id = ?")->execute([hash('sha256', $activationCode), (int)$device['id']]);
        $pdo->prepare("INSERT INTO audit_logs (user_id, device_id, action, target_type, target_public_id, ip_address) VALUES (?, ?, 'device.unbind', 'device', ?, ?)")->execute([(int)$user['id'], (int)$device['id'], $devicePublicId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    json_response(['ok' => true, 'activation_code' => $activationCode]);
}

function api_detection_list(array $user): never
{
    $limit = min(max((int)($_GET['limit'] ?? 30), 1), 100);
    $stmt = database()->prepare("SELECT d.public_id, d.status, d.source, d.captured_at, d.completed_at, d.failure_code, d.failure_message, di.id AS image_id, ar.report_json, ar.status AS report_status FROM detections d LEFT JOIN detection_images di ON di.detection_id = d.id AND di.image_kind = 'original' LEFT JOIN ai_reports ar ON ar.detection_id = d.id WHERE d.user_id = ? ORDER BY d.created_at DESC LIMIT {$limit}");
    $stmt->execute([(int)$user['id']]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['has_image'] = $item['image_id'] !== null;
        unset($item['image_id']);
        $item['report'] = $item['report_json'] ? json_decode((string)$item['report_json'], true) : null;
        unset($item['report_json']);
    }
    json_response(['ok' => true, 'items' => $items]);
}

function api_detection_detail(array $user, string $publicId): never
{
    $stmt = database()->prepare("SELECT d.*, dev.display_name, dev.device_uid, ar.report_json, ar.status AS report_status, ar.provider, ar.model_name FROM detections d LEFT JOIN devices dev ON dev.id = d.device_id LEFT JOIN ai_reports ar ON ar.detection_id = d.id WHERE d.public_id = ? AND d.user_id = ? LIMIT 1");
    $stmt->execute([$publicId, (int)$user['id']]);
    $item = $stmt->fetch();
    if (!$item) {
        json_response(['ok' => false, 'error' => '检测记录不存在。'], 404);
    }
    $images = database()->prepare('SELECT image_kind, width, height, byte_size, created_at FROM detection_images WHERE detection_id = ? ORDER BY id');
    $images->execute([(int)$item['id']]);
    $item['images'] = $images->fetchAll();
    $item['report'] = $item['report_json'] ? json_decode((string)$item['report_json'], true) : null;
    unset($item['id'], $item['user_id'], $item['report_json']);
    json_response(['ok' => true, 'item' => $item]);
}

function api_detection_image(array $user, string $publicId): never
{
    $kind = (string)($_GET['kind'] ?? 'original');
    if (!in_array($kind, ['original', 'normalized', 'annotated', 'thumbnail'], true)) {
        json_response(['ok' => false, 'error' => '未知图片类型。'], 422);
    }
    $stmt = database()->prepare('SELECT di.storage_key, di.mime_type FROM detections d INNER JOIN detection_images di ON di.detection_id = d.id WHERE d.public_id = ? AND d.user_id = ? AND di.image_kind = ? LIMIT 1');
    $stmt->execute([$publicId, (int)$user['id'], $kind]);
    $image = $stmt->fetch();
    if (!$image || !is_string($image['storage_key']) || str_contains($image['storage_key'], '..')) {
        json_response(['ok' => false, 'error' => '图片不存在。'], 404);
    }
    $path = storage_path($image['storage_key']);
    if (!is_file($path)) {
        json_response(['ok' => false, 'error' => '图片文件不存在。'], 404);
    }
    header('Content-Type: ' . $image['mime_type']);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function dispatch_api(string $method, string $path): never
{
    if ($method === 'GET' && $path === '/api/v1/health') {
        json_response(['ok' => true, 'service' => 'chijing', 'time' => date(DATE_ATOM)]);
    }
    if ($method === 'POST' && $path === '/api/v1/auth/register') api_register();
    if ($method === 'POST' && $path === '/api/v1/auth/login') api_login();
    if ($method === 'POST' && $path === '/api/v1/auth/logout') api_logout();
    if ($method === 'GET' && $path === '/api/v1/me') {
        $user = require_user();
        json_response(['ok' => true, 'user' => api_user_payload($user), 'csrf_token' => csrf_token()]);
    }

    $user = require_user();
    if ($method === 'GET' && $path === '/api/v1/devices') api_devices($user);
    if ($method === 'POST' && $path === '/api/v1/devices/claim') api_claim_device($user);
    if ($method === 'GET' && $path === '/api/v1/detections') api_detection_list($user);
    if (preg_match('#^/api/v1/detections/([0-9A-HJKMNP-TV-Z]{26})/image$#', $path, $matches) && $method === 'GET') api_detection_image($user, $matches[1]);
    if (preg_match('#^/api/v1/detections/([0-9A-HJKMNP-TV-Z]{26})$#', $path, $matches) && $method === 'GET') api_detection_detail($user, $matches[1]);
    if (preg_match('#^/api/v1/devices/([0-9A-HJKMNP-TV-Z]{26})/unbind$#', $path, $matches) && $method === 'POST') api_unbind_device($user, $matches[1]);
    json_response(['ok' => false, 'error' => '接口不存在。'], 404);
}
