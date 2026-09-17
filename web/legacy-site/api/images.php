<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok' => false, 'error' => 'POST required'], 405);
$user = require_user();
require_csrf();
$action = (string)($_GET['action'] ?? '');
if (!in_array($action, ['upload', 'analyze', 'delete'], true)) json_response(['ok' => false, 'error' => 'Unsupported action'], 404);

function web_upload_error(int $code): string {
  return match ($code) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片超过服务器允许的上传大小。',
    UPLOAD_ERR_PARTIAL => '图片只上传了一部分，请重新选择后再试。',
    UPLOAD_ERR_NO_FILE => '请选择一张本地图片。',
    UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时上传目录。',
    UPLOAD_ERR_CANT_WRITE => '服务器无法写入上传文件。',
    UPLOAD_ERR_EXTENSION => '服务器扩展中止了本次上传。',
    default => '图片上传失败。',
  };
}

function create_web_image(array $user): void {
  try {
    ensure_storage();
  } catch (Throwable $e) {
    error_log('web image storage initialization failed: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => '图片存储服务暂不可用。'], 500);
  }

  if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['ok' => false, 'error' => '请选择一张本地图片。'], 422);
  }
  $file = $_FILES['file'];
  $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($uploadError !== UPLOAD_ERR_OK) json_response(['ok' => false, 'error' => web_upload_error($uploadError)], 422);
  $tmpName = (string)($file['tmp_name'] ?? '');
  if ($tmpName === '' || !is_uploaded_file($tmpName)) json_response(['ok' => false, 'error' => '上传文件校验失败，请重新选择。'], 400);

  $bytes = (int)($file['size'] ?? 0);
  if ($bytes <= 0 || $bytes > MAX_UPLOAD_BYTES) {
    json_response(['ok' => false, 'error' => '图片为空或超过 8 MB。'], $bytes > MAX_UPLOAD_BYTES ? 413 : 422);
  }
  $info = function_exists('getimagesize') ? @getimagesize($tmpName) : false;
  $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
  $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  if (!isset($extensions[$mime])) json_response(['ok' => false, 'error' => '仅支持 JPEG、PNG 或 WebP 图片。'], 415);
  $width = (int)($info[0] ?? 0);
  $height = (int)($info[1] ?? 0);
  if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ($width * $height) > 60000000) {
    json_response(['ok' => false, 'error' => '图片分辨率无效或过大。'], 422);
  }

  $memberPublicId = mb_substr(trim((string)($_POST['member_id'] ?? '')), 0, 32);
  if ($memberPublicId === '') json_response(['ok' => false, 'error' => '请选择图片所属的家庭成员。'], 422);
  $uploadMode = strtolower(trim((string)($_POST['upload_mode'] ?? 'archive')));
  if (!in_array($uploadMode, ['archive', 'detect'], true)) json_response(['ok' => false, 'error' => '上传方式不正确。'], 422);
  $pipeline = strtolower(trim((string)($_POST['model_pipeline'] ?? 'dental_seg')));
  if (!in_array($pipeline, ['caries', 'both', 'dental_seg', 'calculus_seg', 'tooth_outline', 'all_models'], true)) json_response(['ok' => false, 'error' => '模型选择参数不正确。'], 422);

  $pdo = db();
  $s = $pdo->prepare("SELECT id,public_id FROM family_members WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");
  $s->execute([$memberPublicId, (int)$user['id']]);
  $member = $s->fetch();
  if (!$member) json_response(['ok' => false, 'error' => '成员不存在、已删除或不属于当前账号。'], 422);

  $publicId = public_id();
  $date = date('Ymd');
  $directory = storage_history_dir() . '/u' . (int)$user['id'] . '/m' . (int)$member['id'] . '/' . $date;
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
    json_response(['ok' => false, 'error' => '不能创建图片存储目录。'], 500);
  }
  $filename = 'photo_' . $publicId . '.' . $extensions[$mime];
  $path = $directory . '/' . $filename;
  $temporaryPath = $path . '.uploading';
  if (!@move_uploaded_file($tmpName, $temporaryPath) || !@rename($temporaryPath, $path)) {
    @unlink($temporaryPath);
    json_response(['ok' => false, 'error' => '保存图片失败。'], 500);
  }
  @chmod($path, 0664);

  $relativePath = 'history/u' . (int)$user['id'] . '/m' . (int)$member['id'] . '/' . $date . '/' . $filename;
  $status = $uploadMode === 'detect' ? 'received' : 'saved';
  $message = $uploadMode === 'detect'
    ? '本地图片已上传，等待模型工作端处理。'
    : '本地图片已保存，可随时在模型工作台发起分析。';
  try {
    $pdo->beginTransaction();
    $s = $pdo->prepare("INSERT INTO detections(public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,upload_mode,model_pipeline,status,report_text) VALUES(?,?,NULL,?,?,?,?,?,0,'none',1,?,?,?,?)");
    $s->execute([
      $publicId, (int)$user['id'], (int)$member['id'], $relativePath, $width, $height, $bytes,
      $uploadMode, $pipeline, $status, $message,
    ]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($path);
    error_log('web image database insert failed: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => '创建图片记录失败，请确认已执行网页上传数据库迁移。'], 500);
  }

  json_response([
    'ok' => true,
    'detection_id' => $publicId,
    'member_id' => (string)$member['public_id'],
    'source_type' => 'web',
    'upload_mode' => $uploadMode,
    'model_pipeline' => $pipeline,
    'status' => $status,
    'width' => $width,
    'height' => $height,
    'bytes' => $bytes,
    'message' => $message,
  ], 201);
}

if ($action === 'upload') create_web_image($user);

$data = request_data();
$publicId = mb_substr(trim((string)($data['public_id'] ?? '')), 0, 32);
$pipeline = strtolower(trim((string)($data['pipeline'] ?? 'caries')));
if ($publicId === '') json_response(['ok' => false, 'error' => '缺少图片记录 ID。'], 422);
if (!in_array($pipeline, ['caries', 'both', 'dental_seg', 'calculus_seg', 'tooth_outline', 'all_models'], true)) json_response(['ok' => false, 'error' => '模型选择参数不正确。'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
  $s = $pdo->prepare('SELECT id,public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,status,upload_mode,capture_session_id,capture_region_id FROM detections WHERE public_id=? AND user_id=? LIMIT 1 FOR UPDATE');
  $s->execute([$publicId, (int)$user['id']]);
  $image = $s->fetch();
  if (!$image) {
    $pdo->rollBack();
    json_response(['ok' => false, 'error' => '图片记录不存在或无权操作。'], 404);
  }

  if ($action === 'analyze') {
    if (!image_file_path((string)$image['image_path'])) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => '图片文件不存在，无法发起分析。'], 409);
    }
    if (in_array((string)$image['status'], ['received', 'processing'], true)) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => '该图片已在云端分析队列中。'], 409);
    }
    $analysisPublicId = public_id();
    $insert = $pdo->prepare(
      "INSERT INTO detections(
        public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,
        source_mirrored,normalization_applied,orientation_normalized,
        upload_mode,model_pipeline,status,source_detection_id,progress_step,progress_total,progress_label,report_text
      ) VALUES(?,?,?,?,?,?,?,?,?,?,?,'detect',?,'received',?,0,?,?,'已加入云端模型分析队列。')"
    );
    $total = $pipeline === 'all_models' ? 5 : 1;
    $label = $pipeline === 'all_models' ? '等待运行全部模型联合分析。' : '等待本地模型工作端领取任务。';
    $insert->execute([
      $analysisPublicId,
      (int)$image['user_id'],
      $image['device_id'] === null ? null : (int)$image['device_id'],
      $image['member_id'] === null ? null : (int)$image['member_id'],
      (string)$image['image_path'],
      $image['image_width'],
      $image['image_height'],
      (int)$image['image_bytes'],
      (int)$image['source_mirrored'],
      (string)$image['normalization_applied'],
      (int)$image['orientation_normalized'],
      $pipeline,
      (int)$image['id'],
      $total,
      $label,
    ]);
    $pdo->commit();
    json_response([
      'ok' => true,
      'public_id' => $analysisPublicId,
      'source_public_id' => $publicId,
      'pipeline' => $pipeline,
      'status' => 'received',
      'message' => '已创建新的分析记录并加入模型队列。',
    ]);
  }

  $path = image_file_path((string)$image['image_path']);
  try {
    $stale = $pdo->prepare("SELECT DISTINCT job.id FROM dental_arch_jobs job INNER JOIN dental_arch_job_images link ON link.job_id=job.id WHERE link.source_detection_id=? AND job.user_id=?");
    $stale->execute([(int)$image['id'],(int)$user['id']]);
    $jobIds = array_map('intval',$stale->fetchAll(PDO::FETCH_COLUMN));
    if($jobIds){
      $marks=implode(',',array_fill(0,count($jobIds),'?'));
      $pdo->prepare("UPDATE dental_arch_versions SET status='stale',is_current=0 WHERE job_id IN ({$marks})")->execute($jobIds);
      $pdo->prepare("UPDATE dental_arch_jobs SET status='stale',progress_label='来源照片已删除，请基于当前七图重新生成。',error_message='source_image_deleted' WHERE id IN ({$marks})")->execute($jobIds);
    }
  } catch (Throwable $ignored) {
    // 新牙列迁移尚未部署时，不影响原有影像删除功能。
  }
  $pdo->prepare('DELETE FROM detections WHERE id=?')->execute([(int)$image['id']]);
  if(!empty($image['capture_session_id'])){
    $remaining=$pdo->prepare('SELECT capture_region_index FROM detections WHERE capture_session_id=? AND capture_region_index BETWEEN 1 AND 7');$remaining->execute([(int)$image['capture_session_id']]);$remainingMask=0;$remainingCount=0;
    foreach($remaining->fetchAll(PDO::FETCH_COLUMN) as $index){$remainingMask|=1<<((int)$index-1);$remainingCount++;}
    $pdo->prepare("UPDATE capture_sessions SET status='incomplete',completed_count=?,completed_mask=? WHERE id=? AND status='completed'")->execute([$remainingCount,$remainingMask,(int)$image['capture_session_id']]);
  }
  $referenceCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM detections WHERE image_path=' . $pdo->quote((string)$image['image_path'])
  )->fetchColumn();
  $pdo->commit();
  $fileDeleted = $referenceCount === 0 && $path ? @unlink($path) : false;
  json_response([
    'ok' => true,
    'public_id' => $publicId,
    'file_deleted' => $fileDeleted,
    'file_retained_for_other_records' => $referenceCount > 0,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}
