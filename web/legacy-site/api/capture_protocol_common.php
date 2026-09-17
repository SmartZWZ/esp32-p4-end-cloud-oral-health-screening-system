<?php
declare(strict_types=1);

// Seven-view protocol V1 alignment: 20260821-lateral-side-v8.

require_once __DIR__ . '/reference_library_common.php';
require_once __DIR__ . '/ai_dentist_common.php';

const CAPTURE_PROTOCOL_VERSION = 1;
const CAPTURE_DEPLOYMENT_VERSION = '20260821-seven-view-side-v4';
const CAPTURE_RESPONSE_TARGET_BYTES = 1536;
const CAPTURE_RESPONSE_HARD_LIMIT_BYTES = 4096;
const CAPTURE_MAX_INSTRUCTION_BYTES = 96;
const CAPTURE_MAX_ID_BYTES = 64;
const CAPTURE_MAX_REASON_CODE_BYTES = 32;
const CAPTURE_MAX_REGION_CODE_BYTES = 32;
const CAPTURE_MAX_JPEG_BYTES = 1048576;
const CAPTURE_SERVER_VALIDATE_DEADLINE_MS = 12000;
const CAPTURE_MODEL_TIMEOUT_SECONDS = 9;
const CAPTURE_MODEL_IMAGE_MAX_EDGE = 960;
const CAPTURE_MODEL_IMAGE_QUALITY = 82;

function capture_boot(): void {
  @ini_set('display_errors','0');
  @ini_set('html_errors','0');
  @ini_set('zlib.output_compression','0');
  @ignore_user_abort(true);
  while(ob_get_level()>0) @ob_end_clean();
}

function capture_encode(array $payload): string {
  $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($json)) return '{"ok":false,"protocol_version":1,"error_code":"internal_error","retryable":false,"instruction":"云端响应编码失败"}';
  return $json;
}

function capture_v1_error_code(string $code): string {
  $fixed=['empty_image','invalid_request_id','device_unauthorized','request_in_progress','region_already_confirmed','image_too_large','jpeg_required','invalid_region','member_invalid','session_mismatch','rate_limited','model_timeout','model_unavailable','invalid_model_output','response_too_large','internal_error'];
  if(in_array($code,$fixed,true))return $code;
  $legacy=[
    'invalid_request'=>'invalid_request_id','unauthorized'=>'device_unauthorized','member_not_found'=>'member_invalid',
    'session_not_found'=>'session_mismatch','session_expired'=>'session_mismatch','region_conflict'=>'session_mismatch',
    'jpeg_too_large'=>'image_too_large','invalid_jpeg'=>'jpeg_required','request_conflict'=>'invalid_request_id',
    'server_busy'=>'request_in_progress','reference_unavailable'=>'model_unavailable',
  ];
  $normalized=$legacy[$code]??'internal_error';
  error_log('capture protocol normalized legacy error_code='.$code.' to='.$normalized);
  return $normalized;
}

function capture_log_short_id(string $value): string {
  return strlen($value)<=12?$value:substr($value,0,6).'...'.substr($value,-4);
}

function capture_trace(string $stage,array $context=[],array $details=[]): void {
  $safe=[
    'logged_at'=>(new DateTimeImmutable('now'))->format('Y-m-d H:i:s.vP'),
    'stage'=>$stage,
    // request_id 和 capture_session_id 不是凭据。独立日志保留完整值，便于端云逐请求核对。
    'request_id'=>capture_utf8_cut((string)($context['request_id']??''),CAPTURE_MAX_ID_BYTES),
    'capture_session_id'=>capture_utf8_cut((string)($context['capture_session_id']??''),CAPTURE_MAX_ID_BYTES),
    'region_index'=>(int)($context['region_index']??0),
    'expected_region'=>(string)($context['expected_region']??''),
    'elapsed_ms'=>isset($context['started_ms'])?max(0,(int)round(microtime(true)*1000)-(int)$context['started_ms']):0,
    'deployment'=>CAPTURE_DEPLOYMENT_VERSION,
    'worker_id'=>getmypid(),
  ];
  foreach($details as $key=>$value){
    if(in_array($key,['request_id','capture_session_id','member_id','device_token','api_key'],true))continue;
    if(is_string($value))$safe[$key]=capture_utf8_cut($value,240);elseif(is_scalar($value)||$value===null)$safe[$key]=$value;
  }
  // 不再把大量阶段日志写入 FastCGI stderr。Nginx 会把同一请求的多条 stderr
  // 合并成一条超长记录并截断，造成“只有 model_request_started”的假象。
  $directory=rtrim(storage_root(),'/\\').DIRECTORY_SEPARATOR.'logs';
  $path=$directory.DIRECTORY_SEPARATOR.'capture_validate.log';
  if(!is_dir($directory)&&!@mkdir($directory,0750,true)&&!is_dir($directory)){
    error_log('capture_validate trace_log_unavailable stage='.$stage.' reason=mkdir_failed');
    return;
  }
  $line=capture_encode($safe).PHP_EOL;
  $written=@file_put_contents($path,$line,FILE_APPEND|LOCK_EX);
  if($written===false)error_log('capture_validate trace_log_unavailable stage='.$stage.' reason=write_failed');
}

