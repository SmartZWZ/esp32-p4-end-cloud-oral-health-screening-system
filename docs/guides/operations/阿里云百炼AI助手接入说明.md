# 齿镜：阿里云百炼大语言模型接入说明

本文说明如何把阿里云百炼（Model Studio / DashScope）的大语言模型 API 接入齿镜项目，为后续 ESP32-P4 语音助手做准备。

本文只接入“文字 → 大语言模型 → 文字结果”这一层；ASR、TTS、WebSocket 音频流和 MCP 硬件控制将在其上继续建设。

> 不要把 API Key 写入网页 JavaScript、ESP32 固件、截图、Git 仓库或聊天消息。ESP32 和浏览器只能调用齿镜自己的云端接口，只有服务器能调用百炼。

## 1. 齿镜中的位置

```text
ESP32-P4 / 网页
        │  仅发送已认证的业务请求
        ▼
齿镜云端 AI 网关（后续新增服务）
        │  持有百炼 API Key
        ▼
阿里云百炼：Qwen 大语言模型
        │
        ├─ 文字回复 → TTS → ESP32 扬声器
        └─ 工具意图 → 权限校验 → MCP → ESP32 执行并回执
```

不要让浏览器、ESP32 或 MCP 客户端直接调用百炼。这样可以避免 API Key 泄露，并可在云端统一处理用户隔离、成员上下文、费用限额、审计与医疗安全限制。

## 2. 百炼控制台中需要确认的内容

在百炼控制台确认以下内容：

1. 已开通模型服务，并确认要调用的模型，例如 `qwen-plus`。
2. 已创建用于服务端调用的 API Key。
3. API Key 所在地域为华北 2（北京），或记录实际地域、业务空间 ID（Workspace ID）。
4. 确认该 Key 是**按量付费/标准 API Key**，不是 Coding Plan 或 Token Plan Key。

Coding Plan、Token Plan 仅允许特定 AI 编程工具使用，不可用于齿镜网站、PHP 后端或自建服务。错误类型或错误地域的 Key 与 Base URL 配对会得到 401。

## 3. Base URL 与模型建议

### 3.1 北京地域推荐配置

优先以百炼控制台“API 调用”页显示的调用地址为准。可选形式如下：

| 使用场景 | Base URL |
| --- | --- |
| 快速验证、通用标准 Key | `https://dashscope.aliyuncs.com/compatible-mode/v1` |
| 正式生产、业务空间隔离 | `https://<WORKSPACE_ID>.cn-beijing.maas.aliyuncs.com/compatible-mode/v1` |

正式部署推荐业务空间专属域名：它能提供业务空间级隔离，更适合后续齿镜的生产调用。

### 3.2 首版模型选择

| 用途 | 首选 | 原因 |
| --- | --- | --- |
| 首版语音助手文字理解与回答 | `qwen-plus` | 中文对话能力足够，适合先完成端到端闭环 |
| 检测结果解释、较复杂总结 | `qwen-plus` | 可接收结构化检测结果与成员上下文 |
| 未来需要图片辅助解释 | 视觉模型，例如控制台可用的 `qwen3-vl-plus` | 仅在用户授权时传递图片 URL/内容 |

医疗相关回复必须定位为“口腔健康辅助说明”，不能输出确诊、处方、紧急医疗结论或替代医生建议。

## 4. 安全配置：Key 存放位置

当前站点根目录为：

```text
/www/wwwroot/8.138.230.100_667
```

建议在站点根目录外保存 AI 配置：

```text
/www/wwwroot/chijing_runtime/ai/bailian.env
```

文件内容示例：

```ini
BAILIAN_API_KEY=请粘贴真实Key，不要加引号
BAILIAN_BASE_URL=https://dashscope.aliyuncs.com/compatible-mode/v1
BAILIAN_CHAT_MODEL=qwen-plus
```

该文件权限应仅允许服务器管理员读取：

