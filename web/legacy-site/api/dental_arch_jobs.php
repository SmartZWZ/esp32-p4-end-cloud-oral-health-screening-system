<?php
declare(strict_types=1);
require_once __DIR__ . '/dental_arch_common.php';

function dental_arch_member(PDO $pdo, int $userId, string $publicId): array {
  $stmt=$pdo->prepare("SELECT id,public_id,name,relationship FROM family_members WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");
  $stmt->execute([mb_substr($publicId,0,32),$userId]);$row=$stmt->fetch();
  if(!$row)json_response(['ok'=>false,'error'=>'家庭成员不存在或不属于当前账号。'],404);
  return $row;
}

function dental_arch_source_rows(PDO $pdo, int $userId, array $userData): array {
  $regions=dental_arch_regions();$sourceType=(string)($userData['source_type']??'manual');$rows=[];$member=null;$capture=null;
  if($sourceType==='capture_archive'){
    $archiveId=mb_substr(trim((string)($userData['capture_archive_id']??'')),0,32);
    $stmt=$pdo->prepare("SELECT cs.id,cs.public_id,cs.member_id,cs.status,m.public_id AS member_public_id,m.name AS member_name FROM capture_sessions cs INNER JOIN family_members m ON m.id=cs.member_id WHERE cs.public_id=? AND cs.user_id=? AND m.status='active' LIMIT 1");
    $stmt->execute([$archiveId,$userId]);$capture=$stmt->fetch();
    if(!$capture)json_response(['ok'=>false,'error'=>'七图档案袋不存在或不属于当前账号。'],404);
    $stmt=$pdo->prepare("SELECT id,public_id,member_id,device_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,capture_region_id,capture_region_index FROM detections WHERE user_id=? AND capture_session_id=? AND source_detection_id IS NULL ORDER BY capture_region_index,id");
    $stmt->execute([$userId,(int)$capture['id']]);
    foreach($stmt->fetchAll() as $row){$region=(string)$row['capture_region_id'];if(isset($regions[$region])&&!isset($rows[$region]))$rows[$region]=$row;}
    if(count($rows)!==7)json_response(['ok'=>false,'error'=>'档案袋还没有集齐七个固定视角，请先补齐缺失图片。'],409);
    $member=['id'=>(int)$capture['member_id'],'public_id'=>(string)$capture['member_public_id'],'name'=>(string)$capture['member_name']];
  }else{
    $member=dental_arch_member($pdo,$userId,trim((string)($userData['member_id']??'')));
    $selected=is_array($userData['views']??null)?$userData['views']:[];
    foreach($regions as $region=>$meta){$publicId=mb_substr(trim((string)($selected[$region]??'')),0,32);if($publicId==='')json_response(['ok'=>false,'error'=>'七个固定视角尚未全部选择。'],422);$stmt=$pdo->prepare("SELECT id,public_id,member_id,device_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,capture_region_id,capture_region_index FROM detections WHERE public_id=? AND user_id=? AND member_id=? AND source_detection_id IS NULL LIMIT 1");$stmt->execute([$publicId,$userId,(int)$member['id']]);$row=$stmt->fetch();if(!$row||!image_file_path((string)$row['image_path']))json_response(['ok'=>false,'error'=>$meta['label'].'图片不存在或文件已丢失。'],409);$row['capture_region_id']=$region;$row['capture_region_index']=$meta['index'];$rows[$region]=$row;}
    $sourceType='manual';
  }
  uksort($rows,static fn(string $a,string $b):int=>(dental_arch_regions()[$a]['index']??99)<=>(dental_arch_regions()[$b]['index']??99));
  return ['source_type'=>$sourceType,'member'=>$member,'capture'=>$capture,'rows'=>$rows];
}

function dental_arch_create_outline_detection(PDO $pdo, array $source): array {
  $publicId=public_id();
  $stmt=$pdo->prepare("INSERT INTO detections(public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,upload_mode,model_pipeline,status,source_detection_id,progress_step,progress_total,progress_label,report_text) SELECT ?,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,'detect','tooth_outline','received',id,0,1,'等待本地牙齿轮廓模型。','牙列建档：等待本地轮廓提取。' FROM detections WHERE id=?");
  $stmt->execute([$publicId,(int)$source['id']]);
  return ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$publicId];
}

