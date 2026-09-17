<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
  ensure_storage();
  db()->query('SELECT 1');
  json_response([
    'ok' => true,
    'service' => 'chijing-quick',
    'time' => date(DATE_ATOM),
    'database' => true,
    'storage_writable' => @is_writable(storage_root()),
  ]);
} catch (Throwable $e) {
  error_log('system status check failed: ' . $e->getMessage());
  json_response([
    'ok' => false,
    'service' => 'chijing-quick',
    'database' => false,
    'storage_writable' => false,
    'error' => 'Server storage or database is unavailable.',
  ], 500);
}
