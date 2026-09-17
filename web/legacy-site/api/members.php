<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function member_value(array $data, string $key, int $max = 64): string {
  return mb_substr(trim((string)($data[$key] ?? '')), 0, $max);
}

function member_birth_value(string $value): ?string {
  if ($value === '') return null;
  $date = DateTime::createFromFormat('Y-m-d', $value);
  $errors = DateTime::getLastErrors();
  if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
    throw new InvalidArgumentException('出生日期格式应为 YYYY-MM-DD。');
  }
  return $value;
}

function member_fields(array $data): array {
  $name = member_value($data, 'name');
  if ($name === '') throw new InvalidArgumentException('请填写成员姓名。');
  $gender = member_value($data, 'gender', 16);
  if (!in_array($gender, ['unknown', 'male', 'female'], true)) $gender = 'unknown';
  return [
    'name' => $name,
    'relationship' => member_value($data, 'relationship', 32),
    'gender' => $gender,
    'birth_date' => member_birth_value(member_value($data, 'birth_date', 10)),
  ];
}

function member_device(PDO $pdo, bool $requireToken = false): array {
  $token = header_value('X-Device-Token');
  $authorization = header_value('Authorization') ?? '';
  if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) $token = trim($matches[1]);
  if ($token) {
    $s = $pdo->prepare('SELECT * FROM devices WHERE token_hash=? LIMIT 1');
    $s->execute([hash('sha256', $token)]);
    $device = $s->fetch();
  } elseif (!$requireToken && ($uid = header_value('X-Device'))) {
    // 仅保留给旧固件的只读成员拉取兼容；本地修改必须使用永久 Token。
    $s = $pdo->prepare('SELECT * FROM devices WHERE device_uid=? LIMIT 1');
    $s->execute([mb_substr($uid, 0, 96)]);
    $device = $s->fetch();
  } else {
    $device = false;
  }
  if (!$device) json_response(['ok' => false, 'error' => $requireToken ? '本地成员同步需要有效设备令牌。' : '设备未绑定或令牌无效。'], 401);
  return $device;
}

function member_list(PDO $pdo, int $userId): array {
  $s = $pdo->prepare("SELECT m.public_id,m.name,m.relationship,m.gender,m.birth_date,m.is_default,m.sync_version,m.updated_source,m.updated_at,COUNT(d.id) AS detection_count FROM family_members m LEFT JOIN detections d ON d.member_id=m.id WHERE m.user_id=? AND m.status='active' GROUP BY m.id ORDER BY m.is_default DESC,m.id ASC");
  $s->execute([$userId]);
  return $s->fetchAll();
}

function member_device_list(PDO $pdo, int $userId): array {
  $s = $pdo->prepare("SELECT public_id,name,relationship,gender,birth_date,is_default,sync_version,updated_source,updated_at FROM family_members WHERE user_id=? AND status='active' ORDER BY is_default DESC,id ASC");
  $s->execute([$userId]);
  return $s->fetchAll();
}

function member_sync_operation_id(array $operation): string {
  $id = member_value($operation, 'operation_id', 64);
  if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id)) throw new InvalidArgumentException('操作 ID 格式不正确。');
  return $id;
}

