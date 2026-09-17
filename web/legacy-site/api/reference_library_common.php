<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

const REFERENCE_PROTOCOL_VERSION = 1;
const REFERENCE_MIN_EDGE = 320;
const REFERENCE_MAX_PIXELS = 60000000;

function reference_regions(): array {
  return [
    'front_bite'=>['label'=>'正面咬合位','order'=>1],
    'left_bite'=>['label'=>'左侧咬合位','order'=>2],
    'right_bite'=>['label'=>'右侧咬合位','order'=>3],
    'upper_left_open'=>['label'=>'张口左上牙区','order'=>4],
    'upper_right_open'=>['label'=>'张口右上牙区','order'=>5],
    'lower_left_open'=>['label'=>'张口左下牙区','order'=>6],
    'lower_right_open'=>['label'=>'张口右下牙区','order'=>7],
  ];
}

function reference_distances(): array {
  return [
    'too_far'=>['label'=>'太远','order'=>1],
    'too_close'=>['label'=>'太近','order'=>2],
    'good'=>['label'=>'合适','order'=>3],
  ];
}

function reference_slots(): array {
  $slots=[];$index=1;
  foreach (reference_regions() as $regionId=>$region) {
    foreach (reference_distances() as $distanceId=>$distance) {
      $slots[]=[
        'index'=>$index,
        'code'=>sprintf('%02d_%s_%s',$index,$regionId,$distanceId),
        'region_id'=>$regionId,
        'region_label'=>$region['label'],
        'distance_label'=>$distanceId,
        'distance_name'=>$distance['label'],
      ];
      $index++;
    }
  }
  return $slots;
}

function reference_slot(string $regionId,string $distanceLabel): ?array {
  foreach (reference_slots() as $slot) if ($slot['region_id']===$regionId && $slot['distance_label']===$distanceLabel) return $slot;
  return null;
}

function reference_storage_dir(): string { return storage_root().'/reference_library'; }
function reference_test_dir(): string { return storage_root().'/reference_tests'; }
function reference_test_candidate_path(string $publicId): ?string {
  if (!preg_match('/^[A-Za-z0-9_-]{12,32}$/',$publicId)) return null;
  return reference_test_dir().'/'.$publicId.'.jpg';
}
function ensure_reference_storage(): void {
  ensure_storage();
  foreach ([reference_storage_dir(),reference_test_dir()] as $dir) {
    if (!@is_dir($dir) && !@mkdir($dir,0775,true) && !@is_dir($dir)) throw new RuntimeException('参考图存储目录不可用。');
  }
}

function reference_safe_path(string $relative): ?string {
  $relative=ltrim(str_replace('\\','/',$relative),'/');
  if ($relative==='' || str_contains($relative,"\0") || preg_match('#(^|/)\.\.(/|$)#',$relative)) return null;
  $path=storage_root().'/'.$relative;
  return @is_file($path)?$path:null;
}

/**
 * Remove EXIF APP1 metadata without decoding or changing JPEG pixels.
 * ESP32-P4 display pixels are the orientation source of truth; browsers must
 * not reinterpret an EXIF orientation flag and silently mirror the image.
 */
function reference_strip_jpeg_exif(string $jpeg): string {
  $length=strlen($jpeg);
  if($length<4 || substr($jpeg,0,2)!=="\xFF\xD8") return $jpeg;
  $parts=[substr($jpeg,0,2)];$offset=2;
  while($offset<$length){
    $start=$offset;
    if(ord($jpeg[$offset])!==0xFF){$parts[]=substr($jpeg,$start);break;}
    while($offset<$length && ord($jpeg[$offset])===0xFF)$offset++;
    if($offset>=$length)return $jpeg;
    $marker=ord($jpeg[$offset]);$offset++;
    if($marker===0xDA || $marker===0xD9){$parts[]=substr($jpeg,$start);break;}
    if($marker===0x01 || ($marker>=0xD0 && $marker<=0xD7)){$parts[]=substr($jpeg,$start,$offset-$start);continue;}
    if($offset+2>$length)return $jpeg;
    $segmentLength=unpack('n',substr($jpeg,$offset,2))[1]??0;
    if($segmentLength<2 || $offset+$segmentLength>$length)return $jpeg;
    $payloadOffset=$offset+2;$end=$offset+$segmentLength;
    $isExif=$marker===0xE1 && substr($jpeg,$payloadOffset,6)==="Exif\x00\x00";
    if(!$isExif)$parts[]=substr($jpeg,$start,$end-$start);
    $offset=$end;
  }
  return implode('',$parts);
}

function reference_validate_edited_jpeg(string $jpeg): array {
  if ($jpeg==='' || strlen($jpeg)>MAX_UPLOAD_BYTES) throw new RuntimeException('编辑后的图片为空或超过 8 MB。');
  $jpeg=reference_strip_jpeg_exif($jpeg);
  $info=@getimagesizefromstring($jpeg);
  if (!is_array($info) || (string)($info['mime']??'')!=='image/jpeg') throw new RuntimeException('编辑器必须输出 JPEG 图片。');
  $width=(int)$info[0];$height=(int)$info[1];
  if ($width<REFERENCE_MIN_EDGE || $height<REFERENCE_MIN_EDGE || ($width*$height)>REFERENCE_MAX_PIXELS) throw new RuntimeException('裁剪后的图片尺寸过小或过大。');
  return ['bytes'=>$jpeg,'width'=>$width,'height'=>$height,'size'=>strlen($jpeg)];
}

