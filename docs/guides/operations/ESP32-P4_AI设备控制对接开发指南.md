# ESP32-P4 AI 设备控制对接开发指南

本文档对应齿镜站点 `https://wwwxsh.cn/`，用于把网页端 AI、设备端语音 AI 与 ESP32-P4 的实际功能连接起来。

本版支持以下 6 个白名单命令：

1. `select_active_member`：选择当前家庭成员
2. `capture_and_archive`：拍照并上传保存
3. `capture_and_analyze`：拍照并进行“云端分析”
4. `capture_and_local_analyze`：拍照并进行 ESP32-P4 本地分析
5. `set_speaker_volume`：调节扬声器音量
6. `set_screen_brightness`：调节屏幕亮度

> “云端分析”仍沿用网页中的产品名称。当前模型工作进程可以实际运行在用户的 Windows 电脑上，经 Tailscale 从云端领取任务；ESP32-P4 不需要知道模型究竟运行在云服务器还是本地电脑。

## 一、整体链路

```text
用户对网页 AI 或设备语音 AI 提出明确要求
        ↓
千问模型调用一个白名单控制工具
        ↓
云端 assistant_tools.php 校验账号、设备、成员和参数
        ↓
云端向 device_commands 表写入一条短时有效命令
        ↓
ESP32-P4 使用设备令牌轮询 device_commands.php
        ↓
设备确认、执行，并回传 running / succeeded / failed
        ↓
云端保存完整执行轨迹
```

ESP32-P4 不解析大模型生成的自然语言，也不执行任意字符串。设备只执行本文档列出的固定命令。

## 二、云端前置条件

部署前，服务器必须已经上传以下最新文件：

- `/api/device_commands.php`
- `/api/assistant_tools.php`
- `/api/process.php`
- `/api/assistant_device.php`
- `/ai_gateway/gateway.py`
- `/ai_gateway/tool_dispatcher.py`

数据库需要执行：

```sql
database_ai_device_commands_migration.sql
```

执行 SQL 前必须先在 phpMyAdmin 中选中齿镜数据库。该迁移会创建：

- `device_runtime_states`：保存固件版本、能力表和设备当前状态
- `device_commands`：保存待执行命令和最终结果
- `device_command_events`：保存命令生命周期事件

云端 AI 网关更新后需要执行：

```bash
AI_PYTHON=/www/wwwroot/chijing_runtime/ai_gateway_venv/bin/python
GATEWAY=/www/wwwroot/8.138.230.100_667/ai_gateway/gateway.py
TOOLS=/www/wwwroot/8.138.230.100_667/ai_gateway/tool_dispatcher.py

"$AI_PYTHON" -m py_compile "$GATEWAY" "$TOOLS"
systemctl restart chijing-ai-gateway.service
systemctl status chijing-ai-gateway.service --no-pager
```

## 三、设备鉴权

命令接口复用设备绑定后已经保存的设备令牌：

```http
X-Device-Token: <设备令牌>
```

不要使用设备码代替设备令牌。设备码只用于绑定；设备令牌用于绑定后的长期身份验证。

接口地址：

```text
https://wwwxsh.cn/api/device_commands.php
```

要求：

- 只允许 HTTPS。
- 校验服务器证书，禁止关闭 TLS 校验。
- 不在串口日志中完整打印设备令牌。
- HTTP 请求统一使用 `Content-Type: application/json`。

## 四、设备能力和运行状态

设备第一次启动、网络重连以及能力变化后，应调用 `heartbeat`：

```http
POST /api/device_commands.php?action=heartbeat HTTP/1.1
Host: wwwxsh.cn
X-Device-Token: <设备令牌>
Content-Type: application/json
```

请求示例：

```json
{
  "firmware_version": "chijing-p4-1.3.0",
  "capabilities": {
    "select_active_member": true,
    "capture_and_archive": true,
    "capture_and_analyze": true,
    "capture_and_local_analyze": true,
    "set_speaker_volume": true,
    "set_screen_brightness": true
  },
  "state": {
    "active_member_id": "成员公共ID",
    "camera_ready": true,
    "busy": false,
    "speaker_volume": 60,
    "screen_brightness": 70
  }
}
```

如果音量或背光驱动尚未实现，相应能力必须上报为 `false`，不要谎报支持。

成功响应：

```json
{
  "ok": true,
  "server_time": "2026-07-25T20:00:00+08:00"
}
```

建议正常联网时每 10 秒上报一次心跳；命令轮询请求也可以携带相同的 `firmware_version`、`capabilities` 和 `state`，服务器会同时刷新状态。

## 五、领取命令

### 5.1 轮询请求

