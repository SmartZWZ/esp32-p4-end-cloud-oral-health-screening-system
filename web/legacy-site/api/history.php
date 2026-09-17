<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$user = require_user();
$limit = min(max((int)($_GET['limit'] ?? 50), 1), 100);
$memberId = trim((string)($_GET['member_id'] ?? ''));
$uploadMode = trim((string)($_GET['upload_mode'] ?? ''));
if ($uploadMode !== '' && !in_array($uploadMode, ['archive', 'detect'], true)) json_response(['ok' => false, 'error' => '图片类型参数不正确。'], 422);
$sql = "SELECT d.public_id,d.upload_mode,d.model_pipeline,d.status,d.progress_step,d.progress_total,d.progress_label,d.report_text,d.result_json,d.image_width,d.image_height,d.image_bytes,d.created_at,CASE WHEN d.device_id IS NULL THEN 'web' ELSE 'device' END AS source_type,m.public_id AS member_public_id,COALESCE(m.name,'未归属成员') AS member_name FROM detections d LEFT JOIN family_members m ON m.id=d.member_id WHERE d.user_id=?";
$args = [(int)$user['id']];
if ($memberId !== '') {
  $sql .= ' AND m.public_id=?';
  $args[] = mb_substr($memberId, 0, 32);
}
if ($uploadMode !== '') {
  $sql .= ' AND d.upload_mode=?';
  $args[] = $uploadMode;
}
$sql .= " ORDER BY d.id DESC LIMIT {$limit}";
$s = db()->prepare($sql);
$s->execute($args);
json_response(['ok' => true, 'items' => $s->fetchAll()]);
