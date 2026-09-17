#include "dentscope_init_screen.h"

#include <string.h>

/*
 * Override these macros in your project config if you use custom fonts.
 * The fallbacks keep the module buildable when a larger Montserrat font is
 * not enabled in lv_conf.h, although enabling the listed sizes best matches
 * the approved visual design.
 */
#ifndef DENTISCOPE_FONT_TIME
    #if LV_FONT_MONTSERRAT_64
        #define DENTISCOPE_FONT_TIME (&lv_font_montserrat_64)
    #else
        #define DENTISCOPE_FONT_TIME LV_FONT_DEFAULT
    #endif
#endif

#ifndef DENTISCOPE_FONT_MERIDIEM
    #if LV_FONT_MONTSERRAT_32
        #define DENTISCOPE_FONT_MERIDIEM (&lv_font_montserrat_32)
    #else
        #define DENTISCOPE_FONT_MERIDIEM LV_FONT_DEFAULT
    #endif
#endif

#ifndef DENTISCOPE_FONT_DATE
    #if LV_FONT_MONTSERRAT_28
        #define DENTISCOPE_FONT_DATE (&lv_font_montserrat_28)
    #else
        #define DENTISCOPE_FONT_DATE LV_FONT_DEFAULT
    #endif
#endif

#ifndef DENTISCOPE_FONT_BUTTON
    #if LV_FONT_MONTSERRAT_28
        #define DENTISCOPE_FONT_BUTTON (&lv_font_montserrat_28)
    #else
        #define DENTISCOPE_FONT_BUTTON LV_FONT_DEFAULT
    #endif
#endif

#ifndef DENTISCOPE_FONT_BRAND
    #if LV_FONT_MONTSERRAT_20
        #define DENTISCOPE_FONT_BRAND (&lv_font_montserrat_20)
    #else
        #define DENTISCOPE_FONT_BRAND LV_FONT_DEFAULT
    #endif
#endif

#ifndef DENTISCOPE_FONT_STATUS
    #if LV_FONT_MONTSERRAT_16
        #define DENTISCOPE_FONT_STATUS (&lv_font_montserrat_16)
    #else
        #define DENTISCOPE_FONT_STATUS LV_FONT_DEFAULT
    #endif
#endif

#define COLOR_BACKGROUND lv_color_hex(0x0B0B0B)
#define COLOR_INK        lv_color_hex(0xF5F4F1)
#define COLOR_MUTED      lv_color_hex(0xAAAAA8)
#define COLOR_RULE       lv_color_hex(0x565655)
#define COLOR_BUTTON_INK lv_color_hex(0x111110)

struct dentscope_init_screen {
    lv_obj_t * root;
    lv_obj_t * time;
    lv_obj_t * meridiem;
    lv_obj_t * date;
    lv_obj_t * spinner;
    lv_obj_t * loading_group;
    lv_obj_t * start_button;
    lv_timer_t * completion_timer;
    dentscope_start_cb_t on_start;
    void * user_data;
};

static void spinner_rotation_cb(void * object, int32_t angle)
{
    lv_arc_set_rotation((lv_obj_t *)object, angle);
}

static void start_button_event_cb(lv_event_t * event)
{
    dentscope_init_screen_t * screen = lv_event_get_user_data(event);

    if(lv_event_get_code(event) == LV_EVENT_CLICKED && screen->on_start != NULL) {
        screen->on_start(screen->user_data);
    }
}

static void finish_initialization_cb(lv_timer_t * timer)
{
    dentscope_init_screen_t * screen = lv_timer_get_user_data(timer);
    screen->completion_timer = NULL;
    dentscope_init_screen_complete(screen);
}

static void root_delete_event_cb(lv_event_t * event)
{
    dentscope_init_screen_t * screen = lv_event_get_user_data(event);

    if(screen->completion_timer != NULL) {
        lv_timer_delete(screen->completion_timer);
    }

    lv_free(screen);
}

static lv_obj_t * create_label(lv_obj_t * parent, const char * text,
                                const lv_font_t * font, lv_color_t color)
{
    lv_obj_t * label = lv_label_create(parent);
    lv_label_set_text(label, text);
    lv_obj_set_style_text_font(label, font, LV_PART_MAIN);
    lv_obj_set_style_text_color(label, color, LV_PART_MAIN);
    lv_obj_set_style_text_align(label, LV_TEXT_ALIGN_CENTER, LV_PART_MAIN);
    return label;
}

