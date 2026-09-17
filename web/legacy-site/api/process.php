<?php
declare(strict_types=1);
require_once __DIR__ . '/reference_library_common.php';
// 公开的无敏感信息部署标记，用于确认 Nginx/PHP 实际执行的是“保持设备方向”版本。
header('X-Chijing-Upload-Build: 20260812-preserve-device-v2');
header('X-Chijing-Image-Orientation: preserve-device');
$uploadRequestId = substr((string)(header_value('X-Request-Id') ?? ''), 0, 64);
if ($uploadRequestId === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $uploadRequestId) !== 1) $uploadRequestId = 'up_' . bin2hex(random_bytes(6));
header('X-Chijing-Request-Id: ' . $uploadRequestId);
function upload_error_response(string $error, int $status, string $errorCode, bool $retryable): void {
  global $uploadRequestId;
  json_response(['ok'=>false,'error'=>$error,'error_code'=>$errorCode,'retryable'=>$retryable,'request_id'=>$uploadRequestId],$status);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') upload_error_response('POST required', 405, 'method_not_allowed', false);

try {
  ensure_storage();
} catch (Throwable $e) {
  error_log('upload '.$uploadRequestId.' storage initialization failed: ' . $e->getMessage());
  upload_error_response('图片存储服务暂不可用。',503,'storage_unavailable',true);
}
$pdo = db();
$uploadMode = strtolower(trim((string)(header_value('X-Upload-Mode') ?? ($_POST['upload_mode'] ?? 'detect'))));
if (!in_array($uploadMode, ['archive', 'detect'], true)) json_response(['ok' => false, 'error' => 'upload_mode must be archive or detect'], 422);
$modelPipeline = strtolower(trim((string)(header_value('X-Model-Pipeline') ?? ($_POST['model_pipeline'] ?? 'caries'))));
if (!in_array($modelPipeline, ['caries', 'both', 'dental_seg', 'calculus_seg', 'tooth_outline', 'all_models'], true)) json_response(['ok' => false, 'error' => 'model_pipeline must be caries, both, dental_seg, calculus_seg, tooth_outline or all_models'], 422);
$token = header_value('X-Device-Token');
$auth = header_value('Authorization') ?? '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) $token = trim($matches[1]);
if ($token) {
  $s = $pdo->prepare('SELECT * FROM devices WHERE token_hash=? LIMIT 1');
  $s->execute([hash('sha256', $token)]);
  $device = $s->fetch();
} else {
  // 与原已验证固件兼容：旧固件只发送 X-Device 时也可在快速验证阶段上传。
  $uid = header_value('X-Device');
  $device = false;
  if ($uid) {
    $s = $pdo->prepare('SELECT * FROM devices WHERE device_uid=? LIMIT 1');
    $s->execute([substr($uid, 0, 96)]);
    $device = $s->fetch();
  }
}
if (!$device) upload_error_response('设备未绑定或令牌无效。',401,'device_unauthorized',false);

// ESP32 上传原始 JPEG 时使用 X-Member-Id；multipart 上传也可传 member_id 字段。
$memberPublicId = trim((string)(header_value('X-Member-Id') ?? ($_POST['member_id'] ?? '')));
if ($memberPublicId !== '') {
  $s = $pdo->prepare("SELECT id,public_id FROM family_members WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");
  $s->execute([mb_substr($memberPublicId, 0, 32), (int)$device['user_id']]);
  $member = $s->fetch();
  if (!$member) upload_error_response('成员不存在、已删除或不属于当前账户。',422,'member_invalid',false);
} else {
  // 未升级的设备默认归入网页端设置的第一位成员，保证旧固件还能上传。
  $s = $pdo->prepare("SELECT id,public_id FROM family_members WHERE user_id=? AND status='active' ORDER BY is_default DESC,id ASC LIMIT 1");
  $s->execute([(int)$device['user_id']]);
  $member = $s->fetch();
  if (!$member) upload_error_response('当前账户还没有可用成员，请先在网页端创建成员。',409,'member_missing',false);
}

$payload = '';
foreach (['file', 'photo'] as $field) {
  if (isset($_FILES[$field]) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
    $payload = (string)file_get_contents($_FILES[$field]['tmp_name']);
    break;
  }
}
if ($payload === '') {
  $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($length > MAX_UPLOAD_BYTES) json_response(['ok' => false, 'error' => '图片过大。'], 413);
  $payload = (string)file_get_contents('php://input');
}
if ($payload === '' || strlen($payload) > MAX_UPLOAD_BYTES) json_response(['ok' => false, 'error' => '空图片或图片过大。'], 400);
$info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($payload) : null;
if (!is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') json_response(['ok' => false, 'error' => '仅支持 JPEG 图片。'], 415);
// ESP32-P4 屏幕显示的左右方向就是规范方向。服务器保存设备上传的原始 JPEG，
// 不再做水平翻转或二次 JPEG 编码，确保网页与设备显示完全一致。

$publicId = public_id();
$date = date('Ymd');
$dir = storage_history_dir() . '/u' . (int)$device['user_id'] . '/m' . (int)$member['id'] . '/' . $date;
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) json_response(['ok' => false, 'error' => '不能创建图片目录。'], 500);
$filename = 'photo_' . $publicId . '.jpg';
$path = $dir . '/' . $filename;
$tmp = $path . '.tmp';
if (@file_put_contents($tmp, $payload, LOCK_EX) === false || !@rename($tmp, $path)) {
  @unlink($tmp);
  json_response(['ok' => false, 'error' => '保存图片失败。'], 500);
}

$relative = 'history/u' . (int)$device['user_id'] . '/m' . (int)$member['id'] . '/' . $date . '/' . $filename;
$status = $uploadMode === 'archive' ? 'saved' : 'received';
$message = $uploadMode === 'archive' ? '图片已保存，可在网页端选择云端分析。' : '图片已接收，等待模型服务处理。';
try {
  $pdo->beginTransaction();
  $s = $pdo->prepare("INSERT INTO detections(public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,upload_mode,model_pipeline,status,report_text) VALUES(?,?,?,?,?,?,?,?,0,'none',1,?,?,?,?)");
  $s->execute([$publicId, (int)$device['user_id'], (int)$device['id'], (int)$member['id'], $relative, (int)$info[0], (int)$info[1], strlen($payload), $uploadMode, $modelPipeline, $status, $message]);
  $pdo->prepare('UPDATE devices SET last_seen_at=NOW() WHERE id=?')->execute([(int)$device['id']]);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  @unlink($path);
  error_log('upload '.$uploadRequestId.' database insert failed: '.$e->getMessage());
  upload_error_response('创建检测记录失败。',503,'database_write_failed',true);
}
json_response(['ok' => true, 'request_id'=>$uploadRequestId,'detection_id' => $publicId, 'member_id' => $member['public_id'], 'upload_mode' => $uploadMode, 'model_pipeline' => $modelPipeline, 'status' => $status, 'width' => (int)$info[0], 'height' => (int)$info[1], 'bytes' => strlen($payload), 'orientation_normalized' => true, 'normalization_applied' => 'none', 'message' => $message], 201);
