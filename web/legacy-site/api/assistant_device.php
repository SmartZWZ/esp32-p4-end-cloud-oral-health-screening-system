<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/assistant_memory_common.php';

function device_ai_default_instructions(): string {
  return '你是齿镜设备上的语音助手。请使用简洁、友好的中文回答。用户泛指检查报告、检测报告或口腔情况时，先确认成员并优先读取最近已完成的“口腔综合报告”；成员不明确必须询问。最新报告处理中时说明进度并询问是否查看上一份；没有综合报告时再读取旧版 AI 牙医报告，两者都没有时建议网页生成且不得自动生成。答复包含日期、风险、摘要、最多三条建议、照片数和免责声明。只有用户明确提到模型时才引用模型结果。仅当用户当前轮明确提出时才调用受限设备控制工具；开灯或关灯使用摄像头补光灯工具，不得与屏幕亮度混淆，含义不清时先询问。口腔内容仅用于辅助筛查，不替代诊断。';
}

function device_ai_require_gateway(): void {
  $provided = trim((string)(header_value('X-AI-Gateway-Secret') ?? ''));
  $config = ai_runtime_config();
  $expected = trim((string)($config['AI_GATEWAY_SECRET'] ?? ''));
  if ($provided === '' || $expected === '' || !hash_equals($expected,$provided)) {
    json_response(['ok'=>false,'error'=>'AI 网关身份验证失败。'],401);
  }
}

function device_ai_gateway_conversation(PDO $pdo, string $conversationId, string $devicePublicId): array {
  $stmt = $pdo->prepare('SELECT id,public_id,device_public_id FROM ai_device_conversations WHERE public_id=? LIMIT 1');
  $stmt->execute([$conversationId]);
  $conversation = $stmt->fetch();
  if (!$conversation || !hash_equals((string)$conversation['device_public_id'],$devicePublicId)) {
    json_response(['ok'=>false,'error'=>'未找到对应的设备对话。'],404);
  }
  return $conversation;
}

function device_ai_require_device(PDO $pdo): array {
  $token = trim((string)(header_value('X-Device-Token') ?? ''));
  if ($token === '') json_response(['ok'=>false,'error'=>'缺少设备令牌。'],401);
  $stmt = $pdo->prepare('SELECT id,public_id,user_id,device_uid,display_name FROM devices WHERE token_hash=? LIMIT 1');
  $stmt->execute([hash('sha256',$token)]);
  $device = $stmt->fetch();
  if (!$device) json_response(['ok'=>false,'error'=>'设备未绑定或设备令牌无效。'],401);
  return $device;
}

function device_ai_conversation(PDO $pdo, array $device, string $conversationId): array {
  $stmt = $pdo->prepare(
    'SELECT id,public_id,user_id,device_public_id,device_uid,device_name,title,instructions,
     memory_enabled,summary_text,summary_updated_at,context_status,context_message_count,
     last_message_at,is_active,created_at,updated_at
     FROM ai_device_conversations WHERE public_id=? AND user_id=? AND device_public_id=? LIMIT 1'
  );
  $stmt->execute([$conversationId,(int)$device['user_id'],(string)$device['public_id']]);
  $conversation = $stmt->fetch();
  if (!$conversation) json_response(['ok'=>false,'error'=>'未找到该设备对话，或该对话不属于当前设备。'],404);
  return $conversation;
}

function device_ai_new_conversation(PDO $pdo, array $device): array {
  $id = public_id();
  $pdo->prepare('UPDATE ai_device_conversations SET is_active=0 WHERE user_id=? AND device_public_id=?')
    ->execute([(int)$device['user_id'],(string)$device['public_id']]);
  $stmt = $pdo->prepare('INSERT INTO ai_device_conversations (public_id,user_id,device_id,device_public_id,device_uid,device_name,title,instructions) VALUES (?,?,?,?,?,?,?,?)');
  $stmt->execute([$id,(int)$device['user_id'],(int)$device['id'],(string)$device['public_id'],(string)$device['device_uid'],(string)$device['display_name'],'设备语音对话',device_ai_default_instructions()]);
  return device_ai_conversation($pdo,$device,$id);
}