```bash
mkdir -p /www/wwwroot/chijing_runtime/ai
nano /www/wwwroot/chijing_runtime/ai/bailian.env
chmod 600 /www/wwwroot/chijing_runtime/ai/bailian.env
chown root:root /www/wwwroot/chijing_runtime/ai/bailian.env
```

当前 PHP 使用了 `open_basedir`。因此需要把运行时目录加入站点的 `.user.ini`：

```ini
open_basedir=/www/wwwroot/8.138.230.100_667/:/www/wwwroot/chijing_storage/:/www/wwwroot/chijing_runtime/:/tmp/
```

修改 `.user.ini` 后，如该文件被设置了不可修改属性，应先执行：

```bash
chattr -i /www/wwwroot/8.138.230.100_667/.user.ini
```

修改完可重新加锁：

```bash
chattr +i /www/wwwroot/8.138.230.100_667/.user.ini
```

> `.env` 文件不应打包进“齿镜快速部署版”的上传压缩包；以后上传新代码不会覆盖它。

## 5. 先在服务器验证 API Key

在服务器终端执行下列测试。命令会从外部配置文件读取 Key，不要把 Key 直接写入终端历史：

```bash
set -a
. /www/wwwroot/chijing_runtime/ai/bailian.env
set +a

curl --fail-with-body -sS \
  "$BAILIAN_BASE_URL/chat/completions" \
  -H "Authorization: Bearer $BAILIAN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "qwen-plus",
    "messages": [
      {"role": "user", "content": "请只回复：齿镜百炼连接成功"}
    ],
    "stream": false
  }'
```

成功时会返回 JSON，正文位于：

```text
choices[0].message.content
```

常见结果：

| 现象 | 排查方向 |
| --- | --- |
| 401 | Key 错误、地域不一致，或使用了不允许后端调用的套餐 Key |
| 403 | 模型未开通、账户/业务空间无权限 |
| 429 | 触发并发或速率限制，应在网关做排队和退避重试 |
| 5xx / 超时 | 记录请求 ID，稍后重试；不要无限重试 |

## 6. PHP 中的调用方式

现有齿镜网站是 PHP 项目。首版可用 PHP cURL 调用百炼；不建议把 PHP 作为长期 WebSocket 服务。

建议新增文件职责：

```text
api/ai_chat.php          网页文字对话接口，校验当前登录用户
api/ai_context.php       组装当前成员、检测结果、历史摘要（内部函数/模块）
api/ai_config.php        只读取站点外 bailian.env，不写入 Key
ai_gateway/              后续 Node.js/Python 长连接服务，处理 ESP32 WebSocket 音频与 MCP
```

首版非流式 PHP 请求结构如下。此代码仅展示核心调用，不应直接复制为公开接口：

```php
<?php
function call_bailian_chat(array $messages): array {
    $config = parse_ini_file('/www/wwwroot/chijing_runtime/ai/bailian.env');
    $endpoint = rtrim($config['BAILIAN_BASE_URL'], '/') . '/chat/completions';

    $payload = json_encode([
        'model' => $config['BAILIAN_CHAT_MODEL'] ?? 'qwen-plus',
        'messages' => $messages,
        'stream' => false,
        'temperature' => 0.3,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $config['BAILIAN_API_KEY'],
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($raw === false) throw new RuntimeException('百炼网络请求失败。');
    curl_close($curl);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('百炼调用失败，HTTP ' . $status);
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
```

对网页开放的 `api/ai_chat.php` 必须完成：

1. `require_user()` 与 CSRF 校验；
2. 限制单条文字长度、会话轮数和每用户调用频率；
3. 只读取当前用户可访问的成员、图片和检测记录；
4. 记录时间、模型、Token 用量、耗时和错误码，但不记录 API Key；
5. 对百炼异常返回 JSON，不将底层错误页直接输出给浏览器。

## 7. 系统提示词与上下文边界

