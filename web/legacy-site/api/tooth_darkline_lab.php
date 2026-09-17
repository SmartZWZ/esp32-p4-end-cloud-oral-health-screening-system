<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const DARKLINE_LAYERS = ['overview', 'tooth', 'normalized', 'heatmap', 'candidate', 'skeleton'];

function darkline_demo_datasets(): array {
  $views = [
      'front_bite' => '01-front_bite.jpg',
      'left_bite' => '02-left_bite.jpg',
      'right_bite' => '03-right_bite.jpg',
      'upper_left_open' => '04-upper_left_open.jpg',
      'upper_right_open' => '05-upper_right_open.jpg',
      'lower_left_open' => '06-lower_left_open.jpg',
      'lower_right_open' => '07-lower_right_open.jpg',
  ];
  return [
    'seven-view-numbered-wx-v1' => $views,
    'seven-view-numbered-v1' => $views,
  ];
}

function darkline_demo_views(string $dataset): array {
  return darkline_demo_datasets()[$dataset] ?? [];
}

function darkline_demo_input(string $dataset, string $viewId, int $fdi): array {
  if (!isset(darkline_demo_datasets()[$dataset]) || !isset(darkline_demo_views($dataset)[$viewId]) || $fdi < 11 || $fdi > 48) {
    json_response(['ok' => false, 'error' => '测试牙列来源参数无效。'], 422);
  }
  $manifestPath = dirname(__DIR__) . '/assets/demo/' . $dataset . '/manifest.json';
  $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
  $views = is_array($manifest) ? ($manifest['teeth'][(string)$fdi]['views'] ?? []) : [];
  $selected = null;
  foreach ($views as $view) {
    if (is_array($view) && (string)($view['view_id'] ?? '') === $viewId) { $selected = $view; break; }
  }
  if (!$selected || !is_array($selected['polygon_xy'] ?? null) || count($selected['polygon_xy']) < 3) {
    json_response(['ok' => false, 'error' => '测试档案中不存在当前牙齿视角。'], 404);
  }
  return [
    'source_kind' => 'demo',
    'dataset' => $dataset,
    'view_id' => $viewId,
    'fdi' => $fdi,
    'tooth' => [
      'navigation_id' => 1,
      'confidence' => 1,
      'bbox_xyxy' => array_values($selected['bbox_xyxy'] ?? []),
      'polygon' => array_values($selected['polygon_xy']),
    ],
  ];
}

function darkline_demo_image_path(string $dataset, string $viewId): ?string {
  $filename = darkline_demo_views($dataset)[$viewId] ?? '';
  if ($filename === '') return null;
  $path = dirname(__DIR__) . '/assets/demo/' . $dataset . '/original/' . $filename;
  return is_file($path) ? $path : null;
}

function darkline_clamp_float($value, float $minimum, float $maximum, float $fallback): float {
  if (!is_numeric($value)) return $fallback;
  return min($maximum, max($minimum, (float)$value));
}

function darkline_parameters(array $input): array {
  $smooth = (int)round(darkline_clamp_float($input['smooth_px'] ?? 5, 1, 9, 5));
  if ($smooth % 2 === 0) $smooth = min(9, $smooth + 1);
  return [
    'edge_shrink_pct' => round(darkline_clamp_float($input['edge_shrink_pct'] ?? 6, 0, 15, 6), 2),
    'darkness_threshold' => round(darkline_clamp_float($input['darkness_threshold'] ?? 0.602, 0.2, 0.8, 0.602), 4),
    'black_level_pct' => round(darkline_clamp_float($input['black_level_pct'] ?? 35, 20, 65, 35), 2),
    'min_contrast_pct' => round(darkline_clamp_float($input['min_contrast_pct'] ?? 5, 2, 20, 5), 2),
    'min_width_px' => round(darkline_clamp_float($input['min_width_px'] ?? 0.5, 0.5, 6, 0.5), 2),
    'min_length_pct' => round(darkline_clamp_float($input['min_length_pct'] ?? 18, 2, 25, 18), 2),
    'smooth_px' => $smooth,
    'exclude_highlights' => filter_var($input['exclude_highlights'] ?? true, FILTER_VALIDATE_BOOLEAN),
  ];
}

function darkline_result_payload(array $raw): array {
  if ((string)($raw['pipeline'] ?? '') === 'tooth_outline_darkline_v3') return $raw;
  foreach (($raw['pipeline_results'] ?? []) as $stage) {
    if (is_array($stage) && (string)($stage['pipeline'] ?? '') === 'tooth_outline') return $stage;
  }
  return [];
}

