# 齿镜快速部署版

## AI 牙医

项目已增加独立的多模态口腔照片辅助筛查页面 `ai-dentist.html` 和完整管理后台
`ai-dentist-admin.html`。现有站点升级时执行 `database_ai_dentist_migration.sql`，
再按照 `AI牙医部署与管理说明.md` 补充百炼 OpenAI 兼容接口和管理员邮箱配置。

## AI 助手会话记忆

网页助手与 ESP32-P4 设备助手已支持相互隔离的多轮会话记忆。现有站点升级时执行
`database_ai_assistant_memory_migration.sql`，再按照 `AI助手会话记忆部署说明.md`
上传文件并重启 AI 网关。硬件端必须按照 `ESP32-P4_AI助手会话记忆对接指南.md`
将服务器返回的 `conversation_id` 保存到 NVS。

与原 ESP32-P4 测试项目相同：把本目录的全部文件上传到宝塔站点根目录并解压即可；无需设置 `public` 运行目录、Worker 或命令行计划任务。

1. 在 phpMyAdmin 中选中已创建的数据库，执行 `database.sql`。
2. 上传本目录全部文件到宝塔站点根目录。
3. 给 `data/` 目录读写权限（建议 755；权限不足再用 775）。
4. 宝塔 PHP 开启 `pdo_mysql`、`mbstring`、`fileinfo`；PHP 版本 7.4+。
5. 访问首页注册账户，在“绑定设备”中填入固件的 `X-Device` 值，例如 `esp32p4-camera-quality-test`。

原固件上传地址更新为：`http://8.138.230.100:667/api/process.php`。

快速验证时，旧固件只要继续发送 `X-Device` 即可上传。绑定页面会同时生成 `X-Device-Token`；后续更新固件后建议使用该令牌。图片和历史记录按登录用户从数据库筛选，图片通过 `api/image.php` 鉴权读取。
