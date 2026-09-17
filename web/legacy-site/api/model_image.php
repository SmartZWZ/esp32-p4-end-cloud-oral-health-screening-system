<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

require_model_secret();
$id = trim((string)($_GET['detection_id'] ?? ''));
if ($id === '') json_response(['ok' => false, 'error' => '缺少检测记录 ID。'], 422);
$s = db()->prepare('SELECT image_path FROM detections WHERE public_id=? LIMIT 1');
$s->execute([mb_substr($id, 0, 32)]);
$row = $s->fetch();
if (!$row) { http_response_code(404); exit('Not found'); }
$path = image_file_path((string)$row['image_path']);
if (!$path) { http_response_code(404); exit('Not found'); }
$info = function_exists('getimagesize') ? @getimagesize($path) : false;
$mime = is_array($info) ? (string)($info['mime'] ?? 'image/jpeg') : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
