# 齿镜：宝塔 PHP 网站

齿镜是面向 ESP32-P4 口腔影像设备的用户网站。首版已实现：注册登录、设备绑定、设备令牌、私有影像上传、用户检测历史，以及调用独立模型服务的异步处理脚本。

> 模型与大模型输出必须作为辅助筛查信息展示，不能替代口腔医生诊断。

## 项目结构

```text
app/                       用户、设备、上传与 API 业务代码
config/                    环境变量、数据库连接
database/migrations/       MySQL 建表脚本
public/                    宝塔唯一 Web 根目录
  api/process.php          兼容 ESP32 原有上传地址
  assets/                  网页样式与交互脚本
scripts/                   设备初始化与模型任务 worker
storage/                   私有图片、日志、缓存（绝不可放到 public 下）
deploy/                    Nginx 示例配置
```

## 宝塔部署

1. 上传本项目到 `/www/wwwroot/chijing`；站点运行目录设置为 `/www/wwwroot/chijing/public`。
2. 在宝塔创建 MySQL 数据库（字符集 `utf8mb4`），然后在 phpMyAdmin 选中数据库，导入 [建表脚本](database/migrations/001_initial_schema.sql)。
3. 复制 `.env.example` 为 `.env`，填写数据库配置、`APP_URL` 和强随机 `APP_KEY`。`.env` 不得提交到 Git 或暴露给公网。
4. PHP 选择 8.1+，开启 `pdo_mysql`、`mbstring`、`fileinfo`、`openssl`、`curl` 扩展。
5. 让 PHP 运行用户拥有 `storage/uploads`、`storage/logs`、`storage/cache` 的读写权限。
6. 将 [Nginx 示例](deploy/nginx.conf.example) 合并到宝塔站点配置，并配置 HTTPS 证书。

当前入口为 `https://你的域名/`；健康检查为 `GET /api/v1/health`。

## 首次接入 ESP32-P4

先在服务器命令行执行一次，设备 UID 要与固件的 `X-Device` 值一致：

```bash
php scripts/provision_device.php esp32p4-camera-quality-test "我的齿镜"
```

命令会显示一次性激活码。登录网页后，在“绑定设备”中填入设备 UID 和该激活码；页面会显示一次**设备令牌**。

固件保留现有地址不变：

```text
POST https://你的域名/api/process.php
Content-Type: image/jpeg
X-Device-Token: <网页绑定时生成的设备令牌>
X-Request-Id: <每次拍照唯一 ID，可选，用于重传去重>

<JPEG 二进制>
```

上传接口仍兼容 multipart 的 `file` 或 `photo` 字段。为了便于旧固件迁移，`.env` 中可临时设置 `ALLOW_LEGACY_UNAUTHENTICATED_UPLOAD=true`，此时会接受数据库中已绑定设备的 `X-Device`；正式上线前务必改回 `false`。

## 模型任务

上传成功后记录状态为 `queued`。模型服务以独立 FastAPI 服务运行，接口应为：

```text
POST {AI_SERVICE_URL}/v1/analyze
multipart: image=<JPEG>, detection_id=<ID>
```

返回 JSON 至少应包含：

```json
{
  "model": {"name": "oral-detector", "version": "1", "quality_score": 0.95, "inference_ms": 320},
  "result": {"findings": []},
  "report": {"summary": "…", "advice": ["…"]},
  "provider": "your-llm-provider",
  "llm_model": "your-model-name",
  "prompt_version": "v1"
}
```

宝塔“计划任务”可每分钟运行一次 worker：

```bash
/usr/bin/php /www/wwwroot/chijing/scripts/worker.php 5 >> /www/wwwroot/chijing/storage/logs/worker.log 2>&1
```

## 安全要点

- 图片只保存在 `storage/uploads`，通过登录态和检测归属校验后才可读取。
- 用户每次查询均按 `detections.user_id` 限定；客户端传入的 `user_id` 从不被信任。
- 用户密码、会话令牌、设备令牌和激活码只存哈希值。
- 生产环境必须使用 HTTPS，并关闭旧版无令牌上传。