function capture_log_payload(array $payload): array {
  $safe=$payload;
  if(isset($safe['member_id']))$safe['member_id']='[redacted]';
  foreach(['capture_session_id','temporary_image_id','detection_id'] as $field)if(isset($safe[$field]))$safe[$field]=capture_log_short_id((string)$safe[$field]);
  return $safe;
}

function capture_response(array $payload,int $status=200): void {
  $payload['ok']=(bool)($payload['ok']??false);
  if(!$payload['ok']&&isset($payload['error_code']))$payload['error_code']=capture_v1_error_code((string)$payload['error_code']);
  $payload['protocol_version']=CAPTURE_PROTOCOL_VERSION;
  $json=capture_encode($payload);
  if(strlen($json)>CAPTURE_RESPONSE_HARD_LIMIT_BYTES){
    $status=500;$json='{"ok":false,"protocol_version":1,"error_code":"response_too_large","retryable":false,"instruction":"云端响应超出安全上限"}';
  }
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Content-Encoding: identity');
  header('Content-Length: '.strlen($json));
  header('Cache-Control: no-store');
  if(!empty($payload['request_id']))header('X-Request-Id: '.capture_utf8_cut((string)$payload['request_id'],CAPTURE_MAX_ID_BYTES));
  if(defined('CAPTURE_LOG_SESSION_RESPONSES')&&CAPTURE_LOG_SESSION_RESPONSES){
    $action=trim((string)($GLOBALS['capture_session_action']??''));
    error_log('capture_session response action='.($action!==''?$action:'unknown').' http_status='.$status.' bytes='.strlen($json).' body_redacted='.capture_encode(capture_log_payload($payload)));
  }
  if(defined('CAPTURE_LOG_VALIDATE_RESPONSES')&&CAPTURE_LOG_VALIDATE_RESPONSES){
    error_log('capture_validate wire http_status='.$status.' bytes='.strlen($json).' body_redacted='.capture_encode(capture_log_payload($payload)));
  }
  echo $json;exit;
}

function capture_utf8_cut(string $text,int $maxBytes): string {
  if(strlen($text)<=$maxBytes)return $text;
  if(function_exists('mb_strcut'))return mb_strcut($text,0,$maxBytes,'UTF-8');
  $cut=substr($text,0,$maxBytes);
  while($cut!==''&&preg_match('//u',$cut)!==1)$cut=substr($cut,0,-1);
  return $cut;
}

function capture_instruction(string $text): string {
  $text=trim(preg_replace('/\s+/u',' ',$text)??$text);
  return capture_utf8_cut($text,CAPTURE_MAX_INSTRUCTION_BYTES);
}

function capture_error(string $code,string $instruction,int $status=400,bool $retryable=false,string $requestId=''): void {
  $code=capture_v1_error_code($code);
  $payload=['ok'=>false,'error_code'=>$code,'retryable'=>$retryable,'instruction'=>capture_instruction($instruction)];
  if($requestId!=='')$payload['request_id']=capture_utf8_cut($requestId,CAPTURE_MAX_ID_BYTES);
  capture_response($payload,$status);
}

function capture_header(string $name): string { return trim((string)(header_value($name)??'')); }

function capture_request_json(): array {
  $length=(int)($_SERVER['CONTENT_LENGTH']??0);
  if($length>CAPTURE_RESPONSE_HARD_LIMIT_BYTES)capture_error('invalid_request_id','控制请求过大',413,false);
  $raw=(string)file_get_contents('php://input',false,null,0,CAPTURE_RESPONSE_HARD_LIMIT_BYTES+1);
  if(strlen($raw)>CAPTURE_RESPONSE_HARD_LIMIT_BYTES)capture_error('invalid_request_id','控制请求过大',413,false);
  $data=json_decode($raw,true);
  if(!is_array($data))capture_error('invalid_request_id','请求 JSON 无效',400,false);
  return $data;
}

function capture_valid_id(string $value,int $min=1,int $max=64): bool {
  $length=strlen($value);
  return $length>=$min&&$length<=$max&&preg_match('/^[A-Za-z0-9_-]+$/D',$value)===1;
}

function capture_new_id(string $prefix): string { return $prefix.'_'.date('YmdHis').'_'.bin2hex(random_bytes(5)); }

function capture_regions(): array {
  return [
    1=>'front_bite',2=>'left_bite',3=>'right_bite',4=>'upper_left_open',
    5=>'upper_right_open',6=>'lower_left_open',7=>'lower_right_open',
  ];
}

function capture_region_index(string $region): int {
  $index=array_search($region,capture_regions(),true);return $index===false?0:(int)$index;
}

function capture_require_device(PDO $pdo): array {
  $token=capture_header('X-Device-Token');$authorization=capture_header('Authorization');
  if(preg_match('/^Bearer\s+(.+)$/i',$authorization,$match))$token=trim($match[1]);
  if($token===''||strlen($token)>256)capture_error('device_unauthorized','设备令牌无效',401,false);
  $stmt=$pdo->prepare('SELECT id,public_id,user_id,device_uid,display_name FROM devices WHERE token_hash=? LIMIT 1');
  $stmt->execute([hash('sha256',$token)]);$device=$stmt->fetch();
  if(!$device)capture_error('device_unauthorized','设备未绑定或令牌无效',401,false);
  $pdo->prepare('UPDATE devices SET last_seen_at=NOW() WHERE id=?')->execute([(int)$device['id']]);
  return $device;
}

