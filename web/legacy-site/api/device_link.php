<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_response(['ok' => false, 'error' => '仅支持 POST 请求。'], 405);

function link_code(string $code): string {
  return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code))) ?? '';
}

function link_secret(): string {
  return trim((string)(header_value('X-Device-Secret') ?? ''));
}

function link_device(PDO $pdo, string $uid, string $secret, bool $lock = false): array {
  if ($uid === '' || $secret === '') json_response(['ok' => false, 'error' => '缺少设备身份信息。'], 401);
  $sql = 'SELECT * FROM device_registry WHERE device_uid=? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
  $s = $pdo->prepare($sql);
  $s->execute([$uid]);
  $device = $s->fetch();
  if (!$device || !hash_equals((string)$device['device_secret_hash'], hash('sha256', $secret))) {
    json_response(['ok' => false, 'error' => '设备身份验证失败。'], 401);
  }
  if ($device['status'] === 'disabled') json_response(['ok' => false, 'error' => '设备已被禁用。'], 403);
  return $device;
}

function reset_expired_claim(PDO $pdo, array $device): array {
  if ($device['status'] === 'claimed' && !empty($device['claim_expires_at']) && strtotime((string)$device['claim_expires_at']) < time()) {
    $pdo->prepare("UPDATE device_registry SET status='ready',pending_user_id=NULL,pending_display_name=NULL,claim_expires_at=NULL,rotate_after_claim=0 WHERE id=?")
      ->execute([(int)$device['id']]);
    $device['status'] = 'ready';
    $device['pending_user_id'] = null;
    $device['pending_display_name'] = null;
    $device['claim_expires_at'] = null;
    $device['rotate_after_claim'] = 0;
  }
  return $device;
}

$pdo = db();
$action = (string)($_GET['action'] ?? '');
$data = request_data();

