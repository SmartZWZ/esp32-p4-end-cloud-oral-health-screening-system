<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const DEVICE_AI_COMMANDS = [
  'select_active_member',
  'capture_and_archive',
  'capture_and_analyze',
  'capture_and_local_analyze',
  'set_speaker_volume',
  'set_screen_brightness',
  'set_camera_light',
  'set_light_power',
];
const DEVICE_COMMAND_ARGUMENTS_MAX_BYTES = 512;
const DEVICE_COMMAND_POLL_RESPONSE_MAX_BYTES = 1024;

function command_device(PDO $pdo): array {
  $token = trim((string)(header_value('X-Device-Token') ?? ''));
  if ($token === '') json_response(['ok'=>false,'error'=>'缺少设备令牌。'],401);
  $stmt = $pdo->prepare('SELECT id,public_id,user_id,device_uid,display_name FROM devices WHERE token_hash=? LIMIT 1');
  $stmt->execute([hash('sha256',$token)]);
  $device = $stmt->fetch();
  if (!$device) json_response(['ok'=>false,'error'=>'设备未绑定或设备令牌无效。'],401);
  return $device;
}

function command_object(array $data, string $key, int $maxBytes=16384): ?array {
  $value = $data[$key] ?? null;
  if ($value === null) return null;
  if (!is_array($value)) json_response(['ok'=>false,'error'=>"{$key} 必须是 JSON 对象。"],422);
  $encoded = json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($encoded === false || strlen($encoded) > $maxBytes) json_response(['ok'=>false,'error'=>"{$key} 内容过长。"],422);
  return $value;
}

function command_arguments_error(string $name, array $arguments): ?string {
  if ($name === 'set_light_power') {
    if (count($arguments) !== 1 || !array_key_exists('enabled',$arguments) || !is_bool($arguments['enabled'])) {
      return 'set_light_power.enabled 必须是唯一的 JSON 布尔参数。';
    }
  } elseif ($name === 'set_camera_light') {
    $mode = $arguments['mode'] ?? null;
    if (!is_string($mode) || !in_array($mode,['on','off','auto'],true)) {
      return 'set_camera_light.mode 参数无效。';
    }
  }
  $encoded = json_encode($arguments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($encoded === false || strlen($encoded) > DEVICE_COMMAND_ARGUMENTS_MAX_BYTES) {
    return '设备命令参数超过 512 字节安全上限。';
  }
  return null;
}

function command_poll_response(?array $command, int $pollAfterMs): void {
  $payload = ['ok'=>true,'command'=>$command,'poll_after_ms'=>$pollAfterMs];
  $encoded = json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($encoded === false || strlen($encoded) > DEVICE_COMMAND_POLL_RESPONSE_MAX_BYTES) {
    error_log('device command poll response exceeded safe limit');
    json_response(['ok'=>false,'error'=>'设备命令响应超过安全长度限制。'],500);
  }
  json_response($payload);
}

function command_error_text(mixed $value): string {
  if (is_array($value)) {
    $code = mb_substr(trim((string)($value['code']??'')),0,80);
    $message = mb_substr(trim((string)($value['message']??'')),0,400);
    return trim($code.($code!=='' && $message!==''?'：':'').$message);
  }
  return mb_substr(trim((string)$value),0,500);
}

function command_update_light_state(PDO $pdo, int $deviceId, array $result): void {
  if (!array_key_exists('light_power',$result) || !is_bool($result['light_power'])) return;
  $stmt = $pdo->prepare('SELECT state_json FROM device_runtime_states WHERE device_id=? LIMIT 1');
  $stmt->execute([$deviceId]);
  $row = $stmt->fetch();
  $state = $row ? json_decode((string)$row['state_json'],true) : [];
  if (!is_array($state)) $state = [];
  $state['light_power'] = $result['light_power'];
  $controlState = (string)($result['light_control_state']??'');
  if (in_array($controlState,['auto_wait_dark','auto_light_on','manual_on','manual_off_wait_bright'],true)) {
    $state['light_control_state'] = $controlState;
  }
  $json = json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $pdo->prepare(
    'INSERT INTO device_runtime_states(device_id,state_json,last_reported_at)
     VALUES(?,?,NOW())
     ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),last_reported_at=NOW()'
  )->execute([$deviceId,$json]);
}