function darkline_teeth(array $raw): array {
  $payload = darkline_result_payload($raw);
  if (!empty($payload['teeth']) && is_array($payload['teeth'])) return $payload['teeth'];
  $teeth = [];
  foreach (($payload['findings'] ?? $raw['findings'] ?? []) as $finding) {
    if (!is_array($finding)) continue;
    if ((string)($finding['label'] ?? '') === 'Tooth') {
      $index = (int)($finding['navigation_id'] ?? 0);
      if ($index > 0) $teeth[$index] = $finding + ['candidate_count' => 0, 'darkline_candidates' => []];
    }
  }
  foreach (($payload['findings'] ?? $raw['findings'] ?? []) as $finding) {
    if (!is_array($finding) || (string)($finding['label'] ?? '') !== 'MicrocariesDarkline') continue;
    $index = (int)($finding['tooth_navigation_id'] ?? 0);
    if ($index < 1 || !isset($teeth[$index])) continue;
    $teeth[$index]['darkline_candidates'][] = $finding;
    $teeth[$index]['candidate_count'] = count($teeth[$index]['darkline_candidates']);
  }
  ksort($teeth);
  return array_values($teeth);
}

function darkline_find_tooth(array $raw, int $navigationId): ?array {
  foreach (darkline_teeth($raw) as $tooth) {
    if ((int)($tooth['navigation_id'] ?? 0) === $navigationId) return $tooth;
  }
  return null;
}

function darkline_job_output(array $row): array {
  $result = [];
  if (!empty($row['result_json'])) {
    $decoded = json_decode((string)$row['result_json'], true);
    if (is_array($decoded)) $result = $decoded;
  }
  if ((string)($row['status'] ?? '') !== 'completed' && isset($result['input'])) $result = [];
  if (!empty($result['evidence_layers']) && is_array($result['evidence_layers'])) {
    $urls = [];
    foreach (array_keys($result['evidence_layers']) as $layer) {
      if (in_array($layer, DARKLINE_LAYERS, true)) {
        $urls[$layer] = 'api/tooth_darkline_lab.php?action=evidence&job_id=' . rawurlencode((string)$row['public_id']) . '&layer=' . rawurlencode($layer) . '&v=' . rawurlencode((string)$row['updated_at']);
      }
    }
    $result['evidence_urls'] = $urls;
    unset($result['evidence_layers']);
  }
  return [
    'public_id' => (string)$row['public_id'],
    'status' => (string)$row['status'],
    'tooth_navigation_id' => (int)$row['tooth_navigation_id'],
    'parameters' => json_decode((string)$row['parameters_json'], true) ?: [],
    'result' => $result,
    'error_message' => (string)($row['error_message'] ?? ''),
    'created_at' => (string)$row['created_at'],
    'updated_at' => (string)$row['updated_at'],
  ];
}

$action = trim((string)($_GET['action'] ?? ''));
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$pdo = db();

