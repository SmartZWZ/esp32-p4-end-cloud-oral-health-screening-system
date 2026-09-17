# 齿镜 AI 牙医部署与管理说明

## 1. 本次使用的模型

- 主模型：`qwen3.7-plus`
- 备用模型：`qwen3.6-flash`
- 调用方式：百炼工作空间 OpenAI 兼容接口

AI 牙医属于口腔照片辅助筛查，不是医疗诊断。模型必须只描述照片中可见的表现，并明确无法判断的内容。

## 2. 上传代码

将本项目包上传并覆盖到：

```text
/www/wwwroot/8.138.230.100_667
```

图片仍保存在站点包外的：

```text
/www/wwwroot/chijing_storage
```

覆盖代码不会删除历史图片。

## 3. 执行数据库迁移

在宝塔 phpMyAdmin 中选择数据库 `8_138_230_100_666`，执行：

```text
database_ai_dentist_migration.sql
```

已经运行的网站不要重复执行完整的 `database.sql`。

## 4. 补充服务器环境配置

编辑：

```bash
nano /www/wwwroot/chijing_runtime/ai/bailian.env
```

保留原有语音助手配置，在末尾补充：

```ini
BAILIAN_OPENAI_BASE_URL=https://ws-sk9a2fzftxh5to2c.cn-beijing.maas.aliyuncs.com/compatible-mode/v1
AI_DENTIST_PUBLIC_ORIGIN=https://wwwxsh.cn
AI_DENTIST_ADMIN_EMAILS=你的齿镜登录邮箱
```

多个管理员邮箱用英文逗号分隔：

```ini
AI_DENTIST_ADMIN_EMAILS=admin1@example.com,admin2@example.com
```

不要在网页代码、数据库或聊天记录中填写 `BAILIAN_API_KEY`。继续使用该环境文件中已经配置好的密钥。

## 5. 检查 PHP cURL

```bash
/www/server/php/80/bin/php -m | grep -i curl
```

输出 `curl` 即可。若没有输出，在宝塔的 PHP 8.0 扩展管理中安装 `curl`，然后重载 PHP。

## 6. 重新加载 PHP

```bash
systemctl reload php-fpm-80 2>/dev/null || /etc/init.d/php-fpm-80 reload
```

AI 牙医直接由 PHP 调用百炼兼容接口，不需要新增 Python 常驻服务，也不需要在云服务器安装 PyTorch。

## 7. 验证页面

登录后访问：

```text
https://wwwxsh.cn/ai-dentist.html
```

管理员访问：

```text
https://wwwxsh.cn/ai-dentist-admin.html
```

如果管理页面提示没有权限，检查 `AI_DENTIST_ADMIN_EMAILS` 是否与当前登录邮箱完全一致。

进入管理后台后：

1. 确认 API Key、兼容接口、图片签名域名均显示“已配置”。
2. 主模型保持 `qwen3.7-plus`。
3. 备用模型保持 `qwen3.6-flash`。
4. 第一轮保持“启用口腔照片高分辨率理解”。该选项更适合观察细小口腔表现，但会增加图片 Token 消耗。
5. 点击“测试模型连接”。
6. 测试通过后再用一张口腔照片完成正式分析。

连接测试会真实调用一次百炼模型，会产生少量 Token 用量。

## 8. 图片安全链路

浏览器不会收到百炼 API Key。分析时服务器会为选中图片生成最长约 7 分钟有效的签名地址，百炼只能在有效期内读取本次图片。签名失效后，未登录用户不能继续访问图片。

AI 牙医只读取：

- 当前登录账户；
- 当前选中的家庭成员；
- 用户明确选择的 1～6 张图片；
- 用户允许读取的该成员历史摘要；
- 用户允许参考的所选图片本地模型结果。

不会读取其他账户或其他家庭成员的数据。

## 9. 故障日志

PHP/Nginx 错误：

```bash
tail -n 120 /www/wwwlogs/8.138.230.100_667.error.log
```

AI 牙医调用失败、HTTP 状态、请求 ID、Token 和耗时也会保存到管理后台“调用日志”中，但不会保存 API Key。

如果浏览器明确返回 `504 Gateway Timeout`，在当前站点 Nginx 配置的 `server {}` 内加入：

```nginx
fastcgi_read_timeout 180s;
```

随后执行：

```bash
nginx -t && systemctl reload nginx
```

没有出现 504 时不要修改该项。
