# ESP32-P4 设备码不同步：问题说明与固件修复指南

适用对象：齿镜 ESP32-P4 固件开发人员。  
目的：修复“设备在线、`device_status` 返回成功，但网页输入屏幕设备码时提示未找到设备码”的问题。

> 本文不记录 `device_secret`、`device_token` 等敏感值。固件日志也不得输出这些值。

## 1. 本次故障现象

服务器数据库中存在一条设备登记记录，状态为 `ready`，并且 ESP32 每隔数秒调用 `device_status` 都获得 HTTP 200。这说明：

- `device_uid` 正确；
- `device_secret` 正确；
- ESP32 与服务器网络连通；
- 设备已登记过。

但设备屏幕显示的设备码与服务器保存的 `pairing_code_hash` 不匹配。网页提交设备码时，服务器无法通过哈希查到对应设备，于是返回“未找到该设备码”。

注意：`device_uid`（例如 `CJ-P4-...`）是硬件唯一标识，不是网页要输入的设备码。网页只接受屏幕上显示的 `CJ-XXXX-XXXX-XXXX` 形式的配对码。

## 2. 根因

设备码在三个位置存在“不同步”的可能：

```text
ESP32 NVS 中保存的 device_code / code_version
            ↓
ESP32 屏幕实际显示的 device_code
            ↓
服务器 device_registry.pairing_code_hash / code_version
```

本次问题表示屏幕显示的码已经变化，或显示的并非 NVS 中已登记的码；但服务器没有成功收到对应的 `register` / `rotate_code` 更新。

尤其要注意服务器当前的登记规则：

```text
当设备调用 register 时：
  仅当客户端 code_version > 服务器 code_version 时，服务器才更新配对码哈希。
  若客户端版本号 <= 服务器版本号，服务器只更新 last_seen_at，不更新设备码。
```

因此，以下错误流程会导致本次现象：

1. 设备生成新设备码；
2. 屏幕显示新码；
3. NVS 未同步保存，或未成功调用 `register`；
4. 或仍携带旧的/相同的 `code_version` 调用 `register`；
5. 服务器保留旧设备码，设备却继续显示新码；
6. 网页输入新码时找不到对应哈希。

`device_status` 只校验设备身份并更新在线时间，**不会同步设备码**。所以“设备在线”不能证明“屏幕设备码已登记”。

## 3. 正确的数据所有权与状态机

### 3.1 NVS 是设备端唯一真相

下列字段必须同时、持久化地保存：

| NVS 键 | 说明 |
| --- | --- |
| `device_uid` | 基于 eFuse MAC 生成，永不变化 |
| `device_secret` | 首次生成后永不变化 |
| `device_code` | 当前网页可输入的设备码 |
| `code_version` | 当前设备码版本号，从 1 开始单调递增 |
| `device_token` | 成功绑定后获得的上传令牌 |
| `link_state` | `ready` / `claimed` / `bound` 等状态 |

屏幕必须只显示 NVS 中当前保存的 `device_code`。不得在每次开机、联网或刷新界面时重新随机生成一个只存在于 RAM 中的设备码。

### 3.2 状态机

```text
首次初始化
  └─ NVS 没有设备码：生成码 v1，写入 NVS
       ↓
联网/重连
  └─ register(当前 NVS device_code + code_version)
       ↓
ready：屏幕显示已登记的 NVS 设备码，轮询 device_status
       ↓
网页 claim 成功
  └─ device_status => claimed + rotate_required=true
       ↓
生成新码（仅存 RAM）+ version + 1
  └─ rotate_code 成功后，才将新码与版本写入 NVS
       ↓
activate 获取 device_token 并写入 NVS
       ↓
bound：进入正常图片上传流程
```

## 4. 固件必须实现的调用规则

服务地址：

```text
http://8.138.230.100:667/api/device_link.php
```

生产环境应改为 HTTPS 域名。

### 4.1 每次联网成功、Wi-Fi 重连后都调用 `register`

请求：

```http
POST /api/device_link.php?action=register
Content-Type: application/json
```

请求体必须取自 NVS，而不是临时生成的变量：

```json
{
  "device_uid": "CJ-P4-<稳定硬件标识>",
  "device_code": "CJ-<NVS保存的当前设备码>",
  "device_secret": "仅首次登记/兼容接口需要，禁止日志输出",
  "code_version": 2,
  "firmware_version": "当前固件版本"
}
```

成功后记录 HTTP 状态和 `ok/status/code_version`，但不要记录密钥。

### 4.2 `ready` 状态轮询 `device_status`

```http
POST /api/device_link.php?action=device_status
Content-Type: application/json
X-Device-Secret: <device_secret>
```

```json
{
  "device_uid": "CJ-P4-<稳定硬件标识>"
}
```

