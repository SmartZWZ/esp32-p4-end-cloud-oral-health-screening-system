<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function capture_archive_json(?string $value): ?array {
  if (!$value) return null;
  $decoded = json_decode($value, true);
  return is_array($decoded) ? $decoded : null;
}

function capture_archive_risk(string $value): string {
  return in_array($value, ['unknown', 'low', 'medium', 'high'], true) ? $value : 'unknown';
}

function capture_archive_upload_error(int $code): string {
  return match ($code) {
    UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'图片超过服务器允许的上传大小。',
    UPLOAD_ERR_PARTIAL=>'图片只上传了一部分，请重新上传。',
    UPLOAD_ERR_NO_FILE=>'请选择需要补入档案袋的图片。',
    default=>'图片上传失败。',
  };
}

function capture_archive_regions(): array {
  return [
    'front_bite'=>1,'left_bite'=>2,'right_bite'=>3,'upper_left_open'=>4,
    'upper_right_open'=>5,'lower_left_open'=>6,'lower_right_open'=>7,
  ];
}

function capture_archive_uploaded_image(int $userId, int $memberId): array {
  if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['ok'=>false,'error'=>'请选择需要上传的图片。'],422);
  }
  $file=$_FILES['file'];
  $uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
  if($uploadError!==UPLOAD_ERR_OK)json_response(['ok'=>false,'error'=>capture_archive_upload_error($uploadError)],422);
  $tmp=(string)($file['tmp_name']??'');
  if($tmp===''||!is_uploaded_file($tmp))json_response(['ok'=>false,'error'=>'上传文件校验失败。'],400);
  $bytes=(int)($file['size']??0);
  if($bytes<1||$bytes>MAX_UPLOAD_BYTES)json_response(['ok'=>false,'error'=>'图片为空或超过 8 MB。'],$bytes>MAX_UPLOAD_BYTES?413:422);
  $info=function_exists('getimagesize')?@getimagesize($tmp):false;
  $mime=is_array($info)?(string)($info['mime']??''):'';
  $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
  if(!isset($extensions[$mime]))json_response(['ok'=>false,'error'=>'仅支持 JPEG、PNG 或 WebP 图片。'],415);
  $width=(int)($info[0]??0);$height=(int)($info[1]??0);
  if($width<1||$height<1||$width>12000||$height>12000||$width*$height>60000000)json_response(['ok'=>false,'error'=>'图片分辨率无效或过大。'],422);
  ensure_storage();
  $publicId=public_id();$date=date('Ymd');
  $directory=storage_history_dir().'/u'.$userId.'/m'.$memberId.'/'.$date;
  if(!is_dir($directory)&&!@mkdir($directory,0775,true)&&!is_dir($directory))json_response(['ok'=>false,'error'=>'不能创建图片存储目录。'],500);
  $filename='photo_'.$publicId.'.'.$extensions[$mime];$path=$directory.'/'.$filename;$temporary=$path.'.uploading';
  if(!@move_uploaded_file($tmp,$temporary)||!@rename($temporary,$path)){
    @unlink($temporary);
    json_response(['ok'=>false,'error'=>'保存图片失败。'],500);
  }
  @chmod($path,0664);
  return [
    'public_id'=>$publicId,'path'=>$path,
    'relative'=>'history/u'.$userId.'/m'.$memberId.'/'.$date.'/'.$filename,
    'width'=>$width,'height'=>$height,'bytes'=>$bytes,
  ];
}

/** Return the source image and every analysis record derived from it. */
function capture_archive_detection_tree(PDO $pdo, int $rootId): array {
  $pending=[$rootId];$seen=[];$rows=[];
  while($pending){
    $batch=array_values(array_filter(array_map('intval',$pending),static fn(int $id):bool=>$id>0&&!isset($seen[$id])));
    $pending=[];
    if(!$batch)break;
    $marks=implode(',',array_fill(0,count($batch),'?'));
    $stmt=$pdo->prepare("SELECT id,image_path FROM detections WHERE id IN ({$marks})");
    $stmt->execute($batch);
    foreach($stmt->fetchAll() as $row){$id=(int)$row['id'];$seen[$id]=true;$rows[]=$row;}
    $child=$pdo->prepare("SELECT id FROM detections WHERE source_detection_id IN ({$marks})");
    $child->execute($batch);
    $pending=array_map('intval',$child->fetchAll(PDO::FETCH_COLUMN));
  }
  return $rows;
}

