<?php
declare(strict_types=1);
// Seven-view validate protocol V1: 20260821-503-diagnostics-and-bounded-fallback-v1.
define('CAPTURE_LOG_VALIDATE_RESPONSES',true);
require_once __DIR__ . '/capture_protocol_common.php';

function capture_same_validate_request(array $existing,array $upload,array $member,string $mode,string $expectedRegion,int $regionIndex,?array $session): bool {
  $existingSession=(int)($existing['session_id']??0);$requestedSession=(int)($session['id']??0);
  return hash_equals((string)$existing['request_sha256'],(string)$upload['sha256'])
    &&(int)$existing['member_id']===(int)$member['id']
    &&(string)$existing['capture_mode']===$mode
    &&(string)$existing['expected_region']===$expectedRegion
    &&(int)$existing['region_index']===$regionIndex
    &&$existingSession===$requestedSession;
}

function capture_return_existing_validate(array $existing,array $upload,array $member,string $mode,string $expectedRegion,int $regionIndex,?array $session,string $requestId): void {
  if(isset($upload['path']))@unlink((string)$upload['path']);
  if(!capture_same_validate_request($existing,$upload,$member,$mode,$expectedRegion,$regionIndex,$session))capture_error('invalid_request_id','request_id 已用于不同请求',409,false,$requestId);
  if((string)$existing['validation_status']==='validating')capture_error('request_in_progress','相同请求仍在处理中',409,true,$requestId);
  $payload=json_decode((string)$existing['response_json'],true);
  if(is_array($payload))capture_response($payload,(int)($existing['response_http_status']??200));
  capture_error('internal_error','历史请求结果不可读取',500,true,$requestId);
}

