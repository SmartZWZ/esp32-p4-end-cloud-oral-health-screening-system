#include "app_presenter.h"

#include "board_support.h"
#include "esp_check.h"
#include "esp_log.h"
#include "lvgl.h"

static const char *TAG = "app_presenter";

static void camera_frame_cb(const uint8_t *buf,
                            uint8_t index,
                            uint32_t width,
                            uint32_t height,
                            size_t len,
                            void *user_data)
{
    (void)buf;
    (void)index;
    (void)width;
    (void)height;
    (void)len;

    app_presenter_t *presenter = (app_presenter_t *)user_data;
    app_model_note_frame(&presenter->model);
}

static void update_view(app_presenter_t *presenter)
{
    if (board_support_display_lock(1000) == ESP_OK) {
        app_view_update(&presenter->view, &presenter->model);
        board_support_display_unlock();
    }
}

static void tick_timer_cb(void *arg)
{
    app_presenter_t *presenter = (app_presenter_t *)arg;
    app_model_tick(&presenter->model);
    update_view(presenter);
}

static void action_cb(app_view_action_t action, void *user_data)
{
    app_presenter_t *presenter = (app_presenter_t *)user_data;

    switch (action) {
    case APP_VIEW_ACTION_CAPTURE:
        app_model_capture(&presenter->model);
        break;
    case APP_VIEW_ACTION_RETAKE:
        app_model_retake(&presenter->model);
        break;
    case APP_VIEW_ACTION_NEXT_VIEW:
        app_model_next_view(&presenter->model);
        break;
    default:
        break;
    }

    app_view_update(&presenter->view, &presenter->model);
}

esp_err_t app_presenter_start(app_presenter_t *presenter, i2c_master_bus_handle_t i2c_bus)
{
    app_model_init(&presenter->model);
    camera_service_init(&presenter->camera);
    camera_service_set_frame_cb(&presenter->camera, camera_frame_cb, presenter);

    ESP_ERROR_CHECK(board_support_display_lock(1000));
    app_view_create(&presenter->view, lv_screen_active());
    app_view_set_action_cb(&presenter->view, action_cb, presenter);
    app_view_update(&presenter->view, &presenter->model);
    board_support_display_unlock();

    esp_err_t ret = camera_service_start(&presenter->camera, i2c_bus);
    if (ret == ESP_OK) {
        app_model_set_camera_state(&presenter->model, APP_CAMERA_STREAMING, "Camera streaming");
    } else {
        ESP_LOGW(TAG, "camera service failed: 0x%x", ret);
        app_model_set_camera_state(&presenter->model, APP_CAMERA_ERROR, "Camera offline");
    }
    update_view(presenter);

    const esp_timer_create_args_t timer_args = {
        .callback = tick_timer_cb,
        .arg = presenter,
        .name = "mvp_tick",
    };
    ESP_RETURN_ON_ERROR(esp_timer_create(&timer_args, &presenter->tick_timer), TAG, "timer create failed");
    ESP_RETURN_ON_ERROR(esp_timer_start_periodic(presenter->tick_timer, 1000 * 1000), TAG, "timer start failed");

    ESP_LOGI(TAG, "MVP presenter started");
    return ESP_OK;
}