function capture_archive_history_paths(PDO $pdo, array $ids): array {
  if(!$ids)return [];
  try{
    $marks=implode(',',array_fill(0,count($ids),'?'));
    $stmt=$pdo->prepare("SELECT old_image_path,new_image_path FROM image_edit_history WHERE detection_id IN ({$marks})");
    $stmt->execute($ids);$paths=[];
    foreach($stmt->fetchAll() as $row){$paths[]=(string)$row['old_image_path'];$paths[]=(string)$row['new_image_path'];}
    return $paths;
  }catch(Throwable $ignored){return [];}
}

function capture_archive_mark_dental_arch_stale(PDO $pdo, int $rootId, int $userId, string $reason): void {
  try{
    $stmt=$pdo->prepare('SELECT DISTINCT job.id FROM dental_arch_jobs job INNER JOIN dental_arch_job_images link ON link.job_id=job.id WHERE link.source_detection_id=? AND job.user_id=?');
    $stmt->execute([$rootId,$userId]);$jobIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    if(!$jobIds)return;
    $marks=implode(',',array_fill(0,count($jobIds),'?'));
    $pdo->prepare("UPDATE dental_arch_versions SET status='stale',is_current=0 WHERE job_id IN ({$marks})")->execute($jobIds);
    $params=array_merge([$reason],$jobIds);
    $pdo->prepare("UPDATE dental_arch_jobs SET status='stale',progress_label='来源照片已变化，请基于当前七图重新生成。',error_message=? WHERE id IN ({$marks})")->execute($params);
  }catch(Throwable $ignored){
    // 未部署牙列迁移时，不阻断档案内照片管理。
  }
}

function capture_archive_delete_detection_tree(PDO $pdo, int $rootId): array {
  $rows=capture_archive_detection_tree($pdo,$rootId);
  $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);
  $paths=array_map(static fn(array $row):string=>(string)$row['image_path'],$rows);
  $paths=array_merge($paths,capture_archive_history_paths($pdo,$ids));
  if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM detections WHERE id IN ({$marks})")->execute($ids);}
  return $paths;
}

function capture_archive_recalculate(PDO $pdo, int $sessionId): array {
  $stmt=$pdo->prepare('SELECT capture_region_index FROM detections WHERE capture_session_id=? AND source_detection_id IS NULL AND capture_region_index BETWEEN 1 AND 7 ORDER BY capture_region_index');
  $stmt->execute([$sessionId]);$mask=0;$count=0;
  foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $index){$mask|=1<<((int)$index-1);$count++;}
  $complete=$count===7&&$mask===127;$next=7;
  if(!$complete)for($index=1;$index<=7;$index++)if(($mask&(1<<($index-1)))===0){$next=$index;break;}
  $pdo->prepare('UPDATE capture_sessions SET status=?,completed_count=?,completed_mask=?,current_region_index=?,completed_at=? WHERE id=?')
      ->execute([$complete?'completed':'incomplete',$count,$mask,$next,$complete?date('Y-m-d H:i:s'):null,$sessionId]);
  return ['count'=>$count,'mask'=>$mask,'complete'=>$complete,'next'=>$next];
}

function capture_archive_cleanup_files(PDO $pdo, array $storedPaths): int {
  $deleted=0;
  foreach(array_unique(array_filter(array_map('strval',$storedPaths))) as $storedPath){
    try{
      $stmt=$pdo->prepare('SELECT COUNT(*) FROM detections WHERE image_path=?');$stmt->execute([$storedPath]);
      if((int)$stmt->fetchColumn()>0)continue;
      $path=image_file_path($storedPath);
      if($path&&@unlink($path))$deleted++;
    }catch(Throwable $error){error_log('capture archive file cleanup failed: '.$error->getMessage());}
  }
  return $deleted;
}

$user = require_user();
$pdo = db();
$userId = (int)$user['id'];
$action=(string)($_GET['action']??'');
$archivePublicId = mb_substr(trim((string)($_GET['id'] ?? $_POST['capture_archive_id'] ?? '')), 0, 32);
if ($archivePublicId === '') json_response(['ok' => false, 'error' => '缺少全口采集档案编号。'], 422);

