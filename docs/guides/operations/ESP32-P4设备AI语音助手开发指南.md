# ESP32-P4 设备 AI 语音助手开发指南（按住说话 / Opus 版）

本文给 ESP32-P4 固件开发使用。目标是：用户在屏幕上按住一个按钮录音，松开后由云端 AI 生成语音回答；设备播放回答并把文字记录保存到该设备专属记录页。

## 1. 范围与非目标

本期实现：

- 触屏按住录音、松开提交。
- 上行、下行均使用原始 Opus packet；PCM 只在 I2S 与 Opus 编解码器之间使用。
- 用户语音转写与助手文字回复的设备端留档。
- HTTPS 短期票据 + WSS 实时语音连接。

本期明确不实现：

- 不控制摄像头、补光、显示亮度、拍照、上传图片等任何硬件功能。
- 不实现 MCP、Function Calling 或模型指令到硬件动作的映射。
- 不在设备内保存百炼 API Key、AI 网关密钥或数据库信息。

## 2. 固件需要保留的现有凭据

设备完成既有绑定后，NVS 中应已有以下信息：

```text
device_uid             设备唯一编号
device_token           现有绑定流程得到的设备令牌
```

`device_token` 仅用于 HTTPS 请求头 `X-Device-Token`。不要把它拼到 URL，也不要打印完整值。

还需具备：

- 已校时（NTP），避免 HTTPS 证书时间校验失败。
- `wwwxsh.cn` 的系统 CA 根证书或 ESP-IDF 证书包。
- Wi-Fi 联网状态检测。

## 3. 屏幕交互状态机

```text
IDLE（空闲）
  └─ 手指按下按钮 → PREPARING（申请票据、连接 WSS）
      ├─ 连接成功 → RECORDING（红色录音态，持续发送 Opus packet）
      ├─ 手指提前松开 → 取消连接，回到 IDLE
      └─ 超时/失败 → 显示错误，回到 IDLE

RECORDING
  └─ 手指松开 → COMMITTING（停止 I2S 采集，发送 control.commit）
      └─ 收到回答音频 → PLAYING（边接收边播放）

PLAYING
  ├─ 回答完成且播放队列清空 → IDLE
  └─ 用户再次按下 → 发送 control.cancel，清空播放队列，进入 PREPARING
```

屏幕按钮建议：

- 空闲：圆角实心按钮，文字“按住说话”。
- 按下：显示“松开提交”，有明显红色/高亮录音状态与录音时长。
- 连接中：显示“正在连接语音服务”，禁止重复打开第二条 WebSocket。
- 回答中：显示“正在回答”；再次按住可打断回答。

不要采用“点击开始、点击停止”的交互；录音是否进行必须由触摸按下/松开事件控制。

## 4. 服务器接口一：申请设备短期票据

每次开始一段新对话，或 WSS 重连时，调用：

```http
POST https://wwwxsh.cn/api/assistant_device.php?action=session
Content-Type: application/json
X-Device-Token: <device_token>

{}
```

若希望在同一会话中继续追问，请把上次保存的 `conversation.public_id` 带回：

```json
{"conversation_id":"20260725123456abcdef1234"}
```

成功响应结构：

```json
{
  "ok": true,
  "ticket":"短期票据",
  "input_format":"opus",
  "input_frame_duration_ms":60,
  "input_bitrate":24000,
  "output_format":"opus",
  "output_frame_duration_ms":20,
  "audio": {
    "input_format":"opus",
    "input_sample_rate":16000,
    "input_channels":1,
    "output_format":"pcm_s16le",
    "output_sample_rate":24000,
    "output_channels":1
  }
}
```

注意：

- `ticket` 仅约 60 秒有效且只能连接一次；每次新连接重新申请，不要放入 NVS。
- 失败 401 表示设备未绑定或 token 无效，应回到既有设备绑定排查流程。
- 新按住操作建议新建一条会话（请求体 `{}`）；若后续要提供“连续追问”，仅在内存中保留最近 `conversation_id` 并主动传入。

## 5. 服务器接口二：建立实时 WebSocket

将响应中的票据 URL 编码后连接：

```text
wss://wwwxsh.cn/ai-gateway/device/?ticket=<url_encode(ticket)>
```

这是设备专用通道，不要连接网页端的 `/ai-gateway/`。

连接成功后会收到：

```json
{"type":"gateway.ready","client_kind":"device","input":{"format":"opus"},"output":{"format":"opus"}}
```

设备端不需要也不允许向百炼直连；所有实时流量都经过本服务的网关。

## 6. 录音数据格式与发送规则

I2S 输入需要提供给 Opus 编码器：

```text
PCM signed 16-bit little endian
单声道
16000 Hz
```

编码器约定：

```text
16 kHz、单声道、16-bit PCM 输入；VOIP 模式、60 ms 一帧、24 kbps VBR。
```

