# Backend

云端后端服务放在这里。

推荐技术路线：

- FastAPI 或 Node.js
- MySQL/PostgreSQL
- 对象存储保存口腔图像
- OpenVINO/PyTorch/ONNX Runtime 模型推理接口
- 报告生成与历史记录管理

核心功能：

- 用户登录与权限
- 设备绑定
- 家庭成员档案
- 图像上传
- 端侧粗筛结果接收
- 云端识别结果管理
- 筛查报告生成与历史查询

建议优先实现：

1. `/api/v1/images/upload`
2. `/api/v1/inference/oral-lesion`
3. `/api/v1/reports`
