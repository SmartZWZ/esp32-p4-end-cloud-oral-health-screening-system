<?php
declare(strict_types=1);
require_once __DIR__ . '/ai_dentist_common.php';

function family_report_row(PDO $pdo, int $userId, string $publicId, bool $lock=false): array {
  $sql = "SELECT r.*,m.public_id AS member_public_id,m.name AS member_name,m.relationship,m.gender,m.birth_date,
                 capture.public_id AS capture_public_id,capture.completed_at AS capture_completed_at,
                 capture.updated_at AS capture_updated_at
          FROM family_reports r
          INNER JOIN family_members m ON m.id=r.member_id
          LEFT JOIN capture_sessions capture ON capture.id=r.capture_session_id
          WHERE r.public_id=? AND r.user_id=? LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
  $stmt = $pdo->prepare($sql);
  $stmt->execute([mb_substr(trim($publicId),0,32),$userId]);
  $row = $stmt->fetch();
  if (!$row) json_response(['ok'=>false,'error'=>'口腔综合报告不存在或无权访问。'],404);
  return $row;
}

function family_report_regions(): array {
  return [
    'front_bite'=>['index'=>1,'name'=>'正面咬合','focus'=>'观察前牙、正面咬合关系、牙列中线及可见牙龈。'],
    'left_bite'=>['index'=>2,'name'=>'左侧咬合','focus'=>'图片最右边应为受检者左侧嘴角，图片最左边为牙齿；观察左侧后牙和外侧牙面，不要求必须拍到最后侧牙齿。'],
    'right_bite'=>['index'=>3,'name'=>'右侧咬合','focus'=>'图片最左边应为受检者右侧嘴角，图片最右边为牙齿；观察右侧后牙和外侧牙面，不要求必须拍到最后侧牙齿。'],
    'upper_left_open'=>['index'=>4,'name'=>'左上牙列','focus'=>'观察左上牙列、咬合面及邻近可见牙龈。'],
    'upper_right_open'=>['index'=>5,'name'=>'右上牙列','focus'=>'观察右上牙列、咬合面及邻近可见牙龈。'],
    'lower_left_open'=>['index'=>6,'name'=>'左下牙列','focus'=>'观察左下牙列、咬合面及邻近可见牙龈。'],
    'lower_right_open'=>['index'=>7,'name'=>'右下牙列','focus'=>'观察右下牙列、咬合面及邻近可见牙龈。'],
  ];
}

function family_report_source_fingerprint(array $images): string {
  $parts=[];
  foreach($images as $image){
    $parts[]=(string)($image['capture_region_id']??'free').'|'.(string)$image['public_id'].'|'.
      (string)($image['updated_at']??$image['created_at']??'').'|'.(string)($image['image_bytes']??0);
  }
  return hash('sha256',implode("\n",$parts));
}

function family_report_source_snapshot(array $images,string $mode,array $session=[]): array {
  $regions=family_report_regions();$items=[];
  foreach($images as $index=>$image){
    $region=(string)($image['capture_region_id']??'');$path=image_file_path((string)$image['image_path']);
    $items[]=[
      'sort_order'=>$index,'public_id'=>(string)$image['public_id'],'region_id'=>$region?:null,
      'region_index'=>$region!==''?(int)($regions[$region]['index']??($image['capture_region_index']??0)):null,
      'region_name'=>$region!==''?(string)($regions[$region]['name']??$region):'快速观察照片 '.($index+1),
      'created_at'=>(string)$image['created_at'],'sha256'=>$path?hash_file('sha256',$path):null,
      'source_type'=>$image['device_id']===null?'local_upload':'device_capture',
    ];
  }
  return [
    'schema_version'=>1,'source_mode'=>$mode,
    'capture_public_id'=>$session['public_id']??null,
    'capture_completed_at'=>$session['completed_at']??null,
    'captured_at'=>date(DATE_ATOM),'images'=>$items,
  ];
}

function family_report_latest_images(PDO $pdo, int $userId, int $memberId, int $limit=6): array {
  $stmt = $pdo->prepare(
    "SELECT id,public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,created_at,updated_at,
            NULL AS capture_region_id,NULL AS capture_region_index
     FROM detections
     WHERE user_id=? AND member_id=? AND source_detection_id IS NULL
     ORDER BY id DESC LIMIT 120"
  );
  $stmt->execute([$userId,$memberId]);
  $images = [];
  $seen = [];
  foreach ($stmt->fetchAll() as $row) {
    $stored = (string)$row['image_path'];
    if (isset($seen[$stored])) continue;
    $path = image_file_path($stored);
    if (!$path) continue;
    $seen[$stored] = true;
    $images[] = $row;
    if (count($images) >= $limit) break;
  }
  return $images;
}

