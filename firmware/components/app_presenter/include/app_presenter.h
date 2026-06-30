#pragma once

#include "app_model.h"
#include "app_view.h"
#include "camera_service.h"
#include "driver/i2c_master.h"
#include "esp_err.h"
#include "esp_timer.h"

#ifdef __cplusplus
extern "C" {
#endif

typedef struct {
    app_model_t model;
    app_view_t view;
    camera_service_t camera;
    esp_timer_handle_t tick_timer;
} app_presenter_t;

esp_err_t app_presenter_start(app_presenter_t *presenter, i2c_master_bus_handle_t i2c_bus);

#ifdef __cplusplus
}
#endif
