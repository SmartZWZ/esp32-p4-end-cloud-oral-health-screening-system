# AI

这里存放模型训练、推理、数据处理和实验记录。

建议目录：

- `datasets/`：只放数据集说明或 `.gitkeep`，不直接提交大规模数据。
- `models/`：只放模型说明、导出记录和下载方式，不提交大权重。
- `training/`：训练脚本、数据转换脚本、实验说明。
- `inference/`：推理服务、导出和 benchmark 脚本。
- `notebooks/`：探索性实验。

当前重点：

1. 端侧模型：`Caries/Cavity/Crack -> lesion` 的轻量检测模型。
2. 云端模型：病灶检测/分割模型，部署到 Intel Arc A380 + OpenVINO。
3. 数据策略：补充真实口腔摄像头采集数据，提高小病灶召回率。
