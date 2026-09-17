<?php
declare(strict_types=1);
require_once __DIR__ . '/ai_dentist_common.php';

function dental_arch_regions(): array {
  return [
    'front_bite'=>['index'=>1,'label'=>'正面咬合','english'=>'FRONT BITE'],
    'left_bite'=>['index'=>2,'label'=>'左侧咬合','english'=>'LEFT BITE'],
    'right_bite'=>['index'=>3,'label'=>'右侧咬合','english'=>'RIGHT BITE'],
    'upper_left_open'=>['index'=>4,'label'=>'左上牙列','english'=>'UPPER LEFT'],
    'upper_right_open'=>['index'=>5,'label'=>'右上牙列','english'=>'UPPER RIGHT'],
    'lower_left_open'=>['index'=>6,'label'=>'左下牙列','english'=>'LOWER LEFT'],
    'lower_right_open'=>['index'=>7,'label'=>'右下牙列','english'=>'LOWER RIGHT'],
  ];
}

function dental_arch_valid_fdi(string $value): bool {
  return preg_match('/^(1[1-8]|2[1-8]|3[1-8]|4[1-8])$/', $value) === 1;
}

function dental_arch_json(?string $value): ?array {
  if (!$value) return null;
  $decoded = json_decode($value, true);
  return is_array($decoded) ? $decoded : null;
}

function dental_arch_guide(): array {
  $path = APP_ROOT . '/牙齿轮廓与牙号标注处理指南.md';
  if (!is_readable($path)) throw new RuntimeException('牙齿轮廓与牙号标注处理指南不存在。');
  $content = trim((string)file_get_contents($path));
  if ($content === '') throw new RuntimeException('牙齿轮廓与牙号标注处理指南为空。');
  return ['content'=>$content,'sha256'=>hash('sha256',$content)];
}

function dental_arch_settings(PDO $pdo): array {
  $settings = ai_dentist_settings($pdo);
  $settings['dental_arch_model'] = trim((string)($settings['dental_arch_model'] ?? '')) ?: 'qwen3.7-plus';
  $settings['dental_arch_fallback_model'] = trim((string)($settings['dental_arch_fallback_model'] ?? '')) ?: 'qwen3.6-plus';
  $settings['temperature'] = 0.0;
  $settings['high_resolution_images'] = 1;
  $settings['_transport_timeout_seconds'] = min(max((int)($settings['request_timeout_seconds'] ?? 90), 60), 180);
  $settings['_max_tokens'] = 4096;
  return $settings;
}

function dental_arch_call(PDO $pdo, array $job, array $messages, string $operation): array {
  $settings = dental_arch_settings($pdo);
  $settings['_max_tokens'] = $operation === 'dental_arch_joint' ? 8192 : 4096;
  $models = array_values(array_unique(array_filter([
    trim((string)$job['visual_model']),
    trim((string)($job['fallback_model'] ?? '')),
  ])));
  $last = null;
  foreach ($models as $model) {
    try {
      $result = ai_dentist_provider_call($model, $messages, $settings);
      $finishReason = strtolower(trim((string)($result['finish_reason'] ?? '')));
      if (in_array($finishReason, ['length','max_tokens'], true)) {
        throw new AiDentistProviderException(
          '视觉模型输出达到长度上限，JSON 被截断。',
          (int)($result['http_status'] ?? 0),
          (string)($result['request_id'] ?? '')
        );
      }
      try {
        $result['parsed_json'] = dental_arch_parse_provider_json((string)$result['content']);
      } catch (Throwable $parseError) {
        throw new AiDentistProviderException(
          $parseError->getMessage(),
          (int)($result['http_status'] ?? 0),
          (string)($result['request_id'] ?? '')
        );
      }
      ai_dentist_log($pdo, (int)$job['user_id'], null, $operation, $model, true, $result);
      return $result;
    } catch (Throwable $error) {
      $last = $error;
      $meta = $error instanceof AiDentistProviderException
        ? ['http_status'=>$error->httpStatus,'request_id'=>$error->requestId]
        : [];
      try { ai_dentist_log($pdo, (int)$job['user_id'], null, $operation, $model, false, $meta, $error->getMessage()); }
      catch (Throwable $ignored) {}
    }
  }
  throw $last ?: new RuntimeException('没有可用的牙列视觉模型。');
}

