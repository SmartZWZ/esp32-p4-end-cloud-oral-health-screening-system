idf_component_register(SRCS "src/camera_service.c"
                       INCLUDE_DIRS "include"
                       REQUIRES espressif__esp_video waveshare__esp32_p4_platform esp_driver_i2c esp_driver_gpio heap)
