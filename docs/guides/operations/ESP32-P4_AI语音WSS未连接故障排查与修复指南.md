# ESP32-P4 AI 语音 WSS 未连接故障排查与修复指南

## 1. 当前故障结论

当前服务器已经确认以下环节正常：

1. ESP32-P4 能通过 HTTPS 请求设备语音会话接口。
2. `X-Device-Token` 验证成功。
3. 服务端能够创建独立的设备语音会话。
4. 服务端能够返回 HTTP 200、短期 ticket 和 Opus 协议参数。
5. 公网域名、SSL 证书、Nginx 反向代理和 Python AI 网关工作正常。
6. 从服务器执行下面的请求时，可以到达 AI 网关：

```bash
curl -i https://wwwxsh.cn/ai-gateway/device/
```

服务器正确返回：

```text
HTTP/2 401
Invalid or expired AI ticket
```

AI 网关同时能够记录：

```text
Device gateway request received: ticket_present=no ticket_length=0
Device gateway rejected: invalid_or_expired_ticket
```

但是，在 ESP32-P4 上按住按钮录音、松手后，AI 网关没有出现任何新的：

```text
Device gateway request received
```

因此当前问题已经缩小到：

```text
ESP32调用session成功
        ↓
解析session响应
        ↓
取得ticket
        ↓
构造WSS地址
        ↓
启动TLS WebSocket连接
        ↓
向/ai-gateway/device/发起握手
```

故障发生在上述流程中，尚未到达服务端 WebSocket 应用层。

网页出现一条新的“设备对话记录”，只说明 `session` 请求创建了会话，不能证明：

- WebSocket 已连接；
- Opus 已上传；
- 百炼已经收到音频；
- ASR 已经生成文字；
- `save_message` 已经执行。

因此页面显示“这次设备会话尚未产生可保存的文字记录”符合当前实际状态。

---

## 2. 最可能的问题

### 2.1 session JSON 解析失败

这是当前优先检查的问题。

服务端现在返回单层 JSON，格式如下：

```json
{
  "ok": true,
  "ticket": "<短期票据>",
  "ticket_expires_at": 1753420000,
  "gateway_path": "/ai-gateway/device/",
  "conversation_id": "20260725123456abcdef1234",
  "input_format": "opus",
  "input_sample_rate": 16000,
  "input_channels": 1,
  "input_frame_duration_ms": 60,
  "input_bitrate": 24000,
  "output_format": "opus",
  "output_sample_rate": 24000,
  "output_channels": 1,
  "output_frame_duration_ms": 20
}
```

固件必须从顶层读取：

```cpp
doc["ticket"]
doc["gateway_path"]
doc["conversation_id"]
doc["input_format"]
```

不能继续使用旧结构：

```cpp
doc["gateway"]["ticket"]
doc["audio"]["input_format"]
```

如果仍使用旧结构，`ticket` 会是空字符串，设备不会建立有效 WSS。

### 2.2 JSON 缓冲区不足

早期接口响应曾达到约 2010 字节。当前服务端已删除重复字段，但固件仍应检查 JSON 文档容量。

如果使用 ArduinoJson，建议调试阶段至少使用：

```cpp
DynamicJsonDocument doc(4096);
```

并且必须检查解析结果：

```cpp
DeserializationError error = deserializeJson(doc, responseBody);
if (error) {
    ESP_LOGE(TAG, "AI session JSON parse failed: %s", error.c_str());
    return;
}
```

禁止忽略 `deserializeJson()` 的返回值。

### 2.3 固件仍读取旧字段

当前 ticket 位于：

```cpp
doc["ticket"]
```

如果代码是：

```cpp
doc["gateway"]["ticket"]
```

需要改为：

```cpp
const char* ticketValue = doc["ticket"] | "";
String ticket = String(ticketValue);
```

读取后必须检查：

```cpp
if (ticket.length() == 0) {
    ESP_LOGE(TAG, "AI session response has no ticket");
    return;
}
```

### 2.4 WSS 地址错误

正确地址必须为：

```text
wss://wwwxsh.cn/ai-gateway/device/?ticket=<ticket>
```

固定参数为：

| 项目 | 正确值 |
|---|---|
| 协议 | `wss` |
| 域名 | `wwwxsh.cn` |
| 端口 | `443` |
| 路径 | `/ai-gateway/device/` |
| 查询参数 | `ticket` |

