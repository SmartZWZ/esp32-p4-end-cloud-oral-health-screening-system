<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$uid = trim((string)($argv[1] ?? ''));
if ($uid === '' || mb_strlen($uid) > 96) {
    exit("用法：php scripts/provision_device.php <设备UID> [设备名称]\n");
}
$name = trim((string)($argv[2] ?? '未绑定齿镜'));
$activationCode = strtoupper(bin2hex(random_bytes(5)));
$pdo = database();
$existing = $pdo->prepare('SELECT public_id, owner_user_id FROM devices WHERE device_uid = ? LIMIT 1');
$existing->execute([$uid]);
$device = $existing->fetch();
if ($device && $device['owner_user_id'] !== null) {
    exit("该设备已经绑定用户，不能重新初始化。\n");
}
if ($device) {
    $stmt = $pdo->prepare("UPDATE devices SET public_id = ?, display_name = ?, activation_code_hash = ?, status = 'unbound', owner_user_id = NULL, bound_at = NULL WHERE device_uid = ?");
    $stmt->execute([app_public_id(), mb_substr($name, 0, 64), hash('sha256', $activationCode), $uid]);
} else {
    $stmt = $pdo->prepare("INSERT INTO devices (public_id, device_uid, display_name, activation_code_hash, status) VALUES (?, ?, ?, ?, 'unbound')");
    $stmt->execute([app_public_id(), $uid, mb_substr($name, 0, 64), hash('sha256', $activationCode)]);
}
echo "设备已初始化。\n";
echo "设备 UID：{$uid}\n";
echo "一次性激活码：{$activationCode}\n";
echo "请在网页“绑定设备”中输入以上两项；激活码成功绑定后即失效。\n";
