<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
require_model_secret();
$data = request_data();
$detectionPublicId = trim((string)($data['detection_id'] ?? ''));
$modelName = mb_substr(trim((string)($data['model_name'] ?? '')), 0, 64);
$modelVersion = mb_substr(trim((string)($data['model_version'] ?? '')), 0, 64);
$modelType = trim((string)($data['model_type'] ?? 'vision'));
$status = trim((string)($data['status'] ?? 'completed'));
$summary = mb_substr(trim((string)($data['summary_text'] ?? '')), 0, 60000);
$risk = trim((string)($data['risk_level'] ?? 'unknown'));
$error = mb_substr(trim((string)($data['error_message'] ?? '')), 0, 500);
if ($detectionPublicId === '' || $modelName === '') json_response(['ok' => false, 'error' => '检测记录 ID 和模型名称不能为空。'], 422);
if (!in_array($modelType, ['vision', 'llm'], true) || !in_array($status, ['completed', 'failed'], true) || !in_array($risk, ['unknown', 'low', 'medium', 'high'], true)) json_response(['ok' => false, 'error' => '模型结果状态参数不正确。'], 422);

$raw = $data['raw_result_json'] ?? null;
if (is_string($raw) && $raw !== '') {
  $decoded = json_decode($raw, true);
  if (json_last_error() !== JSON_ERROR_NONE) json_response(['ok' => false, 'error' => 'raw_result_json 不是有效 JSON。'], 422);
  $raw = $decoded;
}
$rawJson = $raw === null || $raw === '' ? null : json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($rawJson === false) json_response(['ok' => false, 'error' => '原始模型结果无法编码。'], 422);

$pdo = db();
$pdo->beginTransaction();
try {
  $s = $pdo->prepare('SELECT id FROM detections WHERE public_id=? LIMIT 1 FOR UPDATE');
  $s->execute([mb_substr($detectionPublicId, 0, 32)]);
  $detection = $s->fetch();
  if (!$detection) { $pdo->rollBack(); json_response(['ok' => false, 'error' => '检测记录不存在。'], 404); }
  $resultPublicId = public_id();
  $s = $pdo->prepare("INSERT INTO detection_results(public_id,detection_id,model_name,model_version,model_type,status,raw_result_json,summary_text,risk_level,error_message,completed_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())");
  $s->execute([$resultPublicId, (int)$detection['id'], $modelName, $modelVersion ?: null, $modelType, $status, $rawJson, $summary ?: null, $risk, $error ?: null]);
  if ($status === 'completed') {
    $report = mb_substr($summary !== '' ? $summary : '模型分析已完成。', 0, 1000);
    $partial = is_array($raw) && (string)($raw['completion_status'] ?? '') === 'partial';
    $progressLabel = $partial ? '联合分析部分完成，可查看已成功模型的结果。' : '模型分析已完成。';
    $pdo->prepare("UPDATE detections SET status='completed',progress_step=progress_total,progress_label=?,report_text=?,result_json=? WHERE id=?")
      ->execute([$progressLabel, $report, $rawJson, (int)$detection['id']]);
  } else {
    $report = mb_substr($error !== '' ? $error : '模型分析失败，请稍后重试。', 0, 1000);
    $pdo->prepare("UPDATE detections SET status='failed',progress_label='模型分析失败。',report_text=? WHERE id=?")
      ->execute([$report, (int)$detection['id']]);
  }
  $pdo->commit();
  // A tooth-outline result may be one stage of a seven-view dental arch job.
  // Keep the generic model callback usable even before the optional migration is installed.
  try {
    require_once __DIR__ . '/dental_arch_common.php';
    dental_arch_note_outline_result($pdo, (int)$detection['id'], $status, is_array($raw) ? $raw : null, $error !== '' ? $error : $summary);
  } catch (Throwable $archError) {
    error_log('dental arch outline handoff failed: ' . $archError->getMessage());
  }
  json_response(['ok' => true, 'result_id' => $resultPublicId, 'detection_id' => $detectionPublicId, 'status' => $status]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}
