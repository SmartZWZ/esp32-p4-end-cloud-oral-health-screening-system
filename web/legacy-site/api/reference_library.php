<?php
declare(strict_types=1);
// Reference validation side-coordinate prompt: 20260821-lateral-side-v8.
require_once __DIR__ . '/reference_library_common.php';
require_once __DIR__ . '/ai_dentist_common.php';

function reference_uploaded_file(string $field='file'): string {
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) json_response(['ok'=>false,'error'=>'请选择一张 JPEG 图片。'],422);
  $file=$_FILES[$field];$error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
  if ($error!==UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>'图片上传失败，错误码 '.$error.'。'],422);
  $tmp=(string)($file['tmp_name']??'');
  if ($tmp==='' || !is_uploaded_file($tmp)) json_response(['ok'=>false,'error'=>'上传文件校验失败。'],400);
  $size=(int)($file['size']??0);
  if ($size<1 || $size>MAX_UPLOAD_BYTES) json_response(['ok'=>false,'error'=>'图片为空或超过 8 MB。'],$size>MAX_UPLOAD_BYTES?413:422);
  $bytes=(string)file_get_contents($tmp);
  if ($bytes==='') json_response(['ok'=>false,'error'=>'无法读取上传图片。'],400);
  return $bytes;
}

function reference_image_record(PDO $pdo,string $publicId,bool $lock=false): array {
  $stmt=$pdo->prepare('SELECT i.*,v.public_id AS version_public_id,v.version_code,v.status AS version_status FROM capture_reference_images i INNER JOIN capture_reference_versions v ON v.id=i.version_id WHERE i.public_id=? LIMIT 1'.($lock?' FOR UPDATE':''));
  $stmt->execute([mb_substr(trim($publicId),0,32)]);$image=$stmt->fetch();
  if (!$image) json_response(['ok'=>false,'error'=>'参考图片不存在。'],404);
  return $image;
}

function reference_upsert_image(PDO $pdo,array $admin,array $version,array $slot,array $normalized,string $sourceType,?int $sourceDetectionId,array $orientation): array {
  ensure_reference_storage();
  $directory=reference_storage_dir().'/'.$version['version_code'].'/'.$slot['code'];
  $baseline=$directory.'/baseline.jpg';$current=$directory.'/current.jpg';
  reference_write_atomic($baseline,$normalized['bytes']);
  reference_write_atomic($current,$normalized['bytes']);
  $baselineRelative='reference_library/'.$version['version_code'].'/'.$slot['code'].'/baseline.jpg';
  $currentRelative='reference_library/'.$version['version_code'].'/'.$slot['code'].'/current.jpg';
  $existingStmt=$pdo->prepare('SELECT id,public_id FROM capture_reference_images WHERE version_id=? AND region_id=? AND distance_label=? LIMIT 1');
  $existingStmt->execute([(int)$version['id'],$slot['region_id'],$slot['distance_label']]);$existing=$existingStmt->fetch();
  if ($existing) {
    $stmt=$pdo->prepare('UPDATE capture_reference_images SET source_type=?,source_detection_id=?,baseline_path=?,image_path=?,image_width=?,image_height=?,image_bytes=?,source_mirrored=?,normalization_applied=?,orientation_normalized=1,note=NULL,captured_distance=NULL,lighting_note=NULL,reviewed_by=NULL,reviewed_at=NULL,created_by=? WHERE id=?');
    $stmt->execute([$sourceType,$sourceDetectionId,$baselineRelative,$currentRelative,$normalized['width'],$normalized['height'],$normalized['size'],$orientation['source_mirrored'],$orientation['normalization_applied'],(int)$admin['id'],(int)$existing['id']]);
    $imageId=(int)$existing['id'];$publicId=(string)$existing['public_id'];$operation='replace_image';
  } else {
    $publicId=public_id();
    $stmt=$pdo->prepare('INSERT INTO capture_reference_images(public_id,version_id,slot_index,slot_code,region_id,distance_label,source_type,source_detection_id,baseline_path,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $stmt->execute([$publicId,(int)$version['id'],$slot['index'],$slot['code'],$slot['region_id'],$slot['distance_label'],$sourceType,$sourceDetectionId,$baselineRelative,$currentRelative,$normalized['width'],$normalized['height'],$normalized['size'],$orientation['source_mirrored'],$orientation['normalization_applied'],(int)$admin['id']]);
    $imageId=(int)$pdo->lastInsertId();$operation=$sourceType==='cloud_detection'?'select_cloud_image':'upload_image';
  }
  reference_audit($pdo,$admin,$operation,(int)$version['id'],$imageId,['slot_code'=>$slot['code'],'source_type'=>$sourceType]);
  return ['public_id'=>$publicId,'image_id'=>$imageId];
}

function reference_test_data_url(string $bytes): string { return 'data:image/jpeg;base64,'.base64_encode($bytes); }
function reference_parse_model_json(string $content): array {
  $clean=trim($content);
  $clean=preg_replace('/^```(?:json)?\s*|\s*```$/iu','',$clean)??$clean;
  $decoded=json_decode($clean,true);
  if (!is_array($decoded)) throw new RuntimeException('模型没有返回有效 JSON。');
  return $decoded;
}

function reference_region_guidance(string $region): string {
  return [
    'front_bite'=>'上下牙保持咬合；上下牙列正中区域位于画面中央；左右前牙及相邻牙面同时可见；不能只拍到单侧后牙。',
    'left_bite'=>'上下牙保持咬合；严格按最终图片的画面坐标判断：图片最左边是牙齿与侧方咬合关系，图片最右边是被拍摄者的人脸嘴角/软组织出口。只要侧方咬合关系具有判断价值即可；不统计牙齿颗数，不要求拍到最后侧牙齿。口角、嘴唇或牵拉程度不是独立合格条件。',
    'right_bite'=>'上下牙保持咬合；严格按最终图片的画面坐标判断：图片最左边是被拍摄者的人脸嘴角/软组织出口，图片最右边是牙齿与侧方咬合关系。只要侧方咬合关系具有判断价值即可；不统计牙齿颗数，不要求拍到最后侧牙齿。口角、嘴唇或牵拉程度不是独立合格条件。',
    'upper_left_open'=>'张口；主体是被拍摄者左上牙列的咬合面与后牙区；不应以右上牙列或下牙列为主体。',
    'upper_right_open'=>'张口；主体是被拍摄者右上牙列的咬合面与后牙区；不应以左上牙列或下牙列为主体。',
    'lower_left_open'=>'张口；主体是被拍摄者左下牙列的咬合面与后牙区；不应以右下牙列或上牙列为主体。',
    'lower_right_open'=>'张口；主体是被拍摄者右下牙列的咬合面与后牙区；不应以左下牙列或上牙列为主体。',
  ][$region]??'无法确定目标区域。';
}

function reference_opposite_region(string $region): ?string {
  return [
    'left_bite'=>'right_bite','right_bite'=>'left_bite',
    'upper_left_open'=>'upper_right_open','upper_right_open'=>'upper_left_open',
    'lower_left_open'=>'lower_right_open','lower_right_open'=>'lower_left_open',
  ][$region]??null;
}

/**
 * Produce a tiny RGB descriptor. It is only a safety guard for the admin
 * benchmark: an exact/near-exact approved image must pass, while its
 * horizontal mirror must not be accepted as the same left/right region.
 */
function reference_rgb_descriptor(string $jpeg,int $width=48,int $height=36): ?array {
  if(!function_exists('imagecreatefromstring')||!function_exists('imagecopyresampled'))return null;
  $info=@getimagesizefromstring($jpeg);if(!is_array($info)||((int)$info[0]*(int)$info[1])>16000000)return null;
  $source=@imagecreatefromstring($jpeg);if(!$source)return null;
  $small=imagecreatetruecolor($width,$height);if(!$small){imagedestroy($source);return null;}
  imagecopyresampled($small,$source,0,0,0,0,$width,$height,imagesx($source),imagesy($source));imagedestroy($source);
  $pixels=[];
  for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){$rgb=imagecolorat($small,$x,$y);$pixels[]=[$rgb>>16&255,$rgb>>8&255,$rgb&255];}
  imagedestroy($small);return ['width'=>$width,'height'=>$height,'pixels'=>$pixels];
}