function capture_require_member(PDO $pdo,array $device,string $publicId): array {
  if(!capture_valid_id($publicId,12,32))capture_error('member_invalid','家庭成员无效',404,false);
  $stmt=$pdo->prepare("SELECT id,public_id,name FROM family_members WHERE public_id=? AND user_id=? AND status='active' LIMIT 1");
  $stmt->execute([$publicId,(int)$device['user_id']]);$member=$stmt->fetch();
  if(!$member)capture_error('member_invalid','家庭成员不存在',404,false);
  return $member;
}

function capture_require_session(PDO $pdo,array $device,string $publicId,bool $lock=false): array {
  if(!capture_valid_id($publicId,12,32))capture_error('session_mismatch','采集会话不存在',404,false);
  $stmt=$pdo->prepare('SELECT * FROM capture_sessions WHERE public_id=? AND device_id=? LIMIT 1'.($lock?' FOR UPDATE':''));
  $stmt->execute([$publicId,(int)$device['id']]);$session=$stmt->fetch();
  if(!$session)capture_error('session_mismatch','采集会话不存在',404,false);
  return $session;
}

function capture_candidate_dir(): string { return storage_root().'/capture_candidates'; }
function capture_ensure_storage(): void {
  ensure_storage();$dir=capture_candidate_dir();
  if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('candidate storage unavailable');
}

function capture_relative_path(string $path): string {
  $root=rtrim(str_replace('\\','/',storage_root()),'/').'/';$normalized=str_replace('\\','/',$path);
  return str_starts_with($normalized,$root)?substr($normalized,strlen($root)):'';
}

function capture_receive_jpeg(string $requestId): array {
  $contentEncoding=strtolower(trim((string)($_SERVER['HTTP_CONTENT_ENCODING']??'')));
  if($contentEncoding!==''&&$contentEncoding!=='identity')capture_error('jpeg_required','JPEG 请求不得压缩',415,false,$requestId);
  if(trim((string)($_SERVER['HTTP_TRANSFER_ENCODING']??''))!=='')capture_error('jpeg_required','JPEG 请求需要固定长度',411,false,$requestId);
  $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
  if($contentType!=='image/jpeg')capture_error('jpeg_required','仅支持 JPEG 图片',415,false,$requestId);
  $lengthHeader=(string)($_SERVER['CONTENT_LENGTH']??'');
  if($lengthHeader===''||!ctype_digit($lengthHeader))capture_error('empty_image','缺少有效图片长度',411,false,$requestId);
  $expected=(int)$lengthHeader;
  if($expected<4)capture_error('empty_image','JPEG 图片为空',400,false,$requestId);
  if($expected>CAPTURE_MAX_JPEG_BYTES)capture_error('image_too_large','图片超过 1 MiB',413,false,$requestId);
  capture_ensure_storage();$rawPath=capture_candidate_dir().'/'.$requestId.'_'.bin2hex(random_bytes(4)).'_raw.jpg';$output=@fopen($rawPath.'.tmp','wb');$input=@fopen('php://input','rb');
  if(!$output||!$input){if(is_resource($output))fclose($output);if(is_resource($input))fclose($input);capture_error('internal_error','图片接收失败',500,true,$requestId);}
  $hash=hash_init('sha256');$total=0;
  while(!feof($input)){$chunk=fread($input,16384);if($chunk===false)break;$size=strlen($chunk);$total+=$size;if($total>CAPTURE_MAX_JPEG_BYTES){fclose($input);fclose($output);@unlink($rawPath.'.tmp');capture_error('image_too_large','图片超过 1 MiB',413,false,$requestId);}hash_update($hash,$chunk);if($size&&fwrite($output,$chunk)!==$size){fclose($input);fclose($output);@unlink($rawPath.'.tmp');capture_error('internal_error','图片写入失败',500,true,$requestId);}}
  fclose($input);fclose($output);
  if($total!==$expected){@unlink($rawPath.'.tmp');capture_error('jpeg_required','图片长度不一致',400,true,$requestId);}
  if(!@rename($rawPath.'.tmp',$rawPath)){@unlink($rawPath.'.tmp');capture_error('internal_error','图片保存失败',500,true,$requestId);}
  $info=@getimagesize($rawPath);
  if(!is_array($info)||(string)($info['mime']??'')!=='image/jpeg'){@unlink($rawPath);capture_error('jpeg_required','JPEG 图片无法解码',415,false,$requestId);}
  return ['path'=>$rawPath,'bytes'=>$total,'sha256'=>hash_final($hash),'width'=>(int)$info[0],'height'=>(int)$info[1]];
}

function capture_flip_to_canonical(string $rawPath,string $requestId): array {
  if(!function_exists('imagecreatefromjpeg')||!function_exists('imageflip'))throw new RuntimeException('PHP GD extension required');
  $image=@imagecreatefromjpeg($rawPath);if(!$image)throw new RuntimeException('JPEG decode failed');
  if(!imageflip($image,IMG_FLIP_HORIZONTAL)){imagedestroy($image);throw new RuntimeException('horizontal flip failed');}
  $canonicalPath=capture_candidate_dir().'/'.$requestId.'_'.bin2hex(random_bytes(4)).'_canonical.jpg';$temporary=$canonicalPath.'.tmp';
  $saved=@imagejpeg($image,$temporary,92);$width=imagesx($image);$height=imagesy($image);imagedestroy($image);
  if(!$saved||!@rename($temporary,$canonicalPath)){@unlink($temporary);throw new RuntimeException('canonical JPEG save failed');}
  return ['path'=>$canonicalPath,'relative'=>capture_relative_path($canonicalPath),'width'=>$width,'height'=>$height,'bytes'=>(int)filesize($canonicalPath)];
}

