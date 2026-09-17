<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
  $id = mb_substr(trim((string)($_GET['id'] ?? '')),0,32);
  $expires = (int)($_GET['expires'] ?? 0);
  $signature = trim((string)($_GET['signature'] ?? ''));
  if ($id === '' || $expires < time() || $expires > time()+900 || !preg_match('/^[a-f0-9]{64}$/',$signature)) {
    http_response_code(403);
    exit;
  }
  $runtime = ai_dentist_runtime_config();
  $expected = hash_hmac('sha256',$id.'|'.$expires,(string)$runtime['AI_GATEWAY_SECRET']);
  if (!hash_equals($expected,$signature)) { http_response_code(403); exit; }
  $stmt = db()->prepare('SELECT image_path FROM detections WHERE public_id=? LIMIT 1');
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  $path = $row ? image_file_path((string)$row['image_path']) : null;
  if (!$path) { http_response_code(404); exit; }
  $info = @getimagesize($path);
  $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
  if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) { http_response_code(415); exit; }
  $size = (int)filesize($path);
  header('Content-Type: '.$mime);
  header('Content-Length: '.$size);
  header('Cache-Control: private, no-store, max-age=0');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
} catch (Throwable $error) {
  error_log('ai_dentist_image.php: '.$error->getMessage());
  http_response_code(500);
}
