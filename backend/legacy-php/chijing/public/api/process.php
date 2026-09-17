<?php
declare(strict_types=1);

// 固件兼容入口：保留原项目已验证的 POST /api/process.php 地址。
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
handle_device_upload();
