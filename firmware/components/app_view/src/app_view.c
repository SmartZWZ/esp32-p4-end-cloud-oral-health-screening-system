#include "app_view.h"

#include <stdint.h>
#include <string.h>

static lv_color_t color_bg(void) { return lv_color_hex(0x111820); }
static lv_color_t color_panel(void) { return lv_color_hex(0x1a232c); }
static lv_color_t color_panel_alt(void) { return lv_color_hex(0x222d37); }
static lv_color_t color_text(void) { return lv_color_hex(0xf2f6fa); }
static lv_color_t color_muted(void) { return lv_color_hex(0x9aa8b5); }
static lv_color_t color_ok(void) { return lv_color_hex(0x28c7a0); }
static lv_color_t color_warn(void) { return lv_color_hex(0xffb347); }
static lv_color_t color_blue(void) { return lv_color_hex(0x7ea6ff); }

static lv_obj_t *make_label(lv_obj_t *parent, const char *text, const lv_font_t *font, lv_color_t color)
{
    lv_obj_t *label = lv_label_create(parent);
    lv_label_set_text(label, text);
    lv_label_set_long_mode(label, LV_LABEL_LONG_WRAP);
    lv_obj_set_style_text_font(label, font, 0);
    lv_obj_set_style_text_color(label, color, 0);
    lv_obj_set_style_text_letter_space(label, 0, 0);
    return label;
}

static lv_obj_t *make_panel(lv_obj_t *parent)
{
    lv_obj_t *panel = lv_obj_create(parent);
    lv_obj_set_style_bg_color(panel, color_panel(), 0);
    lv_obj_set_style_bg_opa(panel, LV_OPA_COVER, 0);
    lv_obj_set_style_border_color(panel, lv_color_hex(0x34424f), 0);
    lv_obj_set_style_border_width(panel, 1, 0);
    lv_obj_set_style_radius(panel, 8, 0);
    lv_obj_set_style_pad_all(panel, 16, 0);
    lv_obj_set_style_pad_row(panel, 10, 0);
    lv_obj_set_style_pad_column(panel, 10, 0);
    lv_obj_clear_flag(panel, LV_OBJ_FLAG_SCROLLABLE);
    return panel;
}

static lv_obj_t *make_button(lv_obj_t *parent, const char *text, lv_color_t color)
{
    lv_obj_t *button = lv_button_create(parent);
    lv_obj_set_height(button, 58);
    lv_obj_set_flex_grow(button, 1);
    lv_obj_set_style_bg_color(button, color, 0);
    lv_obj_set_style_shadow_width(button, 0, 0);
    lv_obj_set_style_radius(button, 8, 0);

    lv_obj_t *label = make_label(button, text, &lv_font_montserrat_18, lv_color_white());
    lv_obj_center(label);
    return button;
}

static void button_event_cb(lv_event_t *e)
{
    if (lv_event_get_code(e) != LV_EVENT_CLICKED) {
        return;
    }

    app_view_button_ctx_t *ctx = lv_event_get_user_data(e);
    if (ctx != NULL && ctx->view != NULL && ctx->view->action_cb != NULL) {
        ctx->view->action_cb(ctx->action, ctx->view->action_user_data);
    }
}

