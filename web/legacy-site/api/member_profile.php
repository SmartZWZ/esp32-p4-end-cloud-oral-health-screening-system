<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function member_profile_json(?string $value): ?array {
  if (!$value) return null;
  $decoded = json_decode($value, true);
  return is_array($decoded) ? $decoded : null;
}

function member_profile_recommendations(?array $report): array {
  if (!$report || !is_array($report['recommendations'] ?? null)) return [];
  $items = [];
  foreach (array_slice($report['recommendations'], 0, 20) as $item) {
    if (!is_array($item)) continue;
    $action = mb_substr(trim((string)($item['action'] ?? '')), 0, 600);
    $reason = mb_substr(trim((string)($item['reason'] ?? '')), 0, 1000);
    if ($action === '' && $reason === '') continue;
    $priority = strtolower((string)($item['priority'] ?? 'routine'));
    if (!in_array($priority, ['routine', 'soon', 'urgent'], true)) $priority = 'routine';
    $items[] = ['priority' => $priority, 'action' => $action, 'reason' => $reason];
  }
  return $items;
}

function member_profile_risk(string $risk): string {
  return in_array($risk, ['unknown', 'low', 'medium', 'high'], true) ? $risk : 'unknown';
}

$user = require_user();
$pdo = db();
$userId = (int)$user['id'];
$memberPublicId = mb_substr(trim((string)($_GET['member'] ?? '')), 0, 32);
if ($memberPublicId === '') json_response(['ok' => false, 'error' => '缺少成员编号。'], 422);

$memberStmt = $pdo->prepare(
  "SELECT id,public_id,name,relationship,gender,birth_date,is_default,status,created_at,updated_at
   FROM family_members
   WHERE public_id=? AND user_id=? LIMIT 1"
);
$memberStmt->execute([$memberPublicId, $userId]);
$member = $memberStmt->fetch();
if (!$member || (string)$member['status'] !== 'active') {
  json_response(['ok' => false, 'error' => '成员不存在、已删除或不属于当前账号。'], 404);
}
$memberId = (int)$member['id'];

$imageStmt = $pdo->prepare(
  "SELECT d.public_id,d.upload_mode,d.model_pipeline,d.status,d.progress_label,d.report_text,
          d.image_width,d.image_height,d.image_bytes,d.created_at,
          d.capture_session_id,d.capture_mode,d.capture_region_id,d.capture_region_index,
          CASE WHEN d.device_id IS NULL THEN 'web' ELSE 'device' END AS source_type,
          (SELECT COUNT(*) FROM detections child WHERE child.source_detection_id=d.id) AS analysis_count,
          (SELECT COUNT(*) FROM detection_results result WHERE result.detection_id=d.id) AS result_count
   FROM detections d
   WHERE d.user_id=? AND d.member_id=? AND d.source_detection_id IS NULL
   ORDER BY d.id DESC"
);
$imageStmt->execute([$userId, $memberId]);
$images = $imageStmt->fetchAll();

