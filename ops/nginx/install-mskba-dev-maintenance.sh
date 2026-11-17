#!/usr/bin/env bash
# Manual, Dev-only activation. Run on the VDS as root after reviewing the diff.
set -euo pipefail

if [[ "$(id -u)" != 0 ]]; then
  echo 'Run as root using an authenticated sudo session.' >&2
  exit 1
fi

src=/var/www/mskba-dev-next/ops/nginx/mskba-dev-maintenance.inc
vhost=/etc/nginx/sites-available/mskba-dev
snippet=/etc/nginx/snippets/mskba-dev-maintenance.conf
include_line='    include /etc/nginx/snippets/mskba-dev-maintenance.conf;'

test -r "$src"
test -f "$vhost"
test -r /var/www/mskba-dev-next/public/maintenance.html
if grep -Fqx "$include_line" "$vhost"; then
  nginx -t
  echo 'Dev maintenance fallback is already configured.'
  exit 0
fi

# Fail before changing files if the expected exact Dev server is absent.
python3 - "$vhost" <<'PY'
import pathlib,sys
s=pathlib.Path(sys.argv[1]).read_text()
if s.count('    server_name dev.mskba.ru;') != 1:
    raise SystemExit('Expected exactly one Dev HTTPS server declaration')
if 'server_name mskba.ru;' in s:
    raise SystemExit('Refusing to edit a file containing the production host')
PY

stamp="$(date +%Y%m%d-%H%M%S)"
backup="${vhost}.before-maintenance-${stamp}"
cp -a "$vhost" "$backup"
mkdir -p /etc/nginx/snippets
had_snippet=0
if [[ -e "$snippet" ]]; then
  cp -a "$snippet" "${snippet}.before-maintenance-${stamp}"
  had_snippet=1
fi

rollback() {
  echo 'Rolling back Dev Nginx configuration' >&2
  cp -a "$backup" "$vhost"
  if [[ "$had_snippet" -eq 1 ]]; then
    cp -a "${snippet}.before-maintenance-${stamp}" "$snippet"
  else
    rm -f "$snippet"
  fi
  nginx -t && systemctl reload nginx
}
trap rollback ERR

install -o root -g root -m 0644 "$src" "$snippet"
python3 - "$vhost" "$include_line" <<'PY'
import pathlib,sys
path=pathlib.Path(sys.argv[1]); include=sys.argv[2]
s=path.read_text()
s=s.replace('    server_name dev.mskba.ru;', '    server_name dev.mskba.ru;\n'+include, 1)
path.write_text(s)
PY
nginx -t
systemctl reload nginx
trap - ERR
printf 'Dev-only maintenance fallback installed; backup: %s\n' "$backup"
