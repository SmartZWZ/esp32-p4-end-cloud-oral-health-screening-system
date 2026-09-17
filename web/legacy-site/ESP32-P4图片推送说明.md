# ESP32-P4 图片推送与成员归档

ESP32-P4 只负责采集、选择成员、推送 JPEG。模型检测结果由云端模型服务生成并写回网站，设备不需要也不应上传检测结论。

## 推送前流程

1. 从 NVS 读取 `device_token`。
2. 请求 `GET /api/members.php?action=device_list`，获得当前账户成员。
3. 在屏幕选择一位成员，保存其 `public_id`。
4. OV5647 采集并编码 JPEG；单张必须不大于 8 MB。
5. 将 JPEG 原始二进制推送到 `/api/process.php`。

## 图片请求

上传前由设备界面明确选择上传模式：

- **上传图片保存**：请求头发送 `X-Upload-Mode: archive`。云端只保存图片，返回 `status: "saved"`，不会进入模型队列。
- **云端检测**：请求头发送 `X-Upload-Mode: detect`。云端返回 `status: "received"`，模型服务会自动处理。
- 为兼容旧固件，不发送该请求头时仍按 `detect` 处理；新固件必须显式发送，避免误触发模型分析。

```http
POST /api/process.php HTTP/1.1
Host: 8.138.230.100:667
Content-Type: image/jpeg
X-Device-Token: <NVS 中保存的永久设备令牌>
X-Member-Id: <屏幕选中成员的 public_id>
X-Upload-Mode: detect

<JPEG 原始二进制>
```

成功响应：

```json
{
  "ok": true,
  "detection_id": "202607211530001a2b3c4d5e",
  "member_id": "20260721151000f6e7d8c9b0",
  "upload_mode": "detect",
  "status": "received"
}
```

收到 `201` 和 `ok: true` 后才可标记本地上传完成。`archive` 模式提示“图片已保存，可在网页端选择分析”；`detect` 模式提示“图片已上传，正在云端分析”。`detection_id` 可用于本地日志，但不需要轮询模型结果。

## 失败处理

| HTTP 状态 | 含义 | 设备处理 |
|---|---|---|
| `401` | Token 无效或设备已解绑 | 停止上传，提示用户网页重新绑定。 |
| `409` | 云端没有可用成员 | 拉取成员列表并提示网页添加成员。 |
| `422` | 当前成员已删除或不属于账户 | 重新拉取成员列表，要求重新选择。 |
| `413` | JPEG 超过 8 MB | 降低分辨率或 JPEG 质量后重试。 |
| 网络超时 | 无法确认是否上传成功 | 将图片暂存 SD 卡或本地队列，网络恢复后重试。 |

重试同一张图片时，建议 ESP32 为图片保留本地 UUID；当前服务端会将每一次成功请求当作一条独立记录，因此只有在未收到成功响应时才重试。

## 不要做的事

- 不要把 `device_token`、成员 ID 或图片数据打印到串口日志。
- 不要向 `model_result.php` 推送数据；该接口只供部署在云端的模型服务使用。
- 不要在设备端根据模型结果修改网页记录，检测结果以云端模型服务回传为准。