```http
POST /api/device_commands.php?action=poll HTTP/1.1
Host: wwwxsh.cn
X-Device-Token: <设备令牌>
Content-Type: application/json
```

请求体可以复用心跳结构。无命令时响应：

```json
{
  "ok": true,
  "command": null,
  "poll_after_ms": 1500
}
```

有命令时响应：

```json
{
  "ok": true,
  "command": {
    "command_id": "命令公共ID",
    "name": "set_speaker_volume",
    "arguments": {
      "level": 60
    },
    "source": "device_ai",
    "status": "delivered",
    "created_at": "2026-07-25 20:00:00",
    "expires_at": "2026-07-25 20:01:00"
  },
  "poll_after_ms": 500
}
```

### 5.2 轮询频率

- 正常空闲：按服务器返回的 `poll_after_ms`，当前建议约 1500 ms。
- 收到命令后：先确认并执行，不要继续并发领取第二条。
- 网络失败：1 秒、2 秒、4 秒、8 秒指数退避，最大 30 秒。
- 恢复联网：立即心跳，然后恢复轮询。

### 5.3 命令过期

设备必须比较 `expires_at`。本机时间应通过 SNTP 校准。

如果命令已过期，不得执行拍照或控制动作，应返回拒绝：

```json
{
  "command_id": "命令公共ID",
  "accepted": false,
  "message": "command_expired"
}
```

## 六、确认、执行中和最终结果

### 6.1 接受命令

设备完成下列检查后再接受：

- 命令名在本地白名单中
- 参数类型和范围正确
- 命令未过期
- 设备具有对应能力
- 当前状态允许执行

```http
POST /api/device_commands.php?action=ack
```

```json
{
  "command_id": "命令公共ID",
  "accepted": true,
  "message": "accepted"
}
```

拒绝命令：

```json
{
  "command_id": "命令公共ID",
  "accepted": false,
  "message": "brightness_unsupported"
}
```

### 6.2 上报执行中

耗时操作，例如拍照、上传和本地推理，应先上报：

```http
POST /api/device_commands.php?action=result
```

```json
{
  "command_id": "命令公共ID",
  "status": "running",
  "result": {
    "stage": "capturing"
  }
}
```

阶段可以使用：

- `capturing`
- `uploading`
- `local_inference`
- `applying_setting`

### 6.3 上报成功

```json
{
  "command_id": "命令公共ID",
  "status": "succeeded",
  "result": {
    "speaker_volume": 60
  }
}
```

### 6.4 上报失败

```json
{
  "command_id": "命令公共ID",
  "status": "failed",
  "error": "camera_unavailable",
  "result": {
    "stage": "capturing"
  }
}
```

`ack` 和最终 `result` 必须带同一个 `command_id`。

## 七、六项命令的精确定义

### 7.1 选择当前家庭成员

命令：

```json
{
  "name": "select_active_member",
  "arguments": {
    "member_id": "成员公共ID",
    "member_name": "张三"
  }
}
```

执行要求：

1. 从网页同步下来的有效成员列表中查找 `member_id`。
2. 不要只按姓名匹配，姓名可能重复。
3. 设置设备全局的 `active_member_id`。
4. 写入 NVS，重启后仍保留。
5. 更新屏幕上的当前成员。
6. 上报成功结果：

```json
{
  "active_member_id": "成员公共ID",
  "active_member_name": "张三"
}
```

若成员不在本地缓存中，先主动拉取一次云端成员列表，再查找；仍不存在则返回 `member_not_found`。

### 7.2 拍照并上传保存

命令：

```json
{
  "name": "capture_and_archive",
  "arguments": {
    "member_id": "成员公共ID",
    "member_name": "张三"
  }
}
```

执行流程：

1. 切换本次任务上下文到指定 `member_id`，但是否永久切换当前成员由产品逻辑决定；推荐同时设置为当前成员。
2. 检查相机是否可用。
3. 获取一张完整 JPEG。
4. 上传到：

```text
POST https://wwwxsh.cn/api/process.php
```

请求头：

```http
X-Device-Token: <设备令牌>
X-Member-Id: <成员公共ID>
X-Upload-Mode: archive
Content-Type: image/jpeg
```

请求体是原始 JPEG 字节，不是 Base64。

成功响应包含：

```json
{
  "ok": true,
  "detection_id": "图片记录ID",
  "member_id": "成员公共ID",
  "upload_mode": "archive",
  "status": "saved"
}
```

最终命令结果建议：

```json
{
  "detection_id": "图片记录ID",
  "member_id": "成员公共ID",
  "upload_mode": "archive",
  "bytes": 123456
}
```

### 7.3 拍照并进行“云端分析”

命令：