function dental_arch_parse_provider_json(string $content): array {
  $clean = preg_replace('/^\xEF\xBB\xBF/', '', trim($content)) ?? trim($content);
  $candidates = [$clean];
  if (preg_match('/```(?:json)?\s*(.*?)\s*```/su', $clean, $match)) {
    $candidates[] = trim($match[1]);
  }
  $first = strpos($clean, '{');
  $last = strrpos($clean, '}');
  if ($first !== false && $last !== false && $last > $first) {
    $candidates[] = substr($clean, $first, $last - $first + 1);
  }
  foreach (array_values(array_unique($candidates)) as $candidate) {
    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) return $decoded;
  }
  throw new RuntimeException(
    '视觉模型没有返回有效 JSON（返回长度 '.strlen($clean).' 字节，解析错误：'.json_last_error_msg().'）。'
  );
}

function dental_arch_points(mixed $points, int $width, int $height, int $limit=180): array {
  if (!is_array($points)) return [];
  $output = [];
  foreach ($points as $point) {
    if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) continue;
    $output[] = [
      round(min(max((float)$point[0], 0), max(0,$width-1)), 1),
      round(min(max((float)$point[1], 0), max(0,$height-1)), 1),
    ];
  }
  if (count($output) > $limit) {
    $step = (int)ceil(count($output) / $limit);
    $output = array_values(array_filter($output, static fn($value,$index): bool => $index % $step === 0, ARRAY_FILTER_USE_BOTH));
  }
  return count($output) >= 3 ? $output : [];
}

function dental_arch_bbox_from_points(array $points): array {
  if (!$points) return [0,0,0,0];
  $xs = array_column($points,0); $ys = array_column($points,1);
  return [round((float)min($xs),1),round((float)min($ys),1),round((float)max($xs),1),round((float)max($ys),1)];
}

function dental_arch_local_teeth(array $raw): array {
  $teeth = is_array($raw['teeth'] ?? null) ? $raw['teeth'] : [];
  $output = [];
  foreach ($teeth as $index=>$tooth) {
    if (!is_array($tooth)) continue;
    $instance = mb_substr(trim((string)($tooth['instance_id'] ?? ('T'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT)))),0,24);
    $polygon = is_array($tooth['polygon'] ?? null) ? $tooth['polygon'] : [];
    if (count($polygon) < 3) continue;
    $output[$instance] = [
      'instance_id'=>$instance,
      'confidence'=>min(max((float)($tooth['confidence'] ?? 0),0),1),
      'bbox_xyxy'=>is_array($tooth['bbox_xyxy'] ?? null)?array_slice($tooth['bbox_xyxy'],0,4):dental_arch_bbox_from_points($polygon),
      'centroid_xy'=>is_array($tooth['centroid_xy'] ?? null)?array_slice($tooth['centroid_xy'],0,2):null,
      'polygon'=>$polygon,
      'candidate_count'=>(int)($tooth['candidate_count'] ?? 0),
      'darkline_candidates'=>is_array($tooth['darkline_candidates'] ?? null)?$tooth['darkline_candidates']:[],
    ];
  }
  return $output;
}

