<?php
declare(strict_types=1);

/**
 * Configuración base segura para Microsoft Entra ID.
 *
 * Define las variables MS_ENTRA_* en el entorno o usa `entra.php` dentro del
 * almacén indicado por PORTALGP_SECRETS_DIR (fuera de htdocs).
 */
return [
    'tenant_id' => '',
    'client_id' => '',
    'client_secret' => '',
    'redirect_uri' => '',
    'allowed_domains' => [],
];