function dental_arch_job_payload(PDO $pdo, int $userId, string $publicId): array {
  $stmt=$pdo->prepare("SELECT job.*,m.public_id AS member_public_id,m.name AS member_name,cs.public_id AS capture_public_id,v.public_id AS version_public_id,v.version_number,v.status AS version_status FROM dental_arch_jobs job INNER JOIN family_members m ON m.id=job.member_id LEFT JOIN capture_sessions cs ON cs.id=job.capture_session_id LEFT JOIN dental_arch_versions v ON v.id=job.active_version_id WHERE job.public_id=? AND job.user_id=? LIMIT 1");
  $stmt->execute([mb_substr($publicId,0,32),$userId]);$job=$stmt->fetch();if(!$job)json_response(['ok'=>false,'error'=>'牙列生成任务不存在。'],404);
  $images=$pdo->prepare("SELECT image.capture_region_id,image.capture_region_index,image.source_kind,image.status,image.error_message,source.public_id AS source_public_id,outline.public_id AS outline_public_id FROM dental_arch_job_images image INNER JOIN detections source ON source.id=image.source_detection_id INNER JOIN detections outline ON outline.id=image.outline_detection_id WHERE image.job_id=? ORDER BY image.capture_region_index");
  $images->execute([(int)$job['id']]);
  return ['public_id'=>(string)$job['public_id'],'status'=>(string)$job['status'],'review_decision'=>(string)$job['review_decision'],'progress_step'=>(int)$job['progress_step'],'progress_total'=>(int)$job['progress_total'],'progress_label'=>(string)($job['progress_label']??''),'error_message'=>(string)($job['error_message']??''),'member'=>['public_id'=>(string)$job['member_public_id'],'name'=>(string)$job['member_name']],'capture_archive_id'=>(string)($job['capture_public_id']??''),'version'=>!empty($job['version_public_id'])?['public_id'=>(string)$job['version_public_id'],'number'=>(int)$job['version_number'],'status'=>(string)$job['version_status']]:null,'images'=>$images->fetchAll(),'updated_at'=>(string)$job['updated_at']];
}

