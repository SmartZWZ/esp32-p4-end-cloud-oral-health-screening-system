<?php
// Example configuration only. Copy to config.php locally and fill values.
declare(strict_types=1);
const APP_ROOT = __DIR__ . '/..';
const LEGACY_DATA_DIR = APP_ROOT . '/data';
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;
// 快速部署版：数据库信息已按当前宝塔数据库填写。请勿公开或提交此文件。
const DB_DSN = ''; const DB_USER = ''; const DB_PASS = '';
// 模型服务回传检测结果、读取待分析图片时使用。部署模型服务时从本文件读取，勿写入网页或 ESP32 固件。
const MODEL_CALLBACK_SECRET = '';
function db(): PDO { static $pdo=null; if($pdo instanceof PDO)return $pdo; $pdo=new PDO(DB_DSN,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $pdo; }
/**
 * Uploaded images must live outside the website package.  This keeps a code
 * deployment from replacing a family's historical images.  CHIJING_STORAGE_DIR
 * may be set in PHP-FPM/BT; the default is a sibling of the site root.
 */
function storage_root(): string {
  static $root = null;
  if (is_string($root)) return $root;
  $configured = trim((string)getenv('CHIJING_STORAGE_DIR'));
  $root = rtrim($configured !== '' ? $configured : dirname(dirname(__DIR__)) . '/chijing_storage', '/\\');
  return $root;
}
function storage_history_dir(): string { return storage_root() . '/history'; }
function storage_latest_dir(): string { return storage_root() . '/latest'; }
function ensure_storage(): void {
  foreach ([storage_root(), storage_history_dir(), storage_latest_dir()] as $dir) {
    if (!@is_dir($dir) && !@mkdir($dir, 0775, true) && !@is_dir($dir)) throw new RuntimeException('Storage directory unavailable.');
  }
}
/** Resolve both new external paths and pre-upgrade data/history paths safely. */
function image_file_path(string $storedPath): ?string {
  $relative = ltrim(str_replace('\\', '/', $storedPath), '/');
  if ($relative === '' || str_contains($relative, "\0") || preg_match('#(^|/)\.\.(/|$)#', $relative)) return null;
  $candidates = [];
  if (strpos($relative, 'data/') === 0) {
    $candidates[] = APP_ROOT . '/' . $relative; // legacy location, before the external-storage upgrade
    $relative = substr($relative, 5);
  }
  $candidates[] = storage_root() . '/' . $relative;
  foreach ($candidates as $candidate) if (@is_file($candidate)) return $candidate;
  return null;
}
function json_response(array $payload,int $status=200): void { http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function start_session(): void { if(session_status()===PHP_SESSION_NONE){session_name('chijing');session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);session_start();} }
function request_data(): array { if(strpos(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')!==false){$v=json_decode((string)file_get_contents('php://input'),true);return is_array($v)?$v:[];}return $_POST; }
function current_user(): ?array { start_session();$id=$_SESSION['uid']??0;if(!$id)return null;$s=db()->prepare('SELECT id,public_id,email,nickname FROM users WHERE id=? AND status=1 LIMIT 1');$s->execute([(int)$id]);return $s->fetch()?:null; }
function require_user(): array { $user=current_user();if(!$user)json_response(['ok'=>false,'error'=>'请先登录。'],401);return $user; }
function csrf(): string { start_session();if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));return $_SESSION['csrf']; }
function require_csrf(): void { start_session();$token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!is_string($token)||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$token))json_response(['ok'=>false,'error'=>'页面已过期，请刷新后重试。'],419); }
function public_id(): string { return date('YmdHis').bin2hex(random_bytes(5)); }
function header_value(string $name): ?string { $v=$_SERVER['HTTP_'.strtoupper(str_replace('-','_',$name))]??null;return is_string($v)&&$v!==''?$v:null; }
function require_model_secret(): void { $secret=header_value('X-Model-Secret')??'';if(!hash_equals(MODEL_CALLBACK_SECRET,$secret))json_response(['ok'=>false,'error'=>'模型服务身份验证失败。'],401); }