$sessionStmt = $pdo->prepare(
  "SELECT cs.id,cs.public_id,cs.member_id,cs.status,cs.completed_count,cs.completed_mask,
          cs.current_region_index,cs.created_at,cs.updated_at,cs.completed_at,
          device.display_name AS device_name,device.public_id AS device_public_id,
          reference.version_code AS reference_version,
          member.public_id AS member_public_id,member.name AS member_name,
          member.relationship,member.gender,member.birth_date
   FROM capture_sessions cs
   INNER JOIN family_members member ON member.id=cs.member_id
   LEFT JOIN devices device ON device.id=cs.device_id
   LEFT JOIN capture_reference_versions reference ON reference.id=cs.reference_version_id
   WHERE cs.public_id=? AND cs.user_id=? AND member.status='active'
   LIMIT 1"
);
$sessionStmt->execute([$archivePublicId, $userId]);
$session = $sessionStmt->fetch();
if (!$session) json_response(['ok' => false, 'error' => '全口采集档案不存在或不属于当前账号。'], 404);
$sessionId = (int)$session['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($action,['fill','replace'],true)) {
  require_csrf();
  $regions=capture_archive_regions();
  $region=trim((string)($_POST['region_id']??$_POST['capture_region_id']??''));
  if(!isset($regions[$region]))json_response(['ok'=>false,'error'=>'图片位置不正确。'],422);
  $exists=$pdo->prepare('SELECT id FROM detections WHERE capture_session_id=? AND capture_region_id=? AND source_detection_id IS NULL LIMIT 1');
  $exists->execute([$sessionId,$region]);
  $existing=$exists->fetch();
  if($action==='fill'&&$existing)json_response(['ok'=>false,'error'=>'该位置已经有图片，请使用“替换”。'],409);
  if($action==='replace'&&!$existing)json_response(['ok'=>false,'error'=>'该位置没有原图，请使用“补图”。'],409);
  $upload=capture_archive_uploaded_image($userId,(int)$session['member_id']);$obsolete=[];
  try{
    $pdo->beginTransaction();
    $locked=$pdo->prepare('SELECT id FROM detections WHERE capture_session_id=? AND capture_region_id=? AND source_detection_id IS NULL LIMIT 1 FOR UPDATE');
    $locked->execute([$sessionId,$region]);$current=$locked->fetch();
    if($action==='fill'&&$current){$pdo->rollBack();@unlink($upload['path']);json_response(['ok'=>false,'error'=>'该位置刚刚已补入图片，请刷新后重试。'],409);}
    if($action==='replace'&&!$current){$pdo->rollBack();@unlink($upload['path']);json_response(['ok'=>false,'error'=>'原图已被删除，请刷新后使用“补图”。'],409);}
    if($current){capture_archive_mark_dental_arch_stale($pdo,(int)$current['id'],$userId,'source_image_replaced');$obsolete=capture_archive_delete_detection_tree($pdo,(int)$current['id']);}
    $message=$action==='replace'?'由用户从本地替换七图档案中的原照片。':'由用户从本地补入七图档案袋。';
    $insert=$pdo->prepare("INSERT INTO detections(public_id,user_id,device_id,member_id,image_path,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,upload_mode,model_pipeline,status,report_text,capture_session_id,capture_mode,capture_region_id,capture_region_index) VALUES(?,?,NULL,?,?,?,?,?,0,'none',1,'archive','caries','saved',?,?,'seven_view',?,?)");
    $insert->execute([$upload['public_id'],$userId,(int)$session['member_id'],$upload['relative'],$upload['width'],$upload['height'],$upload['bytes'],$message,$sessionId,$region,$regions[$region]]);
    $summary=capture_archive_recalculate($pdo,$sessionId);$pdo->commit();
    capture_archive_cleanup_files($pdo,$obsolete);
    json_response(['ok'=>true,'detection_id'=>$upload['public_id'],'region_id'=>$region,'source_type'=>'local_fill','archive_complete'=>$summary['complete'],'operation'=>$action],$action==='fill'?201:200);
  }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();@unlink($upload['path']);error_log('capture archive upload/replace failed: '.$error->getMessage());json_response(['ok'=>false,'error'=>'档案图片保存失败，请稍后重试。'],500);}
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$action==='delete_image'){
  require_csrf();$data=request_data();$region=trim((string)($data['region_id']??$data['capture_region_id']??''));$regions=capture_archive_regions();
  if(!isset($regions[$region]))json_response(['ok'=>false,'error'=>'图片位置不正确。'],422);
  $paths=[];
  try{
    $pdo->beginTransaction();$stmt=$pdo->prepare('SELECT id,public_id FROM detections WHERE user_id=? AND capture_session_id=? AND capture_region_id=? AND source_detection_id IS NULL LIMIT 1 FOR UPDATE');$stmt->execute([$userId,$sessionId,$region]);$image=$stmt->fetch();
    if(!$image){$pdo->rollBack();json_response(['ok'=>false,'error'=>'该位置的图片已不存在，请刷新页面。'],404);}
    capture_archive_mark_dental_arch_stale($pdo,(int)$image['id'],$userId,'source_image_deleted');$paths=capture_archive_delete_detection_tree($pdo,(int)$image['id']);$summary=capture_archive_recalculate($pdo,$sessionId);$pdo->commit();
    $deletedFiles=capture_archive_cleanup_files($pdo,$paths);
    json_response(['ok'=>true,'region_id'=>$region,'deleted_detection_id'=>(string)$image['public_id'],'archive_complete'=>$summary['complete'],'completed_count'=>$summary['count'],'deleted_files'=>$deletedFiles]);
  }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('capture archive image delete failed: '.$error->getMessage());json_response(['ok'=>false,'error'=>'删除档案图片失败，请稍后重试。'],500);}
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&$action==='delete_archive'){
  require_csrf();$paths=[];
  try{
    $pdo->beginTransaction();$lock=$pdo->prepare('SELECT id FROM capture_sessions WHERE id=? AND user_id=? LIMIT 1 FOR UPDATE');$lock->execute([$sessionId,$userId]);
    if(!$lock->fetch()){$pdo->rollBack();json_response(['ok'=>false,'error'=>'档案已不存在，请返回成员档案。'],404);}
    try{
      $jobStmt=$pdo->prepare('SELECT id FROM dental_arch_jobs WHERE user_id=? AND capture_session_id=?');$jobStmt->execute([$userId,$sessionId]);$jobIds=array_map('intval',$jobStmt->fetchAll(PDO::FETCH_COLUMN));
      if($jobIds){$marks=implode(',',array_fill(0,count($jobIds),'?'));$pdo->prepare("UPDATE dental_arch_jobs SET active_version_id=NULL WHERE id IN ({$marks})")->execute($jobIds);$pdo->prepare("DELETE FROM dental_arch_jobs WHERE id IN ({$marks})")->execute($jobIds);}
    }catch(Throwable $ignored){}
    try{$candidate=$pdo->prepare('SELECT raw_path,canonical_path FROM capture_candidates WHERE session_id=?');$candidate->execute([$sessionId]);foreach($candidate->fetchAll() as $row){$paths[]=(string)$row['raw_path'];$paths[]=(string)$row['canonical_path'];}}catch(Throwable $ignored){}
    $roots=$pdo->prepare('SELECT id FROM detections WHERE user_id=? AND capture_session_id=? AND source_detection_id IS NULL');$roots->execute([$userId,$sessionId]);
    foreach(array_map('intval',$roots->fetchAll(PDO::FETCH_COLUMN)) as $rootId)$paths=array_merge($paths,capture_archive_delete_detection_tree($pdo,$rootId));
    $pdo->prepare('DELETE FROM capture_sessions WHERE id=? AND user_id=?')->execute([$sessionId,$userId]);$pdo->commit();
    $deletedFiles=capture_archive_cleanup_files($pdo,$paths);
    json_response(['ok'=>true,'archive_id'=>$archivePublicId,'member_id'=>(string)$session['member_public_id'],'deleted_files'=>$deletedFiles,'reports_preserved'=>true]);
  }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();error_log('capture archive delete failed: '.$error->getMessage());json_response(['ok'=>false,'error'=>'删除全口采集档案失败，请稍后重试。'],500);}
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')json_response(['ok'=>false,'error'=>'不支持的档案操作。'],404);

