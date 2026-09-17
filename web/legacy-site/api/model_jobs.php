<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
require_model_secret();
$action = (string)($_GET['action'] ?? '');
if (!in_array($action, ['next', 'progress', 'status'], true)) json_response(['ok' => false, 'error' => '接口不存在。'], 404);

$pdo = db();
if ($action === 'status') {
  $counts = $pdo->query(
    "SELECT
       SUM(status='received') AS queued,
       SUM(status='processing') AS processing,
       SUM(status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS completed_24h,
       SUM(status='failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_24h
     FROM detections"
  )->fetch() ?: [];
  $active = $pdo->query(
    "SELECT public_id,model_pipeline,status,progress_step,progress_total,progress_label,updated_at
     FROM detections
     WHERE status IN ('received','processing')
     ORDER BY CASE WHEN status='processing' THEN 0 ELSE 1 END,id ASC
     LIMIT 8"
  )->fetchAll();
  json_response([
    'ok' => true,
    'server_time' => date('c'),
    'counts' => [
      'queued' => (int)($counts['queued'] ?? 0),
      'processing' => (int)($counts['processing'] ?? 0),
      'completed_24h' => (int)($counts['completed_24h'] ?? 0),
      'failed_24h' => (int)($counts['failed_24h'] ?? 0),
    ],
    'active_jobs' => $active,
  ]);
}
if ($action === 'progress') {
  $data = request_data();
  $publicId = mb_substr(trim((string)($data['detection_id'] ?? '')), 0, 32);
  $step = min(max((int)($data['step'] ?? 0), 0), 20);
  $total = min(max((int)($data['total'] ?? 1), 1), 20);
  $label = mb_substr(trim((string)($data['label'] ?? '模型正在分析。')), 0, 120);
  if ($publicId === '') json_response(['ok' => false, 'error' => '缺少检测记录 ID。'], 422);
  $s = $pdo->prepare(
    "UPDATE detections
     SET status='processing',progress_step=?,progress_total=?,progress_label=?,report_text=?
     WHERE public_id=? AND status IN ('received','processing')"
  );
  $s->execute([$step, $total, $label, $label, $publicId]);
  if ($s->rowCount() < 1) json_response(['ok' => false, 'error' => '任务不存在或已结束。'], 409);
  json_response(['ok' => true, 'detection_id' => $publicId, 'step' => $step, 'total' => $total, 'label' => $label]);
}

$pdo->beginTransaction();
try {
  $s = $pdo->query("SELECT d.id,d.public_id,d.user_id,d.member_id,d.model_pipeline,d.created_at,m.public_id AS member_public_id,COALESCE(m.name,'未归属成员') AS member_name FROM detections d LEFT JOIN family_members m ON m.id=d.member_id WHERE d.status='received' OR (d.status='processing' AND d.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)) ORDER BY d.id ASC LIMIT 1 FOR UPDATE");
  $job = $s->fetch();
  if (!$job) { $pdo->commit(); json_response(['ok' => true, 'job' => null]); }
  $total = (string)$job['model_pipeline'] === 'all_models' ? 5 : 1;
  $label = $total === 5 ? '准备运行全部模型联合分析。' : '图片正在进行模型分析。';
  $pdo->prepare("UPDATE detections SET status='processing',progress_step=0,progress_total=?,progress_label=?,report_text=? WHERE id=?")
    ->execute([$total, $label, $label, (int)$job['id']]);
  $pdo->commit();
  unset($job['id']);
  json_response(['ok' => true, 'job' => $job]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}
