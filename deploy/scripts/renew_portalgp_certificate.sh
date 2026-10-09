#!/usr/bin/env bash
set -euo pipefail

# Certbot invokes this only after renewal. Reload only if the PortalGP TLS
# configuration actually uses this lineage; never restart either application.
LINEAGE="${RENEWED_LINEAGE:-}"
SITE=/etc/nginx/sites-available/portalgp-ssl
[[ $EUID -eq 0 ]] || exit 1
[[ -n "$LINEAGE" && -f "$SITE" ]] || exit 0
grep -Fq "ssl_certificate $LINEAGE/fullchain.pem;" "$SITE" || exit 0
nginx -t
systemctl reload nginx