function reference_write_atomic(string $path,string $bytes): void {
  $dir=dirname($path);
  if (!@is_dir($dir) && !@mkdir($dir,0775,true) && !@is_dir($dir)) throw new RuntimeException('不能创建参考图目录。');
  $tmp=$path.'.tmp.'.bin2hex(random_bytes(4));
  if (@file_put_contents($tmp,$bytes,LOCK_EX)===false || !@rename($tmp,$path)) { @unlink($tmp);throw new RuntimeException('保存参考图失败。'); }
  @chmod($path,0664);
}

function reference_delete_version_files(string $versionCode): void {
  if (!preg_match('/^REF-[0-9]{8}-[0-9]{3}$/',$versionCode)) return;
  $base=reference_storage_dir();$target=$base.'/'.$versionCode;
  if (!is_dir($target)) return;
  $baseReal=realpath($base);$targetReal=realpath($target);
  if ($baseReal===false || $targetReal===false || !str_starts_with(str_replace('\\','/',$targetReal).'/',rtrim(str_replace('\\','/',$baseReal),'/').'/')) return;
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($targetReal,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
  foreach($iterator as $item){if($item->isDir())@rmdir($item->getPathname());else@unlink($item->getPathname());}
  @rmdir($targetReal);
}

function reference_version_code(PDO $pdo): string {
  $prefix='REF-'.date('Ymd').'-';
  $stmt=$pdo->prepare('SELECT version_code FROM capture_reference_versions WHERE version_code LIKE ? ORDER BY version_code DESC LIMIT 1');
  $stmt->execute([$prefix.'%']);$last=(string)($stmt->fetchColumn()?:'');
  $number=$last!==''?(int)substr($last,-3)+1:1;
  return $prefix.str_pad((string)$number,3,'0',STR_PAD_LEFT);
}

function reference_client_ip(): string {
  return mb_substr(trim((string)($_SERVER['REMOTE_ADDR']??'')),0,64);
}

function reference_audit(PDO $pdo,array $admin,string $operation,?int $versionId=null,?int $imageId=null,array $detail=[]): void {
  $stmt=$pdo->prepare('INSERT INTO capture_reference_audit_logs(public_id,admin_user_id,version_id,image_id,operation,detail_json,ip_address) VALUES(?,?,?,?,?,?,?)');
  $stmt->execute([public_id(),(int)$admin['id'],$versionId,$imageId,mb_substr($operation,0,48),$detail?json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,reference_client_ip()]);
}

function reference_require_version(PDO $pdo,string $publicId,bool $lock=false): array {
  $sql='SELECT v.*,s.active_version_id,(s.active_version_id=v.id) AS is_active FROM capture_reference_versions v CROSS JOIN capture_reference_settings s WHERE s.id=1 AND v.public_id=? LIMIT 1'.($lock?' FOR UPDATE':'');
  $stmt=$pdo->prepare($sql);$stmt->execute([mb_substr(trim($publicId),0,32)]);$version=$stmt->fetch();
  if (!$version) json_response(['ok'=>false,'error'=>'参考图版本不存在。'],404);
  return $version;
}

function reference_require_draft(array $version): void {
  if ((string)$version['status']!=='draft') json_response(['ok'=>false,'error'=>'已发布或归档版本不能直接修改，请复制为新草稿。'],409);
}

function reference_version_payload(PDO $pdo): array {
  $versions=$pdo->query("SELECT v.public_id,v.version_code,v.name,v.description,v.hardware_profile,v.status,v.published_at,v.archived_at,v.created_at,v.updated_at,(s.active_version_id=v.id) AS is_active,COUNT(i.id) AS image_count,SUM(i.reviewed_at IS NOT NULL) AS reviewed_count FROM capture_reference_versions v CROSS JOIN capture_reference_settings s LEFT JOIN capture_reference_images i ON i.version_id=v.id WHERE s.id=1 GROUP BY v.id,s.active_version_id ORDER BY v.id DESC")->fetchAll();
  $settings=$pdo->query('SELECT validation_enabled,active_version_id,quality_model,quality_confidence_threshold FROM capture_reference_settings WHERE id=1')->fetch()?:['validation_enabled'=>0,'active_version_id'=>null,'quality_model'=>null,'quality_confidence_threshold'=>0.8];
  return ['versions'=>$versions,'settings'=>['validation_enabled'=>(bool)$settings['validation_enabled'],'has_active_version'=>$settings['active_version_id']!==null,'quality_model'=>(string)($settings['quality_model']??''),'quality_confidence_threshold'=>(float)($settings['quality_confidence_threshold']??0.8)]];
}

function reference_version_images(PDO $pdo,int $versionId): array {
  $stmt=$pdo->prepare('SELECT public_id,slot_index,slot_code,region_id,distance_label,source_type,image_width,image_height,image_bytes,source_mirrored,normalization_applied,orientation_normalized,note,captured_distance,lighting_note,reviewed_at,created_at,updated_at FROM capture_reference_images WHERE version_id=? ORDER BY slot_index');
  $stmt->execute([$versionId]);return $stmt->fetchAll();
}

function reference_image_urls(string $publicId): array {
  $encoded=rawurlencode($publicId);
  return ['current'=>'api/reference_image.php?id='.$encoded.'&variant=current','baseline'=>'api/reference_image.php?id='.$encoded.'&variant=baseline'];
}
