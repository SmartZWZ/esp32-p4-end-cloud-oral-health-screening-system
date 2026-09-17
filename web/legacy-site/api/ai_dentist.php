<?php
declare(strict_types=1);
require_once __DIR__ . '/ai_dentist_common.php';

function ai_dentist_call_with_fallback(PDO $pdo, array $settings, array $messages, int $userId, int $sessionId, string $operation): array {
  $models = array_values(array_unique(array_filter([
    trim((string)$settings['primary_model']),
    trim((string)$settings['fallback_model']),
  ])));
  $last = null;
  foreach ($models as $model) {
    try {
      $result = ai_dentist_provider_call($model,$messages,$settings);
      ai_dentist_log($pdo,$userId,$sessionId,$operation,$model,true,$result);
      return $result;
    } catch (AiDentistProviderException $error) {
      $last = $error;
      ai_dentist_log($pdo,$userId,$sessionId,$operation,$model,false,[
        'http_status'=>$error->httpStatus,'request_id'=>$error->requestId,
      ],$error->getMessage());
    }
  }
  throw $last ?: new RuntimeException('没有可用的 AI 牙医模型。');
}

function ai_dentist_image_library(PDO $pdo, array $user): void {
  $member = ai_dentist_member($pdo,(int)$user['id'],trim((string)($_GET['member_id']??'')));
  $page = max(1,min(10000,(int)($_GET['page']??1)));
  $limit = 24;
  $offset = ($page-1)*$limit;
  $source = trim((string)($_GET['source']??''));
  $status = trim((string)($_GET['status']??''));
  $days = (int)($_GET['days']??0);
  $where = ['d.user_id=?','d.member_id=?'];
  $params = [(int)$user['id'],(int)$member['id']];

  if ($source === 'web') $where[] = 'd.device_id IS NULL';
  elseif ($source === 'device_archive') $where[] = "d.device_id IS NOT NULL AND d.upload_mode='archive'";
  elseif ($source === 'device_detect') $where[] = "d.device_id IS NOT NULL AND d.upload_mode='detect'";

  if ($status === 'available') $where[] = "d.status IN ('saved','received')";
  elseif (in_array($status,['processing','completed','failed'],true)) {
    $where[] = 'd.status=?';
    $params[] = $status;
  }
  if (in_array($days,[7,30,90],true)) {
    $where[] = 'd.created_at>=DATE_SUB(NOW(),INTERVAL '.$days.' DAY)';
  }

  $whereSql = implode(' AND ',$where);
  $count = $pdo->prepare("SELECT COUNT(*) FROM detections d WHERE {$whereSql}");
  $count->execute($params);
  $total = (int)$count->fetchColumn();
  $sql = "SELECT d.public_id,d.created_at,d.image_width,d.image_height,d.upload_mode,d.model_pipeline,d.status,
      CASE
        WHEN d.device_id IS NULL THEN 'web'
        WHEN d.upload_mode='archive' THEN 'device_archive'
        ELSE 'device_detect'
      END AS source_type,
      COALESCE(report_usage.report_count,0) AS report_count
    FROM detections d
    LEFT JOIN (
      SELECT si.detection_id,COUNT(DISTINCT si.session_id) AS report_count
      FROM ai_dentist_session_images si
      INNER JOIN ai_dentist_sessions s ON s.id=si.session_id AND s.status='completed'
      WHERE s.user_id=?
      GROUP BY si.detection_id
    ) report_usage ON report_usage.detection_id=d.id
    WHERE {$whereSql}
    ORDER BY d.id DESC
    LIMIT {$limit} OFFSET {$offset}";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge([(int)$user['id']],$params));
  $images = $stmt->fetchAll();
  json_response([
    'ok'=>true,
    'images'=>$images,
    'page'=>$page,
    'has_more'=>$offset+count($images)<$total,
    'total'=>$total,
    'member'=>['public_id'=>(string)$member['public_id'],'name'=>(string)$member['name']],
  ]);
}

