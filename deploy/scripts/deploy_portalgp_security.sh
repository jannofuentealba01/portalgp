#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-}"
PORTALGP_IP="${2:-}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
EXPECTED_BRANCH="codex/msp-aws-deploy"
SNIPPET_DIR="/etc/nginx/snippets"
SITE_AVAILABLE="/etc/nginx/sites-available/portalgp-ssl"
SITE_ENABLED="/etc/nginx/sites-enabled/portalgp-ssl"
POOL_FILE="/etc/php/8.3/fpm/pool.d/portalgp.conf"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ $EUID -eq 0 ]] || fail "ejecuta este script con sudo"
[[ "$MODE" == "bootstrap" || "$MODE" == "https" ]] || fail "uso: $0 bootstrap | https <IP>"

cd "$REPO_ROOT"
GIT=(git -c "safe.directory=$REPO_ROOT")
BRANCH="$("${GIT[@]}" branch --show-current)"
[[ "$BRANCH" == "$EXPECTED_BRANCH" ]] || fail "rama actual $BRANCH; se requiere $EXPECTED_BRANCH"
[[ -z "$("${GIT[@]}" status --porcelain)" ]] || fail "el árbol Git contiene cambios locales"
COMMIT="$("${GIT[@]}" rev-parse HEAD)"

install -d -m 0755 /var/www/letsencrypt/.well-known/acme-challenge
install -d -m 0755 "$SNIPPET_DIR" /var/lib/portalgp
BACKUP_DIR="$(mktemp -d /tmp/portalgp-config-XXXXXX)"
[[ "$(realpath "$BACKUP_DIR")" == /tmp/portalgp-config-* && ! -L "$BACKUP_DIR" ]] || fail "ruta temporal inesperada"
HAD_SNIPPET=0
HAD_SITE=0
HAD_APP=0
HAD_POOL=0
HAD_SITE_LINK=0
[[ -L "$SITE_ENABLED" ]] && HAD_SITE_LINK=1
if [[ -e "$SNIPPET_DIR/portalgp-app.conf" ]]; then
    cp -a "$SNIPPET_DIR/portalgp-app.conf" "$BACKUP_DIR/portalgp-app.conf"
    HAD_APP=1
fi
if [[ -e "$POOL_FILE" ]]; then
    cp -a "$POOL_FILE" "$BACKUP_DIR/portalgp-pool.conf"
    HAD_POOL=1
fi
if [[ -e "$SNIPPET_DIR/portalgp.conf" ]]; then
    cp -a "$SNIPPET_DIR/portalgp.conf" "$BACKUP_DIR/portalgp.conf"
    HAD_SNIPPET=1
fi
if [[ -e "$SITE_AVAILABLE" ]]; then
    cp -a "$SITE_AVAILABLE" "$BACKUP_DIR/portalgp-ssl"
    HAD_SITE=1
fi

rollback() {
    if [[ $HAD_APP -eq 1 ]]; then
        cp -a "$BACKUP_DIR/portalgp-app.conf" "$SNIPPET_DIR/portalgp-app.conf"
    else
        rm -f "$SNIPPET_DIR/portalgp-app.conf"
    fi
    if [[ $HAD_POOL -eq 1 ]]; then
        cp -a "$BACKUP_DIR/portalgp-pool.conf" "$POOL_FILE"
    fi
    if [[ $HAD_SNIPPET -eq 1 ]]; then
        cp -a "$BACKUP_DIR/portalgp.conf" "$SNIPPET_DIR/portalgp.conf"
    else
        rm -f "$SNIPPET_DIR/portalgp.conf"
    fi
    if [[ $HAD_SITE -eq 1 ]]; then
        cp -a "$BACKUP_DIR/portalgp-ssl" "$SITE_AVAILABLE"
    else
        rm -f "$SITE_AVAILABLE"
    fi
    [[ $HAD_SITE_LINK -eq 1 ]] || rm -f "$SITE_ENABLED"
    if nginx -t; then systemctl reload nginx; fi
    if [[ $HAD_POOL -eq 1 ]] && php-fpm8.3 -t; then systemctl reload php8.3-fpm; fi
}

on_error() {
    trap - ERR
    rollback
    printf 'ERROR: configuración restaurada; respaldo temporal: %s\n' "$BACKUP_DIR" >&2
    exit 1
}
trap on_error ERR
install -m 0644 deploy/nginx/portalgp-app.conf "$SNIPPET_DIR/portalgp-app.conf"

if [[ "$MODE" == "bootstrap" ]]; then
    install -m 0644 deploy/nginx/portalgp.conf "$SNIPPET_DIR/portalgp.conf"
else
    [[ "$PORTALGP_IP" =~ ^[0-9a-fA-F:.]+$ ]] || fail "IP no válida"
    CERT_DIR="/etc/letsencrypt/live/$PORTALGP_IP"
    [[ -s "$CERT_DIR/fullchain.pem" && -s "$CERT_DIR/privkey.pem" ]] || fail "no existe un certificado para $PORTALGP_IP"
    [[ $HAD_POOL -eq 1 ]] || fail "falta el pool PHP-FPM exclusivo de PortalGP"
    openssl x509 -in "$CERT_DIR/fullchain.pem" -noout -checkip "$PORTALGP_IP" -checkend 7200

    sed "s/__PORTALGP_IP__/$PORTALGP_IP/g" deploy/nginx/portalgp-https-ip.conf.template > "$SITE_AVAILABLE"
    chmod 0644 "$SITE_AVAILABLE"
    ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"

    # Keep HTTP operational until HTTPS passes CA/hostname validation.
    nginx -t
    systemctl reload nginx
    curl --fail --silent --show-error --max-time 20 --retry 2 --retry-connrefused --retry-delay 1 \
        --resolve "$PORTALGP_IP:443:127.0.0.1" \
        "https://$PORTALGP_IP/portalgp/login.php" -o /dev/null
    grep -q '^env\[PORTALGP_CANONICAL_BASE_URL\]' "$POOL_FILE"
    sed -i "s|^env\[PORTALGP_CANONICAL_BASE_URL\].*|env[PORTALGP_CANONICAL_BASE_URL] = \"https://$PORTALGP_IP\"|" "$POOL_FILE"
    php-fpm8.3 -t
    systemctl reload php8.3-fpm
    install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
    install -m 0755 deploy/scripts/renew_portalgp_certificate.sh \
        /etc/letsencrypt/renewal-hooks/deploy/portalgp-nginx
    install -m 0644 deploy/nginx/portalgp-http-redirect.conf "$SNIPPET_DIR/portalgp.conf"
fi

if ! nginx -t; then
    rollback
    rm -rf "$BACKUP_DIR"
    fail "Nginx rechazó la configuración; se restauró la anterior"
fi

systemctl reload nginx
printf '%s\n' "$COMMIT" > /var/lib/portalgp/deployed_commit
chmod 0644 /var/lib/portalgp/deployed_commit
rm -rf "$BACKUP_DIR"
printf 'PortalGP desplegado en modo %s desde %s (%s).\n' "$MODE" "$BRANCH" "$COMMIT"