if ($action === 'demo_image') {
  if ($method !== 'GET') json_response(['ok' => false, 'error' => '仅支持 GET 请求。'], 405);
  require_model_secret();
  $path = darkline_demo_image_path(trim((string)($_GET['dataset'] ?? '')), trim((string)($_GET['view_id'] ?? '')));
  if (!$path) { http_response_code(404); exit('Not found'); }
  $info = function_exists('getimagesize') ? @getimagesize($path) : false;
  header('Content-Type: ' . (is_array($info) ? (string)($info['mime'] ?? 'image/jpeg') : 'image/jpeg'));
  header('Content-Length: ' . (string)filesize($path));
  header('Cache-Control: private, max-age=300');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

if ($action === 'next') {
  if ($method !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
  require_model_secret();
  $pdo->beginTransaction();
  try {
    $query = $pdo->query("SELECT j.id,j.public_id,j.tooth_navigation_id,j.parameters_json,j.result_json AS job_result_json,d.public_id AS detection_public_id,d.result_json AS detection_result_json FROM tooth_darkline_lab_jobs j LEFT JOIN detections d ON d.id=j.detection_id WHERE j.status='queued' OR (j.status='processing' AND j.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)) ORDER BY j.id ASC LIMIT 1 FOR UPDATE");
    $row = $query->fetch();
    if (!$row) { $pdo->commit(); json_response(['ok' => true, 'job' => null]); }
    $jobEnvelope = json_decode((string)($row['job_result_json'] ?? ''), true);
    $demoInput = is_array($jobEnvelope) && is_array($jobEnvelope['input'] ?? null) && ($jobEnvelope['input']['source_kind'] ?? '') === 'demo' ? $jobEnvelope['input'] : null;
    $raw = json_decode((string)($row['detection_result_json'] ?? ''), true);
    $tooth = $demoInput ? ($demoInput['tooth'] ?? null) : (is_array($raw) ? darkline_find_tooth($raw, (int)$row['tooth_navigation_id']) : null);
    if (!$tooth) {
      $pdo->prepare("UPDATE tooth_darkline_lab_jobs SET status='failed',error_message='原始牙齿轮廓结果已不存在。',completed_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
      $pdo->commit();
      json_response(['ok' => true, 'job' => null]);
    }
    $pdo->prepare("UPDATE tooth_darkline_lab_jobs SET status='processing',error_message=NULL WHERE id=?")->execute([(int)$row['id']]);
    $pdo->commit();
    json_response(['ok' => true, 'job' => [
      'public_id' => (string)$row['public_id'],
      'detection_id' => (string)$row['detection_public_id'],
      'source_kind' => $demoInput ? 'demo' : 'detection',
      'demo_dataset' => $demoInput ? (string)$demoInput['dataset'] : '',
      'demo_view_id' => $demoInput ? (string)$demoInput['view_id'] : '',
      'tooth_navigation_id' => (int)$row['tooth_navigation_id'],
      'parameters' => json_decode((string)$row['parameters_json'], true) ?: [],
      'tooth' => $tooth,
    ]]);
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (str_contains($error->getMessage(), 'tooth_darkline_lab_jobs') || (string)$error->getCode() === '42S02') {
      json_response(['ok' => true, 'job' => null, 'migration_required' => true]);
    }
    throw $error;
  }
}

if ($action === 'complete') {
  if ($method !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
  require_model_secret();
  $data = request_data();
  $publicId = mb_substr(trim((string)($data['job_id'] ?? '')), 0, 32);
  $status = (string)($data['status'] ?? 'completed');
  if ($publicId === '' || !in_array($status, ['completed', 'failed'], true)) json_response(['ok' => false, 'error' => '任务参数不正确。'], 422);
  $query = $pdo->prepare('SELECT id,user_id FROM tooth_darkline_lab_jobs WHERE public_id=? LIMIT 1');
  $query->execute([$publicId]);
  $job = $query->fetch();
  if (!$job) json_response(['ok' => false, 'error' => '证据层任务不存在。'], 404);
  if ($status === 'failed') {
    $errorMessage = mb_substr(trim((string)($data['error_message'] ?? '本地数字图像处理失败。')), 0, 500);
    $pdo->prepare("UPDATE tooth_darkline_lab_jobs SET status='failed',error_message=?,completed_at=NOW() WHERE id=?")->execute([$errorMessage, (int)$job['id']]);
    json_response(['ok' => true, 'job_id' => $publicId, 'status' => 'failed']);
  }
  $result = $data['result'] ?? [];
  if (is_string($result)) $result = json_decode($result, true);
  if (!is_array($result)) json_response(['ok' => false, 'error' => '数字图像处理结果格式错误。'], 422);
  $encodedLayers = $data['evidence_layers'] ?? [];
  if (is_string($encodedLayers)) $encodedLayers = json_decode($encodedLayers, true);
  if (!is_array($encodedLayers)) json_response(['ok' => false, 'error' => '证据层格式错误。'], 422);
  $relativeDir = 'darkline_lab/' . (int)$job['user_id'] . '/' . $publicId;
  $absoluteDir = storage_root() . '/' . $relativeDir;
  if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) throw new RuntimeException('无法创建证据层存储目录。');
  $stored = [];
  foreach ($encodedLayers as $layer => $encoded) {
    if (!in_array($layer, DARKLINE_LAYERS, true) || !is_string($encoded) || strlen($encoded) > 4000000) continue;
    if (strpos($encoded, ',') !== false) $encoded = substr($encoded, strpos($encoded, ',') + 1);
    $binary = base64_decode($encoded, true);
    if ($binary === false || strlen($binary) < 24) continue;
    $extension = substr($binary, 0, 2) === "\xFF\xD8" ? 'jpg' : 'png';
    $path = $absoluteDir . '/' . $layer . '.' . $extension;
    if (file_put_contents($path, $binary, LOCK_EX) === false) throw new RuntimeException('证据层保存失败。');
    $stored[$layer] = $relativeDir . '/' . $layer . '.' . $extension;
  }
  if (!$stored) json_response(['ok' => false, 'error' => '没有收到有效证据层图片。'], 422);
  $result['evidence_layers'] = $stored;
  $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($resultJson === false) json_response(['ok' => false, 'error' => '结果无法编码。'], 422);
  $pdo->prepare("UPDATE tooth_darkline_lab_jobs SET status='completed',result_json=?,error_message=NULL,completed_at=NOW() WHERE id=?")->execute([$resultJson, (int)$job['id']]);
  json_response(['ok' => true, 'job_id' => $publicId, 'status' => 'completed']);
}

if ($action === 'evidence') {
  $user = require_user();
  $jobId = mb_substr(trim((string)($_GET['job_id'] ?? '')), 0, 32);
  $layer = trim((string)($_GET['layer'] ?? ''));
  if (!in_array($layer, DARKLINE_LAYERS, true)) { http_response_code(404); exit('Not found'); }
  $query = $pdo->prepare('SELECT result_json FROM tooth_darkline_lab_jobs WHERE public_id=? AND user_id=? AND status=\'completed\' LIMIT 1');
  $query->execute([$jobId, (int)$user['id']]);
  $row = $query->fetch();
  $result = $row ? json_decode((string)$row['result_json'], true) : null;
  $relative = is_array($result) ? (string)($result['evidence_layers'][$layer] ?? '') : '';
  $path = $relative !== '' ? image_file_path($relative) : null;
  if (!$path) { http_response_code(404); exit('Not found'); }
  $mime = str_ends_with(strtolower($path), '.jpg') ? 'image/jpeg' : 'image/png';
  header('Content-Type: ' . $mime);
  header('Content-Length: ' . (string)filesize($path));
  header('Cache-Control: private, max-age=300');
  readfile($path);
  exit;
}

$user = require_user();
if ($action === 'start') {
  if ($method !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
  require_csrf();
  $data = request_data();
  $demoDataset = mb_substr(trim((string)($data['demo_dataset'] ?? '')), 0, 64);
  $demoViewId = mb_substr(trim((string)($data['demo_view_id'] ?? '')), 0, 32);
  $demoFdi = (int)($data['demo_tooth_fdi'] ?? 0);
  $detectionPublicId = mb_substr(trim((string)($data['detection_id'] ?? '')), 0, 32);
  $navigationId = (int)($data['tooth_navigation_id'] ?? 0);
  $detectionId = null;$jobInput = null;$sourceKind = 'detection';
  if ($demoDataset !== '') {
    $jobInput = darkline_demo_input($demoDataset, $demoViewId, $demoFdi);
    $navigationId = 1;$sourceKind = 'demo';
  } else {
    if ($detectionPublicId === '' || $navigationId < 1 || $navigationId > 64) json_response(['ok' => false, 'error' => '请选择有效的牙齿实例。'], 422);
    $query = $pdo->prepare("SELECT id,result_json FROM detections WHERE public_id=? AND user_id=? AND status='completed' LIMIT 1");
    $query->execute([$detectionPublicId, (int)$user['id']]);
    $detection = $query->fetch();
    $raw = $detection ? json_decode((string)$detection['result_json'], true) : null;
    if (!$detection || !is_array($raw) || !darkline_find_tooth($raw, $navigationId)) json_response(['ok' => false, 'error' => '当前记录没有可调参的牙齿轮廓结果。'], 404);
    $detectionId = (int)$detection['id'];
  }
  $params = darkline_parameters(is_array($data['parameters'] ?? null) ? $data['parameters'] : []);
  $publicId = public_id();
  $pdo->prepare('INSERT INTO tooth_darkline_lab_jobs(public_id,user_id,detection_id,tooth_navigation_id,parameters_json,result_json) VALUES(?,?,?,?,?,?)')->execute([
    $publicId, (int)$user['id'], $detectionId, $navigationId,
    json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    $jobInput ? json_encode(['input' => $jobInput], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
  ]);
  json_response(['ok' => true, 'job' => [
    'public_id' => $publicId,
    'status' => 'queued',
    'source_kind' => $sourceKind,
    'tooth_navigation_id' => $navigationId,
    'parameters' => $params,
    'result' => [],
  ]], 201);
}

if ($action === 'status') {
  $jobId = mb_substr(trim((string)($_GET['job_id'] ?? '')), 0, 32);
  $query = $pdo->prepare('SELECT public_id,status,tooth_navigation_id,parameters_json,result_json,error_message,created_at,updated_at FROM tooth_darkline_lab_jobs WHERE public_id=? AND user_id=? LIMIT 1');
  $query->execute([$jobId, (int)$user['id']]);
  $row = $query->fetch();
  if (!$row) json_response(['ok' => false, 'error' => '证据层任务不存在。'], 404);
  json_response(['ok' => true, 'job' => darkline_job_output($row)]);
}

json_response(['ok' => false, 'error' => '接口不存在。'], 404);
