#include "camera_service.h"

#include <fcntl.h>
#include <inttypes.h>
#include <string.h>
#include <sys/ioctl.h>
#include <unistd.h>

#include "bsp/esp-bsp.h"
#include "esp_cache.h"
#include "esp_check.h"
#include "esp_heap_caps.h"
#include "esp_log.h"
#include "esp_private/esp_cache_private.h"
#include "esp_video_device.h"
#include "esp_video_init.h"
#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

static const char *TAG = "camera_service";

static esp_err_t init_video_driver(i2c_master_bus_handle_t i2c_bus)
{
    esp_video_init_csi_config_t csi_config[] = {
        {
            .sccb_config = {
                .init_sccb = false,
                .i2c_handle = i2c_bus,
                .freq = CONFIG_BSP_I2C_CLK_SPEED_HZ,
            },
            .reset_pin = -1,
            .pwdn_pin = -1,
        },
    };

    esp_video_init_config_t cam_config = {
        .csi = csi_config,
    };

    return esp_video_init(&cam_config);
}

static int open_video_device(camera_service_t *service)
{
    const int type = V4L2_BUF_TYPE_VIDEO_CAPTURE;
    struct v4l2_capability capability = {0};
    struct v4l2_format format = {0};

    int fd = open(ESP_VIDEO_MIPI_CSI_DEVICE_NAME, O_RDONLY);
    if (fd < 0) {
        ESP_LOGE(TAG, "open %s failed", ESP_VIDEO_MIPI_CSI_DEVICE_NAME);
        return -1;
    }

    if (ioctl(fd, VIDIOC_QUERYCAP, &capability) != 0) {
        ESP_LOGE(TAG, "VIDIOC_QUERYCAP failed");
        close(fd);
        return -1;
    }

    ESP_LOGI(TAG, "driver=%s card=%s bus=%s version=%u.%u.%u",
             capability.driver,
             capability.card,
             capability.bus_info,
             (uint16_t)(capability.version >> 16),
             (uint8_t)(capability.version >> 8),
             (uint8_t)capability.version);

    format.type = type;
    if (ioctl(fd, VIDIOC_G_FMT, &format) != 0) {
        ESP_LOGE(TAG, "VIDIOC_G_FMT failed");
        close(fd);
        return -1;
    }

    service->width = format.fmt.pix.width;
    service->height = format.fmt.pix.height;
    ESP_LOGI(TAG, "camera format width=%" PRIu32 " height=%" PRIu32 " pixfmt=0x%08" PRIx32,
             service->width, service->height, format.fmt.pix.pixelformat);

    if (format.fmt.pix.pixelformat != CAMERA_SERVICE_DEFAULT_FMT) {
        format.fmt.pix.pixelformat = CAMERA_SERVICE_DEFAULT_FMT;
        if (ioctl(fd, VIDIOC_S_FMT, &format) != 0) {
            ESP_LOGE(TAG, "VIDIOC_S_FMT failed");
            close(fd);
            return -1;
        }
    }

    return fd;
}

static uint32_t frame_bytes_per_pixel(void)
{
    return CAMERA_SERVICE_DEFAULT_FMT == CAMERA_SERVICE_FMT_RGB565 ? 2 : 3;
}

static esp_err_t allocate_buffers(camera_service_t *service)
{
    size_t cache_line_size = 0;
    ESP_ERROR_CHECK(esp_cache_get_alignment(MALLOC_CAP_SPIRAM, &cache_line_size));

    service->buffer_count = 2;
    service->buffer_size = service->width * service->height * frame_bytes_per_pixel();

    for (int i = 0; i < service->buffer_count; i++) {
        service->buffers[i] = heap_caps_aligned_calloc(cache_line_size,
                                                       1,
                                                       service->buffer_size,
                                                       MALLOC_CAP_SPIRAM);
        if (service->buffers[i] == NULL) {
            ESP_LOGE(TAG, "failed to allocate camera buffer %d", i);
            return ESP_ERR_NO_MEM;
        }
    }

    return ESP_OK;
}