$archiveStmt = $pdo->prepare(
  "SELECT cs.id,cs.public_id,cs.status,cs.completed_count,cs.completed_mask,
          cs.current_region_index,cs.created_at,cs.updated_at,cs.completed_at,
          device.display_name AS device_name,device.public_id AS device_public_id,
          reference.version_code AS reference_version,
          arch_job.public_id AS dental_arch_job_id,arch_job.status AS dental_arch_status,
          arch_job.progress_label AS dental_arch_progress,arch_version.public_id AS dental_arch_version_id
   FROM capture_sessions cs
   LEFT JOIN devices device ON device.id=cs.device_id
   LEFT JOIN capture_reference_versions reference ON reference.id=cs.reference_version_id
   LEFT JOIN dental_arch_jobs arch_job ON arch_job.id=(SELECT MAX(latest_arch.id) FROM dental_arch_jobs latest_arch WHERE latest_arch.capture_session_id=cs.id)
   LEFT JOIN dental_arch_versions arch_version ON arch_version.id=arch_job.active_version_id
   WHERE cs.user_id=? AND cs.member_id=?
     AND EXISTS(SELECT 1 FROM detections image WHERE image.capture_session_id=cs.id)
   ORDER BY cs.id DESC"
);
$archiveStmt->execute([$userId, $memberId]);
$archiveRows = $archiveStmt->fetchAll();
$archiveImages = [];
foreach ($images as $image) {
  $sessionId = (int)($image['capture_session_id'] ?? 0);
  if ($sessionId > 0) $archiveImages[$sessionId][] = $image;
}
$archives = [];
foreach ($archiveRows as $row) {
  $sessionId = (int)$row['id'];
  $sessionImages = $archiveImages[$sessionId] ?? [];
  usort($sessionImages, static fn(array $a, array $b): int => (int)$a['capture_region_index'] <=> (int)$b['capture_region_index']);
  $actualMask = 0;
  foreach ($sessionImages as $image) {
    $regionIndex = (int)($image['capture_region_index'] ?? 0);
    if ($regionIndex >= 1 && $regionIndex <= 7) $actualMask |= 1 << ($regionIndex - 1);
  }
  $actualCount = count($sessionImages);
  $isComplete = $actualCount === 7 && $actualMask === 127 && (string)$row['status'] === 'completed';
  $archives[] = [
    'public_id' => (string)$row['public_id'],
    'status' => $isComplete ? 'completed' : 'incomplete',
    'stored_status' => (string)$row['status'],
    'completed_count' => $actualCount,
    'completed_mask' => $actualMask,
    'is_complete' => $isComplete,
    'device_name' => (string)($row['device_name'] ?: '齿镜设备'),
    'device_public_id' => (string)($row['device_public_id'] ?? ''),
    'reference_version' => (string)($row['reference_version'] ?? ''),
    'dental_arch' => empty($row['dental_arch_job_id']) ? null : [
      'job_id'=>(string)$row['dental_arch_job_id'],
      'status'=>(string)$row['dental_arch_status'],
      'progress_label'=>(string)($row['dental_arch_progress']??''),
      'version_id'=>(string)($row['dental_arch_version_id']??''),
    ],
    'created_at' => (string)$row['created_at'],
    'completed_at' => (string)($row['completed_at'] ?: $row['updated_at']),
    'images' => $sessionImages,
    'url' => 'capture-archive.html?id=' . rawurlencode((string)$row['public_id']),
  ];
}

$aiStmt = $pdo->prepare(
  "SELECT public_id,title,status,risk_level,summary,report_json,model_name,created_at,completed_at,
          (SELECT COUNT(*) FROM ai_dentist_session_images si WHERE si.session_id=ai_dentist_sessions.id) AS image_count
   FROM ai_dentist_sessions
   WHERE user_id=? AND member_id=?
   ORDER BY id DESC"
);
$aiStmt->execute([$userId, $memberId]);
$aiRows = $aiStmt->fetchAll();

$familyStmt = $pdo->prepare(
  "SELECT public_id,title,status,image_count,clinical_summary_json,model_summary_json,
          progress_label,error_message,created_at,completed_at
   FROM family_reports
   WHERE user_id=? AND member_id=?
   ORDER BY id DESC"
);
$familyStmt->execute([$userId, $memberId]);
$familyRows = $familyStmt->fetchAll();

$modelStmt = $pdo->prepare(
  "SELECT d.public_id,d.model_pipeline,d.status,d.progress_label,d.report_text,d.created_at,d.updated_at,
          COALESCE(source.public_id,d.public_id) AS image_public_id,
          latest.model_name,latest.model_version,latest.status AS result_status,
          latest.summary_text,latest.risk_level,latest.error_message,latest.completed_at
   FROM detections d
   LEFT JOIN detections source ON source.id=d.source_detection_id
   LEFT JOIN detection_results latest ON latest.id=(
     SELECT result.id FROM detection_results result
     WHERE result.detection_id=d.id ORDER BY result.id DESC LIMIT 1
   )
   WHERE d.user_id=? AND d.member_id=?
     AND (
       d.report_text IS NOT NULL OR d.result_json IS NOT NULL OR latest.id IS NOT NULL
       OR (d.upload_mode='detect' AND d.status IN ('completed','failed'))
     )
   ORDER BY d.id DESC"
);
$modelStmt->execute([$userId, $memberId]);
$modelRows = $modelStmt->fetchAll();

