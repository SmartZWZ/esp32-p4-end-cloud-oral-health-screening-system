#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "driver/i2c_master.h"
#include "esp_err.h"
#include "linux/videodev2.h"
#include "sdkconfig.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef enum {
    CAMERA_SERVICE_FMT_RAW8 = V4L2_PIX_FMT_SBGGR8,
    CAMERA_SERVICE_FMT_RAW10 = V4L2_PIX_FMT_SBGGR10,
    CAMERA_SERVICE_FMT_RGB565 = V4L2_PIX_FMT_RGB565,
    CAMERA_SERVICE_FMT_RGB888 = V4L2_PIX_FMT_RGB24,
} camera_service_fmt_t;

#if CONFIG_BSP_LCD_COLOR_FORMAT_RGB565
#define CAMERA_SERVICE_DEFAULT_FMT CAMERA_SERVICE_FMT_RGB565
#else
#define CAMERA_SERVICE_DEFAULT_FMT CAMERA_SERVICE_FMT_RGB888
#endif

typedef void (*camera_service_frame_cb_t)(const uint8_t *buf,
                                          uint8_t index,
                                          uint32_t width,
                                          uint32_t height,
                                          size_t len,
                                          void *user_data);

typedef struct {
    int video_fd;
    uint8_t buffer_count;
    uint8_t *buffers[2];
    size_t buffer_size;
    uint32_t width;
    uint32_t height;
    uint8_t memory_mode;
    bool stop_requested;
    void *task_handle;
    camera_service_frame_cb_t frame_cb;
    void *frame_user_data;
} camera_service_t;

void camera_service_init(camera_service_t *service);
void camera_service_set_frame_cb(camera_service_t *service, camera_service_frame_cb_t cb, void *user_data);
esp_err_t camera_service_start(camera_service_t *service, i2c_master_bus_handle_t i2c_bus);
esp_err_t camera_service_stop(camera_service_t *service);
bool camera_service_is_streaming(const camera_service_t *service);

#ifdef __cplusplus
}
#endif
