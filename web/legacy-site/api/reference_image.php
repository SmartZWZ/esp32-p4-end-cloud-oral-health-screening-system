<?php
declare(strict_types=1);
require_once __DIR__ . '/reference_library_common.php';

try {
  require_admin();$id=mb_substr(trim((string)($_GET['id']??'')),0,32);$variant=(string)($_GET['variant']??'current');
  if(!in_array($variant,['current','baseline'],true)){http_response_code(422);exit;}
  $stmt=db()->prepare('SELECT image_path,baseline_path,updated_at FROM capture_reference_images WHERE public_id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
  $path=$row?reference_safe_path((string)($variant==='baseline'?$row['baseline_path']:$row['image_path'])):null;if(!$path){http_response_code(404);exit('Not found');}
  header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);
} catch(Throwable $error){error_log('reference_image.php: '.$error->getMessage());http_response_code(500);}

