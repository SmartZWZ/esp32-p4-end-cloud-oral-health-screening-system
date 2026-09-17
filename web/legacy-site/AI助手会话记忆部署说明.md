# 齿镜 AI 助手会话记忆部署说明

## 本次功能

- 网页助手和 ESP32-P4 设备助手都可以延续同一对话。
- 两端数据完全隔离，网页对话不会进入设备对话，设备对话也不会进入网页对话。
- 每次连接百炼时参考最近 10 轮；更早内容由百炼文本模型压缩成最多 2000 字的摘要。
- 上下文总长度最多 12000 字。
- 用户可以按对话关闭记忆、查看或清除摘要、清空消息、重命名和永久删除。
- 设备 30 分钟没有产生新消息后，下一次申请会话会自动创建新对话。
- 管理员可以设置参考轮数、设备空闲时间、数据保留天数和摘要模型。

## 一、上传文件

将本次修改后的文件按原目录上传，不能只上传页面文件：

- `api/assistant.php`
- `api/assistant_device.php`
- `api/device_assistant.php`
- `api/assistant_memory_common.php`
- `api/assistant_context.php`
- `ai_gateway/gateway.py`
- `assistant.html`
- `device-assistant.html`
- `assets/js/assistant.js`
- `assets/js/device-assistant.js`
- `assets/css/assistant-memory.css`
- `database_ai_assistant_memory_migration.sql`

## 二、执行数据库迁移

在宝塔进入数据库管理或 phpMyAdmin，先点击选择齿镜当前使用的数据库，再打开“SQL”，完整执行：

`database_ai_assistant_memory_migration.sql`

不要重复执行旧的 `database.sql`，也不要删除现有表。该迁移只新增记忆字段和设置表，会保留当前网页、设备对话及消息。

执行后检查：

```sql
SHOW COLUMNS FROM ai_conversations;
SHOW COLUMNS FROM ai_device_conversations;
SELECT * FROM ai_assistant_memory_settings WHERE id=1;
```

应能看到 `memory_enabled`、`summary_text`、`context_status`、`last_message_at` 等字段；设备表还应有 `is_active`。

## 三、检查百炼文本接口配置

打开服务器运行时配置：

```bash
nano /www/wwwroot/chijing_runtime/ai/bailian.env
```

确认已有以下两项。API Key 使用你自己的真实值，不要把密钥写进网站代码包：

```ini
BAILIAN_API_KEY=你的百炼工作空间APIKey
BAILIAN_OPENAI_BASE_URL=https://ws-sk9a2fzftxh5to2c.cn-beijing.maas.aliyuncs.com/compatible-mode/v1
```

可选地显式加入内部上下文地址：

```ini
AI_GATEWAY_CONTEXT_URL=https://wwwxsh.cn/api/assistant_context.php
```

如果不写这一项，网关会根据 `AI_GATEWAY_ALLOWED_ORIGIN=https://wwwxsh.cn` 自动生成相同地址。

保存 nano：`Ctrl+O`、回车、`Ctrl+X`。

## 四、重启网关

```bash
AI_PYTHON=/www/wwwroot/chijing_runtime/ai_gateway_venv/bin/python
GATEWAY=/www/wwwroot/8.138.230.100_667/ai_gateway/gateway.py

"$AI_PYTHON" -m py_compile "$GATEWAY"
systemctl restart chijing-ai-gateway.service
systemctl status chijing-ai-gateway.service --no-pager
```

状态必须为 `active (running)`。

## 五、验证内部上下文接口

直接用浏览器或无密钥 curl 请求会返回 401，这是正确行为；该接口只能由本机 AI 网关携带 `X-AI-Gateway-Secret` 调用。

先进行一次网页语音对话，再进行第二次语音提问。实时观察：

```bash
journalctl -u chijing-ai-gateway.service -f
```

页面标题下方应显示：

- `已参考最近 X 轮 · 摘要尚未生成`；或
- `已参考最近 X 轮 · 摘要已就绪`。

只有当对话消息超过最近 10 轮窗口时才需要生成旧历史摘要，因此刚开始显示“摘要尚未生成”是正常的。

## 六、管理员设置

进入网页“助手 → 对话设置 → 全局记忆管理（管理员）”：

- 参考轮数：默认 10。
- 设备空闲分段：默认 30 分钟。
- 保留天数：默认 0，代表永久保存。
- 摘要模型：默认 `qwen-plus`。

管理员身份仍由运行时配置中的 `AI_DENTIST_ADMIN_EMAILS` 判断。普通用户看不到全局管理项，但可以管理自己的每个对话。

## 七、回退原则

- 历史接口失败时，当前语音仍会继续，不会因为记忆故障中断。
- 模型会提示本轮未参考历史。
- 摘要失败时会保留原摘要，并继续使用最近消息。
- 删除对话只删除对应助手对话和消息；不会删除成员、图片、检测记录和设备绑定。