function ai_dentist_session_list(PDO $pdo, array $user): void {
  $where = ['s.user_id=?'];
  $params = [(int)$user['id']];
  $memberPublicId = trim((string)($_GET['member_id']??''));
  if ($memberPublicId !== '') {
    $member = ai_dentist_member($pdo,(int)$user['id'],$memberPublicId);
    $where[] = 's.member_id=?';
    $params[] = (int)$member['id'];
  }
  $risk = trim((string)($_GET['risk']??''));
  if (in_array($risk,['low','medium','high','unknown'],true)) {
    $where[] = 's.risk_level=?';
    $params[] = $risk;
  }
  $days = (int)($_GET['days']??0);
  if (in_array($days,[7,30,90],true)) {
    $where[] = 's.created_at>=DATE_SUB(NOW(),INTERVAL '.$days.' DAY)';
  }
  $query = mb_substr(trim((string)($_GET['q']??'')),0,80);
  if ($query !== '') {
    $where[] = 's.title LIKE ?';
    $params[] = '%'.$query.'%';
  }
  $whereSql = implode(' AND ',$where);
  $stmt = $pdo->prepare("SELECT s.public_id,s.title,s.status,s.risk_level,s.summary,s.created_at,m.public_id AS member_public_id,m.name AS member_name,
      (SELECT COUNT(*) FROM ai_dentist_session_images si WHERE si.session_id=s.id) AS image_count
    FROM ai_dentist_sessions s
    INNER JOIN family_members m ON m.id=s.member_id
    WHERE {$whereSql}
    ORDER BY s.id DESC
    LIMIT 100");
  $stmt->execute($params);
  json_response(['ok'=>true,'sessions'=>$stmt->fetchAll()]);
}

function ai_dentist_bootstrap(PDO $pdo, array $user, array $settings): void {
  $memberStmt = $pdo->prepare("SELECT public_id,name,relationship,gender,birth_date,is_default FROM family_members WHERE user_id=? AND status='active' ORDER BY is_default DESC,id ASC");
  $memberStmt->execute([(int)$user['id']]);
  $imageStmt = $pdo->prepare("SELECT d.public_id,d.created_at,d.image_width,d.image_height,d.upload_mode,d.model_pipeline,d.status,m.public_id AS member_public_id,m.name AS member_name,
      CASE WHEN d.device_id IS NULL THEN 'web' WHEN d.upload_mode='archive' THEN 'device_archive' ELSE 'device_detect' END AS source_type,
      COALESCE(report_usage.report_count,0) AS report_count
    FROM detections d
    INNER JOIN family_members m ON m.id=d.member_id
    LEFT JOIN (
      SELECT si.detection_id,COUNT(DISTINCT si.session_id) AS report_count
      FROM ai_dentist_session_images si
      INNER JOIN ai_dentist_sessions s ON s.id=si.session_id AND s.status='completed'
      WHERE s.user_id=?
      GROUP BY si.detection_id
    ) report_usage ON report_usage.detection_id=d.id
    WHERE d.user_id=? AND m.status='active'
    ORDER BY d.id DESC LIMIT 120");
  $imageStmt->execute([(int)$user['id'],(int)$user['id']]);
  $sessionStmt = $pdo->prepare("SELECT s.public_id,s.title,s.status,s.risk_level,s.summary,s.created_at,m.name AS member_name FROM ai_dentist_sessions s INNER JOIN family_members m ON m.id=s.member_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 40");
  $sessionStmt->execute([(int)$user['id']]);
  $usage = ai_dentist_daily_usage($pdo,(int)$user['id']);
  json_response([
    'ok'=>true,
    'members'=>$memberStmt->fetchAll(),
    'images'=>$imageStmt->fetchAll(),
    'sessions'=>$sessionStmt->fetchAll(),
    'preferences'=>[
      'enabled'=>(bool)$settings['enabled'],
      'max_images'=>(int)$settings['max_images'],
      'include_history_default'=>(bool)$settings['include_history_default'],
      'include_local_results_default'=>(bool)$settings['include_local_results_default'],
      'daily_limit'=>(int)$settings['daily_user_limit'],
      'daily_used'=>$usage,
    ],
    'is_admin'=>is_admin_user($user),
  ]);
}

try {
  $user = require_user();
  $pdo = db();
  $settings = ai_dentist_settings($pdo);
  $action = (string)($_GET['action'] ?? 'bootstrap');
  if ($action === 'bootstrap') ai_dentist_bootstrap($pdo,$user,$settings);
  if ($action === 'images') ai_dentist_image_library($pdo,$user);
  if ($action === 'sessions') ai_dentist_session_list($pdo,$user);
  if ($action === 'session') {
    $session = ai_dentist_session($pdo,(int)$user['id'],trim((string)($_GET['id']??'')));
    json_response(['ok'=>true,'session'=>ai_dentist_session_payload($pdo,$session)]);
  }
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  require_csrf();
  $data = request_data();
  if ($action === 'delete_session') {
    $session = ai_dentist_session($pdo,(int)$user['id'],trim((string)($data['session_id']??'')));
    $pdo->beginTransaction();
    try {
      $stmt = $pdo->prepare('DELETE FROM ai_dentist_sessions WHERE id=? AND user_id=?');
      $stmt->execute([(int)$session['id'],(int)$user['id']]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('报告删除失败，请刷新后重试。');
      $pdo->commit();
      json_response([
        'ok'=>true,
        'deleted_session_id'=>(string)$session['public_id'],
        'message'=>'报告已永久删除，原始照片和检测结果均已保留。',
      ]);
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
  }
  if (!(bool)$settings['enabled']) json_response(['ok'=>false,'error'=>'AI 牙医当前处于维护状态。'],503);
  $used = ai_dentist_daily_usage($pdo,(int)$user['id']);
  if ((int)$settings['daily_user_limit'] > 0 && $used >= (int)$settings['daily_user_limit']) {
    json_response(['ok'=>false,'error'=>'今天的 AI 牙医分析与追问额度已用完，请明天再试。'],429);
  }

  if ($action === 'analyze') {
    $member = ai_dentist_member($pdo,(int)$user['id'],trim((string)($data['member_id']??'')));
    $symptoms = mb_substr(trim((string)($data['symptoms']??'')),0,2000);
    $useHistory = filter_var($data['use_history']??false,FILTER_VALIDATE_BOOLEAN);
    $useLocalResults = filter_var($data['use_local_results']??false,FILTER_VALIDATE_BOOLEAN);
    $images = ai_dentist_images($pdo,(int)$user['id'],(int)$member['id'],is_array($data['image_ids']??null)?$data['image_ids']:[],(int)$settings['max_images']);
    $history = $useHistory ? ai_dentist_history($pdo,(int)$user['id'],(int)$member['id'],(int)$settings['max_history_items']) : [];
    $localResults = $useLocalResults ? ai_dentist_local_results($pdo,$images) : [];
    $publicId = public_id();
    $title = (string)$member['name'].'的口腔影像分析';
    $stmt = $pdo->prepare("INSERT INTO ai_dentist_sessions(public_id,user_id,member_id,title,symptoms,use_history,use_local_results,status) VALUES(?,?,?,?,?,?,?,'processing')");
    $stmt->execute([$publicId,(int)$user['id'],(int)$member['id'],$title,$symptoms,$useHistory?1:0,$useLocalResults?1:0]);
    $sessionId = (int)$pdo->lastInsertId();
    $link = $pdo->prepare('INSERT INTO ai_dentist_session_images(session_id,detection_id,sort_order) VALUES(?,?,?)');
    foreach ($images as $index=>$image) $link->execute([$sessionId,(int)$image['id'],$index]);
    $initialText = $symptoms!=='' ? '请结合这些照片分析。我的描述：'.$symptoms : '请分析本次选择的口腔照片。';
    $pdo->prepare("INSERT INTO ai_dentist_messages(public_id,session_id,role,content) VALUES(?,?,'user',?)")->execute([public_id(),$sessionId,$initialText]);

    $content = [['type'=>'text','text'=>ai_dentist_user_context($member,$symptoms,$useHistory,$history,$useLocalResults,$localResults)]];
    $expires = time()+420;
    foreach ($images as $index=>$image) {
      $content[] = ['type'=>'text','text'=>'口腔照片 '.($index+1)];
      $content[] = ['type'=>'image_url','image_url'=>['url'=>ai_dentist_signed_image_url($image,$expires)]];
    }
    $messages = [
      ['role'=>'system','content'=>(string)$settings['system_prompt']],
      ['role'=>'user','content'=>$content],
    ];
    try {
      $result = ai_dentist_call_with_fallback($pdo,$settings,$messages,(int)$user['id'],$sessionId,'analysis');
      $report = ai_dentist_parse_report((string)$result['content']);
      $reportJson = json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $stmt = $pdo->prepare("UPDATE ai_dentist_sessions SET status='completed',risk_level=?,summary=?,report_json=?,model_name=?,input_tokens=?,output_tokens=?,latency_ms=?,completed_at=NOW() WHERE id=?");
      $stmt->execute([
        $report['overall_risk'],$report['summary'],$reportJson,(string)$result['model'],
        (int)$result['input_tokens'],(int)$result['output_tokens'],(int)$result['latency_ms'],$sessionId,
      ]);
      $pdo->prepare("INSERT INTO ai_dentist_messages(public_id,session_id,role,content) VALUES(?,?,'assistant',?)")->execute([public_id(),$sessionId,(string)$report['summary']]);
      $session = ai_dentist_session($pdo,(int)$user['id'],$publicId);
      json_response(['ok'=>true,'session'=>ai_dentist_session_payload($pdo,$session)]);
    } catch (Throwable $error) {
      $pdo->prepare("UPDATE ai_dentist_sessions SET status='failed',error_message=? WHERE id=?")->execute([mb_substr($error->getMessage(),0,1000),$sessionId]);
      error_log('AI dentist analysis failed: '.$error->getMessage());
      json_response(['ok'=>false,'error'=>'AI 牙医暂时未能完成分析：'.$error->getMessage(),'session_id'=>$publicId],502);
    }
  }

  if ($action === 'follow_up') {
    $session = ai_dentist_session($pdo,(int)$user['id'],trim((string)($data['session_id']??'')));
    if ((string)$session['status'] !== 'completed') json_response(['ok'=>false,'error'=>'当前报告尚未完成，不能继续追问。'],409);
    $question = mb_substr(trim((string)($data['question']??'')),0,2000);
    if ($question === '') json_response(['ok'=>false,'error'=>'请输入要追问的问题。'],422);
    $member = ai_dentist_member($pdo,(int)$user['id'],(string)$session['member_public_id']);
    $stmt = $pdo->prepare('SELECT d.* FROM ai_dentist_session_images si INNER JOIN detections d ON d.id=si.detection_id WHERE si.session_id=? ORDER BY si.sort_order ASC');
    $stmt->execute([(int)$session['id']]);
    $images = $stmt->fetchAll();
    $expires = time()+420;
    $visualContent = [['type'=>'text','text'=>"下面是已经完成的结构化筛查报告：\n".(string)$session['report_json']."\n用户追问：".$question."\n请只返回 JSON：{\"answer\":\"简洁、谨慎的中文回答\",\"safety_note\":\"必要的就医或局限性提醒\"}。不要给出确定诊断。"]];
    foreach ($images as $index=>$image) $visualContent[]=['type'=>'image_url','image_url'=>['url'=>ai_dentist_signed_image_url($image,$expires)]];
    $historyStmt = $pdo->prepare('SELECT role,content FROM ai_dentist_messages WHERE session_id=? ORDER BY id DESC LIMIT 12');
    $historyStmt->execute([(int)$session['id']]);
    $previous = array_reverse($historyStmt->fetchAll());
    $messages = [['role'=>'system','content'=>(string)$settings['system_prompt']]];
    foreach ($previous as $message) $messages[]=['role'=>(string)$message['role'],'content'=>(string)$message['content']];
    $messages[]=['role'=>'user','content'=>$visualContent];
    try {
      $result = ai_dentist_call_with_fallback($pdo,$settings,$messages,(int)$user['id'],(int)$session['id'],'follow_up');
      $answerJson = json_decode(trim((string)$result['content']),true);
      if (!is_array($answerJson)) throw new AiDentistProviderException('模型没有返回有效的追问结果。');
      $answer = trim((string)($answerJson['answer']??''));
      $safety = trim((string)($answerJson['safety_note']??''));
      if ($answer === '') throw new AiDentistProviderException('模型没有生成追问回答。');
      if ($safety !== '') $answer .= "\n\n".$safety;
      $insert = $pdo->prepare('INSERT INTO ai_dentist_messages(public_id,session_id,role,content) VALUES(?,?,?,?)');
      $insert->execute([public_id(),(int)$session['id'],'user',$question]);
      $insert->execute([public_id(),(int)$session['id'],'assistant',mb_substr($answer,0,8000)]);
      $pdo->prepare('UPDATE ai_dentist_sessions SET updated_at=NOW() WHERE id=?')->execute([(int)$session['id']]);
      json_response(['ok'=>true,'answer'=>$answer]);
    } catch (Throwable $error) {
      error_log('AI dentist follow-up failed: '.$error->getMessage());
      json_response(['ok'=>false,'error'=>'这次追问没有完成：'.$error->getMessage()],502);
    }
  }
  json_response(['ok'=>false,'error'=>'接口不存在。'],404);
} catch (PDOException $error) {
  error_log('ai_dentist.php database: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'AI 牙医数据库尚未完成迁移，或数据表结构不完整。'],503);
} catch (Throwable $error) {
  error_log('ai_dentist.php: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'AI 牙医暂时不可用，请检查服务器配置和日志。'],503);
}