function dental_arch_overlay_data_url(string $path, array $localTeeth): ?string {
  if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return null;
  $bytes = @file_get_contents($path);
  if (!is_string($bytes) || $bytes === '') return null;
  $source = @imagecreatefromstring($bytes);
  if (!$source) return null;
  $width = imagesx($source); $height = imagesy($source);
  $scale = min(1, 1400 / max($width,$height));
  $outWidth = max(1,(int)round($width*$scale)); $outHeight=max(1,(int)round($height*$scale));
  $image = imagecreatetruecolor($outWidth,$outHeight);
  imagecopyresampled($image,$source,0,0,0,0,$outWidth,$outHeight,$width,$height);
  imagedestroy($source);
  $green=imagecolorallocate($image,34,230,156); $cyan=imagecolorallocate($image,70,210,255); $shadow=imagecolorallocatealpha($image,0,0,0,40);
  imagesetthickness($image, max(2,(int)round(3*$scale)));
  foreach ($localTeeth as $tooth) {
    $polygon=$tooth['polygon']; $count=count($polygon);
    for($i=0;$i<$count;$i++){
      $a=$polygon[$i];$b=$polygon[($i+1)%$count];
      imageline($image,(int)round($a[0]*$scale),(int)round($a[1]*$scale),(int)round($b[0]*$scale),(int)round($b[1]*$scale),$green);
    }
    $center=$tooth['centroid_xy'];
    if(!is_array($center)){ $box=$tooth['bbox_xyxy'];$center=[((float)$box[0]+(float)$box[2])/2,((float)$box[1]+(float)$box[3])/2]; }
    $x=(int)round($center[0]*$scale);$y=(int)round($center[1]*$scale);
    imagestring($image,5,$x+2,$y+2,(string)$tooth['instance_id'],$shadow);
    imagestring($image,5,$x,$y,(string)$tooth['instance_id'],$cyan);
  }
  ob_start(); imagejpeg($image,null,84); $encoded=ob_get_clean(); imagedestroy($image);
  return is_string($encoded) ? 'data:image/jpeg;base64,'.base64_encode($encoded) : null;
}

