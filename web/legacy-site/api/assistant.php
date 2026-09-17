<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/assistant_memory_common.php';

function assistant_default_instructions(): string {
  return '你是齿镜的语音助手。请使用简洁、友善的中文回答。用户泛指检查报告、检测报告、最近报告或口腔情况时，先确认成员，再优先读取最近已完成的“口腔综合报告”；成员不明确必须询问，不得使用默认成员。最新报告处理中时说明进度并询问是否查看上一份。没有综合报告时再读取旧版 AI 牙医报告；两者都没有时建议用户前往对应页面生成，不得自动生成。答复包含日期、总体风险、摘要、最多三条建议、照片数和免责声明。只有用户明确说云端模型分析、模型检测、龋齿模型、牙结石模型或全部模型联合分析时才引用模型结果。仅在用户当前轮明确要求开灯或关灯时控制摄像头补光灯；不得与屏幕亮度混淆，含义不清时先询问。口腔健康内容只用于辅助筛查，不替代医生诊断。';
}
function assistant_new_conversation(PDO $pdo, array $user): array {
  $publicId = public_id();
  $stmt = $pdo->prepare('INSERT INTO ai_conversations (public_id,user_id,title,instructions) VALUES (?,?,?,?)');
  $stmt->execute([$publicId, (int)$user['id'], '新对话', assistant_default_instructions()]);
  return assistant_conversation($pdo, $user, $publicId);
}
function assistant_conversation(PDO $pdo, array $user, string $publicId): array {
  $stmt = $pdo->prepare(
    'SELECT id,public_id,title,instructions,memory_enabled,summary_text,summary_updated_at,
     context_status,context_message_count,last_message_at,created_at,updated_at
     FROM ai_conversations WHERE public_id=? AND user_id=? LIMIT 1'
  );
  $stmt->execute([$publicId, (int)$user['id']]);
  $row = $stmt->fetch();
  if (!$row) json_response(['ok'=>false,'error'=>'未找到该对话。'],404);
  return $row;
}
function assistant_latest_or_new(PDO $pdo, array $user, string $requestedId=''): array {
  if ($requestedId !== '') return assistant_conversation($pdo, $user, $requestedId);
  $stmt = $pdo->prepare('SELECT public_id FROM ai_conversations WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 1');
  $stmt->execute([(int)$user['id']]);
  $latest = $stmt->fetch();
  return $latest ? assistant_conversation($pdo, $user, (string)$latest['public_id']) : assistant_new_conversation($pdo, $user);
}
function assistant_messages(PDO $pdo, array $conversation): array {
  $stmt = $pdo->prepare('SELECT public_id,role,content,created_at FROM ai_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 80');
  $stmt->execute([(int)$conversation['id']]);
  return array_reverse($stmt->fetchAll());
}
function assistant_conversation_list(PDO $pdo, array $user): array {
  $stmt = $pdo->prepare('SELECT public_id,title,memory_enabled,context_status,updated_at FROM ai_conversations WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 40');
  $stmt->execute([(int)$user['id']]);
  return $stmt->fetchAll();
}

