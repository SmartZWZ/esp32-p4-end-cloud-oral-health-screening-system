idf_component_register(SRCS "src/board_support.c"
                       INCLUDE_DIRS "include"
                       REQUIRES waveshare__esp32_p4_platform lvgl__lvgl esp_driver_i2c)
