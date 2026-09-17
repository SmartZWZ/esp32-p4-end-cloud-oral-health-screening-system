# 语音云端—ESP32-P4 控制协议（v1）

> 状态：**接口约束草案，尚未实现**  
> 适用工程：`firmware_lvgl_ui`  
> 面向对象：语音云端、ASR/LLM/TTS、设备端开发者  
> 更新日期：2026-07-22

## 1. 目的与边界

本协议规定自建语音云端如何向口腔筛查设备提出“查询”或“执行动作”的请求，以及设备如何确认、拒绝和回报结果。

它使用小智 WebSocket + MCP 的传输方式：文本帧承载 JSON，音频帧承载 Opus；MCP 内层为 JSON-RPC 2.0。小智原始协议中的 `hello`、`listen`、`stt`、`tts` 和 Opus 分帧以官方文档为准；**本文件只增加设备控制约束**。

硬性边界：

- 云端不得向设备发送自然语言命令、C/C++ 枚举值、Shell 命令、URL、固件或任意可执行内容。
- 云端只能发送第 5 节列出的工具名和参数。工具名使用稳定字符串，**不使用数字命令 ID**，避免云端与固件枚举序号不一致。
- 设备不信任 LLM 的结论。设备必须重新校验真实 Wi-Fi、成员、抓拍、上传和相机状态，再交由 `voice_action_service → app_presenter` 执行。
- `app_view` 仅显示语音状态和确认弹窗；不得直接解析云端指令或执行设备功能。
- 本协议不把患者语音、照片、成员资料转发给小智官方云。正式环境的 WebSocket 服务端为本项目自建语音网关。

## 2. 会话和音频约定

### 2.1 WebSocket 会话

- 传输：仅 `wss://`；禁止明文 `ws://`。
- 鉴权：使用设备绑定后的短期访问令牌；不得在串口、JSON 日志或错误回包中回显令牌。
- 设备在 `hello` 中声明 `features.mcp=true`。
- `session_id`：服务端生成的非空字符串，长度 1～64 字节；同一会话内必须唯一。
- MCP 协议版本：JSON-RPC 固定为 `"2.0"`；本控制契约版本固定为 `contract_version: 1`。

### 2.2 音频

首版固定协商为：上行/下行均为 **Opus、16 kHz、单声道、60 ms 帧**。若服务端不能满足，必须在 `hello` 协商阶段拒绝会话，不能静默改为 24 kHz 或 PCM。

- 上行：ES8311 录音 PCM → ESP32-P4 Opus → 二进制 WebSocket 帧。
- 下行：二进制 WebSocket Opus 帧 → ESP32-P4 解码为 PCM → ES8311 扬声器。
- 语音播报由 `{"type":"tts","state":"start"}` 开始，以 `state:"stop"` 结束；`sentence_start.text` 仅用于字幕，最大 256 个 UTF-8 字节，**不能承载控制命令**。
- 首版为半双工：`tts:start` 后设备停止采集麦克风；播放结束后才允许下一次录音。

## 3. MCP 控制帧格式

云端向设备发起工具调用时，必须使用下面的结构：

```json
{
  "session_id": "sess_01J...",
  "type": "mcp",
  "payload": {
    "jsonrpc": "2.0",
    "id": 101,
    "method": "tools/call",
    "params": {
      "contract_version": 1,
      "operation_id": "op_01J...",
      "name": "settings.set_volume",
      "arguments": {
        "value": 50
      }
    }
  }
}
```

字段约束：

| 字段 | 类型/范围 | 规则 |
| --- | --- | --- |
| `session_id` | string，1～64 字节 | 必须等于当前连接会话。 |
| `type` | string | 固定为 `"mcp"`。 |
| `payload.jsonrpc` | string | 固定为 `"2.0"`。 |
| `payload.id` | 正整数，1～4294967295 | 当前会话内唯一；设备使用它关联即时 MCP 响应。 |
| `method` | string | 控制请求固定为 `"tools/call"`。 |
| `contract_version` | 整数 | v1 固定为 `1`；其他值必须拒绝。 |
| `operation_id` | string，8～64 字节 | 状态变更工具必填；云端重试时必须保持不变。推荐 UUIDv7/ULID。 |
| `name` | string，见第 5 节 | 精确匹配；未知工具必须拒绝。 |
| `arguments` | object | 仅允许该工具定义的字段；未知字段必须拒绝。 |