function dental_arch_manifest(PDO $pdo, int $userId, string $versionPublicId): array {
  $stmt=$pdo->prepare("SELECT version.*,job.public_id AS job_public_id,job.source_type,job.capture_session_id,m.public_id AS member_public_id,m.name AS member_name FROM dental_arch_versions version INNER JOIN dental_arch_jobs job ON job.id=version.job_id INNER JOIN family_members m ON m.id=job.member_id WHERE version.public_id=? AND job.user_id=? LIMIT 1");
  $stmt->execute([mb_substr($versionPublicId,0,32),$userId]);$version=$stmt->fetch();if(!$version)json_response(['ok'=>false,'error'=>'牙列档案版本不存在。'],404);
  $result=dental_arch_json((string)$version['result_json']);if(!$result)json_response(['ok'=>false,'error'=>'牙列档案内容损坏。'],500);
  $sourceIds=[];foreach($result['views']??[] as $view)if(!empty($view['source_detection_db_id']))$sourceIds[]=(int)$view['source_detection_db_id'];
  $sources=[];if($sourceIds){$placeholders=implode(',',array_fill(0,count($sourceIds),'?'));$sourceStmt=$pdo->prepare("SELECT id,public_id,image_width,image_height FROM detections WHERE id IN ({$placeholders}) AND user_id=?");$sourceStmt->execute([...$sourceIds,$userId]);foreach($sourceStmt->fetchAll() as $source)$sources[(int)$source['id']]=$source;}
  $outlineByView=[];
  $outlineStmt=$pdo->prepare("SELECT image.capture_region_id,outline.public_id AS outline_public_id FROM dental_arch_job_images image INNER JOIN detections outline ON outline.id=image.outline_detection_id WHERE image.job_id=?");
  $outlineStmt->execute([(int)$version['job_id']]);
  foreach($outlineStmt->fetchAll() as $outlineRow)$outlineByView[(string)$outlineRow['capture_region_id']]=(string)$outlineRow['outline_public_id'];
  $views=[];$teeth=[];
  foreach($result['views']??[] as $view){
    $source=$sources[(int)($view['source_detection_db_id']??0)]??null;if(!$source)continue;
    $viewId=(string)($view['view_id']??'');
    $outlinePublicId=$outlineByView[$viewId]??'';
    $view['source_detection_id']=(string)$source['public_id'];
    $view['outline_detection_id']=$outlinePublicId;
    $view['original_url']='api/image.php?id='.rawurlencode((string)$source['public_id']);
    unset($view['source_detection_db_id']);$views[]=$view;
    foreach($view['teeth']??[] as $tooth){
      $fdi=(string)($tooth['tooth_id']??'');if(!dental_arch_valid_fdi($fdi))continue;
      $instanceCandidates=is_array($tooth['source_instance_ids']??null)?$tooth['source_instance_ids']:[];
      $instanceCandidates[]=(string)($tooth['instance_id']??'');
      $navigationId=0;
      foreach($instanceCandidates as $instanceId){if(preg_match('/T0*([1-9][0-9]*)/i',(string)$instanceId,$match)){ $navigationId=(int)$match[1]; break; }}
      $teeth[$fdi]??=['fdi'=>(int)$fdi,'views'=>[]];
      $teeth[$fdi]['views'][]=[
        'view_id'=>$viewId,
        'view_label'=>dental_arch_regions()[$viewId]['label']??$viewId,
        'original_url'=>$view['original_url'],
        'source_detection_id'=>(string)$source['public_id'],
        'outline_detection_id'=>$outlinePublicId,
        'tooth_navigation_id'=>$navigationId,
        'instance_id'=>(string)($tooth['instance_id']??''),
        'image_size'=>[(int)$source['image_width'],(int)$source['image_height']],
        'bbox_xyxy'=>$tooth['bbox_xyxy'],
        'polygon_xy'=>$tooth['contour'],
        'confidence'=>$tooth['confidence'],
        'needs_review'=>$tooth['needs_review'],
        'metrics'=>['candidate_count'=>(int)($tooth['candidate_count']??0),'max_structure_score'=>0,'total_skeleton_length_px'=>0,'mean_candidate_width_px'=>0],
        'candidates'=>$tooth['darkline_candidates']??[],
      ];
    }
  }
  ksort($teeth,SORT_NUMERIC);
  return ['ok'=>true,'archive_id'=>(string)$version['public_id'],'job_id'=>(string)$version['job_public_id'],'title'=>'七视图单牙档案 · 版本 '.(int)$version['version_number'],'created_at'=>(string)$version['created_at'],'status'=>(string)$version['status'],'is_current'=>(bool)$version['is_current'],'member'=>['public_id'=>(string)$version['member_public_id'],'name'=>(string)$version['member_name']],'notice'=>'轮廓与牙号由本地模型和视觉大模型协同生成，人工确认前不得作为诊断依据。','coordinate_system'=>'FDI 恒牙编号；原图像素坐标，左上角为原点。','views'=>$views,'teeth'=>$teeth,'present_teeth'=>array_map('intval',array_keys($teeth)),'missing_teeth'=>array_values(array_diff([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28,48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38],array_map('intval',array_keys($teeth)))),'conflicts'=>$result['conflicts']??[],'review_required'=>(bool)($result['review_required']??false),'summary'=>['view_count'=>count($views),'tooth_count'=>count($teeth),'tooth_view_count'=>array_sum(array_map(static fn(array $item):int=>count($item['views']),$teeth))]];
}