建议系统提示词的核心约束：

```text
你是齿镜口腔健康辅助助手。
只能基于当前用户授权的数据和已提供的检测结果回答。
检测结果仅供口腔照片辅助筛查，不能确诊、开处方或替代线下诊疗。
遇到剧烈疼痛、出血不止、面部肿胀、外伤、发热等情况，应建议及时线下就医。
涉及删除记录、解绑设备、切换成员、拍摄或上传图片时，先说明即将执行的动作并请求确认。
```

传给模型的上下文应最小化，例如：

```json
{
  "member": {"name": "小明", "age_group": "儿童"},
  "latest_detection": {
    "created_at": "2026-07-23 20:00:00",
    "summary": "龋齿候选区域 0 个",
    "findings": []
  }
}
```

不要在普通对话中默认传输原图、完整身份证明、密码、设备密钥、上传令牌或其他成员的数据。

## 8. 流式输出、TTS 与 WebSocket 的分工

百炼的 OpenAI 兼容接口可通过 `stream: true` 返回 SSE 数据流。首版建议先完成非流式回复；语音体验稳定后再做流式。

后续推荐链路：

```text
ESP32 WebSocket 上传音频分片
  → AI Gateway 聚合/转发 ASR
  → 得到完整文字
  → 百炼 chat/completions（可 stream=true）
  → 分句送入 TTS
  → WebSocket 下发音频分片给 ESP32 播放
```

PHP 适合登录、数据库与短请求 API；ESP32 的长连接、音频分片、ASR/TTS 流处理应放入独立的 Node.js 或 Python `ai_gateway` 服务，由 systemd 守护。

## 9. MCP 硬件控制规则

大模型不能直接向 ESP32 发送任意文本命令。应由云端定义固定工具，并由后端校验后执行：

| 工具 | 初期权限 |
| --- | --- |
| `get_device_status` | 允许 |
| `get_current_member` | 允许 |
| `get_latest_detection_summary` | 允许 |
| `capture_photo` | 需要语音/屏幕确认 |
| `save_photo` / `start_analysis` | 需要确认 |
| `switch_member` | 需要确认，并限制为当前账户成员 |
| `delete_photo` / `unbind_device` | 禁止语音直接执行，必须网页二次确认 |

建议模型先输出结构化“工具意图”，例如：

```json
{
  "tool": "capture_photo",
  "arguments": {"member_public_id": "当前已选成员"},
  "requires_confirmation": true
}
```

AI Gateway 校验设备归属、用户权限、参数格式和确认状态后，才通过 WebSocket 向 ESP32 下发固定协议命令；ESP32 需回传 `accepted`、`completed` 或 `failed` 回执。

## 10. 实施顺序

1. 在百炼控制台确认标准 API Key、地域、Base URL 和 `qwen-plus` 权限。
2. 创建站点外 `bailian.env`，完成服务器 cURL 连通性测试。
3. 新增 PHP 的文字对话接口，先用网页输入测试用户隔离、限流、日志和医疗提示。
4. 建立独立 `ai_gateway`，先实现 ESP32 WebSocket 文本消息收发。
5. 接入 ASR，完成“语音 → 文字 → 百炼回复”的闭环。
6. 接入 TTS，完成播报。
7. 定义 MCP 工具白名单、确认机制和 ESP32 命令回执。
8. 最后再接入图片/检测结果解释与多轮会话记忆。

## 11. 官方资料

- [Base URL 总览（地域与 Key 配对）](https://help.aliyun.com/zh/model-studio/base-url)
- [通义千问流式输出（OpenAI 兼容）](https://help.aliyun.com/zh/model-studio/stream)
- [百炼产品与 OpenAI 兼容说明](https://help.aliyun.com/zh/model-studio/what-is-model-studio)
- [百炼控制台 API 页面](https://bailian.console.aliyun.com/cn-beijing/?tab=api)
