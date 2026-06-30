#pragma once

#include <stdbool.h>
#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

typedef enum {
    APP_CAMERA_OFFLINE = 0,
    APP_CAMERA_STARTING,
    APP_CAMERA_STREAMING,
    APP_CAMERA_ERROR,
} app_camera_state_t;

typedef enum {
    APP_QUALITY_UNKNOWN = 0,
    APP_QUALITY_GOOD,
    APP_QUALITY_WARN,
} app_quality_state_t;

typedef struct {
    uint32_t tick_count;
    uint32_t frame_count;
    uint8_t capture_count;
    uint8_t current_view;
    int distance_mm;
    int tilt_deg;
    int led_percent;
    bool stable;
    app_camera_state_t camera_state;
    app_quality_state_t quality_state;
    char guide_text[80];
    char camera_text[80];
} app_model_t;

void app_model_init(app_model_t *model);
void app_model_tick(app_model_t *model);
void app_model_capture(app_model_t *model);
void app_model_retake(app_model_t *model);
void app_model_next_view(app_model_t *model);
void app_model_set_camera_state(app_model_t *model, app_camera_state_t state, const char *message);
void app_model_note_frame(app_model_t *model);
const char *app_model_view_name(const app_model_t *model);

#ifdef __cplusplus
}
#endif
