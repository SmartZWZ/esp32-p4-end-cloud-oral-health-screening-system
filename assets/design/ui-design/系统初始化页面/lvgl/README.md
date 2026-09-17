# DentiScope initialization screen (LVGL)

This module targets LVGL 9.x and a 720 x 1080 portrait display. It uses the approved English copy and switches from `INITIALIZING SYSTEM` to `START` after three seconds in visual-test mode.

## Add to an ESP-IDF component

Add `dentscope_init_screen.c` to the component's `SRCS`, and add this folder to its `INCLUDE_DIRS`.

```cmake
idf_component_register(
    SRCS "main.c" "dentscope_init_screen.c"
    INCLUDE_DIRS "."
    REQUIRES lvgl
)
```

Enable these fonts in `lv_conf.h` for the closest visual match:

```c
#define LV_FONT_MONTSERRAT_16 1
#define LV_FONT_MONTSERRAT_20 1
#define LV_FONT_MONTSERRAT_28 1
#define LV_FONT_MONTSERRAT_32 1
#define LV_FONT_MONTSERRAT_64 1
```

## Use in the application

```c
static void open_capture_screen(void * user_data)
{
    /* Replace with your capture screen navigation. */
}

static dentscope_init_screen_t * init_screen;

void app_show_startup(void)
{
    dentscope_init_screen_config_t config = {
        .time_text = "10:42",
        .meridiem_text = "AM",
        .date_text = "2026/7/18",
        .auto_complete_ms = DENTISCOPE_INIT_TEST_DELAY_MS,
        .on_start = open_capture_screen,
    };

    init_screen = dentscope_init_screen_create(&config);
}
```

For production, set `.auto_complete_ms = 0` and call `dentscope_init_screen_complete(init_screen)` only after the display, camera, network and AI service initialization has actually finished.