function reference_good_similarity(string $candidateBytes,string $goodBytes): array {
  $candidate=reference_rgb_descriptor($candidateBytes);$good=reference_rgb_descriptor($goodBytes);
  if(!$candidate||!$good)return ['available'=>false];
  $width=(int)$candidate['width'];$height=(int)$candidate['height'];$normal=0.0;$mirrored=0.0;$samples=$width*$height*3;
  for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){
    $index=$y*$width+$x;$mirrorIndex=$y*$width+($width-1-$x);$a=$candidate['pixels'][$index];$b=$good['pixels'][$index];$m=$good['pixels'][$mirrorIndex];
    for($channel=0;$channel<3;$channel++){$normal+=abs($a[$channel]-$b[$channel]);$mirrored+=abs($a[$channel]-$m[$channel]);}
  }
  $normal/=$samples*255;$mirrored/=$samples*255;
  return ['available'=>true,'normal_error'=>round($normal,5),'mirrored_error'=>round($mirrored,5),'matches_good'=>$normal<=0.065&&$normal+0.02<$mirrored,'matches_mirrored_good'=>$mirrored<=0.065&&$mirrored+0.02<$normal];
}

function reference_apply_similarity_guard(array $result,string $expectedRegion,float $threshold,array $similarity): array {
  $opposite=reference_opposite_region($expectedRegion);$regions=reference_regions();
  if(($similarity['matches_mirrored_good']??false)&&$opposite!==null){
    $expectedLabel=(string)($regions[$expectedRegion]['label']??$expectedRegion);$oppositeLabel=(string)($regions[$opposite]['label']??$opposite);
    $result['detected_region']=$opposite;$result['region_match']=false;$result['accepted']=false;$result['reason_code']='mirrored_opposite_region';$result['instruction']='当前图像为'.$oppositeLabel.'，请改拍'.$expectedLabel;$result['summary']='候选图与合适参考图的水平镜像高度一致，左右区域不匹配。';$result['confidence']=max((float)$result['confidence'],0.98);$result['_similarity']=$similarity+['decision'=>'mirrored_opposite_region'];return $result;
  }
  if($similarity['matches_good']??false){
    $result['detected_region']=$expectedRegion;$result['region_match']=true;$result['distance']='good';$result['sharpness']='good';$result['lighting']='good';$result['framing']='complete';$result['confidence']=max((float)$result['confidence'],$threshold,0.98);$result['accepted']=true;$result['reason_code']='approved_reference_match';$result['instruction']='与合适参考图一致，可以采纳';$result['summary']='候选图与本区域合适参考图高度一致。';$result['_similarity']=$similarity+['decision'=>'approved_reference_match'];return $result;
  }
  $result['_similarity']=$similarity+['decision'=>'model_result'];return $result;
}

function reference_normalize_test_result(array $result,string $expectedRegion,float $threshold): array {
  $regions=array_keys(reference_regions());
  $detected=in_array((string)($result['detected_region']??''),$regions,true)?(string)$result['detected_region']:'unknown';
  $distance=in_array((string)($result['distance']??''),['too_far','too_close','good','unknown'],true)?(string)$result['distance']:'unknown';
  $sharpness=in_array((string)($result['sharpness']??''),['good','blurred','unknown'],true)?(string)$result['sharpness']:'unknown';
  $lighting=in_array((string)($result['lighting']??''),['good','too_dark','overexposed','unknown'],true)?(string)$result['lighting']:'unknown';
  $framing=in_array((string)($result['framing']??''),['complete','partial','unknown'],true)?(string)$result['framing']:'unknown';
  // Never trust a separate region_match boolean when it contradicts the
  // detected region. Unknown results are explicit failures, not soft passes.
  $regionMatch=$detected===$expectedRegion;
  $compositionMatch=filter_var($result['composition_match_to_good']??false,FILTER_VALIDATE_BOOLEAN);
  // The administrator-approved good image defines the allowed composition.
  // If the model says the candidate matches it and the region is correct, it
  // cannot then invent a stricter framing/distance requirement.
  if($regionMatch&&$compositionMatch){$distance='good';$framing='complete';}
  $confidence=max(0,min(1,(float)($result['confidence']??0)));
  $threshold=max(0.5,min(0.99,$threshold));
  if($regionMatch&&$compositionMatch)$confidence=max($confidence,$threshold);
  $modelClaimedAccepted=filter_var($result['accepted']??false,FILTER_VALIDATE_BOOLEAN);
  $accepted=$regionMatch && $distance==='good' && $sharpness==='good' && $lighting==='good' && $framing==='complete' && $confidence>=$threshold;
  // User-facing advice is generated from the structured fields. Do not pass
  // through invented requirements such as “open the mouth corner wider”.
  if(!$regionMatch)$instruction='拍摄区域不符，请对准指定牙区';
  elseif($distance==='too_far')$instruction='请将摄像头靠近一些';
  elseif($distance==='too_close')$instruction='请将摄像头稍微移远';
  elseif($sharpness==='blurred')$instruction='请保持稳定后重新拍摄';
  elseif($lighting==='too_dark')$instruction='请增加补光后重新拍摄';
  elseif($lighting==='overexposed')$instruction='请降低补光后重新拍摄';
  elseif($framing==='partial')$instruction='请按合适参考图补全目标牙区';
  elseif($confidence<$threshold)$instruction='判断不够稳定，请重新拍摄';
  elseif($accepted)$instruction='位置合适，请保持稳定';
  else $instruction='请调整位置后重新拍摄';
  $summary='区域'.($regionMatch?'匹配':'不匹配').'；距离'.(['too_far'=>'太远','too_close'=>'太近','good'=>'合适','unknown'=>'无法判断'][$distance]??$distance).'；清晰度'.(['good'=>'合格','blurred'=>'模糊','unknown'=>'无法判断'][$sharpness]??$sharpness).'；光照'.(['good'=>'合格','too_dark'=>'偏暗','overexposed'=>'过曝','unknown'=>'无法判断'][$lighting]??$lighting).'；取景'.(['complete'=>'完整','partial'=>'不完整','unknown'=>'无法判断'][$framing]??$framing).'。';
  return [
    'detected_region'=>$detected,
    'region_match'=>$regionMatch,
    'distance'=>$distance,
    'sharpness'=>$sharpness,
    'lighting'=>$lighting,
    'framing'=>$framing,
    'composition_match_to_good'=>$compositionMatch,
    'accepted'=>$accepted,
    'model_claimed_accepted'=>$modelClaimedAccepted,
    'reason_code'=>mb_substr(trim((string)($result['reason_code']??($accepted?'ok':'adjust_required'))),0,48),
    'instruction'=>$instruction,
    'summary'=>$summary,
    'confidence'=>round($confidence,4),
    'confidence_threshold'=>round($threshold,3),
    'prompt_version'=>'capture_quality_v4',
  ];
}

