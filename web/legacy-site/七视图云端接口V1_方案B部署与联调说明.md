# 七视图云端接口 V1（方案 B）部署与联调说明

## 一、实现范围

云端已经按两份硬件组文档实现：业务流程以《端云统一对接细节：七视图自动采集协议 V1（确认稿）》为准，通信和内存上限以《方案 B 端云内存安全补充约束》为准。

新流程使用独立接口，没有修改旧 `/api/process.php` 的请求合同：

```text
POST /api/capture_sessions.php
POST /api/capture_validate.php
POST /api/capture_candidates.php?action=confirm
POST /api/capture_candidates.php?action=discard
```

## 二、部署文件

部署顺序必须是“先数据库、后 PHP”。`api/images.php` 会读取新增字段，如果先覆盖 PHP、后执行迁移，影像管理页会在这段时间内报数据库字段不存在。

1. 先只上传并执行 `database_seven_view_capture_v1_migration.sql`；
2. 数据库检查通过后，再把以下 PHP 文件按原目录覆盖到 `/www/wwwroot/8.138.230.100_667/`。

本次涉及文件：

```text
api/capture_protocol_common.php
api/capture_sessions.php
api/capture_validate.php
api/capture_candidates.php
api/ai_dentist_common.php
api/images.php
database_seven_view_capture_v1_migration.sql
```

`database.sql` 只供将来新建全新数据库使用。已经运行的服务器不要重新执行整个 `database.sql`。

## 三、数据库

在 phpMyAdmin 左侧先点选数据库 `8_138_230_100_666`，只执行：

```text
database_seven_view_capture_v1_migration.sql
```

然后检查：

```sql
USE `8_138_230_100_666`;
SHOW TABLES LIKE 'capture_sessions';
SHOW TABLES LIKE 'capture_candidates';
SHOW COLUMNS FROM detections LIKE 'capture_session_id';
SHOW COLUMNS FROM detections LIKE 'capture_mode';
SHOW COLUMNS FROM detections LIKE 'capture_region_id';
SHOW COLUMNS FROM detections LIKE 'capture_region_index';
```

如果报 `#1044` 且界面显示当前数据库为 `information_schema`，不要修改 SQL，先在左侧重新选择齿镜业务数据库。

## 四、PHP 环境与语法

V1 按双方确认稿由云端水平翻转一次 JPEG，PHP 8.0 必须启用 GD：

```bash
/www/server/php/80/bin/php -m | grep -i '^gd$'
```

应输出 `gd`。没有输出时，在宝塔 PHP-8.0 的“安装扩展”中安装 GD，再重载 PHP-8.0。

检查语法：

```bash
SITE_ROOT=/www/wwwroot/8.138.230.100_667
PHP_BIN=/www/server/php/80/bin/php

for file in api/capture_protocol_common.php api/capture_sessions.php api/capture_validate.php api/capture_candidates.php api/ai_dentist_common.php api/images.php
do
  "$PHP_BIN" -l "$SITE_ROOT/$file" || exit 1
done
```

六个文件都应显示 `No syntax errors detected`。

## 五、路由检查

以下请求没有设备令牌，正确结果是小型 JSON `401`，而不是 HTML `404`：

```bash
curl -sS -i -X POST 'https://wwwxsh.cn/api/capture_sessions.php' -H 'Content-Type: application/json' --data '{"action":"status","protocol_version":1,"capture_session_id":"cs_test_000000"}'

curl -sS -i -X POST 'https://wwwxsh.cn/api/capture_validate.php' -H 'Content-Type: image/jpeg' -H 'Content-Length: 0'

curl -sS -i -X POST 'https://wwwxsh.cn/api/capture_candidates.php?action=confirm' -H 'Content-Type: application/json' --data '{}'
```

若返回 Nginx HTML 404，说明文件没有上传到 `wwwxsh.cn` 实际使用的站点根目录，此时不需要排查数据库。

## 六、真实质量复核的前提

参考图管理后台必须同时满足：