function dental_arch_per_image_messages(array $row, array $localRaw, string $guide, string $imageUrl, ?string $overlayUrl): array {
  $regions=dental_arch_regions();$region=(string)$row['capture_region_id'];$meta=$regions[$region]??['label'=>$region];
  $local=dental_arch_local_teeth($localRaw);
  $candidatePayload=array_values(array_map(static fn(array $tooth): array => [
    'instance_id'=>$tooth['instance_id'],'confidence'=>$tooth['confidence'],'bbox_xyxy'=>$tooth['bbox_xyxy'],
    'centroid_xy'=>$tooth['centroid_xy'],'polygon'=>$tooth['polygon'],
  ],$local));
  $schema = [
    'view_id'=>$region,'jaw'=>'upper|lower|both','patient_side'=>'left|right|both','midline_visible'=>true,
    'teeth'=>[[
      'instance_id'=>'T01或NEW01','source_instance_ids'=>['T01'],'tooth_id'=>'11或null','action'=>'keep|replace|merge|split|new|reject',
      'contour'=>null,'confidence'=>0.95,'cropped'=>false,'needs_review'=>false,'reason'=>'简短依据',
    ]],
    'excluded_instance_ids'=>[],'missing_candidate_notes'=>[],'one_to_one_pass'=>true,'review_notes'=>[],
  ];
  $text = "当前视角：{$meta['label']}（{$region}）。图1是未标注原图；图2若存在，是本地模型候选轮廓图，轮廓旁的 Txx 是候选实例编号，不是牙号。\n"
    ."请以本地轮廓为候选基础，在真实牙体证据支持时修正边界、合并误拆、拆分粘连、删除假牙或补充漏牙，然后分配 FDI 恒牙编号。"
    ."只有 replace/merge/split/new 时才返回原图像素坐标 contour；keep 时 contour 必须为 null。禁止为了补齐序列虚构牙齿。"
    ."患者左右按指南判断；图片可能呈面对患者的观察视角，不得把画面左右直接当成患者左右。严格输出单个 JSON 对象。\n"
    ."输出结构示例：".json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ."\n本地候选（原图像素坐标）：".json_encode($candidatePayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $content=[['type'=>'text','text'=>$text],['type'=>'text','text'=>'图1：未标注原图'],['type'=>'image_url','image_url'=>['url'=>$imageUrl]]];
  if($overlayUrl){$content[]=['type'=>'text','text'=>'图2：本地候选轮廓与实例编号'];$content[]=['type'=>'image_url','image_url'=>['url'=>$overlayUrl]];}
  return [
    ['role'=>'system','content'=>"你是口腔七视图牙体实例与 FDI 编号复核器。必须遵守以下项目指南。不要诊断疾病，不要修改原图，只返回 JSON。\n\n".$guide],
    ['role'=>'user','content'=>$content],
  ];
}

function dental_arch_normalize_image_result(array $provider, array $localRaw, array $row): array {
  $width=max(1,(int)$row['image_width']);$height=max(1,(int)$row['image_height']);
  $local=dental_arch_local_teeth($localRaw);$used=[];$teeth=[];$issues=[];
  foreach (is_array($provider['teeth']??null)?$provider['teeth']:[] as $index=>$item) {
    if(!is_array($item))continue;
    $action=strtolower(trim((string)($item['action']??'keep')));
    if(!in_array($action,['keep','replace','merge','split','new','reject'],true))$action='keep';
    if($action==='reject')continue;
    $sources=array_values(array_unique(array_filter(array_map(static fn($value):string=>mb_substr(trim((string)$value),0,24),is_array($item['source_instance_ids']??null)?$item['source_instance_ids']:[]))));
    $instance=mb_substr(trim((string)($item['instance_id']??($sources[0]??('V'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT))))),0,24);
    $base=null;foreach($sources as $source){if(isset($local[$source])){$base=$local[$source];break;}}
    if(!$base && isset($local[$instance])){$base=$local[$instance];$sources=[$instance];}
    $contour=dental_arch_points($item['contour']??null,$width,$height);
    if(!$contour && $base)$contour=dental_arch_points($base['polygon'],$width,$height);
    if(!$contour){$issues[]="{$instance} 没有可用轮廓";continue;}
    $toothId=trim((string)($item['tooth_id']??''));if(!dental_arch_valid_fdi($toothId))$toothId='';
    $needsReview=(bool)($item['needs_review']??false)||$toothId===''||($action==='merge'&&count($sources)>1&&empty($item['contour']));
    if($toothId!==''&&isset($used[$toothId])){$needsReview=true;$issues[]="同一图片内牙号 {$toothId} 重复";}
    if($toothId!=='')$used[$toothId]=true;
    $teeth[]=[
      'instance_id'=>$instance,'source_instance_ids'=>$sources,'tooth_id'=>$toothId?:null,'action'=>$action,
      'bbox_xyxy'=>dental_arch_bbox_from_points($contour),'contour'=>$contour,
      'confidence'=>round(min(max((float)($item['confidence']??($base['confidence']??0)),0),1),4),
      'cropped'=>(bool)($item['cropped']??false),'needs_review'=>$needsReview,
      'reason'=>mb_substr(trim((string)($item['reason']??'')),0,500),
      'candidate_count'=>(int)($base['candidate_count']??0),'darkline_candidates'=>$base['darkline_candidates']??[],
    ];
  }
  if(!$teeth) throw new RuntimeException('视觉模型没有返回任何可用牙齿实例。');
  return [
    'view_id'=>(string)$row['capture_region_id'],'view_index'=>(int)$row['capture_region_index'],
    'jaw'=>(string)($provider['jaw']??'unknown'),'patient_side'=>(string)($provider['patient_side']??'unknown'),
    'midline_visible'=>(bool)($provider['midline_visible']??false),'teeth'=>$teeth,
    'one_to_one_pass'=>count($issues)===0&&(bool)($provider['one_to_one_pass']??true),
    'issues'=>array_values(array_unique(array_merge($issues,array_map('strval',is_array($provider['review_notes']??null)?$provider['review_notes']:[])))),
  ];
}