function dental_arch_review_result(array $stored, array $submitted): array {
  $byView=[];foreach($stored['views']??[] as $view)$byView[(string)$view['view_id']]=$view;
  $result=$stored;$result['views']=[];$result['conflicts']=[];$result['review_notes']=[];$result['review_required']=false;$present=[];
  foreach(dental_arch_regions() as $viewId=>$meta){$base=$byView[$viewId]??null;if(!$base)throw new RuntimeException('牙列版本缺少 '.$meta['label'].'。');$candidate=null;foreach($submitted['views']??[] as $view)if(is_array($view)&&($view['view_id']??'')===$viewId){$candidate=$view;break;}if(!$candidate)$candidate=$base;$width=(int)$base['image_width'];$height=(int)$base['image_height'];$seen=[];$teeth=[];foreach(is_array($candidate['teeth']??null)?$candidate['teeth']:[] as $index=>$tooth){if(!is_array($tooth))continue;$fdi=trim((string)($tooth['tooth_id']??''));if(!dental_arch_valid_fdi($fdi))continue;if(isset($seen[$fdi]))throw new RuntimeException($meta['label'].'中牙号 '.$fdi.' 重复。');$seen[$fdi]=true;$contour=dental_arch_points($tooth['contour']??null,$width,$height);if(!$contour)continue;$baseTooth=null;foreach($base['teeth']??[] as $item)if(($item['instance_id']??'')===($tooth['instance_id']??'')){$baseTooth=$item;break;}$teeth[]=['instance_id'=>mb_substr(trim((string)($tooth['instance_id']??('M'.($index+1)))),0,24),'source_instance_ids'=>$baseTooth['source_instance_ids']??[],'tooth_id'=>$fdi,'action'=>'manual','bbox_xyxy'=>dental_arch_bbox_from_points($contour),'contour'=>$contour,'confidence'=>round(min(max((float)($tooth['confidence']??1),0),1),4),'cropped'=>(bool)($tooth['cropped']??false),'needs_review'=>false,'reason'=>'人工复核','candidate_count'=>(int)($baseTooth['candidate_count']??0),'darkline_candidates'=>$baseTooth['darkline_candidates']??[],'source_method'=>'manual'];$present[$fdi]=true;}$base['teeth']=$teeth;$base['one_to_one_pass']=true;$base['issues']=[];$result['views'][]=$base;}
  ksort($present,SORT_NUMERIC);$result['present_teeth']=array_keys($present);$result['reviewed_at']=date('c');return $result;
}