static esp_err_t configure_buffers(camera_service_t *service)
{
    struct v4l2_requestbuffers req = {0};
    const int type = V4L2_BUF_TYPE_VIDEO_CAPTURE;

    req.count = service->buffer_count;
    req.type = type;
    req.memory = V4L2_MEMORY_USERPTR;
    service->memory_mode = req.memory;

    if (ioctl(service->video_fd, VIDIOC_REQBUFS, &req) != 0) {
        ESP_LOGE(TAG, "VIDIOC_REQBUFS failed");
        return ESP_FAIL;
    }

    for (int i = 0; i < service->buffer_count; i++) {
        struct v4l2_buffer buf = {0};
        buf.type = type;
        buf.memory = req.memory;
        buf.index = i;

        if (ioctl(service->video_fd, VIDIOC_QUERYBUF, &buf) != 0) {
            ESP_LOGE(TAG, "VIDIOC_QUERYBUF failed");
            return ESP_FAIL;
        }

        buf.m.userptr = (unsigned long)service->buffers[i];
        buf.length = service->buffer_size;

        if (ioctl(service->video_fd, VIDIOC_QBUF, &buf) != 0) {
            ESP_LOGE(TAG, "VIDIOC_QBUF failed");
            return ESP_FAIL;
        }
    }

    return ESP_OK;
}

static void stream_task(void *arg)
{
    camera_service_t *service = (camera_service_t *)arg;
    const int type = V4L2_BUF_TYPE_VIDEO_CAPTURE;

    if (ioctl(service->video_fd, VIDIOC_STREAMON, &type) != 0) {
        ESP_LOGE(TAG, "VIDIOC_STREAMON failed");
        service->stop_requested = true;
    }

    while (!service->stop_requested) {
        struct v4l2_buffer buf = {0};
        buf.type = type;
        buf.memory = service->memory_mode;

        if (ioctl(service->video_fd, VIDIOC_DQBUF, &buf) != 0) {
            ESP_LOGE(TAG, "VIDIOC_DQBUF failed");
            vTaskDelay(pdMS_TO_TICKS(20));
            continue;
        }

        uint8_t index = (uint8_t)buf.index;
        if (index < service->buffer_count && service->frame_cb != NULL) {
            service->frame_cb(service->buffers[index],
                              index,
                              service->width,
                              service->height,
                              service->buffer_size,
                              service->frame_user_data);
        }

        buf.m.userptr = (unsigned long)service->buffers[index];
        buf.length = service->buffer_size;
        if (ioctl(service->video_fd, VIDIOC_QBUF, &buf) != 0) {
            ESP_LOGE(TAG, "VIDIOC_QBUF failed after frame");
            break;
        }
    }

    ioctl(service->video_fd, VIDIOC_STREAMOFF, &type);
    service->task_handle = NULL;
    vTaskDelete(NULL);
}

void camera_service_init(camera_service_t *service)
{
    memset(service, 0, sizeof(*service));
    service->video_fd = -1;
}

void camera_service_set_frame_cb(camera_service_t *service, camera_service_frame_cb_t cb, void *user_data)
{
    service->frame_cb = cb;
    service->frame_user_data = user_data;
}

esp_err_t camera_service_start(camera_service_t *service, i2c_master_bus_handle_t i2c_bus)
{
    ESP_RETURN_ON_ERROR(init_video_driver(i2c_bus), TAG, "esp_video_init failed");

    service->video_fd = open_video_device(service);
    if (service->video_fd < 0) {
        return ESP_FAIL;
    }

    ESP_RETURN_ON_ERROR(allocate_buffers(service), TAG, "buffer allocation failed");
    ESP_RETURN_ON_ERROR(configure_buffers(service), TAG, "buffer setup failed");

    service->stop_requested = false;
    BaseType_t ok = xTaskCreatePinnedToCore(stream_task,
                                            "camera_stream",
                                            4096,
                                            service,
                                            4,
                                            (TaskHandle_t *)&service->task_handle,
                                            0);
    if (ok != pdPASS) {
        ESP_LOGE(TAG, "failed to create camera stream task");
        return ESP_FAIL;
    }

    return ESP_OK;
}

esp_err_t camera_service_stop(camera_service_t *service)
{
    service->stop_requested = true;
    return ESP_OK;
}

bool camera_service_is_streaming(const camera_service_t *service)
{
    return service->task_handle != NULL && !service->stop_requested;
}
