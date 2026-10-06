#!/usr/bin/env bash
set -euo pipefail

check() {
    local url="$1"
    local expected_status="$2"
    local expected_location="${3:-}"

    local headers
    headers="$(curl -sS -o /dev/null -D - --max-redirs 0 "$url")"
    local status
    status="$(printf '%s\n' "$headers" | awk 'toupper($1) ~ /^HTTP\// {code=$2} END {print code}')"
    local location
    location="$(printf '%s\n' "$headers" | awk 'BEGIN{IGNORECASE=1} /^location:/ {sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit}')"

    printf '%-28s -> %s' "$url" "$status"

    if [ "$status" != "$expected_status" ]; then
        echo "  FAIL (expected $expected_status)" >&2
        return 1
    fi

    if [ -n "$expected_location" ]; then
        printf '  Location: %s' "$location"
        if [ "$location" != "$expected_location" ]; then
            echo "  FAIL (expected $expected_location)" >&2
            return 1
        fi
    fi

    echo "  OK"
}

check "http://mskba.ru/" "301" "https://mskba.ru/"
check "http://www.mskba.ru/" "301" "https://mskba.ru/"
check "https://www.mskba.ru/" "301" "https://mskba.ru/"
check "https://mskba.ru/" "200"
