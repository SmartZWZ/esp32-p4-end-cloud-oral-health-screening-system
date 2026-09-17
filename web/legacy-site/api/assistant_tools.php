<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function ai_tools_require_gateway(): void {
  $provided = trim((string)(header_value('X-AI-Gateway-Secret') ?? ''));
  $runtime = ai_runtime_config();
  $expected = trim((string)($runtime['AI_GATEWAY_SECRET'] ?? ''));
  if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
    json_response(['ok' => false, 'error' => 'AI 网关身份验证失败。'], 401);
  }
}

function ai_tools_user(PDO $pdo, array $data): array {
  $kind = (string)($data['client_kind'] ?? 'web');
  $userRef = trim((string)($data['user_ref'] ?? ''));
  if ($userRef === '') json_response(['ok' => false, 'error' => '工具票据缺少用户标识。'], 401);

  if ($kind === 'device') {
    if (!ctype_digit($userRef)) json_response(['ok' => false, 'error' => '设备工具票据的用户标识无效。'], 401);
    $devicePublicId = trim((string)($data['device_public_id'] ?? ''));
    if ($devicePublicId === '') json_response(['ok' => false, 'error' => '设备工具票据缺少设备标识。'], 401);
    $stmt = $pdo->prepare('SELECT u.id,u.public_id,u.nickname,d.id AS ticket_device_id,d.public_id AS ticket_device_public_id FROM users u INNER JOIN devices d ON d.user_id=u.id WHERE u.id=? AND u.status=1 AND d.public_id=? LIMIT 1');
    $stmt->execute([(int)$userRef, mb_substr($devicePublicId, 0, 32)]);
  } elseif ($kind === 'web') {
    $stmt = $pdo->prepare('SELECT id,public_id,nickname,NULL AS ticket_device_id,NULL AS ticket_device_public_id FROM users WHERE public_id=? AND status=1 LIMIT 1');
    $stmt->execute([mb_substr($userRef, 0, 32)]);
  } else {
    json_response(['ok' => false, 'error' => '工具客户端类型无效。'], 401);
  }
  $user = $stmt->fetch();
  if (!$user) json_response(['ok' => false, 'error' => '工具票据对应的账号或设备不存在。'], 401);
  return $user;
}

function ai_tools_limit(array $args, string $key='limit', int $default=5, int $maximum=10): int {
  return min(max((int)($args[$key] ?? $default), 1), $maximum);
}

function ai_tools_member(PDO $pdo, int $userId, array $args): array {
  $memberReference = trim((string)($args['member_id'] ?? $args['member_name'] ?? ''));
  if ($memberReference === '') json_response(['ok' => false, 'error' => '请先选择家庭成员。'], 422);
  $stmt = $pdo->prepare("SELECT id,public_id,name,relationship,gender,birth_date,is_default FROM family_members WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");
  $stmt->execute([mb_substr($memberReference, 0, 32), $userId]);
  $member = $stmt->fetch();
  if (!$member) {
    $stmt = $pdo->prepare(
      "SELECT id,public_id,name,relationship,gender,birth_date,is_default
       FROM family_members
       WHERE user_id=? AND status='active' AND LOWER(TRIM(name))=LOWER(TRIM(?))
       ORDER BY is_default DESC,id ASC LIMIT 2"
    );
    $stmt->execute([$userId,mb_substr($memberReference,0,64)]);
    $matches = $stmt->fetchAll();
    if (count($matches) > 1) {
      json_response(['ok'=>false,'error'=>'账号下存在同名成员，请先调用成员列表并使用 member_id。'],409);
    }
    $member = $matches[0] ?? null;
  }
  if (!$member) {
    json_response(['ok'=>false,'error'=>'未找到成员“'.mb_substr($memberReference,0,40).'”。请先调用成员列表确认姓名或 member_id。'],404);
  }
  return $member;
}