function command_runtime_update(PDO $pdo, array $device, array $data): void {
  $capabilities = command_object($data,'capabilities');
  $state = command_object($data,'state');
  $firmware = mb_substr(trim((string)($data['firmware_version'] ?? '')),0,64);
  if ($capabilities === null && $state === null && $firmware === '') {
    $pdo->prepare('UPDATE devices SET last_seen_at=NOW() WHERE id=?')->execute([(int)$device['id']]);
    return;
  }
  $capJson = $capabilities === null ? null : json_encode($capabilities,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $stateJson = $state === null ? null : json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $stmt = $pdo->prepare(
    'INSERT INTO device_runtime_states(device_id,firmware_version,capabilities_json,state_json,last_reported_at)
     VALUES(?,?,?,?,NOW())
     ON DUPLICATE KEY UPDATE
       firmware_version=COALESCE(VALUES(firmware_version),firmware_version),
       capabilities_json=COALESCE(VALUES(capabilities_json),capabilities_json),
       state_json=COALESCE(VALUES(state_json),state_json),
       last_reported_at=NOW()'
  );
  $stmt->execute([(int)$device['id'],$firmware !== '' ? $firmware : null,$capJson,$stateJson]);
  $pdo->prepare('UPDATE devices SET last_seen_at=NOW() WHERE id=?')->execute([(int)$device['id']]);
}

function command_event(PDO $pdo, int $commandId, string $type, ?array $payload=null): void {
  $json = $payload === null ? null : json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $pdo->prepare('INSERT INTO device_command_events(command_id,event_type,payload_json) VALUES(?,?,?)')
    ->execute([$commandId,$type,$json]);
}

function command_row_payload(array $row): array {
  $arguments = json_decode((string)$row['arguments_json'],true);
  return [
    'command_id'=>(string)$row['public_id'],
    'name'=>(string)$row['command_name'],
    'arguments'=>is_array($arguments)?$arguments:[],
    'source'=>(string)$row['source'],
    'status'=>(string)$row['status'],
    'created_at'=>(string)$row['created_at'],
    'expires_at'=>(string)$row['expires_at'],
  ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
$pdo = db();
$device = command_device($pdo);
$action = (string)($_GET['action'] ?? 'poll');
$data = request_data();

if ($action === 'heartbeat') {
  command_runtime_update($pdo,$device,$data);
  json_response(['ok'=>true,'server_time'=>date(DATE_ATOM)]);
}

if ($action === 'poll') {
  command_runtime_update($pdo,$device,$data);
  $pdo->beginTransaction();
  try {
    $expired = $pdo->prepare("SELECT id FROM device_commands WHERE device_id=? AND status IN ('queued','delivered','accepted','running') AND expires_at<NOW() FOR UPDATE");
    $expired->execute([(int)$device['id']]);
    foreach ($expired->fetchAll() as $row) {
      $pdo->prepare("UPDATE device_commands SET status='expired',completed_at=NOW(),error_message='命令在设备完成前已过期。' WHERE id=?")->execute([(int)$row['id']]);
      command_event($pdo,(int)$row['id'],'expired',['reason'=>'timeout']);
    }
    $stmt = $pdo->prepare("SELECT id,public_id,source,command_name,arguments_json,status,created_at,expires_at FROM device_commands WHERE device_id=? AND status IN ('queued','delivered') AND expires_at>=NOW() ORDER BY id ASC LIMIT 1 FOR UPDATE");
    $stmt->execute([(int)$device['id']]);
    $command = $stmt->fetch();
    if (!$command) {
      $pdo->commit();
      command_poll_response(null,1500);
    }
    $arguments = json_decode((string)$command['arguments_json'],true);
    $argumentError = command_arguments_error(
      (string)$command['command_name'],
      is_array($arguments)?$arguments:[]
    );
    if ($argumentError !== null) {
      $pdo->prepare("UPDATE device_commands SET status='failed',completed_at=NOW(),error_message=? WHERE id=?")
        ->execute([$argumentError,(int)$command['id']]);
      command_event($pdo,(int)$command['id'],'failed',['code'=>'invalid_arguments']);
      $pdo->commit();
      command_poll_response(null,500);
    }
    if ($command['status'] === 'queued') {
      $pdo->prepare("UPDATE device_commands SET status='delivered',delivered_at=NOW() WHERE id=?")->execute([(int)$command['id']]);
      command_event($pdo,(int)$command['id'],'delivered');
      $command['status'] = 'delivered';
    }
    $pdo->commit();
    command_poll_response(command_row_payload($command),500);
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('device_commands poll: '.$error->getMessage());
    json_response(['ok'=>false,'error'=>'设备命令领取失败。'],500);
  }
}

$commandPublicId = mb_substr(trim((string)($data['command_id'] ?? '')),0,32);
if ($action !== 'report_local' && $commandPublicId === '') json_response(['ok'=>false,'error'=>'缺少命令 ID。'],422);

if ($action === 'ack') {
  $accepted = (bool)($data['accepted'] ?? false);
  $message = mb_substr(trim((string)($data['message'] ?? '')),0,500);
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare("SELECT id,status FROM device_commands WHERE public_id=? AND device_id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$commandPublicId,(int)$device['id']]);
    $command = $stmt->fetch();
    if (!$command) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'命令不存在或不属于当前设备。'],404); }
    if (in_array($command['status'],['succeeded','failed','expired','cancelled'],true)) {
      $pdo->commit();
      json_response(['ok'=>true,'status'=>$command['status'],'duplicate'=>true]);
    }
    if ($accepted && in_array($command['status'],['accepted','running'],true)) {
      $pdo->commit();
      json_response(['ok'=>true,'status'=>$command['status'],'duplicate'=>true]);
    }
    $status = $accepted ? 'accepted' : 'failed';
    if ($accepted) {
      $pdo->prepare("UPDATE device_commands SET status='accepted',acknowledged_at=NOW() WHERE id=?")
        ->execute([(int)$command['id']]);
    } else {
      $pdo->prepare("UPDATE device_commands SET status='failed',acknowledged_at=NOW(),completed_at=NOW(),error_message=? WHERE id=?")
        ->execute([$message !== '' ? $message : '设备拒绝执行该命令。',(int)$command['id']]);
    }
    command_event($pdo,(int)$command['id'],$status,$message !== '' ? ['message'=>$message] : null);
    $pdo->commit();
    json_response(['ok'=>true,'status'=>$status]);
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
  }
}