/**
 * AI runtime credentials intentionally live outside the deployable website
 * package.  The file is shared with the local WebSocket gateway but is never
 * returned to a browser.
 */
function ai_runtime_config(): array {
  static $config = null;
  if (is_array($config)) return $config;
  $path = trim((string)getenv('CHIJING_AI_CONFIG'));
  if ($path === '') $path = dirname(dirname(__DIR__)) . '/chijing_runtime/ai/bailian.env';
  if (!is_readable($path)) throw new RuntimeException('AI 助手尚未完成服务器配置。');
  $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
  if (!is_array($parsed)) throw new RuntimeException('AI 助手配置文件格式无效。');
  foreach (['AI_GATEWAY_SECRET', 'AI_GATEWAY_PUBLIC_PATH'] as $key) {
    if (!isset($parsed[$key]) || trim((string)$parsed[$key]) === '') throw new RuntimeException('AI 助手配置不完整。');
  }
  $config = $parsed;
  return $config;
}

function ai_gateway_ticket(array $user, string $instructions, string $conversationId=''): array {
  $config = ai_runtime_config();
  $payload = [
    'sub' => (string)$user['public_id'],
    'kind' => 'web',
    'exp' => time() + 120,
    'nonce' => bin2hex(random_bytes(10)),
    'instructions' => mb_substr($instructions, 0, 1200, 'UTF-8'),
  ];
  if ($conversationId !== '') $payload['conversation_id'] = mb_substr($conversationId, 0, 32);
  $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
  $signature = hash_hmac('sha256', $encoded, (string)$config['AI_GATEWAY_SECRET']);
  return [
    'ticket' => $encoded . '.' . $signature,
    'expires_at' => $payload['exp'],
    'path' => (string)$config['AI_GATEWAY_PUBLIC_PATH'],
  ];
}

/**
 * AI 牙医使用百炼 OpenAI 兼容接口。密钥仍与语音网关一起保存在站点目录外，
 * 浏览器和管理页面只能看到“已配置/未配置”，不能读取真实值。
 */
function ai_dentist_runtime_config(): array {
  $config = ai_runtime_config();
  $baseUrl = rtrim(trim((string)($config['BAILIAN_OPENAI_BASE_URL'] ?? '')), '/');
  $apiKey = trim((string)($config['BAILIAN_API_KEY'] ?? ''));
  $publicOrigin = rtrim(trim((string)($config['AI_DENTIST_PUBLIC_ORIGIN'] ?? 'https://wwwxsh.cn')), '/');
  if ($baseUrl === '' || $apiKey === '') throw new RuntimeException('AI 牙医尚未配置百炼 OpenAI 兼容地址或 API Key。');
  if (!preg_match('#^https://#i', $baseUrl) || !preg_match('#^https://#i', $publicOrigin)) {
    throw new RuntimeException('AI 牙医接口地址必须使用 HTTPS。');
  }
  $config['BAILIAN_OPENAI_BASE_URL'] = $baseUrl;
  $config['AI_DENTIST_PUBLIC_ORIGIN'] = $publicOrigin;
  return $config;
}

function is_admin_user(array $user): bool {
  try {
    $config = ai_runtime_config();
  } catch (Throwable $error) {
    return false;
  }
  $emails = array_filter(array_map(
    static fn(string $email): string => strtolower(trim($email)),
    explode(',', (string)($config['AI_DENTIST_ADMIN_EMAILS'] ?? ''))
  ));
  $current = strtolower(trim((string)($user['email'] ?? '')));
  return $current !== '' && in_array($current, $emails, true);
}

function require_admin(): array {
  $user = require_user();
  if (!is_admin_user($user)) json_response(['ok'=>false,'error'=>'当前账户没有 AI 牙医管理权限。'],403);
  return $user;
}
