#!/usr/bin/env bash
set -euo pipefail

/www/server/nginx/sbin/nginx -t
/etc/init.d/nginx reload
