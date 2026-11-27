# Production host-Nginx fallback; install only inside canonical HTTPS mskba.ru server.
# Review live vhost and path before manually activating; never include in dev.
# Keep 500 and 4xx application responses untouched. Upstream unavailability is 503.
proxy_intercept_errors on;
error_page 502 503 504 =503 /__mskba_prod_unavailable__;

location = /__mskba_prod_unavailable__ {
    internal;
    alias /var/www/mskba/public/maintenance.html;
    default_type text/html;
    charset utf-8;
    add_header Cache-Control 'no-store, max-age=0' always;
    add_header Retry-After 120 always;
    add_header X-Robots-Tag 'noindex, nofollow' always;
}