try {
  $startedMs=(int)round(microtime(true)*1000);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')capture_error('invalid_request_id','仅支持 POST 请求',405,false);
  $pdo=db();$device=capture_require_device($pdo);
  // The board does not discard ordinary retakes. Keep them temporarily for
  // diagnostics and opportunistically expire old files without blocking flow.
  capture_cleanup_expired_candidates($pdo,10);
  $protocol=(int)capture_header('X-Capture-Protocol-Version');if($protocol!==CAPTURE_PROTOCOL_VERSION)capture_error('invalid_request_id','协议版本不支持',422,false);
  $requestId=capture_header('X-Request-Id');if(!capture_valid_id($requestId,1,64))capture_error('invalid_request_id','request_id 无效',422,false);
  $mode=capture_header('X-Capture-Mode');if(!in_array($mode,['single_image','seven_view'],true))capture_error('invalid_request_id','capture_mode 无效',422,false,$requestId);
  $member=capture_require_member($pdo,$device,capture_header('X-Member-Id'));
  $expectedRegion=capture_header('X-Expected-Region');$regionIndex=(int)capture_header('X-Region-Index');$session=null;
  if($mode==='seven_view'){
    if(!isset(reference_regions()[$expectedRegion])||$regionIndex<1||$regionIndex>7||capture_regions()[$regionIndex]!==$expectedRegion)capture_error('invalid_region','区域编号与区域名称不一致',409,false,$requestId);
    $session=capture_require_session($pdo,$device,capture_header('X-Capture-Session-Id'));
    if((int)$session['member_id']!==(int)$member['id']||(string)$session['capture_mode']!=='seven_view')capture_error('session_mismatch','会话与成员不匹配',409,false,$requestId);
    if((string)$session['status']!=='collecting')capture_error('session_mismatch','采集会话已结束',409,false,$requestId);
    if((int)$session['current_region_index']!==$regionIndex)capture_error($regionIndex<(int)$session['current_region_index']?'region_already_confirmed':'invalid_region','当前采集区域不匹配',409,false,$requestId);
  } else {
    if($expectedRegion!==''&&$expectedRegion!=='free')capture_error('invalid_region','单张模式 expected_region 应为 free',409,false,$requestId);
    $expectedRegion='free';$regionIndex=0;
  }

  $trace=['request_id'=>$requestId,'capture_session_id'=>$session?(string)$session['public_id']:'','region_index'=>$regionIndex,'expected_region'=>$expectedRegion,'started_ms'=>$startedMs];
  capture_trace('ingress',$trace,['capture_mode'=>$mode,'content_length'=>(int)($_SERVER['CONTENT_LENGTH']??0)]);
  capture_trace('auth_ok',$trace);
  capture_trace('headers_valid',$trace,['protocol_version'=>$protocol]);
  capture_trace('session_loaded',$trace,['session_status'=>$session?(string)$session['status']:'single_image']);

  $upload=capture_receive_jpeg($requestId);
  capture_trace('jpeg_decoded',$trace,['jpeg_bytes'=>(int)$upload['bytes'],'image_width'=>(int)$upload['width'],'image_height'=>(int)$upload['height']]);
  $existingStmt=$pdo->prepare('SELECT c.*,s.public_id AS session_public_id FROM capture_candidates c LEFT JOIN capture_sessions s ON s.id=c.session_id WHERE c.device_id=? AND c.request_id=? LIMIT 1');
  $existingStmt->execute([(int)$device['id'],$requestId]);$existing=$existingStmt->fetch();
  if($existing)capture_return_existing_validate($existing,$upload,$member,$mode,$expectedRegion,$regionIndex,$session,$requestId);

  try{$canonical=capture_flip_to_canonical((string)$upload['path'],$requestId);}catch(Throwable $error){@unlink($upload['path']);error_log('capture mirror: '.$error->getMessage());capture_error('internal_error','图片方向处理失败',500,true,$requestId);}
  $temporaryId=capture_new_id('tmp');$pdo->beginTransaction();
  if($session){
    $session=capture_require_session($pdo,$device,(string)$session['public_id'],true);
    if((string)$session['status']!=='collecting'||(int)$session['current_region_index']!==$regionIndex){$pdo->rollBack();@unlink($upload['path']);@unlink($canonical['path']);capture_error('session_mismatch','会话状态已经变化',409,false,$requestId);}
    $busy=$pdo->prepare("SELECT public_id FROM capture_candidates WHERE session_id=? AND validation_status='validating' LIMIT 1 FOR UPDATE");$busy->execute([(int)$session['id']]);if($busy->fetchColumn()){$pdo->rollBack();@unlink($upload['path']);@unlink($canonical['path']);capture_error('request_in_progress','当前会话已有图片正在检查',409,true,$requestId);}
  }
  $stmt=$pdo->prepare("INSERT IGNORE INTO capture_candidates(public_id,protocol_version,device_id,user_id,member_id,session_id,request_id,request_sha256,capture_mode,expected_region,region_index,validation_status,lifecycle_status,raw_path,canonical_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,canonical_orientation) VALUES(?,1,?,?,?,?,?,?,?,? ,?,'validating','temporary',?,?,?,?,?,1,'horizontal_flip','patient_coordinate')");
  $stmt->execute([$temporaryId,(int)$device['id'],(int)$device['user_id'],(int)$member['id'],$session?(int)$session['id']:null,$requestId,(string)$upload['sha256'],$mode,$expectedRegion,$regionIndex,capture_relative_path((string)$upload['path']),(string)$canonical['relative'],(int)$canonical['width'],(int)$canonical['height'],(int)$canonical['bytes']]);
  if($stmt->rowCount()!==1){
    $pdo->rollBack();@unlink((string)$canonical['path']);
    $raceStmt=$pdo->prepare('SELECT c.*,s.public_id AS session_public_id FROM capture_candidates c LEFT JOIN capture_sessions s ON s.id=c.session_id WHERE c.device_id=? AND c.request_id=? LIMIT 1');
    $raceStmt->execute([(int)$device['id'],$requestId]);$race=$raceStmt->fetch();
    if($race)capture_return_existing_validate($race,$upload,$member,$mode,$expectedRegion,$regionIndex,$session,$requestId);
    @unlink((string)$upload['path']);capture_error('internal_error','候选图登记失败',500,true,$requestId);
  }
  $candidateId=(int)$pdo->lastInsertId();$pdo->commit();

  try {
    if($mode==='seven_view'){capture_trace('model_slot_acquired',$trace,['scheduler'=>'direct_php_request']);$quality=capture_quality_result($pdo,$expectedRegion,(string)$canonical['path'],$startedMs,(int)($session['reference_version_id']??0),$trace);}
    else $quality=['accepted'=>true,'detected_region'=>'unknown','region_match'=>true,'distance'=>'unknown','quality'=>['sharpness'=>'pass','exposure'=>'pass','coverage'=>'pass','positioning'=>'pass','reflection'=>'pass'],'confidence'=>1.0,'reason_code'=>'ok','instruction'=>'单张图片已接收','model'=>'none','reference_version'=>'','model_latency_ms'=>0];
    $elapsed=(int)round(microtime(true)*1000)-$startedMs;if($elapsed>CAPTURE_SERVER_VALIDATE_DEADLINE_MS)throw new RuntimeException('model_timeout');
    $candidate=['public_id'=>$temporaryId,'request_id'=>$requestId,'session_public_id'=>$session?(string)$session['public_id']:'','capture_mode'=>$mode,'region_index'=>$regionIndex,'expected_region'=>$expectedRegion];$payload=capture_validation_payload($candidate,$quality,$elapsed);$json=capture_encode($payload);
    if(strlen($json)>CAPTURE_RESPONSE_TARGET_BYTES)throw new RuntimeException('response_too_large');
    $storedInstruction=(string)$quality['instruction'];if(!empty($quality['model_reason']))$storedInstruction=mb_substr($storedInstruction.'；模型理由：'.(string)$quality['model_reason'],0,96);
    $pdo->prepare("UPDATE capture_candidates SET validation_status='completed',accepted=?,decision=?,detected_region=?,region_match=?,distance_class=?,quality_result=?,confidence=?,reason_code=?,instruction=?,model_name=?,reference_version_code=?,model_latency_ms=?,server_elapsed_ms=?,response_json=?,response_http_status=200,validated_at=NOW() WHERE id=?")->execute([(bool)$quality['accepted']?1:0,(string)$payload['decision'],(string)$quality['detected_region'],(bool)$quality['region_match']?1:0,(string)$quality['distance'],json_encode($quality['quality'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(float)$quality['confidence'],(string)$quality['reason_code'],$storedInstruction,(string)$quality['model'],(string)$quality['reference_version'],(int)$quality['model_latency_ms'],$elapsed,$json,$candidateId]);
    if($mode==='seven_view'){
      capture_trace('validation_result',$trace,[
        'detected_region'=>(string)$quality['detected_region'],'accepted'=>(bool)$quality['accepted'],
        'decision'=>(string)$payload['decision'],'reason_code'=>(string)$quality['reason_code'],
        'model_reason'=>(string)($quality['model_reason']??''),'instruction'=>(string)$quality['instruction'],
        'distance'=>(string)$quality['distance'],'quality_json'=>capture_encode((array)$quality['quality']),
        'model_details_json'=>capture_encode((array)($quality['model_details']??[])),
        'confidence'=>(float)$quality['confidence'],'confidence_threshold'=>(float)($quality['confidence_threshold']??0),
        'reference_version'=>(string)$quality['reference_version'],'model'=>(string)$quality['model'],
        'total_ms'=>$elapsed,'model_ms'=>(int)$quality['model_latency_ms'],'input_bytes'=>(int)($quality['model_input_bytes']??0),
      ]);
    }
    capture_trace('response_sent',$trace,['http_status'=>200,'accepted'=>(bool)$quality['accepted'],'error_code'=>'']);
    capture_response($payload);
  } catch(Throwable $error){
    $meta=capture_model_error_meta($error);$code=(string)$meta['code'];$status=(int)$meta['status'];$retryable=(bool)$meta['retryable'];$instruction=capture_instruction((string)$meta['instruction']);$elapsed=(int)round(microtime(true)*1000)-$startedMs;
    $payload=['ok'=>false,'request_id'=>$requestId,'capture_session_id'=>$session?(string)$session['public_id']:'','capture_mode'=>$mode,'region_index'=>$regionIndex?:null,'expected_region'=>$expectedRegion,'error_code'=>$code,'retryable'=>$retryable,'instruction'=>$instruction,'server_elapsed_ms'=>$elapsed];$json=capture_encode($payload);
    $pdo->prepare("UPDATE capture_candidates SET validation_status='failed',error_code=?,instruction=?,server_elapsed_ms=?,response_json=?,response_http_status=?,validated_at=NOW() WHERE id=?")->execute([$code,$instruction,$elapsed,$json,$status,$candidateId]);
    $providerRequest=$error instanceof AiDentistProviderException?$error->requestId:'';capture_trace('response_sent',$trace,['http_status'=>$status,'error_code'=>$code,'retryable'=>$retryable,'upstream_status'=>(int)$meta['provider_status'],'curl_errno'=>(int)$meta['curl_errno'],'exception_class'=>get_class($error),'provider_request_id'=>capture_utf8_cut($providerRequest,CAPTURE_MAX_ID_BYTES),'detail'=>capture_utf8_cut($error->getMessage(),180)]);
    if($retryable&&in_array($status,[429,503,504],true))header('Retry-After: 2');capture_response($payload,$status);
  }
} catch(PDOException $error){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if(isset($upload['path']))@unlink($upload['path']);if(isset($canonical['path']))@unlink($canonical['path']);error_log('capture_validate database: '.$error->getMessage());capture_error('internal_error','云端检查服务暂不可用',500,true,$requestId??'');
} catch(Throwable $error){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();if(isset($upload['path']))@unlink($upload['path']);if(isset($canonical['path']))@unlink($canonical['path']);error_log('capture_validate: '.$error->getMessage());capture_error('internal_error','云端检查服务暂不可用',500,true,$requestId??'');
}
