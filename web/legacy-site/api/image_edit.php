<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$user = require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$selectedPublicId = mb_substr(trim((string)($_GET['id'] ?? $_POST['public_id'] ?? '')), 0, 32);
if ($selectedPublicId === '') json_response(['ok' => false, 'error' => '缺少图片记录 ID。'], 422);

function editable_image_record(PDO $pdo, int $userId, string $publicId, bool $lock = false): ?array {
  $suffix = $lock ? ' FOR UPDATE' : '';
  $statement = $pdo->prepare(
    'SELECT d.id,d.public_id,d.user_id,d.device_id,d.member_id,d.image_path,d.image_width,d.image_height,d.image_bytes,
            d.upload_mode,d.model_pipeline,d.status,d.source_detection_id,d.result_json,d.created_at,d.updated_at,
            COALESCE(m.name,\'未归属成员\') AS member_name
       FROM detections d
       LEFT JOIN family_members m ON m.id=d.member_id
      WHERE d.public_id=? AND d.user_id=? LIMIT 1' . $suffix
  );
  $statement->execute([$publicId, $userId]);
  $selected = $statement->fetch();
  if (!$selected) return null;
  if ($selected['source_detection_id'] === null) {
    $selected['selected_public_id'] = $selected['public_id'];
    return $selected;
  }
  $source = $pdo->prepare(
    'SELECT d.id,d.public_id,d.user_id,d.device_id,d.member_id,d.image_path,d.image_width,d.image_height,d.image_bytes,
            d.upload_mode,d.model_pipeline,d.status,d.source_detection_id,d.result_json,d.created_at,d.updated_at,
            COALESCE(m.name,\'未归属成员\') AS member_name
       FROM detections d
       LEFT JOIN family_members m ON m.id=d.member_id
      WHERE d.id=? AND d.user_id=? LIMIT 1' . $suffix
  );
  $source->execute([(int)$selected['source_detection_id'], $userId]);
  $editable = $source->fetch() ?: $selected;
  $editable['selected_public_id'] = $selected['public_id'];
  return $editable;
}

$pdo = db();
if ($method === 'GET') {
  $image = editable_image_record($pdo, (int)$user['id'], $selectedPublicId);
  if (!$image || !image_file_path((string)$image['image_path'])) {
    json_response(['ok' => false, 'error' => '图片不存在或无权编辑。'], 404);
  }
  $analysis = $pdo->prepare(
    'SELECT COUNT(*) FROM detections WHERE user_id=? AND (source_detection_id=? OR (id=? AND result_json IS NOT NULL))'
  );
  $analysis->execute([(int)$user['id'], (int)$image['id'], (int)$image['id']]);
  json_response([
    'ok' => true,
    'image' => [
      'public_id' => (string)$image['public_id'],
      'selected_public_id' => (string)$image['selected_public_id'],
      'member_name' => (string)$image['member_name'],
      'width' => (int)$image['image_width'],
      'height' => (int)$image['image_height'],
      'bytes' => (int)$image['image_bytes'],
      'created_at' => (string)$image['created_at'],
      'updated_at' => (string)$image['updated_at'],
      'analysis_count' => (int)$analysis->fetchColumn(),
      'resolved_from_analysis' => (string)$image['selected_public_id'] !== (string)$image['public_id'],
      'url' => 'api/image.php?id=' . rawurlencode((string)$image['public_id']) . '&v=' . rawurlencode((string)$image['updated_at']),
    ],
  ]);
}

if ($method !== 'POST') json_response(['ok' => false, 'error' => '仅支持 GET 或 POST。'], 405);
require_csrf();
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
  json_response(['ok' => false, 'error' => '没有收到编辑后的图片。'], 422);
}
$file = $_FILES['file'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
  json_response(['ok' => false, 'error' => '编辑后的图片上传失败，错误码：' . $uploadError], 422);
}
$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) json_response(['ok' => false, 'error' => '编辑文件校验失败。'], 400);
$bytes = (int)($file['size'] ?? 0);
if ($bytes < 1 || $bytes > 16 * 1024 * 1024) {
  json_response(['ok' => false, 'error' => '编辑后的图片为空或超过 16 MB。'], $bytes > 0 ? 413 : 422);
}
$info = function_exists('getimagesize') ? @getimagesize($tmp) : false;
$mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensions[$mime])) json_response(['ok' => false, 'error' => '编辑结果仅支持 JPEG、PNG 或 WebP。'], 415);
$width = (int)($info[0] ?? 0);
$height = (int)($info[1] ?? 0);
if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > 60000000) {
  json_response(['ok' => false, 'error' => '编辑结果分辨率无效或过大。'], 422);
}
$operationsRaw = trim((string)($_POST['operations'] ?? '{}'));
if (strlen($operationsRaw) > 8000) json_response(['ok' => false, 'error' => '图片编辑参数过长。'], 422);
$operations = json_decode($operationsRaw, true);
if (!is_array($operations)) json_response(['ok' => false, 'error' => '图片编辑参数格式不正确。'], 422);
$operationsJson = json_encode($operations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
  ensure_storage();
  $pdo->beginTransaction();
  $image = editable_image_record($pdo, (int)$user['id'], $selectedPublicId, true);
  if (!$image) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => '图片不存在或无权编辑。'], 404);
  }
  $oldPath = (string)$image['image_path'];
  $memberDirectory = $image['member_id'] === null ? 'm0' : 'm' . (int)$image['member_id'];
  $date = date('Ymd');
  $directory = storage_history_dir() . '/u' . (int)$user['id'] . '/' . $memberDirectory . '/edits/' . $date;
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('不能创建图片修订目录。');
  }
  $filename = 'photo_' . (string)$image['public_id'] . '_edit_' . date('His') . '_' . bin2hex(random_bytes(3)) . '.' . $extensions[$mime];
  $absolutePath = $directory . '/' . $filename;
  $temporaryPath = $absolutePath . '.uploading';
  if (!@move_uploaded_file($tmp, $temporaryPath) || !@rename($temporaryPath, $absolutePath)) {
    @unlink($temporaryPath);
    throw new RuntimeException('保存编辑结果失败。');
  }
  @chmod($absolutePath, 0664);
  $relativePath = 'history/u' . (int)$user['id'] . '/' . $memberDirectory . '/edits/' . $date . '/' . $filename;
  $hadInlineResult = $image['result_json'] !== null;
  $update = $pdo->prepare(
    "UPDATE detections
        SET image_path=?,image_width=?,image_height=?,image_bytes=?,upload_mode='archive',status='saved',
            progress_step=0,progress_total=0,progress_label=NULL,result_json=NULL,
            report_text='图片已编辑；如需模型结果，请重新发起分析。',updated_at=NOW()
      WHERE id=? AND user_id=?"
  );
  $update->execute([$relativePath, $width, $height, $bytes, (int)$image['id'], (int)$user['id']]);
  $pdo->prepare('DELETE FROM detection_results WHERE detection_id=?')->execute([(int)$image['id']]);

  try {
    $stale = $pdo->prepare('SELECT DISTINCT job.id FROM dental_arch_jobs job INNER JOIN dental_arch_job_images link ON link.job_id=job.id WHERE link.source_detection_id=? AND job.user_id=?');
    $stale->execute([(int)$image['id'], (int)$user['id']]);
    $jobIds = array_map('intval', $stale->fetchAll(PDO::FETCH_COLUMN));
    if ($jobIds) {
      $marks = implode(',', array_fill(0, count($jobIds), '?'));
      $pdo->prepare("UPDATE dental_arch_versions SET status='stale',is_current=0 WHERE job_id IN ({$marks})")->execute($jobIds);
      $pdo->prepare("UPDATE dental_arch_jobs SET status='stale',progress_label='来源照片已编辑，请基于当前七图重新生成。',error_message='source_image_edited' WHERE id IN ({$marks})")->execute($jobIds);
    }
  } catch (Throwable $ignored) {
    // 牙列功能尚未部署时不影响图片编辑。
  }

  try {
    $history = $pdo->prepare('INSERT INTO image_edit_history(public_id,user_id,detection_id,old_image_path,new_image_path,image_width,image_height,image_bytes,operations_json) VALUES(?,?,?,?,?,?,?,?,?)');
    $history->execute([public_id(), (int)$user['id'], (int)$image['id'], $oldPath, $relativePath, $width, $height, $bytes, $operationsJson]);
  } catch (Throwable $auditError) {
    error_log('image edit audit unavailable: ' . $auditError->getMessage());
  }
  $pdo->commit();
  json_response([
    'ok' => true,
    'public_id' => (string)$image['public_id'],
    'selected_public_id' => (string)$image['selected_public_id'],
    'width' => $width,
    'height' => $height,
    'bytes' => $bytes,
    'old_file_retained' => true,
    'inline_model_result_invalidated' => $hadInlineResult,
    'message' => $hadInlineResult
      ? '图片修改已保存。原图片内嵌模型结果已失效，请重新分析。'
      : '图片修改已保存。',
  ]);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (isset($absolutePath) && is_file($absolutePath)) @unlink($absolutePath);
  error_log('image edit failed: ' . $error->getMessage());
  json_response(['ok' => false, 'error' => '保存图片修改失败，请检查图片存储目录权限和数据库迁移。'], 500);
}
