#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://15.229.113.179}"
BASE_URL="${BASE_URL%/}"
FAILED=0

check_status() {
    local path="$1"
    local expected="$2"
    local status
    status="$(curl -ksS -o /dev/null --max-time 15 -w '%{http_code}' "$BASE_URL$path")"
    if [[ ! "$status" =~ ^($expected)$ ]]; then
        printf 'FAIL %-65s esperado=%s obtenido=%s\n' "$path" "$expected" "$status"
        FAILED=1
    else
        printf 'OK   %-65s estado=%s\n' "$path" "$status"
    fi
}

check_status '/portalgp/login.php' '200'
check_status '/portalgp/.git/HEAD' '403'
check_status '/portalgp/composer.json' '403'
check_status '/portalgp/composer.lock' '403'
check_status '/portalgp/semgrep-msp.json' '403'
check_status '/portalgp/msp/SEGURIDAD_DAST_COMPLETA_2026-09-15.md' '403'
check_status '/portalgp/msp/db/core_msp_migrate.sql' '403'
check_status '/portalgp/msp/config/cacert.pem' '403'
check_status '/portalgp/tests/security_stage1.php' '403'

SERVER_HEADER="$(curl -ksSI --max-time 15 "$BASE_URL/portalgp/login.php" | tr -d '\r' | awk -F': ' 'tolower($1)=="server" {print $2; exit}')"
if [[ "$SERVER_HEADER" == *'/'* || "$SERVER_HEADER" == *'('* ]]; then
    printf 'FAIL Server divulga versión: %s\n' "$SERVER_HEADER"
    FAILED=1
else
    printf 'OK   Server no divulga versión exacta: %s\n' "${SERVER_HEADER:-[sin cabecera]}"
fi

if [[ "$BASE_URL" == https://* ]]; then
    HSTS="$(curl -ksSI --max-time 15 "$BASE_URL/portalgp/login.php" | tr -d '\r' | awk -F': ' 'tolower($1)=="strict-transport-security" {print $2; exit}')"
    [[ -n "$HSTS" ]] || { printf 'FAIL falta HSTS\n'; FAILED=1; }
    [[ -n "$HSTS" ]] && printf 'OK   HSTS: %s\n' "$HSTS"
fi

printf 'branch=%s commit=%s dirty=%s\n' \
    "$(git branch --show-current)" \
    "$(git rev-parse HEAD)" \
    "$([[ -z "$(git status --porcelain)" ]] && echo no || echo yes)"

exit "$FAILED"
