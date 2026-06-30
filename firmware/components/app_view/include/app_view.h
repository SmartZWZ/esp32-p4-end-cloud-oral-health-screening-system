#pragma once

#include "app_model.h"
#include "lvgl.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef enum {
    APP_VIEW_ACTION_CAPTURE = 0,
    APP_VIEW_ACTION_RETAKE,
    APP_VIEW_ACTION_NEXT_VIEW,
} app_view_action_t;

typedef void (*app_view_action_cb_t)(app_view_action_t action, void *user_data);

typedef struct app_view_t app_view_t;
typedef struct {
    app_view_t *view;
    app_view_action_t action;
} app_view_button_ctx_t;

struct app_view_t {
    lv_obj_t *view_label;
    lv_obj_t *capture_label;
    lv_obj_t *guide_label;
    lv_obj_t *camera_label;
    lv_obj_t *frame_label;
    lv_obj_t *distance_label;
    lv_obj_t *tilt_label;
    lv_obj_t *led_label;
    lv_obj_t *quality_label;
    lv_obj_t *capture_button;
    lv_obj_t *retake_button;
    lv_obj_t *next_button;
    app_view_action_cb_t action_cb;
    void *action_user_data;
    app_view_button_ctx_t capture_ctx;
    app_view_button_ctx_t retake_ctx;
    app_view_button_ctx_t next_ctx;
};

void app_view_create(app_view_t *view, lv_obj_t *root);
void app_view_set_action_cb(app_view_t *view, app_view_action_cb_t cb, void *user_data);
void app_view_update(app_view_t *view, const app_model_t *model);

#ifdef __cplusplus
}
#endif
