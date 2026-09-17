<?php
declare(strict_types=1);

function authenticate_upload_device(PDO $pdo): array|false
{
    $authorization = request_header('Authorization') ?? '';
    $token = request_header('X-Device-Token');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        $token = trim($matches[1]);
    }

    if (is_string($token) && $token !== '') {
        $stmt = $pdo->prepare('SELECT d.id, d.public_id, d.device_uid, d.owner_user_id, d.status, dt.id AS token_id FROM device_tokens dt INNER JOIN devices d ON d.id = dt.device_id WHERE dt.token_hash = ? AND dt.revoked_at IS NULL AND (dt.expires_at IS NULL OR dt.expires_at > NOW()) LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $device = $stmt->fetch();
        if ($device && $device['status'] === 'active' && $device['owner_user_id'] !== null) {
            $pdo->prepare('UPDATE device_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$device['token_id']]);
            return $device;
        }
    }

    // 临时兼容已烧录的旧固件：只接受数据库中已绑定的设备 UID。
    if (env_bool('ALLOW_LEGACY_UNAUTHENTICATED_UPLOAD')) {
        $deviceUid = request_header('X-Device');
        if (is_string($deviceUid) && $deviceUid !== '') {
            $stmt = $pdo->prepare("SELECT id, public_id, device_uid, owner_user_id, status FROM devices WHERE device_uid = ? AND status = 'active' AND owner_user_id IS NOT NULL LIMIT 1");
            $stmt->execute([substr($deviceUid, 0, 96)]);
            $device = $stmt->fetch();
            if ($device) {
                return $device;
            }
        }
    }
    return false;
}

function extract_upload_bytes(): string
{
    $maxBytes = max(1, (int)(env('UPLOAD_MAX_MB', '10') ?? '10')) * 1024 * 1024;
    foreach (['file', 'photo'] as $field) {
        if (isset($_FILES[$field]) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
            if ((int)$_FILES[$field]['size'] > $maxBytes) {
                json_response(['ok' => false, 'error' => '图片超过上传大小限制。'], 413);
            }
            return (string) file_get_contents($_FILES[$field]['tmp_name']);
        }
    }
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) {
        json_response(['ok' => false, 'error' => '图片超过上传大小限制。'], 413);
    }
    return (string) file_get_contents('php://input');
}

function handle_device_upload(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_response(['ok' => false, 'error' => '仅支持 POST 上传。'], 405);
    }
    $pdo = database();
    $device = authenticate_upload_device($pdo);
    if (!$device) {
        json_response(['ok' => false, 'error' => '设备未授权。请绑定设备并提供 X-Device-Token。'], 401);
    }

    $bytes = extract_upload_bytes();
    $image = is_valid_jpeg($bytes);
    if ($bytes === '' || $image === false) {
        json_response(['ok' => false, 'error' => '仅支持有效的 JPEG 图片。'], 415);
    }

    $requestId = request_header('X-Request-Id');
    $requestId = is_string($requestId) && $requestId !== '' ? substr($requestId, 0, 96) : null;
    if ($requestId !== null) {
        $stmt = $pdo->prepare('SELECT public_id, status FROM detections WHERE device_id = ? AND client_request_id = ? LIMIT 1');
        $stmt->execute([(int)$device['id'], $requestId]);
        $existing = $stmt->fetch();
        if ($existing) {
            json_response(['ok' => true, 'duplicate' => true, 'detection_id' => $existing['public_id'], 'status' => $existing['status']], 200);
        }
    }

    $publicId = app_public_id();
    $datePath = date('Y/m/d');
    $relative = "uploads/{$datePath}/{$publicId}/original.jpg";
    $path = storage_path($relative);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        json_response(['ok' => false, 'error' => '服务器无法创建图片目录。'], 500);
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        json_response(['ok' => false, 'error' => '服务器无法保存图片。'], 500);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO detections (public_id, user_id, device_id, status, source, client_request_id, captured_at) VALUES (?, ?, ?, \'queued\', \'device\', ?, NOW())');
        $stmt->execute([$publicId, (int)$device['owner_user_id'], (int)$device['id'], $requestId]);
        $detectionId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO detection_images (detection_id, image_kind, storage_key, mime_type, byte_size, width, height, sha256) VALUES (?, \'original\', ?, \'image/jpeg\', ?, ?, ?, ?)');
        $stmt->execute([$detectionId, $relative, strlen($bytes), $image['width'], $image['height'], hash('sha256', $bytes)]);
        $pdo->prepare('UPDATE devices SET last_seen_at = NOW() WHERE id = ?')->execute([(int)$device['id']]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, device_id, action, target_type, target_public_id, ip_address) VALUES (?, ?, \'device.upload\', \'detection\', ?, ?)')->execute([(int)$device['owner_user_id'], (int)$device['id'], $publicId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($path);
        error_log('Detection upload database failure: ' . $exception->getMessage());
        json_response(['ok' => false, 'error' => '检测任务创建失败。'], 500);
    }

    json_response([
        'ok' => true,
        'detection_id' => $publicId,
        'status' => 'queued',
        'width' => $image['width'],
        'height' => $image['height'],
        'bytes' => strlen($bytes),
        'message' => '图片已接收，等待模型分析。',
    ], 201);
}
