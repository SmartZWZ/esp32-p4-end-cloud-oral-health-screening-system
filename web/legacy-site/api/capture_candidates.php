<?php
declare(strict_types=1);
// Seven-view candidate protocol V1: 20260813-hardware-v1-sync-v6.
require_once __DIR__ . '/capture_protocol_common.php';

function capture_completed_count(int $mask): int { $count=0;for($i=0;$i<7;$i++)if($mask&(1<<$i))$count++;return $count; }

try {
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')capture_error('invalid_request_id','仅支持 POST 请求',405,false);
  if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')capture_error('invalid_request_id','控制接口需要 JSON',415,false);
  $pdo=db();$device=capture_require_device($pdo);$data=capture_request_json();$action=(string)($_GET['action']??'');
  if((int)($data['protocol_version']??0)!==CAPTURE_PROTOCOL_VERSION)capture_error('invalid_request_id','协议版本不支持',422,false);
  $requestId=trim((string)($data['request_id']??''));$temporaryId=trim((string)($data['temporary_image_id']??''));$sessionPublic=trim((string)($data['capture_session_id']??''));
  if(!capture_valid_id($requestId,1,64)||!capture_valid_id($temporaryId,12,32))capture_error('invalid_request_id','候选图标识无效',422,false,$requestId);
  if(!in_array($action,['confirm','discard'],true))capture_error('invalid_request_id','未知候选图操作',404,false,$requestId);

  $pdo->beginTransaction();
  $stmt=$pdo->prepare('SELECT c.*,s.public_id AS session_public_id,s.status AS session_status,s.current_region_index,s.completed_mask,s.completed_count FROM capture_candidates c LEFT JOIN capture_sessions s ON s.id=c.session_id WHERE c.public_id=? AND c.device_id=? LIMIT 1 FOR UPDATE');
  $stmt->execute([$temporaryId,(int)$device['id']]);$candidate=$stmt->fetch();
  if(!$candidate||(string)$candidate['request_id']!==$requestId){$pdo->rollBack();capture_error('invalid_request_id','候选图不存在或请求不匹配',404,false,$requestId);}
  if((string)($candidate['session_public_id']??'')!==$sessionPublic){$pdo->rollBack();capture_error('session_mismatch','采集会话不匹配',409,false,$requestId);}

  if($action==='discard'){
    if((string)$candidate['lifecycle_status']==='confirmed'){$pdo->rollBack();capture_error('region_already_confirmed','正式影像不能丢弃',409,false,$requestId);}
    if((string)$candidate['lifecycle_status']!=='discarded')$pdo->prepare("UPDATE capture_candidates SET lifecycle_status='discarded',discarded_at=NOW() WHERE id=?")->execute([(int)$candidate['id']]);
    $pdo->commit();capture_unlink_candidate($candidate);capture_response(['ok'=>true,'request_id'=>$requestId,'temporary_image_id'=>$temporaryId,'lifecycle_status'=>'discarded']);
  }

  if((string)$candidate['lifecycle_status']==='confirmed'){
    $payload=json_decode((string)$candidate['confirm_response_json'],true);$pdo->commit();if(is_array($payload))capture_response($payload);capture_error('internal_error','确认历史结果不可读取',500,true,$requestId);
  }
  if((string)$candidate['validation_status']!=='completed'||!(bool)$candidate['accepted']){$pdo->rollBack();capture_error('invalid_request_id','候选图尚未通过质量检查',409,false,$requestId);}
  $session=null;
  if((string)$candidate['capture_mode']==='seven_view'){
    $session=capture_require_session($pdo,$device,$sessionPublic,true);
    if((string)$session['status']!=='collecting'){$pdo->rollBack();capture_error('session_mismatch','采集会话已经结束',409,false,$requestId);}
    if((int)$session['current_region_index']!==(int)$candidate['region_index']){$pdo->rollBack();capture_error('region_already_confirmed','会话区域状态已经变化',409,false,$requestId);}
  }
  $canonical=reference_safe_path((string)$candidate['canonical_path']);if(!$canonical){$pdo->rollBack();capture_error('internal_error','候选图片文件不存在',500,true,$requestId);}
  $date=date('Ymd');$dir=storage_history_dir().'/u'.(int)$candidate['user_id'].'/m'.(int)$candidate['member_id'].'/'.$date;
  if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)){$pdo->rollBack();capture_error('internal_error','正式影像目录不可用',500,true,$requestId);}
  $detectionPublic=public_id();$filename='photo_'.$detectionPublic.'.jpg';$finalPath=$dir.'/'.$filename;$temporary=$finalPath.'.tmp';
  if(!@copy($canonical,$temporary)||!@rename($temporary,$finalPath)){@unlink($temporary);$pdo->rollBack();capture_error('internal_error','正式影像保存失败',500,true,$requestId);}
  $relative='history/u'.(int)$candidate['user_id'].'/m'.(int)$candidate['member_id'].'/'.$date.'/'.$filename;
  try{
    $insert=$pdo->prepare("INSERT INTO detections(public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,canonical_orientation,orientation_normalized,upload_mode,model_pipeline,status,report_text,capture_session_id,capture_mode,capture_region_id,capture_region_index) VALUES(?,?,?,?,?,?,?,?,1,'horizontal_flip','patient_coordinate',1,'archive','caries','saved','七视图规范采集影像',?,?,?,?)");
    $insert->execute([$detectionPublic,(int)$candidate['user_id'],(int)$candidate['device_id'],(int)$candidate['member_id'],$relative,(int)$candidate['image_width'],(int)$candidate['image_height'],(int)$candidate['image_bytes'],$session?(int)$session['id']:null,(string)$candidate['capture_mode'],(string)$candidate['expected_region']==='free'?null:(string)$candidate['expected_region'],(int)$candidate['region_index']?:null]);$detectionId=(int)$pdo->lastInsertId();
    $mask=$session?(int)$session['completed_mask']:0;$count=$session?(int)$session['completed_count']:0;$status='completed';$nextIndex=null;$nextRegion=null;
    if($session){$mask|=1<<((int)$candidate['region_index']-1);$count=capture_completed_count($mask);if($count===7&&$mask===127){$status='completed';$pdo->prepare("UPDATE capture_sessions SET status='completed',completed_count=7,completed_mask=127,completed_at=NOW() WHERE id=?")->execute([(int)$session['id']]);}else{$status='collecting';$nextIndex=(int)$candidate['region_index']+1;$nextRegion=capture_regions()[$nextIndex]??null;$pdo->prepare('UPDATE capture_sessions SET current_region_index=?,completed_count=?,completed_mask=? WHERE id=?')->execute([$nextIndex,$count,$mask,(int)$session['id']]);}}
    $payload=['ok'=>true,'request_id'=>$requestId,'capture_session_id'=>$sessionPublic,'temporary_image_id'=>$temporaryId,'detection_id'=>$detectionPublic,'region_id'=>(string)$candidate['expected_region'],'completed_count'=>$count,'completed_mask'=>$mask,'session_status'=>$status,'next_region_index'=>$nextIndex,'next_region'=>$nextRegion];$json=capture_encode($payload);
    if(strlen($json)>CAPTURE_RESPONSE_TARGET_BYTES)throw new RuntimeException('confirm response too large');
    $pdo->prepare("UPDATE capture_candidates SET lifecycle_status='confirmed',detection_id=?,confirm_response_json=?,confirmed_at=NOW() WHERE id=?")->execute([$detectionId,$json,(int)$candidate['id']]);$pdo->commit();capture_unlink_candidate($candidate);capture_response($payload);
  }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();@unlink($finalPath);throw $error;}
} catch(PDOException $error){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('capture_candidates database: '.$error->getMessage());capture_error('internal_error','候选图服务暂不可用',500,true,$requestId??'');
} catch(Throwable $error){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('capture_candidates: '.$error->getMessage());capture_error('internal_error','候选图服务暂不可用',500,true,$requestId??'');
}
