# DentiScope UI Package

Open `01_initialization/index.html` to start the complete HTML prototype.

## Screen folders

| Folder | Screen | Notes |
| --- | --- | --- |
| `01_initialization` | System initialization | START opens Home; inactivity enters Initialization Failed after 8 seconds. |
| `02_initialization_failed` | Initialization failed | WI-FI SETTINGS opens the complete Wi-Fi flow. |
| `03_home` | Main screen | SETTINGS opens the settings screen. |
| `04_settings` | Settings | Wi-Fi opens Wi-Fi Settings; Volume and Display open interactive slider dialogs. |
| `05_wifi_settings` | Wi-Fi settings | Saved Wi-Fi, Add New Wi-Fi, and Set Default Wi-Fi are included. |

## Shared SVG assets

All SVG icons are in `assets/icons`. Filenames use the format `<screen>_<purpose>.svg` so they can be mapped directly to LVGL image descriptors later.
