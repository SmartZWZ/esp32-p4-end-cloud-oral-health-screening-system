<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/env.php';
load_env(BASE_PATH . '/.env');
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/app/Support/helpers.php';
require_once BASE_PATH . '/app/Services/DeviceUploadService.php';
require_once BASE_PATH . '/app/Http/ApiController.php';

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Shanghai') ?: 'Asia/Shanghai');

function start_web_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('chijing_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
