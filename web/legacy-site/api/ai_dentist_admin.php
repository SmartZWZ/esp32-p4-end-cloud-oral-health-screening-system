<?php
declare(strict_types=1);
require_once __DIR__ . '/ai_dentist_common.php';

function ai_dentist_admin_payload(PDO $pdo): array {
  $settings = ai_dentist_settings($pdo);
  $runtimeStatus = ['api_key'=>false,'base_url'=>false,'public_origin'=>false];
  try {
    $runtime = ai_dentist_runtime_config();
    $runtimeStatus = [
      'api_key'=>trim((string)($runtime['BAILIAN_API_KEY']??''))!=='',
      'base_url'=>trim((string)($runtime['BAILIAN_OPENAI_BASE_URL']??''))!=='',
      'public_origin'=>trim((string)($runtime['AI_DENTIST_PUBLIC_ORIGIN']??''))!=='',
    ];
  } catch (Throwable $error) {}
  $stats = $pdo->query("SELECT
    COUNT(*) AS total_calls,
    SUM(success=1) AS successful_calls,
    SUM(created_at>=CURDATE()) AS today_calls,
    COALESCE(SUM(input_tokens+output_tokens),0) AS total_tokens,
    COALESCE(ROUND(AVG(CASE WHEN success=1 THEN latency_ms END)),0) AS avg_latency
    FROM ai_dentist_call_logs")->fetch();
  $models = $pdo->query("SELECT model_name,COUNT(*) AS calls,SUM(success=1) AS successes,COALESCE(SUM(input_tokens+output_tokens),0) AS tokens FROM ai_dentist_call_logs GROUP BY model_name ORDER BY calls DESC LIMIT 12")->fetchAll();
  return ['settings'=>$settings,'runtime'=>$runtimeStatus,'stats'=>$stats,'models'=>$models];
}

try {
  $admin = require_admin();
  $pdo = db();
  $action = (string)($_GET['action']??'bootstrap');
  if ($action === 'bootstrap') {
    $payload = ai_dentist_admin_payload($pdo);
    $action = 'logs';
  } else {
    $payload = [];
  }
  if ($action === 'logs' && ($_SERVER['REQUEST_METHOD']??'GET') === 'GET') {
    $page = max((int)($_GET['page']??1),1);
    $limit = 30;
    $where = [];
    $args = [];
    $success = trim((string)($_GET['success']??''));
    $operation = trim((string)($_GET['operation']??''));
    $model = trim((string)($_GET['model']??''));
    if ($success==='1' || $success==='0') { $where[]='l.success=?'; $args[]=(int)$success; }
    if (in_array($operation,['analysis','follow_up','admin_test','family_image','family_summary','family_model_report','dental_arch_image','dental_arch_joint'],true)) { $where[]='l.operation=?'; $args[]=$operation; }
    if ($model!=='') { $where[]='l.model_name LIKE ?'; $args[]='%'.mb_substr($model,0,96).'%'; }
    $clause = $where ? ' WHERE '.implode(' AND ',$where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM ai_dentist_call_logs l'.$clause);
    $count->execute($args);
    $total = (int)$count->fetchColumn();
    $offset = ($page-1)*$limit;
    $stmt = $pdo->prepare("SELECT l.public_id,l.operation,l.model_name,l.success,l.http_status,l.provider_request_id,l.input_tokens,l.output_tokens,l.latency_ms,l.error_message,l.created_at,u.email,s.public_id AS session_public_id
      FROM ai_dentist_call_logs l INNER JOIN users u ON u.id=l.user_id LEFT JOIN ai_dentist_sessions s ON s.id=l.session_id
      {$clause} ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($args);
    $payload['logs']=$stmt->fetchAll();
    $payload['pagination']=['page'=>$page,'pages'=>max((int)ceil($total/$limit),1),'total'=>$total];
    $payload['ok']=true;
    json_response($payload);
  }
  if (($_SERVER['REQUEST_METHOD']??'GET') !== 'POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  require_csrf();
  $data = request_data();
  if ($action === 'update') {
    $primary = trim((string)($data['primary_model']??''));
    $fallback = trim((string)($data['fallback_model']??''));
    $dentalArchModel = trim((string)($data['dental_arch_model']??'qwen3.7-plus'));
    $dentalArchFallback = trim((string)($data['dental_arch_fallback_model']??'qwen3.6-plus'));
    $prompt = trim((string)($data['system_prompt']??''));
    if (!preg_match('/^[A-Za-z0-9._:-]{3,96}$/',$primary)) json_response(['ok'=>false,'error'=>'主模型 ID 格式不正确。'],422);
    if ($fallback!=='' && !preg_match('/^[A-Za-z0-9._:-]{3,96}$/',$fallback)) json_response(['ok'=>false,'error'=>'备用模型 ID 格式不正确。'],422);
    if (!preg_match('/^[A-Za-z0-9._:-]{3,96}$/',$dentalArchModel)) json_response(['ok'=>false,'error'=>'牙列视觉复核模型 ID 格式不正确。'],422);
    if ($dentalArchFallback!=='' && !preg_match('/^[A-Za-z0-9._:-]{3,96}$/',$dentalArchFallback)) json_response(['ok'=>false,'error'=>'牙列备用模型 ID 格式不正确。'],422);
    if (mb_strlen($prompt)<80 || mb_strlen($prompt)>10000) json_response(['ok'=>false,'error'=>'系统提示词需要 80 至 10000 个字符。'],422);
    $timeout = min(max((int)($data['request_timeout_seconds']??90),15),180);
    $maxImages = min(max((int)($data['max_images']??6),1),10);
    $maxHistory = min(max((int)($data['max_history_items']??8),0),20);
    $dailyLimit = min(max((int)($data['daily_user_limit']??20),0),500);
    $temperature = min(max((float)($data['temperature']??0.2),0),1);
    $stmt = $pdo->prepare('UPDATE ai_dentist_settings SET enabled=?,primary_model=?,fallback_model=?,dental_arch_model=?,dental_arch_fallback_model=?,system_prompt=?,request_timeout_seconds=?,max_images=?,max_history_items=?,include_history_default=?,include_local_results_default=?,daily_user_limit=?,temperature=?,high_resolution_images=?,updated_by=? WHERE id=1');
    $stmt->execute([
      filter_var($data['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0,$primary,$fallback,$dentalArchModel,$dentalArchFallback,$prompt,$timeout,$maxImages,$maxHistory,
      filter_var($data['include_history_default']??false,FILTER_VALIDATE_BOOLEAN)?1:0,
      filter_var($data['include_local_results_default']??false,FILTER_VALIDATE_BOOLEAN)?1:0,
      $dailyLimit,$temperature,filter_var($data['high_resolution_images']??false,FILTER_VALIDATE_BOOLEAN)?1:0,(int)$admin['id'],
    ]);
    json_response(['ok'=>true,'settings'=>ai_dentist_settings($pdo)]);
  }
  if ($action === 'test') {
    $settings = ai_dentist_settings($pdo);
    $model = (string)$settings['primary_model'];
    try {
      $result = ai_dentist_provider_call($model,[
        ['role'=>'system','content'=>'你是接口连通性检查程序，只返回 JSON。'],
        ['role'=>'user','content'=>'返回 {"ok":true,"message":"视觉模型接口可用"}'],
      ],$settings);
      ai_dentist_log($pdo,(int)$admin['id'],null,'admin_test',$model,true,$result);
      json_response(['ok'=>true,'message'=>'百炼模型连接正常。','latency_ms'=>$result['latency_ms'],'model'=>$result['model']]);
    } catch (Throwable $error) {
      $meta=$error instanceof AiDentistProviderException?['http_status'=>$error->httpStatus,'request_id'=>$error->requestId]:[];
      ai_dentist_log($pdo,(int)$admin['id'],null,'admin_test',$model,false,$meta,$error->getMessage());
      json_response(['ok'=>false,'error'=>'连接测试失败：'.$error->getMessage()],502);
    }
  }
  json_response(['ok'=>false,'error'=>'接口不存在。'],404);
} catch (PDOException $error) {
  error_log('ai_dentist_admin.php database: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'AI 牙医数据库尚未完成迁移。'],503);
} catch (Throwable $error) {
  error_log('ai_dentist_admin.php: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'AI 牙医管理后台暂时不可用。'],503);
}
