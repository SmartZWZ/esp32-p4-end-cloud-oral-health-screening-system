<?php
declare(strict_types=1);
require_once __DIR__ . '/api/config.php';
$user=require_user();$s=db()->prepare('SELECT image_path FROM detections WHERE user_id=? ORDER BY id DESC LIMIT 1');$s->execute([(int)$user['id']]);$row=$s->fetch();if(!$row){http_response_code(404);exit('No uploaded photo');}$path=APP_ROOT.'/'.$row['image_path'];if(!is_file($path)){http_response_code(404);exit('No uploaded photo');}header('Content-Type: image/jpeg');header('Cache-Control: private, no-store');readfile($path);