- 21 个槽位完整；
- 版本状态为 `published`；
- 已设为当前参考版本；
- “启用模型判断”已经打开；
- `quality_model` 是支持多图视觉输入的百炼模型；
- 七个区域的参考图与硬件候选图方向一致。

会话启动时会锁定当时的参考版本。采集中切换当前版本，不会让同一轮七张混用不同版本。

## 七、接口请求

### 1. start

```http
POST /api/capture_sessions.php
Authorization: Bearer <device_token>
Content-Type: application/json
```

```json
{"action":"start","protocol_version":1,"member_id":"<member_public_id>","capture_mode":"seven_view","client_session_nonce":"boot42_s17"}
```

相同设备和相同 `client_session_nonce` 重试会返回同一会话。

设备重启或重新进入采集页后即使生成了新 nonce，云端也会按以下规则恢复：

- 同一设备、同一成员已有 `collecting` 会话：返回该会话及当前区域，硬件直接继续采集；
- 旧会话属于其他成员，但尚未确认任何照片：云端取消空会话并创建新会话；
- 旧会话属于其他成员且已经确认照片：返回 HTTP 409 `request_in_progress`，防止静默丢失进度；
- 超过两小时没有更新的会话先标记为 `expired`，不再阻塞新会话。

恢复逻辑不增加新的必填响应字段，硬件端继续解析原有固定字段即可。

### 2. validate

```http
POST /api/capture_validate.php
Authorization: Bearer <device_token>
Content-Type: image/jpeg
Content-Length: <jpeg_bytes>
X-Member-Id: <member_public_id>
X-Capture-Mode: seven_view
X-Capture-Protocol-Version: 1
X-Capture-Session-Id: <capture_session_id>
X-Request-Id: <request_id>
X-Expected-Region: front_bite
X-Region-Index: 1

<JPEG 原始字节>
```

不得使用 multipart、Base64、gzip 或 chunked。JPEG 超过 `1048576` 字节返回 HTTP 413 小型 JSON。

同一 `(device_id, request_id)`：内容和上下文相同则返回第一次结果且不再调用模型；图片摘要、成员、会话、模式或区域不同则返回 HTTP 409 `invalid_request_id`；第一次仍在处理则返回 `request_in_progress`。

V1 只允许硬件主协议冻结的错误码。`server_busy`、`invalid_jpeg`、`session_not_found`、`session_expired`、`region_conflict`、`jpeg_too_large`、`request_conflict`、`invalid_request`、`unauthorized` 不再直接下发给设备。历史幂等结果若包含旧名称，会在最终响应出口转换为对应的 V1 固定错误码。

正常重拍只依赖顶层 `ok=true + accepted=false + decision=retake`。`wrong_region + distance=good`、`wrong_region + distance=unknown`、`region_match=false + positioning=unknown`、`detected_region=unknown` 以及任一 `quality.*=unknown` 都是合法受控组合，云端不会为了迎合旧解析器而强制改写。

板端无需为正常重拍同步调用 discard。不合格候选保持 `temporary` 状态用于短期诊断，不会写入正式影像；云端在 start 和 validate 请求中限量清理超过 24 小时的临时候选文件及状态。

### 3. confirm

```http
POST /api/capture_candidates.php?action=confirm
Authorization: Bearer <device_token>
Content-Type: application/json
```

```json
{"protocol_version":1,"capture_session_id":"<capture_session_id>","request_id":"<request_id>","temporary_image_id":"<temporary_image_id>"}
```

只有 confirm 成功后才写入正式影像并推进区域。重复 confirm 返回同一个 `detection_id` 和相同进度。

### 4. discard 与 cancel

discard 使用与 confirm 相同的 JSON，URL 改为 `/api/capture_candidates.php?action=discard`。重复 discard 保持成功。

cancel 发送到会话接口：

```json
{"action":"cancel","protocol_version":1,"capture_session_id":"<capture_session_id>"}
```

取消后的迟到 validate 或 confirm 不会重新激活会话。

