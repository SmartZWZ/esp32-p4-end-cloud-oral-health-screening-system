<?php
declare(strict_types=1);
// Seven-view session protocol V1: 20260813-hardware-v1-sync-v6.
define('CAPTURE_LOG_SESSION_RESPONSES',true);
require_once __DIR__ . '/capture_protocol_common.php';

function capture_session_payload(array $session,array $member): array {
  $current=(int)$session['current_region_index'];$regions=capture_regions();
  return [
    'ok'=>true,'capture_session_id'=>(string)$session['public_id'],'capture_mode'=>(string)$session['capture_mode'],
    'member_id'=>(string)$member['public_id'],'session_status'=>(string)$session['status'],
    'current_region_index'=>$session['status']==='collecting'?$current:null,
    'expected_region'=>$session['status']==='collecting'?($regions[$current]??null):null,
    'completed_count'=>(int)$session['completed_count'],'completed_mask'=>(int)$session['completed_mask'],
  ];
}

try {
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')capture_error('invalid_request_id','仅支持 POST 请求',405,false);
  if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')capture_error('invalid_request_id','控制接口需要 JSON',415,false);
  $pdo=db();$device=capture_require_device($pdo);$data=capture_request_json();$action=(string)($data['action']??'');$GLOBALS['capture_session_action']=$action;
  if((int)($data['protocol_version']??0)!==CAPTURE_PROTOCOL_VERSION)capture_error('invalid_request_id','协议版本不支持',422,false);

  if($action==='start'){
    if((string)($data['capture_mode']??'')!=='seven_view')capture_error('invalid_request_id','会话模式必须为 seven_view',422,false);
    $nonce=trim((string)($data['client_session_nonce']??''));if(!capture_valid_id($nonce,1,64))capture_error('invalid_request_id','会话 nonce 无效',422,false);
    $member=capture_require_member($pdo,$device,trim((string)($data['member_id']??'')));
    capture_cleanup_expired_candidates($pdo);
    $pdo->beginTransaction();
    $deviceLock=$pdo->prepare('SELECT id FROM devices WHERE id=? LIMIT 1 FOR UPDATE');$deviceLock->execute([(int)$device['id']]);$deviceLock->fetchColumn();
    // Expire abandoned sessions before nonce/idempotency and recovery checks.
    $pdo->prepare("UPDATE capture_sessions SET status='expired',expired_at=NOW() WHERE device_id=? AND status='collecting' AND updated_at<DATE_SUB(NOW(),INTERVAL 2 HOUR)")->execute([(int)$device['id']]);
    $existing=$pdo->prepare('SELECT * FROM capture_sessions WHERE device_id=? AND client_session_nonce=? LIMIT 1 FOR UPDATE');$existing->execute([(int)$device['id'],$nonce]);$session=$existing->fetch();
    if($session){
      if((int)$session['member_id']!==(int)$member['id']){$pdo->rollBack();capture_error('invalid_request_id','nonce 已用于其他成员',409,false);}
      if((string)$session['status']==='collecting')$pdo->prepare('UPDATE capture_sessions SET updated_at=NOW() WHERE id=?')->execute([(int)$session['id']]);
      $pdo->commit();capture_response(capture_session_payload($session,$member));
    }
    $busy=$pdo->prepare("SELECT * FROM capture_sessions WHERE device_id=? AND status='collecting' ORDER BY updated_at DESC LIMIT 1 FOR UPDATE");$busy->execute([(int)$device['id']]);$active=$busy->fetch();
    if($active){
      if((int)$active['member_id']===(int)$member['id']){
        // A device reboot may generate a new nonce. Resume the same member's active
        // session instead of forcing the ESP32-P4 to remember/cancel the old ID.
        $pdo->prepare('UPDATE capture_sessions SET updated_at=NOW() WHERE id=?')->execute([(int)$active['id']]);
        error_log('capture session resumed: device_id='.(int)$device['id'].' session_id='.(int)$active['id']);
        $pdo->commit();capture_response(capture_session_payload($active,$member));
      }
      if((int)$active['completed_count']===0&&(int)$active['completed_mask']===0){
        // A new member selection may replace an abandoned session only when no
        // image has been confirmed, so recovery never discards accepted records.
        $pdo->prepare("UPDATE capture_sessions SET status='cancelled',cancelled_at=NOW() WHERE id=? AND status='collecting'")->execute([(int)$active['id']]);
        error_log('empty capture session superseded: device_id='.(int)$device['id'].' session_id='.(int)$active['id']);
      }else{
        $pdo->rollBack();capture_error('request_in_progress','设备有其他成员的未完成采集，请先取消',409,false);
      }
    }
    $referenceId=$pdo->query('SELECT active_version_id FROM capture_reference_settings WHERE id=1')->fetchColumn()?:null;$publicId=capture_new_id('cs');
    $stmt=$pdo->prepare("INSERT INTO capture_sessions(public_id,protocol_version,device_id,user_id,member_id,client_session_nonce,capture_mode,status,current_region_index,completed_count,completed_mask,reference_version_id) VALUES(?,1,?,?,?,?, 'seven_view','collecting',1,0,0,?)");
    $stmt->execute([$publicId,(int)$device['id'],(int)$device['user_id'],(int)$member['id'],$nonce,$referenceId]);$id=(int)$pdo->lastInsertId();$pdo->commit();
    $stmt=$pdo->prepare('SELECT * FROM capture_sessions WHERE id=?');$stmt->execute([$id]);capture_response(capture_session_payload($stmt->fetch(),$member),201);
  }

  if($action==='cancel'){
    $pdo->beginTransaction();
    $session=capture_require_session($pdo,$device,trim((string)($data['capture_session_id']??'')),true);
    $memberStmt=$pdo->prepare('SELECT public_id FROM family_members WHERE id=?');$memberStmt->execute([(int)$session['member_id']]);$member=['public_id'=>(string)$memberStmt->fetchColumn()];
    if(!in_array((string)$session['status'],['completed','cancelled','expired'],true)){$pdo->prepare("UPDATE capture_sessions SET status='cancelled',cancelled_at=NOW() WHERE id=?")->execute([(int)$session['id']]);$session['status']='cancelled';}
    $pdo->commit();
    capture_response(capture_session_payload($session,$member));
  }

  if($action==='status'){
    $session=capture_require_session($pdo,$device,trim((string)($data['capture_session_id']??'')));
    $memberStmt=$pdo->prepare('SELECT public_id FROM family_members WHERE id=?');$memberStmt->execute([(int)$session['member_id']]);capture_response(capture_session_payload($session,['public_id'=>(string)$memberStmt->fetchColumn()]));
  }
  capture_error('invalid_request_id','未知会话操作',404,false);
} catch(PDOException $error){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('capture_sessions database: '.$error->getMessage());capture_error('internal_error','会话服务暂不可用',500,true);
} catch(Throwable $error){
  if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();error_log('capture_sessions: '.$error->getMessage());capture_error('internal_error','会话服务暂不可用',500,true);
}