以下地址都是错误的：

```text
wss://wwwxsh.cn:667/ai-gateway/device/
ws://wwwxsh.cn/ai-gateway/device/
wss://8.138.230.100/ai-gateway/device/
wss://wwwxsh.cn/ai-gateway/
wss://wwwxsh.cn/api/ai-gateway/device/
```

不能再使用旧端口 `667`。公网正式入口统一为标准 HTTPS/WSS 端口 443。

### 2.5 只申请 session，没有启动 WebSocket

必须确认固件在成功解析 session 后真正执行了：

```cpp
websocket.beginSSL(...);
```

或者：

```cpp
esp_websocket_client_start(client);
```

不能只保存 ticket 或切换界面状态。

WebSocket 启动函数的返回值必须检查，不能直接忽略。

### 2.6 用户松手时仍处于 PREPARING，固件取消连接

当前交互为“按住说话、松开提交”。网络流程可能需要：

1. HTTPS 请求 session；
2. JSON 解析；
3. DNS；
4. TLS；
5. WebSocket 握手；
6. 等待 `gateway.ready`。

如果用户只按住一两秒，松手时连接可能仍在 `PREPARING`。

如果当前代码在松手时执行：

```cpp
if (state == PREPARING) {
    cancelRequest();
    closeWebSocket();
    state = IDLE;
}
```

就会产生当前现象：

- session 已创建；
- 网页出现空会话；
- WSS 从未连接；
- 网关没有任何硬件请求日志。

调试时先按住 8–10 秒再松手。

正式修复时，建议在 `PREPARING` 状态设置 `release_pending=true`，而不是立即取消：

```cpp
void onTalkReleased()
{
    if (state == RECORDING) {
        stopMicrophone();
        sendCommit();
        return;
    }

    if (state == PREPARING) {
        releasePending = true;
        return;
    }
}
```

WebSocket ready 后再处理：

```cpp
void onGatewayReady()
{
    state = RECORDING;
    flushPreRecordBuffer();

    if (releasePending) {
        stopMicrophone();
        sendCommit();
    }
}
```

更完整的方案是在按下按钮后立即把麦克风 PCM/Opus 写入短时环形缓冲，收到 `gateway.ready` 后再上传，避免丢失开头声音。

### 2.7 WebSocket TLS 配置缺失

虽然 HTTPS session 已成功，但 HTTP 客户端和 WebSocket 客户端可能使用不同的 TLS 配置。

需要分别确认 WebSocket 客户端：

- 已设置可信 CA；
- 使用域名 `wwwxsh.cn`，不能直接用 IP；
- SNI 已启用；
- 设备系统时间正确；
- 没有使用已过期的旧证书；
- 端口为 443。

设备启动后应打印当前时间：

```cpp
time_t now;
time(&now);
ESP_LOGI(TAG, "Current Unix time: %lld", (long long)now);
```

如果时间仍接近 1970 年，TLS 证书校验通常会失败。

### 2.8 WebSocket 错误被固件忽略

必须注册连接、断开和错误事件。

如果使用 ESP-IDF：

```cpp
esp_websocket_register_events(
    client,
    WEBSOCKET_EVENT_ANY,
    websocket_event_handler,
    NULL
);
```

不能只监听 `WEBSOCKET_EVENT_DATA`。

---

## 3. 必须增加的硬件日志

日志不得输出完整 ticket、设备 token、API Key 或完整语音内容。

建议加入：

```cpp
ESP_LOGI(TAG, "AI session request start");
ESP_LOGI(TAG, "AI session HTTP status=%d", httpStatus);
ESP_LOGI(TAG, "AI session response bytes=%u", responseLength);
ESP_LOGI(TAG, "AI session JSON parse=%s", parseOk ? "ok" : "failed");
ESP_LOGI(TAG, "AI session ok=%s", sessionOk ? "true" : "false");
ESP_LOGI(TAG, "AI ticket present=%s", ticketLength > 0 ? "yes" : "no");
ESP_LOGI(TAG, "AI ticket length=%u", ticketLength);
ESP_LOGI(TAG, "AI gateway path=%s", gatewayPath);
ESP_LOGI(TAG, "AI input format=%s", inputFormat);
ESP_LOGI(TAG, "AI websocket host=wwwxsh.cn");
ESP_LOGI(TAG, "AI websocket port=443");
ESP_LOGI(TAG, "AI websocket path=/ai-gateway/device/?ticket=[REDACTED]");
ESP_LOGI(TAG, "AI websocket start result=%s", esp_err_to_name(result));
ESP_LOGI(TAG, "AI websocket connected");
ESP_LOGI(TAG, "AI websocket disconnected");
ESP_LOGI(TAG, "AI websocket error code=%d", errorCode);
ESP_LOGI(TAG, "AI gateway.ready received");
ESP_LOGI(TAG, "AI Opus packets sent=%u", packetCount);
ESP_LOGI(TAG, "AI control.commit sent");
```

