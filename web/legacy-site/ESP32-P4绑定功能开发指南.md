# ESP32-P4 设备码绑定功能开发指南

本文说明 ESP32-P4 固件如何对接齿镜网站的固定设备码绑定功能。目标是让用户只在网页输入设备码，ESP32-P4 自动获得上传令牌；网页端不显示也不传递永久 Token。

## 1. 功能目标与状态机

设备应实现以下状态：

```text
首次初始化
  ↓
未登记（注册设备码）
  ↓
等待网页绑定
  ↓
网页已确认
  ↓
更新设备码
  ↓
激活并保存上传 Token
  ↓
已绑定 / 正常采集上传
```

设备码不按时刷新。只有用户在硬件端执行“更新设备码”，或者网页绑定成功后由硬件执行自动更新时，设备码才会改变。

## 2. NVS 必须保存的数据

使用 ESP-IDF `nvs_flash` / Arduino `Preferences` 保存以下字段：

| 键名 | 内容 | 是否长期保存 |
|---|---|---|
| `device_uid` | 基于 eFuse MAC 的硬件唯一编号 | 是 |
| `device_code` | 当前显示给用户输入的设备码 | 是 |
| `device_secret` | 每台硬件独立的设备身份密钥 | 是 |
| `code_version` | 当前设备码版本号，从 1 开始 | 是 |
| `device_token` | 激活成功后获得的图片上传令牌 | 是 |
| `link_state` | `ready`、`claimed`、`bound` 等运行状态 | 可选 |

不要把这些值写入串口日志，尤其不要输出 `device_secret` 和 `device_token`。

## 3. 生成设备身份与设备码

### 3.1 device_uid

首次启动时读取 eFuse MAC，生成稳定 UID，例如：

```text
CJ-P4-A1B2C3D4E5F6
```

UID 不应因重启、重新联网或重新绑定而改变。

### 3.2 device_secret

生产设备应在烧录阶段写入每台不同的 32 字节随机密钥。开发阶段可在首次启动时由 ESP32 随机生成并保存，但首次登记存在被抢注的风险。

建议使用 ESP-IDF 的 `esp_fill_random()` 生成随机字节，编码为 Base64URL 或十六进制后保存。服务端只保存 SHA-256 哈希。

### 3.3 device_code

设备码推荐格式：

```text
CJ-7H4K-9M2Q-F8RX
```

规则：

- 使用随机 Base32 字符；
- 排除 `0`、`O`、`I`、`L` 等易混淆字符；
- 除 `CJ` 前缀外，建议至少 12 个随机字符；
- 生成后写入 NVS；
- 每次更新时令 `code_version += 1`。

可使用字母表：

```text
ABCDEFGHJKMNPQRSTUVWXYZ23456789
```

## 4. 服务端接口约定

基础地址（当前测试环境）：

```text
http://8.138.230.100:667/api/device_link.php
```

正式部署必须替换为 HTTPS 域名。

所有请求使用 `POST`、`Content-Type: application/json`。除 `register` 外，硬件身份密钥放在请求头：

```http
X-Device-Secret: <device_secret>
```

### 4.1 首次登记或开机登记：`register`

```http
POST /api/device_link.php?action=register
Content-Type: application/json
```

```json
{
  "device_uid": "CJ-P4-A1B2C3D4E5F6",
  "device_code": "CJ-7H4K-9M2Q-F8RX",
  "device_secret": "设备首次登记时的随机密钥",
  "code_version": 1,
  "firmware_version": "0.1.0"
}
```

成功示例：

```json
{"ok":true,"status":"ready","code_version":1}
```

设备应在联网成功后调用一次；如果服务端已登记该设备，服务端会验证 `device_secret`。设备码未变化时只更新时间；硬件端主动更新设备码时使用更大的 `code_version` 再调用此接口，或调用后述 `rotate_code`。

### 4.2 查询网页是否已确认：`device_status`

```http
POST /api/device_link.php?action=device_status
X-Device-Secret: <device_secret>
Content-Type: application/json
```

```json
{"device_uid":"CJ-P4-A1B2C3D4E5F6"}
```

关键响应：

```json
{"ok":true,"status":"ready","rotate_required":false,"code_version":1}
```

```json
{"ok":true,"status":"claimed","rotate_required":true,"code_version":1}
```

建议策略：

- `ready`：显示设备码；每 5 到 10 秒轮询一次。
- `claimed` 且 `rotate_required=true`：屏幕显示“网页已确认”，立即执行设备码更新。
- `bound`：设备已经激活；停止绑定轮询，进入正常采集流程。

### 4.3 网页确认后更新设备码：`rotate_code`

收到 `rotate_required=true` 后，硬件必须按如下顺序执行：

1. 使用硬件随机源生成新设备码。
2. 先暂存在 RAM，不要立即覆盖 NVS 中的旧码。
3. 将版本号加 1。
4. 调用接口上传新设备码。
5. 收到成功响应后，再将新码和新版本写入 NVS。
6. 调用 `activate` 获取永久 Token。