```json
{
  "name": "capture_and_analyze",
  "arguments": {
    "member_id": "成员公共ID",
    "member_name": "张三",
    "model_pipeline": "caries"
  }
}
```

上传地址仍为：

```text
POST https://wwwxsh.cn/api/process.php
```

请求头：

```http
X-Device-Token: <设备令牌>
X-Member-Id: <成员公共ID>
X-Upload-Mode: detect
X-Model-Pipeline: caries
Content-Type: image/jpeg
```

`X-Model-Pipeline` 只能是：

- `caries`：龋齿候选区域模型
- `both`：运行服务器当前配置的完整双模型流水线
- `dental_seg`：在本地 Windows GPU 工作端运行龋齿、窝洞、裂纹、牙齿四类分割模型

上传成功表示图片进入分析队列，不代表模型已经完成。设备命令在上传成功后即可返回 `succeeded`，结果中注明：

```json
{
  "detection_id": "检测记录ID",
  "member_id": "成员公共ID",
  "upload_mode": "detect",
  "model_pipeline": "caries",
  "analysis_status": "queued"
}
```

后续检测结果由网页、AI 查询工具或模型结果接口读取。不要在 ESP32-P4 上伪造云端分析结果。

### 7.4 拍照并进行本地分析

命令：

```json
{
  "name": "capture_and_local_analyze",
  "arguments": {
    "member_id": "成员公共ID",
    "member_name": "张三",
    "local_model": "dental"
  }
}
```

支持的本地模型标识：

- `dental`：设备端口腔目标/分割模型
- `risk`：设备端轻量风险模型

执行流程：

1. 拍摄一张图像。
2. 在 ESP32-P4 上运行指定 ESP-DL 模型。
3. 在屏幕上叠加或展示本地结果。
4. 不上传原图，也不进入云端图片历史。
5. 将结构化摘要作为命令结果回传。

示例：

```json
{
  "member_id": "成员公共ID",
  "local_model": "dental",
  "inference_ms": 824,
  "candidate_count": 2,
  "summary": "发现 2 个本地模型候选区域",
  "notice": "仅为设备端辅助筛查结果"
}
```

本版仅保存命令审计结果，不把本地推理自动写入 `detections`。如后续希望在网页历史中展示本地分析图和叠加结果，需要再增加专用上传接口，不能假装成云端检测记录。

### 7.5 调节扬声器音量

命令：

```json
{
  "name": "set_speaker_volume",
  "arguments": {
    "level": 60
  }
}
```

要求：

- `level` 范围为 0—100。
- 0 可以表示静音。
- 映射到 BSP/codec 实际音量范围时必须限幅。
- 写入 NVS，重启后恢复。
- 调节后立即影响 AI 语音播放。
- 若当前 BSP 只有固定音量而没有运行时接口，上报能力 `false` 并返回 `volume_unsupported`，不要仅在 UI 上显示成功。

成功结果：

```json
{
  "speaker_volume": 60,
  "muted": false
}
```

### 7.6 调节屏幕亮度

命令：

```json
{
  "name": "set_screen_brightness",
  "arguments": {
    "level": 70
  }
}
```

要求：

- `level` 范围为 10—100。
- 云端不允许远程设为 0，避免用户误以为设备损坏。
- 通过背光 PWM、LEDC 或板级 BSP 背光接口实现。
- 写入 NVS，重启后恢复。
- 若硬件背光不可调，上报能力 `false` 并返回 `brightness_unsupported`。

成功结果：

```json
{
  "screen_brightness": 70
}
```

## 八、统一命令分发器

建议所有来源都调用同一个设备内部函数：

```c
esp_err_t chijing_command_dispatch(
    const char *command_id,
    const char *name,
    const cJSON *arguments,
    chijing_command_result_t *result
);
```

来源包括：

- 云端网页 AI
- 云端设备语音 AI
- 设备本地菜单/按钮
- 后续可能加入的本地 AI

不要为每个来源分别实现拍照、成员切换、音量和亮度逻辑，否则很容易出现行为不一致。

设备本地直接触发时没有云端 `command_id`，可以生成本地 UUID，并在联网后调用：

```text
POST /api/device_commands.php?action=report_local
```

请求示例：

```json
{
  "name": "set_screen_brightness",
  "arguments": {
    "level": 70
  },
  "status": "succeeded",
  "result": {
    "screen_brightness": 70
  },
  "firmware_version": "chijing-p4-1.3.0",
  "state": {
    "screen_brightness": 70
  }
}
```

这能让设备本地操作和云端 AI 操作使用同一套审计记录，但不会让云端重复执行该命令。

## 九、防止重复执行

网络超时后同一条命令可能被再次返回。设备必须按 `command_id` 去重。

建议：

