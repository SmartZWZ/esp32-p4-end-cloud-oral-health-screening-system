# 齿镜设备 AI 语音助手：部署与操作指南

本功能将“网页助手”和“齿镜设备助手”彻底分开：

- 网页端继续使用 `assistant.html`，记录保存在 `ai_conversations`、`ai_messages`。
- ESP32-P4 端使用新的设备短期票据，记录只保存在 `ai_device_conversations`、`ai_device_messages`。
- 当前版本只做语音问答，不会下发或执行任何硬件控制指令。

## 一、需要上传的文件

把本次本地项目中以下文件上传并覆盖到服务器网站根目录 `/www/wwwroot/8.138.230.100_667/` 对应位置：

```text
api/assistant_device.php
api/device_assistant.php
ai_gateway/gateway.py
ai_gateway/opus_codec.py
assets/js/app.js
assets/js/device-assistant.js
assets/css/device-assistant.css
devices.html
device-assistant.html
database_ai_device_assistant_migration.sql
```

不要覆盖服务器运行目录中的以下文件或目录：

```text
/www/wwwroot/chijing_runtime/ai/bailian.env
/www/wwwroot/chijing_runtime/ai_gateway_venv/
/www/wwwroot/chijing_storage/
```

其中 `bailian.env` 含 API 密钥，`chijing_storage` 保存历史图片，均不应随网页代码包替换。

设备端使用 Opus 音频包。服务器需要系统 Opus 运行库；首次部署或本次升级时执行：

```bash
apt-get update
apt-get install -y libopus0
```

## 二、执行数据库迁移

在宝塔“数据库 → phpMyAdmin”中选择数据库 `8_138_230_100_666`，导入或执行：

```text
database_ai_device_assistant_migration.sql
```

成功后应新增两张表：

```text
ai_device_conversations
ai_device_messages
```

不要重新执行整个 `database.sql`；这次只执行上面的设备助手迁移文件。

## 三、重启服务

在服务器终端执行：

```bash
systemctl restart chijing-ai-gateway.service
systemctl is-active chijing-ai-gateway.service
```

第二条应输出：

```text
active
```

然后在宝塔“软件商店 → PHP 8.0 → 服务”中点击“重启”。这是为了让 PHP 立即加载新增接口。

当前 Nginx 已有 `/ai-gateway/` 的反向代理规则，因此**本次不需要改 Nginx 配置，也不需要重启 Nginx**。

如需排查网关启动错误：

```bash
journalctl -u chijing-ai-gateway.service -n 80 --no-pager
```

不要在日志截图中暴露 `bailian.env` 内的密钥。

## 三点一、临时保存设备上行音频

为排查 ESP32-P4 麦克风、Opus 编码和 WebSocket 上传问题，网关会把成功解码的设备上行音频保存成 WAV：

```text
/www/wwwroot/chijing_storage/ai_audio_debug/YYYYMMDD/*.wav
```

WAV 格式为 16 kHz、单声道、16-bit PCM，可直接下载到电脑播放。单轮最多保存 180 秒。首次启用前执行：

```bash
install -d -o www -g www -m 750 /www/wwwroot/chijing_storage/ai_audio_debug
```

`bailian.env` 可配置：

```ini
AI_GATEWAY_CAPTURE_DEVICE_AUDIO=1
AI_GATEWAY_CAPTURE_DIR=/www/wwwroot/chijing_storage/ai_audio_debug
```

修改后重启：

```bash
systemctl restart chijing-ai-gateway.service
```

测试完成后建议将 `AI_GATEWAY_CAPTURE_DEVICE_AUDIO` 改为 `0` 并重启服务，避免长期保存用户原始语音。

## 四、网页端检查

1. 浏览器打开并登录 `https://wwwxsh.cn/devices.html`。
2. 在“绑定设备”旁点击“设备对话记录”。
3. 首次没有设备语音会话时，页面显示“该设备还没有语音对话记录”属于正常状态。
4. 硬件完成一次语音问答后，刷新该页面。
5. 左侧按设备筛选会话；点击某次会话，可分别看到“设备听到”和“齿镜助手”的文字记录。

该页面为只读审计页，不能编辑、发送或删除设备对话，避免网页与硬件端的记录混淆。

## 五、设备端实际使用流程

1. 设备已经完成现有绑定流程，并保存有效的 `X-Device-Token`。
2. 用户在设备屏幕上**按住“按住说话”区域**。
3. 设备向服务器申请一次性短期设备票据，建立实时语音连接，然后持续上传原始 Opus 音频包。
4. 用户松开屏幕，设备停止采集并提交本轮音频。
5. 设备播放助手语音，并把用户转写和助手回复分别保存为设备记录。
6. 网页“设备对话记录”会显示这次对话。

如果设备断网、票据过期或 WebSocket 断开，屏幕应提示“语音服务连接失败，请重试”，并回到空闲状态；下一次按住时重新申请票据即可。

## 六、安全边界

- 设备长期只保存自己的 `X-Device-Token`；不要保存百炼 API Key、AI 网关密钥或短期票据。
- 设备票据有效期只有 60 秒且仅能连接一次，只能连接 `/ai-gateway/device/`，只能代表签发它的设备。
- 票据不能放入日志、截图、二维码或长期配置文件。
- 设备使用 HTTPS/WSS：`https://wwwxsh.cn` 与 `wss://wwwxsh.cn`。
- 当前协议没有 MCP、Function Calling 或任何控制设备的命令；模型回复仅用于显示、播报和留档。