建议间隔 5–10 秒。该接口成功只表示设备身份和在线状态正常，不能替代 `register`。

### 4.3 网页确认后更新码：必须使用 `rotate_code`

当响应为：

```json
{
  "status": "claimed",
  "rotate_required": true,
  "code_version": 2
}
```

必须按以下顺序处理：

1. 以硬件随机源生成 `new_code`，先只保存在 RAM。
2. 令 `new_version = nvs.code_version + 1`。
3. 调用 `rotate_code(new_code, new_version)`。
4. 仅在服务器返回成功后，原子写入 NVS：`device_code=new_code`、`code_version=new_version`。
5. 调用 `activate` 获取上传令牌。
6. 上传令牌写入 NVS 成功后才显示“绑定成功”。

请求示例：

```http
POST /api/device_link.php?action=rotate_code
Content-Type: application/json
X-Device-Secret: <device_secret>
```

```json
{
  "device_uid": "CJ-P4-<稳定硬件标识>",
  "device_code": "CJ-新生成的设备码",
  "code_version": 3
}
```

网络失败、超时或服务端拒绝时，禁止覆盖 NVS 中的旧设备码和旧版本；应显示“设备码同步失败，正在重试”。

## 5. 推荐伪代码

```cpp
void ensureDeviceIdentity() {
  if (!nvs.has("device_uid")) {
    nvs.putString("device_uid", makeUidFromEfuseMac());
  }
  if (!nvs.has("device_secret")) {
    nvs.putString("device_secret", makeRandomSecret());
  }
  if (!nvs.has("device_code")) {
    nvs.putString("device_code", makePairingCode());
    nvs.putUInt("code_version", 1);
  }
}

void onNetworkConnected() {
  ensureDeviceIdentity();
  // 只读取 NVS；不得在这里重新生成屏幕设备码。
  registerDevice(
    nvs.getString("device_uid"),
    nvs.getString("device_code"),
    nvs.getString("device_secret"),
    nvs.getUInt("code_version")
  );
  showPairingCode(nvs.getString("device_code"));
}

void onClaimedByWeb() {
  String oldCode = nvs.getString("device_code");
  uint32_t oldVersion = nvs.getUInt("code_version");
  String newCode = makePairingCode();
  uint32_t newVersion = oldVersion + 1;

  if (!rotateCodeOnServer(newCode, newVersion)) {
    showMessage("设备码同步失败，正在重试");
    return; // NVS 保持 oldCode / oldVersion
  }

  nvs.putString("device_code", newCode);
  nvs.putUInt("code_version", newVersion);

  String token = activateAndGetToken();
  if (!token.isEmpty()) {
    nvs.putString("device_token", token);
    nvs.putString("link_state", "bound");
    showMessage("绑定成功");
  }
}
```

实际代码需要根据 Arduino/ESP-IDF 的 HTTP 与 NVS 封装调整。关键原则是：**先服务器成功，再写入新码；屏幕始终显示 NVS 已保存的码。**

## 6. 本次设备的临时恢复方式

在当前服务器数据库中，设备 UID 对应记录为 `ready`，版本为 2。一次性恢复可以由管理员把“设备屏幕当前显示的码”的哈希同步到这条记录；同步时不要修改 `code_version`。

随后网页输入该设备码，ESP32 将收到 `claimed + rotate_required=true`，按正常流程生成版本 3 的新码并激活。

这只是历史不同步的恢复手段，不应代替固件修复。若固件仍会生成未登记的新码，问题会再次出现。

## 7. 固件验收清单

- [ ] 首次启动只生成一次设备码 v1，断电重启后屏幕码不变。
- [ ] Wi-Fi 重连后，发送的 `register` 与屏幕显示、NVS 保存的码完全一致。
- [ ] `device_status` 成功不被误判为“设备码已经同步”。
- [ ] 硬件端手动更新设备码时，版本号严格加 1，并通过 `rotate_code` 成功后再写 NVS。
- [ ] 网页绑定后，设备能依次完成 `rotate_code`、`activate`，最终进入 `bound`。
- [ ] 任意网络中断、重启、断电后，屏幕码、NVS 码、服务端登记码不会产生新旧混用。
- [ ] 串口日志不输出 `device_secret`、`device_token` 或完整请求头。

## 8. 服务端改进建议

为便于后续诊断，建议服务端的 `register` 响应增加：

```json
{
  "ok": true,
  "status": "ready",
  "server_code_version": 2,
  "code_accepted": true
}
```

当设备提交的 `code_version` 与服务器相同但码哈希不同，应返回明确的同步冲突信息，而不是仅更新时间。这样固件能够发现“屏幕码与服务端登记码不同步”，避免表面在线、实际无法绑定。