function capture_model_jpeg(string $path): string {
  if(!is_file($path))throw new RuntimeException('reference_unavailable:file_missing');
  if(!function_exists('imagecreatefromjpeg')||!function_exists('imagecopyresampled')){
    $bytes=(string)file_get_contents($path);
    if($bytes==='')throw new RuntimeException('reference_unavailable:file_unreadable');
    return $bytes;
  }
  $source=@imagecreatefromjpeg($path);
  if(!$source)throw new RuntimeException('reference_unavailable:image_decode_failed');
  $width=imagesx($source);$height=imagesy($source);$long=max($width,$height);
  if($long<=CAPTURE_MODEL_IMAGE_MAX_EDGE){imagedestroy($source);$bytes=(string)file_get_contents($path);if($bytes==='')throw new RuntimeException('reference_unavailable:file_unreadable');return $bytes;}
  $scale=CAPTURE_MODEL_IMAGE_MAX_EDGE/$long;$targetWidth=max(1,(int)round($width*$scale));$targetHeight=max(1,(int)round($height*$scale));
  $target=imagecreatetruecolor($targetWidth,$targetHeight);
  if(!$target){imagedestroy($source);throw new RuntimeException('model_unavailable:image_resize_memory');}
  if(!imagecopyresampled($target,$source,0,0,0,0,$targetWidth,$targetHeight,$width,$height)){imagedestroy($source);imagedestroy($target);throw new RuntimeException('model_unavailable:image_resize_failed');}
  imagedestroy($source);ob_start();$saved=imagejpeg($target,null,CAPTURE_MODEL_IMAGE_QUALITY);$bytes=(string)ob_get_clean();imagedestroy($target);
  if(!$saved||$bytes==='')throw new RuntimeException('model_unavailable:image_encode_failed');
  return $bytes;
}

function capture_model_data_url(string $path): array {
  $bytes=capture_model_jpeg($path);
  return ['url'=>'data:image/jpeg;base64,'.base64_encode($bytes),'bytes'=>strlen($bytes)];
}

function capture_parse_model_json(string $content): array {
  $clean=trim(preg_replace('/^```(?:json)?\s*|\s*```$/iu','',trim($content))??$content);
  $decoded=json_decode($clean,true);
  if(!is_array($decoded)){$start=strpos($clean,'{');$end=strrpos($clean,'}');if($start!==false&&$end!==false&&$end>$start)$decoded=json_decode(substr($clean,$start,$end-$start+1),true);}
  if(!is_array($decoded))throw new RuntimeException('invalid model output');return $decoded;
}

function capture_reason_instruction(string $reason,string $expectedRegion): string {
  $regionLabels=['front_bite'=>'正面咬合位','left_bite'=>'本人左侧咬合位','right_bite'=>'本人右侧咬合位','upper_left_open'=>'左上牙区','upper_right_open'=>'右上牙区','lower_left_open'=>'左下牙区','lower_right_open'=>'右下牙区'];
  return capture_instruction([
    'ok'=>'当前区域拍摄合格','wrong_region'=>'请拍'.$regionLabels[$expectedRegion],
    'region_uncertain'=>'未识别到目标区域，请重新对准','target_too_small'=>'请靠近一点',
    'target_too_close'=>'请稍微远离','target_cropped'=>'请远离并拍全牙区','off_center'=>'请将牙区移到画面中央',
    'blurred'=>'请保持摄像头稳定','too_dark'=>'画面较暗，请调整补光','overexposed'=>'画面过亮，请稍微移开补光',
    'strong_reflection'=>'反光较强，请调整角度','occluded'=>'牙区被遮挡，请重新对准','not_oral_image'=>'未检测到口腔画面',
    'multiple_problems'=>'请重新对准并保持稳定',
  ][$reason]??'请重新对准当前区域');
}

