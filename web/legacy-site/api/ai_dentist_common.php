<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

final class AiDentistProviderException extends RuntimeException {
  public int $httpStatus;
  public string $requestId;
  public int $curlErrno;
  public function __construct(string $message, int $httpStatus = 0, string $requestId = '', int $curlErrno = 0) {
    parent::__construct($message);
    $this->httpStatus = $httpStatus;
    $this->requestId = $requestId;
    $this->curlErrno = $curlErrno;
  }
}

function ai_dentist_settings(PDO $pdo): array {
  $row = $pdo->query('SELECT * FROM ai_dentist_settings WHERE id=1 LIMIT 1')->fetch();
  if (!$row) throw new RuntimeException('AI 牙医数据库迁移尚未执行。');
  return $row;
}

function ai_dentist_member(PDO $pdo, int $userId, string $publicId): array {
  $stmt = $pdo->prepare("SELECT id,public_id,name,relationship,gender,birth_date,is_default FROM family_members WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");
  $stmt->execute([mb_substr($publicId, 0, 32), $userId]);
  $member = $stmt->fetch();
  if (!$member) json_response(['ok'=>false,'error'=>'成员不存在、已删除或不属于当前账户。'],404);
  return $member;
}

function ai_dentist_age(?string $birthDate): ?int {
  if (!$birthDate) return null;
  try { return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y; }
  catch (Throwable $error) { return null; }
}

function ai_dentist_history(PDO $pdo, int $userId, int $memberId, int $limit): array {
  $limit = min(max($limit, 0), 20);
  if ($limit === 0) return [];
  $sql = "SELECT d.public_id,d.created_at,d.upload_mode,d.status,d.report_text,
                 dr.model_name,dr.summary_text,dr.risk_level
          FROM detections d
          LEFT JOIN detection_results dr ON dr.id=(
            SELECT r.id FROM detection_results r
            WHERE r.detection_id=d.id AND r.status='completed'
            ORDER BY r.id DESC LIMIT 1
          )
          WHERE d.user_id=? AND d.member_id=?
          ORDER BY d.id DESC LIMIT {$limit}";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId,$memberId]);
  return array_map(static fn(array $row): array => [
    'date'=>(string)$row['created_at'],
    'record_id'=>(string)$row['public_id'],
    'source_report'=>mb_substr(trim((string)($row['summary_text'] ?: $row['report_text'] ?: '')),0,1200),
    'risk'=>(string)($row['risk_level'] ?: 'unknown'),
    'model'=>(string)($row['model_name'] ?: ''),
  ], $stmt->fetchAll());
}