void app_view_create(app_view_t *view, lv_obj_t *root)
{
    memset(view, 0, sizeof(*view));

    lv_obj_set_style_bg_color(root, color_bg(), 0);
    lv_obj_set_style_pad_all(root, 18, 0);
    lv_obj_set_style_pad_row(root, 14, 0);
    lv_obj_set_flex_flow(root, LV_FLEX_FLOW_COLUMN);

    lv_obj_t *header = lv_obj_create(root);
    lv_obj_remove_style_all(header);
    lv_obj_set_width(header, LV_PCT(100));
    lv_obj_set_height(header, 70);
    lv_obj_set_flex_flow(header, LV_FLEX_FLOW_ROW);
    lv_obj_set_flex_align(header, LV_FLEX_ALIGN_SPACE_BETWEEN, LV_FLEX_ALIGN_CENTER, LV_FLEX_ALIGN_CENTER);

    make_label(header, "ESP32-P4 MVP", &lv_font_montserrat_26, color_text());
    view->camera_label = make_label(header, "Camera starting", &lv_font_montserrat_16, color_muted());

    lv_obj_t *preview = make_panel(root);
    lv_obj_set_width(preview, LV_PCT(100));
    lv_obj_set_flex_grow(preview, 1);
    lv_obj_set_flex_flow(preview, LV_FLEX_FLOW_COLUMN);
    lv_obj_set_style_bg_color(preview, lv_color_hex(0x0c1117), 0);

    view->view_label = make_label(preview, "View A", &lv_font_montserrat_24, color_text());

    lv_obj_t *target = lv_obj_create(preview);
    lv_obj_set_width(target, LV_PCT(100));
    lv_obj_set_flex_grow(target, 1);
    lv_obj_set_style_bg_color(target, lv_color_hex(0x101923), 0);
    lv_obj_set_style_border_color(target, color_ok(), 0);
    lv_obj_set_style_border_width(target, 2, 0);
    lv_obj_set_style_radius(target, 8, 0);
    lv_obj_clear_flag(target, LV_OBJ_FLAG_SCROLLABLE);
    lv_obj_t *target_label = make_label(target, "Live camera service active", &lv_font_montserrat_22, color_muted());
    lv_obj_center(target_label);

    view->guide_label = make_label(preview, "Align target and hold steady", &lv_font_montserrat_22, color_ok());
    lv_obj_set_width(view->guide_label, LV_PCT(100));

    lv_obj_t *metrics = lv_obj_create(root);
    lv_obj_remove_style_all(metrics);
    lv_obj_set_width(metrics, LV_PCT(100));
    lv_obj_set_height(metrics, 190);
    lv_obj_set_flex_flow(metrics, LV_FLEX_FLOW_ROW);
    lv_obj_set_style_pad_column(metrics, 12, 0);

    lv_obj_t *m1 = make_panel(metrics);
    lv_obj_set_flex_grow(m1, 1);
    lv_obj_set_style_bg_color(m1, color_panel_alt(), 0);
    make_label(m1, "Distance", &lv_font_montserrat_14, color_muted());
    view->distance_label = make_label(m1, "-- mm", &lv_font_montserrat_24, color_text());

    lv_obj_t *m2 = make_panel(metrics);
    lv_obj_set_flex_grow(m2, 1);
    lv_obj_set_style_bg_color(m2, color_panel_alt(), 0);
    make_label(m2, "Tilt", &lv_font_montserrat_14, color_muted());
    view->tilt_label = make_label(m2, "-- deg", &lv_font_montserrat_24, color_text());

    lv_obj_t *m3 = make_panel(metrics);
    lv_obj_set_flex_grow(m3, 1);
    lv_obj_set_style_bg_color(m3, color_panel_alt(), 0);
    make_label(m3, "LED", &lv_font_montserrat_14, color_muted());
    view->led_label = make_label(m3, "--%", &lv_font_montserrat_24, color_text());

    lv_obj_t *m4 = make_panel(metrics);
    lv_obj_set_flex_grow(m4, 1);
    lv_obj_set_style_bg_color(m4, color_panel_alt(), 0);
    make_label(m4, "Frames", &lv_font_montserrat_14, color_muted());
    view->frame_label = make_label(m4, "0", &lv_font_montserrat_24, color_text());

    lv_obj_t *footer = lv_obj_create(root);
    lv_obj_remove_style_all(footer);
    lv_obj_set_width(footer, LV_PCT(100));
    lv_obj_set_height(footer, 80);
    lv_obj_set_flex_flow(footer, LV_FLEX_FLOW_ROW);
    lv_obj_set_style_pad_column(footer, 12, 0);

    view->capture_button = make_button(footer, "CAPTURE", color_ok());
    view->retake_button = make_button(footer, "RETAKE", lv_color_hex(0xff647c));
    view->next_button = make_button(footer, "NEXT VIEW", color_blue());

    view->capture_ctx = (app_view_button_ctx_t){ .view = view, .action = APP_VIEW_ACTION_CAPTURE };
    view->retake_ctx = (app_view_button_ctx_t){ .view = view, .action = APP_VIEW_ACTION_RETAKE };
    view->next_ctx = (app_view_button_ctx_t){ .view = view, .action = APP_VIEW_ACTION_NEXT_VIEW };

    lv_obj_add_event_cb(view->capture_button, button_event_cb, LV_EVENT_CLICKED, &view->capture_ctx);
    lv_obj_add_event_cb(view->retake_button, button_event_cb, LV_EVENT_CLICKED, &view->retake_ctx);
    lv_obj_add_event_cb(view->next_button, button_event_cb, LV_EVENT_CLICKED, &view->next_ctx);
}

void app_view_set_action_cb(app_view_t *view, app_view_action_cb_t cb, void *user_data)
{
    view->action_cb = cb;
    view->action_user_data = user_data;
}

void app_view_update(app_view_t *view, const app_model_t *model)
{
    lv_label_set_text_fmt(view->view_label, "%s  %u/4", app_model_view_name(model), model->capture_count);
    lv_label_set_text(view->guide_label, model->guide_text);
    lv_obj_set_style_text_color(view->guide_label,
                                model->quality_state == APP_QUALITY_GOOD ? color_ok() : color_warn(), 0);

    lv_label_set_text(view->camera_label, model->camera_text);
    lv_obj_set_style_text_color(view->camera_label,
                                model->camera_state == APP_CAMERA_STREAMING ? color_ok() : color_warn(), 0);

    lv_label_set_text_fmt(view->distance_label, "%d mm", model->distance_mm);
    lv_label_set_text_fmt(view->tilt_label, "%d deg", model->tilt_deg);
    lv_label_set_text_fmt(view->led_label, "%d%%", model->led_percent);
    lv_label_set_text_fmt(view->frame_label, "%lu", (unsigned long)model->frame_count);
}