如果使用 ArduinoJson，还应输出：

```cpp
ESP_LOGI(TAG, "JSON memory usage=%u", doc.memoryUsage());
ESP_LOGI(TAG, "JSON capacity=%u", doc.capacity());
```

只能打印 ticket 长度，不能打印 ticket 内容。

---

## 4. session 响应解析参考代码

以下为 ArduinoJson 风格示例：

```cpp
struct AiVoiceSession {
    bool ok = false;
    String ticket;
    String gatewayPath;
    String conversationId;
    String inputFormat;
    int inputSampleRate = 0;
    int inputChannels = 0;
    int inputFrameDurationMs = 0;
};

bool parseAiSessionResponse(
    const String& responseBody,
    AiVoiceSession& session
) {
    DynamicJsonDocument doc(4096);

    DeserializationError error =
        deserializeJson(doc, responseBody);

    if (error) {
        ESP_LOGE(
            TAG,
            "AI JSON parse failed: %s, bytes=%u",
            error.c_str(),
            responseBody.length()
        );
        return false;
    }

    session.ok = doc["ok"] | false;
    session.ticket =
        String((const char*)(doc["ticket"] | ""));
    session.gatewayPath =
        String((const char*)(doc["gateway_path"] |
            "/ai-gateway/device/"));
    session.conversationId =
        String((const char*)(doc["conversation_id"] | ""));
    session.inputFormat =
        String((const char*)(doc["input_format"] | ""));
    session.inputSampleRate =
        doc["input_sample_rate"] | 0;
    session.inputChannels =
        doc["input_channels"] | 0;
    session.inputFrameDurationMs =
        doc["input_frame_duration_ms"] | 0;

    ESP_LOGI(
        TAG,
        "AI session parsed: ok=%d ticket_present=%d "
        "ticket_length=%u input=%s/%d/%d",
        session.ok,
        !session.ticket.isEmpty(),
        session.ticket.length(),
        session.inputFormat.c_str(),
        session.inputSampleRate,
        session.inputChannels
    );

    if (!session.ok) {
        ESP_LOGE(TAG, "AI session returned ok=false");
        return false;
    }

    if (session.ticket.isEmpty()) {
        ESP_LOGE(TAG, "AI ticket is empty");
        return false;
    }

    if (session.inputFormat != "opus") {
        ESP_LOGE(
            TAG,
            "Unsupported input format: %s",
            session.inputFormat.c_str()
        );
        return false;
    }

    if (
        session.inputSampleRate != 16000 ||
        session.inputChannels != 1
    ) {
        ESP_LOGE(TAG, "Unexpected audio parameters");
        return false;
    }

    return true;
}
```

注意：调试时不要把整个 `responseBody` 打印到串口，因为其中包含短期 ticket。

---

## 5. WebSocket 地址构造

当前 ticket 使用 URL 安全字符，但仍建议通过可靠的 URL 编码函数处理。

```cpp
String buildAiWebSocketPath(
    const String& gatewayPath,
    const String& ticket
) {
    String basePath = gatewayPath;

    if (basePath.isEmpty()) {
        basePath = "/ai-gateway/device/";
    }

    if (!basePath.endsWith("/")) {
        basePath += "/";
    }

    return basePath + "?ticket=" + urlEncode(ticket);
}
```

构造后只打印脱敏路径：

```cpp
ESP_LOGI(
    TAG,
    "AI WSS path=%s?ticket=[REDACTED]",
    session.gatewayPath.c_str()
);
```

---

## 6. ESP-IDF `esp_websocket_client` 参考

