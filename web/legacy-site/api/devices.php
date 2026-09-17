<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$user = require_user();
$action = (string)($_GET['action'] ?? 'list');
$pdo = db();

if ($action === 'list') {
  $stmt = $pdo->prepare(
    'SELECT public_id,device_uid,display_name,last_seen_at,created_at
     FROM devices WHERE user_id=? ORDER BY id DESC'
  );
  $stmt->execute([(int)$user['id']]);
  json_response(['ok'=>true,'items'=>$stmt->fetchAll()]);
}

if ($action === 'bind') {
  json_response(['ok'=>false,'error'=>'请使用设备码绑定流程。'],410);
}

if ($action === 'unbind') {
  require_csrf();
  $data = request_data();
  $publicId = mb_substr(trim((string)($data['public_id'] ?? '')),0,32);
  $pdo->beginTransaction();
  try {
    $stmt = $pdo->prepare(
      'SELECT id,device_uid FROM devices
       WHERE public_id=? AND user_id=? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$publicId,(int)$user['id']]);
    $device = $stmt->fetch();
    if (!$device) {
      $pdo->rollBack();
      json_response(['ok'=>false,'error'=>'设备不存在。'],404);
    }

    /*
     * 影像和成员属于家庭账号，不属于某一次设备绑定。
     * 解绑前先解除历史影像对旧 devices.id 的引用，避免旧数据库中的
     * ON DELETE CASCADE 外键把图片记录和模型结果一并删除。
     */
    $preserve = $pdo->prepare(
      'UPDATE detections SET device_id=NULL
       WHERE device_id=? AND user_id=?'
    );
    $preserve->execute([(int)$device['id'],(int)$user['id']]);
    $preservedImages = $preserve->rowCount();

    $pdo->prepare('DELETE FROM devices WHERE id=? AND user_id=?')
      ->execute([(int)$device['id'],(int)$user['id']]);
    $pdo->prepare(
      "UPDATE device_registry
       SET status='ready',pending_user_id=NULL,pending_display_name=NULL,
           claim_expires_at=NULL,rotate_after_claim=0
       WHERE device_uid=?"
    )->execute([(string)$device['device_uid']]);
    $pdo->commit();
    json_response([
      'ok'=>true,
      'preserved_image_records'=>$preservedImages,
      'message'=>'设备已解绑，历史图片、成员归属和检测结果均已保留。',
    ]);
  } catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
  }
}

json_response(['ok'=>false,'error'=>'接口不存在。'],404);
