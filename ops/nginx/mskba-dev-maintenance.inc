# Dev ONLY. Include INSIDE the existing `server {}` for dev.mskba.ru on the host.
# This is a host-Nginx fallback: it still works when Docker / PHP are stopped.
# Paths below deliberately point to dev; never include this in a prod server block.
# `proxy_intercept_errors` makes even a 502/503 sent by Docker's Nginx show this page.
proxy_intercept_errors on;
error_page 502 503 504 =503 /__mskba_dev_unavailable__;

location = /__mskba_dev_unavailable__ {
    internal;
    alias /var/www/mskba-dev-next/public/maintenance.html;
    default_type text/html;
    charset utf-8;
    add_header Cache-Control 'no-store, max-age=0' always;
    add_header Retry-After 120 always;
    add_header X-Robots-Tag 'noindex, nofollow' always;
}
