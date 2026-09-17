<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/dental_arch_common.php';

function dental_arch_claim_job(PDO $pdo): ?array {
  $pdo->beginTransaction();
  try {
    $stmt=$pdo->query("SELECT * FROM dental_arch_jobs WHERE status='vision_pending' OR (status='vision_processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 20 MINUTE)) ORDER BY id LIMIT 1 FOR UPDATE");
    $job=$stmt->fetch();
    if(!$job){$pdo->commit();return null;}
    $pdo->prepare("UPDATE dental_arch_jobs SET status='vision_processing',started_at=COALESCE(started_at,NOW()),progress_step=2,progress_label='视觉模型正在逐图完善轮廓并判断牙号。',error_message=NULL WHERE id=?")->execute([(int)$job['id']]);
    $pdo->commit();$job['status']='vision_processing';return $job;
  }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

function dental_arch_job_rows(PDO $pdo, int $jobId): array {
  $stmt=$pdo->prepare("SELECT link.*,source.public_id AS source_public_id,source.image_path,source.image_width,source.image_height FROM dental_arch_job_images link INNER JOIN detections source ON source.id=link.source_detection_id WHERE link.job_id=? ORDER BY link.capture_region_index");
  $stmt->execute([$jobId]);return $stmt->fetchAll();
}

function dental_arch_run_job(PDO $pdo, array $job): void {
  $guide=dental_arch_guide();
  if(!hash_equals((string)$job['guide_sha256'],(string)$guide['sha256'])){
    $pdo->prepare('UPDATE dental_arch_jobs SET guide_sha256=? WHERE id=?')->execute([(string)$guide['sha256'],(int)$job['id']]);
    $job['guide_sha256']=$guide['sha256'];
  }
  $rows=dental_arch_job_rows($pdo,(int)$job['id']);
  if(count($rows)!==7)throw new RuntimeException('牙列任务没有完整的七个视角。');
  $views=[];$lastModel=(string)$job['visual_model'];
  foreach($rows as $offset=>$row){
    $local=dental_arch_json((string)($row['local_result_json']??''));
    if(!$local||($local['pipeline']??'')!=='tooth_outline_darkline_v3')throw new RuntimeException((string)$row['capture_region_id'].'缺少有效的本地轮廓结果。');
    $normalized=dental_arch_json((string)($row['vision_result_json']??''));
    if(!$normalized){
      $path=image_file_path((string)$row['image_path']);if(!$path)throw new RuntimeException((string)$row['capture_region_id'].'原图文件不存在。');
      $overlay=dental_arch_overlay_data_url($path,dental_arch_local_teeth($local));
      // Generate the ticket immediately before this provider call. Reusing one
      // ticket for the whole ten-step job risks expiry during later stages.
      $imageUrl=ai_dentist_signed_image_url(['public_id'=>(string)$row['source_public_id']],time()+600);
      $pdo->prepare("UPDATE dental_arch_job_images SET status='vision_processing',error_message=NULL WHERE id=?")->execute([(int)$row['id']]);
      $result=dental_arch_call($pdo,$job,dental_arch_per_image_messages($row,$local,(string)$guide['content'],$imageUrl,$overlay),'dental_arch_image');
      $lastModel=(string)($result['model']??$lastModel);$provider=is_array($result['parsed_json']??null)?$result['parsed_json']:dental_arch_parse_provider_json((string)$result['content']);$normalized=dental_arch_normalize_image_result($provider,$local,$row);
      $json=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      $pdo->prepare("UPDATE dental_arch_job_images SET status='vision_completed',vision_result_json=?,error_message=NULL WHERE id=?")->execute([$json,(int)$row['id']]);
    }
    $normalized['source_detection_db_id']=(int)$row['source_detection_id'];$normalized['source_detection_id']=(string)$row['source_public_id'];$normalized['source_kind']=(string)$row['source_kind'];$normalized['image_width']=(int)$row['image_width'];$normalized['image_height']=(int)$row['image_height'];$views[]=$normalized;
    $pdo->prepare("UPDATE dental_arch_jobs SET progress_step=?,progress_label=? WHERE id=?")->execute([3+$offset,'视觉模型已完成 '.($offset+1).'/7 个视角。',(int)$job['id']]);
  }
  $pdo->prepare("UPDATE dental_arch_jobs SET progress_step=9,progress_label='正在进行七图牙号统一复核。' WHERE id=?")->execute([(int)$job['id']]);
  // The seven-view review happens after seven independent model calls. Issue a
  // fresh URL for every image here instead of reusing the earlier tickets.
  $jointUrls=[];
  foreach($rows as $row){
    $jointUrls[(string)$row['capture_region_id']]=ai_dentist_signed_image_url(
      ['public_id'=>(string)$row['source_public_id']],
      time()+600
    );
  }
  $jointCall=dental_arch_call($pdo,$job,dental_arch_joint_messages($views,(string)$guide['content'],$jointUrls),'dental_arch_joint');
  $lastModel=(string)($jointCall['model']??$lastModel);$joint=is_array($jointCall['parsed_json']??null)?$jointCall['parsed_json']:dental_arch_parse_provider_json((string)$jointCall['content']);$combined=dental_arch_apply_joint($views,$joint);
  $combined['schema']='chijing.dental_arch.v1';$combined['job_id']=(string)$job['public_id'];$combined['source_type']=(string)$job['source_type'];$combined['guide_sha256']=(string)$job['guide_sha256'];$combined['visual_model']=$lastModel;$combined['generated_at']=date('c');
  $version=dental_arch_store_version($pdo,$job,$combined,$lastModel);$jointJson=json_encode($joint,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $pdo->prepare('UPDATE dental_arch_job_images SET joint_result_json=? WHERE job_id=?')->execute([$jointJson,(int)$job['id']]);
  if((string)$job['review_decision']==='direct'){
    $pdo->beginTransaction();try{if(!empty($job['capture_session_id'])){$pdo->prepare('UPDATE dental_arch_versions version INNER JOIN dental_arch_jobs lineage ON lineage.id=version.job_id SET version.is_current=0 WHERE lineage.capture_session_id=?')->execute([(int)$job['capture_session_id']]);}else{$pdo->prepare('UPDATE dental_arch_versions SET is_current=0 WHERE job_id=?')->execute([(int)$job['id']]);}$pdo->prepare("UPDATE dental_arch_versions SET status='confirmed',is_current=1,confirmed_at=NOW() WHERE id=?")->execute([(int)$version['id']]);$pdo->prepare("UPDATE dental_arch_jobs SET status='completed',active_version_id=?,progress_step=10,progress_label='牙列档案已直接生成并绑定。',completed_at=NOW(),error_message=NULL WHERE id=?")->execute([(int)$version['id'],(int)$job['id']]);$pdo->commit();}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
  }else{
    $pdo->prepare("UPDATE dental_arch_jobs SET status='review_required',active_version_id=?,progress_step=10,progress_label='七图复核完成，请确认牙号与轮廓。',error_message=NULL WHERE id=?")->execute([(int)$version['id'],(int)$job['id']]);
  }
  echo '['.date('c').'] completed job='.(string)$job['public_id'].' version='.(string)$version['public_id'].PHP_EOL;
}

function dental_arch_worker_once(): bool {
  $pdo=db();$job=dental_arch_claim_job($pdo);if(!$job)return false;
  try{dental_arch_run_job($pdo,$job);}catch(Throwable $error){$pdo->prepare("UPDATE dental_arch_jobs SET status='failed',progress_label='牙列生成失败，可从失败步骤重试。',error_message=? WHERE id=?")->execute([mb_substr($error->getMessage(),0,1000),(int)$job['id']]);echo '['.date('c').'] failed job='.(string)$job['public_id'].' error='.$error->getMessage().PHP_EOL;}
  return true;
}

$loop=in_array('--loop',$argv??[],true);
do{$worked=dental_arch_worker_once();if($loop)sleep($worked?2:8);}while($loop);
