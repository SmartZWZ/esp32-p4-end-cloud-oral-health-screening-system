# ESP32-P4 MVP Project

这个工程是面向 Waveshare ESP32-P4-Module-DEV-KIT、Waveshare 7-DSI-TOUCH-A 屏幕和 OV5647 摄像头的 ESP-IDF MVP 架构骨架。

## 结构

```text
main/                     app_main 入口和托管组件依赖
components/board_support  Waveshare BSP 显示、触摸、I2C 初始化
components/camera_service OV5647/esp_video 摄像头服务
components/app_model      Model，保存设备状态和采集状态
components/app_view       View，LVGL v9 触摸界面
components/app_presenter  Presenter，协调 Model/View/摄像头服务
```

## 已加载的关键依赖

- `waveshare/esp32_p4_platform`：Waveshare ESP32-P4 BSP。
- `lvgl/lvgl`：LVGL v9 UI。
- `esp_video`：ESP32-P4 MIPI-CSI 摄像头驱动路径。

`sdkconfig.defaults` 已选择：

- `CONFIG_BSP_LCD_TYPE_720_1280_7_INCH_A=y`
- `CONFIG_CAMERA_OV5647=y`
- `CONFIG_CAMERA_OV5647_MIPI_RAW8_800x1280_50FPS=y`
- `CONFIG_BSP_LCD_DPI_BUFFER_NUMS=3`
- PSRAM 200 MHz、16 MB flash、自定义 15 MB factory 分区。

## ESP32-P4 revision

你的 ESP32-P4-Module-DEV-KIT v1.3 属于 pre-v3 构建路径，本工程默认使用：

- `CONFIG_ESP32P4_SELECTS_REV_LESS_V3=y`
- `CONFIG_ESP32P4_REV_MIN_1=y`

不要使用 `esp32p4_rev_v3_0.defaults` 或 `esp32p4_rev_v3_1.defaults`，否则生成的固件和 v1.x 芯片不兼容。

## 构建

在 ESP-IDF v6.0.1 terminal 中执行：

```powershell
cd D:\Projects\Esp32P4Projects\esp32p4_mvp
idf.py -D SDKCONFIG_DEFAULTS="sdkconfig.defaults;..\esp32-p4-platform\config\esp32p4_rev_pre_v3.defaults" set-target esp32p4
idf.py build
```

切换 revision overlay 前，先删除已有 `sdkconfig`，避免旧配置覆盖 defaults：

```powershell
Remove-Item .\sdkconfig -Force -ErrorAction SilentlyContinue
Remove-Item .\build -Recurse -Force -ErrorAction SilentlyContinue
```

## 运行

```powershell
idf.py -p PORT flash monitor
```

把 `PORT` 替换成你的开发板串口。启动后预期现象：

- 屏幕背光点亮，显示 MVP 状态界面。
- 触摸按钮可以改变采集计数和视图状态。
- 摄像头服务会初始化 OV5647 并记录帧计数；如果摄像头 FPC 或 sensor 配置异常，UI 会显示 camera offline，串口会打印失败原因。

## 端侧筛查职责

固件侧优先完成稳定采集、交互提示和端云传输：

- 摄像头采集与实时预览
- LED 补光控制
- ToF 距离检测
- IMU 姿态与抖动检测
- LVGL 触摸屏界面
- 图像质量检测
- 图像压缩与上传
- 端侧轻量模型推理或粗筛提示

端侧模型第一阶段建议只做 `lesion` 单类检测，不在 ESP32-P4 上做复杂分割。
