# Shared

端侧、云端和 Web 共用的接口协议、数据结构和示例数据放在这里。

- `api-contracts/`：OpenAPI、HTTP 接口、设备通信协议。
- `schemas/`：JSON Schema、返回结果格式。
- `sample-data/`：示例请求、示例响应、模拟推理结果。

建议统一约定云端推理返回格式：

```json
{
  "image_id": "sample-001",
  "detections": [
    {
      "class_name": "lesion",
      "score": 0.82,
      "box": [0.32, 0.41, 0.18, 0.12],
      "mask": null
    }
  ]
}
```
