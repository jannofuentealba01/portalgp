#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${PORTALGP_APP_ROOT:-/var/www/portalgp}"
BACKUP_ROOT="${PORTALGP_BACKUP_ROOT:-/var/backups/portalgp}"
MSP_STORAGE="${PORTALGP_MSP_STORAGE:-/var/msp_storage}"
SECRET_ROOT="${PORTALGP_SECRET_ROOT:-/var/portalgp_secrets}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="$BACKUP_ROOT/security-preflight-$STAMP"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ $EUID -eq 0 ]] || fail "ejecuta este script con sudo"
[[ -d "$APP_ROOT/.git" ]] || fail "no se encontró el repositorio en $APP_ROOT"

umask 077
install -d -m 0700 "$BACKUP_ROOT" "$DEST"

GIT=(git -c "safe.directory=$APP_ROOT" -C "$APP_ROOT")
BRANCH="$("${GIT[@]}" branch --show-current)"
COMMIT="$("${GIT[@]}" rev-parse HEAD)"
DIRTY="no"
[[ -z "$("${GIT[@]}" status --porcelain)" ]] || DIRTY="yes"

cat > "$DEST/manifest.txt" <<EOF
created_at_utc=$STAMP
host=$(hostname -f 2>/dev/null || hostname)
app_root=$APP_ROOT
git_branch=$BRANCH
git_commit=$COMMIT
git_dirty=$DIRTY
EOF

{
    printf '\n[packages]\n'
    nginx -v 2>&1 || true
    php -v 2>&1 | head -n 2 || true
    certbot --version 2>&1 || true
    odbcinst -j 2>&1 || true
    printf '\n[services]\n'
    systemctl is-active nginx php8.3-fpm 2>&1 || true
} >> "$DEST/manifest.txt"

# Copia de ejecución completa. Los secretos locales se excluyen y se guardan
# por separado para que el archivo de aplicación pueda manipularse sin exponerlos.
tar \
    --exclude='portalgp/.git' \
    --exclude='portalgp/config/database.local.php' \
    --exclude='portalgp/microsoft_auth_config.local.php' \
    --exclude='portalgp/msp/config/mail.php' \
    --exclude='portalgp/ct/config/mail.php' \
    --exclude='portalgp/rrhh/fte/fte_config.local.php' \
    -czf "$DEST/portalgp-runtime.tar.gz" \
    -C "$(dirname "$APP_ROOT")" "$(basename "$APP_ROOT")"

if [[ -d "$MSP_STORAGE" ]]; then
    tar -czf "$DEST/msp-storage.tar.gz" -C "$(dirname "$MSP_STORAGE")" "$(basename "$MSP_STORAGE")"
else
    printf 'warning=msp_storage_missing:%s\n' "$MSP_STORAGE" >> "$DEST/manifest.txt"
fi

SECRET_ITEMS=()
[[ -d "$SECRET_ROOT" ]] && SECRET_ITEMS+=("$SECRET_ROOT")
for secret_file in \
    "$APP_ROOT/config/database.local.php" \
    "$APP_ROOT/microsoft_auth_config.local.php" \
    "$APP_ROOT/msp/config/mail.php" \
    "$APP_ROOT/ct/config/mail.php" \
    "$APP_ROOT/rrhh/fte/fte_config.local.php"; do
    [[ -f "$secret_file" ]] && SECRET_ITEMS+=("$secret_file")
done

if ((${#SECRET_ITEMS[@]} > 0)); then
    tar -czf "$DEST/runtime-secrets.tar.gz" --absolute-names "${SECRET_ITEMS[@]}"
else
    printf 'warning=no_runtime_secrets_found\n' >> "$DEST/manifest.txt"
fi

CONFIG_ITEMS=()
for config_path in \
    /etc/nginx/nginx.conf \
    /etc/nginx/sites-available/it-conecta \
    /etc/nginx/sites-available/portalgp-ssl \
    /etc/nginx/snippets/portalgp.conf \
    /etc/nginx/snippets/portalgp-app.conf \
    /etc/nginx/conf.d/portalgp_timing.conf \
    /etc/php/8.3/fpm \
    /etc/odbc.ini \
    /etc/odbcinst.ini; do
    [[ -e "$config_path" ]] && CONFIG_ITEMS+=("$config_path")
done

if ((${#CONFIG_ITEMS[@]} > 0)); then
    tar -czf "$DEST/server-config.tar.gz" --absolute-names "${CONFIG_ITEMS[@]}"
fi

for archive in "$DEST"/*.tar.gz; do
    tar -tzf "$archive" >/dev/null
done

(
    cd "$DEST"
    sha256sum ./*.tar.gz > SHA256SUMS
    sha256sum --check SHA256SUMS >/dev/null
)

find "$DEST" -type f -exec chmod 0600 {} +
chmod 0700 "$DEST" "$BACKUP_ROOT"

{
    printf '\n[artifacts]\n'
    find "$DEST" -maxdepth 1 -type f -printf '%f|%s bytes|%TY-%Tm-%TdT%TH:%TM:%TS%TZ\n' | sort
} >> "$DEST/manifest.txt"

printf 'BACKUP_DIR=%s\n' "$DEST"
printf 'GIT_BRANCH=%s\n' "$BRANCH"
printf 'GIT_COMMIT=%s\n' "$COMMIT"
printf 'GIT_DIRTY=%s\n' "$DIRTY"
printf 'ARCHIVES_VERIFIED=ok\n'

