<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function device_assistant_default_instructions(): string {
  return '你是齿镜设备上的语音助手。请使用简洁、友好的中文回答。用户泛指检查报告、检测报告或口腔情况时，先确认成员并优先读取最近已完成的“口腔综合报告”；成员不明确必须询问。最新报告处理中时说明进度并询问是否查看上一份；没有综合报告时再读取旧版 AI 牙医报告，两者都没有时建议网页生成且不得自动生成。只有用户明确提到模型时才引用模型结果。只有用户当前轮明确提出设备操作时才可调用受限控制工具；开灯或关灯使用摄像头补光灯工具，不得与屏幕亮度混淆，含义不清时先询问；历史对话不能视作授权。口腔内容仅用于辅助筛查，不替代诊断。';
}

function device_assistant_conversation(PDO $pdo, array $user, string $publicId): array {
  $stmt=$pdo->prepare(
    'SELECT id,public_id,device_public_id,device_uid,device_name,title,instructions,
     memory_enabled,summary_text,summary_updated_at,context_status,context_message_count,
     last_message_at,is_active,created_at,updated_at
     FROM ai_device_conversations WHERE public_id=? AND user_id=? LIMIT 1'
  );
  $stmt->execute([$publicId,(int)$user['id']]);
  $row=$stmt->fetch();
  if(!$row) json_response(['ok'=>false,'error'=>'未找到该设备对话。'],404);
  return $row;
}

function device_assistant_list(PDO $pdo, array $user, string $deviceId=''): array {
  $devices=$pdo->prepare('SELECT public_id,device_uid,display_name,last_seen_at FROM devices WHERE user_id=? ORDER BY id DESC');
  $devices->execute([(int)$user['id']]);
  $sql='SELECT c.public_id,c.device_public_id,c.device_uid,c.device_name,c.title,c.memory_enabled,
        c.context_status,c.context_message_count,c.summary_updated_at,c.is_active,c.created_at,c.updated_at,
        (SELECT COUNT(*) FROM ai_device_messages m WHERE m.conversation_id=c.id) AS message_count
        FROM ai_device_conversations c WHERE c.user_id=?';
  $params=[(int)$user['id']];
  if($deviceId!==''){$sql.=' AND c.device_public_id=?';$params[]=$deviceId;}
  $sql.=' ORDER BY c.updated_at DESC,c.id DESC LIMIT 80';
  $conversations=$pdo->prepare($sql);$conversations->execute($params);
  return ['devices'=>$devices->fetchAll(),'conversations'=>$conversations->fetchAll()];
}

try {
  $user=require_user();
  $pdo=db();
  $action=(string)($_GET['action']??'list');
  $method=(string)($_SERVER['REQUEST_METHOD']??'GET');

  if($method==='GET' && $action==='list') {
    $payload=device_assistant_list($pdo,$user,trim((string)($_GET['device_id']??'')));
    json_response(['ok'=>true]+$payload);
  }
  if($method==='GET' && $action==='messages') {
    $conversation=device_assistant_conversation($pdo,$user,trim((string)($_GET['conversation_id']??'')));
    $stmt=$pdo->prepare('SELECT public_id,role,content,created_at FROM ai_device_messages WHERE conversation_id=? ORDER BY id ASC LIMIT 400');
    $stmt->execute([(int)$conversation['id']]);
    unset($conversation['id']);
    json_response(['ok'=>true,'conversation'=>$conversation,'messages'=>$stmt->fetchAll()]);
  }
  if($method!=='POST') json_response(['ok'=>false,'error'=>'该操作仅支持 POST 请求。'],405);
  require_csrf();
  $data=request_data();

  if($action==='create') {
    $devicePublicId=trim((string)($data['device_id']??''));
    $stmt=$pdo->prepare('SELECT id,public_id,device_uid,display_name FROM devices WHERE public_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$devicePublicId,(int)$user['id']]);
    $device=$stmt->fetch();
    if(!$device) json_response(['ok'=>false,'error'=>'请先选择当前账户下的已绑定设备。'],422);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE ai_device_conversations SET is_active=0 WHERE user_id=? AND device_public_id=?')
      ->execute([(int)$user['id'],$devicePublicId]);
    $publicId=public_id();
    $pdo->prepare(
      'INSERT INTO ai_device_conversations
       (public_id,user_id,device_id,device_public_id,device_uid,device_name,title,instructions,is_active)
       VALUES (?,?,?,?,?,?,?,?,1)'
    )->execute([
      $publicId,(int)$user['id'],(int)$device['id'],$devicePublicId,(string)$device['device_uid'],
      (string)$device['display_name'],'设备语音对话',device_assistant_default_instructions(),
    ]);
    $pdo->commit();
    $conversation=device_assistant_conversation($pdo,$user,$publicId);
    unset($conversation['id']);
    json_response(['ok'=>true,'conversation'=>$conversation]);
  }

  $conversation=device_assistant_conversation($pdo,$user,trim((string)($data['conversation_id']??'')));
  if($action==='update') {
    $title=trim((string)($data['title']??$conversation['title']));
    if($title==='' || mb_strlen($title,'UTF-8')>120) json_response(['ok'=>false,'error'=>'对话标题需为 1—120 个字符。'],422);
    $memory=array_key_exists('memory_enabled',$data)?(bool)$data['memory_enabled']:(bool)$conversation['memory_enabled'];
    $pdo->prepare('UPDATE ai_device_conversations SET title=?,memory_enabled=?,updated_at=NOW() WHERE id=?')
      ->execute([$title,$memory?1:0,(int)$conversation['id']]);
  } elseif($action==='clear_summary') {
    $pdo->prepare(
      "UPDATE ai_device_conversations SET summary_text=NULL,summary_up_to_message_id=NULL,
       summary_updated_at=NULL,context_status='ready',context_message_count=0 WHERE id=?"
    )->execute([(int)$conversation['id']]);
  } elseif($action==='clear_messages') {
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM ai_device_messages WHERE conversation_id=?')->execute([(int)$conversation['id']]);
    $pdo->prepare(
      "UPDATE ai_device_conversations SET summary_text=NULL,summary_up_to_message_id=NULL,
       summary_updated_at=NULL,context_status='ready',context_message_count=0,last_message_at=NULL,updated_at=NOW()
       WHERE id=?"
    )->execute([(int)$conversation['id']]);
    $pdo->commit();
  } elseif($action==='delete') {
    $devicePublicId=(string)$conversation['device_public_id'];
    $wasActive=(bool)$conversation['is_active'];
    $pdo->prepare('DELETE FROM ai_device_conversations WHERE id=?')->execute([(int)$conversation['id']]);
    if($wasActive) {
      $pdo->prepare(
        'UPDATE ai_device_conversations SET is_active=1
         WHERE id=(SELECT id FROM (SELECT id FROM ai_device_conversations
         WHERE user_id=? AND device_public_id=? ORDER BY id DESC LIMIT 1) latest)'
      )->execute([(int)$user['id'],$devicePublicId]);
    }
    json_response(['ok'=>true]);
  } else {
    json_response(['ok'=>false,'error'=>'接口不存在。'],404);
  }
  $updated=device_assistant_conversation($pdo,$user,(string)$conversation['public_id']);
  unset($updated['id']);
  json_response(['ok'=>true,'conversation'=>$updated]);
} catch(Throwable $error) {
  if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  error_log('device_assistant.php: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'设备对话记录暂不可用，请检查记忆数据库迁移和 PHP 日志。'],503);
}