完整 JSON 文本帧最大 4096 字节；单个字符串参数最大 256 个 UTF-8 字节。数值不得以字符串表示，禁止浮点数、数组嵌套和 `null` 参数。

## 4. 设备响应、异步结果与重放保护

### 4.1 同步 MCP 响应

设备必须对每一个合法 `tools/call` 返回一个 JSON-RPC 响应，且 `id` 原样回显。

查询类工具示例：

```json
{
  "session_id": "sess_01J...",
  "type": "mcp",
  "payload": {
    "jsonrpc": "2.0",
    "id": 101,
    "result": {
      "status": "ok",
      "data": { "volume": 50 }
    }
  }
}
```

状态变更工具可返回以下 `status`：

- `accepted`：已进入设备执行队列；后续等待 `device_event` 最终结果。
- `pending_confirmation`：设备已显示确认弹窗，尚未执行。
- `rejected`：设备依据策略或当前状态拒绝。
- `failed`：本地初始化或入队失败。

### 4.2 最终状态事件

耗时操作（抓拍、重拍、上传）必须由设备再发送自定义状态事件。该事件仅供**自建**云端使用，不发送给小智官方云：

```json
{
  "type": "device_event",
  "contract_version": 1,
  "event": "action.result",
  "session_id": "sess_01J...",
  "operation_id": "op_01J...",
  "tool": "photo.upload",
  "status": "succeeded",
  "error": null
}
```

`status` 只能为 `succeeded`、`cancelled`、`rejected` 或 `failed`。失败时 `error` 必须是第 7 节的错误码；不得放入 token、密码、成员完整资料、照片内容或堆栈。

### 4.3 重放与确认

- 设备缓存最近 32 个状态变更 `operation_id`，缓存时长至少 10 分钟。
- 收到重复 `operation_id` 时，不得再次拍照、上传或改变设置；返回上一次结果或 `DUPLICATE_OPERATION`。
- 需要确认的工具由设备生成本地 `confirmation_id`（16～64 字节随机字符串），展示给 UI 后返回 `pending_confirmation`。
- 云端不得伪造 `confirmation_id`，也不得重复发送同一工具调用催促执行。
- 确认弹窗默认 30 秒过期；触屏确认后设备执行，取消/超时后发送 `device_event`。

## 5. v1 工具白名单

下表是协议能力，**不表示当前固件已经实现全部工具**。云端必须先调用 `tools/list`，仅调用设备实际声明支持的工具。

| 工具名 | `arguments` | 执行策略 |
| --- | --- | --- |
| `device.get_status` | `{}` | 只读；返回页面、资源概览、语音状态，不返回密钥。 |
| `wifi.get_status` | `{}` | 只读；返回 `connected`、是否已获 IPv4，不返回 SSID/密码。 |
| `camera.get_status` | `{}` | 只读；返回 `idle/live/frozen/busy`。 |
| `photo.get_status` | `{}` | 只读；返回是否有冻结原图及上传状态。 |
| `member.list` | `{}` | 只读；仅在自建、授权会话中返回最小字段 `public_id`、`display_name`。 |
| `member.get_selected` | `{}` | 只读。 |
| `member.select` | `{"public_id":"..."}` | 设备本地精确匹配后选择；不存在或同名歧义必须拒绝/要求 UI 选择。 |
| `screen.navigate` | `{"target":"home|member_management|settings|capture|back"}` | 无副作用导航；不得通过页面名调用内部 UI 函数。 |
| `camera.start_preview` | `{}` | 仅由 Presenter 协调相机和 LCD 所有权。 |
| `camera.capture` | `{}` | 必须确认；仅在已选择成员且预览为 `live` 时执行。 |
| `camera.retake` | `{}` | 当前有冻结照片时必须确认；其余情况可直接拒绝。 |
| `photo.upload` | `{}` | 必须确认；设备再次检查 GOT_IP、绑定、成员、原图和非 busy。 |
| `settings.set_volume` | `{"value":0..100}` | 可直接执行，整数。 |
| `settings.set_brightness` | `{"value":0..100}` | 可直接执行，整数。 |
| `result.get_latest` | `{}` | 只读；仅返回设备已保存的结构化筛查摘要。 |

以下能力 v1 **禁止注册或调用**：成员创建/编辑/删除、Wi-Fi 凭据读写、解除绑定、恢复出厂、重启、OTA、任意 HTTP 请求、任意文件读写、任意图像/屏幕上传。

