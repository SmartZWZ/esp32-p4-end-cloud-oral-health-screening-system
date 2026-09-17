<?php
// 当前路由由 app/Http/ApiController.php 分发；此文件保存对外契约摘要。
// POST /api/v1/auth/register | /login | /logout
// GET  /api/v1/me
// GET  /api/v1/devices
// POST /api/v1/devices/claim | /api/v1/devices/{public_id}/unbind
// GET  /api/v1/detections | /api/v1/detections/{public_id} | /api/v1/detections/{public_id}/image
// POST /api/process.php（ESP32 兼容上传入口，使用 X-Device-Token）
