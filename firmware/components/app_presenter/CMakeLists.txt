idf_component_register(SRCS "src/app_presenter.c"
                       INCLUDE_DIRS "include"
                       REQUIRES app_model app_view board_support camera_service esp_timer)