function ai_dentist_local_results(PDO $pdo, array $images): array {
  if (!$images) return [];
  $ids = array_map(static fn(array $image): int => (int)$image['id'],$images);
  $placeholders = implode(',',array_fill(0,count($ids),'?'));
  $stmt = $pdo->prepare("SELECT dr.detection_id,dr.model_name,dr.summary_text,dr.risk_level,dr.raw_result_json
    FROM detection_results dr
    INNER JOIN (
      SELECT detection_id,MAX(id) AS latest_id FROM detection_results
      WHERE detection_id IN ({$placeholders}) AND model_type='vision' AND status='completed'
      GROUP BY detection_id
    ) latest ON latest.latest_id=dr.id");
  $stmt->execute($ids);
  $byDetection = [];
  foreach ($stmt->fetchAll() as $row) {
    $raw = $row['raw_result_json'] ? json_decode((string)$row['raw_result_json'],true) : null;
    $compactFindings = [];
    foreach (array_slice(is_array($raw['findings']??null)?$raw['findings']:[],0,20) as $finding) {
      if (!is_array($finding)) continue;
      $compactFindings[] = [
        'label'=>(string)($finding['label']??''),
        'confidence'=>(float)($finding['confidence']??0),
        'bbox_xyxy'=>is_array($finding['bbox_xyxy']??null)?array_slice($finding['bbox_xyxy'],0,4):[],
      ];
    }
    $byDetection[(int)$row['detection_id']] = [
      'model'=>(string)$row['model_name'],
      'risk'=>(string)$row['risk_level'],
      'summary'=>mb_substr((string)($row['summary_text']??''),0,1500),
      'counts'=>is_array($raw['counts']??null)?$raw['counts']:[],
      'class_stats'=>is_array($raw['class_stats']??null)?$raw['class_stats']:[],
      'findings'=>$compactFindings,
    ];
  }
  $result = [];
  foreach ($images as $index=>$image) {
    if (isset($byDetection[(int)$image['id']])) $result[]=['image_index'=>$index+1]+$byDetection[(int)$image['id']];
  }
  return $result;
}

function ai_dentist_images(PDO $pdo, int $userId, int $memberId, array $publicIds, int $maximum): array {
  $ids = array_values(array_unique(array_filter(array_map(
    static fn($value): string => mb_substr(trim((string)$value),0,32),
    $publicIds
  ))));
  if (!$ids || count($ids) > $maximum) json_response(['ok'=>false,'error'=>"请选择 1 至 {$maximum} 张属于当前成员的口腔照片。"],422);
  $placeholders = implode(',',array_fill(0,count($ids),'?'));
  $stmt = $pdo->prepare("SELECT id,public_id,image_path,image_width,image_height,created_at FROM detections WHERE user_id=? AND member_id=? AND public_id IN ({$placeholders})");
  $stmt->execute(array_merge([$userId,$memberId],$ids));
  $byId = [];
  foreach ($stmt->fetchAll() as $row) $byId[(string)$row['public_id']] = $row;
  $images = [];
  foreach ($ids as $id) {
    if (!isset($byId[$id])) json_response(['ok'=>false,'error'=>'所选图片不存在、已删除或不属于当前成员。'],404);
    if (!image_file_path((string)$byId[$id]['image_path'])) json_response(['ok'=>false,'error'=>'一张所选图片的原文件已丢失，请重新上传。'],409);
    $images[] = $byId[$id];
  }
  return $images;
}

function ai_dentist_signed_image_url(array $image, int $expires): string {
  $config = ai_dentist_runtime_config();
  $id = (string)$image['public_id'];
  // ai_dentist_image.php only accepts short-lived tickets (at most 15 minutes).
  // Clamp every internal caller to a safe window so a future long expiry cannot
  // silently turn into an immediately-invalid image URL.
  $now = time();
  $expires = min(max($expires, $now + 60), $now + 840);
  $signature = hash_hmac('sha256', $id . '|' . $expires, (string)$config['AI_GATEWAY_SECRET']);
  return (string)$config['AI_DENTIST_PUBLIC_ORIGIN'] . '/api/ai_dentist_image.php?id=' .
    rawurlencode($id) . '&expires=' . $expires . '&signature=' . $signature;
}

function ai_dentist_report_instruction(): string {
  return <<<'PROMPT'
请综合多张口腔照片完成一次辅助筛查，并只返回一个 JSON 对象，不要使用 Markdown 代码块。
JSON 必须包含以下字段：
{
  "image_quality": {
    "usable": true,
    "score": 0到100的整数,
    "problems": ["清晰度、反光、遮挡等问题"],
    "retake_advice": "需要重拍时给出具体方法，否则为空字符串"
  },
  "visible_findings": [
    {
      "id": "F1",
      "image_index": 1,
      "region": "用户能理解的位置描述",
      "finding": "直接可见的表现，不写确定诊断",
      "evidence": "判断依据",
      "possibilities": ["可能性，必须使用疑似/可能等措辞"],
      "risk": "low、medium、high 或 unknown",
      "needs_review": true,
      "relative_position": {"x": 0到100, "y": 0到100}
    }
  ],
  "overall_risk": "low、medium、high 或 unknown",
  "summary": "不超过180字的结论",
  "recommendations": [
    {"priority": "routine、soon 或 urgent", "action": "建议动作", "reason": "原因"}
  ],
  "not_assessable": ["仅凭这些照片不能判断的事项"],
  "disclaimer": "本结果仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。"
}
只有确实能定位时才填写 relative_position；不能定位时将其设为 null。不得输出牙齿编号，除非照片中位置足够明确。不得推断照片之外的信息。
PROMPT;
}

function ai_dentist_user_context(array $member, string $symptoms, bool $useHistory, array $history, bool $useLocalResults, array $localResults): string {
  $gender = ['male'=>'男','female'=>'女','unknown'=>'未填写'][(string)$member['gender']] ?? '未填写';
  $context = [
    '当前成员'=>[
      '姓名'=>(string)$member['name'],
      '与账户关系'=>(string)$member['relationship'],
      '性别'=>$gender,
      '年龄'=>ai_dentist_age($member['birth_date'] ? (string)$member['birth_date'] : null),
    ],
    '用户自述症状'=>$symptoms !== '' ? $symptoms : '未填写',
    '是否允许参考历史'=>$useHistory,
    '最近历史'=>$useHistory ? $history : [],
    '是否允许参考本地视觉模型结果'=>$useLocalResults,
    '已选图片的本地模型结果'=>$useLocalResults ? $localResults : [],
  ];
  return "请分析本次上传的口腔照片。\n成员信息与用户授权的上下文：\n" .
    json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n\n" .
    ai_dentist_report_instruction();
}

function ai_dentist_parse_report(string $content): array {
  if (strlen($content) > 300000) throw new AiDentistProviderException('模型返回的报告内容过长。');
  $clean = trim($content);
  if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su',$clean,$match)) $clean = trim($match[1]);
  $report = json_decode($clean,true);
  if (!is_array($report)) throw new AiDentistProviderException('模型没有返回有效的结构化报告。');
  $risk = strtolower((string)($report['overall_risk'] ?? 'unknown'));
  if (!in_array($risk,['unknown','low','medium','high'],true)) $risk = 'unknown';
  $report['overall_risk'] = $risk;
  $report['summary'] = mb_substr(trim((string)($report['summary'] ?? '分析已完成。')),0,2000);
  $quality = is_array($report['image_quality']??null)?$report['image_quality']:[];
  $report['image_quality'] = [
    'usable'=>(bool)($quality['usable']??false),
    'score'=>min(max((int)($quality['score']??0),0),100),
    'problems'=>array_map(static fn($value): string => mb_substr(trim((string)$value),0,300),array_slice(is_array($quality['problems']??null)?$quality['problems']:[],0,12)),
    'retake_advice'=>mb_substr(trim((string)($quality['retake_advice']??'')),0,1000),
  ];
  $findings = [];
  foreach (array_slice(is_array($report['visible_findings']??null)?$report['visible_findings']:[],0,30) as $index=>$finding) {
    if (!is_array($finding)) continue;
    $itemRisk = strtolower((string)($finding['risk']??'unknown'));
    if (!in_array($itemRisk,['unknown','low','medium','high'],true)) $itemRisk='unknown';
    $point = is_array($finding['relative_position']??null)?$finding['relative_position']:null;
    $point = $point !== null && is_numeric($point['x']??null) && is_numeric($point['y']??null)
      ? ['x'=>min(max((float)$point['x'],0),100),'y'=>min(max((float)$point['y'],0),100)]
      : null;
    $findings[] = [
      'id'=>mb_substr(trim((string)($finding['id']??('F'.($index+1)))),0,20),
      'image_index'=>min(max((int)($finding['image_index']??1),1),10),
      'region'=>mb_substr(trim((string)($finding['region']??'位置未明确')),0,300),
      'finding'=>mb_substr(trim((string)($finding['finding']??'可见表现')),0,600),
      'evidence'=>mb_substr(trim((string)($finding['evidence']??'')),0,1200),
      'possibilities'=>array_map(static fn($value): string => mb_substr(trim((string)$value),0,300),array_slice(is_array($finding['possibilities']??null)?$finding['possibilities']:[],0,8)),
      'risk'=>$itemRisk,
      'needs_review'=>(bool)($finding['needs_review']??true),
      'relative_position'=>$point,
    ];
  }
  $report['visible_findings']=$findings;
  $recommendations=[];
  foreach (array_slice(is_array($report['recommendations']??null)?$report['recommendations']:[],0,20) as $item) {
    if (!is_array($item)) continue;
    $priority=strtolower((string)($item['priority']??'routine'));
    if (!in_array($priority,['routine','soon','urgent'],true)) $priority='routine';
    $recommendations[]=[
      'priority'=>$priority,
      'action'=>mb_substr(trim((string)($item['action']??'')),0,600),
      'reason'=>mb_substr(trim((string)($item['reason']??'')),0,1000),
    ];
  }
  $report['recommendations']=$recommendations;
  $report['not_assessable']=array_map(static fn($value): string => mb_substr(trim((string)$value),0,500),array_slice(is_array($report['not_assessable']??null)?$report['not_assessable']:[],0,20));
  $report['disclaimer']=mb_substr(trim((string)($report['disclaimer']??'本结果仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。')),0,1000);
  return $report;
}

function ai_dentist_provider_call(string $model, array $messages, array $settings): array {
  if (!function_exists('curl_init')) throw new RuntimeException('服务器 PHP 尚未安装 cURL 扩展。');
  $runtime = ai_dentist_runtime_config();
  $timeout = isset($settings['_transport_timeout_seconds'])
    ? min(max((int)$settings['_transport_timeout_seconds'],3),180)
    : min(max((int)$settings['request_timeout_seconds'],15),180);
  $connectTimeout = isset($settings['_connect_timeout_seconds'])
    ? min(max((int)$settings['_connect_timeout_seconds'],2),12)
    : 12;
  $timeoutMs = isset($settings['_transport_timeout_ms'])
    ? min(max((int)$settings['_transport_timeout_ms'],1000),180000)
    : $timeout*1000;
  $connectTimeoutMs = isset($settings['_connect_timeout_ms'])
    ? min(max((int)$settings['_connect_timeout_ms'],500),12000)
    : $connectTimeout*1000;
  $connectTimeoutMs=min($connectTimeoutMs,$timeoutMs);
  @set_time_limit((int)ceil($timeoutMs/1000)+15);
  $payload = [
    'model'=>$model,
    'messages'=>$messages,
    'temperature'=>(float)$settings['temperature'],
    'enable_thinking'=>false,
    'response_format'=>['type'=>'json_object'],
  ];
  if (isset($settings['_max_tokens'])) $payload['max_tokens'] = min(max((int)$settings['_max_tokens'],64),8192);
  foreach ($messages as $message) {
    foreach (is_array($message['content']??null)?$message['content']:[] as $part) {
      if ((bool)($settings['high_resolution_images']??true) && is_array($part) && ($part['type']??'')==='image_url') {
        $payload['vl_high_resolution_images']=true;
        break 2;
      }
    }
  }
  $json = json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if ($json === false) throw new RuntimeException('AI 请求内容无法编码。');
  $requestId = '';
  $started = microtime(true);
  $transportDeadline=$started+($timeoutMs/1000);
  $ch = curl_init((string)$runtime['BAILIAN_OPENAI_BASE_URL'] . '/chat/completions');
  $curlOptions=[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_NOSIGNAL=>true,
    CURLOPT_CONNECTTIMEOUT_MS=>$connectTimeoutMs,
    CURLOPT_TIMEOUT_MS=>$timeoutMs,
    CURLOPT_HTTPHEADER=>[
      'Authorization: Bearer ' . (string)$runtime['BAILIAN_API_KEY'],
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS=>$json,
    CURLOPT_HEADERFUNCTION=>static function($curl,string $header) use (&$requestId): int {
      if (stripos($header,'x-request-id:')===0) $requestId=trim(substr($header,13));
      return strlen($header);
    },
  ];
  // 七视图调用传入毫秒级预算。即使底层 DNS/TLS/代理出现异常等待，
  // 进度回调也会在硬截止到达后主动中止，避免长期占用 PHP-FPM Worker。
  // 宝塔的部分 PHP/cURL 构建没有 CURLOPT_XFERINFOFUNCTION，因此必须兼容
  // 旧版 CURLOPT_PROGRESSFUNCTION；两者都缺失时仍由 CURLOPT_TIMEOUT_MS 截止。
  if(isset($settings['_transport_timeout_ms'])){
    $progressCallback=static function($curl,$downloadTotal,$downloadNow,$uploadTotal,$uploadNow) use ($transportDeadline): int {
      return microtime(true)>=$transportDeadline?1:0;
    };
    $progressOption=null;
    if(defined('CURLOPT_XFERINFOFUNCTION'))$progressOption=(int)constant('CURLOPT_XFERINFOFUNCTION');
    elseif(defined('CURLOPT_PROGRESSFUNCTION'))$progressOption=(int)constant('CURLOPT_PROGRESSFUNCTION');
    if($progressOption!==null){
      $curlOptions[CURLOPT_NOPROGRESS]=false;
      $curlOptions[$progressOption]=$progressCallback;
    }
  }
  curl_setopt_array($ch,$curlOptions);
  $raw = curl_exec($ch);
  $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  $curlErrno = curl_errno($ch);
  $curlError = curl_error($ch);
  curl_close($ch);
  $latency = (int)round((microtime(true)-$started)*1000);
  if ($raw === false) throw new AiDentistProviderException('连接百炼视觉模型失败：'.$curlError,$status,$requestId,$curlErrno);
  $body = json_decode((string)$raw,true);
  if ($status < 200 || $status >= 300) {
    $providerError = is_array($body) ? (string)($body['error']['message'] ?? $body['message'] ?? '') : '';
    throw new AiDentistProviderException('百炼视觉模型请求失败'.($providerError!==''?'：'.$providerError:''),$status,$requestId);
  }
  $content = $body['choices'][0]['message']['content'] ?? '';
  if (is_array($content)) {
    $content = implode('',array_map(static fn($item): string => is_array($item)?(string)($item['text']??''):(string)$item,$content));
  }
  if (!is_string($content) || trim($content)==='') throw new AiDentistProviderException('百炼视觉模型没有返回内容。',$status,$requestId);
  return [
    'content'=>$content,
    'model'=>(string)($body['model'] ?? $model),
    'finish_reason'=>(string)($body['choices'][0]['finish_reason'] ?? ''),
    'request_id'=>$requestId !== '' ? $requestId : (string)($body['id'] ?? ''),
    'http_status'=>$status,
    'input_tokens'=>(int)($body['usage']['prompt_tokens'] ?? 0),
    'output_tokens'=>(int)($body['usage']['completion_tokens'] ?? 0),
    'latency_ms'=>$latency,
  ];
}

function ai_dentist_log(PDO $pdo, int $userId, ?int $sessionId, string $operation, string $model, bool $success, array $meta=[], string $error=''): void {
  $stmt = $pdo->prepare('INSERT INTO ai_dentist_call_logs(public_id,user_id,session_id,operation,model_name,success,http_status,provider_request_id,input_tokens,output_tokens,latency_ms,error_message) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([
    public_id(),$userId,$sessionId,$operation,mb_substr($model,0,96),$success?1:0,
    (int)($meta['http_status']??0) ?: null,mb_substr((string)($meta['request_id']??''),0,160) ?: null,
    (int)($meta['input_tokens']??0),(int)($meta['output_tokens']??0),(int)($meta['latency_ms']??0),
    $error!==''?mb_substr($error,0,1000):null,
  ]);
}

function ai_dentist_daily_usage(PDO $pdo, int $userId): int {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_dentist_call_logs WHERE user_id=? AND success=1 AND created_at>=CURDATE()");
  $stmt->execute([$userId]);
  return (int)$stmt->fetchColumn();
}

function ai_dentist_session(PDO $pdo, int $userId, string $publicId): array {
  $stmt = $pdo->prepare("SELECT s.*,m.public_id AS member_public_id,m.name AS member_name FROM ai_dentist_sessions s INNER JOIN family_members m ON m.id=s.member_id WHERE s.public_id=? AND s.user_id=? LIMIT 1");
  $stmt->execute([mb_substr($publicId,0,32),$userId]);
  $row = $stmt->fetch();
  if (!$row) json_response(['ok'=>false,'error'=>'未找到该 AI 牙医报告。'],404);
  return $row;
}

function ai_dentist_session_payload(PDO $pdo, array $session, bool $withMessages=true): array {
  $stmt = $pdo->prepare('SELECT d.public_id,d.created_at,si.sort_order FROM ai_dentist_session_images si INNER JOIN detections d ON d.id=si.detection_id WHERE si.session_id=? ORDER BY si.sort_order ASC');
  $stmt->execute([(int)$session['id']]);
  $images = $stmt->fetchAll();
  $messages = [];
  if ($withMessages) {
    $stmt = $pdo->prepare('SELECT public_id,role,content,created_at FROM ai_dentist_messages WHERE session_id=? ORDER BY id ASC LIMIT 80');
    $stmt->execute([(int)$session['id']]);
    $messages = $stmt->fetchAll();
  }
  return [
    'public_id'=>(string)$session['public_id'],
    'member'=>['public_id'=>(string)$session['member_public_id'],'name'=>(string)$session['member_name']],
    'title'=>(string)$session['title'],
    'symptoms'=>(string)($session['symptoms']??''),
    'use_history'=>(bool)$session['use_history'],
    'use_local_results'=>(bool)$session['use_local_results'],
    'status'=>(string)$session['status'],
    'risk_level'=>(string)$session['risk_level'],
    'summary'=>(string)($session['summary']??''),
    'report'=>$session['report_json'] ? json_decode((string)$session['report_json'],true) : null,
    'images'=>$images,
    'messages'=>$messages,
    'created_at'=>(string)$session['created_at'],
    'completed_at'=>$session['completed_at'],
  ];
}
