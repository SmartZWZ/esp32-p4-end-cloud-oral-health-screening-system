# ESP32-P4 AI 助手会话记忆对接指南

## 目标

设备端必须保存服务器最终返回的 `conversation_id`。同一 ID 的多次“按住说话”会参考此前对话；设备空闲超过服务器配置的时间后，服务器会返回新的 ID，设备必须覆盖本地旧值。

网页助手与设备助手由云端强制隔离，硬件端不要调用网页助手接口。

## 一、会话申请接口

```http
POST https://wwwxsh.cn/api/assistant_device.php?action=session
Content-Type: application/json
X-Device-Token: 设备绑定后获得的令牌

{"conversation_id":"设备NVS中保存的会话ID；首次可为空字符串"}
```

响应新增或需要关注的字段：

```json
{
  "ok": true,
  "ticket": "...",
  "ticket_expires_at": 1780000000,
  "gateway_path": "/ai-gateway/device/",
  "conversation_id": "20260726123456abcdef1234",
  "conversation_changed": false,
  "memory_enabled": true,
  "idle_timeout_minutes": 30,
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

## 二、NVS 保存规则

建议命名空间：`chijing_ai`，键：`conversation_id`。

每次 `session` 返回 200 且 `ok=true` 后：

1. 读取响应里的 `conversation_id`。
2. 与 RAM/NVS 中的旧值比较。
3. 无论 `conversation_changed` 是 true 还是 false，都以服务器返回值为准。
4. 不同则立即写入 NVS 并提交。
5. 随后再使用响应里的短期 `ticket` 建立 WebSocket。

伪代码：

```c
char saved_id[40] = {0};
nvs_get_str(handle, "conversation_id", saved_id, &length);

post_session(saved_id, &response);
if (!response.ok || response.conversation_id[0] == '\0') {
    show_error("AI会话申请失败");
    return;
}

if (strcmp(saved_id, response.conversation_id) != 0) {
    nvs_set_str(handle, "conversation_id", response.conversation_id);
    nvs_commit(handle);
}

open_ai_websocket(response.gateway_path, response.ticket);
```

## 三、自动换新会话

以下情况服务器会返回新 `conversation_id`：

- 设备首次使用，没有上传 ID。
- 上次会话最后一条消息距今已超过默认 30 分钟。
- 用户在网页“设备对话记录”中点击“新建设备对话”。
- 旧会话已经被用户删除。
- 设备上传的是过期或不属于本设备的 ID。

硬件不要把 `conversation_changed=true` 当成错误，也不要反复申请旧 ID。它表示服务器已经完成安全换段。

## 四、重启恢复

- ESP32-P4 重启后从 NVS 读取 `conversation_id`。
- 调用 `session` 时将其原样提交。
- 服务端确认仍有效就继续；超时或被网页重置就返回新 ID。
- 设备更新 NVS 后继续正常录音。

因此设备重启不会自动丢失对话，但服务器仍是会话状态的最终权威。

## 五、新建对话

当前版本在网页“设备对话记录”页面提供“新建设备对话”。网页创建后会把旧会话设为非当前会话；设备下一次调用 `session` 时会拿到新 ID。

如果以后在硬件端增加“新对话”按钮，建议新增显式云端接口后再做，不要只在本地清空 NVS。仅清空 NVS 时，服务器可能仍恢复最近 30 分钟内的当前会话。

## 六、记忆与设备控制安全

- 历史只用于理解“他、刚才那个成员、继续上一个问题”等上下文。
- 历史中的“拍照、调整亮度”等旧命令不代表当前轮授权。
- 云端提示词要求任何设备控制都必须由用户在当前轮明确提出。
- 硬件仍应只执行具有有效设备令牌、命令 ID、未过期状态和允许命令名的队列命令。

## 七、兼容性

新增 JSON 字段不会改变现有 Opus 协议。硬件至少需要正确解析并保存 `conversation_id`；其他新增字段可以按需读取。建议 JSON 文档容量预留不低于 4096 字节。