function ai_tools_device(PDO $pdo, array $user, array $data, array $args): array {
  $userId = (int)$user['id'];
  $ticketDeviceId = (int)($user['ticket_device_id'] ?? 0);
  if ((string)($data['client_kind'] ?? 'web') === 'device') {
    if ($ticketDeviceId <= 0) json_response(['ok'=>false,'error'=>'设备助手票据没有关联设备。'],403);
    $stmt = $pdo->prepare(
      'SELECT d.id,d.public_id,d.display_name,d.last_seen_at,
       rs.capabilities_json,rs.state_json,rs.last_reported_at
       FROM devices d LEFT JOIN device_runtime_states rs ON rs.device_id=d.id
       WHERE d.id=? AND d.user_id=? LIMIT 1'
    );
    $stmt->execute([$ticketDeviceId,$userId]);
  } else {
    $requested = mb_substr(trim((string)($args['device_id'] ?? '')),0,32);
    if ($requested !== '') {
      $stmt = $pdo->prepare(
        'SELECT d.id,d.public_id,d.display_name,d.last_seen_at,
         rs.capabilities_json,rs.state_json,rs.last_reported_at
         FROM devices d LEFT JOIN device_runtime_states rs ON rs.device_id=d.id
         WHERE d.public_id=? AND d.user_id=? LIMIT 1'
      );
      $stmt->execute([$requested,$userId]);
    } else {
      $stmt = $pdo->prepare(
        'SELECT d.id,d.public_id,d.display_name,d.last_seen_at,
         rs.capabilities_json,rs.state_json,rs.last_reported_at
         FROM devices d LEFT JOIN device_runtime_states rs ON rs.device_id=d.id
         WHERE d.user_id=? ORDER BY d.id ASC LIMIT 2'
      );
      $stmt->execute([$userId]);
      $devices = $stmt->fetchAll();
      if (count($devices) === 0) json_response(['ok'=>false,'error'=>'当前账号没有已绑定设备。'],404);
      if (count($devices) > 1) json_response(['ok'=>false,'error'=>'当前账号有多台设备，请先通过 get_devices_status 确定 device_id。'],422);
      return $devices[0];
    }
  }
  $device = $stmt->fetch();
  if (!$device) json_response(['ok'=>false,'error'=>'设备不存在或不属于当前账号。'],404);
  return $device;
}

function ai_tools_command_result(PDO $pdo, int $userId, string $publicId): ?array {
  $stmt = $pdo->prepare(
    'SELECT public_id,status,result_json,error_message,expires_at,completed_at
     FROM device_commands WHERE public_id=? AND user_id=? LIMIT 1'
  );
  $stmt->execute([$publicId,$userId]);
  $row = $stmt->fetch();
  if (!$row) return null;
  return [
    'command_id'=>(string)$row['public_id'],
    'status'=>(string)$row['status'],
    'result'=>ai_tools_json($row['result_json']),
    'error'=>$row['error_message'],
    'expires_at'=>$row['expires_at'],
    'completed_at'=>$row['completed_at'],
  ];
}

function ai_tools_wait_light_result(PDO $pdo, int $userId, string $publicId, float $seconds=9.0): array {
  $deadline = microtime(true) + min(max($seconds,0.0),9.0);
  $last = null;
  do {
    $last = ai_tools_command_result($pdo,$userId,$publicId);
    if ($last && in_array((string)$last['status'],['succeeded','failed','expired','cancelled'],true)) {
      $last['message'] = $last['status']==='succeeded'
        ? '设备已经回报灯光操作成功。'
        : '设备没有完成灯光操作。';
      return $last;
    }
    if (microtime(true) >= $deadline) break;
    usleep(250000);
  } while (true);
  return [
    'command_id'=>$publicId,
    'status'=>(string)($last['status']??'queued'),
    'result'=>$last['result']??null,
    'error'=>$last['error']??null,
    'expires_at'=>$last['expires_at']??null,
    'completed_at'=>$last['completed_at']??null,
    'message'=>'灯光指令已发送，但尚未收到设备的最终执行结果。',
  ];
}

