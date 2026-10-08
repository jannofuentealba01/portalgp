#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-}"
PORTALGP_IP="${2:-}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
EXPECTED_BRANCH="codex/msp-aws-deploy"
SNIPPET_DIR="/etc/nginx/snippets"
SITE_AVAILABLE="/etc/nginx/sites-available/portalgp-ssl"
SITE_ENABLED="/etc/nginx/sites-enabled/portalgp-ssl"

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
install -m 0644 deploy/nginx/portalgp-app.conf "$SNIPPET_DIR/portalgp-app.conf"

BACKUP_DIR="$(mktemp -d)"
HAD_SNIPPET=0
HAD_SITE=0
if [[ -e "$SNIPPET_DIR/portalgp.conf" ]]; then
    cp -a "$SNIPPET_DIR/portalgp.conf" "$BACKUP_DIR/portalgp.conf"
    HAD_SNIPPET=1
fi
if [[ -e "$SITE_AVAILABLE" ]]; then
    cp -a "$SITE_AVAILABLE" "$BACKUP_DIR/portalgp-ssl"
    HAD_SITE=1
fi

rollback() {
    if [[ $HAD_SNIPPET -eq 1 ]]; then
        cp -a "$BACKUP_DIR/portalgp.conf" "$SNIPPET_DIR/portalgp.conf"
    else
        rm -f "$SNIPPET_DIR/portalgp.conf"
    fi
    if [[ $HAD_SITE -eq 1 ]]; then
        cp -a "$BACKUP_DIR/portalgp-ssl" "$SITE_AVAILABLE"
    else
        rm -f "$SITE_AVAILABLE" "$SITE_ENABLED"
    fi
    nginx -t >/dev/null 2>&1 || true
}

if [[ "$MODE" == "bootstrap" ]]; then
    install -m 0644 deploy/nginx/portalgp.conf "$SNIPPET_DIR/portalgp.conf"
else
    [[ "$PORTALGP_IP" =~ ^[0-9a-fA-F:.]+$ ]] || fail "IP no válida"
    CERT_DIR="/etc/letsencrypt/live/$PORTALGP_IP"
    [[ -s "$CERT_DIR/fullchain.pem" && -s "$CERT_DIR/privkey.pem" ]] || fail "no existe un certificado para $PORTALGP_IP"

    sed "s/__PORTALGP_IP__/$PORTALGP_IP/g" deploy/nginx/portalgp-https-ip.conf.template > "$SITE_AVAILABLE"
    chmod 0644 "$SITE_AVAILABLE"
    ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
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
