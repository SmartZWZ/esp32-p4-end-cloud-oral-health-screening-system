<?php
declare(strict_types=1);
require_once __DIR__ . '/reference_library_common.php';

try {
  require_admin();
  $id=mb_substr(trim((string)($_GET['id']??'')),0,32);
  $stmt=db()->prepare('SELECT public_id FROM capture_reference_tests WHERE public_id=? LIMIT 1');
  $stmt->execute([$id]);
  if(!$stmt->fetchColumn()){http_response_code(404);exit('Not found');}
  $path=reference_test_candidate_path($id);
  if(!$path || !is_file($path)){http_response_code(404);exit('Not found');}
  header('Content-Type: image/jpeg');
  header('Content-Length: '.(string)filesize($path));
  header('Cache-Control: private, no-store');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
} catch(Throwable $error) {
  error_log('reference_test_image.php: '.$error->getMessage());
  http_response_code(500);
}