function family_report_capture_source(PDO $pdo, int $userId, int $memberId, string $capturePublicId): array {
  $sessionStmt = $pdo->prepare(
    "SELECT id,public_id,status,completed_at,updated_at FROM capture_sessions
     WHERE public_id=? AND user_id=? AND member_id=? LIMIT 1"
  );
  $sessionStmt->execute([$capturePublicId,$userId,$memberId]);
  $session = $sessionStmt->fetch();
  if (!$session) json_response(['ok'=>false,'error'=>'指定的全口采集档案不存在或不属于当前成员。'],404);
  $stmt = $pdo->prepare(
    "SELECT id,public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,created_at,updated_at,
            capture_region_id,capture_region_index
     FROM detections
     WHERE user_id=? AND member_id=? AND capture_session_id=? AND source_detection_id IS NULL
       AND capture_region_index BETWEEN 1 AND 7
     ORDER BY capture_region_index ASC,id ASC"
  );
  $stmt->execute([$userId,$memberId,(int)$session['id']]);
  $images=[];$mask=0;
  foreach ($stmt->fetchAll() as $row) {
    $index=(int)$row['capture_region_index'];
    if (($mask & (1 << ($index-1))) !== 0) continue;
    if (!image_file_path((string)$row['image_path'])) continue;
    $mask |= 1 << ($index-1);$images[]=$row;
  }
  if (count($images)!==7 || $mask!==127) {
    json_response(['ok'=>false,'error'=>'该全口采集档案资料不完整，必须保留七个标准位置后才能生成报告；补图和替换后的照片同样有效。'],422);
  }
  return ['session'=>$session,'images'=>$images,'fingerprint'=>family_report_source_fingerprint($images)];
}

function family_report_archives(PDO $pdo,int $userId): array {
  $stmt=$pdo->prepare(
    "SELECT cs.id,cs.public_id,cs.member_id,cs.status,cs.completed_at,cs.updated_at,cs.created_at,
            member.public_id AS member_public_id,member.name AS member_name,
            device.display_name AS device_name,
            (SELECT COUNT(*) FROM family_reports report WHERE report.capture_session_id=cs.id) AS report_count
     FROM capture_sessions cs
     INNER JOIN family_members member ON member.id=cs.member_id AND member.status='active'
     LEFT JOIN devices device ON device.id=cs.device_id
     WHERE cs.user_id=? ORDER BY COALESCE(cs.completed_at,cs.updated_at) DESC,cs.id DESC LIMIT 80"
  );
  $stmt->execute([$userId]);$sessions=$stmt->fetchAll();if(!$sessions)return [];
  $byId=[];foreach($sessions as $row){$id=(int)$row['id'];$row['images']=[];$row['completed_mask_actual']=0;$byId[$id]=$row;}
  $ids=array_keys($byId);$marks=implode(',',array_fill(0,count($ids),'?'));
  $images=$pdo->prepare("SELECT id,capture_session_id,public_id,device_id,capture_region_id,capture_region_index,image_path,created_at FROM detections WHERE user_id=? AND capture_session_id IN ({$marks}) AND source_detection_id IS NULL AND capture_region_index BETWEEN 1 AND 7 ORDER BY capture_region_index,id");
  $images->execute(array_merge([$userId],$ids));
  foreach($images->fetchAll() as $image){$sessionId=(int)$image['capture_session_id'];if(!isset($byId[$sessionId])||!image_file_path((string)$image['image_path']))continue;$index=(int)$image['capture_region_index'];$bit=1<<($index-1);if(((int)$byId[$sessionId]['completed_mask_actual']&$bit)!==0)continue;$byId[$sessionId]['completed_mask_actual']|=$bit;$byId[$sessionId]['images'][]=['public_id'=>(string)$image['public_id'],'region_id'=>(string)$image['capture_region_id'],'region_index'=>$index,'source_type'=>$image['device_id']===null?'local_upload':'device_capture','created_at'=>(string)$image['created_at']];}
  $result=[];foreach($byId as $row){$row['completed_count_actual']=count($row['images']);$row['is_complete']=$row['completed_count_actual']===7&&(int)$row['completed_mask_actual']===127;unset($row['id'],$row['member_id']);$result[]=$row;}
  return $result;
}

function family_report_queue_analysis(PDO $pdo, array $source): int {
  $publicId = public_id();
  $stmt = $pdo->prepare(
    "INSERT INTO detections(
      public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,
      upload_mode,model_pipeline,status,source_detection_id,progress_step,progress_total,progress_label,report_text
    ) VALUES(?,?,?,?,?,?,?,?,'detect','all_models','received',?,0,5,'等待运行全部模型联合分析。','口腔综合报告已加入全部模型联合分析队列。')"
  );
  $stmt->execute([
    $publicId,(int)$source['user_id'],
    $source['device_id']===null?null:(int)$source['device_id'],
    $source['member_id']===null?null:(int)$source['member_id'],
    (string)$source['image_path'],$source['image_width'],$source['image_height'],
    (int)$source['image_bytes'],(int)$source['id'],
  ]);
  return (int)$pdo->lastInsertId();
}

function family_report_ai_call(PDO $pdo, array $settings, array $messages, int $userId, string $operation): array {
  $models = array_values(array_unique(array_filter([
    trim((string)$settings['primary_model']),
    trim((string)$settings['fallback_model']),
  ])));
  $last = null;
  foreach ($models as $model) {
    try {
      $result = ai_dentist_provider_call($model,$messages,$settings);
      ai_dentist_log($pdo,$userId,null,$operation,$model,true,$result);
      return $result;
    } catch (AiDentistProviderException $error) {
      $last = $error;
      ai_dentist_log($pdo,$userId,null,$operation,$model,false,[
        'http_status'=>$error->httpStatus,'request_id'=>$error->requestId,
      ],$error->getMessage());
    }
  }
  throw $last ?: new RuntimeException('没有可用的 AI 牙医模型。');
}

