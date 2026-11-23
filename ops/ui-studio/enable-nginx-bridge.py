#!/usr/bin/env python3
"""One-time, root-only UI Studio Nginx bridge enablement.

Review with: python3 ops/ui-studio/enable-nginx-bridge.py
Apply with:  sudo python3 /var/www/mskba-ui/ops/ui-studio/enable-nginx-bridge.py --apply

No production portal vhost is edited. Only the dedicated Studio vhost changes.
"""

from __future__ import annotations

import argparse
import datetime as dt
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile

VHOST = Path("/etc/nginx/sites-available/mskba-ui")
UI_ENV = Path("/var/www/mskba-ui/.env")
PORTAL_ENV = Path("/var/www/mskba/.env")
MARKER = "# MSKBA_UI_STUDIO_SSO_V1"

POST_LOCATION = """    # MSKBA_UI_STUDIO_SSO_V1: two POST endpoints, all other Studio calls remain GET-only.
    location ~ ^/uikit/(session|reviews)$ {
        auth_basic off;
        limit_except POST { deny all; }
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /workspace/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT /workspace/public;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_param HTTP_COOKIE "mskba_ui_session=$cookie_mskba_ui_session";
        fastcgi_pass unix:/var/www/mskba-ui-runtime/php-fpm.sock;
    }

"""


def patch(source: str) -> tuple[str, bool]:
    if MARKER in source:
        return source, False

    required = (
        "server_name ui.mskba.ru;",
        'auth_basic "MSKBA UI Studio";',
        "    location ^~ /build/ {\n",
        "    location ~ ^/uikit(/|$) {\n",
        '        fastcgi_param HTTP_COOKIE "";\n',
        "        fastcgi_pass unix:/var/www/mskba-ui-runtime/php-fpm.sock;",
    )
    for needle in required:
        if source.count(needle) != 1:
            raise RuntimeError("Unexpected Studio vhost structure: " + repr(needle))

    source = source.replace(
        "    location ^~ /build/ {\n",
        "    location ^~ /build/ {\n        auth_basic off;\n",
        1,
    )
    source = source.replace(
        "    location ~ ^/uikit(/|$) {\n",
        POST_LOCATION + "    location ~ ^/uikit(/|$) {\n        auth_basic off;\n",
        1,
    )
    # Only the new isolated Studio cookie may reach PHP, never a portal cookie.
    source = source.replace(
        '        fastcgi_param HTTP_COOKIE "";\n',
        '        fastcgi_param HTTP_COOKIE "mskba_ui_session=$cookie_mskba_ui_session";\n',
        1,
    )
    if source.count("mskba_ui_session=$cookie_mskba_ui_session") != 2:
        raise RuntimeError("Cookie forwarding policy is incomplete")

    return source, True


def env_items(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key] = value.strip().strip('"').strip("'")
    return values


def preflight() -> None:
    ui = env_items(UI_ENV)
    portal = env_items(PORTAL_ENV)
    assert ui.get("APP_ENV") == "ui-studio", "Unexpected UI Studio environment"
    assert ui.get("SESSION_DRIVER") == "file", "Enable isolated file sessions first"
    assert ui.get("CACHE_STORE") == "file", "Enable isolated persistent cache first"
    assert ui.get("SESSION_COOKIE") == "mskba_ui_session", "Unexpected Studio cookie"
    assert portal.get("APP_ENV") == "production", "Unexpected portal environment"
    secret = ui.get("UI_STUDIO_BRIDGE_SECRET", "")
    assert len(secret) >= 32 and secret == portal.get("UI_STUDIO_BRIDGE_SECRET", ""), (
        "Portal and Studio bridge signatures are not configured alike"
    )
    assert portal.get("UI_STUDIO_BRIDGE_ENABLED", "false") == "false", (
        "Keep the portal bridge disabled until Nginx smoke passes"
    )


def nginx(*args: str) -> None:
    subprocess.run(args, check=True, stdout=subprocess.DEVNULL)


def status(url: str, method: str = "GET") -> str:
    response = subprocess.run(
        [
            "curl", "--silent", "--max-time", "12",
            "--output", "/dev/null", "--write-out", "%{http_code}",
            "-X", method, "-H", "Accept: application/json",
            *([] if method == "GET" else ["--data", "{}"]),
            url,
        ],
        check=True, capture_output=True, text=True,
    )
    return response.stdout


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Requires root; makes a backed-up vhost change")
    args = parser.parse_args()

    source = VHOST.read_text(encoding="utf-8")
    desired, changed = patch(source)
    if not args.apply:
        print("UI Studio Nginx patch:", "READY" if changed else "ALREADY_PRESENT")
        print("Changes: public bootstrap GET; two POST endpoints; isolated cookie only; static build assets")
        return

    if os.geteuid() != 0:
        raise SystemExit("Refusing to apply without root privileges")
    preflight()

    if not changed:
        print("UI Studio Nginx bridge already configured")
        return

    backup = VHOST.with_name(
        VHOST.name + ".ui-studio-995-backup-" + dt.datetime.now(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    )
    shutil.copy2(VHOST, backup)
    backup.chmod(0o600)
    temporary = None

    try:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=VHOST.parent, delete=False) as handle:
            temporary = Path(handle.name)
            handle.write(desired)
            handle.flush()
            os.fsync(handle.fileno())

        temporary.chmod(stat.S_IMODE(VHOST.stat().st_mode))
        os.replace(temporary, VHOST)
        temporary = None

        nginx("nginx", "-t")
        nginx("systemctl", "reload", "nginx")

        if status("https://ui.mskba.ru/uikit/bridge") != "200":
            raise RuntimeError("Studio bootstrap must return HTTP 200")
        if status("https://ui.mskba.ru/uikit") != "401":
            raise RuntimeError("Unidentified Studio requests must remain HTTP 401")
        if status("https://ui.mskba.ru/uikit/session", "POST") not in {"419", "422"}:
            raise RuntimeError("Session endpoint must reach Laravel with CSRF protection")

        print("UI_STUDIO_NGINX_BRIDGE=SUCCESS")
        print("Portal issuer intentionally remains disabled until end-to-end acceptance")

    except Exception:
        # Restore vhost and reload even if a smoke check or reload failed.
        shutil.copy2(backup, VHOST)
        nginx("nginx", "-t")
        nginx("systemctl", "reload", "nginx")
        raise
    finally:
        if temporary is not None and temporary.exists():
            temporary.unlink()


if __name__ == "__main__":
    main()