function capture_model_error_meta(Throwable $error): array {
  $message=strtolower($error->getMessage());
  $status=$error instanceof AiDentistProviderException?$error->httpStatus:0;
  $curlErrno=$error instanceof AiDentistProviderException?$error->curlErrno:0;
  $timeout=in_array($curlErrno,[28,42],true)||in_array($status,[408,504],true)||str_contains($message,'timeout')||str_contains($message,'timed out')||str_contains($message,'超时');
  $reference=str_contains($message,'reference_unavailable');
  $invalidOutput=str_contains($message,'invalid model output')||str_contains($message,'没有返回内容')||str_contains($message,'结构化');
  $tooLarge=str_contains($message,'response_too_large')||str_contains($message,'response too large');
  $network=$curlErrno!==0&&in_array($curlErrno,[5,6,7,18,35,52,55,56,60,77,92],true);
  if($timeout)return ['code'=>'model_timeout','status'=>504,'retryable'=>true,'instruction'=>'云端检查超时','provider_status'=>$status,'curl_errno'=>$curlErrno];
  if($status===429)return ['code'=>'rate_limited','status'=>429,'retryable'=>true,'instruction'=>'云端检查繁忙，请稍后重试','provider_status'=>$status,'curl_errno'=>$curlErrno];
  if($reference)return ['code'=>'model_unavailable','status'=>503,'retryable'=>false,'instruction'=>'参考图未启用或不完整','provider_status'=>$status,'curl_errno'=>$curlErrno];
  if($invalidOutput)return ['code'=>'invalid_model_output','status'=>502,'retryable'=>true,'instruction'=>'云端模型结果无效','provider_status'=>$status,'curl_errno'=>$curlErrno];
  if($tooLarge)return ['code'=>'response_too_large','status'=>500,'retryable'=>false,'instruction'=>'云端响应超出安全上限','provider_status'=>$status,'curl_errno'=>$curlErrno];
  if($network||$status>=500)return ['code'=>'model_unavailable','status'=>503,'retryable'=>true,'instruction'=>'云端模型暂不可用','provider_status'=>$status,'curl_errno'=>$curlErrno];
  if($error instanceof AiDentistProviderException||str_contains($message,'model_unavailable:'))return ['code'=>'model_unavailable','status'=>503,'retryable'=>false,'instruction'=>'云端模型配置不可用','provider_status'=>$status,'curl_errno'=>$curlErrno];
  return ['code'=>'internal_error','status'=>500,'retryable'=>true,'instruction'=>'云端检查服务暂不可用','provider_status'=>$status,'curl_errno'=>$curlErrno];
}

function capture_quality_provider_call(array $models,array $messages,array $settings,int $startedMs,array $trace): array {
  $models=array_values(array_unique(array_filter(array_map(static fn($value): string=>trim((string)$value),$models))));
  if(!$models)throw new RuntimeException('model_unavailable:model_not_configured');
  $queue=$models;$lastError=null;$attempt=0;
  while($attempt<2){
    $attempt++;$model=array_shift($queue)??$models[0];
    $remainingMs=CAPTURE_SERVER_VALIDATE_DEADLINE_MS-((int)round(microtime(true)*1000)-$startedMs)-700;
    if($remainingMs<3000)throw new RuntimeException('model_timeout:no_transport_budget');
    $transportMs=min(CAPTURE_MODEL_TIMEOUT_SECONDS*1000,max(2500,$remainingMs));
    $callSettings=$settings;
    $callSettings['_transport_timeout_ms']=$transportMs;
    $callSettings['_connect_timeout_ms']=min(3000,max(1000,$transportMs-500));
    $deadlineDriver=defined('CURLOPT_XFERINFOFUNCTION')?'xferinfo_callback':(defined('CURLOPT_PROGRESSFUNCTION')?'progress_callback':'curl_timeout_ms');
    capture_trace('model_request_started',$trace,['attempt'=>$attempt,'model'=>$model,'remaining_ms'=>$remainingMs,'deadline_driver'=>$deadlineDriver]);
    $attemptStartedMs=(int)round(microtime(true)*1000);
    try{
      $provider=ai_dentist_provider_call($model,$messages,$callSettings);
      capture_trace('model_request_finished',$trace,['attempt'=>$attempt,'model'=>(string)$provider['model'],'outcome'=>'success','upstream_status'=>(int)$provider['http_status'],'curl_errno'=>0,'timeout_stage'=>'','provider_ms'=>(int)$provider['latency_ms']]);
      return $provider;
    }catch(Throwable $error){
      $lastError=$error;$meta=capture_model_error_meta($error);
      $providerMs=max(0,(int)round(microtime(true)*1000)-$attemptStartedMs);
      $timeoutStage=(string)$meta['code']==='model_timeout'
        ? (((int)$meta['curl_errno']===42)?'hard_deadline_callback':'transport_timeout')
        : '';
      capture_trace('model_request_finished',$trace,['attempt'=>$attempt,'model'=>$model,'outcome'=>'error','error_code'=>(string)$meta['code'],'upstream_status'=>(int)$meta['provider_status'],'curl_errno'=>(int)$meta['curl_errno'],'timeout_stage'=>$timeoutStage,'provider_ms'=>$providerMs,'exception_class'=>get_class($error),'detail'=>capture_utf8_cut($error->getMessage(),180)]);
      $configurationFallback=$error instanceof AiDentistProviderException&&in_array($error->httpStatus,[400,403,404],true);
      $remainingAfter=CAPTURE_SERVER_VALIDATE_DEADLINE_MS-((int)round(microtime(true)*1000)-$startedMs)-700;
      if($attempt>=2||(!$meta['retryable']&&!$configurationFallback)||$remainingAfter<3000)throw $error;
      if(!$queue)$queue[]=$model;
      usleep(150000);
    }
  }
  throw $lastError??new RuntimeException('model_unavailable:provider_failed');
}

