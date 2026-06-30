#include "board_support.h"

#include "bsp/display.h"
#include "bsp/esp-bsp.h"
#include "esp_check.h"
#include "esp_log.h"

static const char *TAG = "board_support";
static lv_display_t *s_display;

esp_err_t board_support_init(void)
{
    if (s_display != NULL) {
        return ESP_OK;
    }

    bsp_display_cfg_t cfg = {
        .lv_adapter_cfg = ESP_LV_ADAPTER_DEFAULT_CONFIG(),
        .rotation = ESP_LV_ADAPTER_ROTATE_0,
        .tear_avoid_mode = ESP_LV_ADAPTER_TEAR_AVOID_MODE_TRIPLE_PARTIAL,
        .touch_flags = {
            .swap_xy = 0,
            .mirror_x = 0,
            .mirror_y = 0,
        },
    };

    s_display = bsp_display_start_with_config(&cfg);
    ESP_RETURN_ON_FALSE(s_display != NULL, ESP_FAIL, TAG, "failed to start BSP display");

    bsp_display_backlight_on();
    ESP_LOGI(TAG, "Waveshare BSP display started");
    return ESP_OK;
}

lv_display_t *board_support_display(void)
{
    return s_display;
}

i2c_master_bus_handle_t board_support_i2c_bus(void)
{
    return bsp_i2c_get_handle();
}

esp_err_t board_support_display_lock(uint32_t timeout_ms)
{
    return bsp_display_lock(timeout_ms);
}

void board_support_display_unlock(void)
{
    bsp_display_unlock();
}