1. RAM 中保存最近 32 个命令 ID 和最终状态。
2. 对拍照类命令，将最近已完成 ID 写入 NVS 或持久化小型环形记录。
3. 收到已完成 ID 时，不重复拍照，直接重发原最终结果。
4. 云端也会对同一个 AI `tool_call_id` 去重，但设备端去重仍不可省略。

## 十、并发与状态机

设备同时只执行一条拍照/上传/推理命令。

推荐状态：

```text
IDLE
  → VALIDATING
  → CAPTURING
  → UPLOADING 或 LOCAL_INFERENCE
  → REPORTING
  → IDLE
```

音量和亮度命令可以快速执行，但不要在摄像头 DMA、音频播放或 UI 线程中直接阻塞网络请求。建议命令轮询任务只负责入队，由单独工作任务执行。

录制语音期间收到拍照命令时，建议先结束当前语音轮次再拍照；至少要避免相机、音频和网络缓冲区同时争抢内存导致崩溃。

## 十一、标准错误码

硬件端应优先返回以下稳定错误码，便于云端和日志定位：

- `unsupported_command`
- `invalid_arguments`
- `command_expired`
- `member_not_found`
- `device_busy`
- `camera_unavailable`
- `capture_failed`
- `wifi_disconnected`
- `upload_failed`
- `cloud_response_invalid`
- `local_model_unavailable`
- `local_inference_failed`
- `volume_unsupported`
- `brightness_unsupported`
- `storage_failed`

可额外在 `result.detail` 中提供简短技术信息，但不要把令牌、密码或完整响应头写入错误。

## 十二、本地 AI 与云端 AI 的边界

### 云端 AI

网页 AI 和设备语音 AI 均可：

- 查询当前账号授权范围内的成员、图片、检测记录和设备状态
- 在用户明确要求时创建上述 6 种设备命令

### 设备本地功能或未来本地 AI

设备离线时可以执行：

- 切换已经缓存的家庭成员
- 本地拍照
- 本地模型分析
- 调节音量
- 调节亮度

设备离线时不能：

- 获取云端最新成员列表
- 上传保存图片
- 发起云端分析
- 查询完整账号历史

未来若在设备或局域网中加入本地大模型，也必须调用同一个白名单分发器，不能让模型生成任意 C 函数名、URL 或 Shell 指令。

## 十三、最小测试顺序

按以下顺序联调，每一步成功后再继续：

1. **心跳**：网页 AI 查询设备状态时能看到固件版本和能力。
2. **亮度**：发送 70，设备实际变化，数据库命令状态为 `succeeded`。
3. **音量**：发送 30，AI 播放音量实际变化。
4. **成员切换**：按 `member_id` 切换，屏幕和 NVS 都更新。
5. **仅保存**：拍照上传，网页图片管理出现彩色原图，`upload_mode=archive`。
6. **云端分析**：上传后 `status=received`，Windows 模型工作进程能够领取并完成。
7. **本地分析**：断开外网也能运行，重新联网后可通过 `report_local` 上报摘要。
8. **重复命令**：人为重复发送同一 `command_id`，设备只能拍一次照片。
9. **过期命令**：设备离线超过命令有效期后上线，不得执行旧拍照命令。
10. **能力缺失**：把亮度能力设为 `false`，AI 命令必须失败并返回 `brightness_unsupported`。

## 十四、联调时的数据库检查

查看最新命令：

```sql
SELECT
  public_id,
  source,
  command_name,
  arguments_json,
  status,
  error_message,
  created_at,
  delivered_at,
  acknowledged_at,
  started_at,
  completed_at
FROM device_commands
ORDER BY id DESC
LIMIT 20;
```

查看事件链：

```sql
SELECT
  c.public_id,
  c.command_name,
  e.event_type,
  e.payload_json,
  e.created_at
FROM device_command_events e
INNER JOIN device_commands c ON c.id=e.command_id
ORDER BY e.id DESC
LIMIT 50;
```

正常完整链路应出现：

```text
queued → delivered → accepted → running → succeeded
```

快速设置命令可以省略 `running`：

```text
queued → delivered → accepted → succeeded
```

## 十五、重要注意事项

- 所有旧的 `http://8.138.230.100:667/` 地址都应替换为 `https://wwwxsh.cn/`。
- 云端返回 `queued` 只代表已进入队列，不代表硬件执行成功。
- 拍照命令必须明确携带成员 ID，不能静默归到默认成员。
- “上传保存”和“云端分析”必须用不同的 `X-Upload-Mode`。
- 本地分析不应调用 `/api/process.php`，除非产品以后明确要求把本地结果同步到网页。
- 不允许增加“执行任意 URL”“执行任意函数”“执行脚本”一类通用命令。