function family_report_images(PDO $pdo, int $reportId): array {
  $stmt = $pdo->prepare(
    "SELECT ri.id,ri.sort_order,ri.capture_region_id,ri.capture_region_index,
            ri.source_public_id_snapshot,ri.source_created_at_snapshot,ri.source_sha256,
            ri.ai_status,ri.ai_report_json,ri.ai_model_name,ri.ai_error_message,ri.model_skipped,
            COALESCE(source.public_id,ri.source_public_id_snapshot) AS source_public_id,
            COALESCE(source.created_at,ri.source_created_at_snapshot) AS source_created_at,
            source.image_width,source.image_height,
            analysis.public_id AS analysis_public_id,analysis.status AS model_status,
            analysis.progress_step AS model_progress_step,analysis.progress_total AS model_progress_total,
            analysis.progress_label AS model_progress_label,analysis.report_text AS model_report_text,
            analysis.result_json AS model_result_json
     FROM family_report_images ri
     LEFT JOIN detections source ON source.id=ri.source_detection_id
     LEFT JOIN detections analysis ON analysis.id=ri.analysis_detection_id
     WHERE ri.report_id=? ORDER BY ri.sort_order ASC"
  );
  $stmt->execute([$reportId]);
  return $stmt->fetchAll();
}

function family_report_json(?string $value): ?array {
  if ($value===null || trim($value)==='') return null;
  $decoded=json_decode($value,true);
  return is_array($decoded)?$decoded:null;
}

function family_report_progress(array $report, array $images): array {
  $imageCount=count($images);
  $aiDone=0;$modelDone=0;
  foreach ($images as $image) {
    if (in_array((string)$image['ai_status'],['completed','failed'],true)) $aiDone++;
    if ((bool)$image['model_skipped'] || in_array((string)($image['model_status']??''),['completed','failed'],true)) $modelDone++;
  }
  $summaryDone=!empty($report['clinical_summary_json'])?1:0;
  $modelSummaryDone=!empty($report['model_summary_json'])?1:0;
  return [
    'step'=>$aiDone+$modelDone+$summaryDone+$modelSummaryDone,
    'total'=>$imageCount*2+2,
    'ai_done'=>$aiDone,
    'model_done'=>$modelDone,
    'image_count'=>$imageCount,
  ];
}

function family_report_payload(PDO $pdo, array $report): array {
  $images=family_report_images($pdo,(int)$report['id']);
  $progress=family_report_progress($report,$images);
  $regions=family_report_regions();
  $items=[];
  foreach ($images as $image) {
    $regionId=(string)($image['capture_region_id']??'');
    $items[]=[
      'sort_order'=>(int)$image['sort_order'],
      'image_index'=>(int)$image['sort_order']+1,
      'source_public_id'=>$image['source_public_id'],
      'source_created_at'=>$image['source_created_at'],
      'capture_region_id'=>$regionId?:null,
      'capture_region_index'=>$image['capture_region_index']===null?null:(int)$image['capture_region_index'],
      'capture_region_name'=>$regionId!==''?(string)($regions[$regionId]['name']??$regionId):'照片 '.((int)$image['sort_order']+1),
      'image_width'=>$image['image_width']===null?null:(int)$image['image_width'],
      'image_height'=>$image['image_height']===null?null:(int)$image['image_height'],
      'ai_status'=>(string)$image['ai_status'],
      'ai_report'=>family_report_json($image['ai_report_json']),
      'ai_model_name'=>$image['ai_model_name'],
      'ai_error'=>$image['ai_error_message'],
      'model_skipped'=>(bool)$image['model_skipped'],
      'analysis_public_id'=>$image['analysis_public_id'],
      'model_status'=>$image['model_status'],
      'model_progress_step'=>(int)($image['model_progress_step']??0),
      'model_progress_total'=>(int)($image['model_progress_total']??0),
      'model_progress_label'=>$image['model_progress_label'],
      'model_report_text'=>$image['model_report_text'],
      'model_result'=>family_report_json($image['model_result_json']),
    ];
  }
  $snapshot=family_report_json($report['source_snapshot_json']??null);
  $sourceMode=(string)($report['source_mode']??'recent_images');$sourceChanged=false;
  if($sourceMode==='seven_view_archive'){
    if(empty($report['capture_session_id']))$sourceChanged=true;
    else{
      $current=$pdo->prepare("SELECT id,public_id,updated_at,created_at,image_bytes,capture_region_id,capture_region_index,image_path FROM detections WHERE user_id=? AND capture_session_id=? AND source_detection_id IS NULL AND capture_region_index BETWEEN 1 AND 7 ORDER BY capture_region_index,id");
      $current->execute([(int)$report['user_id'],(int)$report['capture_session_id']]);$rows=[];$mask=0;
      foreach($current->fetchAll() as $row){$index=(int)$row['capture_region_index'];$bit=1<<($index-1);if(($mask&$bit)!==0||!image_file_path((string)$row['image_path']))continue;$mask|=$bit;$rows[]=$row;}
      $sourceChanged=count($rows)!==7||$mask!==127||!hash_equals((string)($report['source_fingerprint']??''),family_report_source_fingerprint($rows));
    }
  }
  return [
    'public_id'=>(string)$report['public_id'],
    'title'=>(string)$report['title'],
    'member'=>[
      'public_id'=>(string)$report['member_public_id'],'name'=>(string)$report['member_name'],
      'relationship'=>(string)$report['relationship'],'gender'=>(string)$report['gender'],
      'birth_date'=>$report['birth_date'],'age'=>ai_dentist_age($report['birth_date']?(string)$report['birth_date']:null),
    ],
    'symptoms'=>(string)($report['symptoms']??''),
    'source'=>[
      'mode'=>$sourceMode,'label'=>$sourceMode==='seven_view_archive'?'七图全口档案':'最近图片快速观察',
      'capture_public_id'=>(string)($report['capture_public_id']??($snapshot['capture_public_id']??'')),
      'capture_completed_at'=>$report['capture_completed_at']??($snapshot['capture_completed_at']??null),
      'snapshot'=>$snapshot,'changed'=>$sourceChanged,
    ],
    'status'=>(string)$report['status'],
    'progress'=>$progress,
    'progress_label'=>(string)($report['progress_label']??''),
    'clinical_summary'=>family_report_json($report['clinical_summary_json']),
    'model_summary'=>family_report_json($report['model_summary_json']),
    'error_message'=>$report['error_message'],
    'images'=>$items,
    'created_at'=>(string)$report['created_at'],
    'completed_at'=>$report['completed_at'],
  ];
}

