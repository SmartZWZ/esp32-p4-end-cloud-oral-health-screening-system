<?php
declare(strict_types=1);
require_once __DIR__ . '/assistant_memory_common.php';

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok'=>false,'error'=>'仅支持 POST 请求。'],405);
  }
  assistant_memory_gateway_secret();
  $pdo = db();
  $data = request_data();
  $settings = assistant_memory_settings($pdo);
  assistant_memory_cleanup($pdo,(int)$settings['retention_days']);
  $source = assistant_memory_conversation($pdo,$data);
  json_response(['ok'=>true,'context'=>assistant_memory_context($pdo,$source,$settings)]);
} catch (Throwable $error) {
  error_log('assistant_context.php: '.$error->getMessage());
  json_response(['ok'=>false,'error'=>'助手历史暂时不可用。'],503);
}
