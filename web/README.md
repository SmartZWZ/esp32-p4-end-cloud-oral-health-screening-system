# Web Frontend

云端监控前端放在 `web/public/`，当前版本是静态页面，不需要构建步骤。

线上入口：

- HTTPS: `https://user.chijing.xyz:2437/`
- HTTP: `http://user.chijing.xyz:1437/`

## 当前页面

第一版先保留一个侧边栏和一个实时监控功能，后续可以继续扩展报告、设备、模型等模块。

页面四个监控框：

1. 原始视频：显示 ESP32 通过视频流服务上传的实时画面。
2. 单片机拍照：显示 ESP32 通过图片上传接口提交的最新照片。
3. 检测视频：定时抽取实时视频帧，请求 YOLO 推理并绘制检测框。
4. 照片检测：对最新上传照片请求 YOLO 推理并绘制检测框。

## 前端代理路径

`user.chijing.xyz` 由 Nginx 反向代理到现有云端服务：

- `GET /video/health` -> `127.0.0.1:8888/health`
- `GET /image/health` -> `127.0.0.1:8889/health`
- `GET /yolo/health` -> `127.0.0.1:8010/health`
- `WS /video-viewer` -> `127.0.0.1:8888/viewer`
- `GET /image/latest.jpg` -> `127.0.0.1:8889/latest.jpg`
- `GET /image/events` -> `127.0.0.1:8889/events`
- `POST /yolo/api/v1/yolo/image` -> `127.0.0.1:8010/api/v1/yolo/image`
- `POST /yolo/api/v1/yolo/video-frame` -> `127.0.0.1:8010/api/v1/yolo/video-frame`

## 部署位置

服务器静态目录：

```text
/opt/tooth-user-web
```

Nginx 配置：

```text
/www/server/panel/vhost/nginx/user.chijing.xyz.conf
```