$imageStmt = $pdo->prepare(
  "SELECT d.public_id,d.device_id,d.capture_region_id,d.capture_region_index,d.status,
          d.image_width,d.image_height,d.image_bytes,d.created_at,
          (SELECT COUNT(*) FROM detections child WHERE child.source_detection_id=d.id) AS analysis_count,
          (SELECT COUNT(*) FROM detection_results result WHERE result.detection_id=d.id) AS result_count
   FROM detections d
   WHERE d.user_id=? AND d.capture_session_id=? AND d.source_detection_id IS NULL
   ORDER BY d.capture_region_index ASC,d.id ASC"
);
$imageStmt->execute([$userId, $sessionId]);
$images = $imageStmt->fetchAll();
$actualMask = 0;
foreach ($images as $image) {
  $index = (int)($image['capture_region_index'] ?? 0);
  if ($index >= 1 && $index <= 7) $actualMask |= 1 << ($index - 1);
}
$images = array_map(static function(array $image): array {
  $image['source_type'] = $image['device_id'] === null ? 'local_fill' : 'device';
  unset($image['device_id']);
  return $image;
}, $images);
$actualCount = count($images);
$isComplete = $actualCount === 7 && $actualMask === 127 && (string)$session['status'] === 'completed';

$familyStmt = $pdo->prepare(
  "SELECT DISTINCT report.public_id,report.title,report.status,report.clinical_summary_json,
          report.progress_label,report.created_at,report.completed_at
   FROM family_reports report
   INNER JOIN family_report_images link ON link.report_id=report.id
   INNER JOIN detections image ON image.id=link.source_detection_id
   WHERE report.user_id=? AND image.capture_session_id=?
   ORDER BY report.id DESC"
);
$familyStmt->execute([$userId, $sessionId]);