function dental_arch_joint_messages(array $views, string $guide, array $imageUrls): array {
  $compact=[];
  foreach($views as $view){$compact[]=['view_id'=>$view['view_id'],'teeth'=>array_map(static fn(array $tooth):array=>[
    'instance_id'=>$tooth['instance_id'],'tooth_id'=>$tooth['tooth_id'],'confidence'=>$tooth['confidence'],
    'needs_review'=>$tooth['needs_review'],'bbox_xyxy'=>$tooth['bbox_xyxy'],
  ],$view['teeth'])];}
  $schema=['views'=>[['view_id'=>'front_bite','assignments'=>[['instance_id'=>'T01','tooth_id'=>'11','confidence'=>0.96,'needs_review'=>true,'reason'=>'需要人工确认中线位置']]]],'conflicts'=>[],'review_required'=>true,'review_notes'=>[]];
  $content=[['type'=>'text','text'=>"下面是七张图逐图识别结果。请结合七个固定视角做统一牙号检查。只能修改 tooth_id、confidence、needs_review，不能新增或删除实例，不能修改轮廓。"
    ."采用差异输出：views 中只返回牙号确实需要修改、置信度需要调整或必须标记人工复核的实例；完全一致的实例不要回传。某个视角没有修改时不要输出该视角，全部一致时 views 返回空数组。"
    ."每个被返回的 assignment 必须同时包含 instance_id、完整 tooth_id、confidence、needs_review 和 reason。"
    ."同一牙号可出现在不同图片中，但同一图片内必须唯一；没有可靠证据时标记 needs_review，不要强行补齐。严格输出单个 JSON 对象，不要添加 Markdown 或解释文字。\n输出结构："
    .json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n逐图结果：".json_encode($compact,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]];
  foreach($views as $index=>$view){$content[]=['type'=>'text','text'=>'图'.($index+1).'：'.(dental_arch_regions()[$view['view_id']]['label']??$view['view_id'])];$content[]=['type'=>'image_url','image_url'=>['url'=>$imageUrls[$view['view_id']]]];}
  return [
    ['role'=>'system','content'=>"你是七视图口腔照片的 FDI 牙号总复核器。必须遵守以下项目指南，不得根据预期数量虚构牙齿。只返回 JSON。\n\n".$guide],
    ['role'=>'user','content'=>$content],
  ];
}

function dental_arch_apply_joint(array $views, array $joint): array {
  $corrections=[];$conflicts=[];
  foreach(is_array($joint['views']??null)?$joint['views']:[] as $view){
    if(!is_array($view))continue;$viewId=(string)($view['view_id']??'');
    foreach(is_array($view['assignments']??null)?$view['assignments']:[] as $item){
      if(!is_array($item))continue;$instance=(string)($item['instance_id']??'');$tooth=trim((string)($item['tooth_id']??''));
      $corrections[$viewId][$instance]=['tooth_id'=>dental_arch_valid_fdi($tooth)?$tooth:null,'confidence'=>round(min(max((float)($item['confidence']??0),0),1),4),'needs_review'=>(bool)($item['needs_review']??false),'reason'=>mb_substr(trim((string)($item['reason']??'')),0,500)];
    }
  }
  foreach($views as &$view){$used=[];foreach($view['teeth'] as &$tooth){$fix=$corrections[$view['view_id']][$tooth['instance_id']]??null;if($fix){$tooth['tooth_id']=$fix['tooth_id'];$tooth['confidence']=$fix['confidence'];$tooth['needs_review']=$tooth['needs_review']||$fix['needs_review'];if($fix['reason']!=='')$tooth['joint_reason']=$fix['reason'];}$id=(string)($tooth['tooth_id']??'');if($id===''||isset($used[$id])){$tooth['needs_review']=true;if($id!=='')$conflicts[]=['view_id'=>$view['view_id'],'tooth_id'=>$id,'reason'=>'同一图片内牙号重复'];}if($id!=='')$used[$id]=true;}unset($tooth);}unset($view);
  foreach(is_array($joint['conflicts']??null)?$joint['conflicts']:[] as $conflict)if(is_array($conflict))$conflicts[]=$conflict;
  $present=[];$needsReview=(bool)($joint['review_required']??false)||!empty($conflicts);
  foreach($views as $view)foreach($view['teeth'] as $tooth){if(!empty($tooth['tooth_id']))$present[(string)$tooth['tooth_id']]=true;if(!empty($tooth['needs_review']))$needsReview=true;}
  ksort($present,SORT_NUMERIC);
  return ['views'=>$views,'present_teeth'=>array_keys($present),'conflicts'=>$conflicts,'review_required'=>$needsReview,'review_notes'=>array_map('strval',is_array($joint['review_notes']??null)?$joint['review_notes']:[])];
}

function dental_arch_store_version(PDO $pdo, array $job, array $result, string $model): array {
  $pdo->beginTransaction();
  try {
    if(!empty($job['capture_session_id'])){
      $numberStmt=$pdo->prepare('SELECT COALESCE(MAX(version.version_number),0)+1 FROM dental_arch_versions version INNER JOIN dental_arch_jobs lineage ON lineage.id=version.job_id WHERE lineage.capture_session_id=? FOR UPDATE');
      $numberStmt->execute([(int)$job['capture_session_id']]);$number=(int)$numberStmt->fetchColumn();
    }else{
      $number=(int)$pdo->query('SELECT COALESCE(MAX(version_number),0)+1 FROM dental_arch_versions WHERE job_id='.(int)$job['id'].' FOR UPDATE')->fetchColumn();
    }
    $publicId=public_id();$json=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('牙列结果无法编码。');
    $pdo->prepare('INSERT INTO dental_arch_versions(public_id,job_id,version_number,status,is_current,result_json,model_name,guide_sha256) VALUES(?,?,?,\'draft\',0,?,?,?)')
      ->execute([$publicId,(int)$job['id'],$number,$json,mb_substr($model,0,96),(string)$job['guide_sha256']]);
    $versionId=(int)$pdo->lastInsertId();
    dental_arch_replace_tooth_views($pdo,$versionId,$result);
    $pdo->commit();
    return ['id'=>$versionId,'public_id'=>$publicId,'version_number'=>$number];
  }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function dental_arch_replace_tooth_views(PDO $pdo, int $versionId, array $result): void {
  $pdo->prepare('DELETE FROM dental_arch_tooth_views WHERE version_id=?')->execute([$versionId]);
  $insert=$pdo->prepare("INSERT INTO dental_arch_tooth_views(version_id,source_detection_id,capture_region_id,tooth_fdi,instance_id,bbox_json,contour_json,darkline_json,confidence,review_status,source_method) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
  foreach(is_array($result['views']??null)?$result['views']:[] as $view){$sourceId=(int)($view['source_detection_db_id']??0);if($sourceId<1)continue;foreach(is_array($view['teeth']??null)?$view['teeth']:[] as $tooth){$fdi=(string)($tooth['tooth_id']??'');if(!dental_arch_valid_fdi($fdi))continue;$review=!empty($tooth['needs_review'])?'needs_review':'accepted';$insert->execute([$versionId,$sourceId,(string)$view['view_id'],$fdi,mb_substr((string)$tooth['instance_id'],0,24),json_encode($tooth['bbox_xyxy']),json_encode($tooth['contour']),json_encode($tooth['darkline_candidates']??[]),round((float)($tooth['confidence']??0),4),$review,(string)($tooth['source_method']??'local_vlm')]);}}
}

function dental_arch_note_outline_result(PDO $pdo, int $detectionId, string $status, ?array $raw, string $error=''): void {
  $stmt=$pdo->prepare('SELECT image.id,image.job_id,job.status AS job_status FROM dental_arch_job_images image INNER JOIN dental_arch_jobs job ON job.id=image.job_id WHERE image.outline_detection_id=? LIMIT 1');
  $stmt->execute([$detectionId]);$link=$stmt->fetch();if(!$link)return;
  if($status==='failed'){
    $pdo->prepare("UPDATE dental_arch_job_images SET status='failed',error_message=? WHERE id=?")->execute([mb_substr($error,0,1000),(int)$link['id']]);
    $pdo->prepare("UPDATE dental_arch_jobs SET status='failed',progress_label='本地轮廓提取失败。',error_message=? WHERE id=?")->execute([mb_substr($error,0,1000),(int)$link['job_id']]);
    return;
  }
  $json=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $pdo->prepare("UPDATE dental_arch_job_images SET status='outline_completed',local_result_json=?,error_message=NULL WHERE id=?")->execute([$json,(int)$link['id']]);
  $count=$pdo->prepare("SELECT SUM(status='outline_completed'),COUNT(*) FROM dental_arch_job_images WHERE job_id=?");$count->execute([(int)$link['job_id']]);$values=$count->fetch(PDO::FETCH_NUM);
  if((int)$values[0]===(int)$values[1]&&(int)$values[1]===7){$pdo->prepare("UPDATE dental_arch_jobs SET status='vision_pending',progress_step=2,progress_label='本地轮廓已完成，等待视觉模型逐图复核。',error_message=NULL WHERE id=? AND status IN ('waiting_outline','failed')")->execute([(int)$link['job_id']]);}
}
