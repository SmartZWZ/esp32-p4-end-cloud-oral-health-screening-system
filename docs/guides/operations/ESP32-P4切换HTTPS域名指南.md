# ESP32-P4 切换至 HTTPS 域名指南

## 适用范围

齿镜网站已从旧测试地址：

```text
http://8.138.230.100:667
```

迁移到正式地址：

```text
https://wwwxsh.cn
```

ESP32-P4 固件必须同步修改。**不要在新地址后继续添加 `:667`**；HTTPS 使用标准端口 `443`，URL 中省略端口即可。

本次仅修改网络传输方式和基础地址，不改变设备码、`device_secret`、`device_token`、成员数据和已绑定关系。

---

## 1. 必须替换的基础配置

在固件中搜索以下旧内容，并全部替换：

```text
8.138.230.100
http://8.138.230.100:667
Host: 8.138.230.100:667
:667
```

推荐集中定义为：

```cpp
static const char* API_SCHEME = "https";
static const char* API_HOST = "wwwxsh.cn";
static const uint16_t API_PORT = 443;
static const char* API_BASE_URL = "https://wwwxsh.cn";
```

之后所有接口都以 `API_BASE_URL` 拼接。例如：

```cpp
String url = String(API_BASE_URL) + "/api/device_link.php?action=device_status";
```

正确地址示例：

| 功能 | 新地址 |
| --- | --- |
| 设备首次登记 | `https://wwwxsh.cn/api/device_link.php?action=register` |
| 查询绑定状态 | `https://wwwxsh.cn/api/device_link.php?action=device_status` |
| 更新设备码 | `https://wwwxsh.cn/api/device_link.php?action=rotate_code` |
| 激活并获取 Token | `https://wwwxsh.cn/api/device_link.php?action=activate` |
| 拉取成员 | `https://wwwxsh.cn/api/members.php?action=device_list` |
| 推送成员修改 | `https://wwwxsh.cn/api/members.php?action=device_sync` |
| JPEG 上传保存/检测 | `https://wwwxsh.cn/api/process.php` |

请求方法、JSON 请求体和原有请求头保持不变，例如：

```http
X-Device-Secret: <device_secret>
X-Device-Token: <device_token>
X-Member-Id: <member_public_id>
X-Upload-Mode: archive 或 detect
```

如果固件自己拼接原始 HTTP 报文，必须同时改为：

```http
Host: wwwxsh.cn
```

连接目标必须是 `wwwxsh.cn:443`，不能先连接 IP 再填写域名 Host；否则 TLS 证书的域名校验/SNI 可能失败。

---

## 2. 从 HTTP 客户端切换到 HTTPS 客户端

旧版 HTTP 常见写法：

```cpp
WiFiClient client;
HTTPClient http;
http.begin(client, "http://8.138.230.100:667/api/process.php");
```

正式版应改为 TLS 客户端：

```cpp
#include <WiFiClientSecure.h>
#include <HTTPClient.h>

WiFiClientSecure client;
HTTPClient http;

client.setCACert(kLetsEncryptRootCA); // 见第 3 节
http.begin(client, "https://wwwxsh.cn/api/process.php");
```

每次发起请求前确保 Wi-Fi 已连接；请求结束后调用：

```cpp
http.end();
```

设备若使用手写 socket：

```cpp
WiFiClientSecure client;
client.setCACert(kLetsEncryptRootCA);
if (!client.connect("wwwxsh.cn", 443)) {
  // 连接失败：进入退避重试，不要清除 NVS 中的 Token
}
```

---

## 3. 正确校验证书（正式环境必须做）

网站使用 Let's Encrypt 证书。固件应信任签发链的**根 CA**，而不是把当前网站的叶子证书写死。

原因：网站证书会自动续期；若硬编码叶子证书，续期后 ESP32 会突然无法联网。根 CA 通常在多年内有效，续期无需修改固件。

### Arduino-ESP32

将 Let's Encrypt 的根证书 PEM 保存为常量（实际使用时从官方根证书来源获取完整 PEM）：

```cpp
static const char kLetsEncryptRootCA[] PROGMEM = R"EOF(
-----BEGIN CERTIFICATE-----
... 这里放 Let's Encrypt 信任链对应的根 CA PEM ...
-----END CERTIFICATE-----
)EOF";
```

然后在每个 `WiFiClientSecure` 使用前调用：

```cpp
client.setCACert(kLetsEncryptRootCA);
```

### ESP-IDF

推荐启用 ESP-IDF 的证书包，而非自行内嵌 PEM：

1. 在 `menuconfig` 启用 `CONFIG_MBEDTLS_CERTIFICATE_BUNDLE`；
2. 配置 `esp_http_client` 时设置：

```c
#include "esp_crt_bundle.h"

esp_http_client_config_t config = {
    .url = "https://wwwxsh.cn/api/process.php",
    .crt_bundle_attach = esp_crt_bundle_attach,
};
```

### 仅用于短暂排障：跳过校验

```cpp
client.setInsecure();
```

这只能用于确认“问题是否来自证书校验”，**不能作为正式发布版本**。它会失去服务器身份校验，口腔图片、设备密钥和上传 Token 有被中间人截获的风险。

---

## 4. TLS 前必须同步系统时间

