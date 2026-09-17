<?php
declare(strict_types=1);

/*
 * 只允许从服务器命令行运行：
 *   php maintenance/recover_unlinked_device_images.php
 *   php maintenance/recover_unlinked_device_images.php --apply
 *
 * 默认仅预览。--apply 才会把解绑时被级联删除、但文件仍留在
 * chijing_storage/history/u{user}/m{member}/... 中的图片重新登记为“仅保存”记录。
 */
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once dirname(__DIR__) . '/api/config.php';

$options = getopt('', ['apply','user::','member::']);
$apply = array_key_exists('apply',$options);
$onlyUser = isset($options['user']) ? max(0,(int)$options['user']) : 0;
$onlyMember = isset($options['member']) ? max(0,(int)$options['member']) : 0;
$historyRoot = storage_history_dir();

if (!is_dir($historyRoot)) {
  fwrite(STDERR,"历史图片目录不存在：{$historyRoot}\n");
  exit(2);
}

$pdo = db();
$memberStmt = $pdo->prepare(
  'SELECT m.id,m.user_id,m.name,u.nickname
   FROM family_members m INNER JOIN users u ON u.id=m.user_id
   WHERE m.id=? AND m.user_id=? LIMIT 1'
);
$existsStmt = $pdo->prepare(
  'SELECT id FROM detections
   WHERE public_id=? OR image_path=? LIMIT 1'
);
$insertStmt = $pdo->prepare(
  "INSERT INTO detections(
    public_id,user_id,device_id,member_id,image_path,image_width,image_height,
    image_bytes,upload_mode,model_pipeline,status,report_text,created_at
  ) VALUES(?,?,NULL,?,?,?,?,?,'archive','caries','saved',?,?)"
);

$found = 0;
$restored = 0;
$skipped = 0;
$failed = 0;
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($historyRoot,FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
  if (!$file->isFile()) continue;
  $absolute = str_replace('\\','/',$file->getPathname());
  $prefix = rtrim(str_replace('\\','/',$historyRoot),'/') . '/';
  if (!str_starts_with($absolute,$prefix)) continue;
  $relativeHistory = substr($absolute,strlen($prefix));
  if (!preg_match(
    '#^u([0-9]+)/m([0-9]+)/([0-9]{8})/photo_([0-9]{14}[a-f0-9]{10})\.(jpe?g|png|webp)$#i',
    $relativeHistory,$match
  )) continue;

  $userId = (int)$match[1];
  $memberId = (int)$match[2];
  if ($onlyUser > 0 && $onlyUser !== $userId) continue;
  if ($onlyMember > 0 && $onlyMember !== $memberId) continue;
  $found++;

  $memberStmt->execute([$memberId,$userId]);
  $member = $memberStmt->fetch();
  if (!$member) {
    echo "[跳过] 用户 {$userId} / 成员 {$memberId} 已不存在：{$relativeHistory}\n";
    $skipped++;
    continue;
  }

  $publicId = strtolower($match[4]);
  $storedPath = 'history/' . $relativeHistory;
  $existsStmt->execute([$publicId,$storedPath]);
  if ($existsStmt->fetch()) {
    $skipped++;
    continue;
  }

  $imageInfo = @getimagesize($file->getPathname());
  if (!is_array($imageInfo) || empty($imageInfo[0]) || empty($imageInfo[1])) {
    echo "[失败] 不是可读取的图片：{$storedPath}\n";
    $failed++;
    continue;
  }

  $created = DateTimeImmutable::createFromFormat('YmdHis',substr($publicId,0,14));
  $createdAt = $created instanceof DateTimeImmutable
    ? $created->format('Y-m-d H:i:s')
    : date('Y-m-d H:i:s',$file->getMTime());
  echo "[待恢复] {$member['name']} / {$createdAt} / {$storedPath}\n";
  if (!$apply) continue;

  try {
    $insertStmt->execute([
      $publicId,$userId,$memberId,$storedPath,
      (int)$imageInfo[0],(int)$imageInfo[1],(int)$file->getSize(),
      '从解绑后保留的图片文件恢复；原检测状态和模型结果无法从图片文件还原。',
      $createdAt,
    ]);
    $restored++;
  } catch (Throwable $error) {
    echo "[失败] {$storedPath}：{$error->getMessage()}\n";
    $failed++;
  }
}

echo "\n扫描完成：候选 {$found}，已存在/跳过 {$skipped}，恢复 {$restored}，失败 {$failed}。\n";
if (!$apply) {
  echo "当前是预览模式，没有修改数据库。确认列表后加 --apply 再执行。\n";
}
