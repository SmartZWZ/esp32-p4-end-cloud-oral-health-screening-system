<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (str_starts_with($path, '/api/v1/')) {
    dispatch_api($method, $path);
}
$user = current_user();
$csrf = csrf_token();
function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#153b6e">
  <title>齿镜 · 口腔影像辅助筛查</title>
  <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
  <header class="site-header">
    <a class="brand" href="/" aria-label="齿镜首页"><span class="brand-mark">齿</span><span>齿镜</span></a>
    <p class="brand-slogan">口腔影像辅助筛查</p>
    <?php if ($user): ?>
      <div class="account"><span><?= h($user['nickname']) ?></span><button id="logout-button" class="text-button">退出登录</button></div>
    <?php endif; ?>
  </header>

  <main>
  <?php if (!$user): ?>
    <section class="auth-shell">
      <div class="hero-copy">
        <span class="eyebrow">CHIJING ORAL IMAGING</span>
        <h1>把每一次口腔观察，<br>留成可回看的记录。</h1>
        <p>齿镜连接 ESP32-P4 设备，安全保存口腔影像，并在模型完成分析后提供辅助筛查报告。</p>
        <ul class="feature-list"><li>设备专属绑定</li><li>检测记录私密保存</li><li>模型结果清晰呈现</li></ul>
      </div>
      <section class="auth-card" aria-label="账户登录与注册">
        <div class="tabs"><button class="tab active" data-tab="login">登录</button><button class="tab" data-tab="register">注册</button></div>
        <div id="message" class="message" aria-live="polite"></div>
        <form id="login-form" class="auth-form">
          <label>邮箱<input type="email" name="email" autocomplete="email" required></label>
          <label>密码<input type="password" name="password" autocomplete="current-password" minlength="8" required></label>
          <button class="primary-button" type="submit">登录齿镜</button>
        </form>
        <form id="register-form" class="auth-form hidden">
          <label>昵称<input type="text" name="nickname" autocomplete="nickname" minlength="2" maxlength="64" required></label>
          <label>邮箱<input type="email" name="email" autocomplete="email" required></label>
          <label>密码<input type="password" name="password" autocomplete="new-password" minlength="8" required></label>
          <button class="primary-button" type="submit">创建账户</button>
        </form>
        <p class="fine-print">登录即表示你知悉：本平台结果仅用于辅助筛查，不能替代专业口腔诊断。</p>
      </section>
    </section>
  <?php else: ?>
    <section class="dashboard-head">
      <div><span class="eyebrow">我的齿镜</span><h1>你好，<?= h($user['nickname']) ?></h1><p>查看设备状态与每次影像检测的处理进度。</p></div>
      <div class="status-pill"><i></i>服务正常</div>
    </section>
    <section class="notice"><strong>辅助筛查提示：</strong>模型与大模型生成的内容不构成医疗诊断；如有疼痛、出血、肿胀等症状，请及时咨询口腔医生。</section>
    <section class="stats" aria-label="数据概览"><article><span>已绑定设备</span><strong id="device-total">–</strong></article><article><span>检测记录</span><strong id="detection-total">–</strong></article><article><span>已完成报告</span><strong id="report-total">–</strong></article></section>
    <section class="dashboard-grid">
      <article class="panel device-panel">
        <div class="panel-heading"><div><span class="eyebrow">设备</span><h2>我的齿镜</h2></div><button class="secondary-button" id="open-bind">绑定设备</button></div>
        <div id="device-list" class="device-list"><p class="loading">正在读取设备…</p></div>
        <div class="device-help"><strong>固件上传地址：</strong><code>/api/process.php</code><br>设备绑定后，请在固件请求中添加 <code>X-Device-Token</code>。</div>
      </article>
      <article class="panel report-panel">
        <div class="panel-heading"><div><span class="eyebrow">检测记录</span><h2>最近检测</h2></div><button id="refresh-detections" class="text-button">刷新</button></div>
        <div id="detection-list" class="detection-list"><p class="loading">正在读取检测记录…</p></div>
      </article>
    </section>
  <?php endif; ?>
  </main>

  <dialog id="bind-dialog" class="dialog">
    <form method="dialog" class="dialog-card" id="bind-form">
      <button class="dialog-close" value="cancel" aria-label="关闭">×</button>
      <span class="eyebrow">绑定 ESP32-P4</span><h2>添加你的齿镜设备</h2>
      <p>输入设备烧录的唯一编号，以及设备配置时生成的一次性激活码。</p>
      <label>设备编号<input name="device_uid" placeholder="例如 esp32p4-camera-quality-test" maxlength="96" required></label>
      <label>设备名称<input name="display_name" value="我的齿镜" maxlength="64" required></label>
      <label>激活码<input name="activation_code" autocomplete="off" required></label>
      <button class="primary-button" type="submit">绑定并生成令牌</button>
      <div id="bind-message" class="message"></div>
    </form>
  </dialog>
  <dialog id="token-dialog" class="dialog"><section class="dialog-card"><button class="dialog-close" id="close-token" aria-label="关闭">×</button><span class="eyebrow">设备令牌</span><h2>请立即保存</h2><p>此令牌只显示一次。将它烧录到 ESP32，并作为 <code>X-Device-Token</code> 请求头发送。</p><code id="issued-token" class="token-value"></code><button id="copy-token" class="secondary-button">复制令牌</button></section></dialog>

  <script>window.CHIJING = <?= json_encode(['authenticated' => (bool)$user, 'csrf' => $csrf], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="/assets/js/app.js" defer></script>
</body>
</html>
