#!/usr/bin/env bash
set -euo pipefail

BACKUP_ROOT="${PORTALGP_BACKUP_ROOT:-/var/backups/portalgp}"
BACKUP_DIR="${1:-}"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ $EUID -eq 0 ]] || fail "ejecuta este script con sudo"
[[ -n "$BACKUP_DIR" ]] || fail "uso: $0 /var/backups/portalgp/security-preflight-AAAAMMDDTHHMMSSZ"
[[ -d "$BACKUP_DIR" ]] || fail "no existe el respaldo indicado"

ROOT_REAL="$(realpath "$BACKUP_ROOT")"
BACKUP_REAL="$(realpath "$BACKUP_DIR")"
case "$BACKUP_REAL" in
    "$ROOT_REAL"/security-preflight-*) ;;
    *) fail "la ruta no corresponde a un respaldo preflight permitido" ;;
esac

cd "$BACKUP_REAL"
sha256sum --check SHA256SUMS

RESTORE_DIR="$(mktemp -d "$ROOT_REAL/restore-test-XXXXXX")"
cleanup() {
    case "$RESTORE_DIR" in
        "$ROOT_REAL"/restore-test-*) rm -rf -- "$RESTORE_DIR" ;;
        *) printf 'ADVERTENCIA: no se eliminó una ruta temporal inesperada: %s\n' "$RESTORE_DIR" >&2 ;;
    esac
}
trap cleanup EXIT

for archive in ./*.tar.gz; do
    name="$(basename "$archive" .tar.gz)"
    install -d -m 0700 "$RESTORE_DIR/$name"
    tar -xzf "$archive" -C "$RESTORE_DIR/$name"
done

[[ -f "$RESTORE_DIR/portalgp-runtime/portalgp/login.php" ]] || fail "falta login.php en el respaldo"
[[ -f "$RESTORE_DIR/portalgp-runtime/portalgp/msp/msp_menu.php" ]] || fail "falta el menú MSP en el respaldo"
[[ -d "$RESTORE_DIR/msp-storage/msp_storage" ]] || fail "falta el almacenamiento MSP"
[[ -d "$RESTORE_DIR/server-config/etc/nginx" ]] || fail "falta la configuración Nginx"
[[ -d "$RESTORE_DIR/runtime-secrets/var/portalgp_secrets" ]] || fail "faltan los secretos externos"

printf 'RESTORE_SANDBOX_TEST=ok\n'
printf 'BACKUP_DIR=%s\n' "$BACKUP_REAL"
du -sh "$RESTORE_DIR"/* | sort