$reports = [];
$latestAdvice = null;

foreach ($aiRows as $row) {
  $report = member_profile_json($row['report_json']);
  $recommendations = member_profile_recommendations($report);
  $date = (string)($row['completed_at'] ?: $row['created_at']);
  $item = [
    'type' => 'ai_dentist',
    'public_id' => (string)$row['public_id'],
    'title' => (string)$row['title'],
    'status' => (string)$row['status'],
    'risk' => member_profile_risk((string)$row['risk_level']),
    'summary' => (string)($row['summary'] ?: ($report['summary'] ?? '')),
    'recommendations' => $recommendations,
    'image_count' => (int)$row['image_count'],
    'model_name' => (string)($row['model_name'] ?? ''),
    'date' => $date,
    'url' => 'ai-dentist.html?session=' . rawurlencode((string)$row['public_id']),
  ];
  $reports[] = $item;
  if ((string)$row['status'] === 'completed' && ($latestAdvice === null || strcmp($date, $latestAdvice['date']) > 0)) {
    $latestAdvice = $item;
  }
}

foreach ($familyRows as $row) {
  $clinical = member_profile_json($row['clinical_summary_json']);
  $recommendations = member_profile_recommendations($clinical);
  $date = (string)($row['completed_at'] ?: $row['created_at']);
  $status = (string)$row['status'];
  $item = [
    'type' => 'family_report',
    'public_id' => (string)$row['public_id'],
    'title' => (string)$row['title'],
    'status' => $status,
    'risk' => member_profile_risk((string)($clinical['overall_risk'] ?? 'unknown')),
    'summary' => (string)($clinical['summary'] ?? $row['progress_label'] ?? ''),
    'recommendations' => $recommendations,
    'image_count' => (int)$row['image_count'],
    'model_name' => '',
    'date' => $date,
    'url' => 'family-reports.html?id=' . rawurlencode((string)$row['public_id']),
  ];
  $reports[] = $item;
  if (in_array($status, ['completed', 'partial'], true) && $clinical !== null
      && ($latestAdvice === null || strcmp($date, $latestAdvice['date']) > 0)) {
    $latestAdvice = $item;
  }
}

foreach ($modelRows as $row) {
  $summary = trim((string)($row['summary_text'] ?: $row['report_text'] ?: $row['error_message'] ?: $row['progress_label'] ?: ''));
  $reports[] = [
    'type' => 'model',
    'public_id' => (string)$row['public_id'],
    'image_public_id' => (string)$row['image_public_id'],
    'title' => (string)($row['model_name'] ?: $row['model_pipeline'] ?: '模型检测'),
    'status' => (string)($row['result_status'] ?: $row['status']),
    'risk' => member_profile_risk((string)($row['risk_level'] ?: 'unknown')),
    'summary' => $summary,
    'recommendations' => [],
    'image_count' => 1,
    'model_name' => (string)($row['model_name'] ?? ''),
    'pipeline' => (string)$row['model_pipeline'],
    'date' => (string)($row['completed_at'] ?: $row['updated_at'] ?: $row['created_at']),
    'url' => 'model-lab.html?id=' . rawurlencode((string)$row['image_public_id']),
  ];
}

usort($reports, static fn(array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

$completedModelCount = 0;
foreach ($modelRows as $row) {
  if (in_array((string)($row['result_status'] ?: $row['status']), ['completed'], true)) $completedModelCount++;
}

unset($member['id'], $member['status']);
json_response([
  'ok' => true,
  'member' => $member,
  'stats' => [
    'image_count' => count($images),
    'model_report_count' => count($modelRows),
    'completed_model_count' => $completedModelCount,
    'ai_report_count' => count($aiRows),
    'family_report_count' => count($familyRows),
    'latest_capture_at' => $images[0]['created_at'] ?? null,
    'archive_count' => count($archives),
    'completed_archive_count' => count(array_filter($archives, static fn(array $archive): bool => (bool)$archive['is_complete'])),
  ],
  'latest_advice' => $latestAdvice,
  'capture_archives' => $archives,
  'images' => $images,
  'reports' => $reports,
]);