function member_sync_store(PDO $pdo, array $device, string $operationId, string $type, array $result): void {
  $s = $pdo->prepare('INSERT INTO member_sync_operations(device_id,user_id,operation_id,operation_type,response_json) VALUES(?,?,?,?,?)');
  $s->execute([(int)$device['id'], (int)$device['user_id'], $operationId, $type, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function member_sync_saved(PDO $pdo, array $device, string $operationId): ?array {
  $s = $pdo->prepare('SELECT response_json FROM member_sync_operations WHERE device_id=? AND operation_id=? LIMIT 1');
  $s->execute([(int)$device['id'], $operationId]);
  $row = $s->fetch();
  if (!$row) return null;
  $saved = json_decode((string)$row['response_json'], true);
  return is_array($saved) ? $saved : ['operation_id' => $operationId, 'status' => 'rejected', 'error' => '历史同步结果无法读取。'];
}

function member_sync_cloud_member(PDO $pdo, int $userId, string $publicId, bool $lock = false): ?array {
  $s = $pdo->prepare('SELECT * FROM family_members WHERE public_id=? AND user_id=? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
  $s->execute([$publicId, $userId]);
  return $s->fetch() ?: null;
}

function member_sync_member_payload(array $member): array {
  return [
    'public_id' => $member['public_id'], 'name' => $member['name'], 'relationship' => $member['relationship'],
    'gender' => $member['gender'], 'birth_date' => $member['birth_date'], 'is_default' => (bool)$member['is_default'],
    'sync_version' => (int)$member['sync_version'], 'updated_source' => $member['updated_source'],
    'updated_at' => $member['updated_at'], 'status' => $member['status'],
  ];
}

function apply_member_sync(PDO $pdo, array $device, array $operation): array {
  $operationId = member_sync_operation_id($operation);
  $type = member_value($operation, 'type', 16);
  if (!in_array($type, ['create', 'update', 'delete'], true)) throw new InvalidArgumentException('不支持的成员操作。');
  $saved = member_sync_saved($pdo, $device, $operationId);
  if ($saved !== null) return $saved;

  $pdo->beginTransaction();
  try {
    if ($type === 'create') {
      $fields = member_fields($operation);
      $count = $pdo->prepare("SELECT COUNT(*) FROM family_members WHERE user_id=? AND status='active'");
      $count->execute([(int)$device['user_id']]);
      $isDefault = (int)$count->fetchColumn() === 0 ? 1 : 0;
      $publicId = public_id();
      $s = $pdo->prepare("INSERT INTO family_members(public_id,user_id,name,relationship,gender,birth_date,is_default,sync_version,updated_source) VALUES(?,?,?,?,?,?,?,1,'device')");
      $s->execute([$publicId, (int)$device['user_id'], $fields['name'], $fields['relationship'], $fields['gender'], $fields['birth_date'], $isDefault]);
      $member = member_sync_cloud_member($pdo, (int)$device['user_id'], $publicId, true);
      $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'applied', 'client_member_id' => member_value($operation, 'client_member_id', 64), 'member' => member_sync_member_payload($member)];
    } else {
      $publicId = member_value($operation, 'member_id', 32);
      $baseVersion = (int)($operation['base_version'] ?? 0);
      $member = $publicId === '' ? null : member_sync_cloud_member($pdo, (int)$device['user_id'], $publicId, true);
      if (!$member) {
        $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'rejected', 'error' => '云端未找到该成员。'];
      } elseif ((int)$member['sync_version'] !== $baseVersion) {
        $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'conflict', 'error' => '成员已在网页或其他设备修改，请采用云端最新版本后重试。', 'member' => member_sync_member_payload($member)];
      } elseif ($member['status'] !== 'active') {
        $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'conflict', 'error' => '成员已在云端删除。', 'member' => member_sync_member_payload($member)];
      } elseif ($type === 'update') {
        $fields = member_fields($operation);
        $pdo->prepare("UPDATE family_members SET name=?,relationship=?,gender=?,birth_date=?,sync_version=sync_version+1,updated_source='device' WHERE id=?")
          ->execute([$fields['name'], $fields['relationship'], $fields['gender'], $fields['birth_date'], (int)$member['id']]);
        $member = member_sync_cloud_member($pdo, (int)$device['user_id'], $publicId, true);
        $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'applied', 'member' => member_sync_member_payload($member)];
      } else {
        $count = $pdo->prepare("SELECT COUNT(*) FROM family_members WHERE user_id=? AND status='active'");
        $count->execute([(int)$device['user_id']]);
        if ((int)$count->fetchColumn() <= 1) {
          $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'rejected', 'error' => '账户至少需要保留一位成员。'];
        } else {
          $pdo->prepare("UPDATE family_members SET status='deleted',is_default=0,sync_version=sync_version+1,updated_source='device' WHERE id=?")->execute([(int)$member['id']]);
          if ((int)$member['is_default'] === 1) $pdo->prepare("UPDATE family_members SET is_default=1,sync_version=sync_version+1,updated_source='device' WHERE user_id=? AND status='active' ORDER BY id ASC LIMIT 1")->execute([(int)$device['user_id']]);
          $result = ['operation_id' => $operationId, 'type' => $type, 'status' => 'applied', 'member_id' => $publicId, 'deleted' => true];
        }
      }
    }
    member_sync_store($pdo, $device, $operationId, $type, $result);
    $pdo->commit();
    return $result;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