```cpp
String encodedTicket = urlEncode(session.ticket);
String fullUri =
    "wss://wwwxsh.cn/ai-gateway/device/?ticket=" +
    encodedTicket;

esp_websocket_client_config_t config = {};
config.uri = fullUri.c_str();
config.cert_pem = rootCaPem;
config.network_timeout_ms = 10000;
config.reconnect_timeout_ms = 3000;
config.disable_auto_reconnect = true;

esp_websocket_client_handle_t client =
    esp_websocket_client_init(&config);

if (client == NULL) {
    ESP_LOGE(TAG, "esp_websocket_client_init failed");
    return;
}

esp_websocket_register_events(
    client,
    WEBSOCKET_EVENT_ANY,
    websocket_event_handler,
    NULL
);

esp_err_t result =
    esp_websocket_client_start(client);

ESP_LOGI(
    TAG,
    "AI websocket start=%s",
    esp_err_to_name(result)
);

if (result != ESP_OK) {
    esp_websocket_client_destroy(client);
    return;
}
```

必须保证 `fullUri` 的存储生命周期足够长。某些 SDK 版本的客户端配置不会立即复制字符串，如果 `fullUri` 是函数内临时变量，函数返回后可能成为悬空指针。

建议把 URI 保存到长生命周期对象：

```cpp
static String aiWebSocketUri;
aiWebSocketUri =
    "wss://wwwxsh.cn/ai-gateway/device/?ticket=" +
    encodedTicket;
config.uri = aiWebSocketUri.c_str();
```

---

## 7. ESP-IDF WebSocket 事件参考

```cpp
static void websocket_event_handler(
    void* handlerArgs,
    esp_event_base_t base,
    int32_t eventId,
    void* eventData
) {
    esp_websocket_event_data_t* data =
        (esp_websocket_event_data_t*)eventData;

    switch (eventId) {
        case WEBSOCKET_EVENT_CONNECTED:
            ESP_LOGI(TAG, "AI websocket connected");
            break;

        case WEBSOCKET_EVENT_DISCONNECTED:
            ESP_LOGW(TAG, "AI websocket disconnected");
            break;

        case WEBSOCKET_EVENT_DATA:
            if (data->op_code == 0x1) {
                handleAiTextEvent(
                    data->data_ptr,
                    data->data_len
                );
            } else if (data->op_code == 0x2) {
                handleAiOpusOutput(
                    (const uint8_t*)data->data_ptr,
                    data->data_len
                );
            }
            break;

        case WEBSOCKET_EVENT_ERROR:
            ESP_LOGE(TAG, "AI websocket error");
            break;
    }
}
```

收到下面的服务端事件后才能开始上传：

```json
{
  "type": "gateway.ready",
  "client_kind": "device",
  "input": {
    "format": "opus",
    "sample_rate": 16000,
    "channels": 1,
    "frame_duration_ms": 60,
    "bitrate": 24000
  }
}
```

固件收到 `gateway.ready` 后应打印：

```text
AI gateway.ready received
```

---

## 8. Opus 上传要求

每个 WebSocket binary message 必须是一个完整原始 Opus packet：

| 参数 | 要求 |
|---|---|
| 输入 PCM | 16 kHz、单声道、16-bit |
| Opus 模式 | VOIP |
| 帧长 | 60 ms |
| 码率 | 24 kbps VBR |
| 容器 | 无 |
| WebSocket 边界 | 一个 binary message 对应一个 Opus packet |
| packet 长度 | 1–1275 字节 |

不能发送：

- PCM；
- WAV；
- Ogg Opus；
- MP4；
- RTP；
- Base64 文本；
- 多个 Opus packet 拼在一个 binary message 中。

发送示例：

```cpp
int sent = esp_websocket_client_send_bin(
    client,
    (const char*)opusPacket,
    opusPacketLength,
    pdMS_TO_TICKS(1000)
);

if (sent <= 0) {
    ESP_LOGE(TAG, "Opus packet send failed: %d", sent);
} else {
    packetCount++;
}
```

---

## 9. 松手提交

所有 Opus packet 发送完后，发送：

```json
{"type":"control.commit"}
```

示例：

```cpp
static const char* commitMessage =
    "{\"type\":\"control.commit\"}";

int sent = esp_websocket_client_send_text(
    client,
    commitMessage,
    strlen(commitMessage),
    pdMS_TO_TICKS(1000)
);

ESP_LOGI(
    TAG,
    "AI control.commit sent=%d packets=%u",
    sent,
    packetCount
);
```