function family_report_single_messages(array $settings,array $report,array $image): array {
  $gender=['male'=>'男','female'=>'女','unknown'=>'未填写'][(string)$report['gender']]??'未填写';
  $regions=family_report_regions();$regionId=(string)($image['capture_region_id']??'');$region=$regions[$regionId]??null;
  $context=[
    '照片序号'=>(int)$image['sort_order']+1,
    '标准视角'=>$region?['id'=>$regionId,'名称'=>$region['name'],'观察重点'=>$region['focus']]:'最近图片快速观察（未指定标准视角）',
    '成员'=>['姓名'=>(string)$report['member_name'],'性别'=>$gender,'年龄'=>ai_dentist_age($report['birth_date']?(string)$report['birth_date']:null)],
    '用户症状'=>(string)$report['symptoms']!==''?(string)$report['symptoms']:'未填写',
  ];
  $instruction=(string)$settings['system_prompt'].
    "\n本次任务是单张原始照片的独立观察。你没有获得任何本地模型输出，也不得假设模型结果存在。".
    "\n不要参考旧报告，不要跨照片推断。图片可以来自设备采集、补图或替换，这不影响观察资格。".
    "\n如果画面不清晰，只在 image_quality 中如实说明可见范围和复拍建议；不得因为图片曾被替换而拒绝观察。";
  $content=[
    ['type'=>'text','text'=>"请只观察这一张口腔照片，并生成该照片的独立报告。\n".
      json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n".ai_dentist_report_instruction()],
    ['type'=>'image_url','image_url'=>['url'=>ai_dentist_signed_image_url([
      'public_id'=>(string)$image['source_public_id']
    ],time()+420)]],
  ];
  return [['role'=>'system','content'=>$instruction],['role'=>'user','content'=>$content]];
}

function family_report_parse_object(string $content): array {
  $clean=trim($content);
  if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su',$clean,$match)) $clean=trim($match[1]);
  $decoded=json_decode($clean,true);
  if (!is_array($decoded)) throw new AiDentistProviderException('模型没有返回有效的结构化 JSON。');
  return $decoded;
}

function family_report_clinical_messages(array $settings,array $report,array $images): array {
  $single=[];$regions=family_report_regions();$sevenView=(string)($report['source_mode']??'recent_images')==='seven_view_archive';
  foreach ($images as $image) {
    if ((string)$image['ai_status']!=='completed') continue;
    $regionId=(string)($image['capture_region_id']??'');
    $single[]=['image_index'=>(int)$image['sort_order']+1,'region_id'=>$regionId?:null,'region_name'=>$regionId!==''?(string)($regions[$regionId]['name']??$regionId):null,'independent_report'=>family_report_json($image['ai_report_json'])];
  }
  $prompt=<<<'PROMPT'
你正在汇总多份“分别查看单张原始照片后生成的独立观察报告”。
你只能依据这些独立 AI 观察报告汇总，不得使用、猜测或请求任何本地检测模型结果。
如果本请求包含七个标准视角的原图，你还必须对照原图进行一次七图统一复核：合并同一区域的重复描述，指出跨视角支持或冲突，并按上颌、下颌、左侧、右侧、前牙区和后牙区整理。
不要因为多张照片出现类似文字就自动提高诊断确定性；只有不同视角中清晰可见且位置一致时，才能写为“跨视角支持”。
不强制给出 FDI 牙号；没有已经确认的牙号证据时使用区域描述。
图片清晰度不足时记录为观察限制，不得因为图片来自补图或替换而拒绝整份报告。
只返回 JSON：
{
  "overall_risk":"unknown、low、medium 或 high",
  "summary":"不超过300字的成员级初步观察总结",
  "collection_quality":{"usable_views":7,"limited_views":["视角名称"],"summary":"七图采集可用性总结"},
  "region_summaries":[{"region":"区域名称","observations":["可见表现"],"limitations":["观察限制"]}],
  "cross_image_observations":[{"images":[1,2],"regions":["视角名称"],"observation":"跨照片观察","certainty":"limited、supported 或 conflicting"}],
  "recommendations":[{"priority":"routine、soon 或 urgent","action":"建议","reason":"原因"}],
  "not_assessable":["无法仅凭照片判断的事项"],
  "disclaimer":"本报告仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。"
}
PROMPT;
  $content=[['type'=>'text','text'=>$prompt."\n成员：".(string)$report['member_name']."\n来源模式：".($sevenView?'完整七图全口档案':'最近图片快速观察')."\n单图独立报告：".json_encode($single,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
  if($sevenView){
    foreach($images as $image){$regionId=(string)($image['capture_region_id']??'');$content[]=['type'=>'text','text'=>'下面是 '.(string)($regions[$regionId]['name']??('照片 '.((int)$image['sort_order']+1))).' 原图：'];$content[]=['type'=>'image_url','image_url'=>['url'=>ai_dentist_signed_image_url(['public_id'=>(string)$image['source_public_id']],time()+600)]];}
  }
  return [
    ['role'=>'system','content'=>(string)$settings['system_prompt']."\n禁止使用本地模型输出；本请求中也不会提供它们。"],
    ['role'=>'user','content'=>$sevenView?$content:$content[0]['text']],
  ];
}

function family_report_compact_model(array $image): array {
  $raw=family_report_json($image['model_result_json']);
  $regionId=(string)($image['capture_region_id']??'');
  $biteViews=['front_bite','left_bite','right_bite'];
  $openViews=['upper_left_open','upper_right_open','lower_left_open','lower_right_open'];
  $policy=in_array($regionId,$biteViews,true)
    ? ['both'=>['Abrasion'],'calculus_seg'=>['Calculus']]
    : (in_array($regionId,$openViews,true)?['both'=>['Abrasion'],'tooth_outline'=>['MicrocariesDarkline']]:null);
  if (!$raw) return [
    'image_index'=>(int)$image['sort_order']+1,
    'region_id'=>$regionId?:null,
    'status'=>(bool)$image['model_skipped']?'skipped':(string)($image['model_status']??'failed'),
    'message'=>(string)($image['model_report_text']??''),
  ];
  $pipelines=[];
  foreach (is_array($raw['pipeline_results']??null)?$raw['pipeline_results']:[] as $stage) {
    if (!is_array($stage)) continue;
    $pipeline=(string)($stage['pipeline']??'');
    if($policy!==null&&!array_key_exists($pipeline,$policy))continue;
    $allowed=$policy[$pipeline]??null;
    $findings=[];
    foreach (array_slice(is_array($stage['findings']??null)?$stage['findings']:[],0,60) as $finding) {
      if (!is_array($finding)) continue;
      $label=(string)($finding['label']??'');
      if($allowed!==null&&!in_array($label,$allowed,true))continue;
      $findings[]=[
        'label'=>$label,
        'confidence'=>(float)($finding['confidence']??0),
        'model'=>(string)($finding['model']??''),
      ];
    }
    $counts=[];foreach($findings as $finding){$label=(string)$finding['label'];$counts[$label]=($counts[$label]??0)+1;}
    $pipelines[]=[
      'pipeline'=>$pipeline,'title'=>(string)($stage['title']??''),
      'status'=>(string)($stage['status']??''),'summary'=>$policy===null?(string)($stage['summary_text']??''):'仅保留本视角规定的展示项目。',
      'counts'=>$policy===null?(is_array($stage['counts']??null)?$stage['counts']:[]):$counts,'findings'=>$findings,
      'error'=>(string)($stage['error']??''),
    ];
  }
  return [
    'image_index'=>(int)$image['sort_order']+1,
    'region_id'=>$regionId?:null,
    'display_scope'=>$policy===null?'all':(in_array($regionId,$biteViews,true)?'abrasion_calculus':'abrasion_microcaries'),
    'status'=>(string)($raw['completion_status']??'completed'),
    'pipelines'=>$pipelines,
  ];
}

function family_report_model_messages(array $settings,array $images): array {
  $data=array_map('family_report_compact_model',$images);
  $prompt=<<<'PROMPT'
你是实验性视觉模型输出的技术记录员，不是口腔诊断者。
这些本地模型准确度有限。只能忠实整理模型返回的类别、数量、置信度和失败状态。
严禁根据模型输出判断用户是否患病，严禁改变 AI 原图观察的结论，严禁声称多个模型重复检出就更可信。
每张照片必须单独写模型输出说明，然后给出纯技术性的总体统计。传入数据已经按标准视角过滤，严禁补写未提供的模型或类别。
正面、左侧和右侧咬合位只整理牙磨损（Abrasion）与牙结石（Calculus）；四个张口牙列视角只整理牙磨损（Abrasion）与微龋暗线（MicrocariesDarkline）。
只返回 JSON：
{
  "notice":"以下为实验性本地模型的原始输出，仅用于技术展示，不参与AI牙医的独立判断，不作为诊断依据。",
  "summary":"模型运行和输出的客观汇总，不写医学结论",
  "image_reports":[{"image_index":1,"region_id":"标准视角ID或null","status":"completed、partial、failed 或 skipped","narrative":"只复述该图模型输出","limitations":["失败或局限"]}],
  "limitations":["模型准确度、重复检测和照片质量等限制"]
}
PROMPT;
  return [
    ['role'=>'system','content'=>'你只能编写实验模型技术报告，不能进行医学判断，也不能查看或改写 AI 牙医原图观察。'],
    ['role'=>'user','content'=>$prompt."\n实验模型数据：".json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)],
  ];
}

function family_report_normalize_clinical(array $value): array {
  $risk=strtolower((string)($value['overall_risk']??'unknown'));
  if (!in_array($risk,['unknown','low','medium','high'],true)) $risk='unknown';
  return [
    'overall_risk'=>$risk,
    'summary'=>mb_substr(trim((string)($value['summary']??'汇总已完成。')),0,3000),
    'collection_quality'=>is_array($value['collection_quality']??null)?$value['collection_quality']:[],
    'region_summaries'=>array_slice(is_array($value['region_summaries']??null)?$value['region_summaries']:[],0,12),
    'cross_image_observations'=>array_slice(is_array($value['cross_image_observations']??null)?$value['cross_image_observations']:[],0,20),
    'recommendations'=>array_slice(is_array($value['recommendations']??null)?$value['recommendations']:[],0,20),
    'not_assessable'=>array_slice(is_array($value['not_assessable']??null)?$value['not_assessable']:[],0,20),
    'disclaimer'=>mb_substr(trim((string)($value['disclaimer']??'本报告仅用于口腔照片辅助筛查，不替代口腔医生面诊、探诊或影像学检查。')),0,1000),
  ];
}

function family_report_normalize_model(array $value): array {
  return [
    'notice'=>'以下为实验性本地模型的原始输出，仅用于技术展示，不参与AI牙医的独立判断，不作为诊断依据。',
    'summary'=>mb_substr(trim((string)($value['summary']??'模型输出整理已完成。')),0,4000),
    'image_reports'=>array_slice(is_array($value['image_reports']??null)?$value['image_reports']:[],0,7),
    'limitations'=>array_slice(is_array($value['limitations']??null)?$value['limitations']:[],0,30),
  ];
}

function family_report_advance(PDO $pdo,array $user,array $settings,array $report): array {
  $userId=(int)$user['id'];$reportId=(int)$report['id'];
  $pdo->prepare("UPDATE family_report_images SET ai_status='queued',ai_error_message='上次单图观察中断，已重新排队。' WHERE report_id=? AND ai_status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)")
    ->execute([$reportId]);
  $images=family_report_images($pdo,$reportId);

  foreach ($images as $image) {
    if ((string)$image['ai_status']!=='queued') continue;
    $pdo->prepare("UPDATE family_report_images SET ai_status='processing',ai_error_message=NULL WHERE id=? AND ai_status='queued'")
      ->execute([(int)$image['id']]);
    $regionId=(string)($image['capture_region_id']??'');$region=family_report_regions()[$regionId]??null;
    $label='正在观察'.($region?('“'.$region['name'].'”'):('第 '.((int)$image['sort_order']+1).' 张')).'（'.((int)$image['sort_order']+1).'/'.count($images).'）';
    $pdo->prepare("UPDATE family_reports SET status='processing',progress_label=? WHERE id=?")->execute([$label,$reportId]);
    try {
      $result=family_report_ai_call($pdo,$settings,family_report_single_messages($settings,$report,$image),$userId,'family_image');
      $parsed=ai_dentist_parse_report((string)$result['content']);
      $json=json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $pdo->prepare("UPDATE family_report_images SET ai_status='completed',ai_report_json=?,ai_model_name=? WHERE id=?")
        ->execute([$json,mb_substr((string)$result['model'],0,96),(int)$image['id']]);
    } catch (Throwable $error) {
      $pdo->prepare("UPDATE family_report_images SET ai_status='failed',ai_error_message=? WHERE id=?")
        ->execute([mb_substr($error->getMessage(),0,1000),(int)$image['id']]);
    }
    return family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']));
  }

  $pending=false;
  foreach ($images as $image) {
    if (!(bool)$image['model_skipped'] && !in_array((string)($image['model_status']??''),['completed','failed'],true)) {
      $pending=true;break;
    }
  }
  if ($pending) {
    $pdo->prepare("UPDATE family_reports SET status='waiting_models',progress_label='单图 AI 观察已完成，正在等待本地全部模型分析。' WHERE id=?")->execute([$reportId]);
    return family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']));
  }

  $report=family_report_row($pdo,$userId,(string)$report['public_id']);
  if (empty($report['clinical_summary_json'])) {
    $completedAi=array_filter($images,static fn(array $image): bool => (string)$image['ai_status']==='completed');
    if (!$completedAi) {
      $pdo->prepare("UPDATE family_reports SET status='failed',progress_label='所有单图 AI 观察均失败。',error_message='没有可用于汇总的单图 AI 报告。' WHERE id=?")->execute([$reportId]);
      return family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']));
    }
    $pdo->prepare("UPDATE family_reports SET status='compiling',progress_label='正在根据单图 AI 观察生成成员汇总。' WHERE id=?")->execute([$reportId]);
    try {
      $result=family_report_ai_call($pdo,$settings,family_report_clinical_messages($settings,$report,$images),$userId,'family_summary');
      $clinical=family_report_normalize_clinical(family_report_parse_object((string)$result['content']));
      $pdo->prepare('UPDATE family_reports SET clinical_summary_json=? WHERE id=?')->execute([
        json_encode($clinical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$reportId
      ]);
    } catch (Throwable $error) {
      $pdo->prepare("UPDATE family_reports SET status='failed',progress_label='成员汇总生成失败。',error_message=? WHERE id=?")
        ->execute([mb_substr($error->getMessage(),0,1000),$reportId]);
    }
    return family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']));
  }

  if (empty($report['model_summary_json'])) {
    $pdo->prepare("UPDATE family_reports SET status='compiling',progress_label='正在独立整理实验模型输出章节。' WHERE id=?")->execute([$reportId]);
    $allSkipped=count(array_filter($images,static fn(array $image): bool => (bool)$image['model_skipped']))===count($images);
    try {
      if ($allSkipped) {
        $model=[
          'notice'=>'以下为实验性本地模型的原始输出，仅用于技术展示，不参与AI牙医的独立判断，不作为诊断依据。',
          'summary'=>'本次报告已按用户选择跳过本地模型分析。',
          'image_reports'=>array_map(static fn(array $image): array => [
            'image_index'=>(int)$image['sort_order']+1,'region_id'=>$image['capture_region_id']??null,'status'=>'skipped','narrative'=>'已跳过本地模型分析。','limitations'=>[]
          ],$images),
          'limitations'=>['本报告不包含本地模型输出。'],
        ];
      } else {
        $result=family_report_ai_call($pdo,$settings,family_report_model_messages($settings,$images),$userId,'family_model_report');
        $model=family_report_normalize_model(family_report_parse_object((string)$result['content']));
      }
      $hasPartial=false;
      foreach ($images as $image) {
        $raw=family_report_json($image['model_result_json']);
        if ((string)$image['ai_status']==='failed' || (bool)$image['model_skipped'] ||
           (string)($image['model_status']??'')==='failed' || (string)($raw['completion_status']??'')==='partial') {
          $hasPartial=true;
        }
      }
      $status=$hasPartial?'partial':'completed';
      $pdo->prepare("UPDATE family_reports SET model_summary_json=?,status=?,progress_label=?,completed_at=NOW(),error_message=NULL WHERE id=?")
        ->execute([
          json_encode($model,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
          $status,$status==='partial'?'报告已生成，部分观察或模型任务未完成。':'口腔综合报告已生成。',$reportId
        ]);
    } catch (Throwable $error) {
      $pdo->prepare("UPDATE family_reports SET status='failed',progress_label='实验模型章节整理失败。',error_message=? WHERE id=?")
        ->execute([mb_substr($error->getMessage(),0,1000),$reportId]);
    }
  }
  return family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']));
}

try {
  $user=require_user();$userId=(int)$user['id'];$pdo=db();
  $settings=ai_dentist_settings($pdo);
  $action=(string)($_GET['action']??'bootstrap');

  if ($action==='bootstrap') {
    $members=$pdo->prepare("SELECT public_id,name,relationship,gender,birth_date,is_default FROM family_members WHERE user_id=? AND status='active' ORDER BY is_default DESC,id ASC");
    $members->execute([$userId]);
    $reports=$pdo->prepare("SELECT r.public_id,r.title,r.status,r.image_count,r.source_mode,r.progress_label,r.created_at,r.completed_at,m.public_id AS member_public_id,m.name AS member_name,capture.public_id AS capture_public_id FROM family_reports r INNER JOIN family_members m ON m.id=r.member_id LEFT JOIN capture_sessions capture ON capture.id=r.capture_session_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 60");
    $reports->execute([$userId]);
    json_response(['ok'=>true,'members'=>$members->fetchAll(),'archives'=>family_report_archives($pdo,$userId),'reports'=>$reports->fetchAll()]);
  }
  if ($action==='get') {
    $report=family_report_row($pdo,$userId,(string)($_GET['id']??''));
    json_response(['ok'=>true,'report'=>family_report_payload($pdo,$report)]);
  }
  if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  require_csrf();$data=request_data();

  if ($action==='start') {
    if (!(bool)$settings['enabled']) json_response(['ok'=>false,'error'=>'AI 牙医当前处于维护状态。'],503);
    $member=ai_dentist_member($pdo,$userId,trim((string)($data['member_id']??'')));
    $symptoms=mb_substr(trim((string)($data['symptoms']??'')),0,2000);
    $sourceMode=(string)($data['source_mode']??'seven_view_archive');
    if(!in_array($sourceMode,['seven_view_archive','recent_images'],true))json_response(['ok'=>false,'error'=>'报告来源类型不正确。'],422);
    $capturePublicId=mb_substr(trim((string)($data['capture_session_id']??'')),0,32);$captureSource=null;
    if($sourceMode==='seven_view_archive'){
      if($capturePublicId==='')json_response(['ok'=>false,'error'=>'请选择一份七张图片完整的全口采集档案。'],422);
      $captureSource=family_report_capture_source($pdo,$userId,(int)$member['id'],$capturePublicId);$images=$captureSource['images'];
    }else{$capturePublicId='';$images=family_report_latest_images($pdo,$userId,(int)$member['id'],6);}
    if (!$images) json_response(['ok'=>false,'error'=>'该成员没有可读取的口腔照片。请先上传或采集照片。'],422);
    $required=count($images)+2;
    $used=ai_dentist_daily_usage($pdo,$userId);
    if ((int)$settings['daily_user_limit']>0 && $used+$required>(int)$settings['daily_user_limit']) {
      json_response(['ok'=>false,'error'=>"生成本报告预计需要 {$required} 次 AI 调用，今天的剩余额度不足。"],429);
    }
    $pdo->beginTransaction();
    try {
      $publicId=public_id();$title=(string)$member['name'].($sourceMode==='seven_view_archive'?'的七图口腔综合报告':'的快速口腔观察报告');
      $session=$captureSource['session']??[];$snapshot=family_report_source_snapshot($images,$sourceMode,$session);$fingerprint=$captureSource['fingerprint']??family_report_source_fingerprint($images);
      $stmt=$pdo->prepare("INSERT INTO family_reports(public_id,user_id,member_id,title,symptoms,source_mode,capture_session_id,source_snapshot_json,source_fingerprint,status,image_count,progress_total,progress_label) VALUES(?,?,?,?,?,?,?,?,?,'processing',?,?,?)");
      $progressLabel=$sourceMode==='seven_view_archive'?'已绑定完整七图档案，正在创建七个视角任务。':'已选取最近照片，正在创建快速观察任务。';
      $stmt->execute([$publicId,$userId,(int)$member['id'],$title,$symptoms,$sourceMode,$captureSource?(int)$captureSource['session']['id']:null,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$fingerprint,count($images),count($images)*2+2,$progressLabel]);
      $reportId=(int)$pdo->lastInsertId();
      $link=$pdo->prepare("INSERT INTO family_report_images(report_id,source_detection_id,analysis_detection_id,sort_order,capture_region_id,capture_region_index,source_public_id_snapshot,source_created_at_snapshot,source_sha256,ai_status) VALUES(?,?,?,?,?,?,?,?,?,'queued')");
      foreach ($images as $index=>$image) {
        $analysisId=family_report_queue_analysis($pdo,$image);
        $snapshotImage=$snapshot['images'][$index]??[];
        $link->execute([$reportId,(int)$image['id'],$analysisId,$index,$image['capture_region_id']?:null,$image['capture_region_index']?:null,(string)$image['public_id'],(string)$image['created_at'],$snapshotImage['sha256']??null]);
      }
      $pdo->commit();
      $report=family_report_row($pdo,$userId,$publicId);
      json_response(['ok'=>true,'report'=>family_report_payload($pdo,$report)],201);
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
  }

  if ($action==='advance') {
    $report=family_report_row($pdo,$userId,(string)($data['report_id']??''));
    if (in_array((string)$report['status'],['completed','partial'],true)) json_response(['ok'=>true,'report'=>family_report_payload($pdo,$report)]);
    json_response(['ok'=>true,'report'=>family_report_advance($pdo,$user,$settings,$report)]);
  }

  if ($action==='skip_models') {
    $report=family_report_row($pdo,$userId,(string)($data['report_id']??''));
    $images=family_report_images($pdo,(int)$report['id']);
    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE family_report_images SET model_skipped=1 WHERE report_id=?')->execute([(int)$report['id']]);
      $cancel=$pdo->prepare("UPDATE detections SET status='failed',progress_label='口腔综合报告已跳过本地模型。',report_text='口腔综合报告已跳过本地模型。' WHERE public_id=? AND status='received'");
      foreach ($images as $image) if ($image['analysis_public_id']) $cancel->execute([(string)$image['analysis_public_id']]);
      $pdo->prepare("UPDATE family_reports SET progress_label='已跳过本地模型，准备生成汇总报告。' WHERE id=?")->execute([(int)$report['id']]);
      $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
    json_response(['ok'=>true,'report'=>family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']))]);
  }

  if ($action==='retry') {
    $report=family_report_row($pdo,$userId,(string)($data['report_id']??''));
    if ((string)$report['status']!=='failed') {
      json_response(['ok'=>true,'report'=>family_report_payload($pdo,$report)]);
    }
    $pdo->beginTransaction();
    try {
      if (empty($report['clinical_summary_json'])) {
        $pdo->prepare("UPDATE family_report_images SET ai_status='queued',ai_error_message=NULL WHERE report_id=? AND ai_status='failed'")
          ->execute([(int)$report['id']]);
        $label='正在重试未完成的单图观察或成员汇总。';
        $status='processing';
      } else {
        $label='正在重试实验模型章节整理。';
        $status='compiling';
      }
      $pdo->prepare('UPDATE family_reports SET status=?,progress_label=?,error_message=NULL WHERE id=?')
        ->execute([$status,$label,(int)$report['id']]);
      $pdo->commit();
    } catch (Throwable $error) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $error;
    }
    json_response(['ok'=>true,'report'=>family_report_payload($pdo,family_report_row($pdo,$userId,(string)$report['public_id']))]);
  }

  if ($action==='rename') {
    $report=family_report_row($pdo,$userId,(string)($data['report_id']??''));
    $title=mb_substr(trim((string)($data['title']??'')),0,160);
    if ($title==='') json_response(['ok'=>false,'error'=>'报告名称不能为空。'],422);
    $pdo->prepare('UPDATE family_reports SET title=? WHERE id=?')->execute([$title,(int)$report['id']]);
    json_response(['ok'=>true,'title'=>$title]);
  }

  if ($action==='delete') {
    $report=family_report_row($pdo,$userId,(string)($data['report_id']??''));
    $pdo->prepare('DELETE FROM family_reports WHERE id=? AND user_id=?')->execute([(int)$report['id'],$userId]);
    json_response(['ok'=>true,'deleted_report_id'=>(string)$report['public_id'],'message'=>'报告已删除，原始照片和模型历史记录均已保留。']);
  }
  json_response(['ok'=>false,'error'=>'接口不存在。'],404);
} catch (PDOException $error) {
  error_log('family_reports.php database: '.$error->getMessage());
    json_response(['ok'=>false,'error'=>'口腔综合报告数据库迁移尚未执行，或表结构不完整。'],503);
} catch (Throwable $error) {
  error_log('family_reports.php: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'口腔综合报告暂时不可用：'.$error->getMessage()],503);
}