### 5.1 首版功能覆盖矩阵

| 功能 | 用户示例 | 云端应调用/处理 | 设备端执行边界 | 优先级 |
| --- | --- | --- | --- | --- |
| 语音导航 | “打开成员管理”“返回主页” | `screen.navigate`，`target` 仅能为第 5 节枚举值 | `voice_action_service` 转为 Presenter 导航请求 | 最高 |
| 语音选择成员 | “选择张三” | 先 `member.list`，再以精确 `public_id` 调用 `member.select` | 必须使用 `member_service` 当前快照；同名或无匹配时显示候选项，不得猜选 | 最高 |
| 拍摄控制 | “开始拍照”“拍摄”“重新拍摄” | `camera.start_preview`、`camera.capture`、`camera.retake` | 只能经现有 Presenter/相机状态机；抓拍、放弃冻结照片均要按本协议确认 | 最高 |
| 上传控制 | “上传这张照片” | `photo.upload` | 设备复核 GOT_IP、绑定、成员、原始抓拍和 busy 状态，且必须确认 | 最高 |
| 设备状态查询 | “网络连接了吗”“现在选的是谁” | `wifi.get_status`、`member.get_selected`、`camera.get_status`、`photo.get_status` | 仅返回当前 Service/Model 的真实状态 | 高 |
| 音量与亮度控制 | “音量调到 50%”“屏幕暗一点” | `settings.set_volume` / `settings.set_brightness`，`value` 为 0～100 整数 | 交由 `device_settings`；禁止绕过设置服务操作硬件 | 高 |
| 结果播报 | “刚才的检测结果是什么” | 见第 5.2 节：语音云端查询内部筛查结果接口后生成 TTS | 板端只提供当前成员/最近分析关联上下文；不得由 LLM 虚构结果 | 高 |

### 5.2 结果播报的数据边界

“结果播报”不是让 LLM 根据照片或聊天记录猜测结果。结构化筛查结果的权威来源是图像分析服务；自建语音网关必须使用内部、鉴权的结果查询接口读取它，再生成 TTS。

推荐流程：

```text
用户：“刚才的检测结果是什么？”
    ↓
语音网关确认当前设备与会话授权
    ↓
设备 `result.get_latest`：返回最近分析关联/状态（不返回任意历史）
    ↓
语音网关内部查询图像分析服务
    ↓
取得结构化结果 → 生成非诊断性播报文本 → TTS Opus 下发
```

图像分析服务给语音网关的最小结果对象应为：

```json
{
  "analysis_id": "analysis_01J...",
  "member_public_id": "member_...",
  "status": "ready",
  "updated_at": "2026-07-22T10:00:00Z",
  "summary": "发现 1 项需要关注的筛查提示。",
  "findings": [
    {
      "code": "caries_suspected",
      "label": "疑似龋坏风险",
      "confidence": 0.82,
      "recommendation": "建议至正规医疗机构复查。"
    }
  ],
  "medical_disclaimer": "结果仅供口腔筛查参考，不构成临床诊断。"
}
```

- `status` 仅允许 `pending`、`ready`、`failed`、`not_found`；非 `ready` 时云端应如实播报“结果仍在处理中”或“暂未获得结果”。
- `member_public_id` 必须与当前设备会话和当前所选成员一致；不满足时拒绝播报，不得跨成员读取。
- `confidence` 是 0～1 数值，云端可转为“低/中/高置信度”描述，但不得虚构或扩大为临床结论。
- 音频播报只读 `summary`、`findings.label`、`recommendation` 和 `medical_disclaimer`；不播报内部 ID、完整成员资料或原始置信度。

## 6. 动作前置条件与设备仲裁

云端可以提出请求，设备拥有最终决定权：

| 工具 | 必须同时满足的设备条件 |
| --- | --- |
| `camera.start_preview` | 相机可用，未处于 LCD 交接、JPEG 编码或上传冲突状态。 |
| `camera.capture` | 已选择有效成员；当前为 `live`；无抓拍/上传任务进行中。 |
| `camera.retake` | 当前为 `frozen`，且用户已确认放弃当前冻结照片。 |
| `photo.upload` | 已获得 IPv4（`GOT_IP`）、设备绑定有效、成员有效、存在 800×800 原始抓拍、上传服务不忙、用户已确认。 |
| `member.select` | `public_id` 存在于当前 `member_service` 快照。 |
| 音量/亮度设置 | 参数为 0～100 的整数；设备设置服务可用。 |