每个 WebSocket 二进制帧承载**一个完整、原始 Opus packet**。不要 base64 编码，不要 JSON 包装，不要加 Ogg、WAV、MP4 或 RTP 头。packet 为 1–1275 字节，不能拼接或拆分。

伪代码：

```cpp
onTalkPressed() {
  state = PREPARING;
  session = postAiSession({});
  ws.connect("wss://wwwxsh.cn/ai-gateway/device/?ticket=" + urlEncode(session.ticket));
}

onWebSocketReady() {
  state = RECORDING;
  startI2sMic();
}

onOpusPacket(const uint8_t* packet, size_t length) {
  if (state == RECORDING && ws.connected()) {
    ws.sendBinary(packet, length);
  }
}

onTalkReleased() {
  if (state != RECORDING) return;
  stopI2sMic();
  state = COMMITTING;
  ws.sendText("{\"type\":\"control.commit\"}");
}
```

松开后只能发送一次 `control.commit`。不要同时发送多个 commit，也不要把空音频提交给服务。

## 7. 接收回答与播放

网关转发百炼的 JSON 事件。重点处理以下事件：

| 事件 | 处理 |
|---|---|
| `response.audio.start` | 按参数创建 24 kHz、单声道、20 ms 的 Opus 解码器。 |
| WebSocket binary message | 每帧是一个完整原始 Opus packet。解码成 PCM16LE 后写入扬声器播放队列。 |
| `response.audio.end` | 停止接收新的回答音频，等待扬声器队列播放完毕。 |
| `conversation.item.input_audio_transcription.completed` | 读取 `transcript`；保存为用户记录。 |
| `response.audio_transcript.done` | 读取 `transcript`；保存为助手记录。 |
| `response.done` | 表示模型回答生成结束；播放队列清空后回到空闲。 |
| `error` | 停止录音/播放并展示安全的错误提示。 |

处理下行 Opus packet 的伪代码：

```cpp
if (wsMessage.isBinary()) {
  pcm = opusDecoder.decode(wsMessage.binaryData);
  speakerQueue.write(pcm);  // 24 kHz, signed 16-bit LE, mono
  state = PLAYING;
}
```

播放必须使用缓冲队列，不能在 WebSocket 回调中直接阻塞 I2S 写入。建议至少缓存 200–500 ms 音频；网络抖动时可以短暂补静音，避免爆音。

## 8. 保存设备专属文字记录

获得一段最终文本后，调用：

```http
POST https://wwwxsh.cn/api/assistant_device.php?action=save_message
Content-Type: application/json
X-Device-Token: <device_token>

{
  "conversation_id":"设备会话 public_id",
  "role":"user",
  "content":"用户最终转写文本"
}
```

助手回复同样调用一次，只将 `role` 改为 `assistant`。

规则：

- 仅保存最终事件，不能保存每个实时增量，避免网页出现大量重复记录。
- 网络失败时可在 RAM 中暂存一条待上传记录并重试 1–3 次；不要无限积压。
- 保存失败不应影响语音播放；界面可显示“本次记录未同步”。

## 9. 打断、断开与重连

### 用户打断回答

用户在 `PLAYING` 状态再次按下按钮时：

1. 发送 `{"type":"control.cancel"}`。
2. 清空本地扬声器队列，停止当前播放。
3. 关闭当前 WSS。
4. 重新申请一张票据，进入新的 `PREPARING`。

### 网络断开

- 录音中断开：停止 I2S 输入、丢弃本轮未提交音频、提示“网络连接已断开”。
- 回答中断开：停止播放、提示“回答未完成”。
- 不要复用旧票据重连；重新调用 `action=session` 获取新票据。
- 重连退避建议为 1 秒、3 秒、8 秒，最多 3 次；之后回到空闲，等待用户下一次按住。

## 10. 联调检查清单

1. 设备已绑定，现有拍照上传仍正常。
2. 按住时只产生一条 WSS；按住期间每 60 ms 发送一个原始 Opus 二进制帧。
3. 松开后仅发送一次 `control.commit`。
4. 设备能收到 `response.audio.start`、Opus 二进制帧、`response.audio.end`，并解码播放 24 kHz 回答。
5. 网页打开 `https://wwwxsh.cn/device-assistant.html`，可看到设备名、用户转写与助手回复。
6. 网页 `https://wwwxsh.cn/assistant.html` 的对话不会出现在设备记录页，反之亦然。
7. 尝试说“帮我拍照”“打开补光”等内容时，助手只能文字/语音回复，不会执行设备动作。

## 11. 推荐错误提示文案

| 场景 | 屏幕提示 |
|---|---|
| 无 Wi-Fi | 请先连接网络 |
| 申请票据失败 | 语音服务未就绪，请稍后重试 |
| WSS 连接失败 | 语音服务连接失败，请重试 |
| 松开过快 | 录音时间过短，请按住后再说话 |
| 生成失败 | 本次回答未完成，请再试一次 |
| 留档失败 | 已完成回答，但记录未同步 |