try {
  $user=require_user();$pdo=db();$method=(string)($_SERVER['REQUEST_METHOD']??'GET');$action=(string)($_GET['action']??'status');
  if($method==='GET'&&$action==='status')json_response(['ok'=>true,'job'=>dental_arch_job_payload($pdo,(int)$user['id'],trim((string)($_GET['id']??'')))]);
  if($method==='GET'&&$action==='manifest')json_response(dental_arch_manifest($pdo,(int)$user['id'],trim((string)($_GET['version']??''))));
  if($method!=='POST')json_response(['ok'=>false,'error'=>'接口不存在。'],404);
  require_csrf();$data=request_data();
  if($action==='start'){
    $source=dental_arch_source_rows($pdo,(int)$user['id'],$data);$decision=(string)($data['review_decision']??'review');if(!in_array($decision,['review','direct'],true))$decision='review';$settings=dental_arch_settings($pdo);$guide=dental_arch_guide();
    if($source['capture']){$active=$pdo->prepare("SELECT public_id FROM dental_arch_jobs WHERE capture_session_id=? AND status IN ('waiting_outline','vision_pending','vision_processing') ORDER BY id DESC LIMIT 1");$active->execute([(int)$source['capture']['id']]);$existing=$active->fetchColumn();if($existing)json_response(['ok'=>false,'error'=>'这个档案袋已有正在处理的牙列任务，请查看任务进度。','job_id'=>(string)$existing],409);}
    $pdo->beginTransaction();try{$jobPublic=public_id();$pdo->prepare("INSERT INTO dental_arch_jobs(public_id,user_id,member_id,capture_session_id,source_type,review_mode,review_decision,status,visual_model,fallback_model,guide_sha256,progress_step,progress_total,progress_label) VALUES(?,?,?,?,?,'ask',?,'waiting_outline',?,?,?,0,10,'正在创建七个本地轮廓任务。')")->execute([$jobPublic,(int)$user['id'],(int)$source['member']['id'],$source['capture']?(int)$source['capture']['id']:null,$source['source_type'],$decision,(string)$settings['dental_arch_model'],(string)$settings['dental_arch_fallback_model'],(string)$guide['sha256']]);$jobId=(int)$pdo->lastInsertId();$link=$pdo->prepare("INSERT INTO dental_arch_job_images(job_id,source_detection_id,outline_detection_id,capture_region_id,capture_region_index,source_kind,status) VALUES(?,?,?,?,?,?,'waiting_outline')");foreach($source['rows'] as $region=>$row){$outline=dental_arch_create_outline_detection($pdo,$row);$kind=$source['source_type']==='manual'?'manual_upload':($row['device_id']===null?'local_fill':'device');$link->execute([$jobId,(int)$row['id'],(int)$outline['id'],$region,(int)dental_arch_regions()[$region]['index'],$kind]);}$pdo->commit();json_response(['ok'=>true,'job_id'=>$jobPublic,'status'=>'waiting_outline','message'=>'七张图片已加入本地轮廓队列。'],201);}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
  }
  if($action==='retry'){$jobId=mb_substr(trim((string)($data['job_id']??'')),0,32);$region=(string)($data['region_id']??'');$stage=(string)($data['stage']??'vision');$stmt=$pdo->prepare('SELECT * FROM dental_arch_jobs WHERE public_id=? AND user_id=? LIMIT 1');$stmt->execute([$jobId,(int)$user['id']]);$job=$stmt->fetch();if(!$job)json_response(['ok'=>false,'error'=>'任务不存在。'],404);if($stage==='outline'&&isset(dental_arch_regions()[$region])){$imageStmt=$pdo->prepare('SELECT image.id AS link_id,source.id,source.public_id,source.user_id,source.device_id,source.member_id,source.image_path,source.image_width,source.image_height,source.image_bytes,source.source_mirrored,source.normalization_applied,source.orientation_normalized FROM dental_arch_job_images image INNER JOIN detections source ON source.id=image.source_detection_id WHERE image.job_id=? AND image.capture_region_id=? LIMIT 1');$imageStmt->execute([(int)$job['id'],$region]);$image=$imageStmt->fetch();if(!$image)json_response(['ok'=>false,'error'=>'指定视角不存在。'],404);$pdo->beginTransaction();try{$outline=dental_arch_create_outline_detection($pdo,$image);$pdo->prepare("UPDATE dental_arch_job_images SET outline_detection_id=?,status='waiting_outline',local_result_json=NULL,vision_result_json=NULL,joint_result_json=NULL,error_message=NULL WHERE id=?")->execute([(int)$outline['id'],(int)$image['link_id']]);$pdo->prepare("UPDATE dental_arch_jobs SET status='waiting_outline',progress_step=0,progress_label='正在重新提取指定视角轮廓。',error_message=NULL WHERE id=?")->execute([(int)$job['id']]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}}elseif((int)$job['progress_step']>=9){$pdo->prepare("UPDATE dental_arch_jobs SET status='vision_pending',progress_step=9,progress_label='正在重新进行七图统一复核。',error_message=NULL WHERE id=?")->execute([(int)$job['id']]);$pdo->prepare("UPDATE dental_arch_job_images SET status=IF(vision_result_json IS NULL,'outline_completed','vision_completed'),joint_result_json=NULL,error_message=NULL WHERE job_id=?")->execute([(int)$job['id']]);}else{$pdo->prepare("UPDATE dental_arch_jobs SET status='vision_pending',progress_step=2,progress_label='正在重新进行视觉牙号复核。',error_message=NULL WHERE id=?")->execute([(int)$job['id']]);$pdo->prepare("UPDATE dental_arch_job_images SET status='outline_completed',vision_result_json=NULL,joint_result_json=NULL,error_message=NULL WHERE job_id=?")->execute([(int)$job['id']]);}json_response(['ok'=>true,'job'=>dental_arch_job_payload($pdo,(int)$user['id'],$jobId)]);}
  if($action==='create_revision'){$versionPublic=mb_substr(trim((string)($data['version_id']??'')),0,32);$focus=trim((string)($data['tooth_id']??''));$stmt=$pdo->prepare('SELECT version.result_json,version.status AS source_version_status,job.* FROM dental_arch_versions version INNER JOIN dental_arch_jobs job ON job.id=version.job_id WHERE version.public_id=? AND job.user_id=? LIMIT 1');$stmt->execute([$versionPublic,(int)$user['id']]);$job=$stmt->fetch();if(!$job)json_response(['ok'=>false,'error'=>'牙列版本不存在。'],404);if(!in_array((string)$job['source_version_status'],['confirmed','stale'],true))json_response(['ok'=>false,'error'=>'当前版本已经处于待确认状态。'],409);$result=dental_arch_json((string)$job['result_json']);if(!$result)throw new RuntimeException('牙列版本内容损坏。');if(dental_arch_valid_fdi($focus)){foreach($result['views']??[] as &$view)foreach($view['teeth']??[] as &$tooth)if((string)($tooth['tooth_id']??'')===$focus){$tooth['needs_review']=true;$tooth['reason']='从单牙档案发起人工修订';}unset($tooth,$view);$result['review_required']=true;$result['review_notes'][]='重点复核 FDI '.$focus;}$revision=dental_arch_store_version($pdo,$job,$result,(string)$job['visual_model']);$pdo->prepare("UPDATE dental_arch_jobs SET status='review_required',active_version_id=?,progress_step=10,progress_label='已创建人工修订版本，请确认轮廓与牙号。',error_message=NULL WHERE id=?")->execute([(int)$revision['id'],(int)$job['id']]);json_response(['ok'=>true,'version_id'=>(string)$revision['public_id'],'status'=>'draft'],201);}
  if($action==='save_review'||$action==='confirm'){$versionId=mb_substr(trim((string)($data['version_id']??'')),0,32);$stmt=$pdo->prepare("SELECT version.*,job.user_id,job.id AS job_db_id,job.capture_session_id FROM dental_arch_versions version INNER JOIN dental_arch_jobs job ON job.id=version.job_id WHERE version.public_id=? AND job.user_id=? LIMIT 1");$stmt->execute([$versionId,(int)$user['id']]);$version=$stmt->fetch();if(!$version)json_response(['ok'=>false,'error'=>'待确认牙列版本不存在。'],404);if((string)$version['status']!=='draft')json_response(['ok'=>false,'error'=>'只有待确认版本可以修改或确认。'],409);$stored=dental_arch_json((string)$version['result_json']);if(!$stored)throw new RuntimeException('待确认牙列版本内容损坏。');$reviewed=$action==='save_review'?dental_arch_review_result($stored,$data):$stored;$json=json_encode($reviewed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$pdo->beginTransaction();try{$pdo->prepare('UPDATE dental_arch_versions SET result_json=? WHERE id=?')->execute([$json,(int)$version['id']]);dental_arch_replace_tooth_views($pdo,(int)$version['id'],$reviewed);if($action==='confirm'){if(!empty($version['capture_session_id'])){$pdo->prepare('UPDATE dental_arch_versions version INNER JOIN dental_arch_jobs lineage ON lineage.id=version.job_id SET version.is_current=0 WHERE lineage.capture_session_id=?')->execute([(int)$version['capture_session_id']]);}else{$pdo->prepare('UPDATE dental_arch_versions SET is_current=0 WHERE job_id=?')->execute([(int)$version['job_id']]);}$pdo->prepare("UPDATE dental_arch_versions SET status='confirmed',is_current=1,confirmed_by=?,confirmed_at=NOW() WHERE id=?")->execute([(int)$user['id'],(int)$version['id']]);$pdo->prepare("UPDATE dental_arch_jobs SET status='completed',active_version_id=?,progress_step=10,progress_label='牙列档案已确认并绑定。',completed_at=NOW(),error_message=NULL WHERE id=?")->execute([(int)$version['id'],(int)$version['job_id']]);}$pdo->commit();json_response(['ok'=>true,'version_id'=>$versionId,'status'=>$action==='confirm'?'confirmed':'draft']);}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}}
  json_response(['ok'=>false,'error'=>'接口不存在。'],404);
} catch(PDOException $error){error_log('dental_arch_jobs.php database: '.$error->getMessage());json_response(['ok'=>false,'error'=>'牙列标注数据库尚未完成迁移。'],503);}catch(Throwable $error){error_log('dental_arch_jobs.php: '.$error->getMessage());json_response(['ok'=>false,'error'=>$error->getMessage()?:'牙列任务暂时不可用。'],500);}