if ($action === 'register') {
  $uid = trim((string)($data['device_uid'] ?? ''));
  $code = link_code((string)($data['device_code'] ?? ''));
  $secret = trim((string)($data['device_secret'] ?? link_secret()));
  $firmware = trim((string)($data['firmware_version'] ?? ''));
  $version = max(1, (int)($data['code_version'] ?? 1));
  if ($uid === '' || mb_strlen($uid) > 96 || strlen($code) < 12 || mb_strlen($code) > 40 || strlen($secret) < 24) {
    json_response(['ok' => false, 'error' => '设备登记信息不完整或格式不正确。'], 422);
  }
  $pdo->beginTransaction();
  try {
    $s = $pdo->prepare('SELECT * FROM device_registry WHERE device_uid=? LIMIT 1 FOR UPDATE');
    $s->execute([$uid]);
    $current = $s->fetch();
    if (!$current) {
      $pdo->prepare("INSERT INTO device_registry(public_id,device_uid,pairing_code_hash,device_secret_hash,code_version,status,last_seen_at,code_changed_at) VALUES(?,?,?,?,?,'ready',NOW(),NOW())")
        ->execute([public_id(), $uid, hash('sha256', $code), hash('sha256', $secret), $version]);
      $status = 'ready';
    } else {
      if (!hash_equals((string)$current['device_secret_hash'], hash('sha256', $secret))) json_response(['ok' => false, 'error' => '设备身份验证失败。'], 401);
      if ($version > (int)$current['code_version']) {
        $pdo->prepare('UPDATE device_registry SET pairing_code_hash=?,code_version=?,code_changed_at=NOW(),last_seen_at=NOW() WHERE id=?')
          ->execute([hash('sha256', $code), $version, (int)$current['id']]);
      } else {
        $pdo->prepare('UPDATE device_registry SET last_seen_at=NOW() WHERE id=?')->execute([(int)$current['id']]);
      }
      $status = (string)$current['status'];
    }
    $pdo->commit();
    json_response(['ok' => true, 'status' => $status, 'code_version' => $version]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000') json_response(['ok' => false, 'error' => '设备码已被其他设备使用，请在硬件端更新设备码。'], 409);
    throw $e;
  }
}

if ($action === 'claim') {
  $user = require_user();
  require_csrf();
  $code = link_code((string)($data['device_code'] ?? ''));
  $name = trim((string)($data['display_name'] ?? '我的齿镜'));
  if (strlen($code) < 12 || mb_strlen($code) > 40) json_response(['ok' => false, 'error' => '请输入设备屏幕显示的完整设备码。'], 422);
  $pdo->beginTransaction();
  try {
    $s = $pdo->prepare('SELECT * FROM device_registry WHERE pairing_code_hash=? LIMIT 1 FOR UPDATE');
    $s->execute([hash('sha256', $code)]);
    $device = $s->fetch();
    if (!$device) json_response(['ok' => false, 'error' => '未找到该设备码，请确认设备已联网并已完成登记。'], 404);
    $device = reset_expired_claim($pdo, $device);
    if ($device['status'] === 'bound') json_response(['ok' => false, 'error' => '该设备已被绑定，请先在原账户解绑。'], 409);
    if ($device['status'] === 'claimed' && (int)$device['pending_user_id'] !== (int)$user['id']) json_response(['ok' => false, 'error' => '该设备正在由其他账户完成绑定。'], 409);
    $expires = date('Y-m-d H:i:s', time() + 15 * 60);
    $pdo->prepare("UPDATE device_registry SET status='claimed',pending_user_id=?,pending_display_name=?,claim_expires_at=?,rotate_after_claim=1 WHERE id=?")
      ->execute([(int)$user['id'], mb_substr($name === '' ? '我的齿镜' : $name, 0, 64), $expires, (int)$device['id']]);
    $pdo->commit();
    json_response(['ok' => true, 'pairing_id' => (string)$device['public_id'], 'status' => 'waiting_device', 'claim_expires_at' => $expires]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

if ($action === 'claim_status') {
  $user = require_user();
  $id = trim((string)($data['pairing_id'] ?? ''));
  $s = $pdo->prepare('SELECT public_id,device_uid,status,claim_expires_at FROM device_registry WHERE public_id=? AND pending_user_id=? LIMIT 1');
  $s->execute([$id, (int)$user['id']]);
  $device = $s->fetch();
  if (!$device) json_response(['ok' => false, 'error' => '绑定请求不存在或已失效。'], 404);
  if ($device['status'] === 'claimed' && !empty($device['claim_expires_at']) && strtotime((string)$device['claim_expires_at']) < time()) {
    $pdo->prepare("UPDATE device_registry SET status='ready',pending_user_id=NULL,pending_display_name=NULL,claim_expires_at=NULL,rotate_after_claim=0 WHERE public_id=?")->execute([$id]);
    json_response(['ok' => true, 'status' => 'expired']);
  }
  json_response(['ok' => true, 'status' => $device['status'], 'claim_expires_at' => $device['claim_expires_at']]);
}

if ($action === 'device_status') {
  $uid = trim((string)($data['device_uid'] ?? ''));
  $secret = link_secret();
  $pdo->beginTransaction();
  try {
    $device = reset_expired_claim($pdo, link_device($pdo, $uid, $secret, true));
    $pdo->prepare('UPDATE device_registry SET last_seen_at=NOW() WHERE id=?')->execute([(int)$device['id']]);
    $pdo->commit();
    json_response(['ok' => true, 'status' => $device['status'], 'rotate_required' => (bool)$device['rotate_after_claim'], 'code_version' => (int)$device['code_version']]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

if ($action === 'rotate_code') {
  $uid = trim((string)($data['device_uid'] ?? ''));
  $secret = link_secret();
  $code = link_code((string)($data['device_code'] ?? ''));
  $version = (int)($data['code_version'] ?? 0);
  if (strlen($code) < 12 || mb_strlen($code) > 40 || $version < 1) json_response(['ok' => false, 'error' => '新的设备码格式不正确。'], 422);
  $pdo->beginTransaction();
  try {
    $device = link_device($pdo, $uid, $secret, true);
    if ($version <= (int)$device['code_version']) json_response(['ok' => false, 'error' => '设备码版本未更新。'], 409);
    $pdo->prepare('UPDATE device_registry SET pairing_code_hash=?,code_version=?,code_changed_at=NOW(),rotate_after_claim=0,last_seen_at=NOW() WHERE id=?')
      ->execute([hash('sha256', $code), $version, (int)$device['id']]);
    $pdo->commit();
    json_response(['ok' => true, 'status' => $device['status'], 'code_version' => $version]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000') json_response(['ok' => false, 'error' => '新设备码已被使用，请重新生成。'], 409);
    throw $e;
  }
}

if ($action === 'activate') {
  $uid = trim((string)($data['device_uid'] ?? ''));
  $secret = link_secret();
  $pdo->beginTransaction();
  try {
    $registry = reset_expired_claim($pdo, link_device($pdo, $uid, $secret, true));
    if ($registry['status'] !== 'claimed' || empty($registry['pending_user_id'])) json_response(['ok' => false, 'error' => '设备尚未完成网页确认。'], 409);
    if ((int)$registry['rotate_after_claim'] === 1) json_response(['ok' => false, 'error' => '请先更新设备码。'], 409);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('INSERT INTO devices(public_id,user_id,device_uid,display_name,token_hash) VALUES(?,?,?,?,?)')
      ->execute([public_id(), (int)$registry['pending_user_id'], $uid, (string)$registry['pending_display_name'], hash('sha256', $token)]);
    $pdo->prepare("UPDATE device_registry SET status='bound',claim_expires_at=NULL,last_seen_at=NOW() WHERE id=?")->execute([(int)$registry['id']]);
    $pdo->commit();
    json_response(['ok' => true, 'status' => 'bound', 'device_token' => $token]);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000') json_response(['ok' => false, 'error' => '设备已激活，请刷新网页查看。'], 409);
    throw $e;
  }
}

json_response(['ok' => false, 'error' => '接口不存在。'], 404);