不能在最后一个 Opus packet 尚未发送完成时提前 commit。

---

## 10. 推荐状态机

```text
IDLE
  │
  ├─ 触摸按下
  ▼
REQUESTING_SESSION
  │
  ├─ HTTPS 200 + JSON解析成功 + ticket非空
  ▼
CONNECTING_WSS
  │
  ├─ WebSocket connected
  │
  ├─ 收到 gateway.ready
  ▼
RECORDING
  │
  ├─ 上传一个个完整Opus packet
  │
  ├─ 触摸松开
  ▼
COMMITTING
  │
  ├─ 发送control.commit
  ▼
WAITING_RESPONSE
  │
  ├─ 收到response.done
  ▼
IDLE
```

任何错误都必须：

1. 打印错误阶段和错误码；
2. 停止麦克风；
3. 关闭并销毁 WebSocket；
4. 清空当前短期 ticket；
5. 返回 IDLE；
6. 下次操作重新请求 session；
7. 禁止复用旧 ticket。

---

## 11. 服务端日志与硬件行为对照

服务器执行：

```bash
journalctl -u chijing-ai-gateway.service -f
```

### 情况 A：没有任何新日志

固件没有到达 WSS HTTP 握手。

检查：

- session JSON 解析；
- ticket 长度；
- WSS start 是否调用；
- URI 生命周期；
- TLS；
- 域名、端口、路径；
- 松手时是否取消 PREPARING。

### 情况 B：没有 ticket

服务端：

```text
Device gateway request received:
ticket_present=no
ticket_length=0
```

修复 ticket 解析和 URL 拼接。

### 情况 C：ticket 无效

服务端：

```text
Device gateway rejected: invalid_or_expired_ticket
```

检查：

- ticket 是否被截断；
- 是否错误转义；
- 是否等待超过 60 秒；
- 是否取得顶层 `ticket`；
- ticket 长度在解析前后是否一致。

### 情况 D：ticket 被重复使用

服务端：

```text
Device gateway rejected: ticket_already_used
```

每次 WSS 重连前重新请求 session。

### 情况 E：连接成功但没有音频

服务端：

```text
Device gateway connected
Device gateway ready
```

但没有：

```text
Device first Opus packet decoded
```

检查录音任务、Opus 队列和 binary send。

### 情况 F：Opus 解码失败

服务端：

```text
decode_failed
```

检查：

- 是否上传了 Ogg；
- 是否误传 PCM；
- packet 是否被拼接；
- packet 是否被拆分；
- 编码采样率是否为 16 kHz；
- 是否每帧 60 ms。

### 情况 G：音频链路成功

服务端应依次出现：

```text
Device gateway connected
Device gateway ready
Device first Opus packet decoded
Device audio capture started
Device audio committed
Device audio capture saved
Device gateway disconnected
```

此时服务器会生成：

```text
/www/wwwroot/chijing_storage/ai_audio_debug/YYYYMMDD/*.wav
```

下载 WAV 后可直接人工检查麦克风声音。

---

## 12. 本轮硬件验收标准

硬件开发完成后应提供以下脱敏串口日志：

```text
AI session HTTP status=200
AI session JSON parse=ok
AI session ok=true
AI ticket present=yes
AI ticket length=<数字>
AI input format=opus
AI websocket start result=ESP_OK
AI websocket connected
AI gateway.ready received
AI Opus packets sent=<大于0>
AI control.commit sent
```

服务器应对应出现：

```text
Device gateway request received
Device gateway connected
Device gateway ready
Device first Opus packet decoded
Device audio capture saved
```

在这些日志出现之前，不需要继续排查：

- 数据库消息表；
- 百炼 ASR；
- 文字保存；
- 下行语音播放；
- 网页设备消息展示。

这些都位于当前故障点之后。

---

## 13. 安全要求

任何串口、服务器日志、截图和问题文档中都不得出现：

- 完整 `X-Device-Token`；
- 完整 AI ticket；
- 百炼 API Key；
- `AI_GATEWAY_SECRET`；
- 数据库密码。

允许记录：

- ticket 是否存在；
- ticket 长度；
- HTTP 状态码；
- WebSocket 错误码；
- Opus packet 数量与字节数；
- conversation ID；
-脱敏后的路径。