if ($action === 'result') {
  $status = (string)($data['status'] ?? '');
  if (!in_array($status,['running','succeeded','failed'],true)) json_response(['ok'=>false,'error'=>'命令结果状态无效。'],422);
  $result = command_object($data,'result',2048);
  $errorMessage = command_error_text($data['error'] ?? '');
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare('SELECT id,status,command_name FROM device_commands WHERE public_id=? AND device_id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$commandPublicId,(int)$device['id']]);
    $command = $stmt->fetch();
    if (!$command) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'命令不存在或不属于当前设备。'],404); }
    if (in_array($command['status'],['succeeded','failed','expired','cancelled'],true)) {
      $pdo->commit();
      json_response(['ok'=>true,'status'=>$command['status'],'duplicate'=>true]);
    }
    if ((string)$command['command_name']==='set_light_power' && $status==='succeeded') {
      if (!is_array($result) || !array_key_exists('light_power',$result) || !is_bool($result['light_power'])) {
        $pdo->rollBack();
        json_response(['ok'=>false,'error'=>'灯光成功结果必须包含布尔值 light_power。'],422);
      }
    }
    $resultJson = $result === null ? null : json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($status === 'running') {
      $pdo->prepare("UPDATE device_commands SET status='running',started_at=COALESCE(started_at,NOW()),result_json=? WHERE id=?")
        ->execute([$resultJson,(int)$command['id']]);
    } else {
      $pdo->prepare('UPDATE device_commands SET status=?,result_json=?,error_message=?,completed_at=NOW() WHERE id=?')
        ->execute([$status,$resultJson,$status === 'failed' ? ($errorMessage ?: '设备执行失败。') : null,(int)$command['id']]);
    }
    if ((string)$command['command_name']==='set_light_power' && $status==='succeeded' && is_array($result)) {
      command_update_light_state($pdo,(int)$device['id'],$result);
    }
    command_event($pdo,(int)$command['id'],$status,$result);
    $pdo->commit();
    json_response(['ok'=>true,'status'=>$status]);
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
  }
}

if ($action === 'report_local') {
  $name = trim((string)($data['name'] ?? ''));
  if (!in_array($name,DEVICE_AI_COMMANDS,true)) json_response(['ok'=>false,'error'=>'本地 AI 命令名称无效。'],422);
  $arguments = command_object($data,'arguments',DEVICE_COMMAND_ARGUMENTS_MAX_BYTES) ?? [];
  $argumentError = command_arguments_error($name,$arguments);
  if ($argumentError !== null) json_response(['ok'=>false,'error'=>$argumentError],422);
  $result = command_object($data,'result');
  $status = (string)($data['status'] ?? 'succeeded');
  if (!in_array($status,['succeeded','failed'],true)) json_response(['ok'=>false,'error'=>'本地 AI 结果状态无效。'],422);
  if ($name==='set_light_power' && $status==='succeeded') {
    if (!is_array($result) || !array_key_exists('light_power',$result) || !is_bool($result['light_power'])) {
      json_response(['ok'=>false,'error'=>'灯光成功结果必须包含布尔值 light_power。'],422);
    }
  }
  $publicId = public_id();
  $stmt = $pdo->prepare("INSERT INTO device_commands(public_id,user_id,device_id,source,command_name,arguments_json,status,result_json,error_message,expires_at,acknowledged_at,started_at,completed_at) VALUES(?,?,?,'local_ai',?,?,?,?,?,NOW(),NOW(),NOW(),NOW())");
  $stmt->execute([
    $publicId,(int)$device['user_id'],(int)$device['id'],$name,
    json_encode($arguments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    $status,$result === null ? null : json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    $status === 'failed' ? mb_substr(trim((string)($data['error'] ?? '设备本地执行失败。')),0,500) : null,
  ]);
  command_event($pdo,(int)$pdo->lastInsertId(),$status,$result);
  if ($name==='set_light_power' && $status==='succeeded' && is_array($result)) {
    command_update_light_state($pdo,(int)$device['id'],$result);
  }
  command_runtime_update($pdo,$device,$data);
  json_response(['ok'=>true,'command_id'=>$publicId,'status'=>$status],201);
}

json_response(['ok'=>false,'error'=>'设备命令接口不存在。'],404);