try {
  $user = require_user();
  $pdo = db();
  $action = (string)($_GET['action'] ?? 'bootstrap');
  if ($action === 'bootstrap') {
    $conversation = assistant_latest_or_new($pdo, $user, trim((string)($_GET['conversation_id'] ?? '')));
    $ticket = ai_gateway_ticket($user, (string)$conversation['instructions'], (string)$conversation['public_id']);
    json_response([
      'ok'=>true,'conversation'=>$conversation,'messages'=>assistant_messages($pdo,$conversation),
      'conversations'=>assistant_conversation_list($pdo,$user),'gateway'=>$ticket,
      'is_admin'=>is_admin_user($user),
      'memory_settings'=>is_admin_user($user) ? assistant_memory_settings($pdo) : null,
    ]);
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  require_csrf();
  $data = request_data();
  if ($action === 'create') {
    $conversation = assistant_new_conversation($pdo, $user);
    json_response(['ok'=>true,'conversation'=>$conversation]);
  }
  if ($action === 'admin_memory_settings') {
    if (!is_admin_user($user)) json_response(['ok'=>false,'error'=>'当前账户没有助手管理权限。'],403);
    $contextTurns = min(max((int)($data['context_turns'] ?? 10),1),30);
    $summaryMax = min(max((int)($data['summary_max_chars'] ?? 2000),500),4000);
    $contextMax = min(max((int)($data['context_max_chars'] ?? 12000),3000),24000);
    $idleMinutes = min(max((int)($data['device_idle_minutes'] ?? 30),5),1440);
    $retentionDays = min(max((int)($data['retention_days'] ?? 0),0),3650);
    $summaryModel = trim((string)($data['summary_model'] ?? 'qwen-plus'));
    if ($summaryModel === '' || strlen($summaryModel) > 96 || !preg_match('/^[A-Za-z0-9._-]+$/',$summaryModel)) {
      json_response(['ok'=>false,'error'=>'摘要模型名称格式无效。'],422);
    }
    $stmt = $pdo->prepare(
      'INSERT INTO ai_assistant_memory_settings
       (id,context_turns,summary_max_chars,context_max_chars,device_idle_minutes,retention_days,summary_model,updated_by)
       VALUES (1,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE context_turns=VALUES(context_turns),summary_max_chars=VALUES(summary_max_chars),
       context_max_chars=VALUES(context_max_chars),device_idle_minutes=VALUES(device_idle_minutes),
       retention_days=VALUES(retention_days),summary_model=VALUES(summary_model),updated_by=VALUES(updated_by)'
    );
    $stmt->execute([$contextTurns,$summaryMax,$contextMax,$idleMinutes,$retentionDays,$summaryModel,(int)$user['id']]);
    json_response(['ok'=>true,'memory_settings'=>assistant_memory_settings($pdo)]);
  }
  $conversationId = trim((string)($data['conversation_id'] ?? ''));
  $conversation = assistant_conversation($pdo, $user, $conversationId);
  if ($action === 'rename') {
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '' || mb_strlen($title,'UTF-8') > 120) {
      json_response(['ok'=>false,'error'=>'对话标题需为 1—120 个字符。'],422);
    }
    $stmt = $pdo->prepare('UPDATE ai_conversations SET title=? WHERE id=? AND user_id=?');
    $stmt->execute([$title,(int)$conversation['id'],(int)$user['id']]);
    json_response([
      'ok'=>true,
      'conversation'=>assistant_conversation($pdo,$user,$conversationId),
      'conversations'=>assistant_conversation_list($pdo,$user),
    ]);
  }
  if ($action === 'update') {
    $title = trim((string)($data['title'] ?? ''));
    $instructions = trim((string)($data['instructions'] ?? ''));
    if ($title === '' || mb_strlen($title,'UTF-8') > 120) json_response(['ok'=>false,'error'=>'对话标题需为 1—120 个字符。'],422);
    if ($instructions === '' || mb_strlen($instructions,'UTF-8') > 1200) json_response(['ok'=>false,'error'=>'助手指令需为 1—1200 个字符。'],422);
    $memoryEnabled = array_key_exists('memory_enabled',$data) ? (bool)$data['memory_enabled'] : (bool)$conversation['memory_enabled'];
    $stmt = $pdo->prepare('UPDATE ai_conversations SET title=?,instructions=?,memory_enabled=? WHERE id=?');
    $stmt->execute([$title,$instructions,$memoryEnabled?1:0,(int)$conversation['id']]);
    json_response(['ok'=>true,'conversation'=>assistant_conversation($pdo,$user,$conversationId)]);
  }
  if ($action === 'clear_summary') {
    $pdo->prepare(
      "UPDATE ai_conversations SET summary_text=NULL,summary_up_to_message_id=NULL,
       summary_updated_at=NULL,context_status='ready',context_message_count=0 WHERE id=?"
    )->execute([(int)$conversation['id']]);
    json_response(['ok'=>true,'conversation'=>assistant_conversation($pdo,$user,$conversationId)]);
  }
  if ($action === 'clear_messages') {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM ai_messages WHERE conversation_id=?')->execute([(int)$conversation['id']]);
    $pdo->prepare(
      "UPDATE ai_conversations SET summary_text=NULL,summary_up_to_message_id=NULL,
       summary_updated_at=NULL,context_status='ready',context_message_count=0,last_message_at=NULL,updated_at=NOW()
       WHERE id=?"
    )->execute([(int)$conversation['id']]);
    $pdo->commit();
    json_response(['ok'=>true,'conversation'=>assistant_conversation($pdo,$user,$conversationId)]);
  }
  if ($action === 'delete') {
    $pdo->prepare('DELETE FROM ai_conversations WHERE id=?')->execute([(int)$conversation['id']]);
    $next = assistant_latest_or_new($pdo,$user);
    json_response(['ok'=>true,'conversation'=>$next]);
  }
  if ($action === 'save_message') {
    $role = (string)($data['role'] ?? '');
    $content = trim((string)($data['content'] ?? ''));
    if (!in_array($role,['user','assistant'],true)) json_response(['ok'=>false,'error'=>'无效的消息角色。'],422);
    if ($content === '' || mb_strlen($content,'UTF-8') > 8000) json_response(['ok'=>false,'error'=>'消息内容需为 1—8000 个字符。'],422);
    $stmt = $pdo->prepare('INSERT INTO ai_messages (public_id,conversation_id,role,content) VALUES (?,?,?,?)');
    $stmt->execute([public_id(),(int)$conversation['id'],$role,$content]);
    $pdo->prepare('UPDATE ai_conversations SET last_message_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$conversation['id']]);
    json_response(['ok'=>true]);
  }
  json_response(['ok'=>false,'error'=>'未知操作。'],404);
} catch (Throwable $error) {
  error_log('assistant.php: ' . $error->getMessage());
  json_response(['ok'=>false,'error'=>'AI 助手暂不可用，请检查服务器配置和日志。'],503);
}
