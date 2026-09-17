#pragma once

#include "lvgl.h"

#ifdef __cplusplus
extern "C" {
#endif

/* LVGL 9.x implementation. */
#if LVGL_VERSION_MAJOR < 9
#error "dentscope_init_screen requires LVGL 9.x."
#endif

#define DENTISCOPE_SCREEN_WIDTH  720
#define DENTISCOPE_SCREEN_HEIGHT 1080
#define DENTISCOPE_INIT_TEST_DELAY_MS 3000U

typedef struct dentscope_init_screen dentscope_init_screen_t;

typedef void (*dentscope_start_cb_t)(void * user_data);

typedef struct {
    const char * time_text;          /* Example: "10:42" */
    const char * meridiem_text;      /* Example: "AM" */
    const char * date_text;          /* Example: "2026/7/18" */
    uint32_t auto_complete_ms;       /* 0 = wait for dentscope_init_screen_complete(). */
    dentscope_start_cb_t on_start;
    void * user_data;
} dentscope_init_screen_config_t;

/**
 * Create the 720 x 1080 DentiScope initialization screen and load it.
 * If config is NULL, the screen uses the visual-test defaults and completes
 * after DENTISCOPE_INIT_TEST_DELAY_MS.
 */
dentscope_init_screen_t * dentscope_init_screen_create(const dentscope_init_screen_config_t * config);

/** Reveal the START button. Call this when hardware initialization is done. */
void dentscope_init_screen_complete(dentscope_init_screen_t * screen);

/** Update the clock from the RTC without recreating the screen. */
void dentscope_init_screen_set_clock(dentscope_init_screen_t * screen,
                                     const char * time_text,
                                     const char * meridiem_text,
                                     const char * date_text);

/** Return the LVGL root screen for navigation or integration. */
lv_obj_t * dentscope_init_screen_get_root(dentscope_init_screen_t * screen);

#ifdef __cplusplus
}
#endif