try {
  $admin=require_admin();$pdo=db();$action=(string)($_GET['action']??'bootstrap');$method=(string)($_SERVER['REQUEST_METHOD']??'GET');

  if ($method==='GET' && $action==='bootstrap') {
    $base=reference_version_payload($pdo);$versionId=mb_substr(trim((string)($_GET['version_id']??'')),0,32);$selected=null;$images=[];
    if ($versionId!=='') { $selected=reference_require_version($pdo,$versionId);$images=reference_version_images($pdo,(int)$selected['id']); }
    elseif ($base['versions']) { $selected=reference_require_version($pdo,(string)$base['versions'][0]['public_id']);$images=reference_version_images($pdo,(int)$selected['id']); }
    foreach ($images as &$image) $image['urls']=reference_image_urls((string)$image['public_id']);unset($image);
    json_response(['ok'=>true]+$base+['slots'=>reference_slots(),'selected_version'=>$selected,'images'=>$images,'runtime'=>['orientation_policy'=>'preserve_device','storage_ready'=>is_dir(reference_storage_dir())||is_writable(storage_root())]]);
  }

  if ($method==='GET' && $action==='cloud_images') {
    $limit=min(max((int)($_GET['limit']??100),1),200);$query=mb_substr(trim((string)($_GET['query']??'')),0,64);
    // 参考图库只从设备采集的原始影像中选择，不混入网页本地上传图片和分析派生记录。
    $where="d.device_id IS NOT NULL AND d.source_detection_id IS NULL AND d.image_path<>''";$args=[];
    if ($query!=='') { $where.=' AND (m.name LIKE ? OR d.public_id LIKE ?)';$args[]='%'.$query.'%';$args[]='%'.$query.'%'; }
    $stmt=$pdo->prepare("SELECT d.id,d.public_id,d.device_id,d.image_width,d.image_height,d.image_bytes,d.source_mirrored,d.normalization_applied,d.orientation_normalized,d.created_at,CASE WHEN d.device_id IS NULL THEN 'web' ELSE 'device' END AS source_type,COALESCE(m.name,'未归属成员') AS member_name FROM detections d LEFT JOIN family_members m ON m.id=d.member_id WHERE {$where} ORDER BY d.id DESC LIMIT {$limit}");
    $stmt->execute($args);json_response(['ok'=>true,'items'=>$stmt->fetchAll()]);
  }

  if ($method==='GET' && $action==='logs') {
    $stmt=$pdo->query("SELECT l.public_id,l.operation,l.detail_json,l.ip_address,l.created_at,u.email,v.version_code,i.slot_code FROM capture_reference_audit_logs l INNER JOIN users u ON u.id=l.admin_user_id LEFT JOIN capture_reference_versions v ON v.id=l.version_id LEFT JOIN capture_reference_images i ON i.id=l.image_id ORDER BY l.id DESC LIMIT 100");
    json_response(['ok'=>true,'items'=>$stmt->fetchAll()]);
  }

  if ($method==='GET' && $action==='tests') {
    $versionPublic=mb_substr(trim((string)($_GET['version_id']??'')),0,32);$limit=min(max((int)($_GET['limit']??20),1),100);$args=[];$where='1=1';
    if($versionPublic!==''){$where='v.public_id=?';$args[]=$versionPublic;}
    $stmt=$pdo->prepare("SELECT t.public_id,t.expected_region,t.candidate_source,t.model_name,t.success,t.result_json,t.error_message,t.latency_ms,t.human_verdict,t.human_note,t.labeled_at,t.created_at,v.public_id AS version_public_id,v.version_code,v.name AS version_name,u.email AS admin_email,lu.email AS labeler_email FROM capture_reference_tests t INNER JOIN capture_reference_versions v ON v.id=t.version_id INNER JOIN users u ON u.id=t.admin_user_id LEFT JOIN users lu ON lu.id=t.labeled_by WHERE {$where} ORDER BY t.id DESC LIMIT {$limit}");
    $stmt->execute($args);$items=$stmt->fetchAll();
    foreach($items as &$item){$item['result']=$item['result_json']?json_decode((string)$item['result_json'],true):null;unset($item['result_json']);$item['candidate_url']='api/reference_test_image.php?id='.rawurlencode((string)$item['public_id']);}unset($item);
    $calibrationArgs=[];$calibrationWhere='t.success=1 AND t.human_verdict IS NOT NULL';
    if($versionPublic!==''){$calibrationWhere.=' AND v.public_id=?';$calibrationArgs[]=$versionPublic;}
    $calibrationStmt=$pdo->prepare("SELECT t.result_json,t.human_verdict FROM capture_reference_tests t INNER JOIN capture_reference_versions v ON v.id=t.version_id WHERE {$calibrationWhere}");
    $calibrationStmt->execute($calibrationArgs);$labeled=$calibrationStmt->fetchAll();$agreement=0;$distanceLabeled=0;$distanceMatches=0;
    foreach($labeled as $sample){
      $result=json_decode((string)$sample['result_json'],true);if(!is_array($result))continue;
      $human=(string)$sample['human_verdict'];$humanAccepted=$human==='accepted';$modelAccepted=(bool)($result['accepted']??false);if($humanAccepted===$modelAccepted)$agreement++;
      $expectedDistance=['accepted'=>'good','too_far'=>'too_far','too_close'=>'too_close'][$human]??null;
      if($expectedDistance!==null){$distanceLabeled++;if((string)($result['distance']??'unknown')===$expectedDistance)$distanceMatches++;}
    }
    $labeledCount=count($labeled);
    json_response(['ok'=>true,'items'=>$items,'calibration'=>[
      'labeled_count'=>$labeledCount,
      'accepted_agreement_count'=>$agreement,
      'accepted_agreement_rate'=>$labeledCount?round($agreement/$labeledCount,4):null,
      'distance_labeled_count'=>$distanceLabeled,
      'distance_match_count'=>$distanceMatches,
      'distance_accuracy_rate'=>$distanceLabeled?round($distanceMatches/$distanceLabeled,4):null,
    ]]);
  }

  if ($method!=='POST') json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  require_csrf();
  $data=request_data();

  if ($action==='save_quality_settings') {
    $model=mb_substr(trim((string)($data['quality_model']??'')),0,96);
    if($model!==''&&!preg_match('/^[A-Za-z0-9._:\/-]+$/',$model))json_response(['ok'=>false,'error'=>'质量判断模型 ID 包含不支持的字符。'],422);
    $threshold=round((float)($data['quality_confidence_threshold']??0.8),3);
    if($threshold<0.5||$threshold>0.99)json_response(['ok'=>false,'error'=>'最低通过置信度必须在 0.50 到 0.99 之间。'],422);
    $pdo->beginTransaction();$pdo->prepare('UPDATE capture_reference_settings SET quality_model=?,quality_confidence_threshold=?,updated_by=? WHERE id=1')->execute([$model!==''?$model:null,$threshold,(int)$admin['id']]);reference_audit($pdo,$admin,'update_quality_settings',null,null,['quality_model'=>$model?:'follow_ai_dentist_primary','quality_confidence_threshold'=>$threshold]);$pdo->commit();
    json_response(['ok'=>true,'quality_model'=>$model,'quality_confidence_threshold'=>$threshold]);
  }

  if ($action==='label_test') {
    $testId=mb_substr(trim((string)($data['test_id']??'')),0,32);$verdict=trim((string)($data['human_verdict']??''));$note=mb_substr(trim((string)($data['human_note']??'')),0,500);
    $allowed=['accepted','too_far','too_close','wrong_region','blurred','lighting','framing','uncertain'];
    if($testId===''||!in_array($verdict,$allowed,true))json_response(['ok'=>false,'error'=>'人工复核结论无效。'],422);
    $stmt=$pdo->prepare('SELECT id,version_id FROM capture_reference_tests WHERE public_id=? LIMIT 1');$stmt->execute([$testId]);$test=$stmt->fetch();if(!$test)json_response(['ok'=>false,'error'=>'测试记录不存在。'],404);
    $pdo->beginTransaction();$pdo->prepare('UPDATE capture_reference_tests SET human_verdict=?,human_note=?,labeled_by=?,labeled_at=NOW() WHERE id=?')->execute([$verdict,$note!==''?$note:null,(int)$admin['id'],(int)$test['id']]);reference_audit($pdo,$admin,'label_test',(int)$test['version_id'],null,['test_id'=>$testId,'human_verdict'=>$verdict,'human_note'=>$note]);$pdo->commit();
    json_response(['ok'=>true]);
  }

  if ($action==='create_version') {
    $name=mb_substr(trim((string)($data['name']??'')),0,96);if ($name==='') json_response(['ok'=>false,'error'=>'请填写版本名称。'],422);
    $pdo->beginTransaction();$code=reference_version_code($pdo);$publicId=public_id();
    $stmt=$pdo->prepare('INSERT INTO capture_reference_versions(public_id,version_code,name,description,hardware_profile,created_by) VALUES(?,?,?,?,?,?)');
    $stmt->execute([$publicId,$code,$name,mb_substr(trim((string)($data['description']??'')),0,500)?:null,'ESP32-P4 + OV5647',(int)$admin['id']]);
    $id=(int)$pdo->lastInsertId();reference_audit($pdo,$admin,'create_version',$id,null,['version_code'=>$code]);$pdo->commit();
    json_response(['ok'=>true,'public_id'=>$publicId,'version_code'=>$code],201);
  }

  if ($action==='duplicate_version') {
    $source=reference_require_version($pdo,(string)($data['version_id']??''));$name=mb_substr(trim((string)($data['name']??('复制 '.$source['name']))),0,96);
    $pdo->beginTransaction();$code=reference_version_code($pdo);$newPublic=public_id();
    $pdo->prepare('INSERT INTO capture_reference_versions(public_id,version_code,name,description,hardware_profile,created_by) VALUES(?,?,?,?,?,?)')->execute([$newPublic,$code,$name,(string)($source['description']??''),(string)$source['hardware_profile'],(int)$admin['id']]);
    $newId=(int)$pdo->lastInsertId();$images=reference_version_images($pdo,(int)$source['id']);ensure_reference_storage();
    foreach ($images as $image) {
      $slot=reference_slot((string)$image['region_id'],(string)$image['distance_label']);if (!$slot) continue;
      $sourceRow=reference_image_record($pdo,(string)$image['public_id']);$sourceCurrent=reference_safe_path((string)$sourceRow['image_path']);
      if (!$sourceCurrent) continue;$normalized=reference_validate_edited_jpeg((string)file_get_contents($sourceCurrent));$bytes=$normalized['bytes'];$directory=reference_storage_dir().'/'.$code.'/'.$slot['code'];$baseline=$directory.'/baseline.jpg';$current=$directory.'/current.jpg';reference_write_atomic($baseline,$bytes);reference_write_atomic($current,$bytes);
      $pid=public_id();$stmt=$pdo->prepare('INSERT INTO capture_reference_images(public_id,version_id,slot_index,slot_code,region_id,distance_label,source_type,source_detection_id,baseline_path,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,note,captured_distance,lighting_note,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $stmt->execute([$pid,$newId,$slot['index'],$slot['code'],$slot['region_id'],$slot['distance_label'],(string)$sourceRow['source_type'],$sourceRow['source_detection_id'],'reference_library/'.$code.'/'.$slot['code'].'/baseline.jpg','reference_library/'.$code.'/'.$slot['code'].'/current.jpg',(int)$normalized['width'],(int)$normalized['height'],strlen($bytes),0,'none',1,(string)($sourceRow['note']??''),(string)($sourceRow['captured_distance']??''),(string)($sourceRow['lighting_note']??''),(int)$admin['id']]);
    }
    reference_audit($pdo,$admin,'copy_version',$newId,null,['source_version'=>$source['version_code']]);$pdo->commit();json_response(['ok'=>true,'public_id'=>$newPublic,'version_code'=>$code],201);
  }

  if ($action==='delete_version') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));
    if((string)$version['status']!=='draft' || (bool)$version['is_active'] || !empty($version['published_at']))json_response(['ok'=>false,'error'=>'只有从未发布且未启用的草稿版本可以删除。'],409);
    $versionCode=(string)$version['version_code'];$pdo->beginTransaction();reference_audit($pdo,$admin,'delete_version',(int)$version['id'],null,['version_code'=>$versionCode,'name'=>$version['name']]);$pdo->prepare('DELETE FROM capture_reference_versions WHERE id=?')->execute([(int)$version['id']]);$pdo->commit();reference_delete_version_files($versionCode);json_response(['ok'=>true]);
  }

  if ($action==='begin_revision') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));
    if((string)$version['status']==='archived')json_response(['ok'=>false,'error'=>'归档版本不能直接修改，请先恢复为已发布。'],409);
    if((string)$version['status']==='draft')json_response(['ok'=>true,'already_editable'=>true,'was_active'=>(bool)$version['is_active']]);
    $wasActive=(bool)$version['is_active'];$pdo->beginTransaction();
    if($wasActive)$pdo->prepare('UPDATE capture_reference_settings SET active_version_id=NULL,validation_enabled=0,updated_by=? WHERE id=1')->execute([(int)$admin['id']]);
    $pdo->prepare("UPDATE capture_reference_versions SET status='draft',archived_by=NULL,archived_at=NULL WHERE id=?")->execute([(int)$version['id']]);
    reference_audit($pdo,$admin,'begin_revision',(int)$version['id'],null,['was_active'=>$wasActive]);$pdo->commit();
    json_response(['ok'=>true,'already_editable'=>false,'was_active'=>$wasActive]);
  }

  if ($action==='upload_image') {
    $version=reference_require_version($pdo,(string)($_POST['version_id']??''));reference_require_draft($version);
    $slot=reference_slot((string)($_POST['region_id']??''),(string)($_POST['distance_label']??''));if (!$slot) json_response(['ok'=>false,'error'=>'参考图槽位无效。'],422);
    $raw=reference_uploaded_file();$normalized=reference_validate_edited_jpeg($raw);$pdo->beginTransaction();$result=reference_upsert_image($pdo,$admin,$version,$slot,$normalized,'admin_upload',null,['source_mirrored'=>0,'normalization_applied'=>'none']);$pdo->commit();
    json_response(['ok'=>true,'image_public_id'=>$result['public_id'],'slot_code'=>$slot['code']],201);
  }

  if ($action==='assign_cloud_image') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));reference_require_draft($version);
    $slot=reference_slot((string)($data['region_id']??''),(string)($data['distance_label']??''));if (!$slot) json_response(['ok'=>false,'error'=>'参考图槽位无效。'],422);
    $stmt=$pdo->prepare('SELECT id,public_id,device_id,image_path,source_mirrored,normalization_applied,orientation_normalized FROM detections WHERE public_id=? LIMIT 1');$stmt->execute([mb_substr(trim((string)($data['detection_id']??'')),0,32)]);$source=$stmt->fetch();
    $path=$source?image_file_path((string)$source['image_path']):null;if (!$source||!$path) json_response(['ok'=>false,'error'=>'云端影像不存在或文件已丢失。'],404);
    $raw=(string)file_get_contents($path);$normalized=reference_validate_edited_jpeg($raw);
    // Reference files always preserve stored pixel order. Historical direction
    // flags are metadata only and must never trigger another transformation.
    $orientation=['source_mirrored'=>0,'normalization_applied'=>'none'];
    $pdo->beginTransaction();$result=reference_upsert_image($pdo,$admin,$version,$slot,$normalized,'cloud_detection',(int)$source['id'],$orientation);$pdo->commit();
    json_response(['ok'=>true,'image_public_id'=>$result['public_id'],'slot_code'=>$slot['code']],201);
  }

  if ($action==='edit_image') {
    $image=reference_image_record($pdo,(string)($_POST['image_id']??''));if ((string)$image['version_status']!=='draft') json_response(['ok'=>false,'error'=>'只有草稿版本可以编辑。'],409);
    $edited=reference_validate_edited_jpeg(reference_uploaded_file());$path=reference_safe_path((string)$image['image_path']);if (!$path) json_response(['ok'=>false,'error'=>'参考图文件不存在。'],404);
    $horizontalFlipped=filter_var($_POST['horizontal_flipped']??false,FILTER_VALIDATE_BOOLEAN);$reviewed=filter_var($_POST['reviewed']??false,FILTER_VALIDATE_BOOLEAN);reference_write_atomic($path,$edited['bytes']);$pdo->beginTransaction();$pdo->prepare('UPDATE capture_reference_images SET image_width=?,image_height=?,image_bytes=?,note=?,captured_distance=?,lighting_note=?,reviewed_by=?,reviewed_at=? WHERE id=?')->execute([$edited['width'],$edited['height'],$edited['size'],mb_substr(trim((string)($_POST['note']??'')),0,500)?:null,mb_substr(trim((string)($_POST['captured_distance']??'')),0,80)?:null,mb_substr(trim((string)($_POST['lighting_note']??'')),0,180)?:null,$reviewed?(int)$admin['id']:null,$reviewed?date('Y-m-d H:i:s'):null,(int)$image['id']]);reference_audit($pdo,$admin,'edit_image',(int)$image['version_id'],(int)$image['id'],['width'=>$edited['width'],'height'=>$edited['height'],'horizontal_flipped'=>$horizontalFlipped,'reviewed'=>$reviewed]);$pdo->commit();json_response(['ok'=>true,'reviewed'=>$reviewed]);
  }

  if ($action==='reset_image') {
    $image=reference_image_record($pdo,(string)($data['image_id']??''));if ((string)$image['version_status']!=='draft') json_response(['ok'=>false,'error'=>'只有草稿版本可以重置。'],409);
    $baseline=reference_safe_path((string)$image['baseline_path']);$current=storage_root().'/'.ltrim((string)$image['image_path'],'/');if (!$baseline) json_response(['ok'=>false,'error'=>'规范基准图不存在。'],404);
    $bytes=(string)file_get_contents($baseline);$meta=reference_validate_edited_jpeg($bytes);reference_write_atomic($current,$bytes);$pdo->beginTransaction();$pdo->prepare('UPDATE capture_reference_images SET image_width=?,image_height=?,image_bytes=?,reviewed_by=NULL,reviewed_at=NULL WHERE id=?')->execute([$meta['width'],$meta['height'],$meta['size'],(int)$image['id']]);reference_audit($pdo,$admin,'reset_image',(int)$image['version_id'],(int)$image['id']);$pdo->commit();json_response(['ok'=>true]);
  }

  if ($action==='review_image') {
    $image=reference_image_record($pdo,(string)($data['image_id']??''));if ((string)$image['version_status']!=='draft') json_response(['ok'=>false,'error'=>'只有草稿版本可以审核。'],409);
    $reviewed=filter_var($data['reviewed']??false,FILTER_VALIDATE_BOOLEAN);$stmt=$pdo->prepare('UPDATE capture_reference_images SET note=?,captured_distance=?,lighting_note=?,reviewed_by=?,reviewed_at=? WHERE id=?');
    $stmt->execute([mb_substr(trim((string)($data['note']??'')),0,500)?:null,mb_substr(trim((string)($data['captured_distance']??'')),0,80)?:null,mb_substr(trim((string)($data['lighting_note']??'')),0,180)?:null,$reviewed?(int)$admin['id']:null,$reviewed?date('Y-m-d H:i:s'):null,(int)$image['id']]);reference_audit($pdo,$admin,$reviewed?'review_image':'unreview_image',(int)$image['version_id'],(int)$image['id']);json_response(['ok'=>true]);
  }

  if ($action==='delete_image') {
    $image=reference_image_record($pdo,(string)($data['image_id']??''));if ((string)$image['version_status']!=='draft') json_response(['ok'=>false,'error'=>'只有草稿版本可以删除参考图。'],409);
    $baseline=reference_safe_path((string)$image['baseline_path']);$current=reference_safe_path((string)$image['image_path']);$pdo->beginTransaction();reference_audit($pdo,$admin,'delete_draft_image',(int)$image['version_id'],(int)$image['id'],['slot_code'=>$image['slot_code']]);$pdo->prepare('DELETE FROM capture_reference_images WHERE id=?')->execute([(int)$image['id']]);$pdo->commit();if($baseline)@unlink($baseline);if($current&&$current!==$baseline)@unlink($current);json_response(['ok'=>true]);
  }

  if ($action==='publish_version') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));reference_require_draft($version);
    $stmt=$pdo->prepare('SELECT COUNT(*) AS total,SUM(reviewed_at IS NOT NULL) AS reviewed FROM capture_reference_images WHERE version_id=?');$stmt->execute([(int)$version['id']]);$counts=$stmt->fetch();
    if ((int)$counts['total']!==21 || (int)$counts['reviewed']!==21) json_response(['ok'=>false,'error'=>'发布前必须填满并逐张确认全部 21 个参考图槽位。'],409);
    $pdo->beginTransaction();$pdo->prepare("UPDATE capture_reference_versions SET status='published',published_by=?,published_at=NOW(),archived_by=NULL,archived_at=NULL WHERE id=?")->execute([(int)$admin['id'],(int)$version['id']]);reference_audit($pdo,$admin,'publish_version',(int)$version['id']);$pdo->commit();json_response(['ok'=>true]);
  }

  if ($action==='activate_version') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));if ((string)$version['status']!=='published') json_response(['ok'=>false,'error'=>'只有已发布版本可以启用。'],409);
    $pdo->beginTransaction();$pdo->prepare('UPDATE capture_reference_settings SET active_version_id=?,updated_by=? WHERE id=1')->execute([(int)$version['id'],(int)$admin['id']]);reference_audit($pdo,$admin,'activate_version',(int)$version['id']);$pdo->commit();json_response(['ok'=>true]);
  }

  if ($action==='deactivate_version') {
    $pdo->beginTransaction();$active=(int)($pdo->query('SELECT active_version_id FROM capture_reference_settings WHERE id=1 FOR UPDATE')->fetchColumn()?:0);$pdo->prepare('UPDATE capture_reference_settings SET active_version_id=NULL,validation_enabled=0,updated_by=? WHERE id=1')->execute([(int)$admin['id']]);if($active)reference_audit($pdo,$admin,'deactivate_version',$active);$pdo->commit();json_response(['ok'=>true]);
  }

  if ($action==='toggle_validation') {
    $enabled=filter_var($data['enabled']??false,FILTER_VALIDATE_BOOLEAN);$settings=$pdo->query('SELECT active_version_id FROM capture_reference_settings WHERE id=1')->fetch();if($enabled && empty($settings['active_version_id']))json_response(['ok'=>false,'error'=>'请先启用一个已发布参考版本。'],409);
    $pdo->beginTransaction();$pdo->prepare('UPDATE capture_reference_settings SET validation_enabled=?,updated_by=? WHERE id=1')->execute([$enabled?1:0,(int)$admin['id']]);reference_audit($pdo,$admin,'toggle_validation',$settings['active_version_id']?(int)$settings['active_version_id']:null,null,['enabled'=>$enabled]);$pdo->commit();json_response(['ok'=>true,'validation_enabled'=>$enabled]);
  }

  if ($action==='archive_version') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));if ((bool)$version['is_active'])json_response(['ok'=>false,'error'=>'当前启用版本不能归档，请先停用或启用其他版本。'],409);if((string)$version['status']!=='published')json_response(['ok'=>false,'error'=>'只有已发布版本可以归档。'],409);
    $pdo->beginTransaction();$pdo->prepare("UPDATE capture_reference_versions SET status='archived',archived_by=?,archived_at=NOW() WHERE id=?")->execute([(int)$admin['id'],(int)$version['id']]);reference_audit($pdo,$admin,'archive_version',(int)$version['id']);$pdo->commit();json_response(['ok'=>true]);
  }

  if ($action==='restore_version') {
    $version=reference_require_version($pdo,(string)($data['version_id']??''));if((string)$version['status']!=='archived')json_response(['ok'=>false,'error'=>'只有归档版本可以恢复。'],409);
    $pdo->beginTransaction();$pdo->prepare("UPDATE capture_reference_versions SET status='published',archived_by=NULL,archived_at=NULL WHERE id=?")->execute([(int)$version['id']]);reference_audit($pdo,$admin,'restore_version',(int)$version['id']);$pdo->commit();json_response(['ok'=>true]);
  }

  if ($action==='record_test_client_timing') {
    $testId=mb_substr(trim((string)($data['test_id']??'')),0,32);$clientMs=max(0,min(600000,(int)($data['client_roundtrip_ms']??0)));
    if($testId===''||$clientMs<1)json_response(['ok'=>false,'error'=>'端到端计时数据无效。'],422);
    $stmt=$pdo->prepare('SELECT result_json FROM capture_reference_tests WHERE public_id=? AND admin_user_id=? AND success=1 LIMIT 1');$stmt->execute([$testId,(int)$admin['id']]);$raw=$stmt->fetchColumn();
    if($raw===false)json_response(['ok'=>false,'error'=>'没有找到本次测试记录。'],404);
    $result=json_decode((string)$raw,true);if(!is_array($result))$result=[];if(!isset($result['_timing'])||!is_array($result['_timing']))$result['_timing']=[];$result['_timing']['client_roundtrip_ms']=$clientMs;
    $pdo->prepare('UPDATE capture_reference_tests SET result_json=? WHERE public_id=?')->execute([json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$testId]);
    json_response(['ok'=>true,'client_roundtrip_ms'=>$clientMs]);
  }

  if ($action==='test_validation') {
    $totalStarted=microtime(true);$version=reference_require_version($pdo,(string)($_POST['version_id']??''));if((string)$version['status']==='archived')json_response(['ok'=>false,'error'=>'归档版本不能运行新测试，请先恢复版本。'],409);
    $region=trim((string)($_POST['expected_region']??''));if(!isset(reference_regions()[$region]))json_response(['ok'=>false,'error'=>'测试区域无效。'],422);
    $stmt=$pdo->prepare("SELECT * FROM capture_reference_images WHERE version_id=? AND region_id=? ORDER BY FIELD(distance_label,'too_far','too_close','good')");$stmt->execute([(int)$version['id'],$region]);$refs=$stmt->fetchAll();if(count($refs)!==3)json_response(['ok'=>false,'error'=>'该区域的三张参考图尚未齐全。'],409);
    $oppositeRegion=reference_opposite_region($region);$oppositeRef=null;$oppositeBytes='';
    if($oppositeRegion!==null){$oppositeStmt=$pdo->prepare("SELECT * FROM capture_reference_images WHERE version_id=? AND region_id=? AND distance_label='good' LIMIT 1");$oppositeStmt->execute([(int)$version['id'],$oppositeRegion]);$oppositeRef=$oppositeStmt->fetch()?:null;if($oppositeRef){$oppositePath=reference_safe_path((string)$oppositeRef['image_path']);if($oppositePath)$oppositeBytes=(string)file_get_contents($oppositePath);else $oppositeRef=null;}}
    $candidateDetectionId=mb_substr(trim((string)($_POST['candidate_detection_id']??'')),0,32);$candidateDbId=null;$candidateSource='admin_upload';
    if($candidateDetectionId!==''){$stmt=$pdo->prepare('SELECT id,device_id,image_path,orientation_normalized FROM detections WHERE public_id=? LIMIT 1');$stmt->execute([$candidateDetectionId]);$candidate=$stmt->fetch();$path=$candidate?image_file_path((string)$candidate['image_path']):null;if(!$candidate||!$path)json_response(['ok'=>false,'error'=>'候选云端影像不存在。'],404);$raw=(string)file_get_contents($path);$candidateBytes=reference_validate_edited_jpeg($raw)['bytes'];$candidateDbId=(int)$candidate['id'];$candidateSource='cloud_detection';}
    else{$candidateBytes=reference_validate_edited_jpeg(reference_uploaded_file('candidate_file'))['bytes'];}
    ensure_reference_storage();$testId=public_id();$candidatePath=reference_test_candidate_path($testId);if(!$candidatePath)throw new RuntimeException('测试编号无效。');reference_write_atomic($candidatePath,$candidateBytes);
    $regionLabel=(string)reference_regions()[$region]['label'];$regionGuide=reference_region_guidance($region);
    $qualityConfig=$pdo->query('SELECT quality_model,quality_confidence_threshold FROM capture_reference_settings WHERE id=1 LIMIT 1')->fetch()?:[];
    $threshold=max(0.5,min(0.99,(float)($qualityConfig['quality_confidence_threshold']??0.8)));
    $settings=ai_dentist_settings($pdo);$configuredModel=trim((string)($qualityConfig['quality_model']??''));$model=$configuredModel!==''?$configuredModel:(string)$settings['primary_model'];$settings['temperature']=0.0;$settings['high_resolution_images']=1;
    $candidateNumber=$oppositeRef?5:4;
    $sideCoordinateGuide='图片已是服务器最终保存方向，不是自拍镜像。严禁再次水平翻转或凭直觉交换左右。画面左/右是观看图片时的左/右；本人左/右是被拍摄者的解剖左/右。';
    if($region==='left_bite'||$region==='right_bite')$sideCoordinateGuide.='固定构图（优先级最高）：left_bite（第二张、左侧咬合位）=左牙右嘴角，即画面最左边是牙齿/牙列主体、画面最右边是人脸嘴角/软组织出口；right_bite（第三张、右侧咬合位）=左嘴角右牙，即画面最左边是人脸嘴角/软组织出口、画面最右边是牙齿/牙列主体。不得使用相反映射；即使参考图标签或解剖直觉冲突，也必须服从该画面坐标规则。';
    elseif($oppositeRef)$sideCoordinateGuide.='以图3目标区域合适参考图与图4反侧合适参考图的整体构图相似性为准，不可只根据单个牙尖或局部亮暗猜测左右。';
    $schema='{"detected_region":"front_bite|left_bite|right_bite|upper_left_open|upper_right_open|lower_left_open|lower_right_open|unknown","region_match":true,"composition_match_to_good":false,"distance":"too_far|too_close|good|unknown","sharpness":"good|blurred|unknown","lighting":"good|too_dark|overexposed|unknown","framing":"complete|partial|unknown","accepted":false,"reason_code":"简短英文代码","instruction":"不超过24字的可执行中文建议","summary":"不超过80字中文依据","confidence":0.0}';
    $taskPrompt="任务：判断图{$candidateNumber}能否作为“{$regionLabel}”（ID={$region}）的规范采集图。\n"
      ."目标区域定义：{$regionGuide}\n"
      ."图片可能来自不同的人。必须忽略牙齿天然大小、颜色、缺牙、疾病和个体差异；只比较摄影几何与图像质量。\n"
      ."左右坐标强制规则：{$sideCoordinateGuide}\n"
      .($oppositeRef?'图4是“'.(string)reference_regions()[$oppositeRegion]['label']."”的合适参考图，只用于识别左右反侧，是区域硬负样本，绝不能当作目标区域正例。\n":'')
      ."严格按以下顺序独立判定：\n"
      ."1. 区域：图{$candidateNumber}主体是否确为目标区域；必须先与反侧区域区分。对 left_bite/right_bite 只能按上述固定画面坐标检查嘴角与牙列主体所在侧，不得根据可见牙齿颗数、是否拍到最后侧磨牙或解剖直觉改判。固定坐标规则与旧参考图发生冲突时，以固定坐标规则为准；证据不足才 detected_region=unknown。\n"
      ."2. 距离：以图1太远、图2太近、图3合适为同一区域的标尺，重点比较目标牙区在画面中的占比、四周余量、边缘裁切和无关区域占比。最接近图3才是 good。\n"
      ."3. 清晰度：牙缘和表面纹理无法辨认即 blurred。\n"
      ."4. 光照：影响牙面辨认即 too_dark 或 overexposed。\n"
      ."5. 取景：对侧方咬合位采用宽容规则，不统计牙齿颗数，不要求拍到最后侧牙齿；最后侧磨牙未出现本身绝不是 partial。只要可辨认的侧方咬合关系清楚，至少包含具有判断价值的犬牙、前磨牙或磨牙颊侧区域，且主体未被严重裁切或遮挡，就判 complete。其他区域仅在目标牙区被明显裁切、遮挡或关键牙面不完整时判 partial。\n"
      ."先明确输出 composition_match_to_good：当候选图与图3在牙区占比、四周余量、裁切和可见牙面上近似一致时为 true。图3就是本版本对构图和暴露范围的最终正标准。不得额外发明图3没有满足的条件；尤其不得把张大嘴角、牵拉口角、嘴唇暴露程度作为独立门槛。composition_match_to_good=true 且区域正确时，distance 必须为 good、framing 必须为 complete。\n"
      ."禁止因为候选图看起来清楚就忽略区域或距离错误。任何一项为 unknown，accepted 必须为 false。\n"
      ."只有区域正确、distance=good、sharpness=good、lighting=good、framing=complete 且你对整体判断的 confidence 足够高时，accepted 才可为 true。\n"
      ."只返回一个 JSON 对象，不要 Markdown、解释文字或代码块。格式：{$schema}";
    $content=[['type'=>'text','text'=>$taskPrompt]];
    $referenceNames=['too_far'=>'图1：太远参考图（只作为距离反例）','too_close'=>'图2：太近参考图（只作为距离反例）','good'=>'图3：合适参考图（距离与取景正例）'];
    $goodBytes='';foreach($refs as $ref){$path=reference_safe_path((string)$ref['image_path']);if(!$path)json_response(['ok'=>false,'error'=>'参考图文件缺失。'],409);$distance=(string)$ref['distance_label'];$bytes=(string)file_get_contents($path);if($distance==='good')$goodBytes=$bytes;$content[]=['type'=>'text','text'=>$referenceNames[$distance]??('参考图：'.$distance)];$content[]=['type'=>'image_url','image_url'=>['url'=>reference_test_data_url($bytes)]];}
    if($oppositeRef){$content[]=['type'=>'text','text'=>'图4：'.(string)reference_regions()[$oppositeRegion]['label'].'合适参考图（反侧区域硬负样本）'];$content[]=['type'=>'image_url','image_url'=>['url'=>reference_test_data_url($oppositeBytes)]];}
    $content[]=['type'=>'text','text'=>'图'.$candidateNumber.'：本次待判定候选图。请只对这张图输出最终结论。'];$content[]=['type'=>'image_url','image_url'=>['url'=>reference_test_data_url($candidateBytes)]];$similarity=$goodBytes!==''?reference_good_similarity($candidateBytes,$goodBytes):['available'=>false];$prepareMs=(int)round((microtime(true)-$totalStarted)*1000);$modelStarted=microtime(true);
    try{
      $provider=ai_dentist_provider_call($model,[['role'=>'system','content'=>'你是口腔摄影采集质控器，不是疾病诊断助手。严格按用户给出的画面坐标映射识别左右，不得自行镜像或交换患者左右；固定画面坐标规则优先于解剖直觉和参考图标签。侧方咬合位不得统计牙齿颗数，也不得因为没拍到最后侧牙齿而拒绝。你必须逐项执行区域、距离、清晰度、光照、取景判定；不确定时明确拒绝，不得为了给出肯定答案而猜测。严格输出用户要求的单个 JSON 对象。'],['role'=>'user','content'=>$content]],$settings);
      $modelMs=(int)($provider['latency_ms']??round((microtime(true)-$modelStarted)*1000));$decoded=reference_normalize_test_result(reference_parse_model_json((string)$provider['content']),$region,$threshold);$decoded=reference_apply_similarity_guard($decoded,$region,$threshold,$similarity);$persistStarted=microtime(true);
      $decoded['_policy']=['model'=>(string)$provider['model'],'confidence_threshold'=>round($threshold,3),'temperature'=>0.0,'high_resolution_images'=>true];
      $decoded['_timing']=['prepare_ms'=>$prepareMs,'model_ms'=>$modelMs,'persist_ms'=>0,'total_ms'=>0];
      $stmt=$pdo->prepare('INSERT INTO capture_reference_tests(public_id,admin_user_id,version_id,expected_region,candidate_source,candidate_detection_id,model_name,success,result_json,latency_ms) VALUES(?,?,?,?,?,?,?,1,?,?)');$stmt->execute([$testId,(int)$admin['id'],(int)$version['id'],$region,$candidateSource,$candidateDbId,(string)$provider['model'],json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$modelMs]);
      $persistMs=(int)round((microtime(true)-$persistStarted)*1000);$totalMs=(int)round((microtime(true)-$totalStarted)*1000);$decoded['_timing']=['prepare_ms'=>$prepareMs,'model_ms'=>$modelMs,'persist_ms'=>$persistMs,'total_ms'=>$totalMs];
      $pdo->prepare('UPDATE capture_reference_tests SET result_json=? WHERE public_id=?')->execute([json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$testId]);reference_audit($pdo,$admin,'test_validation',(int)$version['id'],null,['test_id'=>$testId,'region'=>$region,'success'=>true,'total_ms'=>$totalMs]);
      json_response(['ok'=>true,'test_id'=>$testId,'model'=>$provider['model'],'latency_ms'=>$modelMs,'timing'=>$decoded['_timing'],'candidate_url'=>'api/reference_test_image.php?id='.rawurlencode($testId),'result'=>$decoded]);
    } catch(Throwable $error){
      $totalMs=(int)round((microtime(true)-$totalStarted)*1000);$stmt=$pdo->prepare('INSERT INTO capture_reference_tests(public_id,admin_user_id,version_id,expected_region,candidate_source,candidate_detection_id,model_name,success,error_message,latency_ms) VALUES(?,?,?,?,?,?,?,0,?,?)');$stmt->execute([$testId,(int)$admin['id'],(int)$version['id'],$region,$candidateSource,$candidateDbId,$model,mb_substr($error->getMessage(),0,1000),$totalMs]);reference_audit($pdo,$admin,'test_validation',(int)$version['id'],null,['test_id'=>$testId,'region'=>$region,'success'=>false,'total_ms'=>$totalMs]);json_response(['ok'=>false,'error'=>'测试失败：'.$error->getMessage(),'test_id'=>$testId,'timing'=>['total_ms'=>$totalMs]],502);
    }
  }

  json_response(['ok'=>false,'error'=>'接口不存在。'],404);
} catch(PDOException $error) {
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('reference_library.php database: '.$error->getMessage());json_response(['ok'=>false,'error'=>'参考图数据库尚未完成迁移。'],503);
} catch(Throwable $error) {
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('reference_library.php: '.$error->getMessage());json_response(['ok'=>false,'error'=>$error->getMessage()?:'参考图后台暂时不可用。'],503);
}