语音会话处于录音、Opus 编解码或 TTS 播放时，设备不得在 LVGL 线程中等待网络。JPEG 编码、照片上传、相机 PPA/LCD 接管期间，设备可返回 `DEVICE_BUSY`；云端不得无限重试，而应播报“设备正在处理，请稍后”。

## 7. 错误码

错误统一放入 JSON-RPC 的 `error`：

```json
{
  "jsonrpc": "2.0",
  "id": 101,
  "error": {
    "code": 41204,
    "message": "CAPTURE_PREREQUISITE_FAILED"
  }
}
```

| code | `message` | 含义与云端处理 |
| ---: | --- | --- |
| 40001 | `UNSUPPORTED_CONTRACT_VERSION` | 停止调用，升级双方协议。 |
| 40002 | `INVALID_REQUEST` | 字段、类型、范围或未知参数错误；不得原样重试。 |
| 40101 | `UNAUTHORIZED_SESSION` | 重新鉴权，不得继续发送控制请求。 |
| 40301 | `TOOL_NOT_ALLOWED` | 工具不在白名单或不在设备 `tools/list` 中。 |
| 40401 | `TOOL_NOT_IMPLEMENTED` | 固件尚未实现该已规划工具。 |
| 40901 | `DUPLICATE_OPERATION` | 使用此前结果；不得再次执行。 |
| 40902 | `DEVICE_BUSY` | 可在用户下一次明确请求后再尝试。 |
| 41201 | `NETWORK_PREREQUISITE_FAILED` | 未取得 IP 或网络不可用。 |
| 41202 | `MEMBER_PREREQUISITE_FAILED` | 无有效成员或成员状态冲突。 |
| 41203 | `PHOTO_PREREQUISITE_FAILED` | 无有效冻结原图。 |
| 41204 | `CAPTURE_PREREQUISITE_FAILED` | 非 live、相机不可用或未选择成员。 |
| 42301 | `USER_CONFIRMATION_REQUIRED` | 已进入确认流程，不得重复下发。 |
| 42302 | `USER_CONFIRMATION_CANCELLED` | 用户取消或确认超时。 |
| 50001 | `LOCAL_EXECUTION_FAILED` | 本地服务失败；云端记录 `operation_id`，不自动重复危险操作。 |

## 8. 云端 LLM 与提示词约束

云端的 LLM 只负责理解用户意图、生成自然语言回复并选择已注册工具；它不是设备权限主体。

- 需要设备当前状态时，先调用查询工具，不得猜测“已联网”“已拍照”“上传成功”。
- 当工具返回 `pending_confirmation` 时，只能用 TTS 询问用户，不得再发一次同类工具调用。
- 口腔内容只能描述筛查、护理建议和就医建议；不得输出临床诊断、处方或虚构检测结果。
- `member.list`、`result.get_latest` 的返回仅用于当前会话；云端日志应保存脱敏的工具名、错误码、`operation_id` 和耗时，不保存完整语音、成员名或照片。
- TTS 文本与 MCP 工具调用必须为不同消息：禁止把 JSON、操作码或成员 `public_id` 读给用户。

## 9. 联调顺序

1. 仅建立 WSS 和 `hello`，确认 16 kHz Opus 参数协商。
2. 接入按住说话：`listen:start` → Opus 上行 → `listen:stop`；仅验证 `stt` 和 `tts` 播放。
3. 实现 `tools/list`、全部只读工具和 `settings.set_volume`。
4. 实现 `screen.navigate`、`member.select`，验证 Presenter 不被语音线程绕过。
5. 实现 `camera.capture`、`camera.retake`、`photo.upload` 的二次确认、异步结果与去重。
6. 在实时预览、抓拍冻结、JPEG 编码、上传和连续 TTS 下分别做实机回归；不得将源码静态检查当作硬件验证。

## 10. 设备端实现映射（后续）

计划新增的分层为：

```text
xiaozhi_client（WSS / Opus / MCP 编解码）
    ↓
voice_action_service（白名单、参数校验、确认、去重、状态事件）
    ↓
app_presenter（现有状态机和资源协调）
    ↓
member_service / camera_service / photo_upload_service / device_settings
```

不得让 `xiaozhi_client` 或 `voice_action_service` 直接调用 LVGL、相机帧回调、NVS、HTTP 图片上传或 Wi-Fi 驱动。