function device_ai_active_conversation(PDO $pdo, array $device, string $requested, int $idleMinutes): array {
  $stmt = $pdo->prepare(
    'SELECT public_id,COALESCE(last_message_at,created_at) AS activity_at
     FROM ai_device_conversations
     WHERE user_id=? AND device_public_id=? AND is_active=1
     ORDER BY id DESC LIMIT 1'
  );
  $stmt->execute([(int)$device['user_id'],(string)$device['public_id']]);
  $active = $stmt->fetch();
  if ($active) {
    $activity = strtotime((string)$active['activity_at']) ?: 0;
    $notExpired = $activity >= time() - ($idleMinutes * 60);
    if ($notExpired) return device_ai_conversation($pdo,$device,(string)$active['public_id']);
    $pdo->prepare('UPDATE ai_device_conversations SET is_active=0 WHERE user_id=? AND device_public_id=?')
      ->execute([(int)$device['user_id'],(string)$device['public_id']]);
  }
  if ($requested !== '') {
    $stmt = $pdo->prepare(
      'SELECT public_id,COALESCE(last_message_at,created_at) AS activity_at
       FROM ai_device_conversations
       WHERE public_id=? AND user_id=? AND device_public_id=? LIMIT 1'
    );
    $stmt->execute([$requested,(int)$device['user_id'],(string)$device['public_id']]);
    $row = $stmt->fetch();
    if ($row && (strtotime((string)$row['activity_at']) ?: 0) >= time() - ($idleMinutes * 60)) {
      $pdo->prepare('UPDATE ai_device_conversations SET is_active=1 WHERE public_id=?')->execute([$requested]);
      return device_ai_conversation($pdo,$device,$requested);
    }
  }
  return device_ai_new_conversation($pdo,$device);
}

