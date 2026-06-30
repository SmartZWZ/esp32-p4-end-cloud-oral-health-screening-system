#include "esp_err.h"
#include "esp_log.h"

#include "app_presenter.h"
#include "board_support.h"

static const char *TAG = "app_main";
static app_presenter_t s_presenter;

void app_main(void)
{
    ESP_LOGI(TAG, "Starting ESP32-P4 MVP application");

    ESP_ERROR_CHECK(board_support_init());
    ESP_ERROR_CHECK(app_presenter_start(&s_presenter, board_support_i2c_bus()));
}
