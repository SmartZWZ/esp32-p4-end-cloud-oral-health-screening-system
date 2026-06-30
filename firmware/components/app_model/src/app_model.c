#include "app_model.h"

#include <stdio.h>
#include <string.h>

static const char *const s_view_names[] = {
    "View A",
    "View B",
    "View C",
    "View D",
};

void app_model_init(app_model_t *model)
{
    memset(model, 0, sizeof(*model));
    model->distance_mm = 36;
    model->tilt_deg = 4;
    model->led_percent = 50;
    model->stable = true;
    model->camera_state = APP_CAMERA_STARTING;
    model->quality_state = APP_QUALITY_UNKNOWN;
    snprintf(model->guide_text, sizeof(model->guide_text), "Align target and hold steady");
    snprintf(model->camera_text, sizeof(model->camera_text), "Camera starting");
}

void app_model_tick(app_model_t *model)
{
    model->tick_count++;

    model->distance_mm = 24 + (int)((model->tick_count * 7) % 34);
    model->tilt_deg = (int)((model->tick_count * 5) % 25);
    model->led_percent = 35 + (int)((model->tick_count * 9) % 55);
    model->stable = (model->tick_count % 5) != 0;

    const bool distance_ok = model->distance_mm >= 30 && model->distance_mm <= 46;
    const bool tilt_ok = model->tilt_deg <= 12;

    if (!distance_ok) {
        snprintf(model->guide_text, sizeof(model->guide_text),
                 model->distance_mm < 30 ? "Move closer" : "Move back slightly");
        model->quality_state = APP_QUALITY_WARN;
    } else if (!tilt_ok) {
        snprintf(model->guide_text, sizeof(model->guide_text), "Reduce tilt");
        model->quality_state = APP_QUALITY_WARN;
    } else if (!model->stable) {
        snprintf(model->guide_text, sizeof(model->guide_text), "Hold steady");
        model->quality_state = APP_QUALITY_WARN;
    } else {
        snprintf(model->guide_text, sizeof(model->guide_text), "Ready to capture");
        model->quality_state = APP_QUALITY_GOOD;
    }
}

void app_model_capture(app_model_t *model)
{
    if (model->capture_count < 4) {
        model->capture_count++;
    }
    snprintf(model->guide_text, sizeof(model->guide_text), "Captured frame %u", model->capture_count);
}

void app_model_retake(app_model_t *model)
{
    if (model->capture_count > 0) {
        model->capture_count--;
    }
    snprintf(model->guide_text, sizeof(model->guide_text), "Retake current view");
}

void app_model_next_view(app_model_t *model)
{
    model->current_view = (uint8_t)((model->current_view + 1) % 4);
    snprintf(model->guide_text, sizeof(model->guide_text), "Next view selected");
}

void app_model_set_camera_state(app_model_t *model, app_camera_state_t state, const char *message)
{
    model->camera_state = state;
    snprintf(model->camera_text, sizeof(model->camera_text), "%s", message ? message : "");
}

void app_model_note_frame(app_model_t *model)
{
    model->frame_count++;
    if (model->camera_state != APP_CAMERA_STREAMING) {
        app_model_set_camera_state(model, APP_CAMERA_STREAMING, "Camera streaming");
    }
}

const char *app_model_view_name(const app_model_t *model)
{
    return s_view_names[model->current_view % 4];
}