function ai_tools_enqueue_command(PDO $pdo, array $user, array $data, string $tool, array $arguments): array {
  $device = ai_tools_device($pdo,$user,$data,$arguments);
  $commandArguments = [];
  $commandName = $tool;
  if (in_array($tool,['select_active_member','capture_and_archive','capture_and_analyze','capture_and_local_analyze'],true)) {
    $member = ai_tools_member($pdo,(int)$user['id'],$arguments);
    $commandArguments['member_id'] = (string)$member['public_id'];
    $commandArguments['member_name'] = (string)$member['name'];
  }
  if ($tool === 'capture_and_analyze') {
    $pipeline = strtolower(trim((string)($arguments['model_pipeline'] ?? 'caries')));
    if (!in_array($pipeline,['caries','both','dental_seg','calculus_seg','tooth_outline'],true)) json_response(['ok'=>false,'error'=>'云端分析模型参数无效。'],422);
    $commandArguments['model_pipeline'] = $pipeline;
  } elseif ($tool === 'capture_and_local_analyze') {
    $model = strtolower(trim((string)($arguments['local_model'] ?? 'dental')));
    if (!in_array($model,['dental','risk'],true)) json_response(['ok'=>false,'error'=>'本地分析模型参数无效。'],422);
    $commandArguments['local_model'] = $model;
  } elseif ($tool === 'set_speaker_volume') {
    if (!array_key_exists('level',$arguments)) json_response(['ok'=>false,'error'=>'缺少音量参数。'],422);
    $level = (int)$arguments['level'];
    if ($level < 0 || $level > 100) json_response(['ok'=>false,'error'=>'音量必须在 0—100 之间。'],422);
    $commandArguments['level'] = $level;
  } elseif ($tool === 'set_screen_brightness') {
    if (!array_key_exists('level',$arguments)) json_response(['ok'=>false,'error'=>'缺少屏幕亮度参数。'],422);
    $level = (int)$arguments['level'];
    if ($level < 10 || $level > 100) json_response(['ok'=>false,'error'=>'屏幕亮度必须在 10—100 之间。'],422);
    $commandArguments['level'] = $level;
  } elseif ($tool === 'set_camera_light') {
    $mode = strtolower(trim((string)($arguments['mode'] ?? '')));
    if (!in_array($mode,['on','off','auto'],true)) {
      json_response(['ok'=>false,'error'=>'摄像头灯模式必须是 on、off 或 auto。'],422);
    }
    $commandArguments['mode'] = $mode;
  } elseif ($tool === 'set_light_power') {
    if (!array_key_exists('enabled',$arguments) || !is_bool($arguments['enabled'])) {
      json_response(['ok'=>false,'error'=>'补光灯 enabled 必须是 JSON 布尔值。'],422);
    }
    $capabilities = ai_tools_json($device['capabilities_json'] ?? null);
    if (!is_array($capabilities) || ($capabilities['set_light_power'] ?? null) !== true) {
      json_response(['ok'=>false,'error'=>'该设备尚未报告 set_light_power 能力，不能下发补光灯命令。'],409);
    }
    $commandArguments['enabled'] = $arguments['enabled'];
  }

  $callId = mb_substr(trim((string)($data['call_id'] ?? '')),0,160);
  if ($callId !== '') {
    $stmt = $pdo->prepare('SELECT public_id,status,expires_at FROM device_commands WHERE user_id=? AND tool_call_id=? LIMIT 1');
    $stmt->execute([(int)$user['id'],$callId]);
    $existing = $stmt->fetch();
    if ($existing) {
      if ($tool === 'set_light_power') {
        return ai_tools_wait_light_result($pdo,(int)$user['id'],(string)$existing['public_id']);
      }
      return [
        'command_id'=>$existing['public_id'],'status'=>$existing['status'],
        'expires_at'=>$existing['expires_at'],'duplicate'=>true,
        'message'=>'该设备命令已创建，不会重复下发。',
      ];
    }
  }

  $source = (string)($data['client_kind'] ?? 'web') === 'device' ? 'device_ai' : 'web_ai';
  $publicId = public_id();
  $ttl = in_array($tool,['capture_and_archive','capture_and_analyze','capture_and_local_analyze'],true) ? 120 : 60;
  $argumentsJson = json_encode($commandArguments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($argumentsJson === false || strlen($argumentsJson) > 512) {
    json_response(['ok'=>false,'error'=>'设备命令参数超过 512 字节安全上限。'],422);
  }
  $stmt = $pdo->prepare("INSERT INTO device_commands(public_id,user_id,device_id,conversation_id,tool_call_id,source,command_name,arguments_json,status,expires_at) VALUES(?,?,?,?,?,?,?,?,'queued',DATE_ADD(NOW(),INTERVAL {$ttl} SECOND))");
  $stmt->execute([
    $publicId,(int)$user['id'],(int)$device['id'],
    mb_substr(trim((string)($data['conversation_id'] ?? '')),0,32) ?: null,
    $callId !== '' ? $callId : null,$source,$commandName,$argumentsJson,
  ]);
  $commandId = (int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO device_command_events(command_id,event_type,payload_json) VALUES(?,'queued',?)")
    ->execute([$commandId,$argumentsJson]);
  $online = $device['last_seen_at'] !== null && strtotime((string)$device['last_seen_at']) >= time()-120;
  if ($tool === 'set_light_power' && $online) {
    return ai_tools_wait_light_result($pdo,(int)$user['id'],$publicId);
  }
  return [
    'command_id'=>$publicId,
    'status'=>'queued',
    'device'=>[
      'device_id'=>$device['public_id'],
      'name'=>$device['display_name'],
      'is_online'=>$online,
      'last_seen_at'=>$device['last_seen_at'],
    ],
    'arguments'=>$commandArguments,
    'expires_in_seconds'=>$ttl,
    'message'=>$online
      ? '命令已进入队列，等待设备领取并执行。'
      : '命令已进入队列，但设备当前可能离线；若未及时上线，命令会自动过期。',
  ];
}

function ai_tools_json(?string $value): mixed {
  if ($value === null || trim($value) === '') return null;
  $decoded = json_decode($value, true);
  return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function ai_tools_detection_item(array $row): array {
  $item = [
    'detection_id' => (string)$row['public_id'],
    'member_id' => (string)$row['member_public_id'],
    'member_name' => (string)$row['member_name'],
    'upload_mode' => (string)$row['upload_mode'],
    'model_pipeline' => (string)$row['model_pipeline'],
    'status' => (string)$row['status'],
    'report' => $row['report_text'],
    'image_width' => $row['image_width'] === null ? null : (int)$row['image_width'],
    'image_height' => $row['image_height'] === null ? null : (int)$row['image_height'],
    'image_bytes' => (int)$row['image_bytes'],
    'created_at' => (string)$row['created_at'],
    'updated_at' => (string)$row['updated_at'],
  ];
  if (array_key_exists('result_status',$row)) {
    $item['latest_model_result'] = [
      'status'=>$row['result_status'],
      'model_name'=>$row['result_model_name'] ?? null,
      'model_type'=>$row['result_model_type'] ?? null,
      'summary'=>$row['result_summary'] ?? null,
      'risk_level'=>$row['result_risk_level'] ?? null,
      'completed_at'=>$row['result_completed_at'] ?? null,
      'error'=>$row['result_error'] ?? null,
    ];
  }
  return $item;
}

function ai_tools_audit(PDO $pdo, int $userId, array $data, string $tool, array $arguments, bool $success, ?string $error, int $elapsedMs): void {
  try {
    $stmt = $pdo->prepare('INSERT INTO ai_tool_audit_logs(public_id,user_id,client_kind,device_public_id,conversation_id,call_id,tool_name,arguments_json,success,error_message,elapsed_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $argumentsJson = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt->execute([
      public_id(), $userId, (string)($data['client_kind'] ?? 'web'),
      mb_substr(trim((string)($data['device_public_id'] ?? '')), 0, 32) ?: null,
      mb_substr(trim((string)($data['conversation_id'] ?? '')), 0, 32) ?: null,
      mb_substr(trim((string)($data['call_id'] ?? '')), 0, 160) ?: null,
      mb_substr($tool, 0, 64), $argumentsJson === false ? '{}' : $argumentsJson,
      $success ? 1 : 0, $error === null ? null : mb_substr($error, 0, 500), max(0, $elapsedMs)
    ]);
  } catch (Throwable $auditError) {
    error_log('assistant_tools audit failed: ' . $auditError->getMessage());
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);
ai_tools_require_gateway();
$started = microtime(true);
$pdo = db();
$data = request_data();
$tool = trim((string)($data['tool'] ?? ''));
$arguments = $data['arguments'] ?? [];
if (!is_array($arguments)) json_response(['ok' => false, 'error' => '工具参数格式无效。'], 422);
$controlTools = ['select_active_member','capture_and_archive','capture_and_analyze','capture_and_local_analyze','set_speaker_volume','set_screen_brightness','set_camera_light','set_light_power'];
$allowed = ['get_server_time','list_family_members','get_recent_detections','get_detection_detail','get_recent_images','analyze_saved_image_with_all_models','summarize_detection_history','get_recent_family_reports','get_family_report_detail','get_recent_ai_dentist_reports','get_devices_status',...$controlTools];
if (!in_array($tool, $allowed, true)) json_response(['ok' => false, 'error' => '该工具未获授权。'], 403);
$user = ai_tools_user($pdo, $data);
$userId = (int)$user['id'];

try {
  $result = null;
  if ($tool === 'get_server_time') {
    $result = ['timezone' => date_default_timezone_get(), 'server_time' => date(DATE_ATOM)];
  } elseif ($tool === 'list_family_members') {
    $stmt = $pdo->prepare("SELECT public_id,name,relationship,gender,birth_date,is_default FROM family_members WHERE user_id=? AND status='active' ORDER BY is_default DESC,id ASC LIMIT 50");
    $stmt->execute([$userId]);
    $items = array_map(static fn(array $row): array => [
      'member_id' => (string)$row['public_id'],
      'name' => (string)$row['name'],
      'relationship' => (string)$row['relationship'],
      'gender' => (string)$row['gender'],
      'birth_date' => $row['birth_date'],
      'is_default' => (bool)$row['is_default'],
    ], $stmt->fetchAll());
    $result = ['count' => count($items), 'members' => $items];
  } elseif ($tool === 'get_recent_detections') {
    $member = ai_tools_member($pdo, $userId, $arguments);
    $limit = ai_tools_limit($arguments);
    $stmt = $pdo->prepare(
      "SELECT d.public_id,d.upload_mode,d.model_pipeline,d.status,d.report_text,
       d.image_width,d.image_height,d.image_bytes,d.created_at,d.updated_at,
       m.public_id AS member_public_id,m.name AS member_name,
       dr.status AS result_status,dr.model_name AS result_model_name,dr.model_type AS result_model_type,
       dr.summary_text AS result_summary,dr.risk_level AS result_risk_level,
       dr.completed_at AS result_completed_at,dr.error_message AS result_error
       FROM detections d
       INNER JOIN family_members m ON m.id=d.member_id
       LEFT JOIN detection_results dr ON dr.id=(
         SELECT dr2.id FROM detection_results dr2 WHERE dr2.detection_id=d.id ORDER BY dr2.id DESC LIMIT 1
       )
       WHERE d.user_id=? AND d.member_id=?
       AND (d.status<>'saved' OR dr.id IS NOT NULL)
       ORDER BY d.id DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId, (int)$member['id']]);
    $items = array_map('ai_tools_detection_item', $stmt->fetchAll());
    $result = ['member' => ['member_id' => $member['public_id'], 'name' => $member['name']], 'count' => count($items), 'detections' => $items];
  } elseif ($tool === 'get_detection_detail') {
    $detectionId = mb_substr(trim((string)($arguments['detection_id'] ?? '')), 0, 32);
    if ($detectionId === '') json_response(['ok' => false, 'error' => '缺少检测记录 ID。'], 422);
    $stmt = $pdo->prepare("SELECT d.id,d.public_id,d.upload_mode,d.model_pipeline,d.status,d.report_text,d.image_width,d.image_height,d.image_bytes,d.created_at,d.updated_at,m.public_id AS member_public_id,COALESCE(m.name,'未归属成员') AS member_name FROM detections d LEFT JOIN family_members m ON m.id=d.member_id WHERE d.public_id=? AND d.user_id=? LIMIT 1");
    $stmt->execute([$detectionId, $userId]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false, 'error' => '检测记录不存在或无权读取。'], 404);
    $stmt = $pdo->prepare('SELECT public_id,model_name,model_version,model_type,status,summary_text,risk_level,error_message,created_at,completed_at FROM detection_results WHERE detection_id=? ORDER BY id DESC LIMIT 10');
    $stmt->execute([(int)$row['id']]);
    $results = array_map(static fn(array $item): array => [
      'result_id' => $item['public_id'],
      'model_name' => $item['model_name'],
      'model_version' => $item['model_version'],
      'model_type' => $item['model_type'],
      'status' => $item['status'],
      'summary' => $item['summary_text'],
      'risk_level' => $item['risk_level'],
      'error' => $item['error_message'],
      'created_at' => $item['created_at'],
      'completed_at' => $item['completed_at'],
    ], $stmt->fetchAll());
    unset($row['id']);
    $result = ['detection' => ai_tools_detection_item($row), 'model_results' => $results];
  } elseif ($tool === 'get_recent_images') {
    $member = ai_tools_member($pdo, $userId, $arguments);
    $limit = ai_tools_limit($arguments);
    $uploadMode = (string)($arguments['upload_mode'] ?? 'all');
    if (!in_array($uploadMode, ['all','archive','detect'], true)) json_response(['ok' => false, 'error' => '图片类型参数无效。'], 422);
    $sql = "SELECT d.public_id,d.upload_mode,d.model_pipeline,d.status,d.report_text,d.image_width,d.image_height,d.image_bytes,d.created_at,d.updated_at,m.public_id AS member_public_id,m.name AS member_name FROM detections d INNER JOIN family_members m ON m.id=d.member_id WHERE d.user_id=? AND d.member_id=?";
    $params = [$userId, (int)$member['id']];
    if ($uploadMode !== 'all') { $sql .= ' AND d.upload_mode=?'; $params[] = $uploadMode; }
    $sql .= " ORDER BY d.id DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = array_map('ai_tools_detection_item', $stmt->fetchAll());
    $result = ['member' => ['member_id' => $member['public_id'], 'name' => $member['name']], 'filter' => $uploadMode, 'count' => count($items), 'images' => $items];
  } elseif ($tool === 'analyze_saved_image_with_all_models') {
    if ((string)($data['client_kind'] ?? 'web') !== 'web') {
      json_response(['ok' => false, 'error' => '全部模型联合分析仅允许网页助手发起。'], 403);
    }
    $detectionId = mb_substr(trim((string)($arguments['detection_id'] ?? '')), 0, 32);
    if ($detectionId === '') json_response(['ok' => false, 'error' => '缺少图片记录 ID。'], 422);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
      'SELECT id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,status
       FROM detections WHERE public_id=? AND user_id=? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$detectionId, $userId]);
    $source = $stmt->fetch();
    if (!$source) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => '图片记录不存在或无权分析。'], 404);
    }
    if (!image_file_path((string)$source['image_path'])) {
      $pdo->rollBack();
      json_response(['ok' => false, 'error' => '图片文件不存在，无法发起联合分析。'], 409);
    }
    $newPublicId = public_id();
    $insert = $pdo->prepare(
      "INSERT INTO detections(
        public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,
        upload_mode,model_pipeline,status,source_detection_id,progress_step,progress_total,progress_label,report_text
      ) VALUES(?,?,?,?,?,?,?,?,'detect','all_models','received',?,0,5,'等待运行全部模型联合分析。','已由网页 AI 助手加入全部模型联合分析队列。')"
    );
    $insert->execute([
      $newPublicId,
      $userId,
      $source['device_id'] === null ? null : (int)$source['device_id'],
      $source['member_id'] === null ? null : (int)$source['member_id'],
      (string)$source['image_path'],
      $source['image_width'],
      $source['image_height'],
      (int)$source['image_bytes'],
      (int)$source['id'],
    ]);
    $pdo->commit();
    $result = [
      'detection_id' => $newPublicId,
      'source_detection_id' => $detectionId,
      'model_pipeline' => 'all_models',
      'status' => 'received',
      'message' => '已创建新的历史记录并加入全部模型联合分析队列。可稍后查询该 detection_id 获取结果。',
    ];
  } elseif ($tool === 'summarize_detection_history') {
    $member = ai_tools_member($pdo, $userId, $arguments);
    $days = min(max((int)($arguments['days'] ?? 90), 7), 365);
    $stmt = $pdo->prepare("SELECT status,upload_mode,COUNT(*) AS total FROM detections WHERE user_id=? AND member_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY) GROUP BY status,upload_mode");
    $stmt->execute([$userId, (int)$member['id']]);
    $counts = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT d.public_id,d.status,d.report_text,d.created_at,dr.model_name,dr.risk_level,dr.summary_text FROM detections d LEFT JOIN detection_results dr ON dr.id=(SELECT dr2.id FROM detection_results dr2 WHERE dr2.detection_id=d.id ORDER BY dr2.id DESC LIMIT 1) WHERE d.user_id=? AND d.member_id=? AND d.created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY) ORDER BY d.id DESC LIMIT 10");
    $stmt->execute([$userId, (int)$member['id']]);
    $recent = $stmt->fetchAll();
    $result = [
      'member' => ['member_id' => $member['public_id'], 'name' => $member['name']],
      'period_days' => $days,
      'statistics' => $counts,
      'recent_reports' => $recent,
      'notice' => '这些内容来自既有齿镜记录，只用于口腔健康辅助筛查和趋势整理，不构成医学诊断。',
    ];
  } elseif ($tool === 'get_recent_family_reports') {
    $limit = ai_tools_limit($arguments,'limit',5,10);
    $member = ai_tools_member($pdo,$userId,$arguments);
    $includeModelResults = (bool)($arguments['include_model_results'] ?? false);
    $stmt = $pdo->prepare(
      "SELECT r.public_id,r.title,r.status,r.image_count,r.clinical_summary_json,r.model_summary_json,
       r.created_at,r.completed_at,m.public_id AS member_public_id,m.name AS member_name
       FROM family_reports r INNER JOIN family_members m ON m.id=r.member_id
       WHERE r.user_id=? AND r.member_id=? ORDER BY r.id DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId,(int)$member['id']]);
    $items = array_map(static function(array $row) use ($includeModelResults): array {
      $clinical = ai_tools_json($row['clinical_summary_json']);
      $recommendations = [];
      foreach (array_slice(is_array($clinical['recommendations']??null)?$clinical['recommendations']:[],0,3) as $item) {
        if (!is_array($item)) continue;
        $recommendations[] = [
          'priority'=>$item['priority']??'routine',
          'action'=>$item['action']??'',
          'reason'=>$item['reason']??'',
        ];
      }
      $item = [
        'report_id'=>$row['public_id'],'title'=>$row['title'],'status'=>$row['status'],
        'member_id'=>$row['member_public_id'],'member_name'=>$row['member_name'],
        'image_count'=>(int)$row['image_count'],
        'ai_independent_summary'=>is_array($clinical)?[
          'overall_risk'=>$clinical['overall_risk']??'unknown',
          'summary'=>$clinical['summary']??'',
          'recommendations'=>$recommendations,
          'disclaimer'=>$clinical['disclaimer']??'本报告仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。',
        ]:null,
        'created_at'=>$row['created_at'],'completed_at'=>$row['completed_at'],
      ];
      if ($includeModelResults) {
        $model = ai_tools_json($row['model_summary_json']);
        $item['experimental_model_summary'] = is_array($model)?($model['summary']??null):null;
      }
      return $item;
    },$stmt->fetchAll());
    $latestUsable = null;
    foreach ($items as $item) {
      if (in_array((string)$item['status'],['completed','partial'],true)) {
        $latestUsable = $item;
        break;
      }
    }
    $result = [
      'member'=>['member_id'=>$member['public_id'],'name'=>$member['name']],
      'count'=>count($items),
      'latest_report'=>$items[0]??null,
      'latest_completed_or_partial_report'=>$latestUsable,
      'reports'=>$items,
      'model_results_included'=>$includeModelResults,
      'response_rule'=>'若 latest_report 仍在处理中，请说明进度并询问用户是否查看 latest_completed_or_partial_report，不要未经询问直接切换；没有可用报告时再查询旧版 AI 牙医报告。',
      'separation_notice'=>'AI 独立观察与实验性本地模型附录是两条隔离链路；普通报告查询不应引用实验模型结果。',
    ];
  } elseif ($tool === 'get_family_report_detail') {
    $reportId = mb_substr(trim((string)($arguments['report_id'] ?? '')),0,32);
    $includeModelResults = (bool)($arguments['include_model_results'] ?? false);
    if ($reportId === '') json_response(['ok'=>false,'error'=>'缺少口腔综合报告 ID。'],422);
    $stmt = $pdo->prepare(
      'SELECT r.id,r.public_id,r.title,r.status,r.image_count,r.symptoms,r.clinical_summary_json,r.model_summary_json,
       r.created_at,r.completed_at,m.public_id AS member_public_id,m.name AS member_name
       FROM family_reports r INNER JOIN family_members m ON m.id=r.member_id
       WHERE r.public_id=? AND r.user_id=? LIMIT 1'
    );
    $stmt->execute([$reportId,$userId]);
    $report = $stmt->fetch();
    if (!$report) json_response(['ok'=>false,'error'=>'口腔综合报告不存在或无权读取。'],404);
    $stmt = $pdo->prepare(
      'SELECT ri.sort_order,ri.ai_status,ri.ai_report_json,ri.ai_error_message,ri.model_skipped,
       d.public_id AS image_id,a.status AS model_status,a.report_text AS model_report_text
       FROM family_report_images ri
       LEFT JOIN detections d ON d.id=ri.source_detection_id
       LEFT JOIN detections a ON a.id=ri.analysis_detection_id
       WHERE ri.report_id=? ORDER BY ri.sort_order ASC'
    );
    $stmt->execute([(int)$report['id']]);
    $images = array_map(static function(array $row) use ($includeModelResults): array {
      $single = ai_tools_json($row['ai_report_json']);
      $findings = [];
      foreach (array_slice(is_array($single['visible_findings']??null)?$single['visible_findings']:[],0,10) as $finding) {
        if (!is_array($finding)) continue;
        $findings[] = [
          'region'=>$finding['region']??'','finding'=>$finding['finding']??'',
          'risk'=>$finding['risk']??'unknown','needs_review'=>(bool)($finding['needs_review']??false),
        ];
      }
      $item = [
        'image_index'=>(int)$row['sort_order']+1,'image_id'=>$row['image_id'],
        'ai_status'=>$row['ai_status'],
        'ai_independent_observation'=>is_array($single)?[
          'overall_risk'=>$single['overall_risk']??'unknown','summary'=>$single['summary']??'',
          'visible_findings'=>$findings,
        ]:null,
        'ai_error'=>$row['ai_error_message'],
      ];
      if ($includeModelResults) {
        $item['experimental_model_status'] = (bool)$row['model_skipped']?'skipped':$row['model_status'];
        $item['experimental_model_record'] = $row['model_report_text'];
      }
      return $item;
    },$stmt->fetchAll());
    $clinicalSummary = ai_tools_json($report['clinical_summary_json']);
    $modelSummary = $includeModelResults?ai_tools_json($report['model_summary_json']):null;
    unset($report['id'],$report['clinical_summary_json'],$report['model_summary_json']);
    $result = [
      'report'=>$report,
      'ai_independent_summary'=>$clinicalSummary,
      'images'=>$images,
      'model_results_included'=>$includeModelResults,
      'separation_notice'=>'实验性模型输出不参与 AI 牙医原图观察或成员级判断；只有用户明确询问模型结果时才可引用。',
    ];
    if ($includeModelResults) $result['experimental_model_appendix'] = $modelSummary;
  } elseif ($tool === 'get_recent_ai_dentist_reports') {
    $member = ai_tools_member($pdo,$userId,$arguments);
    $limit = ai_tools_limit($arguments,'limit',3,5);
    $stmt = $pdo->prepare(
      "SELECT s.public_id,s.title,s.status,s.risk_level,s.summary,s.report_json,s.created_at,s.completed_at,
       (SELECT COUNT(*) FROM ai_dentist_session_images si WHERE si.session_id=s.id) AS image_count
       FROM ai_dentist_sessions s
       WHERE s.user_id=? AND s.member_id=?
       ORDER BY s.id DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId,(int)$member['id']]);
    $items = array_map(static function(array $row): array {
      $report = ai_tools_json($row['report_json']);
      $recommendations = [];
      foreach (array_slice(is_array($report['recommendations']??null)?$report['recommendations']:[],0,3) as $item) {
        if (!is_array($item)) continue;
        $recommendations[] = [
          'priority'=>$item['priority']??'routine',
          'action'=>$item['action']??'',
          'reason'=>$item['reason']??'',
        ];
      }
      return [
        'report_id'=>$row['public_id'],
        'title'=>$row['title'],
        'status'=>$row['status'],
        'overall_risk'=>$report['overall_risk']??$row['risk_level']??'unknown',
        'summary'=>$report['summary']??$row['summary']??'',
        'recommendations'=>$recommendations,
        'image_count'=>(int)$row['image_count'],
        'disclaimer'=>$report['disclaimer']??'本结果仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。',
        'created_at'=>$row['created_at'],
        'completed_at'=>$row['completed_at'],
      ];
    },$stmt->fetchAll());
    $latestCompleted = null;
    foreach ($items as $item) {
      if ((string)$item['status']==='completed') {
        $latestCompleted = $item;
        break;
      }
    }
    $result = [
      'member'=>['member_id'=>$member['public_id'],'name'=>$member['name']],
      'count'=>count($items),
      'latest_report'=>$items[0]??null,
      'latest_completed_report'=>$latestCompleted,
      'reports'=>$items,
      'source'=>'AI 牙医历史报告',
      'response_rule'=>'仅当该成员没有可用的口腔综合报告时，才用此结果作为普通报告查询的回退。',
    ];
  } elseif ($tool === 'get_devices_status') {
    $stmt = $pdo->prepare('SELECT d.public_id,d.display_name,d.last_seen_at,d.created_at,CASE WHEN d.last_seen_at IS NOT NULL AND d.last_seen_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) THEN 1 ELSE 0 END AS is_online,rs.firmware_version,rs.capabilities_json,rs.state_json,rs.last_reported_at FROM devices d LEFT JOIN device_runtime_states rs ON rs.device_id=d.id WHERE d.user_id=? ORDER BY d.id DESC LIMIT 20');
    $stmt->execute([$userId]);
    $items = array_map(static fn(array $row): array => [
      'device_id' => $row['public_id'],
      'name' => $row['display_name'],
      'is_online' => (bool)$row['is_online'],
      'last_seen_at' => $row['last_seen_at'],
      'bound_at' => $row['created_at'],
      'firmware_version' => $row['firmware_version'],
      'capabilities' => ai_tools_json($row['capabilities_json']),
      'state' => ai_tools_json($row['state_json']),
      'state_reported_at' => $row['last_reported_at'],
    ], $stmt->fetchAll());
    $result = ['count' => count($items), 'devices' => $items];
  } else {
    $result = ai_tools_enqueue_command($pdo,$user,$data,$tool,$arguments);
  }
  $elapsedMs = (int)round((microtime(true) - $started) * 1000);
  ai_tools_audit($pdo, $userId, $data, $tool, $arguments, true, null, $elapsedMs);
  json_response(['ok' => true, 'tool' => $tool, 'data' => $result]);
} catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $elapsedMs = (int)round((microtime(true) - $started) * 1000);
  ai_tools_audit($pdo, $userId, $data, $tool, $arguments, false, $error->getMessage(), $elapsedMs);
  error_log('assistant_tools.php: ' . $error->getMessage());
  json_response(['ok' => false, 'error' => '执行齿镜工具时发生服务器错误。'], 500);
}