$aiStmt = $pdo->prepare(
  "SELECT DISTINCT session.public_id,session.title,session.status,session.risk_level,
          session.summary,session.created_at,session.completed_at
   FROM ai_dentist_sessions session
   INNER JOIN ai_dentist_session_images link ON link.session_id=session.id
   INNER JOIN detections image ON image.id=link.detection_id
   WHERE session.user_id=? AND image.capture_session_id=?
   ORDER BY session.id DESC"
);
$aiStmt->execute([$userId, $sessionId]);

$dentalArch=null;
try{$archStmt=$pdo->prepare("SELECT job.public_id,job.status,job.progress_label,job.error_message,version.public_id AS version_public_id,version.status AS version_status FROM dental_arch_jobs job LEFT JOIN dental_arch_versions version ON version.id=job.active_version_id WHERE job.user_id=? AND job.capture_session_id=? ORDER BY job.id DESC LIMIT 1");$archStmt->execute([$userId,$sessionId]);$dentalArch=$archStmt->fetch()?:null;}catch(Throwable $ignored){}
$dentalArchVersions=[];
try{$versionStmt=$pdo->prepare("SELECT version.public_id,version.version_number,version.status,version.is_current,version.created_at,job.status AS job_status FROM dental_arch_versions version INNER JOIN dental_arch_jobs job ON job.id=version.job_id WHERE job.user_id=? AND job.capture_session_id=? ORDER BY version.version_number DESC,version.id DESC");$versionStmt->execute([$userId,$sessionId]);$dentalArchVersions=$versionStmt->fetchAll();}catch(Throwable $ignored){}

$reports = [];
foreach ($familyStmt->fetchAll() as $row) {
  $clinical = capture_archive_json($row['clinical_summary_json']);
  $reports[] = [
    'type' => 'family_report',
    'public_id' => (string)$row['public_id'],
    'title' => (string)$row['title'],
    'status' => (string)$row['status'],
    'risk' => capture_archive_risk((string)($clinical['overall_risk'] ?? 'unknown')),
    'summary' => (string)($clinical['summary'] ?? $row['progress_label'] ?? ''),
    'date' => (string)($row['completed_at'] ?: $row['created_at']),
    'url' => 'family-reports.html?id=' . rawurlencode((string)$row['public_id']),
  ];
}
foreach ($aiStmt->fetchAll() as $row) {
  $reports[] = [
    'type' => 'ai_dentist',
    'public_id' => (string)$row['public_id'],
    'title' => (string)$row['title'],
    'status' => (string)$row['status'],
    'risk' => capture_archive_risk((string)$row['risk_level']),
    'summary' => (string)($row['summary'] ?? ''),
    'date' => (string)($row['completed_at'] ?: $row['created_at']),
    'url' => 'ai-dentist.html?session=' . rawurlencode((string)$row['public_id']),
  ];
}
usort($reports, static fn(array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));

json_response([
  'ok' => true,
  'archive' => [
    'public_id' => (string)$session['public_id'],
    'status' => $isComplete ? 'completed' : 'incomplete',
    'stored_status' => (string)$session['status'],
    'completed_count' => $actualCount,
    'completed_mask' => $actualMask,
    'is_complete' => $isComplete,
    'can_generate_report' => $isComplete,
    'device_name' => (string)($session['device_name'] ?: '齿镜设备'),
    'device_public_id' => (string)($session['device_public_id'] ?? ''),
    'reference_version' => (string)($session['reference_version'] ?? ''),
    'created_at' => (string)$session['created_at'],
    'completed_at' => (string)($session['completed_at'] ?: $session['updated_at']),
  ],
  'member' => [
    'public_id' => (string)$session['member_public_id'],
    'name' => (string)$session['member_name'],
    'relationship' => (string)$session['relationship'],
    'gender' => (string)$session['gender'],
    'birth_date' => $session['birth_date'],
  ],
  'images' => $images,
  'reports' => $reports,
  'dental_arch' => $dentalArch,
  'dental_arch_versions' => $dentalArchVersions,
]);
