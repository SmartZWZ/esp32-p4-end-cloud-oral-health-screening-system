#pragma once

#include "driver/i2c_master.h"
#include "esp_err.h"
#include "lvgl.h"

#ifdef __cplusplus
extern "C" {
#endif

esp_err_t board_support_init(void);
lv_display_t *board_support_display(void);
i2c_master_bus_handle_t board_support_i2c_bus(void);
esp_err_t board_support_display_lock(uint32_t timeout_ms);
void board_support_display_unlock(void);

#ifdef __cplusplus
}
#endif