TLS 会检查证书有效期。ESP32-P4 若上电后时间仍是 1970 年，可能报证书过期、未生效或握手失败。

Wi-Fi 连接成功后、首次 HTTPS 请求前执行 NTP 同步：

```cpp
#include <time.h>

configTime(8 * 3600, 0, "ntp.aliyun.com", "ntp.ntsc.ac.cn", "pool.ntp.org");

tm timeinfo;
bool timeReady = getLocalTime(&timeinfo, 15000);
if (!timeReady || timeinfo.tm_year + 1900 < 2024) {
  // 不进行正式 HTTPS 上传；提示“正在校准时间”，稍后指数退避重试
}
```

若使用 ESP-IDF，请使用 `esp_sntp` 进行等价的 SNTP 时间同步。

---

## 5. 上传 JPEG 的 HTTPS 示例

以下保留原有认证和成员归档头，只替换连接方式与 URL：

```cpp
bool uploadJpeg(const uint8_t* jpeg, size_t jpegLen,
                const String& deviceToken, const String& memberId,
                const char* uploadMode) {
  WiFiClientSecure client;
  client.setCACert(kLetsEncryptRootCA);

  HTTPClient http;
  if (!http.begin(client, "https://wwwxsh.cn/api/process.php")) return false;

  http.addHeader("Content-Type", "image/jpeg");
  http.addHeader("X-Device-Token", deviceToken);
  http.addHeader("X-Member-Id", memberId);
  http.addHeader("X-Upload-Mode", uploadMode); // "archive" 或 "detect"

  const int code = http.POST(jpeg, jpegLen);
  const String body = http.getString();
  http.end();

  if (code == 201) {
    // 解析 {"ok":true,...} 后才标记本地图片上传完成
    return true;
  }
  if (code == 401) {
    // Token 已失效/设备解绑；停止自动上传并提示网页重新绑定
  }
  // 其余错误进入指数退避；不要因为一次失败删除本地 JPEG 或 NVS Token
  return false;
}
```

若使用 `HTTPClient::POST(uint8_t*, size_t)` 的核心版本不兼容，保留现有 JPEG 发送逻辑即可；关键是 `WiFiClientSecure`、CA 校验、HTTPS URL 和 443 端口必须到位。

---

## 6. 绑定与成员同步的改动原则

以下业务字段不改：

- NVS：`device_uid`、`device_code`、`device_secret`、`code_version`、`device_token`；
- 设备码的登记、网页确认、`rotate_code`、`activate` 状态机；
- 成员列表 `public_id`、`sync_version`、`operation_id`；
- 图片上传模式 `archive` 和 `detect`。

只改：

1. URL 主机名改为 `wwwxsh.cn`；
2. 端口改为 `443` 或 URL 中省略端口；
3. `WiFiClient` 改为 `WiFiClientSecure` / ESP-IDF TLS；
4. 配置根 CA 与 NTP 时间同步。

设备之前已绑定也无需重新输入设备码；升级固件后，应自动使用既有 NVS Token 连接新域名。

---

## 7. 推荐发布顺序

1. 在电脑浏览器打开 `https://wwwxsh.cn/`，确认网页可访问且证书正常。
2. 确认阿里云安全组入方向已放行 TCP `443`；保留 TCP `80` 供证书续期验证使用。
3. 先烧录一台测试 ESP32-P4，使用 `client.setInsecure()` 临时验证接口 URL 可通。
4. 临时验证完成后，替换为根 CA 校验并完成 NTP 同步。
5. 验证 `device_status` 返回正常，再验证成员拉取、`archive` 上传、`detect` 上传。
6. 确认无误后再给其余设备升级。

不要让旧固件和新固件交替使用同一台设备反复绑定；旧固件仍请求 `http://8.138.230.100:667`，会造成排障混乱。

---

## 8. 联调与故障判断

| 现象 | 常见原因 | 处理 |
| --- | --- | --- |
| `connection refused` / 超时 | 443 未在阿里云安全组或系统防火墙放行；Wi-Fi 无公网 | 放行 TCP 443，检查路由和 Wi-Fi。 |
| TLS handshake failed | 使用了 `WiFiClient`；设备时间错误；CA 不正确 | 切换 `WiFiClientSecure`，先同步 NTP，再检查根 CA。 |
| certificate verify failed | 根 CA 过旧、填入了叶子证书、时间未同步 | 使用 ESP-IDF 证书包或更新根 CA；不要固化叶子证书。 |
| `401` | 设备 Token 失效或已解绑 | 保留日志但不输出 Token；在网页重新绑定。 |
| `404` | URL 漏掉 `/api/`、仍携带错误端口或接口 action 写错 | 逐字核对第 1 节 URL。 |
| `ERR_SSL_PROTOCOL_ERROR`（浏览器） | 访问了 `https://wwwxsh.cn:667` | 浏览器和设备均改为 `https://wwwxsh.cn`，不带 `:667`。 |

## 9. 本次不需要修改的部分

- ESP32-P4 的语音 WebSocket / AI 助手功能尚未接入，当前不需要在硬件端修改；
- 本地 RTX 模型工作机、Tailscale 和模型回调不是 ESP32 的 HTTPS 迁移范围；
- 数据库表和已有照片不需要迁移。