## 八、方案 B 强制上限

```text
正常响应目标               <= 1536 bytes
任何响应绝对上限           <= 4096 bytes
instruction                <= 96 UTF-8 bytes
ID                          <= 64 bytes
JPEG                        <= 1048576 bytes
模型 HTTP 超时              = 10 seconds
服务端 validate 截止时间   = 12 seconds
```

新接口返回紧凑 UTF-8 JSON、准确 `Content-Length` 和 `Content-Encoding: identity`，不返回 Base64 图片、模型原文、提示词、第三方错误正文、PHP warning 或 HTML。HTTP keep-alive 由 Nginx/HTTP 协议管理，PHP 不发送 HTTP/2 禁止的 `Connection` 头。

## 九、响应长度检查

用真实令牌和成员 ID 完成 start 后检查：

```bash
curl -sS -D /tmp/capture_headers.txt -o /tmp/capture_body.json -X POST 'https://wwwxsh.cn/api/capture_sessions.php' -H 'Authorization: Bearer <device_token>' -H 'Content-Type: application/json' --data '{"action":"start","protocol_version":1,"member_id":"<member_public_id>","capture_mode":"seven_view","client_session_nonce":"manual_test_001"}'

wc -c /tmp/capture_body.json
grep -Ei 'content-type|content-length|content-encoding|transfer-encoding' /tmp/capture_headers.txt
cat /tmp/capture_body.json
```

body 应小于 1536 字节；`Content-Length` 应与 `wc -c` 一致；不得出现 `Transfer-Encoding: chunked`。不要把真实设备令牌发到聊天或截图中。

## 十、数据库联调检查

```sql
SELECT public_id,status,current_region_index,completed_count,completed_mask,reference_version_id,created_at,completed_at
FROM capture_sessions ORDER BY id DESC LIMIT 10;

SELECT request_id,expected_region,region_index,validation_status,lifecycle_status,accepted,decision,reason_code,server_elapsed_ms,detection_id
FROM capture_candidates ORDER BY id DESC LIMIT 30;

SELECT public_id,capture_session_id,capture_mode,capture_region_id,capture_region_index,image_path
FROM detections WHERE capture_session_id IS NOT NULL ORDER BY id DESC LIMIT 20;
```

完整会话必须为 `status=completed`、`completed_count=7`、`completed_mask=127`，七个区域各一张且属于同一 `capture_session_id`。删除其中任意一张后，会话会被改成 `incomplete`，不再具有规范七视图报告资格。

## 十一、首轮联合验收顺序

1. 先验证 start、成员归属和 nonce 幂等；
2. 只联调第一个区域，核对云端规范图的左右；
3. 分别制造正常重拍和正常通过；
4. 确认通过后仍必须 confirm 才能推进；
5. 主动丢失 confirm 响应，再用相同请求重试，确认没有重复图片；
6. 完成七张并检查 `completed_mask=127`；
7. 测试取消、迟到响应、1 MiB 超限和模型超时；
8. 连续完成 10 轮，观察硬件 RAM、PSRAM 最大连续块以及云端重复记录；
9. 回归检查旧单张拍照、旧云端分析、语音助手和成员同步。

## 十二、七视图质量判断已恢复

七张连续上传与档案袋关系已经通过联调，当前版本不再读取 `capture_accept_all.flag`，也不再提供七视图直通逻辑。即使服务器残留旧标志文件，`capture_validate.php` 仍会调用参考图和百炼视觉模型执行真实质量判断。

部署当前版本时仍应清理旧标志文件，避免与历史版本混淆：

```bash
SITE_ROOT=/www/wwwroot/8.138.230.100_667
rm -f "$SITE_ROOT/data/capture_accept_all.flag"
```

真实判断要求后台同时满足：已发布参考版本、该版本已设为当前版本、21 张参考图齐全，并且“启用模型判断”已打开。普通质量不合格返回 HTTP 200 和 `decision=retake`；参考图、模型或超时故障仍返回受控的 HTTP 503。