function capture_quality_result(PDO $pdo,string $expectedRegion,string $candidatePath,int $startedMs,?int $referenceVersionId=null,array $trace=[]): array {
  $settings=$pdo->query('SELECT active_version_id,validation_enabled,quality_model,quality_confidence_threshold FROM capture_reference_settings WHERE id=1 LIMIT 1')->fetch();
  if(!$settings)throw new RuntimeException('reference_unavailable:settings_missing');
  if(!(bool)$settings['validation_enabled'])throw new RuntimeException('reference_unavailable:validation_disabled');
  if((int)($referenceVersionId??0)<=0&&empty($settings['active_version_id']))throw new RuntimeException('reference_unavailable:active_version_missing');
  $versionId=$referenceVersionId&&$referenceVersionId>0?$referenceVersionId:(int)$settings['active_version_id'];
  $stmt=$pdo->prepare("SELECT v.version_code,i.distance_label,i.image_path FROM capture_reference_versions v INNER JOIN capture_reference_images i ON i.version_id=v.id WHERE v.id=? AND v.status='published' AND i.region_id=? ORDER BY FIELD(i.distance_label,'too_far','too_close','good')");
  $stmt->execute([$versionId,$expectedRegion]);$refs=$stmt->fetchAll();if(count($refs)!==3)throw new RuntimeException('reference_unavailable:region_refs_incomplete');
  // 左右是七视图判断中最容易混淆的部分。除目标区域三张距离参考图外，
  // 再提供反侧的“合适”图作为硬负样本，避免模型仅靠文字猜测解剖左右。
  $oppositeRegions=[
    'left_bite'=>'right_bite','right_bite'=>'left_bite',
    'upper_left_open'=>'upper_right_open','upper_right_open'=>'upper_left_open',
    'lower_left_open'=>'lower_right_open','lower_right_open'=>'lower_left_open',
  ];
  $oppositeRegion=$oppositeRegions[$expectedRegion]??'';$oppositeRef=null;
  if($oppositeRegion!==''){
    $oppositeStmt=$pdo->prepare("SELECT i.image_path FROM capture_reference_images i INNER JOIN capture_reference_versions v ON v.id=i.version_id WHERE i.version_id=? AND v.status='published' AND i.region_id=? AND i.distance_label='good' LIMIT 1");
    $oppositeStmt->execute([$versionId,$oppositeRegion]);$oppositeRef=$oppositeStmt->fetch()?:null;
  }
  $elapsedBeforePrepare=(int)round(microtime(true)*1000)-$startedMs;if($elapsedBeforePrepare>=CAPTURE_SERVER_VALIDATE_DEADLINE_MS-3000)throw new RuntimeException('model_timeout:prepare_budget_exhausted');
  $ai=ai_dentist_settings($pdo);$models=[(string)($settings['quality_model']??''),(string)($ai['primary_model']??''),(string)($ai['fallback_model']??'')];if(!array_filter(array_map('trim',$models)))throw new RuntimeException('model_unavailable:model_not_configured');
  $ai['temperature']=0.0;$ai['high_resolution_images']=0;$ai['_connect_timeout_seconds']=4;$ai['_max_tokens']=420;
  $regionLabel=(string)(reference_regions()[$expectedRegion]['label']??$expectedRegion);
  $regionGuide=[
    'front_bite'=>'上下牙咬合，正面牙列位于中央，左右前牙及相邻牙面可见。',
    'left_bite'=>'上下牙保持咬合。严格按最终图片的画面坐标判断：图片最左边是牙齿与侧方咬合关系，图片最右边是被拍摄者的人脸嘴角/软组织出口。只要侧方咬合关系具有判断价值即可；不要求固定牙齿颗数，也不要求拍到最后侧牙齿。',
    'right_bite'=>'上下牙保持咬合。严格按最终图片的画面坐标判断：图片最左边是被拍摄者的人脸嘴角/软组织出口，图片最右边是牙齿与侧方咬合关系。只要侧方咬合关系具有判断价值即可；不要求固定牙齿颗数，也不要求拍到最后侧牙齿。',
    'upper_left_open'=>'张口，主体为本人左上牙列咬合面与后牙区。',
    'upper_right_open'=>'张口，主体为本人右上牙列咬合面与后牙区。',
    'lower_left_open'=>'张口，主体为本人左下牙列咬合面与后牙区。',
    'lower_right_open'=>'张口，主体为本人右下牙列咬合面与后牙区。',
  ][$expectedRegion]??$regionLabel;
  $sideCoordinateGuide='图片已是服务器最终保存方向，不是自拍镜像。严禁在判断时再次水平翻转或凭直觉交换左右。画面左/右是观看图片时的左/右；本人左/右是被拍摄者的解剖左/右。';
  if($expectedRegion==='left_bite'||$expectedRegion==='right_bite'){
    $sideCoordinateGuide.='咬合侧位必须按以下固定构图识别，而且该规则优先于解剖直觉和参考图标签：left_bite（第二张、左侧咬合位）=左牙右嘴角，即画面最左边是牙齿/牙列主体、画面最右边是人脸嘴角/软组织出口；right_bite（第三张、右侧咬合位）=左嘴角右牙，即画面最左边是人脸嘴角/软组织出口、画面最右边是牙齿/牙列主体。不得使用相反映射。';
  }else{
    $sideCoordinateGuide.='涉及左/右牙区时，以目标区域合适参考图与反侧合适参考图的整体构图相似性为准，不可只根据单个牙尖或局部亮暗猜测左右。';
  }
  $candidateNumber=$oppositeRef?5:4;
  $isSideBite=in_array($expectedRegion,['left_bite','right_bite'],true);
  if($isSideBite){
    $regionDecisionGuide='第一步只按上述固定画面坐标判左右和区域：检查嘴角/软组织出口位于哪一侧、牙列主体位于哪一侧。不得用可见牙齿颗数、是否拍到最后侧磨牙或解剖直觉反推左右。图3用于支持目标区域，图4仅作反侧对照；如果旧参考图的标签或内容与固定坐标规则冲突，必须以固定坐标规则为准。';
    $coverageGuide='侧位覆盖采用宽容规则：不统计可见牙齿颗数，不要求拍到最后侧牙齿，也不得仅因最后侧磨牙未出现就判 partial 或 target_cropped。只要侧方咬合关系清楚、至少有具有判断价值的犬牙/前磨牙/磨牙颊侧区域，且主体没有被严重裁掉或遮挡，就应判 framing=complete。';
  }else{
    $regionDecisionGuide=$oppositeRef
      ?'第一步只判左右和区域。必须同时比较候选图与图3、图4的整体构图；候选图明显更像图3时，不得仅凭解剖术语把它改判为反侧。'
      :'第一步先判断候选图是否为目标区域；以图3的整体构图为区域正标准，不得根据无关的局部差异改判。';
    $coverageGuide='关键牙区缺失、主体被明显裁切或遮挡时才判 framing=partial。';
  }
  $schema='{"detected_region":"front_bite|left_bite|right_bite|upper_left_open|upper_right_open|lower_left_open|lower_right_open|unknown","composition_match_to_good":false,"distance":"too_far|too_close|good|unknown","sharpness":"good|blurred|unknown","lighting":"good|too_dark|overexposed|unknown","framing":"complete|partial|unknown","reason":"用一句中文说明候选图与合适参考图的主要差异，不超过60字","confidence":0.0}';
  $prompt="判断图{$candidateNumber}能否作为{$regionLabel}（{$expectedRegion}）的规范采集图。目标定义：{$regionGuide}\n图1太远、图2太近、图3是目标区域合适参考图。".($oppositeRef?"图4是反侧区域合适参考图，只是左右识别的硬负样本，绝不能把它当作目标区域正例。":"")."图{$candidateNumber}是候选图。\n左右坐标强制规则：{$sideCoordinateGuide}\n判断顺序：{$regionDecisionGuide}第二步才以图3为最终构图标准比较牙区占比、余量和裁切。{$coverageGuide}忽略牙齿大小、颜色、缺牙和疾病差异。若候选图与图3构图近似且区域正确，composition_match_to_good=true、distance=good、framing=complete；不得额外要求图3没有满足的张嘴角或牵拉口角。清晰度不足为blurred，影响辨认为too_dark或overexposed。不确定用unknown，禁止在左右证据矛盾时以高置信度猜测。reason必须说明画面左、右两侧分别出现了牙列主体还是嘴角，并说明是否使用了侧位覆盖宽容规则；即使合格也要简短说明。只输出JSON：{$schema}";
  $content=[['type'=>'text','text'=>$prompt]];$inputBytes=0;
  $referenceNames=['too_far'=>'图1：太远参考','too_close'=>'图2：太近参考','good'=>'图3：合适参考'];
  foreach($refs as $ref){$path=reference_safe_path((string)$ref['image_path']);if(!$path)throw new RuntimeException('reference_unavailable:file_missing');$prepared=capture_model_data_url($path);$inputBytes+=(int)$prepared['bytes'];$content[]=['type'=>'text','text'=>$referenceNames[(string)$ref['distance_label']]??'参考图'];$content[]=['type'=>'image_url','image_url'=>['url'=>$prepared['url']]];}
  if($oppositeRef){$oppositePath=reference_safe_path((string)$oppositeRef['image_path']);if(!$oppositePath)throw new RuntimeException('reference_unavailable:opposite_file_missing');$oppositePrepared=capture_model_data_url($oppositePath);$inputBytes+=(int)$oppositePrepared['bytes'];$content[]=['type'=>'text','text'=>'图4：'.(string)(reference_regions()[$oppositeRegion]['label']??$oppositeRegion).'合适参考图（反侧硬负样本）'];$content[]=['type'=>'image_url','image_url'=>['url'=>$oppositePrepared['url']]];}
  $candidatePrepared=capture_model_data_url($candidatePath);$inputBytes+=(int)$candidatePrepared['bytes'];$content[]=['type'=>'text','text'=>'图'.$candidateNumber.'：候选图'];$content[]=['type'=>'image_url','image_url'=>['url'=>$candidatePrepared['url']]];
  if($inputBytes>2200000)throw new RuntimeException('model_unavailable:model_input_too_large');
  $provider=capture_quality_provider_call($models,[['role'=>'system','content'=>'你是口腔七视图采集质控器。严格按用户给出的画面坐标映射识别左右，不得自行镜像或交换患者左右；固定画面坐标规则优先于解剖直觉和参考图标签。侧方咬合位不得统计牙齿颗数，也不得因为没拍到最后侧牙齿而拒绝。严格输出单个JSON对象，不确定时使用unknown。'],['role'=>'user','content'=>$content]],$ai,$startedMs,$trace);
  if((int)round(microtime(true)*1000)-$startedMs>CAPTURE_SERVER_VALIDATE_DEADLINE_MS)throw new RuntimeException('model_timeout');
  $raw=capture_parse_model_json((string)$provider['content']);capture_trace('model_response_parsed',$trace,['model'=>(string)$provider['model']]);$regions=array_values(capture_regions());$detected=in_array((string)($raw['detected_region']??''),$regions,true)?(string)$raw['detected_region']:'unknown';$distance=in_array((string)($raw['distance']??''),['too_far','too_close','good','unknown'],true)?(string)$raw['distance']:'unknown';
  $composition=filter_var($raw['composition_match_to_good']??false,FILTER_VALIDATE_BOOLEAN);$regionMatch=$detected===$expectedRegion;if($regionMatch&&$composition)$distance='good';
  $sharpness=in_array((string)($raw['sharpness']??''),['good','blurred','unknown'],true)?(string)$raw['sharpness']:'unknown';$lighting=in_array((string)($raw['lighting']??''),['good','too_dark','overexposed','unknown'],true)?(string)$raw['lighting']:'unknown';$framing=in_array((string)($raw['framing']??''),['complete','partial','unknown'],true)?(string)$raw['framing']:'unknown';if($regionMatch&&$composition)$framing='complete';
  $modelReason=preg_replace('/\s+/u',' ',trim((string)($raw['reason']??'')))??'';$modelReason=mb_substr($modelReason,0,160);
  $quality=['sharpness'=>$sharpness==='good'?'pass':($sharpness==='blurred'?'fail':'unknown'),'exposure'=>$lighting==='good'?'pass':(in_array($lighting,['too_dark','overexposed'],true)?'fail':'unknown'),'coverage'=>$framing==='complete'?'pass':($framing==='partial'?'fail':'unknown'),'positioning'=>($regionMatch&&$distance==='good')?'pass':($regionMatch?'fail':'unknown'),'reflection'=>'pass'];
  $confidence=max(0,min(1,(float)($raw['confidence']??0)));$threshold=max(0.5,min(0.99,(float)($settings['quality_confidence_threshold']??0.8)));$regionMatch=$detected===$expectedRegion;
  $reason='region_uncertain';if(!$regionMatch)$reason=$detected==='unknown'?'region_uncertain':'wrong_region';elseif($distance==='too_far')$reason='target_too_small';elseif($distance==='too_close')$reason='target_too_close';elseif($sharpness==='blurred')$reason='blurred';elseif($lighting==='too_dark')$reason='too_dark';elseif($lighting==='overexposed')$reason='overexposed';elseif($framing==='partial')$reason='target_cropped';elseif($composition&&$confidence>=$threshold)$reason='ok';
  $accepted=$regionMatch&&$distance==='good'&&count(array_filter($quality,static fn($value)=>$value!=='pass'))===0&&$confidence>=$threshold;
  if($accepted)$reason='ok';elseif($reason==='ok')$reason='region_uncertain';
  return ['accepted'=>$accepted,'detected_region'=>$detected,'region_match'=>$regionMatch,'distance'=>$distance,'quality'=>$quality,'confidence'=>round($confidence,4),'confidence_threshold'=>round($threshold,4),'reason_code'=>$reason,'instruction'=>capture_reason_instruction($reason,$expectedRegion),'model_reason'=>$modelReason,'model_details'=>['detected_region'=>$detected,'composition_match_to_good'=>$composition,'distance'=>$distance,'sharpness'=>$sharpness,'lighting'=>$lighting,'framing'=>$framing,'confidence'=>round($confidence,4)],'model'=>(string)$provider['model'],'reference_version'=>(string)$refs[0]['version_code'],'model_latency_ms'=>(int)$provider['latency_ms'],'model_input_bytes'=>$inputBytes];
}