$pdo = db();
$action = (string)($_GET['action'] ?? 'list');
$data = request_data();

if ($action === 'device_list') {
  $device = member_device($pdo);
  json_response(['ok' => true, 'items' => member_device_list($pdo, (int)$device['user_id'])]);
}

if ($action === 'device_sync') {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
  $device = member_device($pdo, true);
  $operations = $data['operations'] ?? [];
  if (!is_array($operations) || count($operations) > 20) json_response(['ok' => false, 'error' => '每次最多同步 20 个成员操作。'], 422);
  $results = [];
  foreach ($operations as $operation) {
    if (!is_array($operation)) { $results[] = ['status' => 'rejected', 'error' => '成员操作格式不正确。']; continue; }
    try { $results[] = apply_member_sync($pdo, $device, $operation); }
    catch (InvalidArgumentException $e) { $results[] = ['operation_id' => member_value($operation, 'operation_id', 64), 'status' => 'rejected', 'error' => $e->getMessage()]; }
  }
  json_response(['ok' => true, 'results' => $results, 'items' => member_device_list($pdo, (int)$device['user_id'])]);
}

$user = require_user();
if ($action === 'list') json_response(['ok' => true, 'items' => member_list($pdo, (int)$user['id'])]);

if ($action === 'create') {
  require_csrf();
  try { $fields = member_fields($data); } catch (InvalidArgumentException $e) { json_response(['ok' => false, 'error' => $e->getMessage()], 422); }
  $s = $pdo->prepare("SELECT COUNT(*) FROM family_members WHERE user_id=? AND status='active'");
  $s->execute([(int)$user['id']]);
  $isDefault = (int)$s->fetchColumn() === 0 ? 1 : 0;
  $s = $pdo->prepare("INSERT INTO family_members(public_id,user_id,name,relationship,gender,birth_date,is_default,sync_version,updated_source) VALUES(?,?,?,?,?,?,?,1,'web')");
  $s->execute([public_id(), (int)$user['id'], $fields['name'], $fields['relationship'], $fields['gender'], $fields['birth_date'], $isDefault]);
  json_response(['ok' => true], 201);
}

if ($action === 'update') {
  require_csrf();
  $id = member_value($data, 'public_id', 32);
  try { $fields = member_fields($data); } catch (InvalidArgumentException $e) { json_response(['ok' => false, 'error' => $e->getMessage()], 422); }
  if ($id === '') json_response(['ok' => false, 'error' => '成员信息不完整。'], 422);
  $s = $pdo->prepare("UPDATE family_members SET name=?,relationship=?,gender=?,birth_date=?,sync_version=sync_version+1,updated_source='web' WHERE public_id=? AND user_id=? AND status='active'");
  $s->execute([$fields['name'], $fields['relationship'], $fields['gender'], $fields['birth_date'], $id, (int)$user['id']]);
  if ($s->rowCount() === 0) json_response(['ok' => false, 'error' => '成员不存在或已删除。'], 404);
  json_response(['ok' => true]);
}

if ($action === 'delete') {
  require_csrf();
  $id = member_value($data, 'public_id', 32);
  $pdo->beginTransaction();
  try {
    $member = $id === '' ? null : member_sync_cloud_member($pdo, (int)$user['id'], $id, true);
    if (!$member || $member['status'] !== 'active') { $pdo->rollBack(); json_response(['ok' => false, 'error' => '成员不存在或已删除。'], 404); }
    $count = $pdo->prepare("SELECT COUNT(*) FROM family_members WHERE user_id=? AND status='active'");
    $count->execute([(int)$user['id']]);
    if ((int)$count->fetchColumn() <= 1) { $pdo->rollBack(); json_response(['ok' => false, 'error' => '账户至少需要保留一位成员。'], 422); }
    $pdo->prepare("UPDATE family_members SET status='deleted',is_default=0,sync_version=sync_version+1,updated_source='web' WHERE id=?")->execute([(int)$member['id']]);
    if ((int)$member['is_default'] === 1) $pdo->prepare("UPDATE family_members SET is_default=1,sync_version=sync_version+1,updated_source='web' WHERE user_id=? AND status='active' ORDER BY id ASC LIMIT 1")->execute([(int)$user['id']]);
    $pdo->commit();
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

json_response(['ok' => false, 'error' => '接口不存在。'], 404);