```http
POST /api/device_link.php?action=rotate_code
X-Device-Secret: <device_secret>
Content-Type: application/json
```

```json
{
  "device_uid":"CJ-P4-A1B2C3D4E5F6",
  "device_code":"CJ-AB7K-9M3D-Q6RX",
  "code_version":2
}
```

成功后服务端会清除“需要更新设备码”标记。若网络失败或返回冲突，不覆盖 NVS，重新生成新码后重试。

### 4.4 激活并获取上传 Token：`activate`

```http
POST /api/device_link.php?action=activate
X-Device-Secret: <device_secret>
Content-Type: application/json
```

```json
{"device_uid":"CJ-P4-A1B2C3D4E5F6"}
```

成功响应：

```json
{
  "ok": true,
  "status": "bound",
  "device_token": "只返回给硬件一次的永久令牌"
}
```

收到后立刻写入 NVS；写入成功前不要显示“绑定完成”。若写入失败，应保留“需要激活”的状态并提示用户，避免设备丢失唯一返回的 Token。

> 当前服务端为防止重复激活，Token 只在首次 `activate` 中返回。实际固件应确保写 NVS 成功后再继续下一步。后续若设备丢失 Token，建议网页解绑后重新绑定。

## 5. 正常图片上传

激活成功后，保持现有 JPEG 上传流程，但使用永久 Token：

```http
POST /api/process.php
X-Device-Token: <device_token>
Content-Type: image/jpeg
```

请求体为 OV5647 采集并编码后的 JPEG。服务端返回 `201` 和检测记录 ID 即表示图片已进入用户的专属记录。

若收到 `401`：

1. 停止继续上传，避免无限重试；
2. 在屏幕提示“设备已解绑或令牌失效”；
3. 保留诊断日志但不要显示 Token；
4. 引导用户在网页重新绑定；
5. 不要自动清除设备码，是否更新设备码应由硬件菜单或下一次成功绑定决定。

## 6. 硬件端主动更新设备码

在设备设置中提供“更新设备码”操作，建议二次确认：

```text
更新设备码？
旧设备码将立即失效
[取消] [确认]
```

确认后：

1. 生成新随机设备码；
2. `code_version += 1`；
3. 调用 `rotate_code`；
4. 服务端成功后写 NVS；
5. 屏幕显示新的设备码。

已绑定设备主动更新设备码不会改变上传 Token，也不会改变设备归属；它只使旧设备码失效。

## 7. 显示屏交互建议

未绑定：

```text
齿镜设备码
CJ-7H4K-9M2Q-F8RX

请在齿镜网页输入设备码
网络：已连接
```

网页确认后：

```text
网页已确认
正在更新设备码并完成绑定…
请勿断电
```

绑定完成：

```text
绑定成功
设备已连接至齿镜账户
可开始采集
```

网络异常：

```text
网络未连接
设备码已保留
请检查 Wi-Fi 后重试
```

## 8. 异常处理与重试

| 场景 | 固件处理 |
|---|---|
| Wi-Fi 未连接 | 保留 NVS 中的设备码，定时重连，不生成新码。 |
| `register` 超时 | 指数退避重试，最长间隔建议 60 秒。 |
| 网页未确认 | 每 5–10 秒调用 `device_status`。 |
| 网页确认超时 | 服务端回到 `ready`，硬件继续显示原设备码。 |
| `rotate_code` 网络失败 | 不写新 NVS，保留旧设备码并重试。 |
| `activate` 后 NVS 写入失败 | 显示错误并停止；需网页解绑后重新绑定恢复。 |
| 上传接口返回 401 | 提示已解绑或 Token 失效，停止上传并引导重新绑定。 |

## 9. 安全与上线要求

- 正式环境必须使用 HTTPS；不要在公网 HTTP 上传设备密钥、Token 或口腔图片。
- 设备码、设备密钥和上传 Token 均不可输出到串口日志或调试网页。
- `device_secret` 不应通过普通网页接口发送，也不应由用户手工输入。
- 推荐使用设备唯一出厂密钥，并在云端预先登记其 SHA-256 哈希；首次开机自动注册仅适合开发测试。
- 所有随机值应使用 ESP32 硬件随机源，例如 `esp_fill_random()`。
- 接口重试需要指数退避，避免 Wi-Fi 故障时大量请求服务器。

## 10. 联调检查清单

1. 清除测试设备 NVS 或使用未登记 UID。
2. 启动设备，确认显示稳定设备码。
3. 检查 `register` 返回 `ready`。
4. 登录网页，输入该设备码。
5. 确认网页进入“等待设备确认”。
6. 确认硬件收到 `claimed + rotate_required=true`。
7. 确认硬件生成新设备码并成功调用 `rotate_code`。
8. 确认硬件调用 `activate`、NVS 保存 Token 成功。
9. 确认网页设备列表出现设备。
10. 上传一张 JPEG，确认只出现在该用户的检测记录中。
11. 在网页解绑，确认下一次上传返回 401。