static void start_spinner(dentscope_init_screen_t * screen)
{
    lv_anim_t animation;
    lv_anim_init(&animation);
    lv_anim_set_var(&animation, screen->spinner);
    lv_anim_set_exec_cb(&animation, spinner_rotation_cb);
    lv_anim_set_values(&animation, 0, 360);
    lv_anim_set_duration(&animation, 900);
    lv_anim_set_repeat_count(&animation, LV_ANIM_REPEAT_INFINITE);
    lv_anim_start(&animation);
}

dentscope_init_screen_t * dentscope_init_screen_create(const dentscope_init_screen_config_t * config)
{
    const char * time_text = "10:42";
    const char * meridiem_text = "AM";
    const char * date_text = "2026/7/18";
    uint32_t auto_complete_ms = DENTISCOPE_INIT_TEST_DELAY_MS;

    dentscope_init_screen_t * screen = lv_malloc(sizeof(*screen));
    if(screen == NULL) return NULL;
    memset(screen, 0, sizeof(*screen));

    if(config != NULL) {
        if(config->time_text != NULL) time_text = config->time_text;
        if(config->meridiem_text != NULL) meridiem_text = config->meridiem_text;
        if(config->date_text != NULL) date_text = config->date_text;
        auto_complete_ms = config->auto_complete_ms;
        screen->on_start = config->on_start;
        screen->user_data = config->user_data;
    }

    screen->root = lv_obj_create(NULL);
    lv_obj_set_size(screen->root, DENTISCOPE_SCREEN_WIDTH, DENTISCOPE_SCREEN_HEIGHT);
    lv_obj_remove_flag(screen->root, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_set_style_bg_color(screen->root, COLOR_BACKGROUND, LV_PART_MAIN);
    lv_obj_set_style_bg_opa(screen->root, LV_OPA_COVER, LV_PART_MAIN);
    lv_obj_set_style_border_width(screen->root, 0, LV_PART_MAIN);
    lv_obj_add_event_cb(screen->root, root_delete_event_cb, LV_EVENT_DELETE, screen);

    /* Time is intentionally raised compared with the approved static mockup. */
    screen->time = create_label(screen->root, time_text, DENTISCOPE_FONT_TIME, COLOR_INK);
    lv_obj_set_size(screen->time, DENTISCOPE_SCREEN_WIDTH, LV_SIZE_CONTENT);
    lv_obj_set_pos(screen->time, 0, 220);

    screen->meridiem = create_label(screen->root, meridiem_text, DENTISCOPE_FONT_MERIDIEM, COLOR_INK);
    lv_obj_set_size(screen->meridiem, 110, LV_SIZE_CONTENT);
    lv_obj_set_pos(screen->meridiem, 446, 251);

    screen->date = create_label(screen->root, date_text, DENTISCOPE_FONT_DATE, COLOR_MUTED);
    lv_obj_set_size(screen->date, DENTISCOPE_SCREEN_WIDTH, LV_SIZE_CONTENT);
    lv_obj_set_pos(screen->date, 0, 326);

    /* The startup state is intentionally lower than the initial mockup. */
    screen->loading_group = lv_obj_create(screen->root);
    lv_obj_set_size(screen->loading_group, 280, 138);
    lv_obj_set_pos(screen->loading_group, 220, 680);
    lv_obj_remove_flag(screen->loading_group, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_set_style_bg_opa(screen->loading_group, LV_OPA_TRANSP, LV_PART_MAIN);
    lv_obj_set_style_border_width(screen->loading_group, 0, LV_PART_MAIN);
    lv_obj_set_style_pad_all(screen->loading_group, 0, LV_PART_MAIN);

    screen->spinner = lv_arc_create(screen->loading_group);
    lv_obj_set_size(screen->spinner, 76, 76);
    lv_obj_set_pos(screen->spinner, 102, 0);
    lv_arc_set_range(screen->spinner, 0, 100);
    lv_arc_set_bg_angles(screen->spinner, 0, 360);
    lv_arc_set_value(screen->spinner, 78);
    lv_obj_remove_style(screen->spinner, NULL, LV_PART_KNOB);
    lv_obj_remove_flag(screen->spinner, LV_OBJ_FLAG_CLICKABLE);
    lv_obj_set_style_arc_width(screen->spinner, 3, LV_PART_MAIN);
    lv_obj_set_style_arc_opa(screen->spinner, LV_OPA_TRANSP, LV_PART_MAIN);
    lv_obj_set_style_arc_width(screen->spinner, 3, LV_PART_INDICATOR);
    lv_obj_set_style_arc_color(screen->spinner, COLOR_INK, LV_PART_INDICATOR);
    lv_obj_set_style_arc_rounded(screen->spinner, false, LV_PART_INDICATOR);
    start_spinner(screen);

    lv_obj_t * status = create_label(screen->loading_group, "INITIALIZING SYSTEM",
                                      DENTISCOPE_FONT_STATUS, COLOR_MUTED);
    lv_obj_set_size(status, 280, LV_SIZE_CONTENT);
    lv_obj_set_pos(status, 0, 102);
    lv_obj_set_style_text_letter_space(status, 2, LV_PART_MAIN);

    screen->start_button = lv_button_create(screen->root);
    lv_obj_set_size(screen->start_button, 280, 76);
    lv_obj_set_pos(screen->start_button, 220, 704);
    lv_obj_add_flag(screen->start_button, LV_OBJ_FLAG_HIDDEN);
    lv_obj_set_style_radius(screen->start_button, 14, LV_PART_MAIN);
    lv_obj_set_style_bg_color(screen->start_button, COLOR_INK, LV_PART_MAIN);
    lv_obj_set_style_bg_opa(screen->start_button, LV_OPA_COVER, LV_PART_MAIN);
    lv_obj_set_style_border_width(screen->start_button, 0, LV_PART_MAIN);
    lv_obj_add_event_cb(screen->start_button, start_button_event_cb, LV_EVENT_CLICKED, screen);

    lv_obj_t * start_text = create_label(screen->start_button, "START", DENTISCOPE_FONT_BUTTON, COLOR_BUTTON_INK);
    lv_obj_center(start_text);

    lv_obj_t * divider = lv_obj_create(screen->root);
    lv_obj_set_size(divider, 598, 1);
    lv_obj_set_pos(divider, 61, 1010);
    lv_obj_remove_flag(divider, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_set_style_bg_color(divider, COLOR_RULE, LV_PART_MAIN);
    lv_obj_set_style_bg_opa(divider, LV_OPA_COVER, LV_PART_MAIN);
    lv_obj_set_style_border_width(divider, 0, LV_PART_MAIN);
    lv_obj_set_style_radius(divider, 0, LV_PART_MAIN);
    lv_obj_set_style_pad_all(divider, 0, LV_PART_MAIN);

    lv_obj_t * brand = create_label(screen->root, "DentiScope", DENTISCOPE_FONT_BRAND, COLOR_MUTED);
    lv_obj_set_size(brand, DENTISCOPE_SCREEN_WIDTH, LV_SIZE_CONTENT);
    lv_obj_set_pos(brand, 0, 1030);

    lv_screen_load(screen->root);

    if(auto_complete_ms > 0U) {
        screen->completion_timer = lv_timer_create(finish_initialization_cb, auto_complete_ms, screen);
        lv_timer_set_repeat_count(screen->completion_timer, 1);
    }

    return screen;
}

void dentscope_init_screen_complete(dentscope_init_screen_t * screen)
{
    if(screen == NULL || lv_obj_has_flag(screen->start_button, LV_OBJ_FLAG_HIDDEN) == false) return;

    if(screen->completion_timer != NULL) {
        lv_timer_delete(screen->completion_timer);
        screen->completion_timer = NULL;
    }

    lv_anim_delete(screen->spinner, NULL);
    lv_obj_add_flag(screen->loading_group, LV_OBJ_FLAG_HIDDEN);
    lv_obj_clear_flag(screen->start_button, LV_OBJ_FLAG_HIDDEN);
}

void dentscope_init_screen_set_clock(dentscope_init_screen_t * screen,
                                     const char * time_text,
                                     const char * meridiem_text,
                                     const char * date_text)
{
    if(screen == NULL) return;
    if(time_text != NULL) lv_label_set_text(screen->time, time_text);
    if(meridiem_text != NULL) lv_label_set_text(screen->meridiem, meridiem_text);
    if(date_text != NULL) lv_label_set_text(screen->date, date_text);
}

lv_obj_t * dentscope_init_screen_get_root(dentscope_init_screen_t * screen)
{
    return screen == NULL ? NULL : screen->root;
}