function capture_validation_payload(array $candidate,array $result,int $elapsedMs): array {
  $accepted=(bool)$result['accepted'];$index=(int)$candidate['region_index'];
  return [
    'ok'=>true,'request_id'=>(string)$candidate['request_id'],'capture_session_id'=>(string)($candidate['session_public_id']??''),
    'capture_mode'=>(string)$candidate['capture_mode'],'region_index'=>$index?:null,'expected_region'=>(string)$candidate['expected_region'],
    'detected_region'=>(string)$result['detected_region'],'region_match'=>(bool)$result['region_match'],'accepted'=>$accepted,
    'decision'=>$accepted?($index===7?'complete':'next'):'retake','reason_code'=>(string)$result['reason_code'],'retryable'=>!$accepted,
    'instruction'=>capture_instruction((string)$result['instruction']),'distance'=>(string)$result['distance'],'quality'=>$result['quality'],
    'confidence'=>(float)$result['confidence'],'temporary_image_id'=>(string)$candidate['public_id'],'server_elapsed_ms'=>$elapsedMs,
  ];
}

function capture_unlink_candidate(array $candidate): void {
  foreach(['raw_path','canonical_path'] as $field){$path=reference_safe_path((string)($candidate[$field]??''));if($path)@unlink($path);}
}

function capture_cleanup_expired_candidates(PDO $pdo,int $limit=30): void {
  $limit=max(1,min($limit,100));
  $stmt=$pdo->query("SELECT id,raw_path,canonical_path FROM capture_candidates WHERE lifecycle_status='temporary' AND created_at<DATE_SUB(NOW(),INTERVAL 24 HOUR) ORDER BY id ASC LIMIT ".$limit);
  $items=$stmt->fetchAll();
  if(!$items)return;
  $ids=[];
  foreach($items as $item){$ids[]=(int)$item['id'];capture_unlink_candidate($item);}
  $placeholders=implode(',',array_fill(0,count($ids),'?'));
  $update=$pdo->prepare("UPDATE capture_candidates SET lifecycle_status='expired' WHERE lifecycle_status='temporary' AND id IN ($placeholders)");
  $update->execute($ids);
}

capture_boot();
