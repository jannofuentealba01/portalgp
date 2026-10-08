#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
EXPECTED_BRANCH="codex/msp-aws-deploy"
BACKUP_ROOT="/var/backups/portalgp-security-stage4-$(date +%Y%m%d_%H%M%S)"

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ $EUID -eq 0 ]] || fail "ejecuta este script con sudo"
cd "$REPO_ROOT"
GIT=(git -c "safe.directory=$REPO_ROOT")
[[ "$("${GIT[@]}" branch --show-current)" == "$EXPECTED_BRANCH" ]] || fail "rama de despliegue incorrecta"
[[ -z "$("${GIT[@]}" status --porcelain)" ]] || fail "el árbol Git contiene cambios locales"
command -v php-fpm8.3 >/dev/null || fail "php-fpm8.3 no está instalado"
command -v nginx >/dev/null || fail "nginx no está instalado"
command -v sshd >/dev/null || fail "sshd no está instalado"
command -v fail2ban-client >/dev/null || fail "fail2ban no está instalado"

install -d -m 0700 "$BACKUP_ROOT"
for path in \
    /etc/nginx/snippets/portalgp-app.conf \
    /etc/php/8.3/fpm/pool.d/portalgp.conf \
    /etc/ssh/sshd_config.d/99-portalgp-hardening.conf \
    /etc/fail2ban/jail.d/portalgp-sshd.local; do
    if [[ -e "$path" ]]; then
        cp -a --parents "$path" "$BACKUP_ROOT"
    fi
done

if ! id -u portalgp >/dev/null 2>&1; then
    useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin portalgp
fi

# Se copian solo sesiones PortalGP para no desconectar a sus usuarios activos.
install -d -o portalgp -g portalgp -m 0700 /var/lib/php/sessions-portalgp
shopt -s nullglob
for session_file in /var/lib/php/sessions/sess_*; do
    if grep -aq 'pgp_security_version' "$session_file"; then
        install -o portalgp -g portalgp -m 0600 "$session_file" "/var/lib/php/sessions-portalgp/$(basename "$session_file")"
    fi
done
shopt -u nullglob

[[ -d /var/msp_storage ]] || fail "no existe /var/msp_storage"
chown -R portalgp:portalgp /var/msp_storage
chmod 0770 /var/msp_storage

[[ -d /var/portalgp_secrets ]] || fail "no existe /var/portalgp_secrets"
chown root:portalgp /var/portalgp_secrets
chmod 0750 /var/portalgp_secrets
find /var/portalgp_secrets -maxdepth 1 -type f -exec chown root:portalgp {} +
find /var/portalgp_secrets -maxdepth 1 -type f -exec chmod 0640 {} +

FTE_CONFIG="/var/www/portalgp/rrhh/fte/fte_config.local.php"
if [[ -f "$FTE_CONFIG" ]]; then
    FTE_OWNER="$(stat -c '%U' "$FTE_CONFIG")"
    chown "$FTE_OWNER:portalgp" "$FTE_CONFIG"
    chmod 0640 "$FTE_CONFIG"
fi

install -o root -g root -m 0644 deploy/php-fpm/portalgp.conf /etc/php/8.3/fpm/pool.d/portalgp.conf
install -o root -g root -m 0644 deploy/ssh/99-portalgp-hardening.conf /etc/ssh/sshd_config.d/99-portalgp-hardening.conf
install -o root -g root -m 0644 deploy/fail2ban/portalgp-sshd.local /etc/fail2ban/jail.d/portalgp-sshd.local
install -o root -g root -m 0644 deploy/nginx/portalgp-app.conf /etc/nginx/snippets/portalgp-app.conf

php-fpm8.3 -t
sshd -t
nginx -t
fail2ban-client -t

systemctl reload php8.3-fpm
for _ in {1..20}; do
    [[ -S /run/php/php8.3-fpm-portalgp.sock ]] && break
    sleep 0.25
done
[[ -S /run/php/php8.3-fpm-portalgp.sock ]] || fail "no se creó el socket aislado de PortalGP"
systemctl reload nginx
systemctl reload ssh
systemctl enable --now fail2ban

printf 'Backup de configuración: %s\n' "$BACKUP_ROOT"
printf 'Pool PortalGP: %s\n' "$(stat -c '%U:%G %A' /run/php/php8.3-fpm-portalgp.sock)"
printf 'Sesiones migradas: %s\n' "$(find /var/lib/php/sessions-portalgp -maxdepth 1 -type f | wc -l)"
printf 'Configuración aplicada. Verifica una nueva conexión SSH antes de retirar la llave de root.\n'