function device_ai_ticket(array $device, array $conversation): array {
  $config = ai_runtime_config();
  // Keep the device ticket compact.  ESP32 firmware accepts at most 1023
  // bytes, so prompts and conversation context must stay server-side.
  $payload = [
    's' => (string)$device['user_id'],
    'k' => 'd',
    'd' => (string)$device['public_id'],
    'c' => (string)$conversation['public_id'],
    'e' => time() + 90,
    'n' => rtrim(strtr(base64_encode(random_bytes(12)),'+/','-_'),'='),
  ];
  $encoded = rtrim(strtr(base64_encode((string)json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),'+/','-_'),'=');
  $ticket = $encoded . '.' . hash_hmac('sha256',$encoded,(string)$config['AI_GATEWAY_SECRET']);
  $ticketLength = strlen($ticket);
  if ($ticketLength < 1 || $ticketLength > 1000) {
    error_log('device_ai_ticket invalid length=' . $ticketLength);
    throw new RuntimeException('设备语音票据生成失败。');
  }
  error_log(
    'device_ai_ticket created length=' . $ticketLength .
    ' ttl=90 fingerprint=' . substr(hash('sha256',$ticket),0,10)
  );
  return [
    'ticket' => $ticket,
    'ticket_length' => $ticketLength,
    'expires_at' => $payload['e'],
    'path' => rtrim((string)$config['AI_GATEWAY_PUBLIC_PATH'],'/') . '/device/',
  ];
}

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  $pdo = db();
  $action = (string)($_GET['action'] ?? '');
  $data = request_data();

  if ($action === 'gateway_save_message') {
    device_ai_require_gateway();
    $conversationId = trim((string)($data['conversation_id'] ?? ''));
    $devicePublicId = trim((string)($data['device_public_id'] ?? ''));
    $role = (string)($data['role'] ?? '');
    $content = trim((string)($data['content'] ?? ''));
    $sourceEventId = trim((string)($data['source_event_id'] ?? ''));
    if ($conversationId === '' || $devicePublicId === '') json_response(['ok'=>false,'error'=>'缺少设备对话标识。'],422);
    if (!in_array($role,['user','assistant'],true)) json_response(['ok'=>false,'error'=>'无效的消息角色。'],422);
    if ($content === '' || mb_strlen($content,'UTF-8') > 8000) json_response(['ok'=>false,'error'=>'消息内容需为 1—8000 个字符。'],422);
    if ($sourceEventId === '' || strlen($sourceEventId) > 240) json_response(['ok'=>false,'error'=>'缺少有效的上游事件标识。'],422);
    $conversation = device_ai_gateway_conversation($pdo,$conversationId,$devicePublicId);
    // A deterministic public_id makes provider-event retries idempotent without
    // requiring another database migration.
    $messagePublicId = 'ag' . substr(hash('sha256',$conversationId.'|'.$role.'|'.$sourceEventId),0,30);
    $stmt = $pdo->prepare('INSERT INTO ai_device_messages (public_id,conversation_id,role,content) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role),content=VALUES(content)');
    $stmt->execute([$messagePublicId,(int)$conversation['id'],$role,$content]);
    $pdo->prepare('UPDATE ai_device_conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$conversation['id']]);
    json_response(['ok'=>true,'message_id'=>$messagePublicId]);
  }

  $device = device_ai_require_device($pdo);

  if ($action === 'session') {
    $requested = trim((string)($data['conversation_id'] ?? ''));
    $settings = assistant_memory_settings($pdo);
    $conversation = device_ai_active_conversation($pdo,$device,$requested,(int)$settings['device_idle_minutes']);
    $conversationChanged = $requested !== (string)$conversation['public_id'];
    $pdo->prepare('UPDATE devices SET last_seen_at=NOW() WHERE id=?')->execute([(int)$device['id']]);
    $gateway=device_ai_ticket($device,$conversation);
    json_response(['ok'=>true,
      // Keep the device response deliberately flat and small. ESP32 firmware
      // commonly uses a fixed JSON document and only needs these fields before
      // opening the WebSocket.
      'ticket'=>$gateway['ticket'],'ticket_length'=>$gateway['ticket_length'],
      'ticket_expires_at'=>$gateway['expires_at'],'gateway_path'=>$gateway['path'],
      'conversation_id'=>$conversation['public_id'],
      'conversation_changed'=>$conversationChanged,
      'memory_enabled'=>(bool)$conversation['memory_enabled'],
      'idle_timeout_minutes'=>(int)$settings['device_idle_minutes'],
      'input_format'=>'opus','input_sample_rate'=>16000,'input_channels'=>1,'input_frame_duration_ms'=>60,'input_bitrate'=>24000,
      'output_format'=>'opus','output_sample_rate'=>24000,'output_channels'=>1,'output_frame_duration_ms'=>20
    ]);
  }

  if ($action === 'save_message') {
    $conversation = device_ai_conversation($pdo,$device,trim((string)($data['conversation_id'] ?? '')));
    $role = (string)($data['role'] ?? '');
    $content = trim((string)($data['content'] ?? ''));
    if (!in_array($role,['user','assistant'],true)) json_response(['ok'=>false,'error'=>'无效的消息角色。'],422);
    if ($content === '' || mb_strlen($content,'UTF-8') > 8000) json_response(['ok'=>false,'error'=>'消息内容需为 1—8000 个字符。'],422);
    $stmt = $pdo->prepare('INSERT INTO ai_device_messages (public_id,conversation_id,role,content) VALUES (?,?,?,?)');
    $stmt->execute([public_id(),(int)$conversation['id'],$role,$content]);
    $pdo->prepare('UPDATE ai_device_conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$conversation['id']]);
    json_response(['ok'=>true]);
  }
  json_response(['ok'=>false,'error'=>'接口不存在。'],404);
} catch (Throwable $error) {
  error_log('assistant_device.php: ' . $error->getMessage());
  json_response(['ok'=>false,'error'=>'设备语音助手暂不可用，请检查服务器配置和日志。'],503);
}
